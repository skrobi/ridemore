<?php
// core/Models/Emblem.php
// EMBLEMATY ZA UKOŃCZENIE TRASY (migr. 087, 2026-09-11).
//
// CO TO JEST: wirtualna odznaka przypięta do znanej trasy albo do wydarzenia.
// Zdobywa się ją przez POKRYCIE 100% PÓL tej trasy — decyzja usera, ta sama
// reguła dla obu źródeł. Nie za obecność, nie za wgranie pliku: za faktyczne
// przejechanie całości.
//
// TRZY RZECZY, KTÓRE ODRÓŻNIAJĄ TEN MODUŁ OD RESZTY DISCOVERY:
//
// 1. EMBLEMATU NIE DA SIĘ STRACIĆ (decyzja usera). `point_transactions`
//    odbiera `TRAIL_COMPLETION`, gdy pokrycie spadnie poniżej 100% — a spada
//    np. wtedy, gdy admin podmieni plik GPX trasy na dłuższy. Emblemat jest
//    pamiątką, nie stanem konta, więc ma własny rejestr chwili
//    (`emblem_awards`) i nie ma tu odpowiednika `revoke()`.
//
// 2. PRZYZNAWANIE JEST ZBIOROWE, NIE PER UŻYTKOWNIK. Jedno `INSERT ... SELECT`
//    na trasę nadaje emblemat WSZYSTKIM, którzy ją domknęli — i to samo
//    zapytanie, zawężone o `AND dc.user_id = :uid`, obsługuje pojedynczy
//    upload. Dzięki temu cron i ścieżka po wgraniu pliku to ten sam kod, a nie
//    dwa mechanizmy, które kiedyś się rozjadą.
//
// 3. PUNKTÓW NIE DAJE. Wartość trasy liczy `DiscoveryScoring` i rejestr
//    punktów; emblemat jest nagrodą RÓWNOLEGŁĄ, nie kolejnym progiem. Gdyby
//    dawał punkty, byłby drugim źródłem prawdy o tym samym zdarzeniu.
//
// NA PRZYSZŁOŚĆ: user zapowiedział „rzeczywiste ordery za przejechanie trasy".
// Dlatego emblemat jest osobnym bytem (`emblems`), a nie kompletem kolumn przy
// trasie — fizyczny order dołoży tu kolumny (nakład, status wysyłki), a nie
// wymusi migracji dwóch tabel naraz.
namespace Models;

use Core\Database;

class Emblem
{
    public const SOURCE_ROUTE = 'route';
    public const SOURCE_EVENT = 'event';

    // ───────────────────────────────────────────────────────── DEFINICJE

    /** Wszystkie emblematy do listy w panelu i do selectów przy trasie/wyjeździe. */
    public static function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT e.id, e.name, e.description, e.image_url, e.is_active, e.created_at,
                       (SELECT COUNT(*) FROM emblem_awards a WHERE a.emblem_id = e.id) AS awarded,
                       (SELECT COUNT(*) FROM known_routes kr WHERE kr.emblem_id = e.id) AS routes,
                       (SELECT COUNT(*) FROM events ev WHERE ev.emblem_id = e.id) AS events
                  FROM emblems e'
            . ($onlyActive ? ' WHERE e.is_active = 1' : '')
            . ' ORDER BY e.name';

        return Database::connection()->query($sql)->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM emblems WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Zapis z panelu. `$id === null` = nowy.
     *
     * `image_url` pomijamy, gdy nie przyszedł nowy plik — pole formularza jest
     * puste przy każdym otwarciu (przeglądarka nie wypełnia inputów plikowych),
     * więc traktowanie pustki jako „skasuj" kasowałoby grafikę przy każdej
     * poprawce opisu. Ta sama pułapka co przy okładce znanej trasy.
     */
    public static function save(?int $id, array $fields): int
    {
        $db = Database::connection();
        $dozwolone = ['name', 'description', 'image_url', 'is_active'];
        $dane = array_intersect_key($fields, array_flip($dozwolone));

        if ($id === null) {
            $kolumny = implode(', ', array_keys($dane));
            $wartosci = implode(', ', array_map(static fn(string $k): string => ':' . $k, array_keys($dane)));
            $db->prepare("INSERT INTO emblems ($kolumny) VALUES ($wartosci)")->execute($dane);
            return (int) $db->lastInsertId();
        }

        if ($dane) {
            $set = implode(', ', array_map(static fn(string $k): string => "$k = :$k", array_keys($dane)));
            $db->prepare("UPDATE emblems SET $set WHERE id = :id")->execute($dane + ['id' => $id]);
        }
        return $id;
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM emblems WHERE id = :id')->execute(['id' => $id]);
    }

