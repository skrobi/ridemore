<?php
// core/Controllers/Admin/PointsController.php
// PANEL PUNKTÓW — /admin/punkty (Etap 8A/15).
//
// Do tej pory jedyną drogą do wartości punktowych była edycja pliku
// core/discovery.php i wgranie go na serwer, a bonus per wydarzenie ustawiało
// się WPROST W BAZIE (patrz migr. 044). Ten ekran nie zmienia tej architektury —
// konfiguracja globalna dalej mieszka w pliku, bo jest częścią wdrożenia i ma
// przechodzić przez przegląd kodu jak każda inna zmiana reguł gry.
//
// Ekran robi dwie rzeczy, których w pliku zrobić się nie da:
//   1. daje bonusy PER TRASA i PER WYDARZENIE — to są dane, nie reguły, i
//      wpisywanie ich w SQL-u było jedynym miejscem w tym module, gdzie admin
//      musiał tknąć bazę. OBIE listy (2026-09-03) pokazują TYLKO wyjątki —
//      trasa z wartością automatyczną i wydarzenie bez bonusu w ogóle się nie
//      renderują. Dodanie jest świadomym krokiem przez wyszukiwarkę
//      (`routeSearchResults()`/`eventSearchResults()`), żeby przy większym
//      katalogu ekran nie był ścianą kart, które i tak nic nie zmieniają,
//   2. pokazuje ostatnie naliczenia z rejestru — jedyny sposób, żeby sprawdzić,
//      czy zmiana zadziałała, bez zaglądania do point_transactions.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Auth;
use Core\Csrf;
use Core\Database;
use Models\DiscoveryScoring;
use Models\KnownRoute;
use Models\PointLedger;
use Models\ScoringSettings;
use Utils\View;

class PointsController
{
    public static function index(): void
    {
        $db = Database::connection();

        // Ostatnie naliczenia — bez filtrów i bez paginacji, bo to podgląd
        // kontrolny („czy po zmianie liczy się to, co miało"), a nie przeglądarka
        // historii. Do audytu konkretnej osoby służy jej profil.
        $recent = $db->query('
            SELECT pt.source, pt.points, pt.description, pt.ride_date,
                   u.name AS user_name, u.email AS user_email
              FROM point_transactions pt
              JOIN users u ON u.id = pt.user_id
             ORDER BY pt.id DESC
             LIMIT 40
        ')->fetchAll();

        // LISTA POKAZUJE TYLKO WYJĄTKI (2026-09-03, druga uwaga usera): przy
        // dziesiątkach tras karta na każdą, w większości identyczna
        // („Automatycznie"), była nieczytelną ścianą. Trasa z wartością
        // automatyczną w ogóle się tu nie pokazuje — admin dodaje ją przez
        // wyszukiwarkę tylko wtedy, gdy naprawdę ma być wyjątkiem.
        $allRoutes = self::routesWithScoring();
        $manualRoutes = array_values(array_filter($allRoutes, static fn(array $r): bool => $r['mode'] === 'manual'));

        $q = trim((string) ($_GET['q'] ?? ''));
        $searching = isset($_GET['q']);

        // TA SAMA ZASADA CO PRZY TRASACH (2026-09-03, trzecia uwaga usera):
        // lista pokazuje TYLKO wydarzenia, które już mają bonus — resztę
        // dodaje się świadomie przez wyszukiwarkę, żeby ekran nie wypisywał
        // całego kalendarza w oczekiwaniu, że akurat któreś dostanie punkty.
        $eventsWithBonus = self::eventsWithBonus();
        $eq = trim((string) ($_GET['eq'] ?? ''));
        $eventSearching = isset($_GET['eq']);

        View::render('web', 'points-admin', [
            'title'       => 'Punkty i trasy — ridemore.bike',
            'noindex'     => true,
            // STAWKI DO EDYCJI (migr. 053). Panel dostaje dla każdej: wartość
            // obowiązującą, domyślną z pliku i informację, czy ktoś ją nadpisał
            // — bez tego trzeciego nie da się odróżnić „ustawione tak celowo"
            // od „nigdy nie ruszane".
            // Stawki znanych tras (liczba progów, wartość domyślna, pkt/km)
            // osobno od reszty — to jedna spójna sekcja o punktacji tras
            // razem z kartami tras niżej, nie kolejny wiersz w ogólnej
            // siatce (zgłoszenie usera 2026-09-03: rozrzucone po ekranie,
            // trudne do ogarnięcia).
            'rates'       => self::editableRates(),
            'trailRates'  => self::editableTrailRates(),
            'lastChange'  => ScoringSettings::lastChange(),
            'routes'      => $manualRoutes,
            'hasAnyRoutes' => (bool) $allRoutes,
            'routeSearch' => [
                'q'         => $q,
                'searching' => $searching,
                'results'   => $searching
                    ? self::routeSearchResults($q, array_column($manualRoutes, 'id'))
                    : [],
            ],
            'events'      => $eventsWithBonus,
            // Emblematy do selectów przy wydarzeniach (migr. 087) — tylko
            // aktywne, bo wycofanego nie ma sensu przypinać na nowo.
            'emblems'     => \Models\Emblem::all(true),
            'eventSearch' => [
                'q'         => $eq,
                'searching' => $eventSearching,
                'results'   => $eventSearching
                    ? self::eventSearchResults($eq, array_column($eventsWithBonus, 'id'))
                    : [],
            ],
            'recent'      => $recent,
            'totals'      => self::totals(),
            'info'        => ($_GET['info'] ?? '') === 'zapisane' ? 'Zapisano.' : null,
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Punkty i trasy']],
        ]);
    }

    /**
     * Wartość trasy — `known_routes.bonus_points`, NULL = policz automatycznie
     * (patrz DiscoveryScoring::trailValueFor()).
     *
     * `value_mode` rozstrzyga, co zapisać, PRZED odczytaniem pola: przy „auto"
     * formularz mógł wysłać w nim sugerowaną wartość (widoczną na ekranie jako
     * podpowiedź), a to nie jest to samo co ręczne nadpisanie — dlatego przy
     * „auto" pole idzie do bazy jako NULL niezależnie od tego, co w nim stoi.
     *
     * `completion_bonus` jest tu ZAWSZE zerowane (nie ma go już w formularzu):
     * to legacy kolumna sprzed modelu „jedna wartość dzielona równo" — panel
     * pisze od teraz wyłącznie do `bonus_points` (patrz
     * DiscoveryScoring::legacyRouteOverrideTotal(), które nadal poprawnie
     * odczyta trasy zapisane przed tą zmianą).
     */
    public static function saveRoute(string $id): void
    {
        if (Csrf::check($_POST['csrf_token'] ?? null)) {
            $manual = ($_POST['value_mode'] ?? 'auto') === 'manual';
            $raw = trim((string) ($_POST['bonus_points'] ?? ''));
            $points = (!$manual || $raw === '') ? null : max(0, (int) $raw);

            Database::connection()->prepare('
                UPDATE known_routes
                   SET bonus_enabled = :enabled, bonus_points = :points, completion_bonus = NULL
                 WHERE id = :id
            ')->execute([
                'enabled' => empty($_POST['bonus_enabled']) ? 0 : 1,
                'points'  => $points,
                'id'      => (int) $id,
            ]);
        }
        self::back();
    }

