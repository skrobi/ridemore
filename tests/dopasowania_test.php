<?php
// tests/dopasowania_test.php
// DOPASOWANIA (Etap 2) — rdzeń oceniający.
//
// PRZENIESIONE z test_match_engine.php (2026-08-15). Treść asercji bez zmian — zmienił się
// wyłącznie sposób ich uruchamiania: zamiast płaskiego skryptu, który trzeba
// było odpalać osobno i czytać jego wydruk, jest to zestaw dla `tests/run.php`.
// Dzięki temu biegnie razem z resztą i psujący się kod widać od razu, a nie
// dopiero wtedy, gdy ktoś sobie o tym skrypcie przypomni.
//
// Zestaw jest CZYSTO ODCZYTOWY — niczego nie zapisuje. Transakcja runnera i tak
// go obejmuje, więc nawet gdyby kiedyś zaczął, baza tego nie zapamięta.

t_test('Silnik dopasowań: RouteCells, zgodność regionów, MatchEngine', function () {
    // Most na starą nazwę: oryginał wołał $assert(warunek, opis) i tak zostaje,
    // żeby przeniesienie nie zmieniło ani jednej asercji.
    $assert = static function (bool $warunek, string $opis): void {
        t_true($warunek, $opis);
    };
    // Oryginał trzymał połączenie w zmiennej globalnej skryptu; wewnątrz
    // domknięcia trzeba je wziąć na nowo (Database::connection() i tak zwraca
    // to samo, jedno na proces).
    $pdo = Core\Database::connection();

    // --- 1. Utils\RouteCells: siatka i interpolacja -----------------------

    $cellA = Utils\RouteCells::pointToCell(50.0, 22.0);
    $cellB = Utils\RouteCells::pointToCell(50.0, 22.0);
    $assert($cellA === $cellB, 'RouteCells: ten sam punkt daje tę samą komórkę');

    $cellNear = Utils\RouteCells::pointToCell(50.0001, 22.0);
    $assert($cellA === $cellNear, 'RouteCells: punkty odległe o ~10m trafiają w tę samą komórkę 200m');

    $cellFar = Utils\RouteCells::pointToCell(50.01, 22.0);
    $assert($cellA !== $cellFar, 'RouteCells: punkty odległe o ~1.1km trafiają w różne komórki');

    $sparseProfile = [
        ['d' => 0.0, 'e' => 100, 'lat' => 50.000, 'lon' => 22.000],
        ['d' => 1.66, 'e' => 120, 'lat' => 50.015, 'lon' => 22.000], // ~1.66km dalej, jak realne próbki co 1.66km
    ];
    $interpolatedCells = Utils\RouteCells::fromElevationProfile($sparseProfile);
    $assert(count($interpolatedCells) >= 5, 'RouteCells: interpolacja między próbkami odległymi o 1.66km daje wiele komórek (znaleziono ' . count($interpolatedCells) . ')');

    $noCoordsProfile = [['d' => 0.0, 'e' => 100], ['d' => 1.0, 'e' => 110]];
    $assert(Utils\RouteCells::fromElevationProfile($noCoordsProfile) === [], 'RouteCells: profil bez lat/lon (sprzed backfill_elevation_profiles.php) daje pustą listę, nie błąd');

    // --- 2. Dictionary::regionCompatibility --------------------------------

    $regionRows = $pdo->query("
        SELECT di.id, di.code, di.parent_id FROM dictionary_items di
        JOIN dictionaries d ON d.id = di.dictionary_id WHERE d.code = 'region'
    ")->fetchAll();
    $byCode = [];
    foreach ($regionRows as $r) {
        $byCode[$r['code']] = $r;
    }
    if (isset($byCode['bieszczady'], $byCode['tatry'])) {
        $bieszczady = (int) $byCode['bieszczady']['id'];
        $tatry = (int) $byCode['tatry']['id'];
        $assert(Models\Dictionary::regionCompatibility($bieszczady, $bieszczady) === 1.0, 'regionCompatibility: ten sam region = 1.0');
        $siblingScore = Models\Dictionary::regionCompatibility($bieszczady, $tatry);
        $assert($siblingScore > 0.0 && $siblingScore < 1.0, "regionCompatibility: różne regiony pod tym samym krajem > 0 i < 1 (jest {$siblingScore})");
        if (isset($byCode['polska'])) {
            $polska = (int) $byCode['polska']['id'];
            $ancestorScore = Models\Dictionary::regionCompatibility($polska, $bieszczady);
            $assert($ancestorScore > $siblingScore, "regionCompatibility: przodek/potomek ({$ancestorScore}) silniejsze niż rodzeństwo ({$siblingScore})");
        }
    } else {
        echo "POMINIĘTO: brak oczekiwanych regionów (bieszczady/tatry) w słowniku tej bazy\n";
    }
    $assert(Models\Dictionary::regionCompatibility(null, 1) === 0.0, 'regionCompatibility: null po jednej stronie = 0.0 (brak wykluczenia, tylko brak wkładu)');

    // --- 3. MatchEngine: filtr twardy statusu -------------------------------

    $draftOrCompleted = $pdo->query("
        SELECT e.id FROM events e
        JOIN dictionary_items st ON st.id = e.status_item_id
        WHERE st.code IN ('draft', 'completed', 'oczekuje_weryfikacji', 'cancelled')
        LIMIT 1
    ")->fetchColumn();
    if ($draftOrCompleted !== false) {
        $eventEditionId = $pdo->prepare('SELECT id FROM event_editions WHERE event_id = :id LIMIT 1');
        $eventEditionId->execute(['id' => $draftOrCompleted]);
        $editionId = $eventEditionId->fetchColumn();
        if ($editionId !== false) {
            $result = Models\MatchEngine::forEdition((int) $editionId);
            $foundSelf = false;
            foreach ($result['matches'] as $m) {
                if ($m['eventId'] === (int) $draftOrCompleted) {
                    $foundSelf = true;
                }
            }
            $assert(!$foundSelf, 'MatchEngine: kandydat nie dopasowuje sam siebie (excludeEventId działa)');
        }
    }

    $publishedEditionId = $pdo->query("
        SELECT ed.id FROM event_editions ed
        JOIN events e ON e.id = ed.event_id
        JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
        LIMIT 1
    ")->fetchColumn();
    if ($publishedEditionId !== false) {
        $result = Models\MatchEngine::forEdition((int) $publishedEditionId);
        $assert(is_array($result['matches']), 'MatchEngine::forEdition zwraca tablicę matches bez wyjątku');
        $assert(isset($result['wideningLevel']), 'MatchEngine::forEdition zwraca wideningLevel');
        foreach ($result['matches'] as $m) {
            $assert(in_array($m['classification'], ['scalenie', 'dolaczenie', 'para'], true), 'klasyfikacja jest jedną z trzech dozwolonych wartości (' . $m['classification'] . ')');
            $assert(is_string($m['reason']) && $m['reason'] !== '', 'każdy wynik ma niepusty powód w języku naturalnym');
            $assert($m['alignOn'] === null || in_array($m['alignOn'], ['punkt startu', 'termin', 'tempo', 'dystans'], true), 'alignOn jest null albo jedną z czterech dozwolonych osi');
            $assert(strpos($m['reason'], '%') === false || $m['classification'] === 'scalenie', 'procent pojawia się wyłącznie w uzasadnieniu scalenia (pokrycie trasy), nigdy jako "pewność dopasowania"');
        }
    } else {
        echo "POMINIĘTO: brak opublikowanego wydarzenia z turnusem do testu forEdition()\n";
    }

    // --- 4. MatchEngine: forDraft z ręcznie skonstruowanym podmiotem --------

    $draftResult = Models\MatchEngine::forDraft([
        'startDate'       => date('Y-m-d', strtotime('+30 days')),
        'endDate'         => date('Y-m-d', strtotime('+32 days')),
        'regionItemId'    => $byCode['bieszczady']['id'] ?? null,
        'bikeTypeCodes'   => [],
    ]);
    $assert(is_array($draftResult['matches']), 'MatchEngine::forDraft działa na niezapisanych danych formularza bez wyjątku');

    // --- 5. Nigdy pusty wynik (poza całkowitym brakiem opublikowanych wydarzeń) --

    $anyPublished = $pdo->query("
        SELECT COUNT(*) FROM events e JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
    ")->fetchColumn();
    if ((int) $anyPublished > 1) {
        $farFutureDraft = Models\MatchEngine::forDraft([
            'startDate' => date('Y-m-d', strtotime('+400 days')),
            'endDate'   => date('Y-m-d', strtotime('+401 days')),
            'bikeTypeCodes' => ['nieistniejacy_typ_XYZ'],
        ]);
        $assert(!empty($farFutureDraft['matches']) || $farFutureDraft['wideningLevel'] >= 1, 'przy skrajnie niedopasowanych kryteriach silnik poszerza kryteria zamiast zwracać pustkę bez próby (poziom=' . $farFutureDraft['wideningLevel'] . ', wyników=' . count($farFutureDraft['matches']) . ')');
    }

    echo "---\n";
});