    // ───────────────────────────────────────────────────────── PRZYZNAWANIE

    /**
     * Przyznaje wszystkie zasłużone emblematy — jednemu użytkownikowi albo
     * (przy `$userId === null`) wszystkim naraz.
     *
     * WOŁANE Z DWÓCH MIEJSC I TO JEST CELOWE: po wgraniu śladu
     * (`RiderActivity`, z konkretnym `$userId`) oraz z `cron.php` bez
     * zawężenia. Ta druga droga nie jest zapasem na wszelki wypadek —
     * pokrycie trasy potrafi dojść do 100% BEZ nowego przejazdu: wystarczy,
     * że admin skróci trasę albo podmieni jej przebieg na taki, który ktoś
     * już ma cały. Bez crona taki człowiek czekałby na emblemat do swojej
     * następnej jazdy.
     *
     * @return int ile emblematów faktycznie nadano
     */
    public static function sync(?int $userId = null): int
    {
        return self::syncRoutes($userId) + self::syncEvents($userId);
    }

    /**
     * Znane trasy — jedno zapytanie na trasę z emblematem.
     *
     * `HAVING COUNT(...) >= cells_total` to CAŁY warunek „100% trasy": pól
     * trasy nie ubywa, a `discovery_cells` ma unikat na (user, cell), więc
     * policzenie wspólnych pól wystarczy. `>=`, nie `=`, bo `cells_total` jest
     * liczbą zapisaną przy wgraniu pliku i przy rozjeździe z `known_route_cells`
     * (trasa sprzed migr. 048, przeliczenie w toku) lepiej nadać emblemat niż
     * zablokować go po cichu na zawsze.
     */
    private static function syncRoutes(?int $userId): int
    {
        $db = Database::connection();
        $trasy = $db->query('
            SELECT kr.id, kr.emblem_id, kr.cells_total
              FROM known_routes kr
              JOIN emblems e ON e.id = kr.emblem_id AND e.is_active = 1
             WHERE kr.is_active = 1 AND kr.cells_total > 0
        ')->fetchAll();

        $nadane = 0;
        foreach ($trasy as $trasa) {
            $stmt = $db->prepare('
                INSERT IGNORE INTO emblem_awards (user_id, emblem_id, source, source_id)
                SELECT dc.user_id, :emblem_id, :source, :route_id
                  FROM discovery_cells dc
                  JOIN known_route_cells krc
                    ON krc.cell_id = dc.cell_id AND krc.route_id = :route_id2
                 ' . ($userId !== null ? 'WHERE dc.user_id = :uid' : '') . '
                 GROUP BY dc.user_id
                HAVING COUNT(DISTINCT dc.cell_id) >= :total
            ');
            $params = [
                'emblem_id' => (int) $trasa['emblem_id'],
                'source'    => self::SOURCE_ROUTE,
                'route_id'  => (int) $trasa['id'],
                // Ten sam identyfikator dwa razy — EMULATE_PREPARES=false, więc
                // nazwanego placeholdera nie wolno powtórzyć w zapytaniu.
                'route_id2' => (int) $trasa['id'],
                'total'     => (int) $trasa['cells_total'],
            ];
            if ($userId !== null) {
                $params['uid'] = $userId;
            }
            $stmt->execute($params);
            $nadane += $stmt->rowCount();
        }
        return $nadane;
    }

    /**
     * Wydarzenia — ta sama reguła, inne źródło pól.
     *
     * WYDARZENIE NIE MA `known_route_cells`. Jego pola siatki odkryć liczy
     * `RoutePreview::cellsForGpx()` z plików etapów i wariantów, z cache'em
     * pod hashem treści (migr. 050) — to samo źródło, z którego biorą się
     * regiony wydarzenia (`Event::syncRegions`). Nie budujemy więc trzeciej
     * tabeli pól, tylko czytamy `gpx_route_cells` po hashu.
     *
     * KANDYDACI, NIE SUMA — patrz `eventRouteCandidates()`.
     */
    private static function syncEvents(?int $userId): int
    {
        $db = Database::connection();
        $wydarzenia = $db->query('
            SELECT ev.id, ev.emblem_id
              FROM events ev
              JOIN emblems e ON e.id = ev.emblem_id AND e.is_active = 1
        ')->fetchAll();

        $nadane = 0;
        foreach ($wydarzenia as $wydarzenie) {
            foreach (self::eventRouteCandidates((int) $wydarzenie['id']) as $kandydat) {
                if ($kandydat['total'] === 0) {
                    continue;
                }
                $hashe = [];
                $params = [
                    'emblem_id' => (int) $wydarzenie['emblem_id'],
                    'source'    => self::SOURCE_EVENT,
                    'event_id'  => (int) $wydarzenie['id'],
                    'total'     => $kandydat['total'],
                ];
                foreach (array_values($kandydat['hashes']) as $i => $hash) {
                    $hashe[] = ':h' . $i;
                    $params['h' . $i] = $hash;
                }

                $stmt = $db->prepare('
                    INSERT IGNORE INTO emblem_awards (user_id, emblem_id, source, source_id)
                    SELECT dc.user_id, :emblem_id, :source, :event_id
                      FROM discovery_cells dc
                      JOIN gpx_route_cells grc
                        ON grc.cell_id = dc.cell_id AND grc.gpx_hash IN (' . implode(',', $hashe) . ')
                     ' . ($userId !== null ? 'WHERE dc.user_id = :uid' : '') . '
                     GROUP BY dc.user_id
                    HAVING COUNT(DISTINCT dc.cell_id) >= :total
                ');
                if ($userId !== null) {
                    $params['uid'] = $userId;
                }
                $stmt->execute($params);
                $nadane += $stmt->rowCount();
            }
        }
        return $nadane;
    }

    /**
     * Trasy wydarzenia, z których KAŻDA Z OSOBNA wystarcza do emblematu.
     *
     * ROZRÓŻNIENIE, NA KTÓRYM STOI SENSOWNOŚĆ CAŁEJ REGUŁY:
     *
     *   ETAPY to DNI JEDNEJ WYPRAWY — żeby zaliczyć wielodniówkę, trzeba
     *   przejechać wszystkie. Dlatego etapy sklejają się w JEDNEGO kandydata
     *   (suma pól wszystkich dni).
     *
     *   WARIANTY to ALTERNATYWNE DYSTANSE tego samego wyścigu (50/100/160 km) —
     *   uczestnik wybiera JEDEN. Dlatego każdy wariant jest OSOBNYM kandydatem.
     *   Suma zamiast alternatywy znaczyłaby, że emblemat dostaje tylko ktoś,
     *   kto przejechał wszystkie trzy dystanse naraz, czyli praktycznie nikt.
     *
     * Wydarzenie z wariantami bierze WYŁĄCZNIE warianty: gdy istnieją, to one
     * są trasami do wyboru, a plik etapu jest wtedy zapowiedzią dnia, nie
     * czwartym dystansem.
     *
     * @return list<array{hashes: list<string>, total: int}>
     */
    private static function eventRouteCandidates(int $eventId): array
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT gpx_url FROM event_route_variants WHERE event_id = :id AND gpx_url IS NOT NULL');
        $stmt->execute(['id' => $eventId]);
        $warianty = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if ($warianty) {
            $out = [];
            foreach ($warianty as $gpxUrl) {
                $k = self::candidateFor([(string) $gpxUrl]);
                if ($k !== null) {
                    $out[] = $k;
                }
            }
            return $out;
        }

        $stmt = $db->prepare('SELECT gpx_url FROM event_stages WHERE event_id = :id AND gpx_url IS NOT NULL ORDER BY id');
        $stmt->execute(['id' => $eventId]);
        $etapy = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!$etapy) {
            return [];
        }

        $k = self::candidateFor(array_map('strval', $etapy));
        return $k === null ? [] : [$k];
    }

    /**
     * Hashe i łączna liczba pól dla zestawu plików GPX.
     *
     * `RoutePreview::cellsForGpx()` woła się TYLKO po to, żeby rozgrzać cache
     * (`gpx_route_cells`) — wynik czytamy potem zapytaniem, bo liczba pól musi
     * być policzona po UNII hashów, a nie jako suma długości list (dwa etapy
     * przechodzące przez to samo skrzyżowanie dzielą pole i policzyłoby się
     * ono dwa razy). Plik już policzony nie kosztuje tu nic poza jednym SELECT-em.
     *
     * @param list<string> $gpxUrls
     * @return array{hashes: list<string>, total: int}|null
     */
    private static function candidateFor(array $gpxUrls): ?array
    {
        $hashe = [];
        foreach (array_unique($gpxUrls) as $gpxUrl) {
            $sciezka = CORE_PATH . '/..' . $gpxUrl;
            if (!is_file($sciezka)) {
                continue;
            }
            RoutePreview::cellsForGpx($sciezka);
            $hash = hash_file('sha256', $sciezka);
            if ($hash !== false) {
                $hashe[$hash] = true;
            }
        }
        if (!$hashe) {
            return null;
        }

        $hashe = array_keys($hashe);
        $placeholders = implode(',', array_fill(0, count($hashe), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(DISTINCT cell_id) FROM gpx_route_cells WHERE gpx_hash IN ($placeholders)"
        );
        $stmt->execute($hashe);

        return ['hashes' => $hashe, 'total' => (int) $stmt->fetchColumn()];
    }

    // ───────────────────────────────────────────────────────── ODCZYT

    /**
     * Zdobyte emblematy tej osoby — pod kartę na profilu publicznym.
     *
     * Podpis „za co" składa się TU, a nie w widoku: nazwa trasy/wydarzenia
     * mieszka w innej tabeli dla każdego źródła, a widok nie ma być miejscem,
     * w którym rozstrzyga się, z której. `LEFT JOIN` w obie strony, bo trasa
     * albo wydarzenie mogą zostać skasowane — emblemat zostaje (to jest cała
     * jego idea), tylko traci podpis i link.
     *
     * @return list<array{name:string, description:?string, imageUrl:?string,
     *                    awardedAt:string, sourceLabel:?string, sourceUrl:?string}>
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT em.name, em.description, em.image_url, a.awarded_at, a.source,
                   kr.name AS route_name, kr.slug AS route_slug,
                   ev.title AS event_title, ev.slug AS event_slug
              FROM emblem_awards a
              JOIN emblems em ON em.id = a.emblem_id
              LEFT JOIN known_routes kr ON a.source = "route" AND kr.id = a.source_id
              LEFT JOIN events ev       ON a.source = "event" AND ev.id = a.source_id
             WHERE a.user_id = :uid
             ORDER BY a.awarded_at DESC, a.id DESC
        ');
        $stmt->execute(['uid' => $userId]);

        return array_map(static function (array $r): array {
            $trasa = $r['source'] === self::SOURCE_ROUTE;
            return [
                'name'        => (string) $r['name'],
                'description' => $r['description'],
                'imageUrl'    => $r['image_url'],
                'awardedAt'   => (string) $r['awarded_at'],
                'sourceLabel' => $trasa ? $r['route_name'] : $r['event_title'],
                'sourceUrl'   => $trasa
                    ? ($r['route_slug'] !== null ? '/trasy/' . $r['route_slug'] : null)
                    : ($r['event_slug'] !== null ? '/events/' . $r['event_slug'] : null),
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Emblemat przypięty do trasy — pod sekcję „Za ukończenie" na stronie
     * trasy i pod zaznaczenie „masz go już" dla zalogowanego.
     */
    public static function forRoute(int $routeId, ?int $viewerId = null): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT em.id, em.name, em.description, em.image_url,
                   (SELECT COUNT(*) FROM emblem_awards a
                     WHERE a.emblem_id = em.id AND a.user_id = :uid) AS mine
              FROM known_routes kr
              JOIN emblems em ON em.id = kr.emblem_id AND em.is_active = 1
             WHERE kr.id = :route_id
        ');
        $stmt->execute(['route_id' => $routeId, 'uid' => $viewerId ?? 0]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'name'        => (string) $row['name'],
            'description' => $row['description'],
            'imageUrl'    => $row['image_url'],
            'mine'        => $viewerId !== null && (int) $row['mine'] > 0,
        ];
    }
}