    /**
     * Bonus za udział w konkretnym wydarzeniu (migr. 044, `events.point_bonus`).
     * NULL = brak — model ma UMOŻLIWIAĆ nagrodę za wybrane wydarzenie, nie
     * dawać jej każdemu.
     */
    public static function saveEvent(string $id): void
    {
        if (Csrf::check($_POST['csrf_token'] ?? null)) {
            $raw = trim((string) ($_POST['point_bonus'] ?? ''));

            // EMBLEMAT (migr. 087) JEDZIE TYM SAMYM FORMULARZEM co bonus —
            // jeden wiersz listy, jeden przycisk „Zapisz". Osobna akcja
            // znaczyłaby dwa przyciski obok siebie robiące „to samo" i dwa
            // miejsca, w których trzeba pamiętać o CSRF.
            //
            // Formularz „Dodaj" (nowe wydarzenie na liście) NIE ma pola
            // emblematu i wtedy `emblem_id` w POST nie istnieje — `?? null`
            // daje wtedy null, czyli dokładnie stan, w jakim wydarzenie
            // wchodzi na listę. Zero i pustka znaczą „bez emblematu": zapisanie
            // `0` wywaliłoby się o klucz obcy zamiast po prostu go zdjąć.
            $emblem = (int) ($_POST['emblem_id'] ?? 0);

            Database::connection()
                ->prepare('UPDATE events SET point_bonus = :bonus, emblem_id = :emblem WHERE id = :id')
                ->execute([
                    'bonus'  => $raw === '' ? null : max(0, (int) $raw),
                    'emblem' => $emblem > 0 ? $emblem : null,
                    'id'     => (int) $id,
                ]);

            // Jak przy trasach: przypięcie emblematu ma zadziałać od razu,
            // także wstecz, dla ludzi, którzy przejechali tę trasę dawno temu.
            \Models\Emblem::sync();
        }
        self::back();
    }

