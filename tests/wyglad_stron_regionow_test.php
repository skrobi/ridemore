<?php
// tests/wyglad_stron_regionow_test.php
// STRONY REGIONÓW (2026-09-14, tasks/done/strony-regionow.md).
//
// Nazwa sortuje się PO `widoki_test.php` CELOWO (pierwsza wersja, `widoki_regionow`,
// sortowała się przed nim i wywaliła jego test lightboxa): strona regionu dokleja
// `photo-lightbox.php`, który renderuje się RAZ na żądanie — a runner to jedno żądanie.
//
// Dane: 16 województw w słowniku to stały układ (migr. 070), więc testy
// adresów opierają się na nich. Wszystko, co zależy od treści (przejazdy,
// trasy, skarby), test WSTAWIA SAM w transakcji, zamiast liczyć na bazę dev.

use Controllers\RegionController;
use Core\Database;
use Models\KnownRoute;
use Models\Region;
use Models\RiderActivity;
use Models\Treasure;

function wr_region_id(string $code): int
{
    $id = Models\Dictionary::id('region', $code);
    if ($id === null) {
        t_fail('Brak regionu ' . $code . ' w słowniku — test zakłada 16 województw z migr. 070.');
    }
    return $id;
}

function wr_render(callable $fn): string
{
    ob_start();
    try {
        $fn();
    } finally {
        $html = (string) ob_get_clean();
    }
    return $html;
}

t_test('regiony: adres regionu, kraju i przekierowanie spod złego kraju', function () {
    Region::forgetCache();
    $ok = Region::resolve('polska', 'podkarpackie');
    t_not_null($ok, 'aktywne województwo się rozwiązuje');
    t_eq('podkarpackie', $ok['region']['code'], 'to ten region');
    t_null($ok['redirect'], 'bez przekierowania');

    $kraj = Region::resolve('polska', null);
    t_null($kraj['region'], 'Polska ma regiony, więc jej adres to strona kraju');

    $zly = Region::resolve('slaskie', 'malopolskie');
    t_eq('/regiony/polska/malopolskie', $zly['redirect'] ?? null, 'region pod złym krajem → właściwy adres');

    t_null(Region::resolve('polska', 'bieszczady'), 'nieaktywne dawne pasmo = 404');
    t_null(Region::resolve('polska', 'nie-ma-takiego'), 'nieistniejący kod = 404');
    t_null(Region::resolve('atlantyda', null), 'nieistniejący kraj = 404');
});

t_test('regiony: link z kodu — region, kraj i kod spoza słownika', function () {
    t_eq('/regiony/polska/slaskie', Region::pathForCode('slaskie'), 'województwo');
    t_eq('/regiony/polska', Region::pathForCode('polska'), 'wydarzenie otagowane samym krajem');
    t_null(Region::pathForCode('bieszczady'), 'nieaktywny kod nie dostaje linku');
    t_null(Region::pathForCode(null), 'brak kodu');
});

t_test('regiony: kraj bez regionów jest stroną regionu pod jednym segmentem', function () {
    $db = Database::connection();
    $dictId = (int) $db->query("SELECT id FROM dictionaries WHERE code = 'region'")->fetchColumn();
    $db->prepare('INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order, is_active) VALUES (:d, NULL, :c, :n, 999, 1)')
        ->execute(['d' => $dictId, 'c' => 'test-kraj-plaski', 'n' => 'Testlandia']);
    Region::forgetCache();

    $r = Region::resolve('test-kraj-plaski', null);
    t_not_null($r['region'] ?? null, 'kraj płaski rozwiązuje się od razu do regionu');
    t_eq('/regiony/test-kraj-plaski', Region::pathForCode('test-kraj-plaski'), 'jeden segment');
    t_eq('/regiony/test-kraj-plaski', Region::resolve('test-kraj-plaski', 'test-kraj-plaski')['redirect'] ?? null,
        'drugi segment przekierowuje na jeden');
    Region::forgetCache();
});

