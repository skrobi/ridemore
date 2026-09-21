<?php
// core/Models/RiderConnection.php
// PELETON — relacja „jeździliśmy razem" (migr. 036).
//
// Powstaje SAMA z faktycznej wspólnej obecności na tym samym turnusie
// (Models\EventAttendance). Nie ma przycisku „obserwuj" i nie będzie —
// do czyjegoś peletonu wchodzi się wyłącznie wsiadając na rower.
//
// Tabela rider_connections jest ZMATERIALIZOWANYM agregatem, nie źródłem
// prawdy: źródłem zostaje event_attendance, a te wiersze da się w każdej
// chwili odtworzyć od zera. Materializacja istnieje po to, żeby pytanie
// „kto z mojego peletonu jedzie na to wydarzenie" było jednym JOIN-em przy
// każdym wejściu na stronę wydarzenia, a nie samozłączeniem po całej
// historii obecności.
//
// Para jest KANONICZNA (user_a_id < user_b_id) — jeden wiersz na parę, więc
// relacja jest symetryczna z definicji: u obu osób ta sama liczba wspólnych
// wyjazdów i ten sam wspólny dystans.
namespace Models;

use Core\Database;

class RiderConnection
{
    // Przeliczenie po każdej zmianie obecności na turnusie (wołane z
    // EventAttendance::declare(), żeby nie dało się o nim zapomnieć przy
    // dokładaniu kolejnego wejścia).
    //
    // Zakres: wszystkie pary WŚRÓD osób, które mają jakikolwiek wpis obecności
    // na tym turnusie — także te z attended=0. To celowe: gdy ktoś zmieni
    // odpowiedź z „byłem" na „nie dojechałem", jego pary muszą się przeliczyć
    // W DÓŁ, a bez uwzględnienia nieobecnych w zbiorze nigdy by do tego nie doszło.
    //
    // Pary spoza tego zbioru są nietknięte — ten turnus nie mógł na nie wpłynąć.
    public static function recomputeForEdition(int $editionId): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('
            SELECT DISTINCT r.user_id
            FROM event_attendance a
            JOIN event_rsvps r ON r.id = a.rsvp_id
            WHERE r.edition_id = :edition_id
        ');
        $stmt->execute(['edition_id' => $editionId]);
        $userIds = array_map('intval', array_column($stmt->fetchAll(), 'user_id'));

        // Jedna osoba nie tworzy pary — nie ma czego liczyć.
        if (count($userIds) < 2) {
            return;
        }

        $in = implode(',', array_fill(0, count($userIds), '?'));

        // Czyścimy pary z tego zbioru i wstawiamy je na nowo. Dzięki temu para,
        // która spadła do zera wspólnych przejazdów, po prostu znika, zamiast
        // zostać z nieaktualnym licznikiem.
        $pdo->prepare("
            DELETE FROM rider_connections
            WHERE user_a_id IN ($in) AND user_b_id IN ($in)
        ")->execute(array_merge($userIds, $userIds));

        // rb.user_id > ra.user_id daje od razu parę kanoniczną i jednocześnie
        // odsiewa duplikaty (a,b)/(b,a) oraz parę z samym sobą.
        //
        // Każdy user ma najwyżej jeden zapis na turnus, więc na parę przypada
        // dokładnie jeden wiersz na wspólny turnus — COUNT/SUM nie zdublują.
        // Dystans z widoku event_totals; wydarzenie bez wpisanego dystansu
        // dorzuca 0 (liczba wyjazdów zawsze prawdziwa, dystans bywa niepełny).
        $pdo->prepare("
            INSERT INTO rider_connections
                (user_a_id, user_b_id, rides_count, shared_km, first_ride_at, last_ride_at)
            SELECT ra.user_id, rb.user_id,
                   COUNT(*),
                   COALESCE(SUM(et.total_distance_km), 0),
                   MIN(ed.start_date),
                   MAX(ed.start_date)
            FROM event_attendance aa
            JOIN event_rsvps ra ON ra.id = aa.rsvp_id
            JOIN event_rsvps rb ON rb.edition_id = ra.edition_id AND rb.user_id > ra.user_id
            JOIN event_attendance ab ON ab.rsvp_id = rb.id
            JOIN event_editions ed ON ed.id = ra.edition_id
            LEFT JOIN event_totals et ON et.event_id = ra.event_id
            WHERE aa.attended = 1 AND ab.attended = 1
              AND ra.user_id IN ($in) AND rb.user_id IN ($in)
            GROUP BY ra.user_id, rb.user_id
        ")->execute(array_merge($userIds, $userIds));
    }

    // Peleton usera — lista osób, z którymi faktycznie jechał, od najczęstszych.
    // Para jest kanoniczna, więc user może siedzieć po dowolnej stronie; CASE
    // wyciąga „tego drugiego". Osobne nazwy placeholderów (:uid1/:uid2), bo przy
    // EMULATE_PREPARES=false tego samego nazwanego parametru nie da się użyć
    // dwa razy w jednym zapytaniu (ta sama pułapka co w /api/organizers/search).
    public static function forUser(int $userId, int $limit = 12): array
    {
        $stmt = Database::connection()->prepare('
            SELECT u.id AS user_id, u.name, u.email, u.public_slug, u.avatar_url,
                   c.rides_count, c.shared_km, c.first_ride_at, c.last_ride_at
            FROM rider_connections c
            JOIN users u ON u.id = CASE WHEN c.user_a_id = :uid1 THEN c.user_b_id ELSE c.user_a_id END
            WHERE (c.user_a_id = :uid2 OR c.user_b_id = :uid3)
              AND u.roster_visible = 1
            ORDER BY c.rides_count DESC, c.last_ride_at DESC
            LIMIT ' . max(1, $limit) . '
        ');
        $stmt->execute(['uid1' => $userId, 'uid2' => $userId, 'uid3' => $userId]);
        return $stmt->fetchAll();
    }

    // UWAGA przy Etapie 3 (profil): liczy WSZYSTKICH, także tych, którzy wyłączyli
    // się z list (users.roster_visible = 0) — bo faktycznie z nimi jechałeś.
    // forUser() ich natomiast nie zwraca. Gdyby profil pokazywał licznik obok
    // listy, te dwie liczby mogłyby się nie zgadzać — wtedy trzeba świadomie
    // wybrać: albo licznik też filtrować, albo dopisać „i N osób ukrytych".
    public static function countForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*) FROM rider_connections
            WHERE user_a_id = :uid1 OR user_b_id = :uid2
        ');
        $stmt->execute(['uid1' => $userId, 'uid2' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // „Z Twojego peletonu jadą..." — osoby z peletonu widza, które mają
    // POTWIERDZONY zapis na ten konkretny turnus. To jest miejsce, w którym
    // peleton realnie zarabia na siebie: znajomy w składzie waży przy decyzji
    // o zapisie nieporównanie więcej niż nieznajomy.
    //
    // Widz siebie samego tu nie zobaczy — nie jest w swoim własnym peletonie
    // (para z samym sobą nie powstaje, patrz recomputeForEdition).
    public static function pelotonOnEdition(int $editionId, int $viewerId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT u.id AS user_id, u.name, u.email, u.public_slug, u.avatar_url, c.rides_count, c.shared_km
            FROM rider_connections c
            JOIN users u ON u.id = CASE WHEN c.user_a_id = :uid1 THEN c.user_b_id ELSE c.user_a_id END
            JOIN event_rsvps r ON r.user_id = u.id AND r.edition_id = :edition_id
            JOIN dictionary_items rdi
              ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            WHERE (c.user_a_id = :uid2 OR c.user_b_id = :uid3)
              AND u.roster_visible = 1
            ORDER BY c.rides_count DESC
        ");
        $stmt->execute([
            'uid1' => $viewerId, 'uid2' => $viewerId, 'uid3' => $viewerId,
            'edition_id' => $editionId,
        ]);
        return $stmt->fetchAll();
    }

    // „CO TERAZ ROBIMY?" — nadchodzące wyjazdy, na które zapisał się ktoś
    // z peletonu widza (Etap 5, Puls).
    //
    // To jest silnik powrotów całego kierunku. Nie mówi „mamy nowe wydarzenia",
    // tylko „Michał i Ania jadą w sobotę" — i dlatego działa przy chudym
    // kalendarzu: peleton nie potrzebuje WIĘCEJ wyjazdów, tylko sprawia, że
    // te już istniejące zaczynają cokolwiek znaczyć.
    //
    // Wyjazdy, na które widz sam jest już zapisany, celowo odpadają — wie o nich.
    public static function upcomingForPeloton(int $userId, int $limit = 6): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, e.cover_photo_url,
                   ed.id AS edition_id, ed.start_date,
                   reg.name AS region_label,
                   COUNT(DISTINCT u.id) AS mates_count,
                   -- Nazwa, slug I awatar w jednym GROUP_CONCAT — imiona w tym
                   -- bloku są linkami do profili, a kółka pokazują wgrane zdjęcie
                   -- (2026-08-22), więc sam tekst nie wystarczy.
                   -- Separatory: 0x1f między polami, 0x1e między osobami (znaki
                   -- sterujące, nie wystąpią w nazwie, slugu ani w adresie pliku).
                   GROUP_CONCAT(DISTINCT CONCAT(
                       COALESCE(NULLIF(u.name, ''), u.email), 0x1f, COALESCE(u.public_slug, ''),
                       0x1f, COALESCE(u.avatar_url, '')
                   ) ORDER BY c.rides_count DESC SEPARATOR 0x1e) AS mate_pairs
            FROM rider_connections c
            JOIN users u ON u.id = CASE WHEN c.user_a_id = :uid1 THEN c.user_b_id ELSE c.user_a_id END
            JOIN event_rsvps r ON r.user_id = u.id
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
            WHERE (c.user_a_id = :uid2 OR c.user_b_id = :uid3)
              AND u.roster_visible = 1
              AND ed.start_date >= CURDATE()
              AND NOT EXISTS (
                  SELECT 1 FROM event_rsvps mine
                  WHERE mine.edition_id = ed.id AND mine.user_id = :uid4
              )
            GROUP BY e.slug, e.title, e.cover_photo_url, ed.id, ed.start_date, reg.name
            ORDER BY ed.start_date ASC
            LIMIT " . max(1, $limit) . "
        ");
        $stmt->execute(['uid1' => $userId, 'uid2' => $userId, 'uid3' => $userId, 'uid4' => $userId]);

        return array_map(static function (array $row): array {
            // Skracamy do „Michał W." dopiero tutaj — SQL dostarcza pełne nazwy,
            // bo skracanie po stronie bazy byłoby nieczytelne.
            $mates = [];
            foreach (explode("\x1e", (string) $row['mate_pairs']) as $pair) {
                if ($pair === '') { continue; }
                [$raw, $slug, $avatar] = array_pad(explode("\x1f", $pair, 3), 3, '');
                $parts = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $short = $parts ? $parts[0] : __('Rowerzysta');
                if (count($parts) > 1) {
                    $short .= ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
                }
                $mates[] = [
                    'name'      => $short,
                    'slug'      => $slug !== '' ? $slug : null,
                    'initials'  => mb_strtoupper(mb_substr($raw !== '' ? $raw : '?', 0, 2)),
                    'avatarUrl' => $avatar !== '' ? $avatar : null,
                ];
            }

            return [
                'eventSlug'    => $row['slug'],
                'editionId'    => (int) $row['edition_id'],
                'title'        => $row['title'],
                'regionLabel'  => $row['region_label'],
                'startDate'    => $row['start_date'],
                'coverPhotoUrl'=> $row['cover_photo_url'],
                'matesCount'   => (int) $row['mates_count'],
                'mates'        => $mates,
            ];
        }, $stmt->fetchAll());
    }

    // Kto z peletonu widza jest zapisany na WSKAZANE turnusy — pod podpis na
    // karcie dopasowania („Jedzie z nimi Michał W.").
    //
    // Wołane WYŁĄCZNIE dla kilku finalnych kandydatów, nie dla całej puli:
    // ranking potrzebuje tylko liczby (jest w candidatePool jako podzapytanie),
    // imiona są potrzebne dopiero na tych 3-5 kartach, które faktycznie zobaczy
    // użytkownik. Zwraca [editionId => ['Michał W.', 'Ania K.']].
    public static function namesOnEditions(array $editionIds, int $viewerId): array
    {
        if (!$editionIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($editionIds), '?'));
        $stmt = Database::connection()->prepare("
            SELECT r.edition_id, u.name, u.email, c.rides_count
            FROM event_rsvps r
            JOIN dictionary_items rdi
              ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            JOIN users u ON u.id = r.user_id
            JOIN rider_connections c
              ON (c.user_a_id = u.id AND c.user_b_id = ?)
              OR (c.user_b_id = u.id AND c.user_a_id = ?)
            WHERE r.edition_id IN ($in)
              AND u.roster_visible = 1
            ORDER BY c.rides_count DESC
        ");
        $stmt->execute(array_merge([$viewerId, $viewerId], $editionIds));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $raw = trim((string) $row['name']);
            if ($raw === '') {
                $raw = (string) strstr((string) $row['email'], '@', true);
            }
            $parts = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $short = $parts ? $parts[0] : __('Rowerzysta');
            if (count($parts) > 1) {
                $short .= ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
            }
            $out[(int) $row['edition_id']][] = $short;
        }
        return $out;
    }

    // „Powstało N nowych znajomości" — pary, dla których TEN turnus był
    // pierwszym wspólnym przejazdem (Etap 4, kronika).
    //
    // To jest jedyne miejsce w produkcie, gdzie wyjazd pokazany jest jako
    // ZDARZENIE SPOŁECZNE, a nie jako trasa: tego dnia tylu ludzi poznało się
    // nawzajem. Liczba, nie lista — kto z kim, widać już w składzie wyżej,
    // a wyciąganie par imion byłoby ujawnianiem relacji, o które nikt nie prosił.
    //
    // first_ride_at to MIN(start_date) po wszystkich wspólnych przejazdach pary
    // (patrz recomputeForEdition), więc porównanie z datą tego turnusu wystarczy.
    public static function newPairsFromEdition(int $editionId): int
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*)
            FROM rider_connections c
            JOIN event_editions ed ON ed.id = :edition_id
            WHERE c.first_ride_at = ed.start_date
              AND EXISTS (
                  SELECT 1 FROM event_rsvps ra
                  JOIN event_attendance aa ON aa.rsvp_id = ra.id AND aa.attended = 1
                  WHERE ra.edition_id = ed.id AND ra.user_id = c.user_a_id
              )
              AND EXISTS (
                  SELECT 1 FROM event_rsvps rb
                  JOIN event_attendance ab ON ab.rsvp_id = rb.id AND ab.attended = 1
                  WHERE rb.edition_id = ed.id AND rb.user_id = c.user_b_id
              )
        ');
        $stmt->execute(['edition_id' => $editionId]);
        return (int) $stmt->fetchColumn();
    }

