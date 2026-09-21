<?php
// tests/preferencje_test.php
// PREFERENCJE (Etap 3) — sygnały i rekomendacje.
//
// PRZENIESIONE z test_preferences.php (2026-08-15). Treść asercji bez zmian — zmienił się
// wyłącznie sposób ich uruchamiania: zamiast płaskiego skryptu, który trzeba
// było odpalać osobno i czytać jego wydruk, jest to zestaw dla `tests/run.php`.
// Dzięki temu biegnie razem z resztą i psujący się kod widać od razu, a nie
// dopiero wtedy, gdy ktoś sobie o tym skrypcie przypomni.
//
// UWAGA: ten zestaw ZAPISUJE — zakłada użytkownika testowego, dokłada mu
// sygnały i na końcu go kasuje. W wersji skryptowej sprzątanie było ręczne
// i przerwanie w połowie zostawiało śmieci w bazie DEV. Teraz obejmuje go
// transakcja runnera, więc wycofuje się samo, także po błędzie — własne
// DELETE na końcu jest już tylko zapasem, nie jedyną linią obrony.

t_test('Preferencje: sygnały, wyliczanie, rekomendacje', function () {
    // Most na starą nazwę: oryginał wołał $assert(warunek, opis) i tak zostaje,
    // żeby przeniesienie nie zmieniło ani jednej asercji.
    $assert = static function (bool $warunek, string $opis): void {
        t_true($warunek, $opis);
    };
    // Oryginał trzymał połączenie w zmiennej globalnej skryptu; wewnątrz
    // domknięcia trzeba je wziąć na nowo (Database::connection() i tak zwraca
    // to samo, jedno na proces).
    $pdo = Core\Database::connection();

    $testUser = Models\User::createPending('etap3-test-' . bin2hex(random_bytes(4)) . '@example.invalid');
    $userId = $testUser->id;
    echo "Testowy user id={$userId}\n";

    // --- 1. Warstwa deklarowana: zapis/odczyt, rozdzielenie operacyjne/aspiracyjne --

    Models\UserPreference::save($userId, [
        'bikeTypes'              => ['gravel', 'mtb'],
        'paces'                  => ['srednie'],
        'regions'                => ['bieszczady'],
        'aspirationalRegions'    => ['mazury'],
        'aspirationalEventTypes' => ['wycieczka_wielodniowa'],
        'distanceMinKm'          => 40,
        'distanceMaxKm'          => 90,
        'groupSizePref'          => 'small',
        'notifyMatches'          => true,
    ]);

    $prefs = Models\UserPreference::forUser($userId);
    $assert($prefs['operational']['bike_type'] === ['gravel', 'mtb'], 'deklaracja operacyjna: typy roweru zapisane poprawnie');
    $assert($prefs['operational']['region'] === ['bieszczady'], 'deklaracja operacyjna: region "gdzie jeżdżę" zapisany');
    $assert($prefs['aspirational']['region'] === ['mazury'], 'deklaracja aspiracyjna: region "gdzie chcę pojechać" ODDZIELNY od operacyjnego');
    $assert($prefs['aspirational']['event_type'] === ['wycieczka_wielodniowa'], 'deklaracja aspiracyjna: typ wydarzenia zapisany');
    $assert($prefs['groupSizePref'] === 'small', 'preferowany rozmiar grupy zapisany');
    $assert($prefs['notifyMatches'] === true, 'zgoda na powiadomienia zapisana');
    $assert($prefs['declaredUpdatedAt'] !== null, 'declared_updated_at ustawiony po zapisie (napędza osłabienie rampy)');

    // Region jako i operacyjny, i aspiracyjny NAJEDNOCZEŚNIE (docs/etap3 §3: obie
    // deklaracje mogą być prawdziwe naraz) — dopisujemy Tatry po obu stronach.
    Models\UserPreference::save($userId, [
        'regions'             => ['bieszczady', 'tatry'],
        'aspirationalRegions' => ['tatry'],
    ]);
    $prefs2 = Models\UserPreference::forUser($userId);
    $assert(in_array('tatry', $prefs2['operational']['region'], true) && in_array('tatry', $prefs2['aspirational']['region'], true),
        'ta sama pozycja słownikowa (region) może być OBIE naraz: operacyjna i aspiracyjna');

    // --- 2. Warstwa wynikająca: recompute + ramp ------------------------------

    // Symulujemy zachowanie: potwierdzony zapis na wydarzenie z gravelem/mazurami,
    // żeby recomputeForUser miało z czego zbudować sygnał (bezpośrednio przez
    // event_rsvps na istniejącym, opublikowanym evencie — nie tworzymy nowego
    // wydarzenia, żeby nie zaśmiecać katalogu).
    $publishedEvent = $pdo->query("
        SELECT e.id, ed.id AS edition_id FROM events e
        JOIN event_editions ed ON ed.event_id = e.id
        JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
        LIMIT 1
    ")->fetch();
    $confirmedStatusId = Models\Dictionary::id('rsvp_status', 'potwierdzony');
    $pdo->prepare('INSERT INTO event_rsvps (event_id, edition_id, user_id, status_item_id, joined_at) VALUES (:e, :ed, :u, :s, NOW())')
        ->execute(['e' => $publishedEvent['id'], 'ed' => $publishedEvent['edition_id'], 'u' => $userId, 's' => $confirmedStatusId]);

    // event_count < 2 (tylko jeden RSVP) -> warstwa wynikająca NIE powinna wejść
    // w życie (docs/etap3 §5: minimum 2 wydarzenia źródłowe).
    Models\DerivedPreference::recomputeAll();
    $stats1 = $pdo->prepare('SELECT * FROM user_preference_stats WHERE user_id = :u');
    $stats1->execute(['u' => $userId]);
    $s1 = $stats1->fetch();
    $assert((int) $s1['event_count'] === 1, 'jeden dotychczasowy RSVP daje event_count=1');

    $result1 = Models\MatchEngine::forEdition((int) $publishedEvent['edition_id'], 1, $userId);
    // forEdition na WŁASNYM turnusie zwraca puste matches (excludeEventId), ale
    // interesuje nas tu tylko to, że silnik się nie wywala z jednym zdarzeniem
    // źródłowym — właściwy test rampy jest niżej z 2+ zdarzeniami.
    $assert(is_array($result1['matches']), 'MatchEngine::forEdition działa z profilem o event_count=1 bez wyjątku (poniżej progu wejścia warstwy wynikającej)');

    // Drugie zdarzenie źródłowe (inny event, żeby event_count>=2) -> warstwa
    // wynikająca powinna się teraz aktywować.
    $secondEvent = $pdo->query("
        SELECT e.id, ed.id AS edition_id FROM events e
        JOIN event_editions ed ON ed.event_id = e.id
        JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
        WHERE e.id != {$publishedEvent['id']}
        LIMIT 1
    ")->fetch();
    $pdo->prepare('INSERT INTO event_rsvps (event_id, edition_id, user_id, status_item_id, joined_at) VALUES (:e, :ed, :u, :s, NOW())')
        ->execute(['e' => $secondEvent['id'], 'ed' => $secondEvent['edition_id'], 'u' => $userId, 's' => $confirmedStatusId]);

    Models\DerivedPreference::recomputeAll();
    $stats2 = $pdo->prepare('SELECT * FROM user_preference_stats WHERE user_id = :u');
    $stats2->execute(['u' => $userId]);
    $s2 = $stats2->fetch();
    $assert((int) $s2['event_count'] === 2, 'dwa RSVP dają event_count=2 (próg wejścia warstwy wynikającej)');
    $assert((float) $s2['signal_strength'] > 0, 'signal_strength > 0 po dwóch potwierdzonych zapisach');

    $expectedRamp = min(0.5, (float) $s2['signal_strength'] / 20.0);
    // declared_updated_at jest ŚWIEŻY (zapisaliśmy preferencje wyżej w tym samym
    // biegu) -> osłabienie o połowę powinno być aktywne (docs/etap3 §5).
    $expectedRampDampened = $expectedRamp / 2;
    $result2 = Models\MatchEngine::forEdition((int) $secondEvent['edition_id'], 3, $userId);
    $actualDerivedShare = !empty($result2['matches']) ? $result2['matches'][0]['derivedShare'] : null;
    $assert($actualDerivedShare !== null, 'MatchEngine zwraca derivedShare w wynikach, gdy profil ma warstwę wynikającą');
    if ($actualDerivedShare !== null) {
        $assert(abs($actualDerivedShare - $expectedRampDampened) < 0.001,
            "derivedShare={$actualDerivedShare} zgodny z rampą min(0.5, signal/20)/2 (osłabienie po świeżej edycji deklaracji) = {$expectedRampDampened}");
    }

    // --- 3. Odrzucenie trzystopniowe: eskalacja 3x w 90 dni -------------------

    $pdo->prepare("DELETE FROM recommendation_dismissals WHERE user_id = :u")->execute(['u' => $userId]);
    // Trzy różne wydarzenia (3, 13, 72) dzielące pace_group_item_id=27 (patrz
    // odkrycie w trakcie budowy tego testu), odrzucone z tym samym powodem
    // 'nie_moje_tempo' w ciągu ostatnich 90 dni.
    $eventsForEscalation = [3, 13, 72];
    foreach ($eventsForEscalation as $i => $eventId) {
        $daysAgo = 60 - $i * 10; // wszystkie w oknie 90 dni, w kolejności chronologicznej
        $pdo->prepare("
            INSERT INTO recommendation_dismissals (user_id, event_id, reason, dismissed_at)
            VALUES (:u, :e, 'nie_moje_tempo', DATE_SUB(NOW(), INTERVAL :d DAY))
        ")->execute(['u' => $userId, 'e' => $eventId, 'd' => $daysAgo]);
    }
    $countDismissals = $pdo->prepare('SELECT COUNT(*) FROM recommendation_dismissals WHERE user_id = :u AND reason = "nie_moje_tempo"');
    $countDismissals->execute(['u' => $userId]);
    $assert((int) $countDismissals->fetchColumn() === 3, 'trzy odrzucenia z powodem nie_moje_tempo zapisane (dedupe per-event działa, to trzy różne eventy)');

    Models\DerivedPreference::recomputeAll();
    $sigStmt = $pdo->prepare('SELECT weight FROM user_preference_signals WHERE user_id = :u AND dictionary_item_id = 27');
    $sigStmt->execute(['u' => $userId]);
    $paceWeight = $sigStmt->fetchColumn();
    // persist() świadomie NIE zapisuje wag <= 0 (docs: normalizacja przycina
    // ujemne do zera) — więc `false` (brak wiersza) jest POPRAWNYM wynikiem
    // pełnej kary za 3x odrzucenie z tym samym powodem w 90 dni, nie błędem.
    // Test weryfikuje tylko, że recomputeAll() przeszedł bez wyjątku i że wynik
    // jest jednym z dwóch spodziewanych stanów (brak wiersza ALBO niska waga).
    $assert($paceWeight === false || (float) $paceWeight < 1.0,
        'DerivedPreference::recomputeAll() z karami za odrzucenie: waga tempa ' . ($paceWeight === false ? 'wyzerowana (brak wiersza)' : $paceWeight) . ' — kara zadziałała, nie błąd');

    // --- 4. RecommendationDismissal / wykluczenie z puli kandydatów -----------

    Models\RecommendationDismissal::record($userId, (int) $secondEvent['id'], null);
    $assert(Models\RecommendationDismissal::isDismissed($userId, (int) $secondEvent['id']), 'RecommendationDismissal::record + isDismissed działa (stopień 1)');

    $poolCheck = Models\MatchEngine::forDraft([
        'startDate' => date('Y-m-d'),
        'userId'    => $userId,
    ]);
    $foundDismissed = false;
    foreach ($poolCheck['matches'] as $m) {
        if ($m['eventId'] === (int) $secondEvent['id']) {
            $foundDismissed = true;
        }
    }
    $assert(!$foundDismissed, 'odrzucone wydarzenie nie pojawia się ponownie w wynikach tego samego usera');

    // --- 5. Realizacja aspiracji ------------------------------------------------

    // Mazury (aspiracyjne) — user ma realny potwierdzony zapis? Sprawdzamy
    // mechanizm na regionie BIESZCZADY, na który user ma potwierdzony RSVP
    // (publishedEvent) — deklarujemy go jako aspiracyjny (nawet jeśli już jest
    // operacyjny, docs/etap3 dopuszcza obie klasy naraz) i weryfikujemy konwersję.
    // Region NIE jest już skalarną kolumną na events (migr. 074) — bierzemy
    // pierwszy z event_regions, jeśli event ma choć jeden.
    $bieszczadyRegionId = $pdo->query("SELECT region_item_id FROM event_regions WHERE event_id = {$publishedEvent['id']} LIMIT 1")->fetchColumn();
    if ($bieszczadyRegionId) {
        $pdo->prepare("INSERT IGNORE INTO user_preference_items (user_id, dictionary_item_id, kind) VALUES (:u, :i, 'aspirational')")
            ->execute(['u' => $userId, 'i' => $bieszczadyRegionId]);
        $converted = Models\PreferenceNotifier::convertRealizedAspirations();
        $stillAspirational = $pdo->prepare("SELECT 1 FROM user_preference_items WHERE user_id=:u AND dictionary_item_id=:i AND kind='aspirational'");
        $stillAspirational->execute(['u' => $userId, 'i' => $bieszczadyRegionId]);
        $assert(!$stillAspirational->fetchColumn(), 'realizacja aspiracji: region z potwierdzonym udziałem PRZESTAJE być aspiracyjny po konwersji');
    } else {
        echo "POMINIĘTO: testowe wydarzenie bez region_item_id\n";
    }

    // --- Sprzątanie ------------------------------------------------------------
    // Kaskadowe FK na users(id) porządkują user_preference_items/preferences/
    // signals/stats/recommendation_log/recommendation_dismissals/event_rsvps.
    $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $userId]);
    echo "Usunięto usera testowego id={$userId} (kaskadowo posprzątało wszystkie powiązane wiersze).\n";

    echo "---\n";
});