t_test('regiony: trasy po ID regionu — „śląskie" nie łapie „dolnośląskiego"', function () {
    $db = Database::connection();
    $routeId = (int) $db->query('SELECT id FROM known_routes WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if ($routeId === 0) {
        t_fail('Baza dev nie ma aktywnej znanej trasy.');
    }
    $slaskie = wr_region_id('slaskie');
    $dolnoslaskie = wr_region_id('dolnoslaskie');
    $db->prepare('DELETE FROM known_route_regions WHERE route_id = :r')->execute(['r' => $routeId]);
    $db->prepare('INSERT INTO known_route_regions (route_id, region_item_id) VALUES (:r, :g)')
        ->execute(['r' => $routeId, 'g' => $dolnoslaskie]);

    t_true(in_array($routeId, KnownRoute::idsInRegion($dolnoslaskie), true), 'trasa jest w dolnośląskim');
    t_false(in_array($routeId, KnownRoute::idsInRegion($slaskie), true), 'i NIE ma jej w śląskim');
});

t_test('regiony: skarby regionu maskowane jak na trasie (gość nie widzi zagadki)', function () {
    // (50.0, 20.0) leży w małopolskim.
    $ukryty = t_treasure(['name' => 'TEST ukryty w regionie', 'reveal_level' => 0]);
    $jawny = t_treasure(['name' => 'TEST jawny w regionie', 'lat' => 50.001]);
    $region = (int) Database::connection()
        ->query('SELECT region_item_id FROM region_cells WHERE cell_id = ' . (int) $ukryty['cell_id'])
        ->fetchColumn();
    if ($region === 0) {
        t_fail('Pole testowego skarbu nie ma regionu — brak importu region_cells na dev.');
    }

    $lista = Treasure::listInRegion($region, null, 1000);
    $nazwy = array_column($lista['items'], 'name');
    t_true(in_array('TEST jawny w regionie', $nazwy, true), 'jawny skarb jest na liście');
    t_false(in_array('TEST ukryty w regionie', $nazwy, true), 'ukryty nie wychodzi nazwą');
    t_true((int) $lista['hidden'] >= 1, 'ale liczy się w ukrytych');
    t_true(Treasure::onRegion($region, null)['total'] >= 2, 'licznik obejmuje oba');
    unset($jawny);
});

t_test('regiony: „Kto tu jeździ" nie pokazuje osoby z ukrytym profilem, ale ją liczy', function () {
    $db = Database::connection();
    [$widoczny, $ukryty] = t_users(2);
    $db->prepare("UPDATE users SET roster_visible = 1, public_slug = COALESCE(public_slug, CONCAT('test-wr-', id)) WHERE id = :id")->execute(['id' => $widoczny]);
    $db->prepare('UPDATE users SET roster_visible = 0 WHERE id = :id')->execute(['id' => $ukryty]);
    $region = wr_region_id('opolskie');
    $db->exec('DELETE rar FROM rider_activity_regions rar WHERE rar.region_item_id = ' . $region);

    foreach ([$widoczny, $ukryty] as $uid) {
        $db->prepare("INSERT INTO rider_activities (user_id, source_code, ride_date, distance_km) VALUES (:u, 'solo', CURDATE(), 10)")
            ->execute(['u' => $uid]);
        $db->prepare('INSERT INTO rider_activity_regions (activity_id, region_item_id) VALUES (:a, :r)')
            ->execute(['a' => (int) $db->lastInsertId(), 'r' => $region]);
    }

    $kto = RiderActivity::ridersInRegion($region);
    $ids = array_map('intval', array_column($kto['people'], 'id'));
    t_eq(2, $kto['total'], 'liczba obejmuje oboje');
    t_true(in_array($widoczny, $ids, true), 'publiczny profil widać');
    t_false(in_array($ukryty, $ids, true), 'ukryty profil nie wychodzi');
});

t_test('regiony: strona regionu — nagłówek, JSON-LD i indeksowanie bez progu', function () {
    t_auth_as(null);
    Region::forgetCache();
    $html = wr_render(fn() => RegionController::show('polska', 'opolskie'));

    t_true(str_contains($html, '<h1>Opolskie</h1>') || str_contains($html, 'hero-ttl">Opolskie</h1>'), 'nazwa regionu w H1');
    t_false(str_contains($html, 'name="robots"'), 'pusty region też jest indeksowany (bez progu)');
    t_true(str_contains($html, '"AdministrativeArea"'), 'węzeł AdministrativeArea');
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    foreach ($m[1] as $json) {
        t_not_null(json_decode($json, true), 'każdy blok JSON-LD to poprawny JSON');
    }
    t_true(str_contains($html, '/regiony/polska/slaskie'), 'linki do innych regionów kraju');
});

