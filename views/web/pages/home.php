<?php
// views/web/pages/home.php
// Strona główna — landing (od 2026-07-31). Pełna, filtrowalna lista wydarzeń
// żyje pod /wydarzenia (patrz Controllers\EventsListController, events-list.php).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;

$matchCards     = $matchCards ?? [];
$featuredEvents = $featuredEvents ?? [];
$formatCounts   = $formatCounts ?? [];
$regionCounts   = $regionCounts ?? [];
$routeOfDay     = $routeOfDay ?? null;
$recap          = $recap ?? null;
$stats          = $stats ?? [];

// Cytat na stronie głównej to teaser, nie pełna relacja — ta czeka na
// /events/{slug}#relacje. Ucinamy do ok. 220 znaków, na granicy słowa, żeby
// nie kończyć w połowie wyrazu.
$recapQuote = null;
if ($recap && trim((string) $recap['body']) !== '') {
    $body = trim($recap['body']);
    if (mb_strlen($body) > 220) {
        $body = mb_substr($body, 0, 220);
        $body = mb_substr($body, 0, (int) strrpos($body, ' ')) . '…';
    }
    $recapQuote = $body;
}
$community = $community ?? [];
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);
?>
<h1 class="visually-hidden"><?= __('Wspólne wyjazdy rowerowe i odkrywanie tras w Polsce i na Świecie — ridemore.bike') ?></h1>

