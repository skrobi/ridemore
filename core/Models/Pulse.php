<?php
// core/Models/Pulse.php
// PULS — „co się dzieje w rowerowym świecie" (Etap 5).
//
// JEDNOSTKĄ PULSU JEST WYJAZD ALBO GRUPA, NIGDY KLIKNIĘCIE. Nie ma tu i nie
// będzie wpisów typu „Jan polubił", „Kasia dodała zdjęcie", „Tomek zmienił
// profil" — każdy wpis mówi o tym, co wydarzyło się NA TRASIE albo wokół
// konkretnego wyjazdu.
//
// Tak jak kronika (Etap 4), Puls NIE MA WŁASNEJ TABELI. Wszystkie pięć typów
// wpisów wyprowadzamy z danych, które już istnieją — obecności, zapisów,
// relacji i wydarzeń. Zysk jest ten sam co przy kronice: nie ma producentów
// zdarzeń do podpięcia w kilkunastu miejscach (a więc nie ma jak zapomnieć
// o jednym), nie ma tabeli, która może rozjechać się ze źródłem, i nie trzeba
// niczego backfillować przy wdrożeniu — Puls od pierwszego dnia zna całą
// historię serwisu.
//
// SKARBY (SKA/12, decyzja usera 2026-08-15) to JEDYNE wpisy w tym feedzie bez
// wyjazdu — i zasada wyżej nadal obowiązuje, bo żaden z nich nie jest
// kliknięciem. „Nowe skarby" to postawione w terenie punkty zwinięte do jednego
// wpisu na dobę; „pierwsze znalezienie" zdarza się każdemu skarbowi RAZ w całej
// jego historii, więc jest samoograniczające się z definicji. Zwykłe kolejne
// znalezienie własnego wpisu NIE DOSTAJE — podpada pod tę samą regułę, co
// odkrycia pól (patrz komentarz przy Discovery w feed()).
//
// Świadomie ODRZUCONE typy wpisów:
//   - „X i Y jechali razem po raz pierwszy" (imiona konkretnej pary) — zamiast
//     tego liczba nowych znajomości wewnątrz wpisu o przejeździe. Ta sama
//     informacja, bez plotkarskiego tonu i bez wywlekania czyjejś relacji.
//   - „ktoś wrócił po przerwie" — najsłabszy sygnał z całej listy, a wymaga
//     analizy luk czasowych per użytkownik. Do rozważenia, gdy będzie ruch.
namespace Models;

use Core\Database;

class Pulse
{
    // Ile ostatnich dni obejmuje Puls. Rytm jest TYGODNIOWY, nie dzienny —
    // przy realnym natężeniu ruchu na wyjazdach rowerowych pusty dzień wygląda
    // jak awaria, a spokojny tydzień wygląda naturalnie.
    private const WINDOW_DAYS = 21;

