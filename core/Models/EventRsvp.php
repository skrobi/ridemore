<?php
// core/Models/EventRsvp.php
// Zapis dotyczy KONKRETNEGO turnusu (Models\EventEdition), nie całego
// wydarzenia — ten sam user może mieć osobny zapis na każdy termin tego
// samego wydarzenia (limit miejsc i lista uczestników liczą się OSOBNO per
// turnus). event_rsvps.event_id zostaje jako denormalizacja pod zapytania o
// relację z CAŁYM wydarzeniem, niezależnie od konkretnego terminu (uprawnienia
// do opinii/relacji, wysyłka zaproszeń po zakończeniu, odwołanie całego eventu).
namespace Models;

use Core\Database;

class EventRsvp
{
    // Do tej pory liczyliśmy tylko COUNT(*) potwierdzonych zapisów (Event::upcoming()
    // itd.) — to pierwsze miejsce, gdzie faktycznie wypisujemy wiersze uczestników,
    // pod maile z zaproszeniem do opinii. Po CAŁYM wydarzeniu (wszystkie turnusy) —
    // zaproszenie do opinii idzie raz, gdy WSZYSTKIE terminy się skończyły
    // (patrz Event::processCompletions()).
    public static function confirmedParticipants(int $eventId): array
    {
        // edition_id dołożone pod link "Potwierdź, że byłeś" w mailu po wyjeździe
        // (patrz Event::sendReviewInvitesIfNeeded) — obecność potwierdza się per
        // TURNUS, więc link musi wskazywać właściwy termin, nie dać się rozwiązać
        // domyślnemu. MIN() + GROUP BY zamiast DISTINCT, żeby ktoś zapisany na dwa
        // terminy tego samego wydarzenia nadal dostał JEDEN e-mail (jak dotąd),
        // a nie po jednym na termin.
        $stmt = Database::connection()->prepare("
            SELECT u.id AS user_id, u.name, u.email, MIN(r.edition_id) AS edition_id
            FROM event_rsvps r
            JOIN users u ON u.id = r.user_id
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            WHERE r.event_id = :event_id
            GROUP BY u.id, u.name, u.email
        ");
        $stmt->execute(['event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    // Jak wyżej, ale dla JEDNEGO turnusu — pod listę awatarów "N osób już
    // jedzie" w panelu zapisu (szablony/wydarzenie.html .book__who). Przy
    // wydarzeniu z kilkoma terminami confirmedParticipants() (całe wydarzenie)
    // pokazywałby też osoby zapisane na INNY termin niż aktualnie oglądany —
    // ta wersja jest poprawna per-turnus.
    public static function confirmedParticipantsForEdition(int $editionId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT DISTINCT u.id AS user_id, u.name, u.email
            FROM event_rsvps r
            JOIN users u ON u.id = r.user_id
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            WHERE r.edition_id = :edition_id
        ");
        $stmt->execute(['edition_id' => $editionId]);
        return $stmt->fetchAll();
    }

    // "Kto jedzie" — skład turnusu pod sekcję na stronie wydarzenia (Etap 1).
    // Świadomie JEDNO zapytanie na dwie grupy zamiast dwóch bliźniaczych metod:
    // różnią się wyłącznie kodem statusu, a strona wydarzenia potrzebuje obu
    // naraz. Zwraca ['confirmed' => [...], 'interested' => [...]].
    //
    // Kształt wiersza celowo IDENTYCZNY z confirmedParticipantsForEdition()
    // (user_id/name/email), żeby istniejący blok awatarów w panelu zapisu
    // (.book__who w event-page.php) dało się zasilić z 'confirmed' bez zmiany
    // widoku. joined_at tylko do sortowania — kto pierwszy, ten wyżej.
    //
    // 'oczekuje_platnosci' NIE wchodzi do żadnej z grup: ta osoba zarezerwowała
    // miejsce, ale nie zapłaciła, więc pokazanie jej jako "jedzie" byłoby
    // obietnicą bez pokrycia. To zgodne z tym, co .book__who liczył do tej pory.
    public static function rosterForEdition(int $editionId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT DISTINCT u.id AS user_id, u.name, u.email, u.roster_visible, u.public_slug,
                   u.avatar_url,
                   rdi.code AS status_code, r.joined_at
            FROM event_rsvps r
            JOIN users u ON u.id = r.user_id
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id
            WHERE r.edition_id = :edition_id
              AND rdi.code IN ('potwierdzony', 'zainteresowany')
            ORDER BY r.joined_at ASC
        ");
        $stmt->execute(['edition_id' => $editionId]);

        // Kto wyłączył się z list (users.roster_visible = 0, migr. 037) NIE
        // trafia na listę twarzy/imion, ale WCHODZI do licznika. Odjęcie go od
        // liczby uczestników byłoby kłamstwem wobec oglądającego i psułoby
        // dowód społeczny organizatorowi — ukrycie dotyczy tożsamości, nie faktu.
        $roster = [
            'confirmed' => [], 'confirmedTotal' => 0,
            'interested' => [], 'interestedTotal' => 0,
        ];
        foreach ($stmt->fetchAll() as $row) {
            $bucket = $row['status_code'] === 'potwierdzony' ? 'confirmed' : 'interested';
            $roster[$bucket . 'Total']++;
            if ((int) $row['roster_visible'] === 1) {
                $roster[$bucket][] = $row;
            }
        }
        return $roster;
    }

    // Pod odwołanie CAŁEGO eventu (patrz Event::cancel() — odwołuje wszystkie
    // turnusy naraz) — trzeba powiadomić nie tylko 'potwierdzony', ale też
    // 'oczekuje_platnosci'/'lista_rezerwowa', bo ich zapisy też zostają anulowane,
    // mimo że wcześniej nic nie wpłacili. Zwraca też status_code, żeby wywołujący
    // wiedział, komu faktycznie należy się info o zwrocie.
    public static function participantsWithStatuses(int $eventId, array $statusCodes): array
    {
        $placeholders = implode(',', array_fill(0, count($statusCodes), '?'));
        $stmt = Database::connection()->prepare("
            SELECT u.id AS user_id, u.name, u.email, rdi.code AS status_code
            FROM event_rsvps r
            JOIN users u ON u.id = r.user_id
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id
            WHERE r.event_id = ? AND rdi.code IN ($placeholders)
        ");
        $stmt->execute(array_merge([$eventId], $statusCodes));
        return $stmt->fetchAll();
    }

    // „JEDZIE NA:" na publicznym profilu rowerzysty — najbliższy NADCHODZĄCY
    // wyjazd tej osoby. To jest element, przez który profil zamyka pętlę: nie
    // jest podsumowaniem przeszłości, tylko zaproszeniem do wspólnej jazdy.
    //
    // WYŁĄCZNIE status 'potwierdzony' i tylko wydarzenia opublikowane —
    // 'zainteresowany'/'oczekuje_platnosci' to sygnały prywatne (rozważam,
    // nie zapłaciłem), których na CUDZYM profilu pokazywać nie wolno.
    public static function publicNextRideForUser(int $userId): ?array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, ed.id AS edition_id, ed.start_date,
                   reg.name AS region_label
            FROM event_rsvps r
            JOIN dictionary_items rdi
              ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN events e ON e.id = r.event_id
            JOIN dictionary_items st
              ON st.id = e.status_item_id AND st.code IN ('published', 'full')
            LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
            WHERE r.user_id = :user_id AND ed.start_date >= CURDATE()
            ORDER BY ed.start_date ASC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Bramka uprawnień do opinii/relacji — czy ten user ma potwierdzony zapis na
    // KTÓRYKOLWIEK turnus tego eventu (niezależnie od tego, czy jest organizatorem).
    // Celowo po event_id, nie edition_id — udział w JEDNYM terminie wystarcza do
    // wystawienia opinii o całym wyjeździe/organizatorze.
    public static function isConfirmedParticipant(int $eventId, int $userId): bool
    {
        $stmt = Database::connection()->prepare("
            SELECT 1 FROM event_rsvps r
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            WHERE r.event_id = :event_id AND r.user_id = :user_id
        ");
        $stmt->execute(['event_id' => $eventId, 'user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    // Czy istnieje JAKIKOLWIEK zapis (dowolny status — nawet samo
    // "zainteresowany") na to wydarzenie, na KTÓRYKOLWIEK jego turnus —
    // bramka bezpieczeństwa dla trwałego usunięcia (dopisane 2026-08-09,
    // patrz EventController::delete()). Event bez ani jednego zapisu jest
    // bezpieczny do skasowania niezależnie od statusu (draft/published/
    // cancelled/completed — sam status nic tu nie mówi o tym, czy ktoś
    // faktycznie się zaangażował); event z choćby jednym zostaje chroniony,
    // dla niego jest tylko "odwołaj" (zachowuje historię).
    public static function existsAnyForEvent(int $eventId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM event_rsvps WHERE event_id = :event_id LIMIT 1');
        $stmt->execute(['event_id' => $eventId]);
        return (bool) $stmt->fetchColumn();
    }

    // Przycisk "Dołącz do wyjazdu" na stronie eventu — idempotentne (ponowne
    // kliknięcie nie tworzy duplikatu, UNIQUE(edition_id, user_id) + upsert).
    // Bez limitu miejsc zawsze 'potwierdzony'; przy limicie i komplecie —
    // 'lista_rezerwowa'. Limit i licznik miejsc liczą się OSOBNO dla tego
    // turnusu (edition_id), nie całego wydarzenia. Zwraca kod przyznanego statusu.
    public static function join(int $editionId, int $userId): string
    {
        $pdo = Database::connection();
        // WŁASNA TRANSAKCJA TYLKO WTEDY, GDY NIKT JEJ JESZCZE NIE OTWORZYŁ —
        // ten sam wzorzec co Models\AppLoginToken::consume() i z tego samego
        // powodu: PDO nie zagnieżdża beginTransaction(), więc bezwarunkowe
        // wywołanie wysadza każdego, kto woła join() wewnątrz własnej
        // transakcji (pierwszy taki wywołujący to uruchamiacz testów, który
        // owija w nią każdy przypadek). Blokada FOR UPDATE niżej działa
        // identycznie w obu przypadkach — różni się tylko to, KTO domyka
        // transakcję.
        $wlasna = !$pdo->inTransaction();
        if ($wlasna) {
            $pdo->beginTransaction();
        }

        try {
            // Blokada na wierszu TURNUSU serializuje równoległe join() dla tego
            // samego terminu. Bez tego COUNT (niżej) i INSERT to dwa oddzielne
            // zapytania (TOCTOU) — dwóch userów klikających "Dołącz" na ostatnie
            // wolne miejsce w tej samej chwili mogliby oba zobaczyć tę samą
            // liczbę przed jakimkolwiek INSERT-em i oboje dostać 'potwierdzony',
            // przekraczając max_participants TEGO turnusu.
            $editionStmt = $pdo->prepare('SELECT event_id, max_participants FROM event_editions WHERE id = :edition_id FOR UPDATE');
            $editionStmt->execute(['edition_id' => $editionId]);
            $edition = $editionStmt->fetch();
            if (!$edition) {
                throw new \RuntimeException("Nie znaleziono turnusu id=$editionId");
            }
            $eventId = (int) $edition['event_id'];
            $maxParticipants = $edition['max_participants'] !== null ? (int) $edition['max_participants'] : null;

            $statusCode = 'potwierdzony';
            if ($maxParticipants !== null) {
                $countStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM event_rsvps r
                    JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                    WHERE r.edition_id = :edition_id AND r.user_id != :user_id
                ");
                $countStmt->execute(['edition_id' => $editionId, 'user_id' => $userId]);
                if ((int) $countStmt->fetchColumn() >= $maxParticipants) {
                    $statusCode = 'lista_rezerwowa';
                }
            }
            $statusId = Dictionary::id('rsvp_status', $statusCode);

            $pdo->prepare('
                INSERT INTO event_rsvps (event_id, edition_id, user_id, status_item_id)
                VALUES (:event_id, :edition_id, :user_id, :status_id)
                ON DUPLICATE KEY UPDATE status_item_id = VALUES(status_item_id)
            ')->execute(['event_id' => $eventId, 'edition_id' => $editionId, 'user_id' => $userId, 'status_id' => $statusId]);

            if ($wlasna) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($wlasna && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // PUSH „KTOŚ DOŁĄCZYŁ DO TWOJEGO WYJAZDU" (Etap 8 przebudowy apki,
        // 2026-08-28) — TYLKO potwierdzony zapis (lista rezerwowa nie jest
        // jeszcze „dołączeniem"), TYLKO gdy dołączający to nie sam organizator
        // (zapis na własny wyjazd). Świadomie POZA transakcją wyżej i we
        // WŁASNYM try/catch: `Push::sendToUser()` już połyka własne błędy
        // (wzorzec `Core\Mailer`), ale zapis RSVP musi przejść niezależnie od
        // tego, co się stanie z powiadomieniem.
        if ($statusCode === 'potwierdzony') {
            try {
                $organizer = $pdo->prepare('
                    SELECT e.organizer_id, e.title, e.slug FROM events e
                     WHERE e.id = (SELECT event_id FROM event_editions WHERE id = :edition_id)
                ');
                $organizer->execute(['edition_id' => $editionId]);
                $row = $organizer->fetch();
                // BRAMKA (2026-09-11, migr. 081). Typ TRANSAKCYJNY — organizator
                // ma się dowiedzieć o zapisie wtedy, kiedy zapis nastąpił.
                // Klucz „ta osoba dołączyła do tego turnusu": ponowny zapis po
                // wypisaniu się nie zawiadomi drugi raz, i tak ma być — to
                // wciąż ta sama informacja o tej samej parze człowiek/wyjazd.
                if ($row && (int) $row['organizer_id'] !== $userId
                    && NotificationGate::claim(
                        (int) $row['organizer_id'],
                        NotificationGate::ZAPIS_NA_WYJAZD,
                        'rsvp:' . $editionId . ':' . $userId
                    ) !== null) {
                    // Treść z panelu (migr. 084); `{wyjazd}` to tytuł wydarzenia.
                    $znaczniki = ['wyjazd' => (string) $row['title']];
                    \Core\Lang::with(\Models\User::langOf((int) ((int) $row['organizer_id'])), static fn() => \Core\Push::sendToUser(
                        (int) $row['organizer_id'],
                        NotificationTexts::render('event_join.push.title', $znaczniki, [], true),
                        NotificationTexts::render('event_join.push.body', $znaczniki, [], true),
                        // DO LISTY UCZESTNIKÓW TEGO wyjazdu, nie na pulpit
                        // (zgłoszenie usera 2026-09-11). Organizator dostaje
                        // to powiadomienie po to, żeby zobaczyć, KTO doszedł —
                        // pulpit kazał mu szukać wyjazdu samemu.
                        ['url' => \Utils\View::url('/wydarzenia/' . $row['slug'] . '/uczestnicy')]
                    ));
                }
            } catch (\Throwable $e) {
                // Patrz komentarz wyżej — brak powiadomienia nie cofa zapisu.
            }
        }

        return $statusCode;
    }

    // Licznik pod link "Moje wydarzenia" w headerze — ile RÓŻNYCH wydarzeń
    // (dowolny status, przeszłe i przyszłe, niezależnie od liczby turnusów, na
    // które user jest zapisany w danym wydarzeniu) ma potwierdzony zapis.
    /**
     * Ile wyjazdów ta osoba ma PRZED SOBĄ i ile już za sobą.
     *
     * Zastąpiło `confirmedEventCountForUser()`, które liczyło
     * `COUNT(DISTINCT event_id)` po WSZYSTKICH potwierdzonych zapisach bez
     * filtra daty — nagłówek pokazywał „Moje wydarzenia (8)", choć przed
     * użytkownikiem był jeden wyjazd, a siedem już się odbyło (zgłoszenie usera
     * 2026-08-13). Licznik obok pozycji nawigacyjnej to zapowiedź, nie archiwum.
     *
     * LICZYMY TURNUSY, nie wydarzenia: cykliczna ustawka to jedno wydarzenie
     * i wiele terminów, a `DISTINCT event_id` sklejał je w jeden — ktoś zapisany
     * na trzy najbliższe soboty widziałby „1".
     *
     * Granicą jest data KOŃCA turnusu (albo startu, gdy końca nie ma):
     * wielodniówka, która właśnie trwa, jest jeszcze przede mną, a nie za mną.
     *
     * @return array{upcoming:int, past:int}
     */
    public static function confirmedEditionCountsForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT
                SUM(COALESCE(ed.end_date, ed.start_date) >= CURDATE()) AS upcoming,
                SUM(COALESCE(ed.end_date, ed.start_date) <  CURDATE()) AS past
            FROM event_rsvps r
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            JOIN event_editions ed ON ed.id = r.edition_id
            WHERE r.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'upcoming' => (int) ($row['upcoming'] ?? 0),
            'past'     => (int) ($row['past'] ?? 0),
        ];
    }

    // "Co wiemy z Twoich zapisów" (moje-konto.html, karta preferencji) —
    // dominanta (najczęstsza wartość) regionu/tempa/typu roweru + zakres
    // dystansu z FAKTYCZNIE potwierdzonych zapisów, nie deklaracji. Poniżej
    // 3 wydarzeń próbka jest za mała, żeby cokolwiek sensownie wywnioskować
    // (jeden wyjazd nie jest "wzorcem") — zwraca null, karta się wtedy chowa.
    // Dominantę liczymy w PHP (array_count_values), nie w SQL — czytelniej
    // przy spłaszczaniu relacji wiele-do-wielu (typ roweru).
    public static function behavioralPatternForUser(int $userId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT DISTINCT e.id, reg.name AS region_name, pace.name AS pace_name
            FROM event_rsvps r
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            JOIN events e ON e.id = r.event_id
            LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
            LEFT JOIN dictionary_items pace ON pace.id = e.pace_group_item_id
            WHERE r.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $events = $stmt->fetchAll();
        if (count($events) < 3) {
            return null;
        }
        $eventIds = array_column($events, 'id');

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $bikeStmt = $pdo->prepare("
            SELECT bdi.name FROM event_bike_types ebt
            JOIN dictionary_items bdi ON bdi.id = ebt.bike_type_item_id
            WHERE ebt.event_id IN ($placeholders)
        ");
        $bikeStmt->execute($eventIds);
        $bikeNames = $bikeStmt->fetchAll(\PDO::FETCH_COLUMN);

        $distStmt = $pdo->prepare("
            SELECT event_id, SUM(distance_km) AS km FROM event_stages
            WHERE event_id IN ($placeholders) AND distance_km > 0
            GROUP BY event_id
        ");
        $distStmt->execute($eventIds);
        $distances = array_column($distStmt->fetchAll(), 'km');

        // Zwraca najczęstszą wartość z listy, pomijając puste — albo null,
        // gdy nic konkretnego nie przeważa (np. same NULL-e).
        $mode = function (array $values): ?string {
            $values = array_filter($values, fn($v) => $v !== null && $v !== '');
            if (!$values) return null;
            $counts = array_count_values($values);
            arsort($counts);
            return array_key_first($counts);
        };

        $distanceRangeLabel = null;
        if ($distances) {
            $min = (int) round(min($distances));
            $max = (int) round(max($distances));
            $distanceRangeLabel = $min === $max ? $min . ' km' : $min . '–' . $max . ' km';
        }

        return [
            'bikeTypeLabel'      => $mode($bikeNames),
            'regionLabel'        => $mode(array_column($events, 'region_name')),
            'paceLabel'          => $mode(array_column($events, 'pace_name')),
            'distanceRangeLabel' => $distanceRangeLabel,
        ];
    }

    // Wstępna rezerwacja na płatny turnus — ZAWSZE 'oczekuje_platnosci', bez
    // sprawdzania pojemności (celowo: rezerwacja nie zajmuje puli miejsc,
    // dopiero confirmPayment() poniżej to robi, bo dopiero wtedy status
    // zmienia się na 'potwierdzony', jedyny liczony przez join()/confirmed_count).
    public static function reserve(int $editionId, int $userId): void
    {
        $eventId = self::eventIdForEdition($editionId);
        $statusId = Dictionary::id('rsvp_status', 'oczekuje_platnosci');
        Database::connection()->prepare('
            INSERT INTO event_rsvps (event_id, edition_id, user_id, status_item_id)
            VALUES (:event_id, :edition_id, :user_id, :status_id)
            ON DUPLICATE KEY UPDATE status_item_id = VALUES(status_item_id)
        ')->execute(['event_id' => $eventId, 'edition_id' => $editionId, 'user_id' => $userId, 'status_id' => $statusId]);
    }

    // Najlżejsze możliwe oznaczenie — "obserwuję ten termin", zero zobowiązania.
    // Ten sam upsert co reserve(); nie liczy się do puli miejsc z tego samego
    // powodu (join()/confirmed_count filtrują tylko 'potwierdzony').
    public static function markInterested(int $editionId, int $userId): void
    {
        $eventId = self::eventIdForEdition($editionId);
        $statusId = Dictionary::id('rsvp_status', 'zainteresowany');
        Database::connection()->prepare('
            INSERT INTO event_rsvps (event_id, edition_id, user_id, status_item_id)
            VALUES (:event_id, :edition_id, :user_id, :status_id)
            ON DUPLICATE KEY UPDATE status_item_id = VALUES(status_item_id)
        ')->execute(['event_id' => $eventId, 'edition_id' => $editionId, 'user_id' => $userId, 'status_id' => $statusId]);
    }

    private static function eventIdForEdition(int $editionId): int
    {
        $stmt = Database::connection()->prepare('SELECT event_id FROM event_editions WHERE id = :id');
        $stmt->execute(['id' => $editionId]);
        $eventId = $stmt->fetchColumn();
        if ($eventId === false) {
            throw new \RuntimeException("Nie znaleziono turnusu id=$editionId");
        }
        return (int) $eventId;
    }

    // Status usera dla KONKRETNEGO turnusu (dowolny) albo null gdy brak zapisu —
    // pod trzeci stan CTA na event-page.php ("wstępnie zarezerwowano") i pod
    // guard "czy mogę się zapisać na TEN termin".
    public static function statusForUser(int $editionId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare("
            SELECT rdi.code FROM event_rsvps r
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id
            WHERE r.edition_id = :edition_id AND r.user_id = :user_id
        ");
        $stmt->execute(['edition_id' => $editionId, 'user_id' => $userId]);
        $code = $stmt->fetchColumn();
        return $code !== false ? $code : null;
    }

    // joined_at usera dla turnusu — pod przeliczenie terminu płatności przy
    // każdorazowym wejściu na stronę eventu (deadline = joined_at + X dni),
    // bez trzymania osobnej kolumny na sam termin.
    public static function joinedAtForUser(int $editionId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare('
            SELECT joined_at FROM event_rsvps WHERE edition_id = :edition_id AND user_id = :user_id
        ');
        $stmt->execute(['edition_id' => $editionId, 'user_id' => $userId]);
        $joinedAt = $stmt->fetchColumn();
        return $joinedAt !== false ? $joinedAt : null;
    }

    // Pełna lista zapisów na WSZYSTKIE turnusy eventu pod stronę uczestników
    // organizatora — jeden user może wystąpić kilka razy (raz na termin, na
    // który się zapisał). edition_start_date pod grupowanie/etykietę terminu
    // w widoku. amount_paid to SUMA z rejestru wpłat (event_rsvp_payments),
    // nie jakieś zgadywanie na podstawie statusu.
    public static function forEvent(int $eventId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT u.id AS user_id, u.name, u.email, rdi.code AS status_code,
                   r.id AS rsvp_id, r.edition_id, ed.start_date AS edition_start_date,
                   r.joined_at, r.payment_confirmed_at, r.last_payment_confirmed_at,
                   COALESCE((SELECT SUM(amount) FROM event_rsvp_payments WHERE rsvp_id = r.id), 0) AS amount_paid
            FROM event_rsvps r
            JOIN users u ON u.id = r.user_id
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id
            WHERE r.event_id = :event_id
            ORDER BY ed.start_date ASC, r.joined_at ASC
        ");
        $stmt->execute(['event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    // Pojedynczy zapis (z sumą wpłat) pod stronę historii wpłat konkretnego
    // uczestnika NA KONKRETNY termin — ten sam kształt danych co wiersz z
    // forEvent(), tylko dla jednego usera i jednego turnusu zamiast całej listy.
    public static function forEditionAndUser(int $editionId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare("
            SELECT r.id AS rsvp_id, rdi.code AS status_code, r.joined_at,
                   COALESCE((SELECT SUM(amount) FROM event_rsvp_payments WHERE rsvp_id = r.id), 0) AS amount_paid
            FROM event_rsvps r
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id
            WHERE r.edition_id = :edition_id AND r.user_id = :user_id
        ");
        $stmt->execute(['edition_id' => $editionId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Historia pojedynczych wpłat dla danego zapisu — pod "kto/kiedy/ile"
    // przy weryfikacji bilansu (widok uczestników organizatora).
    public static function paymentsForRsvp(int $rsvpId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT p.amount, p.confirmed_at, u.name AS confirmed_by_name
            FROM event_rsvp_payments p
            JOIN users u ON u.id = p.confirmed_by_user_id
            WHERE p.rsvp_id = :rsvp_id
            ORDER BY p.confirmed_at ASC
        ');
        $stmt->execute(['rsvp_id' => $rsvpId]);
        return $stmt->fetchAll();
    }

    // Ręczne potwierdzenie wpłaty — organizator wpisuje FAKTYCZNIE otrzymaną
    // kwotę (niekoniecznie równą zaliczce ani całej cenie: może być więcej niż
    // zaliczka, ale mniej niż całość, albo kolejna z kilku rat). Dopisuje wiersz
    // do event_rsvp_payments i przelicza SUMĘ wszystkich wpłat tego zapisu
    // (zapis = jeden konkretny turnus, patrz $editionId):
    // - suma >= cena  -> status 'potwierdzony', stempluje payment_confirmed_at/by
    //   (rozliczenie zamknięte — pokrywa zarówno "zapłacił całość od razu", jak
    //   i "dopłacił resztę po wcześniejszych częściowych wpłatach").
    // - suma < cena   -> status 'oczekuje_doplaty', stempluje last_payment_confirmed_at/by
    //   (zapis zostaje w kolejce EventRsvp::pendingForOrganizer() do czasu dopłaty).
    // Wywoływane też przez samego usera dla registration_type=external (patrz
    // RsvpController::confirmPaymentSelf()) — tam zawsze $amount = pełna cena.
    // Idempotentne względem stanu: no-op (null) gdy zapis nie istnieje albo nie
    // jest w stanie oczekiwania na wpłatę ('oczekuje_platnosci'/'oczekuje_doplaty').
    // Zwraca podsumowanie do wyboru treści maila przez wywołującego.
    public static function confirmPayment(int $editionId, int $userId, float $amount, int $confirmedByUserId): ?array
    {
        $pdo = Database::connection();

        // Cała sekwencja (insert wpłaty + przeliczenie sumy + update statusu)
        // musi być atomowa — bez transakcji awaria procesu między insertem a
        // update'em zostawiłaby zarejestrowaną wpłatę przy statusie wciąż
        // "oczekuje_platnosci". FOR UPDATE blokuje wiersz zapisu na czas
        // transakcji, więc dwa równoległe potwierdzenia tej samej wpłaty
        // (np. podwójny klik) nie policzą się osobno.
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT r.id, rdi.code AS status_code, ep.price_amount
                FROM event_rsvps r
                JOIN dictionary_items rdi ON rdi.id = r.status_item_id
                JOIN event_pricing ep ON ep.event_id = r.event_id
                WHERE r.edition_id = :edition_id AND r.user_id = :user_id
                FOR UPDATE
            ");
            $stmt->execute(['edition_id' => $editionId, 'user_id' => $userId]);
            $rsvp = $stmt->fetch();
            if (!$rsvp || !in_array($rsvp['status_code'], ['oczekuje_platnosci', 'oczekuje_doplaty'], true)) {
                $pdo->rollBack();
                return null;
            }

            $pdo->prepare('
                INSERT INTO event_rsvp_payments (rsvp_id, amount, confirmed_by_user_id)
                VALUES (:rsvp_id, :amount, :by)
            ')->execute(['rsvp_id' => $rsvp['id'], 'amount' => $amount, 'by' => $confirmedByUserId]);

            $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM event_rsvp_payments WHERE rsvp_id = :rsvp_id');
            $totalStmt->execute(['rsvp_id' => $rsvp['id']]);
            $totalPaid = (float) $totalStmt->fetchColumn();

            $price = (float) $rsvp['price_amount'];
            $isFullySettled = $totalPaid >= $price;
            $newCode = $isFullySettled ? 'potwierdzony' : 'oczekuje_doplaty';

            $sql = $isFullySettled
                ? 'UPDATE event_rsvps SET status_item_id = :status_id, payment_confirmed_at = NOW(), payment_confirmed_by_user_id = :by1,
                     last_payment_confirmed_at = NOW(), last_payment_confirmed_by_user_id = :by2 WHERE id = :id'
                : 'UPDATE event_rsvps SET status_item_id = :status_id, last_payment_confirmed_at = NOW(), last_payment_confirmed_by_user_id = :by1 WHERE id = :id';
            $params = [
                'status_id' => Dictionary::id('rsvp_status', $newCode),
                'by1'       => $confirmedByUserId,
                'id'        => $rsvp['id'],
            ];
            if ($isFullySettled) {
                $params['by2'] = $confirmedByUserId;
            }
            $pdo->prepare($sql)->execute($params);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'statusCode'     => $newCode,
            'isFullySettled' => $isFullySettled,
            'amountPaidNow'  => $amount,
            'totalPaid'      => $totalPaid,
            'remaining'      => max(0.0, $price - $totalPaid),
            'price'          => $price,
        ];
    }

    // Rezygnacja z udziału w KONKRETNYM turnusie — zainicjowana przez uczestnika
    // (samoobsługa) ALBO przez organizatora w jego imieniu (zgłoszenie
    // telefoniczne/mailowe poza platformą, patrz RsvpController::cancelParticipantByOrganizer()).
    // Z 'potwierdzony': jeśli event PŁATNY -> 'oczekuje_zwrotu' + refund_requested_at
    // (zwrot musi potwierdzić organizator, patrz confirmRefund()); jeśli DARMOWY
    // -> od razu 'anulowany', bo nie ma czego zwracać (bez tego rozróżnienia
    // rezygnacja z darmowego eventu tworzyłaby fałszywy wpis w kolejce zwrotów
    // na /admin/platnosci). Z 'oczekuje_platnosci'/'lista_rezerwowa' (nic
    // niewpłacone niezależnie od ceny eventu) -> od razu 'anulowany'.
    // Status od razu przestaje się liczyć do puli miejsc TEGO turnusu —
    // join()/confirmed_count filtrują tylko 'potwierdzony', zero zmian w tamtej
    // logice. Zwraca nowy status albo null gdy zapis był w innym stanie (nie dotyczyło).
    public static function requestCancellation(int $editionId, int $userId): ?string
    {
        $pdo = Database::connection();
        $current = self::statusForUser($editionId, $userId);

        // 'oczekuje_doplaty' liczy się tu jak 'potwierdzony' — zaliczka już
        // wpłynęła, więc rezygnacja musi przejść przez kolejkę zwrotów, tak
        // samo jak przy pełnej wpłacie (jest co oddać).
        if ($current === 'potwierdzony' || $current === 'oczekuje_doplaty') {
            $priceStmt = $pdo->prepare('
                SELECT 1 FROM event_pricing ep
                JOIN event_editions ed ON ed.event_id = ep.event_id
                WHERE ed.id = :edition_id
            ');
            $priceStmt->execute(['edition_id' => $editionId]);
            $isPaid = (bool) $priceStmt->fetchColumn();

            $newCode = $isPaid ? 'oczekuje_zwrotu' : 'anulowany';
            $stmt = $pdo->prepare('
                UPDATE event_rsvps
                SET status_item_id = :new_id' . ($isPaid ? ', refund_requested_at = NOW()' : '') . '
                WHERE edition_id = :edition_id AND user_id = :user_id AND status_item_id = :old_id
            ');
            $stmt->execute([
                'new_id'     => Dictionary::id('rsvp_status', $newCode),
                'old_id'     => Dictionary::id('rsvp_status', $current),
                'edition_id' => $editionId,
                'user_id'    => $userId,
            ]);
            return $stmt->rowCount() > 0 ? $newCode : null;
        }

        if (in_array($current, ['oczekuje_platnosci', 'lista_rezerwowa', 'zainteresowany'], true)) {
            $stmt = $pdo->prepare('
                UPDATE event_rsvps
                SET status_item_id = :new_id
                WHERE edition_id = :edition_id AND user_id = :user_id AND status_item_id = :old_id
            ');
            $stmt->execute([
                'new_id'     => Dictionary::id('rsvp_status', 'anulowany'),
                'old_id'     => Dictionary::id('rsvp_status', $current),
                'edition_id' => $editionId,
                'user_id'    => $userId,
            ]);
            return $stmt->rowCount() > 0 ? 'anulowany' : null;
        }

        return null;
    }

    // Ręczne potwierdzenie zwrotu — 'oczekuje_zwrotu' -> 'anulowany'. Zawsze
    // organizator (nigdy sam uczestnik, w przeciwieństwie do confirmPayment()
    // przy registration_type=external) — pieniądze zawsze wychodzą od
    // organizatora/jego operatora płatności, nie ma tu odpowiednika self-service.
    public static function confirmRefund(int $editionId, int $userId, int $confirmedByUserId): bool
    {
        $pdo = Database::connection();
        $waitingId = Dictionary::id('rsvp_status', 'oczekuje_zwrotu');
        $cancelledId = Dictionary::id('rsvp_status', 'anulowany');

        // Update statusu + kompensujący wpis w rejestrze wpłat muszą być
        // atomowe — inaczej awaria między nimi zostawia zapis "anulowany", ale
        // bilans wpłat wciąż pokazujący kwotę "do zwrotu" (księgowość rozjeżdża
        // się z rzeczywistym stanem).
        $pdo->beginTransaction();
        try {
            $rsvpStmt = $pdo->prepare('SELECT id FROM event_rsvps WHERE edition_id = :edition_id AND user_id = :user_id AND status_item_id = :waiting_id FOR UPDATE');
            $rsvpStmt->execute(['edition_id' => $editionId, 'user_id' => $userId, 'waiting_id' => $waitingId]);
            $rsvpId = $rsvpStmt->fetchColumn();
            if ($rsvpId === false) {
                $pdo->rollBack();
                return false;
            }

            $stmt = $pdo->prepare('
                UPDATE event_rsvps
                SET status_item_id = :cancelled_id, refund_processed_at = NOW(), refund_processed_by_user_id = :by
                WHERE id = :id AND status_item_id = :waiting_id
            ');
            $stmt->execute([
                'cancelled_id' => $cancelledId,
                'by'           => $confirmedByUserId,
                'id'           => $rsvpId,
                'waiting_id'   => $waitingId,
            ]);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }

            // UNIQUE(edition_id, user_id) oznacza, że jeśli ten sam user zapisze
            // się na ten sam TERMIN ponownie w przyszłości, to będzie DOKŁADNIE
            // ten sam wiersz event_rsvps — a więc i ten sam rsvp_id w rejestrze
            // wpłat. Bez zamykającego wpisu na minus, stare wpłaty z ODWOŁANEJ
            // rezerwacji (już zwrócone!) liczyłyby się na poczet przyszłego,
            // nowego zapisu. Zamiast kasować historię (potrzebna do bilansu),
            // dopisujemy wpis zerujący — suma wraca do 0, a każda wpłata i jej
            // zwrot zostają widoczne w historii.
            $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM event_rsvp_payments WHERE rsvp_id = :rsvp_id');
            $totalStmt->execute(['rsvp_id' => $rsvpId]);
            $totalPaid = (float) $totalStmt->fetchColumn();
            if ($totalPaid > 0) {
                $pdo->prepare('
                    INSERT INTO event_rsvp_payments (rsvp_id, amount, confirmed_by_user_id)
                    VALUES (:rsvp_id, :amount, :by)
                ')->execute(['rsvp_id' => $rsvpId, 'amount' => -$totalPaid, 'by' => $confirmedByUserId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return true;
    }

    // Zbiorcza kolejka pod /admin/platnosci — wszystkie zapisy WYMAGAJĄCE akcji
    // organizatora (oczekują potwierdzenia wpłaty (całości ALBO dopłaty) LUB
    // zwrotu) ze WSZYSTKICH jego wydarzeń i turnusów na raz, żeby nie trzeba było
    // odwiedzać każdego z osobna. $organizerUserId null => wszystkie wydarzenia
    // na platformie (widok admina, ten sam wzorzec co Event::forDashboard()).
    // Uwzględnia też eventy, do których $organizerUserId ma dostęp jako
    // współpracownik (organizer_collaborators) — spójnie z EventPermission::canEdit(),
    // nie tylko własne organizer_id. amount_paid to SUMA z rejestru wpłat —
    // widok sam wylicza dokładną resztę do zapłaty (cena - amount_paid) albo,
    // dla zwrotów, dokładną kwotę faktycznie wpłaconą (nie zawsze cała cena).
    public static function pendingForOrganizer(?int $organizerUserId): array
    {
        $pdo = Database::connection();
        $params = [];
        if ($organizerUserId !== null) {
            $ownerWhere = 'AND (e.organizer_id = :uid OR e.organizer_id IN (
                SELECT organizer_user_id FROM organizer_collaborators WHERE user_id = :uid2
            ))';
            $params['uid'] = $organizerUserId;
            $params['uid2'] = $organizerUserId;
        } else {
            $ownerWhere = '';
        }

        $stmt = $pdo->prepare("
            SELECT e.slug AS event_slug, e.title AS event_title, ed.start_date AS edition_start_date,
                   u.id AS user_id, u.name, u.email,
                   rdi.code AS status_code, r.joined_at, r.refund_requested_at,
                   r.last_payment_confirmed_at, r.payment_confirmed_at,
                   ep.price_amount, ep.deposit_amount, cur.code AS currency_code,
                   COALESCE((SELECT SUM(amount) FROM event_rsvp_payments WHERE rsvp_id = r.id), 0) AS amount_paid
            FROM event_rsvps r
            JOIN events e ON e.id = r.event_id
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN users u ON u.id = r.user_id
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code IN ('oczekuje_platnosci', 'oczekuje_doplaty', 'oczekuje_zwrotu')
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            LEFT JOIN dictionary_items cur ON cur.id = ep.currency_item_id
            WHERE 1=1 $ownerWhere
            ORDER BY COALESCE(r.refund_requested_at, r.last_payment_confirmed_at, r.joined_at) ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
