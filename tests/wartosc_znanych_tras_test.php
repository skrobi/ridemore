<?php
// tests/wartosc_znanych_tras_test.php
// WARTOŚĆ ZNANYCH TRAS (2026-09-03, DWIE ITERACJE):
//
//   1. Zgłoszenie usera: trasa 1000 km i trasa 50 km nie mogą płacić tyle
//      samo za ukończenie tylko dlatego, że obie mają włączony bonus bez
//      ręcznego nadpisania. → Models\DiscoveryScoring::trailValueFor().
//   2. Drugie zgłoszenie: model „progi cząstkowe osobno, ukończenie osobno,
//      w proporcji z globalnej drabinki" był nie do ogarnięcia. Trasa ma
//      JEDNĄ wartość total, którą `threshold_count` progów dzieli między
//      siebie RÓWNO. → Models\DiscoveryScoring::trailAwards()/splitEqually().
//
// Oczekiwane liczby liczone są z ŻYWEJ konfiguracji (DiscoveryScoring::config()),
// nie z literałów w tym pliku — dev baza miewa nadpisania w scoring_settings,
// więc porównanie do core/discovery.php na sztywno byłoby fałszywym negatywem.
use Models\DiscoveryScoring;

t_test('Dłuższa trasa bez ręcznego nadpisania jest warta więcej niż krótsza', function () {
    $short = DiscoveryScoring::trailValueFor(50.0, null);
    $long  = DiscoveryScoring::trailValueFor(1000.0, null);

    t_eq('distance', $short['source'], 'krótka trasa liczy się z długości');
    t_eq('distance', $long['source'], 'długa trasa liczy się z długości');
    t_true($long['total'] > $short['total'], '1000 km daje więcej punktów niż 50 km');

    $rate = (float) DiscoveryScoring::config()['trails']['points_per_km'];
    t_eq((int) round(50 * $rate), $short['total'], '50 km × stawka');
    t_eq((int) round(1000 * $rate), $long['total'], '1000 km × stawka');
});

t_test('splitEqually dzieli sumę na identyczne części, resztę dokłada do ostatniej', function () {
    // t_eq rzutuje na string (przez to każda tablica staje się "Array" i
    // porównanie zawsze by "przeszło") — dla tablic trzeba t_same().
    t_same([175, 175, 175, 175], DiscoveryScoring::splitEqually(700, 4), '700 / 4 dzieli się bez reszty');

    $withRemainder = DiscoveryScoring::splitEqually(100, 3);
    t_eq(3, count($withRemainder), 'trzy części');
    t_eq(33, $withRemainder[0], 'pierwsza część w dół');
    t_eq(33, $withRemainder[1], 'druga część w dół');
    t_eq(34, $withRemainder[2], 'reszta z zaokrąglenia na OSTATNIEJ części');
    t_eq(100, array_sum($withRemainder), 'suma części = dokładnie total, żadnego punktu nie ginie');
});

t_test('splitEqually(0, N) i splitEqually(total, 0) nie wywracają się i nie dają ujemnych punktów', function () {
    t_same([0, 0, 0], DiscoveryScoring::splitEqually(0, 3), 'zero total = same zera');
    t_same([], DiscoveryScoring::splitEqually(700, 0), 'zero progów = pusta lista');
});

t_test('trailAwards dzieli total równo między progi z trailThresholds()', function () {
    $thresholds = DiscoveryScoring::trailThresholds();
    $awards = DiscoveryScoring::trailAwards(100, 800);

    t_eq(count($thresholds), count($awards), 'tyle wpisów w wyniku, ile progów');
    t_eq(800, array_sum($awards), 'suma progów = dokładnie wpisana wartość total');

    // Wszystkie progi PRÓCZ OSTATNIEGO (który bierze resztę z zaokrąglenia)
    // muszą być identyczne — to jest sedno „dzieli się równo".
    $values = array_values($awards);
    $allButLast = array_slice($values, 0, -1);
    if ($allButLast) {
        t_eq(1, count(array_unique($allButLast)), 'progi poza ostatnim są identyczne');
    }
});

t_test('Ręczne nadpisanie w known_routes wygrywa z sugestią z długości', function () {
    $value = DiscoveryScoring::trailValueFor(1000.0, 1500);

    t_eq('override', $value['source'], 'nadpisanie ma źródło "override"');
    t_eq(1500, $value['total'], 'total to dokładnie to, co wpisano');
});

t_test('Trasa bez znanej długości wraca do wartości domyślnej, nie do zera', function () {
    $expectedDefault = (int) DiscoveryScoring::config()['trails']['default_total_points'];

    $value = DiscoveryScoring::trailValueFor(0.0, null);
    t_eq('default', $value['source'], 'brak długości => wartość domyślna');
    t_eq($expectedDefault, $value['total'], 'total to wartość domyślna, nie zero');
});

t_test('legacyRouteOverrideTotal scala stare kolumny bonus_points + completion_bonus w jeden total', function () {
    t_eq(1500, DiscoveryScoring::legacyRouteOverrideTotal(600, 900), 'suma dwóch starych kolumn (Szlak Testowy sprzed zmiany)');
    t_eq(600, DiscoveryScoring::legacyRouteOverrideTotal(600, null), 'tylko jedna kolumna wypełniona liczy się jako total');
    t_null(DiscoveryScoring::legacyRouteOverrideTotal(null, null), 'obie puste = brak nadpisania, nie zero');
});

t_test('Podgląd progów sumuje się DOKŁADNIE do total (nie w przybliżeniu)', function () {
    $value = DiscoveryScoring::trailValueFor(263.0, null);
    $awards = DiscoveryScoring::trailAwards(100, $value['total']);

    t_eq($value['total'], array_sum($awards), 'suma progów = total co do punktu, dzięki resztowaniu na ostatnim progu');
});
