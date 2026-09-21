<?php
// core/Models/RiderFeed.php
// AKTYWNOŚĆ JEDNEJ OSOBY — „co ta osoba wniosła do serwisu", pod mapą na jej
// profilu publicznym.
//
// STOSUNEK DO PULSU
// -----------------
// Puls (Models\Pulse) odpowiada na pytanie „co się dzieje" i jego jednostką
// jest WYJAZD ALBO GRUPA, nigdy kliknięcie. Tutaj pytanie jest inne — „kim ta
// osoba jest jako uczestnik" — więc jednostką jest COŚ, CO NAPISAŁA ALBO
// WGRAŁA. To nie jest ten sam feed zawężony do jednej osoby i dlatego nie
// próbujemy przerabiać Pulsu parametrem: tamten grupuje wpisy per wydarzenie
// („3 wpisy w kronice"), a tutaj każdy wpis ma być osobny, bo to konkretna
// rzecz, którą ktoś zrobił.
//
// CZEGO TU NIE MA, ŚWIADOMIE
// --------------------------
// Zapisów, wypisań, ocen wystawionych innym ludziom, wejść na stronę. Zasada
// jest ta sama, którą Puls trzyma od Etapu 5: pokazujemy TREŚĆ, nie zdarzenia.
// „Jan zapisał się na wyjazd" to zdarzenie i wisi w Pulsie; „Jan napisał wpis
// do kroniki" to treść i ma autora, więc należy do jego profilu.
//
// Tak jak Puls i kronika, ten feed NIE MA WŁASNEJ TABELI — wszystko wyprowadza
// się z tabel, które już istnieją. Nie ma więc czego backfillować ani czego
// rozjeżdżać ze źródłem: profil od pierwszego dnia zna całą historię osoby.
namespace Models;

use Core\Database;

class RiderFeed
{
    /** Ile znaków treści pokazujemy w zajawce, zanim utniemy. */
    private const EXCERPT = 180;

    /**
     * Wpisów na stronę. Dziesięć, nie pięć jak przy przejazdach obok mapy:
     * tam wiersz stoi w kolumnie szerokiej na 280 px, tutaj ma całą szerokość
     * strony i zajawkę treści, więc dycha czyta się jeszcze jak lista, a nie
     * jak ściana.
     */
    private const PER_PAGE = 10;

    /**
     * Sufit wierszy pobieranych z JEDNEGO źródła.
     *
     * Bez niego głęboka strona kazałaby przeczytać całą historię czterech tabel,
     * żeby pokazać dziesięć wierszy. 300 wystarcza na 30 stron, a profil, który
     * ma więcej, i tak potrzebuje filtrów, nie głębszego stronicowania.
     */
    private const MAX_SCAN = 300;