<section class="hero hero--split">
    <div class="hero__main">
    <p class="eyebrow"><span class="blaze" style="--bz:var(--blaze-dark);"></span><?= __('Odkrywaj Polskę i Świat na rowerach z innymi') ?></p>
    <h1><?= __('Ile z tej mapy masz naprawdę') ?> <em><?= __('przejechane') ?></em>?</h1>
    <p class="hero__lede"><?= __('Sprawdź, gdzie jeszcze nie byłeś — pięć kilometrów stąd albo na drugim końcu Polski.
        Znajdź ludzi, którzy tam jadą, i zabierz stamtąd coś więcej niż ślad GPS.') ?></p>

    <form role="search" action="<?= Utils\View::url('/wydarzenia') ?>" method="get">
        <div class="search-row">
            <label class="visually-hidden" for="hero-q"><?= __('Szukaj wyjazdu') ?></label>
            <input id="hero-q" class="search-input" name="q" type="search" placeholder="<?= htmlspecialchars(__('Region, miasto albo nazwa wyjazdu')) ?>">
            <button class="btn" type="submit"><?= __('Szukaj') ?></button>
            <?php // Ta sama kontrolka co na liście wydarzeń: <button> z geolokalizacją,
                  // nie <a> udający przycisk. Wcześniej te dwa „Blisko mnie" wyglądały
                  // i zachowywały się inaczej — tu link do listy, tam realne pytanie
                  // o pozycję. Teraz strona główna też pyta o pozycję i przekazuje ją
                  // dalej w adresie, więc obietnica przycisku jest w obu miejscach ta sama. ?>
            <button class="locate-btn" type="button" data-role="home-locate">
                <?= Utils\Icon::render('pin') ?> <span data-role="locate-label"><?= __('Blisko mnie') ?></span>
            </button>
        </div>
    </form>
    </div>

    <?php // RAZEM ODKRYLIŚMY — ten sam komponent (.disc-social) i ta sama liczba
          // co na /odkrycia, nie druga jej wersja. Stoi tu, bo strona główna
          // mówiła dotąd wyłącznie o kalendarzu: ktoś, kto tu ląduje, nie miał
          // skąd wiedzieć, że ten serwis jest o czymś więcej niż zapisy na
          // wyjazdy. Sekcja pojawia się dopiero, gdy jest co pokazać — „0 pól"
          // w nagłówku strony głównej reklamowałoby pustkę.
          //
          // TŁO Z „NASTĘPNEGO CELU" (2026-09-05, prośba usera) — `.disc-social--photo`
          // dokłada tę samą miniaturę w mgle, którą ma karta celu na /odkrycia
          // (patrz `.disc-goal__photo` w style.css). Tu nie ma jednego
          // konkretnego miejsca do pokazania (to suma całej społeczności, nie
          // czyjś cel), więc miniatura zostaje w wariancie PUSTYM — sama mgła
          // i wyblakły hex — zamiast wymyślać zdjęcie, które niczego
          // konkretnego by nie przedstawiało. ?>
    <?php if (!empty($community['cells'])): ?>
    <aside class="disc-social disc-social--photo">
        <div class="disc-goal__body">
            <span class="disc-social__lbl"><?= __('Razem odkryliśmy') ?></span>
            <strong class="disc-social__num"><span class="hexn"><?= Utils\Icon::render('hex') ?><?= number_format((int) $community['cells'], 0, ',', ' ') ?></span>
                <small><?= $plural((int) $community['cells'], 'pole', 'pola', 'pól') ?></small></strong>
            <p class="disc-social__sub">
                <?php if (($community['cellsRecent'] ?? 0) > 0): ?>
                <?= __('W ostatnim miesiącu społeczność ridemore odkryła') ?>

                <b><?= number_format((int) $community['cellsRecent'], 0, ',', ' ') ?></b>
                <?= $plural((int) $community['cellsRecent'], 'nowe pole', 'nowe pola', __('nowych pól')) ?>.
                <?php else: ?>
                <?= __('Każdy przejechany ślad zdejmuje z mapy kawałek szarości.') ?>

                <?php endif; ?>
            </p>
            <a class="btn" href="<?= Utils\View::url('/odkrycia') ?>"><?= __('Zobacz mapę odkryć') ?></a>
        </div>
        <span class="disc-goal__photo disc-goal__photo--empty" aria-hidden="true"><?= Utils\Icon::render('hex') ?></span>
    </aside>
    <?php endif; ?>
</section>

<div class="chip-row home-intent">
    <a class="chip" href="<?= Utils\View::url('/wydarzenia?when=weekend') ?>"><?= __('Ten weekend') ?></a>
    <a class="chip" href="<?= Utils\View::url('/wydarzenia?distance=50') ?>"><?= __('Do 50 km ode mnie') ?></a>
    <a class="chip" href="<?= Utils\View::url('/wydarzenia?bikeTypes[]=gravel') ?>">Gravel</a>
    <a class="chip" href="<?= Utils\View::url('/wydarzenia?bikeTypes[]=mtb') ?>">MTB</a>
    <a class="chip" href="<?= Utils\View::url('/wydarzenia?eventTypes[]=pokrec_z_kims') ?>"><?= __('Szukam towarzystwa') ?></a>
</div>

<?php require __DIR__ . '/../partials/match-suggestions.php'; ?>

<?php if (!empty($pulseItems)): ?>
<?php
    // PULS NA STRONIE GŁÓWNEJ — odpowiedź na „czy tu się w ogóle coś dzieje".
    // Strona główna do tej pory mówiła wyłącznie w czasie PRZYSZŁYM („najbliższe
    // wyjazdy") — czyli o półce sklepowej, nie o ludziach. Ten blok jest
    // pierwszym miejscem, gdzie widać czas teraźniejszy i dokonany: kto już
    // przejechał, gdzie zbiera się skład.
    //
    // Celowo NAD kalendarzem: ludzie decydują patrząc na ludzi. Trzy wpisy,
    // nie feed — pełny Puls jest pod /puls.
    $pulseLabels = [
        'przejazd' => [__('Przejechali'), '--s-red'],
        'sklad'    => [__('Skład się zbiera'), '--s-blue'],
        'kronika'  => [__('Nowe w kronice'), '--s-green'],
        'wezwanie' => [__('Ktoś szuka towarzystwa'), '--s-purple'],
        // Trzy typy, które feed produkuje od dawna, a ta mapa ich nie znała:
        // każdy spadał na default („Puls"), a skarby dodatkowo dostawały
        // w opisie „Szuka towarzystwa". Brzmienia i kolory JAK NA /puls,
        // żeby ten sam wpis nie wyglądał inaczej w obu miejscach.
        'zapis'          => [__('Ktoś dołączył'), '--s-blue'],
        'skarb-nowe'     => [__('Nowe skarby na mapie'), '--s-purple'],
        'skarb-pierwszy' => [__('Pierwszy raz znaleziony'), '--s-red'],
        // CZWARTY typ z dokładnie tą samą usterką (2026-09-12, zgłoszenie usera:
        // „user wgrał trasę, a na Pulsie wyszło, że szuka towarzystwa").
        // `slady-wgrane` doszedł do modelu 2026-09-11 — tego samego dnia co
        // poprawka wyżej — ale do TEJ mapy nie trafił. Tu było gorzej niż przy
        // skarbach: tytułem tego wpisu jest IMIĘ człowieka, więc karta mówiła
        // wprost „Jan Kowalski · Szuka towarzystwa", czyli przypisywała
        // konkretnej osobie intencję, której nigdy nie wyraziła.
        'slady-wgrane'   => [__('Nowe ślady na mapie'), '--s-red'],
    ];
?>
<section class="home-section" aria-labelledby="h2-puls">
    <div class="sec-head">
        <div>
            <p class="eyebrow"><span class="blaze" style="--bz:var(--blaze-dark);"></span><?= __('Puls') ?></p>
            <h2 id="h2-puls"><?= __('Co się dzieje na trasach') ?></h2>
        </div>
        <a class="btn btn-secondary" href="<?= Utils\View::url('/puls') ?>"><?= __('Zobacz cały puls &rarr;') ?></a>
    </div>
    <div class="op-grid">
        <?php foreach ($pulseItems as $pi): ?>
        <?php [$plLabel, $plColor] = $pulseLabels[$pi['type']] ?? [__('Puls'), '--accent']; ?>
        <?php // Wpisy o skarbach nie mają turnusu (eventSlug = null) — prowadzą
              // na wspólną mapę odkryć, dokładnie jak na pełnym Pulsie. Bez tego
              // warunku href wychodził /events/?termin=0, czyli 404.
              //
              // Wgrane ślady też są bez turnusu, ale ich adresem NIE jest mapa.
              // JEDEN ślad prowadzi DO TEGO PRZEJAZDU, wiele — na profil: wpis
              // o całej dobie nie ma jednego „tego" przejazdu, więc obiecywanie
              // konkretnego byłoby zmyśleniem. Model oddaje `rideId` wyłącznie
              // przy grupie jednoelementowej (patrz Models\Pulse::trackUploads,
              // tam też bramka widoczności). ?>
        <?php $pulseHref = $pi['eventSlug'] !== null
            ? Utils\View::url(($pi['type'] === 'przejazd' || $pi['type'] === 'kronika' ? '/kronika/' : '/events/') . $pi['eventSlug']) . '?termin=' . (int) $pi['editionId']
            : ($pi['type'] === 'slady-wgrane'
                ? (($pi['rideId'] ?? null) !== null
                    ? Utils\View::url('/przejazd/' . (int) $pi['rideId'])
                    : Utils\View::url('/rowerzysta/' . $pi['riderSlug']))
                : Utils\View::url('/odkrycia') . '?mapa=spolecznosc'); ?>
        <?php
            // TŁO KARTY. Wpis o wgranych śladach nie ma okładki (nie ma
            // wyjazdu), więc dostaje OBRAZEK SAMEGO ŚLADU — kafle OSM plus
            // przebieg, składane raz i zapisywane na dysk
            // (TileController::trackGroupMap).
            //
            // `mapReady > 0` JEST WARUNKIEM KONIECZNYM, nie ostrożnością:
            // ślad bez policzonej geometrii nie ma z czego się narysować,
            // endpoint odda na niego 404, a karta pokazałaby ikonę zepsutego
            // obrazka. Model liczy to jednym zapytaniem dla całego feedu.
            $pulseTlo = $pi['type'] === 'slady-wgrane' && (int) ($pi['mapReady'] ?? 0) > 0
                ? Utils\View::url('/assets/tiles/slad/' . $pi['mapKey'] . '/karta/' . $pi['mapStamp'] . '.png')
                : ($pi['coverPhotoUrl'] ? Utils\Image::src($pi['coverPhotoUrl'], 'card') : null);
        ?>
        <a class="op-card" href="<?= $pulseHref ?>">
            <div class="op-card__v"<?= $pulseTlo ? ' style="background-image:url(\'' . htmlspecialchars($pulseTlo) . '\');"' : '' ?>>
                <span class="op-card__f" style="--bz:var(<?= $plColor ?>);"><i></i><?= htmlspecialchars($plLabel) ?></span>
            </div>
            <div class="op-card__b">
                <h3 class="op-card__t"><?= htmlspecialchars($pi['title']) ?></h3>
                <?php
                    // Opis karty per typ. Skarby dostają fakty jak na /puls
                    // (kategoria · region), wpisy wyjazdowe zachowują dotychczasowe
                    // brzmienie + region na końcu.
                    if ($pi['type'] === 'przejazd') {
                        $n = (int) $pi['peopleCount'];
                        $pulseMeta = __n($n, '{n} osoba przejechała razem', '{n} osoby przejechały razem', '{n} osób przejechało razem');
                    } elseif ($pi['type'] === 'sklad') {
                        $n = (int) $pi['peopleCount'];
                        $pulseMeta = __n($n, '{n} osoba zapisana', '{n} osoby zapisane', '{n} osób zapisanych');
                    } elseif ($pi['type'] === 'kronika') {
                        $pulseMeta = __('Nowe wpisy w kronice');
                    } elseif ($pi['type'] === 'zapis') {
                        $pulseMeta = __('Pierwsza osoba w składzie');
                    } elseif ($pi['type'] === 'skarb-nowe' || $pi['type'] === 'skarb-pierwszy') {
                        // Fakty skarbu — kategoria · region, ta sama linia co w
                        // feedzie /puls; bez regionu podpis „Mapa społeczności",
                        // bo pusty opis wyglądałby na usterkę.
                        $pulseMeta = implode(' · ', array_filter([
                            $pi['categoryLabel'] ?? null,
                            $pi['regionLabel'] ?? null,
                        ])) ?: __('Mapa społeczności');
                    } elseif ($pi['type'] === 'slady-wgrane') {
                        // BEZ CZASOWNIKA W FORMIE OSOBOWEJ, dokładnie jak na
                        // /puls: „wgrał"/„wgrała" wymusza rodzaj, którego konto
                        // nie deklaruje. Podmiotem zdania są ŚLADY — imię i tak
                        // stoi w tytule karty wyżej.
                        $n = (int) $pi['count'];
                        $pulseMeta = __n($n, '{n} nowy ślad', '{n} nowe ślady', '{n} nowych śladów');
                        if ((float) $pi['km'] > 0) {
                            $pulseMeta .= ' · ' . Format::distance((float) $pi['km']);
                        }
                    } elseif ($pi['type'] === 'wezwanie') {
                        $pulseMeta = __('Szuka towarzystwa');
                    } else {
                        // DOMYŚLNY OPIS MUSI BYĆ NEUTRALNY. Do 2026-09-12 stało
                        // tu „Szuka towarzystwa" — brzmienie `wezwania`, które
                        // dostawał KAŻDY typ nieobsłużony wyżej. Złapało to już
                        // skarby (poprawione 2026-09-11) i wgrane ślady; dziewiąty
                        // typ wpisu ma spaść na zdanie, które nigdy nie kłamie,
                        // a nie na cudzą intencję.
                        $pulseMeta = __('Zobacz w Pulsie');
                    }
                ?>
                <p class="op-card__m">
                    <?= htmlspecialchars($pulseMeta) ?><?= $pulseMeta !== __('Mapa społeczności') && $pi['regionLabel'] && !in_array($pi['type'], ['skarb-nowe', 'skarb-pierwszy'], true) ? ' · ' . htmlspecialchars($pi['regionLabel']) : '' ?>
                </p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($featuredEvents): ?>
<section class="home-section" aria-labelledby="h2-najblizsze">
    <div class="sec-head">
        <div>
            <p class="eyebrow"><span class="blaze" style="--bz:var(--s-red);"></span><?= __('Najbliższe wyjazdy') ?></p>
            <h2 id="h2-najblizsze"><?= __('Wyjazdy, na które zdążysz') ?></h2>
        </div>
        <a class="btn btn-secondary" href="<?= Utils\View::url('/wydarzenia') ?>"><?= __('Zobacz wszystkie') ?><?= $stats['upcomingCount'] ? ' ' . (int) $stats['upcomingCount'] : '' ?> &rarr;</a>
    </div>
    <div class="event-grid-wide">
        <?php foreach ($featuredEvents as $ev): ?>
            <?php require __DIR__ . '/../partials/home-event-card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="home-section home-section--tint" aria-labelledby="h2-formaty">
    <div class="sec-head">
        <div>
            <p class="eyebrow"><span class="blaze" style="--bz:var(--s-blue);"></span><?= __('Cztery sposoby, żeby pojechać razem') ?></p>
            <h2 id="h2-formaty"><?= __('Od porannej jazdy po tygodniową wyprawę') ?></h2>
        </div>
    </div>
    <div class="formats-grid">
        <div class="format-card" style="--bz:var(--s-red);">
            <h3><?= __('Zorganizowane wydarzenie') ?></h3>
            <p><?= __('Jeden dzień, konkretna godzina zbiórki, konkretna trasa. Prowadzi ktoś, kto zna drogę.
                Zwykle bezpłatnie — zapisujesz się, przyjeżdżasz, jedziesz.') ?></p>
            <span class="mono"><?= __('{n} nadchodzących', ['n' => (int) ($formatCounts['ustawka'] ?? 0)]) ?></span>
        </div>
        <div class="format-card" style="--bz:var(--s-purple);">
            <h3><?= __('Wyścig') ?></h3>
            <p><?= __('Start o wyznaczonej godzinie, często kilka dystansów do wyboru w jednym zapisie.
                Rywalizacja albo własne tempo — decydujesz sam.') ?></p>
            <span class="mono"><?= __('{n} nadchodzących', ['n' => (int) ($formatCounts['wyscig'] ?? 0)]) ?></span>
        </div>
        <div class="format-card" style="--bz:var(--s-blue);">
            <h3><?= __('Wielodniówka') ?></h3>
            <p><?= __('Od weekendu w górach po tydzień bikepackingu. Etapy, noclegi, bagaż, czasem przewodnik
                i ubezpieczenie — cena i zapisy zostają po stronie organizatora.') ?></p>
            <span class="mono"><?= __('{n} nadchodzących', ['n' => (int) ($formatCounts['wycieczka_wielodniowa'] ?? 0)]) ?></span>
        </div>
        <div class="format-card" style="--bz:var(--s-green);">
            <h3><?= __('Pokręcę z kimś') ?></h3>
            <p><?= __('Masz trasę i wolny weekend, ale nie chcesz jechać sam. Wrzucasz okno terminowe,
                a termin dogadujecie w komentarzach. Bezpłatne z definicji.') ?></p>
            <span class="mono"><?= __('{n} ogłoszeń', ['n' => (int) ($formatCounts['pokrec_z_kims'] ?? 0)]) ?></span>
        </div>
    </div>
</section>

<?php if ($regionCounts): ?>
<section class="home-section" aria-labelledby="h2-regiony">
    <div class="sec-head">
        <div>
            <p class="eyebrow"><span class="blaze" style="--bz:var(--s-green);"></span><?= __('Przeglądaj po regionach') ?></p>
            <h2 id="h2-regiony"><?= __('Gdzie chcesz pojechać') ?></h2>
        </div>
        <a class="btn btn-secondary" href="<?= Utils\View::url('/wydarzenia') ?>"><?= __('Zobacz wszystkie wyjazdy &rarr;') ?></a>
    </div>
    <div class="regions-grid">
        <?php foreach ($regionCounts as $r): ?>
        <?php // Strona regionu zamiast filtra listy (2026-09-14) — na niej jest
              // i lista wyjazdów, i to, co zostaje po sezonie (trasy, ludzie). ?>
        <a class="region-link" href="<?= Utils\View::url(Models\Region::pathForCode($r['code']) ?? '/wydarzenia?regions[]=' . urlencode($r['code'])) ?>">
            <span><?= htmlspecialchars($r['name']) ?></span>
            <span class="mono"><?= (int) $r['event_count'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($routeOfDay): ?>
<?php
    // PRZEPROJEKTOWANE 2026-09-05 (zgłoszenie usera: „za duże zdjęcie rozciąga
    // całą kontrolkę" — naprawione w CSS, patrz .rotd-photo — i „kluczowe
    // informacje są małe i niewiele wnoszą, warto ją przeprojektować").
    //
    // „TRASA DNIA" MA TERAZ DWA ŹRÓDŁA (HomeController::buildRouteOfDay()):
    // najbliższe nadchodzące wydarzenie ALBO, gdy żadne się nie kwalifikuje,
    // znana trasa z katalogu (user: „dołożyłbym też znane trasy, rozszerzają
    // one zakres — nie musi być to tylko event"). `$routeOfDay['type']`
    // rozstrzyga cel linków i to, czy pokazać datę/organizatora (trasa
    // katalogowa nie ma ani jednego, ani drugiego).
    $rotdHref = Utils\View::url($routeOfDay['type'] === 'event'
        ? '/events/' . $routeOfDay['slug']
        : '/trasy/' . $routeOfDay['slug']);

    // Części już jako HTML — region jest linkiem do swojej strony (2026-09-14).
    require_once __DIR__ . '/../partials/region-link.php';
    $rotdSubParts = [];
    if ($routeOfDay['startDate']) {
        $rotdSubParts[] = htmlspecialchars(Format::dateShort($routeOfDay['startDate']));
    } elseif ($routeOfDay['type'] === 'route') {
        $rotdSubParts[] = __('znana trasa');
    }
    if ($routeOfDay['regionName']) {
        $rotdSubParts[] = renderRegionLinks($routeOfDay['regionName']);
    }
?>
<section class="home-section home-section--tint" aria-labelledby="h2-trasa">
    <div class="rotd">
        <div class="rotd-head">
            <div>
                <p class="eyebrow"><span class="blaze" style="--bz:var(--blaze-dark);"></span><?= __('Trasa dnia') ?></p>
                <?php // Tytuł jest LINKIEM (2026-08-13). Dotąd jedynym wyjściem
                      // z tej karty był odnośnik na samym dole — a tytuł to
                      // pierwsza rzecz, w którą człowiek klika. ?>
                <h2 id="h2-trasa"><a href="<?= $rotdHref ?>"><?= htmlspecialchars($routeOfDay['title']) ?></a></h2>
            </div>
            <?php if ($rotdSubParts): ?>
            <p><?= implode(' · ', $rotdSubParts) ?></p>
            <?php endif; ?>
        </div>
        <?php // KLUCZOWE LICZBY, WIĘKSZE I Z IKONĄ (zgłoszenie usera: „trochę
              // małe i niewiele wnoszą") — ta sama para ikon co reszta serwisu:
              // `route` dla dystansu, `tre-viewpoint` (sylwetka góry) dla
              // przewyższenia, ten sam znak co „Nowy teren" na /odkrycia. ?>
        <div class="rotd-stats">
            <span class="rotd-stat"><?= Utils\Icon::render('route') ?><b><?= htmlspecialchars(Format::distance($routeOfDay['distanceKm'])) ?></b> <?= __('dystansu') ?></span>
            <span class="rotd-stat"><?= Utils\Icon::render('tre-viewpoint') ?><b><?= (int) $routeOfDay['elevationGainM'] ?> m</b> <?= __('przewyższenia') ?></span>
            <?php if ($routeOfDay['bikeTypeNames']): ?><span class="rotd-stat rotd-stat--tag"><?= htmlspecialchars($routeOfDay['bikeTypeNames']) ?></span><?php endif; ?>
        </div>
        <?php if ($routeOfDay['description']): ?>
        <p class="rotd-desc"><?= htmlspecialchars($routeOfDay['description']) ?></p>
        <?php endif; ?>
        <?php
            // Karta na dwie części: wizualizacja trasy i zdjęcie (2026-08-13,
            // przeprojektowane 2026-09-05). Zdjęcie TYLKO przy wyjeździe
            // jednodniowym (wielodniówka: profil/kafle pierwszego etapu to
            // ułamek trasy, zestawienie ze zdjęciem obiecywałoby całość) —
            // znana trasa ma durationDays=1, więc to ograniczenie jej nie
            // dotyczy. Bez zdjęcia wizualizacja zostaje na pełnej szerokości.
            $rotdSplit = !empty($routeOfDay['coverPhotoUrl']) && (int) $routeOfDay['durationDays'] <= 1;
        ?>
        <div class="rotd-body<?= $rotdSplit ? ' rotd-body--split' : '' ?>">
            <?php // ŚLAD Z PRAWDZIWEJ MAPY (2026-09-05, poprawione po
                  // odrzuceniu abstrakcyjnego wykresu przez usera: "myślałem
                  // że bedzie to wyglądało jak tiles z mapy") — złożony
                  // obrazek z prawdziwych kafli OSM plus pól tej trasy plus
                  // jej śladu, wygenerowany raz dziennie (Controllers\
                  // TileController::routeOfDayMap). Zwykły <img>, bez
                  // Leafletu/JS-a na tej stronie. Spada na wykres profilu
                  // wysokości (jak dotąd), gdy kafli jeszcze nie policzono
                  // (np. plik GPX etapu jeszcze nie doszedł), a na nic, gdy
                  // nie ma żadnego z dwóch. ?>
            <?php if (!empty($routeOfDay['mapImageUrl'])): ?>
            <img class="rotd-map" src="<?= htmlspecialchars($routeOfDay['mapImageUrl']) ?>" loading="lazy" width="<?= (int) $routeOfDay['viewW'] ?>" height="<?= (int) $routeOfDay['viewH'] ?>"
                 alt="<?= htmlspecialchars(__('Mapa trasy {tytul} z zaznaczonymi polami odkryć, które ta trasa dotyka', ['tytul' => $routeOfDay['title']])) ?>">
            <?php elseif (!empty($routeOfDay['svgLinePath'])): ?>
            <svg class="rotd-chart" viewBox="0 0 <?= (int) $routeOfDay['viewW'] ?> <?= (int) $routeOfDay['viewH'] ?>" preserveAspectRatio="none" role="img"
                 aria-label="<?= htmlspecialchars(__('Profil wysokościowy trasy {tytul}: {km}, {m} m przewyższenia', ['tytul' => $routeOfDay['title'], 'km' => Format::distance($routeOfDay['distanceKm']), 'm' => (int) $routeOfDay['elevationGainM']])) ?>">
                <path class="area" d="<?= htmlspecialchars($routeOfDay['svgAreaPath']) ?>"></path>
                <path class="line" d="<?= htmlspecialchars($routeOfDay['svgLinePath']) ?>" vector-effect="non-scaling-stroke"></path>
            </svg>
            <?php endif; ?>
            <?php if ($rotdSplit): ?>
            <a class="rotd-photo" href="<?= $rotdHref ?>" aria-hidden="true" tabindex="-1">
                <img src="<?= htmlspecialchars(Utils\Image::src($routeOfDay['coverPhotoUrl'], 'card')) ?>" alt=""
                     loading="lazy" width="420" height="180">
            </a>
            <?php endif; ?>
        </div>
        <div class="rotd-foot">
            <?php // IKONKA ORGANIZATORA — TYLKO event (zgłoszenie usera: „ikonkę
                  // organizatora, jeśli event"); trasa katalogowa nie ma
                  // organizatora, więc nie ma czym wypełnić tego miejsca.
                  // Reużyte klasy `.hcard-org`/`.hcard-av`/`.hcard-ver`
                  // z partials/home-event-card.php — ten sam wygląd, ta sama
                  // logika awatar/inicjał/znaczek weryfikacji, nie druga
                  // implementacja tego samego. ?>
            <?php if ($routeOfDay['type'] === 'event' && $routeOfDay['organizerName']): ?>
            <?php
                // Zgłoszenie usera 2026-09-10: to jest kontekst ORGANIZATORA
                // (ikonka pokazuje się tylko przy evencie), więc link idzie do
                // /organizatorzy/, nie do /rowerzysta/ — inaczej niż wszędzie
                // indziej, gdzie ta sama osoba pojawia się jako uczestnik.
                // Bez sluga (organizator bez profilu) zostaje zwykły <span>,
                // ten sam wzorzec co brak sluga w rider-avatar.php.
                $rotdOrgUrl = !empty($routeOfDay['organizerSlug'])
                    ? Utils\View::url('/organizatorzy/' . $routeOfDay['organizerSlug'])
                    : null;
                $rotdOrgTag = $rotdOrgUrl ? 'a' : 'span';
            ?>
            <<?= $rotdOrgTag ?> class="hcard-org rotd-org" style="text-decoration:none;color:inherit;"<?= $rotdOrgUrl ? ' href="' . htmlspecialchars($rotdOrgUrl) . '"' : '' ?>>
                <?php if (!empty($routeOfDay['organizerAvatarUrl'])): ?>
                <img class="hcard-av" src="<?= htmlspecialchars(Utils\View::url($routeOfDay['organizerAvatarUrl'])) ?>" alt="" width="24" height="24">
                <?php else: ?>
                <span class="hcard-av"><?= htmlspecialchars(mb_substr($routeOfDay['organizerName'], 0, 1)) ?></span>
                <?php endif; ?>
                <?= htmlspecialchars($routeOfDay['organizerName']) ?><?php if ($routeOfDay['organizerVerified']): ?> <span class="hcard-ver"><?= __('✓ zweryfikowany') ?></span><?php endif; ?>
            </<?= $rotdOrgTag ?>>
            <?php endif; ?>
            <p class="rotd-link"><a href="<?= $rotdHref ?>"><?= $routeOfDay['type'] === 'event' ? __('Zobacz szczegóły wyjazdu') : __('Zobacz szczegóły trasy') ?> &rarr;</a></p>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="home-section" aria-labelledby="h2-dowod">
    <h2 id="h2-dowod" class="visually-hidden"><?= __('Zaufanie i statystyki') ?></h2>
    <div class="proof-layout">
        <?php if ($recapQuote): ?>
        <div class="featured-quote">
            <p>&bdquo;<?= nl2br(htmlspecialchars($recapQuote)) ?>&rdquo;</p>
            <cite><a href="<?= Utils\View::url('/events/' . $recap['event_slug']) ?>"><?= __('Relacja z wyjazdu') ?> · <?= htmlspecialchars($recap['event_title']) ?></a></cite>
        </div>
        <?php endif; ?>
        <div class="proof-stats">
            <div class="stat"><span class="stat-value"><?= (int) $stats['upcomingCount'] ?></span><span class="stat-label"><?= __('wyjazdów, do których możesz dołączyć') ?></span></div>
            <?php if (!empty($stats['organizerCount'])): ?>
            <div class="stat"><span class="stat-value"><?= (int) $stats['organizerCount'] ?></span><span class="stat-label"><?= __('organizatorów') ?></span></div>
            <?php endif; ?>
            <div class="stat"><span class="stat-value">0%</span><span class="stat-label"><?= __('prowizji od wyjazdów') ?></span></div>
        </div>
    </div>
</section>

<section class="home-section">
    <div class="cta-banner">
        <div>
            <p class="eyebrow"><span class="blaze" style="--bz:var(--blaze-dark);"></span><?= __('Dla organizatorów') ?></p>
            <h2><?= __('Organizujesz wyjazd? Wystaw go tutaj.') ?></h2>
            <p><?= __('Zapisy, płatności i kontakt z uczestnikami zostają u Ciebie — nie bierzemy prowizji ani nie
                pośredniczymy. Dajemy stronę wydarzenia, profil organizatora i ludzi, którzy szukają
                dokładnie tego, co robisz.') ?></p>
        </div>
        <div class="chips">
            <a class="btn" style="background:var(--blaze);border-color:var(--blaze-dark);color:var(--ink);" href="<?= Utils\View::url('/wydarzenia/nowe') ?>"><?= __('Dodaj wyjazd') ?></a>
            <a class="btn btn-secondary" href="<?= Utils\View::url('/dla-organizatorow') ?>"><?= __('Jak to działa') ?></a>
        </div>
    </div>
</section>

<script>
// „BLISKO MNIE" na stronie głównej — do 2026-08-13 był tu zwykły <a> do listy
// wydarzeń, czyli przycisk, który wygląda jak geolokalizacja, a nią nie jest.
// Na liście ten sam napis realnie pyta o pozycję. Teraz pyta w obu miejscach,
// a pozycja jedzie w adresie, więc lista otwiera się od razu z filtrem
// promienia zamiast prosić o zgodę drugi raz.
(function () {
    var btn = document.querySelector('[data-role="home-locate"]');
    if (!btn) return;
    var label = btn.querySelector('[data-role="locate-label"]');
    var base = <?= json_encode(Utils\View::url('/wydarzenia'), JSON_UNESCAPED_SLASHES) ?>;

    btn.addEventListener('click', function () {
        label.textContent = 'Szukam...';
        // Most (assets/js/native.js). Wysoka dokładność WYŁĄCZONA: idziemy
        // filtrować listę promieniem 50 km, więc pozycja z masztu w zupełności
        // wystarcza, a nie każe czekać na złapanie satelitów.
        RM.native.position({ timeout: 8000, highAccuracy: false }).then(function (pos) {
            location.href = base + '?lat=' + pos.lat.toFixed(5)
                + '&lng=' + pos.lon.toFixed(5) + '&radiusKm=50';
        }).catch(function () {
            // Odmowa zgody nie jest błędem — po prostu idziemy na listę bez
            // filtru, zamiast zostawiać człowieka z przyciskiem, który „nie działa".
            location.href = base;
        });
    });
})();
</script>
