<?php
// core/Models/EventAttendance.php
// "Byłem" — potwierdzenie FAKTYCZNEJ obecności na wyjeździe (migr. 035).
//
// Obecność wisi na ZAPISIE (event_rsvps.id), nie na parze event+user — a zapis
// dotyczy konkretnego turnusu, więc ktoś zapisany na dwa terminy tego samego
// wydarzenia potwierdza każdy osobno. To celowe: peleton i kronika są per
// turnus, bo to na jednym terminie ludzie faktycznie się spotykają.
//
// TRZY stany, nie dwa (patrz migration_035):
//   - brak wiersza    → jeszcze nie odpowiedział
//   - attended = true → był
//   - attended = false→ odpowiedział, że nie dojechał
// Nie wolno traktować braku wiersza jak nieobecności — większość ludzi po
// prostu nie kliknie, a peleton zbudowany na takim założeniu gubiłby realne
// wspólne przejazdy.
namespace Models;

use Core\Database;

class EventAttendance
{
    // Zaznaczenie obecności. Idempotentne — rsvp_id jest UNIQUE, więc powtórne
    // kliknięcie (albo zmiana zdania "był" → "nie dojechał") nadpisuje wiersz
    // zamiast wywalać się na duplikacie.
    //
    // $byOrganizer rozróżnia deklarację własną uczestnika od zatwierdzenia
    // przez prowadzącego. Raz ustawione na true NIE jest cofane przez późniejszą
    // deklarację uczestnika (GREATEST) — potwierdzenie organizatora to mocniejszy
    // sygnał i nie chcemy, żeby uczestnik mógł je zdjąć jednym kliknięciem.
    public static function declare(
        int $rsvpId,
        bool $attended,
        ?int $declaredByUserId,
        bool $byOrganizer = false
    ): void {
        Database::connection()->prepare('
            INSERT INTO event_attendance
                (rsvp_id, attended, declared_at, declared_by_user_id, confirmed_by_organizer)
            VALUES (:rsvp_id, :attended, NOW(), :declared_by, :by_org)
            ON DUPLICATE KEY UPDATE
                attended = VALUES(attended),
                declared_at = NOW(),
                declared_by_user_id = VALUES(declared_by_user_id),
                confirmed_by_organizer = GREATEST(confirmed_by_organizer, VALUES(confirmed_by_organizer))
        ')->execute([
            'rsvp_id'     => $rsvpId,
            'attended'    => $attended ? 1 : 0,
            'declared_by' => $declaredByUserId,
            'by_org'      => $byOrganizer ? 1 : 0,
        ]);

        // Peleton przelicza się TUTAJ, a nie w kontrolerach, celowo: obecność
        // jest jedynym źródłem relacji „jeździliśmy razem", więc niezmiennik
        // „zmieniła się obecność → peleton jest aktualny" nie może być
        // opcjonalny ani zależeć od tego, czy ktoś pamiętał dopisać wywołanie
        // przy kolejnym wejściu (mail, panel organizatora, import...).
        $editionId = self::editionIdForRsvp($rsvpId);
        if ($editionId !== null) {
            RiderConnection::recomputeForEdition($editionId);
        }