t_test('regiony: zalogowany widzi własne odkrycie, gość — społeczności', function () {
    // Dwie różne ścieżki danych (progressForUser vs all, pctMine vs
    // pctCommunity) — każda musi się wyrenderować bez błędu.
    Region::forgetCache();
    t_auth_as(t_user());
    $zalogowany = wr_render(fn() => RegionController::show('polska', 'podkarpackie'));
    t_auth_as(null);
    $gosc = wr_render(fn() => RegionController::show('polska', 'podkarpackie'));

    t_true(str_contains($zalogowany, 'Twoje odkrycie'), 'zalogowany: własny procent');
    t_false(str_contains($zalogowany, 'Dołącz do społeczności'), 'zalogowany nie dostaje zaproszenia do rejestracji');
    t_true(str_contains($gosc, 'pól regionu przejechała społeczność'), 'gość: procent społeczności');
});

t_test('regiony: granica regionu idzie na mapę (gruba czerwona linia)', function () {
    $rings = Region::rings('podkarpackie');
    t_true(count($rings) >= 1 && count($rings[0]) > 10, 'województwo ma obrys z importu');
    t_same([], Region::rings('nie-ma-takiego'), 'region bez geometrii = pusta lista, nie błąd');

    t_auth_as(null);
    Region::forgetCache();
    $html = wr_render(fn() => RegionController::show('polska', 'podkarpackie'));
    t_true(str_contains($html, "createPane('regionOutline')"), 'strona rysuje granicę na własnej warstwie');
    t_true((bool) preg_match('/var granica = \[\[\[\d/', $html), 'z prawdziwymi współrzędnymi');
});

t_test('regiony: nazwa regionu jako link — kilka regionów, nazwa spoza słownika, ucieczka', function () {
    // Zgłoszenie usera 2026-09-14: „jest podkarpackie i nie da się na to kliknąć".
    require_once CORE_PATH . '/../views/web/partials/region-link.php';
    Region::forgetCache();

    $html = renderRegionLinks('podkarpackie, Małopolskie');
    t_eq(2, substr_count($html, '<a '), 'każdy region z listy osobnym linkiem');
    t_true(str_contains($html, '/regiony/polska/podkarpackie"'), 'podkarpackie');
    t_true(str_contains($html, '/regiony/polska/malopolskie"'), 'nazwa z wielkiej litery też się rozpoznaje');

    t_same('Bieszczady', renderRegionLinks('Bieszczady'), 'nieaktywny region zostaje tekstem');
    t_false(str_contains(renderRegionLinks('<b>x</b>'), '<b>'), 'nazwa spoza słownika jest ucieczkowana');
    t_same('', renderRegionLinks(null), 'brak podpisu = nic');

    $tagi = renderRegionLinks('podkarpackie, Nieznany', 'tag tag--plain', ' ');
    t_true(str_contains($tagi, '<a class="tag tag--plain"'), 'region jako klikalny tag');
    t_true(str_contains($tagi, '<span class="tag tag--plain">Nieznany</span>'), 'nieznany jako zwykły tag');
});

t_test('regiony: emblematy regionów prowadzą na strony regionów', function () {
    require_once CORE_PATH . '/../views/web/partials/region-emblems.php';
    Region::forgetCache();
    $grupy = [[
        'country'    => ['code' => 'polska', 'name' => 'Polska', 'mine' => 10, 'total' => 100, 'pctMine' => 10.0],
        'showHeader' => true,
        'regions'    => [
            ['code' => 'slaskie', 'name' => 'śląskie', 'mine' => 5, 'total' => 50, 'pctMine' => 10.0],
            ['code' => 'nie-ma-takiego', 'name' => 'Nieznany', 'mine' => 1, 'total' => 9, 'pctMine' => 11.0],
        ],
    ]];
    $html = wr_render(fn() => renderRegionEmblems($grupy));
    t_true((bool) preg_match('#<a href="[^"]*/regiony/polska/slaskie" class="region-emblem#', $html), 'emblemat to link');
    t_true(str_contains($html, '<div class="region-emblem'), 'region spoza słownika zostaje kartą');
    t_true(str_contains($html, '/regiony/polska"'), 'nagłówek kraju linkuje do strony kraju');
});

