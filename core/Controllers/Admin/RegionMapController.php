<?php
// core/Controllers/Admin/RegionMapController.php
// NARZĘDZIE „REGIONY NA MAPIE" (/admin/regiony-mapa, 2026-09-01).
//
// PO CO ISTNIEJE. Region ma geometrię (region_cells, migr. 070), ale jedyną
// drogą jej nadania był plik z granicami administracyjnymi. Dla kraju, którego
// podział ma być autorski i zgrubny (Czechy, Słowacja), takiego pliku nie ma.
// Ten ekran pozwala DODAĆ region do słownika i NARYSOWAĆ jego obrys — a potem
// zapisuje go jako GeoJSON, czyli w formacie, który system już czyta.
//
// CZEGO TU ŚWIADOMIE NIE MA: liczenia pokrycia Z GEOMETRII. Pokrycie CAŁYCH
// regionów liczy `backfill_regions.php` — ten sam kod i ten sam przebieg co
// dla województw. Gdyby ten ekran liczył je sam, powstałaby druga
// implementacja tego samego (trzy przebiegi, styki, dziedziczenie od
// sąsiadów). Narzędzie mówi tylko, KIEDY import trzeba puścić.
//
// WYJĄTEK (2026-09-10): `assignCell()` niżej pisze do `region_cells` wprost,
// z pominięciem geometrii — ale to POPRAWKA POJEDYNCZEGO HEKSA, nie druga
// implementacja importu. Patrz uwaga w `Models\RegionOutline::assignCell()`.
//
// GEOMETRIA IDZIE OSOBNĄ KOŃCÓWKĄ (`geometry`), a nie w HTML-u: to ok. 150 kB
// współrzędnych wszystkich regionów, których przeglądarka nie musi pobierać
// razem z każdym przeładowaniem panelu.
//
// Adres pod /admin, a nie /api: końcówki JSON-owe tego ekranu są jego
// prywatnym zapleczem i mają dziedziczyć bramkę admina z admin/routes.php
// ($adminGet/$adminPost), zamiast dokładać kolejny rodzaj autoryzacji
// w api/routes.php.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Csrf;
use Core\Database;
use Models\Dictionary;
use Models\RegionOutline;
use Utils\DiscoveryGrid;
use Utils\View;

class RegionMapController
{
    // Paleta legendy. Kolor jest WYŁĄCZNIE etykietą w narzędziu (nie trafia
    // ani do bazy, ani do GeoJSON-a) — przydzielany po kolejności na liście,
    // żeby ten sam region miał ten sam kolor przy każdym wejściu.
    private const PALETTE = [
        '#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2',
        '#db2777', '#65a30d', '#ea580c', '#4f46e5', '#0d9488', '#b91c1c',
        '#9333ea', '#059669', '#c026d3', '#ca8a04',
    ];

    public static function index(): void
    {
        $komunikaty = ['dodano' => 'Region dodany.', 'usunieto' => 'Obrys usunięty.'];
        $bledy = [
            'kod'      => 'Kod może zawierać tylko małe litery, cyfry i podkreślenia (max 64 znaki).',
            'nazwa'    => 'Podaj nazwę (max 128 znaków).',
            'duplikat' => 'Taki kod już istnieje w słowniku regionów.',
            'rodzic'   => 'Wybrany kraj nadrzędny nie istnieje.',
            'region'   => 'Nie znaleziono regionu.',
        ];

        View::render('web', 'region-map-admin', [
            'title'       => 'Regiony na mapie — ridemore.bike',
            'regions'     => self::regionList(),
            'sizeM'       => DiscoveryGrid::sizeM(RegionOutline::RES_OUTLINE),
            // Poziom poprawki pojedynczego pola (2026-09-10) — ten sam co
            // discovery_cells/region_cells, o rząd wielkości mniejszy niż
            // pole obrysu (RES_OUTLINE), bo tu poprawia się JEDEN heks, nie
            // rysuje całą granicę.
            'sizeMCell'   => DiscoveryGrid::sizeM(DiscoveryGrid::RES_CELL),
            'info'        => $komunikaty[$_GET['info'] ?? ''] ?? null,
            'error'       => $bledy[$_GET['blad'] ?? ''] ?? null,
            'extraHead'   => Support::leafletMapHead() . "\n"
                           . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>',
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Regiony na mapie']],
            'noindex'     => true,
        ]);
    }