    /**
     * Aktywność osoby, od najnowszej.
     *
     * BEZ OKNA CZASOWEGO — inaczej niż Puls. Puls pokazuje „co się dzieje",
     * więc wpis sprzed roku jest tam szumem; profil pokazuje „kim ta osoba
     * jest", a tam historia sprzed roku jest dokładnie tym, po co się przyszło.
     *
     * STRONICOWANIE KROKOWE, NIE KURSOROWE — inaczej niż Puls. Tam feed rośnie
     * w czasie rzeczywistym i wpis dopisany między stronami przesuwałby offset;
     * tutaj historia jednej osoby jest zamknięta, a numer strony daje to, czego
     * kursor nie potrafi: pozycję („11–20 z 200") i możliwość cofnięcia się.
     *
     * @return array{items:array<int,array>, page:int, perPage:int, from:int,
     *               to:int, hasPrev:bool, hasNext:bool}
     */
    public static function forUser(int $userId, int $page = 0, int $perPage = self::PER_PAGE, ?int $viewerId = null): array
    {
        // Ile wierszy musi przyslac KAZDE zrodlo, zeby po scaleniu wystarczylo
        // na zadana strone: w najgorszym razie wszystkie wpisy tej strony
        // pochodza z jednego zrodla. Sufit chroni przed zapytaniem bez granic,
        // gdyby ktos wpisal numer strony z kosmosu.
        $page = max(0, $page);
        $need = min(self::MAX_SCAN, ($page + 1) * $perPage);

        $items = array_merge(
            self::chronicle($userId, $need),
            self::comments($userId, $need),
            self::photos($userId, $need),
            self::tracks($userId, $need),
            self::solos($userId, $need),
            self::treasures($userId, $need, $viewerId)
        );

        // Scalanie i sortowanie w PHP, nie UNION-em: cztery zapytania o różnym
        // kształcie czytają się lepiej niż jedno wielopiętrowe, a przy skali
        // jednego profilu różnica w koszcie jest żadna. To ta sama decyzja
        // co w Models\Pulse::feed().
        usort($items, static fn(array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        $total = count($items);
        $slice = array_slice($items, $page * $perPage, $perPage);

        return [
            'items'   => $slice,
            'page'    => $page,
            'perPage' => $perPage,
            'from'    => $slice ? $page * $perPage + 1 : 0,
            'to'      => $page * $perPage + count($slice),
            'hasPrev' => $page > 0,
            'hasNext' => $total > ($page + 1) * $perPage,
        ];
    }

    /** Ile wpisów ta osoba ma w ogóle — pod nagłówek sekcji. */
    public static function countForUser(int $userId): int
    {
        $db = Database::connection();
        $n = 0;
        foreach ([
            'SELECT COUNT(*) FROM event_recaps WHERE author_user_id = :u',
            'SELECT COUNT(*) FROM event_comments WHERE user_id = :u',
            'SELECT COUNT(*) FROM event_photos WHERE uploaded_by = :u',
            'SELECT COUNT(*) FROM edition_tracks WHERE uploaded_by_user_id = :u',
            'SELECT COUNT(*) FROM rider_activities WHERE user_id = :u AND source_code = "solo" AND gpx_url IS NOT NULL',
            'SELECT COUNT(*) FROM treasure_finds WHERE user_id = :u',
        ] as $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute(['u' => $userId]);
            $n += (int) $stmt->fetchColumn();
        }
        return $n;
    }

    /** Wpisy do kroniki wyjazdu (event_recaps) — najbogatszy typ treści. */
    private static function chronicle(int $userId, int $need): array
    {
        $stmt = Database::connection()->prepare('
            SELECT rc.id, rc.body, rc.created_at AS at, rc.edition_id,
                   e.slug, e.title, e.cover_photo_url, ed.start_date
              FROM event_recaps rc
              JOIN events e ON e.id = rc.event_id
              LEFT JOIN event_editions ed ON ed.id = rc.edition_id
             WHERE rc.author_user_id = :u
             ORDER BY rc.created_at DESC
             LIMIT ' . (int) $need . '
        ');
        $stmt->execute(['u' => $userId]);

        return array_map(static fn(array $r): array => [
            'type'      => 'kronika',
            'at'        => $r['at'],
            'title'     => $r['title'],
            'url'       => '/kronika/' . $r['slug'] . ($r['edition_id'] ? '?termin=' . (int) $r['edition_id'] : ''),
            'excerpt'   => self::excerpt($r['body']),
            'cover'     => $r['cover_photo_url'],
            'startDate' => $r['start_date'],
        ], $stmt->fetchAll());
    }

    /** Komentarze pod wydarzeniami. FAQ organizatora liczy się osobno. */
    private static function comments(int $userId, int $need): array
    {
        $stmt = Database::connection()->prepare('
            SELECT c.id, c.body, c.created_at AS at, c.is_faq, c.is_organizer_reply,
                   e.slug, e.title, e.cover_photo_url
              FROM event_comments c
              JOIN events e ON e.id = c.event_id
             WHERE c.user_id = :u
             ORDER BY c.created_at DESC
             LIMIT ' . (int) $need . '
        ');
        $stmt->execute(['u' => $userId]);

        return array_map(static fn(array $r): array => [
            'type'    => !empty($r['is_faq']) ? 'faq' : (!empty($r['is_organizer_reply']) ? 'odpowiedz' : 'komentarz'),
            'at'      => $r['at'],
            'title'   => $r['title'],
            'url'     => '/events/' . $r['slug'] . '#dyskusja',
            'excerpt' => self::excerpt($r['body']),
            'cover'   => $r['cover_photo_url'],
        ], $stmt->fetchAll());
    }

    /**
     * Zdjęcia — ZWINIĘTE do jednego wpisu na wydarzenie i dzień.
     *
     * Bez zwijania jedna sesja wrzucania dwudziestu zdjęć zalałaby cały feed
     * i wypchnęła z niego wszystko inne. To ta sama decyzja co przy zapisach
     * w Pulsie (Models\Pulse::signups).
     */
    private static function photos(int $userId, int $need): array
    {
        $stmt = Database::connection()->prepare('
            SELECT COUNT(*) AS n, MAX(p.created_at) AS at,
                   e.slug, e.title, e.cover_photo_url
              FROM event_photos p
              JOIN events e ON e.id = p.event_id
             WHERE p.uploaded_by = :u
             GROUP BY e.slug, e.title, e.cover_photo_url, DATE(p.created_at)
             ORDER BY at DESC
             LIMIT ' . (int) $need . '
        ');
        $stmt->execute(['u' => $userId]);

        return array_map(static fn(array $r): array => [
            'type'  => 'zdjecia',
            'at'    => $r['at'],
            'title' => $r['title'],
            'url'   => '/events/' . $r['slug'],
            'count' => (int) $r['n'],
            'cover' => $r['cover_photo_url'],
        ], $stmt->fetchAll());
    }

    /** Wgrane ślady — jedyny wpis, który jest jednocześnie dowodem przejazdu. */
    private static function tracks(int $userId, int $need): array
    {
        $stmt = Database::connection()->prepare('
            SELECT t.id, t.created_at AS at, t.distance_km, t.label,
                   e.slug, e.title, e.cover_photo_url, t.edition_id
              FROM edition_tracks t
              JOIN event_editions ed ON ed.id = t.edition_id
              JOIN events e ON e.id = ed.event_id
             WHERE t.uploaded_by_user_id = :u
             ORDER BY t.created_at DESC
             LIMIT ' . (int) $need . '
        ');
        $stmt->execute(['u' => $userId]);

        return array_map(static fn(array $r): array => [
            'type'     => 'slad',
            'at'       => $r['at'],
            'title'    => $r['title'],
            // ID ŚLADU — to samo, którym posługuje się lista „Przejazdy ze
            // śladem" obok mapy (data-track). Dzięki temu kliknięcie we wpis
            // aktywności zaznacza ślad TYM SAMYM mechanizmem, zamiast budować
            // drugi. `url` zostaje jako zapasowe wyjście dla przeglądarki bez JS.
            'trackId'  => (int) $r['id'],
            'url'      => '/kronika/' . $r['slug'] . '?termin=' . (int) $r['edition_id'],
            'km'       => (float) $r['distance_km'],
            'label'    => $r['label'],
            'cover'    => $r['cover_photo_url'],
        ], $stmt->fetchAll());
    }

    /**
     * PRZEJAZDY SOLO (migr. 049) — dołączone 2026-08-25 po uwadze usera:
     * „mam wiele solo przejazdów, które się nie pojawiają w aktywności".
     * Feed czytał wyłącznie edition_tracks, więc osoba jeżdżąca głównie solo
     * miała profil mówiący o niej nieprawdę.
     *
     * STEROWANIE MAPĄ: `activityId` — plik GPX wisi na przejazdach
     * (rider_activities.gpx_url), nie na edition_tracks, więc podświetlenie
     * idzie inną mapą URL-i niż ślady wyjazdów (Support::trackUrlsForFeed).
     * Plik jest przycięty u źródła (§27) — bezpieczny dla każdego widza.
     */
    private static function solos(int $userId, int $need): array
    {
        // NAZWA WŁASNA WYGRYWA (2026-09-11, zgłoszenie usera: „zmieniłem nazwę
        // przejazdu, a w aktywności dalej stoi «Przejazd solo»"). Do tej daty
        // zapytanie nie pobierało nawet `a.name` i wpis miał tytuł wpisany na
        // sztywno — czyli profil przeczył temu, co właściciel sam ustawił.
        //
        // Regułę priorytetu (własna > z licznika > generyczna) trzyma JEDNA
        // metoda, `RideController::rideName()` — publiczna właśnie po to, żeby
        // dymek na mapie, strona przejazdu i ta lista nie rozjechały się na
        // trzy różne odpowiedzi na to samo pytanie.
        $stmt = Database::connection()->prepare('
            SELECT a.id AS activity_id, a.ride_date AS at, a.distance_km,
                   a.name, a.source_code,
                   d.activity_name AS device_name
              FROM rider_activities a
              LEFT JOIN device_activities d ON d.rider_activity_id = a.id
             WHERE a.user_id = :u AND a.source_code = "solo" AND a.gpx_url IS NOT NULL
             ORDER BY a.ride_date DESC, a.id DESC
             LIMIT ' . (int) $need . '
        ');
        $stmt->execute(['u' => $userId]);

        return array_map(static fn(array $r): array => [
            'type'       => 'solo',
            'at'         => $r['at'],
            'title'      => \Controllers\RideController::rideName($r),
            'activityId' => (int) $r['activity_id'],
            'url'        => '#mapa',
            'km'         => (float) $r['distance_km'],
        ], $stmt->fetchAll());
    }

    /**
     * Znalezione skarby (Etap 8D).
     *
     * Mieści się w zasadzie tego feedu („treść, nie zdarzenia"), mimo że
     * znalezienie jest jednym kliknięciem: rzecz, która się liczy, wydarzyła
     * się W TERENIE — ktoś tam dojechał, zsiadł z roweru i znalazł naklejkę.
     * Kliknięcie tylko to potwierdza.
     *
     * ZAGADKA ZOSTAJE ZAGADKĄ (2026-09-13). Wpis „Znaleziony skarb" oddawał
     * OBCEMU nazwę Tropu i Ukrytego — feed pytał wyłącznie o znalazcę, a nie
     * o to, kto ogląda. Ta sama reguła co `Treasure::reveal()` i
     * `Treasure::showcaseForUser()`: kto sam tego skarbu nie znalazł, dostaje
     * „Tajemnica rozwiązana", bez nazwy, a przy Ukrytym także bez kategorii
     * i regionu. `$viewerId = null` (gość) maskuje zawsze.
     */
    private static function treasures(int $userId, int $need, ?int $viewerId = null): array
    {
        try {
            $stmt = Database::connection()->prepare('
                SELECT f.id, t.id AS treasure_id, f.claimed_at AS at, f.points_awarded, f.method,
                       t.name, t.code, t.reveal_level, c.name AS category_label, c.icon AS category_icon,
                       r.name AS region_label,
                       EXISTS (SELECT 1 FROM treasure_finds v
                                WHERE v.treasure_id = t.id AND v.user_id = :viewer) AS viewer_found
                  FROM treasure_finds f
                  JOIN treasures t ON t.id = f.treasure_id
                  LEFT JOIN dictionary_items c ON c.id = t.category_item_id
                  LEFT JOIN dictionary_items r ON r.id = t.region_item_id
                 WHERE f.user_id = :u
                 ORDER BY f.claimed_at DESC
                 LIMIT ' . (int) $need . '
            ');
            $stmt->execute(['u' => $userId, 'viewer' => $viewerId ?? 0]);
        } catch (\Throwable $e) {
            // Środowisko przed migracją 054 nie ma tych tabel — feed ma wtedy
            // po prostu jedno źródło mniej, a nie pustą stronę profilu.
            return [];
        }

        return array_map(static function (array $r) use ($userId, $viewerId): array {
            $level = (int) $r['reveal_level'];
            $masked = $level < 2 && $viewerId !== $userId && !(bool) $r['viewer_found'];
            $hidden = $masked && $level === 0;
            return [
            'type'   => 'skarb',
            'at'     => $r['at'],
            // Tytul jest CZYSTYM TEKSTEM (widok go escapuje), wiec ikona nie ma
            // jak sie w nim znalezc — leci osobno, jako klucz do Utils\Icon.
            'title'  => $masked ? __('Tajemnica rozwiązana') : (string) $r['name'],
            'icon'   => $hidden ? null : ($r['category_icon'] ?: null),
            // SAMO ID, bez współrzędnych. Feed renderuje się na PUBLICZNEJ
            // stronie, a wstawienie w niego lat/lon zdradzałoby położenie
            // skarbów ukrytych, które warstwa mapy celowo przycina. Pozycję
            // pobiera dopiero kliknięcie, przez /api/treasures/{id}.
            'treasureId' => (int) $r['treasure_id'],
            // KOTWICA DO MAPY NA TEJ STRONIE, nie adres innej.
            //
            // Dwie rzeczy naraz. Po pierwsze: świadomie bez /skarb/{code} —
            // kod z naklejki jest sekretem (dla niego istnieje
            // Treasure::rotateCode), a w linku na publicznym profilu dałby
            // każdemu zaliczenie skarbu z kanapy. Po drugie: wcześniej stało
            // tu /odkrycia — czyli wpis o czyjejś aktywności wyprowadzał
            // oglądającego z profilu na mapę SPOŁECZNOŚCI, żeby pokazać mu
            // cudze dane (uwaga usera 2026-08-15). Teraz zostajemy na miejscu;
            // resztę robi JS, który przewija do mapy i wskazuje pinezkę.
            'url'    => '#mapa',
            'points' => (int) $r['points_awarded'],
            'region' => $hidden ? null : $r['region_label'],
            'label'  => $hidden ? __('Skarb ukryty') : $r['category_label'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Zajawka treści. Ucinamy na GRANICY SŁOWA, nie w połowie wyrazu — wpis
     * urwany na „przejechal" wygląda na uszkodzone dane, a nie na skrót.
     */
    private static function excerpt(?string $body): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $body));
        if (mb_strlen($text) <= self::EXCERPT) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::EXCERPT);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
    }
}
