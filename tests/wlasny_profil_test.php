<?php
// tests/wlasny_profil_test.php
// PROFIL ROWERZYSTY: WŁAŚCICIEL vs OBCY (2026-09-13, cztery rundy uwag usera).
//
// Zasada, której pilnują te testy: PROFIL POKAZUJE, USTAWIENIA USTAWIAJĄ.
//   1. właściciel dostaje skróty (wgrywanie, ustawienia, preferencje,
//      powiadomienia, prywatność) — same linki do istniejących ekranów;
//      obcy i gość ich nie dostają,
//   2. ukryty rowerzysta widzi SWÓJ profil (wcześniej 404), a obcy dalej 404,
//   3. linki prowadzą do istniejących sekcji konta (`?sekcja=` + kotwice),
//   4. TAJEMNICA ZOSTAJE TAJEMNICĄ: skarb Ukryty/Trop znaleziony przez
//      właściciela nie zdradza obcemu nazwy ani zdjęcia (gablota i kolekcja).
//
// NAZWA PLIKU MUSI SORTOWAĆ SIĘ PO `widoki_test.php`. Te testy renderują CAŁY
// profil, a ten dołącza `partials/photo-lightbox.php`, który pilnuje się stałą
// `RIDEMORE_PHOTO_LIGHTBOX` — stałej nie da się cofnąć, więc gdyby ten plik szedł
// pierwszy, test „lightbox dokleja się tylko RAZ" w widokach dostałby pusty wynik.

/** HTML strony profilu dla danego widza. */
function pu_render(string $slug, ?int $viewerId): string
{
    t_auth_as($viewerId);
    http_response_code(200);
    ob_start();
    Controllers\RiderController::show($slug);
    return (string) ob_get_clean();
}

function pu_user(int $id): Models\User
{
    $u = Models\User::find($id);
    if ($u === null || !$u->publicSlug) {
        t_fail('Użytkownik ' . $id . ' nie ma sluga — test potrzebuje konta z profilem.');
    }
    return $u;
}

t_test('profil: skróty do ustawień i wgrywania widzi właściciel, obcy nie', function () {
    [$a, $b] = t_users(2);
    $pdo = Core\Database::connection();
    $pdo->prepare('UPDATE users SET roster_visible = 1 WHERE id = :id')->execute(['id' => $a]);
    $owner = pu_user($a);

    $own = pu_render($owner->publicSlug, $a);
    t_true(str_contains($own, 'class="rp-short"'), 'właściciel dostaje skróty');
    t_false(str_contains($own, 'rp-side--both'), 'bez zaplanowanego wyjazdu skróty nie rezerwują drugiej kolumny');
    t_true(str_contains($own, 'moje-przejazdy#dodaj'), 'skrót: wgraj przejazd');
    t_true(str_contains($own, 'moje-konto?sekcja=preferencje#jak-jezdze'), 'skrót: preferencje');
    t_true(str_contains($own, 'moje-konto?sekcja=preferencje#prywatnosc'), 'skrót: prywatność');
    t_true(str_contains($own, 'moje-konto?sekcja=profil#powiadomienia-mail'), 'skrót: powiadomienia');
    // Rozstrzygnięcie usera: na profilu NIE MA kart ze stanem ustawień.
    t_false(str_contains($own, 'rp-own'), 'dawny panel z kartami ustawień zniknął');

    $stranger = pu_render($owner->publicSlug, $b);
    t_false(str_contains($stranger, 'class="rp-short"'), 'obcy nie dostaje skrótów');
    t_false(str_contains($stranger, 'moje-przejazdy#dodaj'), 'obcy nie dostaje „wgraj przejazd" na cudzym profilu');
    t_false(str_contains($stranger, 'Twoje punkty'), 'punkty nie są publiczne');

    $guest = pu_render($owner->publicSlug, null);
    t_false(str_contains($guest, 'class="rp-short"'), 'gość nie dostaje skrótów');
    t_false(str_contains($guest, 'rp-todo'), 'gość nie dostaje podpowiedzi');
    t_true(str_contains($own, 'msg-icon--add') && strpos($own, 'msg-icon--add') < strpos($own, 'Wiadomości'), 'nagłówek: ikona „Dodaj wydarzenie" na lewo od wiadomości');
    t_false(str_contains($guest, 'msg-icon--add'), 'gość ma w nagłówku tekst „Zgłoś wydarzenie", nie ikonę');
    t_auth_as(null);
});