    /**
     * Aktualne wartości + PRZYKŁADY policzone tym samym kodem, który nalicza
     * punkty naprawdę. Liczby przepisane ręcznie do widoku rozjechałyby się
     * z konfiguracją przy pierwszej jej zmianie — a ten ekran istnieje właśnie
     * po to, żeby dało się jej zaufać.
     */
    /**
     * Zapis stawek globalnych. Puste pole = KASUJEMY nadpisanie, czyli powrót
     * do wartości z core/discovery.php — dlatego formularz nie musi znać
     * liczby domyślnej, żeby dało się do niej wrócić.
     */
    public static function saveRates(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }
        $userId = Auth::user()?->id;
        foreach (array_keys(ScoringSettings::EDITABLE) as $key) {
            // Klucz w nazwie pola ma podkreślenia zamiast kropek — kropka
            // w `name` formularza jest przez PHP zamieniana na podkreślenie
            // przy budowaniu $_POST i klucz przestałby się zgadzać.
            $field = 'rate_' . str_replace('.', '__', $key);
            if (!array_key_exists($field, $_POST)) {
                continue;
            }
            $raw = trim((string) $_POST[$field]);
            ScoringSettings::set($key, $raw === '' ? null : (float) str_replace(',', '.', $raw), $userId);
        }
        self::back();
    }

    /** @return array<int,array{key:string,label:string,unit:string,hint:string,value:float,default:float,overridden:bool}> */
    private static function editableRates(): array
    {
        return self::formatRates(static fn(string $key): bool => !str_starts_with($key, 'trails.'));
    }

    /** Te same stawki, ale TYLKO „Znana trasa — ..." — renderowane w sekcji tras, nie w ogólnej siatce. */
    private static function editableTrailRates(): array
    {
        return self::formatRates(static fn(string $key): bool => str_starts_with($key, 'trails.'));
    }

    /** @return array<int,array{key:string,label:string,unit:string,hint:string,value:float,default:float,overridden:bool}> */
    private static function formatRates(callable $include): array
    {
        $defaults = DiscoveryScoring::defaults();
        $overrides = ScoringSettings::all();
        $out = [];
        foreach (ScoringSettings::EDITABLE as $key => $meta) {
            if (!$include($key)) {
                continue;
            }
            $default = ScoringSettings::defaultFor($key, $defaults);
            if ($default === null) {
                continue;   // parametr zniknął z pliku — nie pokazujemy martwego pola
            }
            $out[] = [
                'key'        => $key,
                'field'      => 'rate_' . str_replace('.', '__', $key),
                'label'      => $meta['label'],
                'unit'       => $meta['unit'],
                'hint'       => $meta['hint'],
                'value'      => $overrides[$key] ?? $default,
                'default'    => $default,
                'overridden' => isset($overrides[$key]),
            ];
        }
        return $out;
    }

    /**
     * Wyniki pod przycisk „Ustaw wartości ręcznie" — ta sama wyszukiwarka
     * (nazwa/adres/region), co na /admin/znane-trasy (`KnownRoute::search()`),
     * z dołożoną sugestią automatyczną i BEZ tras, które są już na liście
     * ręcznych niżej (dodanie drugi raz nic by nie znaczyło).
     *
     * @param int[] $excludeIds
     * @return array<int,array<string,mixed>>
     */
    private static function routeSearchResults(string $q, array $excludeIds): array
    {
        if ($q === '') {
            return [];
        }
        $rate = (float) (DiscoveryScoring::config()['trails']['points_per_km'] ?? 0);
        $items = KnownRoute::search(['szukaj' => $q])['items'];
        $items = array_values(array_filter(
            $items,
            static fn(array $r): bool => !in_array((int) $r['id'], $excludeIds, true)
        ));

        return array_map(static function (array $r) use ($rate): array {
            $distanceKm = (float) $r['distance_km'];
            $r['suggestedTotal'] = DiscoveryScoring::trailValueFor($distanceKm, null)['total'];
            $r['hasDistance'] = $distanceKm > 0 && $rate > 0;
            return $r;
        }, $items);
    }

    /**
     * Trasy + WSZYSTKO, co widok potrzebuje, żeby pokazać „ile ta trasa jest
     * warta" bez własnego liczenia — sekcja „Znana trasa — punktacja" tylko
     * renderuje to, co policzył DiscoveryScoring, tą samą metodą, która
     * naliczy naprawdę (KnownRoute::syncProgress, TrailController::show).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function routesWithScoring(): array
    {
        $rate = (float) (DiscoveryScoring::config()['trails']['points_per_km'] ?? 0);

        return array_map(static function (array $r) use ($rate): array {
            $distanceKm = (float) $r['distance_km'];
            $override = DiscoveryScoring::legacyRouteOverrideTotal(
                $r['bonus_points'] !== null ? (int) $r['bonus_points'] : null,
                $r['completion_bonus'] !== null ? (int) $r['completion_bonus'] : null
            );

            $value = DiscoveryScoring::trailValueFor($distanceKm, $override);
            $r['mode'] = $value['source'] === 'override' ? 'manual' : 'auto';
            $r['total'] = $value['total'];
            $r['breakdown'] = DiscoveryScoring::trailAwards(100, $value['total']);
            $r['hasDistance'] = $distanceKm > 0 && $rate > 0;
            $r['rate'] = $rate;
            // Co dałaby AUTOMATYCZNA wartość, niezależnie od aktualnego trybu
            // — pod tekst przy kafelku „Automatycznie" (widoczny też, gdy
            // trasa jest akurat na ręcznej wartości, jako punkt odniesienia).
            $r['suggestedTotal'] = DiscoveryScoring::trailValueFor($distanceKm, null)['total'];
            return $r;
        }, KnownRoute::all(false));
    }

    /**
     * Wydarzenia z już ustawionym bonusem ALBO z emblematem — resztę dodaje
     * wyszukiwarka (`eventSearchResults()`).
     *
     * WARUNEK JEST ALTERNATYWĄ OD migr. 087 i to nie jest kosmetyka: emblemat
     * bywa jedyną nagrodą (wydarzenie może dawać odznakę bez ani jednego
     * punktu). Przy samym `point_bonus IS NOT NULL` takie wydarzenie znikałoby
     * z tej listy zaraz po przypisaniu emblematu — czyli nie dałoby się go już
     * z panelu zdjąć ani nawet zobaczyć.
     */
    private static function eventsWithBonus(): array
    {
        return Database::connection()->query("
            SELECT e.id, e.slug, e.title, e.point_bonus, e.emblem_id, ed.start_date
              FROM events e
              JOIN dictionary_items st ON st.id = e.status_item_id AND st.code IN ('published', 'full', 'completed')
              LEFT JOIN event_editions ed ON ed.id = (
                    SELECT MIN(x.id) FROM event_editions x WHERE x.event_id = e.id
              )
             WHERE e.point_bonus IS NOT NULL OR e.emblem_id IS NOT NULL
             ORDER BY ed.start_date DESC
        ")->fetchAll();
    }

    /**
     * Wyniki pod przycisk „Dodaj bonus punktów wydarzenia" — po tytule, z tego
     * samego zbioru statusów co lista wyżej (draft nie dostaje bonusu — nie ma
     * jeszcze komu go przyznać), bez wydarzeń, które już mają bonus ustawiony.
     *
     * @param int[] $excludeIds
     */
    private static function eventSearchResults(string $q, array $excludeIds): array
    {
        if ($q === '') {
            return [];
        }
        $stmt = Database::connection()->prepare("
            SELECT e.id, e.slug, e.title, ed.start_date
              FROM events e
              JOIN dictionary_items st ON st.id = e.status_item_id AND st.code IN ('published', 'full', 'completed')
              LEFT JOIN event_editions ed ON ed.id = (
                    SELECT MIN(x.id) FROM event_editions x WHERE x.event_id = e.id
              )
             WHERE e.title LIKE :q
             ORDER BY ed.start_date DESC
             LIMIT 30
        ");
        $stmt->execute(['q' => '%' . $q . '%']);

        return array_values(array_filter(
            $stmt->fetchAll(),
            static fn(array $e): bool => !in_array((int) $e['id'], $excludeIds, true)
        ));
    }

    private static function totals(): array
    {
        $db = Database::connection();
        return [
            'transactions' => (int) $db->query('SELECT COUNT(*) FROM point_transactions')->fetchColumn(),
            'points'       => (int) $db->query('SELECT COALESCE(SUM(points), 0) FROM point_transactions')->fetchColumn(),
            'riders'       => (int) $db->query('SELECT COUNT(DISTINCT user_id) FROM point_transactions')->fetchColumn(),
            'bySource'     => $db->query('
                SELECT source, SUM(points) AS pts, COUNT(*) AS n
                  FROM point_transactions GROUP BY source ORDER BY pts DESC
            ')->fetchAll(),
        ];
    }

    private static function back(): void
    {
        header('Location: ' . View::url('/admin/punkty?info=zapisane'));
        exit;
    }
}