    // Pełne odtworzenie tabeli od zera — pod jednorazowe wypełnienie po
    // wdrożeniu (historyczne obecności sprzed wprowadzenia peletonu) i pod
    // ewentualną naprawę, gdyby agregat kiedykolwiek się rozjechał ze
    // źródłem. Nie jest wołane w normalnym cyklu życia aplikacji.
    public static function rebuildAll(): int
    {
        $pdo = Database::connection();
        $pdo->exec('DELETE FROM rider_connections');
        $pdo->exec("
            INSERT INTO rider_connections
                (user_a_id, user_b_id, rides_count, shared_km, first_ride_at, last_ride_at)
            SELECT ra.user_id, rb.user_id,
                   COUNT(*),
                   COALESCE(SUM(et.total_distance_km), 0),
                   MIN(ed.start_date),
                   MAX(ed.start_date)
            FROM event_attendance aa
            JOIN event_rsvps ra ON ra.id = aa.rsvp_id
            JOIN event_rsvps rb ON rb.edition_id = ra.edition_id AND rb.user_id > ra.user_id
            JOIN event_attendance ab ON ab.rsvp_id = rb.id
            JOIN event_editions ed ON ed.id = ra.edition_id
            LEFT JOIN event_totals et ON et.event_id = ra.event_id
            WHERE aa.attended = 1 AND ab.attended = 1
            GROUP BY ra.user_id, rb.user_id
        ");
        return (int) $pdo->query('SELECT COUNT(*) FROM rider_connections')->fetchColumn();
    }
}