t_test('profil: ukryty rowerzysta widzi siebie, obcy dalej 404', function () {
    [$a, $b] = t_users(2);
    $pdo = Core\Database::connection();
    $pdo->prepare('UPDATE users SET roster_visible = 0 WHERE id = :id')->execute(['id' => $a]);
    $owner = pu_user($a);

    $own = pu_render($owner->publicSlug, $a);
    t_eq(200, http_response_code(), 'właściciel nie dostaje 404');
    t_true(str_contains($own, 'Twój profil jest <b>ukryty</b>'), 'mówimy wprost, że profil jest ukryty');
    t_true(str_contains($own, 'noindex'), 'ukryty profil nie idzie do wyszukiwarek');

    $stranger = pu_render($owner->publicSlug, $b);
    t_eq(404, http_response_code(), 'obcy dostaje 404 jak dotąd');
    t_true(str_contains($stranger, 'Nie znaleziono rowerzysty'), 'ten sam ekran co przy braku konta');

    pu_render($owner->publicSlug, null);
    t_eq(404, http_response_code(), 'gość też 404');
    t_auth_as(null);
    http_response_code(200);
});

t_test('profil: tajemniczy skarb nie zdradza obcemu nazwy ani zdjęcia', function () {
    [$a, $b] = t_users(2);
    $pdo = Core\Database::connection();
    $pdo->prepare('UPDATE users SET roster_visible = 1 WHERE id = :id')->execute(['id' => $a]);
    $owner = pu_user($a);

    $ukryty = t_treasure(['name' => 'TEST Sekretna Grota', 'reveal_level' => 0, 'rarity' => 'EPIC',
                          'photo_url' => '/assets/uploads/covers/test-sekret.jpg']);
    $jawny = t_treasure(['name' => 'TEST Jawna Wieża', 'reveal_level' => 2, 'rarity' => 'RARE', 'lat' => 50.01]);
    $ins = $pdo->prepare('INSERT INTO treasure_finds (treasure_id, user_id, method, claimed_at) VALUES (:t, :u, "QR", NOW())');
    $ins->execute(['t' => $ukryty['id'], 'u' => $a]);
    $ins->execute(['t' => $jawny['id'], 'u' => $a]);

    // Model — tu zapada decyzja, widok tylko rysuje.
    $dlaObcego = array_column(Models\Treasure::showcaseForUser($a, $b, 100), null, 'id');
    t_true($dlaObcego[$ukryty['id']]['masked'], 'obcy dostaje zamaskowany wiersz');
    t_null($dlaObcego[$ukryty['id']]['name'], 'bez nazwy');
    t_null($dlaObcego[$ukryty['id']]['photo'], 'bez zdjęcia');
    t_null($dlaObcego[$ukryty['id']]['rarity'], 'bez rzadkości');
    t_false($dlaObcego[$jawny['id']]['masked'], 'jawny skarb zostaje jawny');

    $dlaWlasciciela = array_column(Models\Treasure::showcaseForUser($a, $a, 100), null, 'id');
    t_false($dlaWlasciciela[$ukryty['id']]['masked'], 'znalazca widzi swój skarb w pełni');

    // Oglądający, który SAM znalazł ten skarb, nie ma czego odkrywać.
    $ins->execute(['t' => $ukryty['id'], 'u' => $b]);
    $dlaZnalazcy = array_column(Models\Treasure::showcaseForUser($a, $b, 100), null, 'id');
    t_false($dlaZnalazcy[$ukryty['id']]['masked'], 'drugi znalazca też widzi go w pełni');
    $pdo->prepare('DELETE FROM treasure_finds WHERE treasure_id = :t AND user_id = :u')->execute(['t' => $ukryty['id'], 'u' => $b]);

    // Strona — nic z zagadki nie może wyciec do HTML-a, także przez atrybuty.
    $html = pu_render($owner->publicSlug, $b);
    t_false(str_contains($html, 'Sekretna Grota'), 'nazwa ukrytego nie trafia do HTML-a obcego');
    t_false(str_contains($html, 'test-sekret'), 'zdjęcie ukrytego nie trafia do HTML-a obcego');
    t_true(str_contains($html, 'Tajemnica rozwiązana'), 'obcy widzi, że coś tu jest');

    $own = pu_render($owner->publicSlug, $a);
    t_true(str_contains($own, 'Sekretna Grota'), 'właściciel widzi nazwę swojego skarbu');
    t_auth_as(null);
});