    /**
     * Geometria WSZYSTKICH regionów — rysowanych ręką i zaimportowanych
     * z granic administracyjnych.
     *
     * Narzędzie musi widzieć jedno i drugie: bez tego rysuje się na ślepo —
     * nie widać, do czego się dokłada, gdzie została biała plama i w co się
     * wchodzi (zgłoszenie usera 2026-09-01).
     */
    public static function geometry(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        // Współrzędne skracamy do 5 miejsc (ok. 1 m) — to jest podkład do
        // rysowania i klikania, a nie źródło importu; pełna precyzja zostaje
        // w plikach.
        $out = [];
        foreach (RegionOutline::allRegions() as $code => $region) {
            $out[$code] = [
                'name'     => $region['name'],
                'editable' => $region['editable'],
                'priority' => $region['priority'],
                'rings'    => array_map(
                    static fn(array $ring): array => array_map(
                        static fn(array $p): array => [round($p[0], 5), round($p[1], 5)],
                        $ring
                    ),
                    $region['rings']
                ),
            ];
        }
        echo json_encode(['regions' => $out], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Zapis obrysu jednego regionu.
     *
     * Przeglądarka przysyła ŚRODKI klikniętych heksów (lat/lon), nie
     * identyfikatory pól — dosunięciem do siatki zajmuje się model, dzięki
     * czemu jedynym właścicielem pakowania cell_id zostaje Utils\DiscoveryGrid.
     */
    public static function save(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];

        if (!Csrf::check($body['csrf_token'] ?? null)) {
            self::error('Nieważny token formularza — odśwież stronę.');
            return;
        }

        $item = self::regionItem($body['region'] ?? null);
        if ($item === null) {
            self::error('Nie znaleziono regionu.');
            return;
        }
        // Kontener-kraj nie dostaje obrysu: pokrycie liczy się w aplikacji po
        // LIŚCIACH słownika (Discovery::regionsForUser, regionProgress), więc
        // geometria przypięta do kraju byłaby geometrią, której nikt nie
        // policzy. Sprawdzane na serwerze, nie tylko wyszarzeniem w liście.
        if (!self::isLeaf((int) $item['id'])) {
            self::error('To jest kraj (kontener) — rysuj obrysy jego regionów, nie jego samego.');
            return;
        }
        // Województwo ma granicę administracyjną w osobnym pliku. Zapisanie mu
        // obrysu z ręki dałoby DWA feature'y z tym samym kodem (backfill
        // przerywa import na duplikacie) i zamieniło granicę dokładną na
        // przybliżoną do ok. 8 km. Podgląd — tak; edycja — nie.
        $wszystkie = RegionOutline::allRegions();
        $istniejacy = $wszystkie[$item['code']] ?? null;
        if ($istniejacy !== null && !$istniejacy['editable']) {
            self::error('Ten region ma granice z importu administracyjnego — tutaj tylko podgląd.');
            return;
        }

        $wynik = RegionOutline::save($item['code'], $item['name'], (array) ($body['ring'] ?? []));
        if (!($wynik['ok'] ?? false)) {
            self::error($wynik['error'] ?? 'Nie udało się zapisać.');
            return;
        }

        // Odsyłamy geometrię WSZYSTKIEGO, nie tylko zapisanego: mapa ma się
        // przerysować z tego, co naprawdę leży na dysku.
        echo json_encode($wynik, JSON_UNESCAPED_UNICODE);
    }

    /**
     * POPRAWKA POJEDYNCZEGO POLA (2026-09-10) — omija geometrię i pisze
     * wprost do `region_cells` przez `RegionOutline::assignCell()`. Działa
     * na KAŻDYM regionie, TAKŻE z importu administracyjnego (województwa) —
     * w odróżnieniu od `save()` wyżej, tu nie ma bramki `editable`, bo to
     * jest właśnie ta świadomie dopuszczona zdolność. Patrz uwaga w
     * `Models\RegionOutline` o tym, czemu to MUSI być osobna droga, a nie
     * mały obrys pod tym samym kodem.
     */
    public static function assignCell(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];

        if (!Csrf::check($body['csrf_token'] ?? null)) {
            self::error('Nieważny token formularza — odśwież stronę.');
            return;
        }

        $item = self::regionItem($body['region'] ?? null);
        if ($item === null) {
            self::error('Nie znaleziono regionu.');
            return;
        }
        // Ten sam warunek co przy obrysie: kontener-kraj nie ma pokrycia
        // heksowego, bo liczy się je po LIŚCIACH słownika.
        if (!self::isLeaf((int) $item['id'])) {
            self::error('To jest kraj (kontener) — przypisuj pola do jego regionów, nie do niego.');
            return;
        }

        $lat = is_numeric($body['lat'] ?? null) ? (float) $body['lat'] : null;
        $lon = is_numeric($body['lon'] ?? null) ? (float) $body['lon'] : null;
        if ($lat === null || $lon === null || $lat < -85.0 || $lat > 85.0 || $lon < -180.0 || $lon > 180.0) {
            self::error('Nieprawidłowy punkt.');
            return;
        }

        // DOSUNIĘCIE DO SIATKI ROBI SERWER, nie przeglądarka — ta sama zasada
        // co przy zapisie obrysu (`RegionOutline::save()`). Poziom RES_CELL,
        // nie RES_OUTLINE: to jest ten sam heks, który niesie `discovery_cells`.
        $cellId = DiscoveryGrid::pointToCell($lat, $lon, DiscoveryGrid::RES_CELL);
        RegionOutline::assignCell($cellId, (int) $item['id']);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Dodanie pozycji słownika `region` — kraju (bez rodzica) albo regionu
     * w jego obrębie. Ta sama walidacja i to samo wejście do bazy co w panelu
     * taksonomii (Dictionary::createItem); tutaj po to, żeby nie trzeba było
     * wychodzić z mapy po każdym nowym regionie.
     */
    public static function createRegion(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
        }

        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $parentInput = trim($_POST['parent_id'] ?? '');
        $parentId = ($parentInput !== '' && ctype_digit($parentInput)) ? (int) $parentInput : null;

        if ($code === '' || mb_strlen($code) > 64 || !preg_match('/^[a-z0-9_]+$/', $code)) {
            self::back('blad=kod');
        }
        if ($name === '' || mb_strlen($name) > 128) {
            self::back('blad=nazwa');
        }

        $dictId = null;
        foreach (Dictionary::dictionaries() as $d) {
            if ($d['code'] === 'region') { $dictId = (int) $d['id']; break; }
        }
        if ($dictId === null) {
            self::back('blad=region');
        }

        // Rodzic musi należeć do TEGO słownika — blokada przed spreparowanym
        // POST-em podpinającym region pod pozycję innego słownika (ta sama
        // reguła co w TaxonomyController::create).
        if ($parentId !== null) {
            $parent = Dictionary::findItem($parentId);
            if ($parent === null || $parent['dictionaryId'] !== $dictId) {
                self::back('blad=rodzic');
            }
        }

        $newId = Dictionary::createItem($dictId, $parentId, $code, $name, 100);
        self::back($newId !== null ? 'info=dodano&region=' . $newId : 'blad=duplikat');
    }