    /**
     * @param ?string $before KURSOR — pokaż wyłącznie wpisy STARSZE niż ten
     *        znacznik czasu. Stronicowanie kursorowe, nie OFFSET-owe: wpisy
     *        powstają w locie z czterech źródeł i są scalane w PHP, więc OFFSET
     *        wymagałby i tak pobrania wszystkiego, co przed nim. Kursor daje
     *        każdemu z czterech zapytań warunek, który ono samo potrafi wykonać
     *        na własnym indeksie.
     *
     *        Stronicowanie NIE ZASTĘPUJE okna WINDOW_DAYS — feed dalej sięga
     *        21 dni wstecz i nie dalej. Kursor porusza się wewnątrz tego okna.
     */
    public static function feed(int $limit = 30, ?string $before = null): array
    {
        $items = array_merge(
            self::rides($before),
            self::formingGroups($before),
            self::chronicleEntries($before),
            self::openCalls($before),
            self::signups($before),
            self::treasuresAdded($before),
            self::treasureFirstFinds($before),
            self::trackUploads($before)
        );

        // Sortowanie w PHP, nie UNION-em w SQL: pięć zapytań o różnym
        // kształcie czytają się i utrzymują znacznie lepiej niż jedno
        // wielopiętrowe UNION ALL, a przy tej skali danych różnica w koszcie
        // jest żadna.
        usort($items, static fn(array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));
        $items = array_slice($items, 0, $limit);

        // Pełne dane karty wyjazdu dokładane JEDNYM zapytaniem, dopiero do
        // wyciętej listy. Bez tego Puls był suchym tekstem, podczas gdy reszta
        // serwisu ma bogatą kartę — a to często pierwsza strona, na której ktoś
        // ląduje, więc musi nieść komplet faktów (dystans, trudność, region,
        // cena, wolne miejsca, organizator, okładka).
        // Wpisy o skarbach nie mają turnusu i wchodzą tu z `editionId => 0`.
        // Odsiewamy je PRZED zapytaniami o karty, bo inaczej każde z trzech
        // dostałoby w liście parametrów zero, którego i tak nigdy nie dopasuje.
        $editionIds = array_values(array_filter(array_unique(array_column($items, 'editionId'))));
        $cards  = Event::cardsForEditions($editionIds);
        $photos = self::chroniclePhotos($editionIds);
        // Discovery (Etap 8, §21) NIE dostaje własnego typu wpisu. Odkrycie
        // nie jest osobnym zdarzeniem — jest tym, co WYNIKŁO z przejazdu, o
        // którym wpis i tak już mówi. Osobny wpis „ktoś odkrył pola" byłby
        // dokładnie tą mikro-akcją, której ten feed od początku unika.
        $cells  = Discovery::newCellsForEditions($editionIds);

        foreach ($items as $i => $item) {
            $items[$i]['card'] = $cards[$item['editionId']] ?? null;
            $items[$i]['newCells'] = $cells[$item['editionId']] ?? 0;
            // Miniatura: NAJPIERW zdjęcie wrzucone przez uczestnika do kroniki
            // tego turnusu, dopiero potem okładka wydarzenia. Feed ma pokazywać,
            // co ludzie faktycznie przywieźli z trasy — okładka to materiał
            // organizatora sprzed wyjazdu i przy wpisie „przejechali" byłaby
            // zdjęciem nie na temat.
            $items[$i]['photoUrl'] = $photos[$item['editionId']]
                ?? ($cards[$item['editionId']]['coverPhotoUrl'] ?? null);
        }

        return $items;
    }