t_test('konto: ?sekcja= otwiera wskazaną sekcję, nieznana wartość nie przechodzi', function () {
    $a = t_user(0);
    t_auth_as($a);

    $_GET = ['sekcja' => 'preferencje'];
    ob_start();
    Controllers\Admin\AccountController::form();
    $html = (string) ob_get_clean();
    t_true((bool) preg_match('/section-panel active" data-section="preferencje"/', $html), 'preferencje aktywne');
    t_true(str_contains($html, 'id="prywatnosc"'), 'kotwica prywatności istnieje');
    t_true(str_contains($html, 'id="jak-jezdze"'), 'kotwica „Jak jeżdżę" istnieje');

    $_GET = ['sekcja' => '"><script>'];
    ob_start();
    Controllers\Admin\AccountController::form();
    $html = (string) ob_get_clean();
    t_true((bool) preg_match('/section-panel active" data-section="profil"/', $html), 'śmieć = domyślny Profil');
    t_false(str_contains($html, '"><script>'), 'wartość z adresu nie trafia do HTML-a');

    $_GET = [];
    t_auth_as(null);
});

t_test('profil: 30 skarbów idzie stronami po 12, filtr nie zdradza zagadki', function () {
    [$a, $b] = t_users(2);
    $pdo = Core\Database::connection();
    $pdo->prepare('UPDATE users SET roster_visible = 1 WHERE id = :id')->execute(['id' => $a]);
    $owner = pu_user($a);
    $przed = Models\Treasure::countShowcaseForUser($a, $b);

    $ins = $pdo->prepare('INSERT INTO treasure_finds (treasure_id, user_id, method, claimed_at) VALUES (:t, :u, "QR", NOW())');
    for ($i = 0; $i < 30; $i++) {
        $t = t_treasure(['name' => 'TEST skala ' . $i, 'rarity' => 'EPIC', 'lat' => 50.0 + $i * 0.01]);
        $ins->execute(['t' => $t['id'], 'u' => $a]);
    }
    $ukryty = t_treasure(['name' => 'TEST Skala Ukryta', 'rarity' => 'LEGENDARY', 'reveal_level' => 0, 'lat' => 51.5]);
    $ins->execute(['t' => $ukryty['id'], 'u' => $a]);

    t_eq($przed + 31, Models\Treasure::countShowcaseForUser($a, $b), 'licznik obejmuje wszystkie znaleziska, także zagadkę');
    t_count(12, Models\Treasure::showcaseForUser($a, $b, 12, 0), 'strona ma 12 pozycji, nie 31');

    // Filtr rzadkości dla OBCEGO: ukryty legendarny nie może wypaść w „Legendarne".
    $legendaObcy = Models\Treasure::showcaseForUser($a, $b, 50, 0, 'LEGENDARY');
    t_false(in_array($ukryty['id'], array_column($legendaObcy, 'id'), true), 'zagadka nie trafia do filtra rzadkości obcego');
    t_eq(0, Models\Treasure::showcaseRarityCounts($a, $b)['LEGENDARY'] - count(array_filter($legendaObcy, static fn(array $s): bool => !$s['masked'])), 'licznik „Legendarne” obcego = to, co filtr mu pokaże');
    $legendaWl = Models\Treasure::showcaseForUser($a, $a, 50, 0, 'LEGENDARY');
    t_true(in_array($ukryty['id'], array_column($legendaWl, 'id'), true), 'właściciel widzi swój legendarny w filtrze');

    // Strona profilu: pager i druga strona.
    $html = pu_render($owner->publicSlug, $b);
    t_eq(12, substr_count($html, 'class="rp-tre rp-tre--'), 'na pierwszej stronie 12 kafli skarbów');
    t_true(str_contains($html, 'skarby=2#skarby'), 'link do drugiej strony skarbów');
    t_false(str_contains($html, 'Skala Ukryta'), 'nazwa zagadki nie wycieka także przy stronicowaniu');

    $_GET = ['skarby' => '3'];
    $html3 = pu_render($owner->publicSlug, $b);
    $_GET = [];
    t_true(substr_count($html3, 'class="rp-tre rp-tre--') >= 1, 'trzecia strona ma skarby');
    t_true(str_contains($html3, '25–'), 'licznik pozycji zaczyna się od 25 na trzeciej stronie');
    t_auth_as(null);
});

t_test('pager: link zmienia swój parametr i zostawia resztę adresu', function () {
    $_GET = ['aktywnosc' => '3', 'rzadkosc' => 'EPIC'];
    $href = stepPagerHref('/rowerzysta/x', ['skarby' => 2], 'skarby');
    t_true(str_contains($href, 'aktywnosc=3'), 'strona dziennika zostaje');
    t_true(str_contains($href, 'rzadkosc=EPIC'), 'filtr zostaje');
    t_true(str_contains($href, 'skarby=2'), 'nowa strona skarbów');
    t_true(str_ends_with($href, '#skarby'), 'kotwica sekcji');
    $bez = stepPagerHref('/rowerzysta/x', ['rzadkosc' => null], '');
    t_false(str_contains($bez, 'rzadkosc'), 'null usuwa parametr');
    $_GET = [];
});