    /**
     * Usunięcie obrysu. Pokrycia (region_cells) NIE rusza — o tym, co jest
     * w bazie, decyduje import, a nie ten ekran. Żeby cofnąć pokrycie
     * skasowanego obrysu, trzeba puścić `backfill_regions.php --rebuild`;
     * napisane jest to wprost przy przycisku, bo inaczej „usunąłem, a region
     * dalej jest" byłoby zagadką.
     */
    public static function removeOutline(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
        }
        $item = self::regionItem($_POST['region'] ?? null);
        if ($item === null) {
            self::back('blad=region');
        }
        RegionOutline::remove($item['code']);
        self::back('info=usunieto&region=' . $item['id']);
    }

    // ---------------------------------------------------------------
    // Wspólne
    // ---------------------------------------------------------------

    /**
     * Lista pozycji słownika `region` z kolorem i liczbą heksów pokrycia.
     *
     * Mianownik bierzemy z `region_cell_counts` (migr. 071), a nie
     * z GROUP BY po `region_cells` — tamto jest skanem 1,5 mln wierszy na
     * każde wejście na stronę (ok. 1,5 s zmierzone na dev).
     */
    private static function regionList(): array
    {
        $counts = [];
        foreach (Database::connection()->query('SELECT region_item_id, cells_total FROM region_cell_counts') as $row) {
            $counts[(int) $row['region_item_id']] = (int) $row['cells_total'];
        }
        $geometria = RegionOutline::allRegions();

        $out = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$out, $counts, $geometria): void {
            foreach ($nodes as $node) {
                $code = (string) $node['code'];
                $geo = $geometria[$code] ?? null;
                $out[] = [
                    'id'          => (int) $node['id'],
                    'name'        => $node['name'],
                    'code'        => $code,
                    'depth'       => $depth,
                    'isActive'    => (bool) $node['isActive'],
                    'hasChildren' => !empty($node['children']),
                    'cells'       => $counts[(int) $node['id']] ?? 0,
                    // Trzy stany, nie dwa: bez geometrii · narysowany ręką ·
                    // z importu administracyjnego (podgląd, bez edycji).
                    'geometry'    => $geo === null ? 'brak' : ($geo['editable'] ? 'obrys' : 'import'),
                    'color'       => self::PALETTE[count($out) % count(self::PALETTE)],
                ];
                $walk($node['children'], $depth + 1);
            }
        };
        $walk(Dictionary::tree('region'), 0);
        return $out;
    }

    /** Pozycja słownika `region` po id, albo null. */
    private static function regionItem(mixed $raw): ?array
    {
        if (!ctype_digit((string) $raw)) {
            return null;
        }
        $item = Dictionary::findItem((int) $raw);
        return ($item !== null && $item['dictionaryCode'] === 'region') ? $item : null;
    }

    private static function isLeaf(int $regionItemId): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM dictionary_items WHERE parent_id = ?');
        $stmt->execute([$regionItemId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    private static function error(string $message): void
    {
        http_response_code(400);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }

    private static function back(string $query = ''): never
    {
        header('Location: ' . View::url('/admin/regiony-mapa') . ($query !== '' ? '?' . $query : ''));
        exit;
    }
}