    // Najświeższe zdjęcie z kroniki per turnus, jednym zapytaniem dla całej
    // listy (zamiast round-tripu na wpis).
    private static function chroniclePhotos(array $editionIds): array
    {
        if (!$editionIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($editionIds), '?'));
        $stmt = Database::connection()->prepare("
            SELECT r.edition_id, p.url
            FROM event_photos p
            JOIN event_recaps r ON r.id = p.recap_id
            WHERE r.edition_id IN ($in)
            ORDER BY p.created_at DESC, p.id DESC
        ");
        $stmt->execute($editionIds);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            // Pierwszy wygrywa (ORDER BY malejąco) — kolejne dla tego samego
            // turnusu pomijamy.
            $out[(int) $row['edition_id']] ??= $row['url'];
        }
        return $out;
    }

    /**
     * Warunek kursora dla zapytań, w których znacznik czasu jest AGREGATEM
     * (`MAX(...) AS at`) — trzy z pięciu generatorów tak mają, a agregatu nie
     * wolno postawić w WHERE.
     */
    private static function cursorHaving(?string $before): string
    {
        return $before !== null ? ' AND at < :before' : '';
    }

    /** Parametry z kursorem albo bez — żeby nie powtarzać tego `if` pięć razy. */
    private static function cursorParams(?string $before, array $params): array
    {
        if ($before !== null) {
            $params['before'] = $before;
        }
        return $params;
    }

    // 1. GRUPA PRZEJECHAŁA TRASĘ — najmocniejszy wpis w całym Pulsie, bo mówi
    // o czymś, co naprawdę się wydarzyło w terenie. Niesie od razu dwa dodatkowe
    // fakty: dla ilu osób był to debiut w tym regionie i ile znajomości zawiązało
    // się tego dnia.
    //
    // Próg 2 osób: „użytkownicy ukończyli WSPÓLNĄ trasę" — samotny przejazd nie
    // jest sygnałem społecznościowym, choć oczywiście trafia do kroniki i profilu.
    private static function rides(?string $before = null): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, e.cover_photo_url,
                   ed.id AS edition_id, ed.start_date,
                   reg.name AS region_label,
                   COUNT(DISTINCT r.user_id) AS people_count,
                   MAX(a.declared_at) AS at
            FROM event_attendance a
            JOIN event_rsvps r ON r.id = a.rsvp_id
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN events e ON e.id = r.event_id
            LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
            WHERE a.attended = 1
              AND a.declared_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY e.slug, e.title, e.cover_photo_url, ed.id, ed.start_date, reg.name
            HAVING people_count >= 2" . self::cursorHaving($before) . "
            ORDER BY at DESC
            LIMIT 40
        ");
        $stmt->execute(self::cursorParams($before, ['days' => self::WINDOW_DAYS]));

        return array_map(static function (array $row): array {
            $editionId = (int) $row['edition_id'];
            return [
                'type'         => 'przejazd',
                'at'           => $row['at'],
                'eventSlug'    => $row['slug'],
                'editionId'    => $editionId,
                'title'        => $row['title'],
                'regionLabel'  => $row['region_label'],
                'startDate'    => $row['start_date'],
                'coverPhotoUrl'=> $row['cover_photo_url'],
                'peopleCount'  => (int) $row['people_count'],
                'firstTimers'  => EventAttendance::firstTimersInRegionForEdition($editionId),
                'newPairs'     => RiderConnection::newPairsFromEdition($editionId),
                'isUpcoming'   => false,
            ];
        }, $stmt->fetchAll());
    }

    // 2. SKŁAD SIĘ ZBIERA — jedyny wpis skierowany w przyszłość i jedyne miejsce,
    // gdzie Puls realnie napędza zapisy. Pokazuje wyjazd, na który ludzie właśnie
    // się zapisują, wraz z tym, ile miejsc jeszcze zostało.
    private static function formingGroups(?string $before = null): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, e.cover_photo_url,
                   ed.id AS edition_id, ed.start_date, ed.max_participants,
                   reg.name AS region_label,
                   COUNT(DISTINCT r.user_id) AS people_count,
                   MAX(r.joined_at) AS at
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
            WHERE ed.start_date >= CURDATE()
              AND r.joined_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY e.slug, e.title, e.cover_photo_url, ed.id, ed.start_date,
                     ed.max_participants, reg.name
            HAVING people_count >= 2" . self::cursorHaving($before) . "
            ORDER BY at DESC
            LIMIT 40
        ");
        $stmt->execute(self::cursorParams($before, ['days' => self::WINDOW_DAYS]));

        return array_map(static function (array $row): array {
            $max = $row['max_participants'] !== null ? (int) $row['max_participants'] : null;
            $count = (int) $row['people_count'];
            return [
                'type'         => 'sklad',
                'at'           => $row['at'],
                'eventSlug'    => $row['slug'],
                'editionId'    => (int) $row['edition_id'],
                'title'        => $row['title'],
                'regionLabel'  => $row['region_label'],
                'startDate'    => $row['start_date'],
                'coverPhotoUrl'=> $row['cover_photo_url'],
                'peopleCount'  => $count,
                // Wolne miejsca tylko, gdy limit w ogóle ustawiony — nie
                // wymyślamy pilności tam, gdzie jej nie ma.
                'spotsLeft'    => ($max !== null && $max > $count) ? $max - $count : null,
                'isUpcoming'   => true,
            ];
        }, $stmt->fetchAll());
    }

    // 3. NOWY WPIS W KRONICE — treść od użytkowników, ale zawsze przypięta do
    // konkretnego przejechanego wyjazdu, nigdy jako samodzielny „post".
    private static function chronicleEntries(?string $before = null): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, e.cover_photo_url,
                   ed.id AS edition_id, ed.start_date,
                   reg.name AS region_label,
                   COUNT(*) AS entries_count,
                   MAX(rc.created_at) AS at
            FROM event_recaps rc
            JOIN event_editions ed ON ed.id = rc.edition_id
            JOIN events e ON e.id = rc.event_id
            LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
            WHERE rc.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY e.slug, e.title, e.cover_photo_url, ed.id, ed.start_date, reg.name
            HAVING 1 = 1" . self::cursorHaving($before) . "
            ORDER BY at DESC
            LIMIT 40
        ");
        $stmt->execute(self::cursorParams($before, ['days' => self::WINDOW_DAYS]));

        return array_map(static fn(array $row): array => [
            'type'         => 'kronika',
            'at'           => $row['at'],
            'eventSlug'    => $row['slug'],
            'editionId'    => (int) $row['edition_id'],
            'title'        => $row['title'],
            'regionLabel'  => $row['region_label'],
            'startDate'    => $row['start_date'],
            'coverPhotoUrl'=> $row['cover_photo_url'],
            'entriesCount' => (int) $row['entries_count'],
            'isUpcoming'   => false,
        ], $stmt->fetchAll());
    }

    // 4. KTOŚ SZUKA TOWARZYSTWA — „pokręcę z kimś". Jedyny typ podaży, który nie
    // zależy od organizatorów, więc dla Pulsu przy chudym kalendarzu jest
    // nieproporcjonalnie ważny.
    private static function openCalls(?string $before = null): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, e.cover_photo_url, e.created_at AS at,
                   ed.id AS edition_id, ed.start_date,
                   reg.name AS region_label
            FROM events e
            JOIN dictionary_items typ
              ON typ.id = e.event_type_item_id AND typ.code = 'pokrec_z_kims'
            JOIN dictionary_items st
              ON st.id = e.status_item_id AND st.code IN ('published', 'full')
            JOIN event_editions ed ON ed.event_id = e.id
            LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) reg ON reg.event_id = e.id
            WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)" . ($before !== null ? " AND e.created_at < :before" : "") . "
              AND ed.start_date >= CURDATE()
            ORDER BY e.created_at DESC
            LIMIT 20
        ");
        $stmt->execute(self::cursorParams($before, ['days' => self::WINDOW_DAYS]));

        return array_map(static fn(array $row): array => [
            'type'         => 'wezwanie',
            'at'           => $row['at'],
            'eventSlug'    => $row['slug'],
            'editionId'    => (int) $row['edition_id'],
            'title'        => $row['title'],
            'regionLabel'  => $row['region_label'],
            'startDate'    => $row['start_date'],
            'coverPhotoUrl'=> $row['cover_photo_url'],
            'isUpcoming'   => true,
        ], $stmt->fetchAll());
    }

    /**
     * Polska odmiana przez liczbę: 1 / 2-4 / 5+ (z wyjątkiem nastek).
     *
     * Ta sama reguła siedzi w kilku widokach jako domknięcie `$plural`, ale
     * TUTAJ tytuł powstaje w modelu (bo zależy od zwinięcia wierszy), więc
     * potrzebuje własnej kopii — inaczej wychodziło „2 nowych skarbów".
     */
    private static function odmiana(int $n, string $jeden, string $kilka, string $wiele): string
    {
        if ($n === 1) {
            return $jeden;
        }
        $ost = $n % 10;
        $ost2 = $n % 100;
        return ($ost >= 2 && $ost <= 4 && ($ost2 < 12 || $ost2 > 14)) ? $kilka : $wiele;
    }

    // 8. KTOŚ WGRAŁ ŚLADY (2026-09-11, zgłoszenie usera: „w Pulsie nie widać
    // tego, że użytkownicy wgrali swoje ślady — jeśli ktoś zaimportował wiele,
    // powinien iść komunikat, że taki rowerzysta wrzucił X śladów, z linkiem do
    // jego profilu").
    //
    // ZWIJANY DO JEDNEGO WPISU NA OSOBĘ I DOBĘ, nie jeden na ślad — i tu jest
    // to szczególnie ważne, bo import z Garmina wciąga CAŁĄ HISTORIĘ naraz.
    // Jeden taki import to potrafi być kilkaset przejazdów w kwadrans; bez
    // zwinięcia pierwsze podłączenie licznika wyczyściłoby cały Puls. Ta sama
    // decyzja i ten sam powód co przy `treasuresAdded` niżej.
    //
    // TO JEDYNY WPIS W CAŁYM PULSIE, KTÓRY WYMIENIA CZŁOWIEKA Z IMIENIA, i jest
    // to świadome odstępstwo od zasady trzymanej od PULS/1 („bez imienia — kto
    // to zrobił, jest sprawą jego profilu"). Decyzja usera z 2026-09-11: wpis ma
    // prowadzić DO PROFILU, więc musi powiedzieć, czyjego. Dlatego bramka
    // `roster_visible` i wymóg `public_slug` stoją w samym zapytaniu: kto ukrył
    // się z listy rowerzystów, nie pojawia się tu ani z imieniem, ani wcale.
    //
    // DATĄ WPISU JEST `created_at` (kiedy plik trafił do serwisu), NIE
    // `ride_date` (kiedy przejazd się odbył). Puls mówi „co się właśnie dzieje";
    // wgranie archiwum sprzed dwóch lat jest zdarzeniem DZISIEJSZYM i tak ma się
    // sortować — inaczej import historii nie pokazałby się w feedzie ani razu.
    private static function trackUploads(?string $before = null): array
    {
        // DWA LEFT JOIN-y PYTAJĄ TYLKO O JEDNO: czy jest z czego narysować
        // obrazek śladu na karcie (2026-09-12). Wiersz bez policzonej geometrii
        // nie narysuje się nigdy — a widok, który mimo to wstawiłby <img>,
        // pokazałby ikonę zepsutego obrazka po 404. Taniej zapytać tu, raz dla
        // całego feedu, niż strzelać żądaniem i sprzątać po nim w przeglądarce.
        //
        // ŹRÓDŁO ZALEŻY OD TEGO, CZYJ TO ŚLAD: solo (`edition_id = 0`) wolno
        // pokazać obcemu WYŁĄCZNIE po przycięciu okolic domu (§27), więc liczy
        // się `gpx_geometry_trimmed`; ślad z wyjazdu jest publiczny z natury
        // i idzie ze zwykłej tabeli. Ta sama para źródeł, którą rozróżnia
        // TileSource::tracks('all') dla mapy społeczności.
        //
        // `points IS NOT NULL`, a nie `point_count > 0` — bo dokładnie o to
        // pyta zapytanie o kadr w GpxGeometry; dwa różne warunki na to samo
        // rozjechałyby się przy pierwszym niepełnym wierszu.
        $stmt = Database::connection()->prepare("
            SELECT u.id AS user_id, u.name, u.email, u.public_slug,
                   MAX(a.created_at) AS at,
                   DATE(a.created_at) AS doba,
                   MIN(a.id) AS ride_id,
                   COUNT(*) AS ile,
                   SUM(a.distance_km) AS km,
                   SUM(a.cells_new) AS pola,
                   SUM(CASE WHEN COALESCE(a.edition_id, 0) = 0
                            THEN (gt.gpx_hash IS NOT NULL)
                            ELSE (gg.gpx_hash IS NOT NULL) END) AS z_geometria
              FROM rider_activities a
              JOIN users u ON u.id = a.user_id
                          AND u.roster_visible = 1
                          AND u.public_slug IS NOT NULL AND u.public_slug <> ''
         LEFT JOIN gpx_geometry_trimmed gt ON gt.gpx_hash = a.gpx_hash AND gt.points IS NOT NULL
         LEFT JOIN gpx_geometry         gg ON gg.gpx_hash = a.gpx_hash AND gg.points IS NOT NULL
             WHERE a.gpx_url IS NOT NULL
               AND a.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY u.id, u.name, u.email, u.public_slug, DATE(a.created_at)
            HAVING 1 = 1" . self::cursorHaving($before) . "
             ORDER BY at DESC
             LIMIT 20
        ");
        $stmt->execute(self::cursorParams($before, ['days' => self::WINDOW_DAYS]));

        return array_map(static function (array $row): array {
            // Imię, a w razie jego braku część adresu przed małpą — ta sama
            // reguła, którą stosuje profil rowerzysty i lista odkrywców na
            // /odkrycia. Konto bez jednego i drugiego nie istnieje (rejestracja
            // wymaga adresu), więc nie ma tu gałęzi na pustkę.
            $imie = trim((string) ($row['name'] ?? ''));
            if ($imie === '') {
                $imie = (string) strstr((string) $row['email'], '@', true);
            }

            $ile = (int) $row['ile'];

            return [
                'type'         => 'slady-wgrane',
                'at'           => $row['at'],
                'eventSlug'    => null,
                'editionId'    => 0,
                'title'        => $imie,
                'riderSlug'    => (string) $row['public_slug'],
                'count'        => $ile,
                'km'           => (float) $row['km'],
                // ID PRZEJAZDU TYLKO WTEDY, GDY W GRUPIE JEST DOKŁADNIE JEDEN.
                // `MIN(a.id)` przy dwóch śladach wskazywałby losowy z nich,
                // a wpis mówi o całej dobie — wtedy właściwym adresem jest
                // profil, nie jeden wybrany przejazd.
                'rideId'       => $ile === 1 ? (int) $row['ride_id'] : null,
                // OBRAZEK ŚLADU (2026-09-12). `mapKey` identyfikuje GRUPĘ
                // (osoba + doba), czyli dokładnie to, czym jest ten wpis.
                // `mapStamp` to moment ostatniego wgrania w grupie: kolejny
                // ślad tego samego dnia zmienia stempel, więc zmienia adres
                // — obrazek unieważnia się sam, bez kasowania czegokolwiek
                // (ta sama sztuczka co data w adresie „Trasy dnia").
                'mapKey'       => 'sw-' . (int) $row['user_id'] . '-' . str_replace('-', '', (string) $row['doba']),
                'mapStamp'     => date('YmdHis', strtotime((string) $row['at'])),
                // Ile śladów grupy MA policzoną geometrię. Zero → widok nie
                // wstawia obrazka w ogóle (patrz komentarz przy zapytaniu).
                'mapReady'     => (int) $row['z_geometria'],
                // `cellsNew`, NIE `newCells`: tej drugiej nazwy `feed()`
                // używa na pola odkryte przez TURNUS i nadpisuje ją każdemu
                // wpisowi (dla wpisu bez turnusu zerem), więc wartość spod
                // tego klucza i tak by tu nie dojechała.
                // `cells_new` bywa NULL przy śladzie sprzed siatki odkryć —
                // rzutowanie na int daje wtedy zero, a zero po prostu nie
                // wchodzi do zdania (patrz widok).
                'cellsNew'     => (int) $row['pola'],
                'regionLabel'  => null,
                'startDate'    => null,
                'coverPhotoUrl'=> null,
                'isUpcoming'   => false,
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Hashe śladów JEDNEJ grupy wpisu „wgrane ślady" (osoba + doba), rozbite
     * na źródła geometrii (2026-09-12, obrazek śladu na karcie Pulsu).
     *
     * Solo (`edition_id = 0`) idzie kluczem `trimmed`, bo obcemu wolno je
     * pokazać WYŁĄCZNIE po wycięciu okolic domu (§27) — ta sama granica, którą
     * trzyma publiczna mapa społeczności. Ślad z wyjazdu jest publiczny
     * z natury (zbiórka jest ogłaszana) i idzie kluczem `normal`.
     *
     * STOI TU, OBOK `trackUploads()`, NIE W KONTROLERZE OBRAZKA: definicja
     * grupy („kto i której doby") musi w kodzie istnieć raz. Dwie kopie
     * rozjechałyby się przy pierwszej zmianie zwijania i karta mówiłaby
     * o 37 śladach, rysując 35.
     *
     * BEZ OKNA `WINDOW_DAYS`, celowo: grupa domyka się z końcem doby, a raz
     * zapisany obrazek bywa proszony długo po tym, jak wpis wypadł z feedu.
     * Bramkę „komu wolno" stawia wołający (`Support::visibleRiderById`) — i to
     * ona ma prawo zmienić odpowiedź w czasie, gdy ktoś ukryje się z listy.
     *
     * @return array{trimmed:string[], normal:string[]}
     */
    public static function trackGroupHashes(int $userId, string $day): array
    {
        $stmt = Database::connection()->prepare("
            SELECT a.gpx_hash, COALESCE(a.edition_id, 0) AS edycja
              FROM rider_activities a
             WHERE a.user_id = :uid
               AND DATE(a.created_at) = :doba
               AND a.gpx_url IS NOT NULL
               AND a.gpx_hash IS NOT NULL AND a.gpx_hash <> ''
        ");
        $stmt->execute(['uid' => $userId, 'doba' => $day]);

        // Klucze tablicy, nie append — ten sam plik wgrany dwa razy ma jeden
        // hash i nie ma powodu rysować go dwukrotnie.
        $zbior = ['trimmed' => [], 'normal' => []];
        foreach ($stmt->fetchAll() as $row) {
            $zrodlo = (int) $row['edycja'] === 0 ? 'trimmed' : 'normal';
            $zbior[$zrodlo][(string) $row['gpx_hash']] = true;
        }

        return [
            'trimmed' => array_keys($zbior['trimmed']),
            'normal'  => array_keys($zbior['normal']),
        ];
    }

    // 6. NOWE SKARBY NA MAPIE — pierwszy typ wpisu bez wyjazdu (SKA/12).
    //
    // ZWIJANY DO JEDNEGO WPISU NA DOBĘ, nie jeden na skarb, i to jest tu
    // decydujące. Skarby stawia się PARTIAMI — jeden wyjazd z rolką naklejek to
    // kilkanaście punktów wprowadzonych w kwadrans. Bez zwinięcia pierwszy taki
    // wieczór zasypałby cały Puls, a wpisy byłyby nierozróżnialne.
    //
    // Wpis jest ZAPROSZENIEM, nie relacją: mówi, ile rzeczy pojawiło się do
    // znalezienia, więc jego naturalne miejsce jest obok „ktoś szuka
    // towarzystwa", a nie obok „przejechali".
    private static function treasuresAdded(?string $before = null): array
    {
        $stmt = Database::connection()->prepare("
            SELECT DATE(t.created_at) AS dzien,
                   MAX(t.created_at) AS at,
                   COUNT(*) AS ile,
                   COUNT(DISTINCT t.region_item_id) AS regionow,
                   MAX(r.name) AS region_label,
                   MAX(t.name) AS przyklad,
                   -- ID i poziom ujawnienia PRZYDATNE TYLKO PRZY PARTII
                   -- JEDNOELEMENTOWEJ (patrz `treasureId` niżej) — przy
                   -- większej MAX() wskazuje losowy skarb z partii i nie ma
                   -- prawa trafić do linku.
                   MAX(t.id) AS jedyny_id,
                   MAX(t.reveal_level) AS jedyny_reveal
              FROM treasures t
              LEFT JOIN dictionary_items r ON r.id = t.region_item_id
             WHERE t.is_active = 1 AND t.status = 'ACTIVE'
               AND t.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY DATE(t.created_at)
            HAVING 1 = 1" . self::cursorHaving($before) . "
             ORDER BY at DESC
             LIMIT 20
        ");
        $stmt->execute(self::cursorParams($before, ['days' => self::WINDOW_DAYS]));

        return array_map(static function (array $row): array {
            $ile = (int) $row['ile'];
            return [
                'type'         => 'skarb-nowe',
                'at'           => $row['at'],
                'eventSlug'    => null,
                'editionId'    => 0,
                'title'        => $ile === 1
                    ? (string) $row['przyklad']
                    : $ile . ' ' . self::odmiana($ile, 'nowy skarb', 'nowe skarby', __('nowych skarbów'))
                          . ' do znalezienia',
                // Region podpisujemy TYLKO wtedy, gdy cała partia jest z
                // jednego — „Beskidy i 3 inne regiony" nie jest informacją,
                // tylko szumem.
                'regionLabel'  => ((int) $row['regionow'] === 1) ? $row['region_label'] : null,
                // KADR NA MAPIE TYLKO DLA PARTII JEDNOELEMENTOWEJ (2026-09-11).
                // Ten wpis zwija CAŁĄ DOBĘ do jednego wiersza, więc przy
                // kilkunastu skarbach nie ma czegoś takiego jak „ten skarb" —
                // i wtedy mapa całej okolicy jest prawidłową odpowiedzią.
                // Gdy w partii jest dokładnie jeden, tytuł wpisu JEST jego
                // nazwą, więc link musi prowadzić tam, co tytuł obiecuje.
                // Bramka poziomu ujawnienia jak przy pierwszym znalezieniu.
                'treasureId'   => ($ile === 1 && (int) ($row['jedyny_reveal'] ?? 2) >= 2)
                    ? (int) $row['jedyny_id']
                    : null,
                'count'        => $ile,
                'startDate'    => null,
                'coverPhotoUrl'=> null,
                'isUpcoming'   => false,
            ];
        }, $stmt->fetchAll());
    }

    // 7. PIERWSZE ZNALEZIENIE — drugi i ostatni wpis bez wyjazdu.
    //
    // NIE ZWIJAMY GO, bo nie ma czego: każdy skarb ma pierwsze znalezienie
    // dokładnie RAZ w całej swojej historii. Liczba takich wpisów jest z
    // definicji ograniczona liczbą postawionych skarbów i maleje w czasie —
    // dokładna odwrotność zwykłego znalezienia, które powtarza się bez końca
    // i dlatego własnego wpisu nie dostaje.
    //
    // PRYWATNOŚĆ: bez imienia, tak samo jak przy zapisach. Wpis mówi, że skarb
    // przestał czekać — kto go zdjął z mapy, jest sprawą jego profilu.
    private static function treasureFirstFinds(?string $before = null): array
    {
        $stmt = Database::connection()->prepare("
            SELECT t.id AS treasure_id, t.name, t.rarity, t.reveal_level,
                   c.name AS category_label, c.icon AS category_icon,
                   r.name AS region_label,
                   MIN(f.claimed_at) AS at
              FROM treasure_finds f
              JOIN treasures t ON t.id = f.treasure_id
              LEFT JOIN dictionary_items c ON c.id = t.category_item_id
              LEFT JOIN dictionary_items r ON r.id = t.region_item_id
             WHERE t.is_active = 1 AND t.status = 'ACTIVE'
             GROUP BY f.treasure_id, t.id, t.name, t.rarity, t.reveal_level, c.name, c.icon, r.name
            HAVING at >= DATE_SUB(NOW(), INTERVAL :days DAY)" . self::cursorHaving($before) . "
             ORDER BY at DESC
             LIMIT 20
        ");
        $stmt->execute(self::cursorParams($before, ['days' => self::WINDOW_DAYS]));

        return array_map(static fn(array $row): array => [
            'type'         => 'skarb-pierwszy',
            'at'           => $row['at'],
            'eventSlug'    => null,
            'editionId'    => 0,
            // Czysty tekst — patrz ta sama uwaga w Models\RiderFeed::treasures.
            'title'        => (string) $row['name'],
            // ID POD KADR NA MAPIE (2026-09-11, zgłoszenie usera: „skarby
            // w Pulsie linkują nie do skarbu, tylko do mapy społecznościowej").
            // Odbiorcą jest `/odkrycia?skarb={id}` — kadrowanie dodane
            // 2026-09-11 pod przycisk w powiadomieniu o nowym skarbie w okolicy.
            //
            // TYLKO DLA SKARBU JAWNEGO, i to nie jest ostrożność na wyrost:
            // `DiscoveryController::kadrNaSkarbie()` i tak odda współrzędne
            // wyłącznie takiemu, więc dla ukrytego adres z parametrem byłby
            // obietnicą bez pokrycia — link wyglądałby tak samo, a wiózł
            // donikąd. Lepiej go wtedy nie składać.
            'treasureId'   => (int) ($row['reveal_level'] ?? 2) >= 2
                ? (int) $row['treasure_id']
                : null,
            'icon'         => $row['category_icon'] ?: null,
            'regionLabel'  => $row['region_label'],
            'categoryLabel'=> $row['category_label'],
            'rarity'       => $row['rarity'],
            'startDate'    => null,
            'coverPhotoUrl'=> null,
            'isUpcoming'   => false,
        ], $stmt->fetchAll());
    }

    // 5. KTOŚ SIĘ ZAPISAŁ — najmniejszy sygnał w tym feedzie i jedyny, który
    // mówi o POJEDYNCZEJ decyzji (dodany 2026-08-13 na prośbę usera).
    //
    // ZWIJANY DO JEDNEGO WPISU NA WYJAZD I DOBĘ, nie jeden wpis na zapis. Bez
    // tego pierwszy popularny wyjazd zasypałby cały Puls dwudziestoma
    // identycznymi wierszami i unieważnił zasadę „jednostką jest wyjazd albo
    // grupa, nigdy kliknięcie" — feed zamieniłby się w log zdarzeń.
    //
    // Wyjazdy, na które zapisały się co najmniej DWIE osoby, są już opisane
    // przez wpis „skład się zbiera" (formingGroups) — ten typ dokłada więc
    // wyłącznie pojedyncze zapisy, żeby te dwa wpisy nie mówiły o tym samym.
    //
    // PRYWATNOŚĆ: żadnych imion. Wpis mówi ILE osób i na co, nigdy kto —
    // `roster_visible` nie ma tu więc czego filtrować, bo tożsamość i tak nie
    // wychodzi (ta sama zasada co przy składzie: ukrywamy TOŻSAMOŚĆ, nie FAKT).
    private static function signups(?string $before = null): array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, e.cover_photo_url,
                   ed.id AS edition_id, ed.start_date,
                   reg.name AS region_label,
                   COUNT(*) AS signup_count,
                   MAX(r.joined_at) AS at
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
            WHERE r.joined_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
              AND ed.start_date >= CURDATE()
            GROUP BY e.slug, e.title, e.cover_photo_url, ed.id, ed.start_date, reg.name,
                     DATE(r.joined_at)
            HAVING signup_count = 1" . self::cursorHaving($before) . "
            ORDER BY at DESC
            LIMIT 30
        ");
        $stmt->execute(self::cursorParams($before, ['days' => self::WINDOW_DAYS]));

        return array_map(static fn(array $row): array => [
            'type'         => 'zapis',
            'at'           => $row['at'],
            'eventSlug'    => $row['slug'],
            'editionId'    => (int) $row['edition_id'],
            'title'        => $row['title'],
            'regionLabel'  => $row['region_label'],
            'startDate'    => $row['start_date'],
            'coverPhotoUrl'=> $row['cover_photo_url'],
            'signupCount'  => (int) $row['signup_count'],
            'isUpcoming'   => true,
        ], $stmt->fetchAll());
    }
}