        // Discovery (Etap 8) wisi w tym samym miejscu i z tego samego powodu
        // co peleton: potwierdzona obecność jest JEDYNYM źródłem wiedzy o tym,
        // że ten człowiek naprawdę tamtędy jechał, więc niezmiennik „zmieniła
        // się obecność → wszystko pochodne jest aktualne" nie może zależeć od
        // tego, czy ktoś pamiętał dopisać wywołanie w kolejnym kontrolerze.
        // Działa w obie strony — wycofanie obecności kasuje przejazd i
        // wynikające z niego odkrycia.
        //
        // Koszt: przy potwierdzeniu obecności parsowany jest plik GPX
        // wydarzenia (zmierzone: ok. 100 ms dla trasy 160 km / 5 360 punktów).
        // Discovery jest warstwą DODATKOWĄ — gdyby cokolwiek w niej padło, nie
        // wolno mu przewrócić samego potwierdzania obecności.
        try {
            RiderActivity::syncForRsvp($rsvpId, $attended);
        } catch (\Throwable $e) {
            error_log('Discovery: nie udało się przeliczyć przejazdu dla rsvp ' . $rsvpId . ': ' . $e->getMessage());
        }
    }

    private static function editionIdForRsvp(int $rsvpId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT edition_id FROM event_rsvps WHERE id = :id');
        $stmt->execute(['id' => $rsvpId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    // Stan obecności usera na JEDNYM turnusie — pod pytanie "Byłeś?" na stronie
    // zakończonego wydarzenia. Zwraca null, gdy ten user nie ma tu zapisu
    // (wtedy nie ma o co pytać). 'attended' jest null, gdy zapis istnieje, ale
    // nikt jeszcze nie odpowiedział — to właśnie stan, w którym pokazujemy pytanie.
    //
    // Tylko zapisy 'potwierdzony': kto miał tylko status "zainteresowany" albo
    // nie dopłacił, tego nie pytamy, czy był.
    public static function forEditionAndUser(int $editionId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare("
            SELECT r.id AS rsvp_id, a.attended, a.confirmed_by_organizer
            FROM event_rsvps r
            JOIN dictionary_items rdi
              ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            LEFT JOIN event_attendance a ON a.rsvp_id = r.id
            WHERE r.edition_id = :edition_id AND r.user_id = :user_id
        ");
        $stmt->execute(['edition_id' => $editionId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'rsvpId'      => (int) $row['rsvp_id'],
            // null = nie odpowiedział; bool = odpowiedź
            'attended'    => $row['attended'] === null ? null : (bool) $row['attended'],
            'byOrganizer' => (bool) $row['confirmed_by_organizer'],
        ];
    }

    /**
     * „MOJE PRZEJAZDY" — wszystkie potwierdzone zapisy tej osoby, z odpowiedzią
     * na trzy pytania naraz: byłem?, jest ślad?, ile z tego wyszło.
     *
     * Osobno od `ridesForUser()`, bo tamta jest publiczną historią (wyłącznie
     * `attended = 1`), a to jest ekran ZARZĄDCZY: najcenniejsze wiersze to
     * właśnie te, w których czegoś BRAKUJE — nieodpowiedziana obecność i wyjazd
     * bez śladu. Filtr na `attended = 1` usunąłby z tabeli dokładnie to, po co
     * się na nią wchodzi.
     *
     * Tylko zapisy `potwierdzony` — ta sama reguła co w `forEditionAndUser()`:
     * kogo nie pytamy „byłeś?", tego nie ma po co pokazywać w tabeli obecności.
     *
     * Jedno zapytanie na całą tabelę. Podzapytania zamiast JOIN-ów po
     * `edition_tracks`, bo śladów na turnus bywa kilka (wielodniówka ma jeden
     * na dzień) i JOIN zwielokrotniłby wiersze wyjazdów.
     */
    public static function myRidesForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT r.id AS rsvp_id,
                   e.slug, e.title,
                   ed.id AS edition_id, ed.start_date, ed.end_date,
                   reg.name AS region_label,
                   COALESCE(et.total_distance_km, 0) AS planned_km,
                   a.attended, a.confirmed_by_organizer,
                   (SELECT COUNT(*) FROM edition_tracks t
                     WHERE t.edition_id = ed.id AND t.user_id = r.user_id) AS own_tracks,
                   (SELECT COUNT(*) FROM edition_tracks t
                     WHERE t.edition_id = ed.id AND t.user_id IS NULL) AS event_tracks,
                   act.id AS activity_id, act.cells_new, act.distance_km AS ridden_km,
                   COALESCE((SELECT SUM(pt.points) FROM point_transactions pt
                              WHERE pt.activity_id = act.id), 0) AS points
              FROM event_rsvps r
              JOIN dictionary_items rdi
                ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
              JOIN event_editions ed ON ed.id = r.edition_id
              JOIN events e ON e.id = r.event_id
              LEFT JOIN event_attendance a ON a.rsvp_id = r.id
              LEFT JOIN event_totals et ON et.event_id = e.id
              LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
              LEFT JOIN rider_activities act
                ON act.user_id = r.user_id AND act.edition_id = ed.id
             WHERE r.user_id = :user_id
             ORDER BY ed.start_date DESC, ed.id DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * CO ZOSTAŁO DO DOKOŃCZENIA po odbytych wyjazdach — podpowiedzi na WŁASNYM
     * profilu (2026-09-13). Tylko turnusy, które już się skończyły i mają
     * potwierdzony zapis. Każdy wiersz niesie gotowe flagi, a widok decyduje
     * wyłącznie o tym, jak je powiedzieć:
     *   `attended` NULL          — nikt nie odpowiedział „byłem" (nic się nie liczy),
     *   `has_track` 0            — był, ale bez śladu: pola, punkty i emblemat nie zaliczone,
     *   `emblem_pending` 1       — wyjazd ma emblemat, ślad jest, a emblematu brak,
     *                              czyli ślad nie pokrył całej trasy,
     *   `own_recaps` 0 + completed — skończone, a relacji z tej osoby nie ma.
     * Jedno zapytanie, podzapytania per wiersz — ta sama konwencja co
     * `myRidesForUser()` wyżej.
     */
    public static function profileTodoForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, ed.id AS edition_id, ed.start_date,
                   COALESCE(ed.end_date, ed.start_date) AS end_date,
                   st.code AS status_code,
                   a.attended,
                   ((SELECT COUNT(*) FROM edition_tracks t
                      WHERE t.edition_id = ed.id AND (t.user_id = r.user_id OR t.user_id IS NULL)) > 0) AS has_track,
                   (SELECT COUNT(*) FROM event_recaps rc
                     WHERE rc.edition_id = ed.id AND rc.author_user_id = r.user_id) AS own_recaps,
                   (e.emblem_id IS NOT NULL
                    AND EXISTS (SELECT 1 FROM edition_tracks t2 WHERE t2.edition_id = ed.id AND t2.user_id = r.user_id)
                    AND NOT EXISTS (SELECT 1 FROM emblem_awards ea
                                     WHERE ea.user_id = r.user_id AND ea.emblem_id = e.emblem_id)) AS emblem_pending
              FROM event_rsvps r
              JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
              JOIN event_editions ed ON ed.id = r.edition_id
              JOIN events e ON e.id = r.event_id
              LEFT JOIN dictionary_items st ON st.id = e.status_item_id
              LEFT JOIN event_attendance a ON a.rsvp_id = r.id
             WHERE r.user_id = :user_id
               AND COALESCE(ed.end_date, ed.start_date) < CURDATE()
             ORDER BY COALESCE(ed.end_date, ed.start_date) DESC, ed.id DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    // Mapa rsvp_id => stan, pod listę uczestników organizatora (jedno zapytanie
    // na całą tabelkę zamiast pytania per wiersz).
    public static function mapByRsvpForEvent(int $eventId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT a.rsvp_id, a.attended, a.confirmed_by_organizer
            FROM event_attendance a
            JOIN event_rsvps r ON r.id = a.rsvp_id
            WHERE r.event_id = :event_id
        ');
        $stmt->execute(['event_id' => $eventId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['rsvp_id']] = [
                'attended'    => (bool) $row['attended'],
                'byOrganizer' => (bool) $row['confirmed_by_organizer'],
            ];
        }
        return $map;
    }

    // Kto FAKTYCZNIE był na danym turnusie — źródło prawdy dla peletonu (Etap 2)
    // i składu kroniki (Etap 4). Świadomie osobna metoda od
    // EventRsvp::confirmedParticipantsForEdition(): tamta mówi "kto się zapisał",
    // ta "kto pojechał", i to jest cała różnica, na której stoi ten kierunek.
    public static function attendedForEdition(int $editionId): array
    {
        // public_slug pod podpisy w kronice (Etap 4) — skład prowadzi do profili.
        // roster_visible NIE filtruje tutaj: to jest lista OSÓB, KTÓRE BYŁY,
        // czyli fakt o wyjeździe. Ukrycie działa na poziomie prezentacji
        // (widok nie linkuje i nie pokazuje imienia ukrytej osoby), nie przez
        // wycięcie jej z historii — patrz Resources\ChronicleResource.
        $stmt = Database::connection()->prepare('
            SELECT u.id AS user_id, u.name, u.email, u.public_slug, u.roster_visible, u.avatar_url
            FROM event_attendance a
            JOIN event_rsvps r ON r.id = a.rsvp_id
            JOIN users u ON u.id = r.user_id
            WHERE r.edition_id = :edition_id AND a.attended = 1
            ORDER BY u.name ASC
        ');
        $stmt->execute(['edition_id' => $editionId]);
        return $stmt->fetchAll();
    }

    // Wyjazdy, na których user FAKTYCZNIE był — historia publicznego profilu
    // rowerzysty (Etap 3) i podstawa wszystkich jego liczb (regiony, dystans,
    // powroty w ten sam region). Świadomie NIE reużywamy Event::forParticipant():
    // tamta lista jest pod „Moje wydarzenia" i niesie też statusy prywatne
    // ('zainteresowany', 'oczekuje_platnosci'), których na cudzym profilu
    // pokazywać nie wolno.
    //
    // Bez limitu: profil liczy z tego agregaty (ilu regionów, ile km), więc
    // ucięcie listy zafałszowałoby liczby. Widok bierze do wyświetlenia tyle,
    // ile potrzebuje.
    public static function ridesForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, e.cover_photo_url,
                   ed.id AS edition_id, ed.start_date,
                   COALESCE(et.total_distance_km, 0) AS distance_km,
                   COALESCE(et.duration_days, 1) AS duration_days,
                   reg.name AS region_label,
                   reg.code AS region_code,
                   typ.code AS event_type_code,
                   -- Trasa ZAPOWIADANA wyjazdu: PIERWSZY etap z plikiem.
                   -- Wielodniówka ma ślad per dzień; na mapie całego życia
                   -- rowerzysty rysujemy jeden reprezentatywny, inaczej jeden
                   -- wyjazd zdominowałby obraz liczbą warstw.
                   -- UWAGA: to NIE jest to, z czego liczą się pola odkryć —
                   -- te biorą się wyłącznie z `edition_tracks` (migr. 042).
                   -- Profil rysuje tę linię przerywaną i podpisuje jako
                   -- zapowiedź, właśnie po to, żeby różnicy nie dało się
                   -- pomylić z brakiem odkryć.
                   (SELECT s.gpx_url FROM event_stages s
                     WHERE s.event_id = e.id AND s.gpx_url IS NOT NULL
                     ORDER BY s.day_number ASC LIMIT 1) AS gpx_url
            FROM event_attendance a
            JOIN event_rsvps r ON r.id = a.rsvp_id
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN events e ON e.id = r.event_id
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN (
                    SELECT er.event_id,
                           GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name,
                           SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ','), ',', 1) AS code
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
            LEFT JOIN dictionary_items typ ON typ.id = e.event_type_item_id
            WHERE r.user_id = :user_id AND a.attended = 1
            ORDER BY ed.start_date DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    // Indeks kronik — /relacje (Etap 4). Turnusy, które faktycznie się odbyły
    // i mają potwierdzoną obecność, od najnowszych. Kronika istnieje dokładnie
    // wtedy, gdy istnieje skład, więc ta lista JEST listą kronik.
    public static function chronicleIndex(int $limit = 40): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, e.cover_photo_url,
                   ed.id AS edition_id, ed.start_date,
                   reg.name AS region_label,
                   COUNT(DISTINCT r.user_id) AS people_count,
                   COALESCE(et.total_distance_km, 0) AS distance_km
            FROM event_attendance a
            JOIN event_rsvps r ON r.id = a.rsvp_id
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN events e ON e.id = r.event_id
            JOIN dictionary_items st
              ON st.id = e.status_item_id AND st.code = 'completed'
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN (
                    SELECT er.event_id,
                           GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name,
                           SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ','), ',', 1) AS code
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
            WHERE a.attended = 1
            GROUP BY e.slug, e.title, e.cover_photo_url, ed.id, ed.start_date,
                     reg.name, et.total_distance_km
            ORDER BY ed.start_date DESC
            LIMIT " . max(1, $limit) . "
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // „Dla N osób to był pierwszy raz w tym regionie" — kronika (Etap 4).
    //
    // Odkrywanie pokazane jako fakt ZBIOROWY, nie jako odznaka dla jednostki:
    // mówimy ILU, nie KTO. Region bierzemy z wydarzenia; jeśli wydarzenie go nie
    // ma, zwracamy 0 (nie ma o czym mówić) zamiast zgadywać.
    public static function firstTimersInRegionForEdition(int $editionId): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*)
            FROM event_rsvps r
            JOIN event_attendance a ON a.rsvp_id = r.id AND a.attended = 1
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN events e ON e.id = r.event_id
            WHERE r.edition_id = :edition_id
              AND EXISTS (SELECT 1 FROM event_regions er WHERE er.event_id = e.id)
              AND NOT EXISTS (
                  SELECT 1
                  FROM event_rsvps r2
                  JOIN event_attendance a2 ON a2.rsvp_id = r2.id AND a2.attended = 1
                  JOIN event_editions ed2 ON ed2.id = r2.edition_id
                  JOIN events e2 ON e2.id = r2.event_id
                  -- „Ten sam region" = DZIELĄ choć jeden (migr. 074) — wcześniej
                  -- to było porównanie skalarne, dziś event może mieć kilka.
                  WHERE r2.user_id = r.user_id
                    AND EXISTS (
                        SELECT 1 FROM event_regions er1
                        JOIN event_regions er2 ON er2.region_item_id = er1.region_item_id
                        WHERE er1.event_id = e.id AND er2.event_id = e2.id
                    )
                    AND ed2.start_date < ed.start_date
              )
        ');
        $stmt->execute(['edition_id' => $editionId]);
        return (int) $stmt->fetchColumn();
    }

    // Ilu z zapisanych już odpowiedziało — pod pasek postępu na liście
    // uczestników ("potwierdzono 8 z 14"), żeby organizator widział, czy warto
    // jeszcze domykać listę ręcznie.
    public static function answeredCountForEvent(int $eventId): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*)
            FROM event_attendance a
            JOIN event_rsvps r ON r.id = a.rsvp_id
            WHERE r.event_id = :event_id
        ');
        $stmt->execute(['event_id' => $eventId]);
        return (int) $stmt->fetchColumn();
    }
}