t_test('regiony: sitemapa zawiera każdy aktywny region i żadnego nieaktywnego', function () {
    Region::forgetCache();
    // @ — header() po tym, jak runner już coś wypisał, sypie ostrzeżeniem w CLI.
    $xml = wr_render(static fn() => @Controllers\SitemapController::regions());
    t_true(str_contains($xml, '/regiony/polska/wielkopolskie</loc>'), 'województwo bez treści też jest');
    t_true(str_contains($xml, '/regiony/polska</loc>'), 'strona kraju');
    t_false(str_contains($xml, 'bieszczady'), 'nieaktywne pasmo nie');
    // Z włączonym angielskim (dev) każdy adres jest w sitemapie raz NA JĘZYK.
    t_eq(count(Core\Lang::supported()), substr_count($xml, '/regiony</loc>'), 'spis raz na język');
});

// --- Zdjęcia z regionu (2026-09-16) -----------------------------------
//
// Prośba usera: zdjęcia z relacji, opinii i skarbów na stronie regionu —
// „ważne, aby wiadome było, co jest źródłem danego zdjęcia i czego dotyczy".
// Decyzje: skarb ukryty tylko dla tego, kto go znalazł; autor imieniem tylko
// przy publicznym profilu.

/** Wyjazd w regionie z jednym turnusem. `$status` to kod słownika event_status. */
function wr_event(int $regionId, string $status = 'completed', string $title = 'TEST wyjazd regionu'): array
{
    $db = Database::connection();
    $db->prepare('
        INSERT INTO events (organizer_id, event_type_item_id, status_item_id, title, slug, start_date)
        VALUES (:org, :type, :status, :title, :slug, CURDATE() - INTERVAL 7 DAY)
    ')->execute([
        'org'    => t_user(0),
        'type'   => Models\Dictionary::id('event_type', 'ustawka'),
        'status' => Models\Dictionary::id('event_status', $status),
        'title'  => $title,
        'slug'   => 'test-wr-' . bin2hex(random_bytes(6)),
    ]);
    $eventId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO event_regions (event_id, region_item_id) VALUES (:e, :r)')
        ->execute(['e' => $eventId, 'r' => $regionId]);
    $db->prepare('INSERT INTO event_editions (event_id, start_date) VALUES (:e, CURDATE() - INTERVAL 7 DAY)')
        ->execute(['e' => $eventId]);

    return ['id' => $eventId, 'editionId' => (int) $db->lastInsertId()];
}

/** Zdjęcie relacji (albo opinii, gdy `$review`) wrzucone przez `$userId`. Zwraca URL. */
function wr_event_photo(array $event, int $userId, bool $review = false): string
{
    $db = Database::connection();
    $url = '/assets/uploads/gallery/test-wr-' . bin2hex(random_bytes(6)) . '.jpg';
    if ($review) {
        $db->prepare('INSERT INTO event_reviews (event_id, reviewer_user_id, target_user_id, rating, comment) VALUES (:e, :u, :t, 5, "TEST")')
            ->execute(['e' => $event['id'], 'u' => $userId, 't' => t_user(0)]);
        $recapId = null;
        $reviewId = (int) $db->lastInsertId();
    } else {
        $db->prepare('INSERT INTO event_recaps (event_id, edition_id, author_user_id, body) VALUES (:e, :ed, :u, "TEST")')
            ->execute(['e' => $event['id'], 'ed' => $event['editionId'], 'u' => $userId]);
        $recapId = (int) $db->lastInsertId();
        $reviewId = null;
    }
    Models\EventPhoto::attach($event['id'], $userId, [$url], $reviewId, $recapId);

    return $url;
}

t_test('zdjęcia z regionu: relacja i opinia z wyjazdu w regionie — szkic i inny region nie', function () {
    $opolskie = wr_region_id('opolskie');
    $user = t_user(1);
    $wyjazd = wr_event($opolskie, 'completed', 'TEST Opolska pętla');
    $zRelacji = wr_event_photo($wyjazd, $user);
    $zOpinii = wr_event_photo($wyjazd, $user, true);
    $zeSzkicu = wr_event_photo(wr_event($opolskie, 'draft'), $user);
    $zInnego = wr_event_photo(wr_event(wr_region_id('lubuskie')), $user);

    $po = [];
    foreach (Models\EventPhoto::forRegion($opolskie, 50) as $p) {
        $po[$p['url']] = $p;
    }
    t_true(isset($po[$zRelacji]), 'zdjęcie z relacji jest');
    t_eq('recap', $po[$zRelacji]['kind'] ?? null, 'podpisane jako relacja');
    t_eq('TEST Opolska pętla', $po[$zRelacji]['eventTitle'] ?? null, 'wiadomo, którego wyjazdu dotyczy');
    t_eq('review', $po[$zOpinii]['kind'] ?? null, 'zdjęcie z opinii podpisane jako opinia');
    t_false(isset($po[$zeSzkicu]), 'szkic wyjazdu nie wychodzi na publiczną stronę');
    t_false(isset($po[$zInnego]), 'wyjazd z innego regionu nie');
});

