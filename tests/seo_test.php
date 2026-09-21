<?php
// tests/seo_test.php
// SEO (2026-09-14): dane strukturalne wydarzenia wg wymagań Google dla Event
// (startDate z godziną i strefą, location z PostalAddress, endDate, offers)
// oraz lista wydarzeń w formacie „strony podsumowania" (same adresy).
// Testy na syntetycznej tablicy w kształcie EventResource — bez bazy.

use Utils\JsonLd;

function seo_event(array $overrides = []): array
{
    return array_replace([
        'title'               => 'Śląskie Szutry',
        'description'         => "Cykl gravelowych wydarzeń.\r\n\r\nCztery edycje.",
        'statusCode'          => 'published',
        'isPaid'              => true,
        'pricing'             => ['amount' => 230, 'currency' => 'PLN'],
        'variants'            => [['priceAmount' => 180], ['priceAmount' => null]],
        'meetingPointAddress' => 'MOSiR Rybnik Kamień Kąpielisko, Kamień, Rybnik, województwo śląskie, Polska',
        'meetingPointLat'     => 50.07,
        'meetingPointLng'     => 18.5,
        'regionLabel'         => 'śląskie',
        'coverPhotoUrl'       => '/assets/uploads/covers/x.png',
        'organizer'           => ['name' => 'Fundacja VELO', 'slug' => 'fundacja-velo'],
        'stages'              => [['date' => '2026-10-03']],
        'selectedEditionId'   => 1,
        'editions'            => [
            ['id' => 1, 'startDate' => '2026-10-03', 'endDate' => '2026-10-03', 'startTime' => '09:30:00', 'dateIsFlexible' => false, 'isCancelled' => false],
            ['id' => 2, 'startDate' => '2026-01-10', 'endDate' => '2026-01-11', 'startTime' => null, 'dateIsFlexible' => false, 'isCancelled' => false],
        ],
    ], $overrides);
}

function seo_graph(string $html): array
{
    t_true(str_starts_with($html, '<script type="application/ld+json">'), 'jest blok JSON-LD');
    $json = preg_replace('#^<script[^>]*>|</script>$#', '', $html);
    return json_decode($json, true)['@graph'][0];
}

t_test('seo: startDate ma godzinę i przesunięcie strefy (lato +02:00, zima +01:00)', function () {
    $ev = seo_graph(JsonLd::forEvent(seo_event(), 'https://ridemore.bike/events/x'));
    t_eq('2026-10-03T09:30+02:00', $ev['startDate'], 'termin letni');
    t_eq('2026-01-10', $ev['subEvent'][0]['startDate'], 'bez godziny — sama data, nie północ');
    t_eq('2026-01-11', $ev['subEvent'][0]['endDate'], 'wielodniowy turnus ma endDate');
    t_eq('2026-01-10T08:00+01:00', JsonLd::isoDate('2026-01-10', '08:00'), 'termin zimowy');
});

t_test('seo: location to Place z PostalAddress rozłożonym na pola i współrzędnymi', function () {
    $ev = seo_graph(JsonLd::forEvent(seo_event(), 'https://ridemore.bike/events/x'));
    t_eq('Place', $ev['location']['@type'], 'Place');
    t_eq('MOSiR Rybnik Kamień Kąpielisko', $ev['location']['name'], 'nazwa miejsca');
    t_eq('PostalAddress', $ev['location']['address']['@type'], 'PostalAddress');
    t_eq('Rybnik', $ev['location']['address']['addressLocality'], 'miejscowość');
    t_eq('województwo śląskie', $ev['location']['address']['addressRegion'], 'region');
    t_eq('PL', $ev['location']['address']['addressCountry'], 'kraj jako kod ISO');
    t_eq(50.07, $ev['location']['geo']['latitude'], 'geo');
    t_eq('OfflineEventAttendanceMode', basename($ev['eventAttendanceMode']), 'wydarzenie w terenie, nie online');
});

t_test('seo: adres z kodem pocztowym i zagranicą', function () {
    $a = JsonLd::addressParts('ul. Długa 5, 34-500 Zakopane, Polska');
    t_eq('34-500', $a['postalCode'], 'kod pocztowy');
    t_eq('Zakopane', $a['locality'], 'miejscowość bez kodu');
    t_eq('ul. Długa 5', $a['street'], 'ulica');
    t_eq('CZ', JsonLd::addressParts('Náměstí, Karviná, Česko')['country'], 'kraj zagraniczny');
    t_null(JsonLd::addressParts('Gdzieś, Nieznany Kraj')['country'], 'nieznany kraj nie jest zgadywany');
    $manual = JsonLd::addressParts('Rynek w Lesku, parking przy dworcu');
    t_null($manual['locality'], 'ręczny adres: bez zgadywania miejscowości');
    t_eq('Rynek w Lesku, parking przy dworcu', $manual['street'], 'ręczny adres w całości jako ulica');
});

t_test('seo: oferta — najniższa cena, SoldOut przy komplecie, 0 dla bezpłatnych', function () {
    $ev = seo_graph(JsonLd::forEvent(seo_event(), 'https://ridemore.bike/events/x'));
    t_eq('180', $ev['offers']['price'], 'najniższa cena z wariantów');
    t_eq('InStock', basename($ev['offers']['availability']), 'są miejsca');
    $full = seo_graph(JsonLd::forEvent(seo_event(['statusCode' => 'full']), 'u'));
    t_eq('SoldOut', basename($full['offers']['availability']), 'komplet');
    $free = seo_graph(JsonLd::forEvent(seo_event(['isPaid' => false, 'pricing' => null, 'variants' => []]), 'u'));
    t_eq('0', $free['offers']['price'], 'bezpłatne');
});

t_test('seo: bez daty, z terminem do uzgodnienia albo bez żadnego miejsca — brak znacznika Event', function () {
    $flex = seo_event();
    $flex['editions'][0]['dateIsFlexible'] = true;
    t_eq('', JsonLd::forEvent($flex, 'u'), 'termin do uzgodnienia');
    t_eq('', JsonLd::forEvent(seo_event(['meetingPointAddress' => null, 'regionLabel' => null]), 'u'), 'bez miejsca');
    $regionOnly = seo_graph(JsonLd::forEvent(seo_event(['meetingPointAddress' => null, 'meetingPointLat' => null]), 'u'));
    t_eq('śląskie', $regionOnly['location']['address']['addressRegion'], 'sam region wystarcza na adres');
});

t_test('seo: opis bez surowych nowych linii i bez ucięcia w pół słowa', function () {
    $ev = seo_graph(JsonLd::forEvent(seo_event(), 'u'));
    t_false(str_contains($ev['description'], "\r"), 'bez \\r\\n');
    $cut = JsonLd::excerpt(str_repeat('słowo ', 50), 40);
    t_true(str_ends_with($cut, 'słowo…'), 'ucięte na granicy słowa: ' . $cut);
});

t_test('seo: lista wydarzeń to same adresy (bez niepełnych Event)', function () {
    $html = JsonLd::forEventList([['slug' => 'a', 'title' => 'A'], ['slug' => 'b', 'title' => 'B']], 'https://ridemore.bike/wydarzenia');
    $list = seo_graph($html);
    t_eq('ItemList', $list['@type'], 'ItemList');
    t_eq(2, $list['itemListElement'][1]['position'], 'pozycja');
    t_true(str_ends_with($list['itemListElement'][0]['url'], '/events/a'), 'adres');
    t_false(isset($list['itemListElement'][0]['item']), 'bez zagnieżdżonego Event');
});