t_test('zdjęcia z regionu: autor imieniem tylko z publicznym profilem, zablokowany nie wychodzi', function () {
    $db = Database::connection();
    [$publiczny, $prywatny, $zablokowany] = t_users(3);
    $db->prepare("UPDATE users SET roster_visible = 1, name = 'Anna Testowa', public_slug = COALESCE(public_slug, CONCAT('test-wr-', id)), blocked_at = NULL WHERE id = :id")->execute(['id' => $publiczny]);
    $db->prepare('UPDATE users SET roster_visible = 0, blocked_at = NULL WHERE id = :id')->execute(['id' => $prywatny]);
    $db->prepare('UPDATE users SET blocked_at = NOW() WHERE id = :id')->execute(['id' => $zablokowany]);

    $opolskie = wr_region_id('opolskie');
    $wyjazd = wr_event($opolskie);
    $urlPub = wr_event_photo($wyjazd, $publiczny);
    $urlPryw = wr_event_photo($wyjazd, $prywatny);
    $urlBlok = wr_event_photo($wyjazd, $zablokowany);

    $po = [];
    foreach (Models\EventPhoto::forRegion($opolskie, 50) as $p) {
        $po[$p['url']] = $p;
    }
    t_eq('Anna Testowa', $po[$urlPub]['authorName'] ?? null, 'publiczny profil — z imieniem');
    t_not_null($po[$urlPub]['authorSlug'] ?? null, 'i z linkiem do profilu');
    t_true(isset($po[$urlPryw]), 'zdjęcie osoby z ukrytym profilem jest');
    t_null($po[$urlPryw]['authorName'], 'ale bez imienia');
    t_null($po[$urlPryw]['authorSlug'], 'i bez linku');
    t_false(isset($po[$urlBlok]), 'konto zablokowane nie wychodzi wcale');
});

t_test('zdjęcia z regionu: zdjęcie skarbu tylko przy skarbie odsłoniętym w całości', function () {
    $db = Database::connection();
    [$widz, $autor] = t_users(2);
    $db->prepare('UPDATE users SET blocked_at = NULL WHERE id IN (:a, :b)')->execute(['a' => $widz, 'b' => $autor]);
    // (50.0, 20.0) leży w małopolskim — ten sam punkt co test maskowania wyżej.
    $jawny = t_treasure(['name' => 'TEST jawny z galerią', 'lat' => 50.002]);
    $ukryty = t_treasure(['name' => 'TEST ukryty z galerią', 'reveal_level' => 0]);
    $urlJawny = '/assets/uploads/gallery/test-wr-jawny-' . bin2hex(random_bytes(4)) . '.jpg';
    $urlUkryty = '/assets/uploads/gallery/test-wr-ukryty-' . bin2hex(random_bytes(4)) . '.jpg';
    Models\TreasurePhoto::add((int) $jawny['id'], [$urlJawny], $autor);
    Models\TreasurePhoto::add((int) $ukryty['id'], [$urlUkryty], $autor);
    $region = (int) $db->query('SELECT region_item_id FROM region_cells WHERE cell_id = ' . (int) $ukryty['cell_id'])->fetchColumn();
    if ($region === 0) {
        t_fail('Pole testowego skarbu nie ma regionu — brak importu region_cells na dev.');
    }
    $kod = (string) $db->query('SELECT code FROM dictionary_items WHERE id = ' . $region)->fetchColumn();

    Region::forgetCache();
    t_auth_as(null);
    $gosc = wr_render(fn() => RegionController::show('polska', $kod));
    t_true(str_contains($gosc, $urlJawny), 'gość widzi zdjęcie skarbu jawnego');
    t_true(str_contains($gosc, 'Skarb „TEST jawny z galerią&quot;'), 'z podpisem: skarb i jego nazwa');
    t_false(str_contains($gosc, $urlUkryty), 'zdjęcie ukrytego skarbu nie wychodzi — pokazałoby, czego szukać');

    $db->prepare('INSERT INTO treasure_finds (treasure_id, user_id, method) VALUES (:t, :u, "GPS")')
        ->execute(['t' => (int) $ukryty['id'], 'u' => $widz]);
    t_auth_as($widz);
    $znalazca = wr_render(fn() => RegionController::show('polska', $kod));
    t_auth_as(null);
    t_true(str_contains($znalazca, $urlUkryty), 'ten, kto znalazł ukryty skarb, widzi jego zdjęcie');
});

t_test('zdjęcia z regionu: podpis pod zdjęciem i w podglądzie — źródło, przedmiot, autor', function () {
    $db = Database::connection();
    $prywatny = t_user(2);
    $db->prepare('UPDATE users SET roster_visible = 0, blocked_at = NULL WHERE id = :id')->execute(['id' => $prywatny]);
    $opolskie = wr_region_id('opolskie');
    $url = wr_event_photo(wr_event($opolskie, 'completed', 'TEST Relacja z Opola'), $prywatny);

    Region::forgetCache();
    t_auth_as(null);
    $html = wr_render(fn() => RegionController::show('polska', 'opolskie'));
    t_true(str_contains($html, 'id="zdjecia"'), 'sekcja „Zdjęcia z regionu"');
    t_true(str_contains($html, 'data-caption="Relacja z wyjazdu „TEST Relacja z Opola&quot; · zdjęcie: uczestnik wyjazdu'),
        'podpis w podglądzie: źródło, wyjazd i anonimowy autor');
    t_true(str_contains($html, 'class="rph__src">Relacja z wyjazdu</span>'), 'podpis pod zdjęciem mówi, skąd jest');
    t_true(str_contains($html, '#opinie">TEST Relacja z Opola</a>'), 'przedmiot linkuje do relacji przy wyjeździe');
    t_true(str_contains($html, htmlspecialchars($url)), 'samo zdjęcie jest');
    // Lightbox renderuje się RAZ na żądanie (a runner to jedno żądanie), więc
    // jego znacznik sprawdzamy w pliku, nie w tym wyrenderowaniu.
    $lightbox = (string) file_get_contents(CORE_PATH . '/../views/web/partials/photo-lightbox.php');
    t_true(str_contains($lightbox, 'class="ph-modal__cap"') && str_contains($lightbox, "getAttribute('data-caption')"),
        'lightbox pokazuje podpis z data-caption');
});

t_test('karta organizatora: opis kafli mówi, czy to zdjęcia z profilu, czy okładki wyjazdów', function () {
    $db = Database::connection();
    $org = t_user(0);
    $kafel = static function (string $source): string {
        $o = ['slug' => 'test', 'name' => 'TEST Org', 'coverPhotos' => ['/x.jpg'], 'coverPhotosSource' => $source,
              'organizerType' => 'peer', 'isVerified' => false, 'regionName' => null, 'ratingAvg' => null,
              'reviewCount' => 0, 'completedCount' => 0, 'avatarUrl' => null];
        return wr_render(static function () use ($o) {
            require CORE_PATH . '/../views/web/partials/organizer-card.php';
        });
    };
    t_true(str_contains($kafel('profile'), 'TEST Org — zdjęcie z profilu organizatora'), 'zdjęcia z profilu');
    t_true(str_contains($kafel('events'), 'TEST Org — okładka wyjazdu organizatora'), 'okładki wyjazdów');

    $db->prepare('INSERT INTO organizer_profiles (user_id, hero_photo_urls) VALUES (:u, :h) ON DUPLICATE KEY UPDATE hero_photo_urls = VALUES(hero_photo_urls)')
        ->execute(['u' => $org, 'h' => json_encode(['/hero1.jpg', '/hero2.jpg'])]);
    $z = Models\Organizer::coverPhotosWithSource($org, 3);
    t_eq('profile', $z['source'], 'własne zdjęcia organizatora mają pierwszeństwo i tak są opisane');
    t_same(['/hero1.jpg', '/hero2.jpg'], $z['urls'], 'adresy zdjęć z profilu');
    t_same($z['urls'], Models\Organizer::recentCoverPhotos($org, 3), 'recentCoverPhotos oddaje dokładnie to samo');
});
