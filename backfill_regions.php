<?php
// backfill_regions.php
// Wypełnia `region_cells` (migr. 070): przypisuje każdy heks siatki Discovery
// dokładnie jednemu województwu na podstawie granic administracyjnych z
// data/wojewodztwa.geojson (źródło: ppatrzyk/polska-geojson, wersja uproszczona).
//
// Decyzje usera (2026-08-25), które skrypt realizuje:
//   - regiony = województwa, liście pokrywają CAŁĄ Polskę,
//   - jeden heks = jeden region (PK blokuje podwójne przypisanie),
//   - styki siatki NIE mogą zostawiać dziur: „nie musi być odwzorowane
//     idealnie, ważne żeby nie brakło kafli".
//
// TRZY PRZEBIEGI:
//   1. Środek heksu wewnątrz poligonu → przypisanie. To jedyna „prawdziwa"
//      reguła; poligony województw są rozłączne, więc wyłączność jest tu
//      automatyczna.
//   2. Heksy bez przypisania (zaokrąglenia siatki na stykach) dostają test
//      ROGU: wystarczy, że którykolwiek z 7 punktów (środek + wierzchołki)
//      leży w poligonie. Załatwia szczeliny szerokości ułamka heksa bez
//      żadnej pamięciowej magii.
//   3. Co jeszcze zostało MAJĄC przypisanych sąsiadów → dziedziczy region
//      większości (min. 3 z 6 sąsiadów, kilka przebiegów). Konserwatywnie:
//      kafle za granicą (bbox kraju ich nie wyłącza) zwykle mają za mało
//      przypisanych sąsiadów i zostają puste — raport pokazuje, ile ich jest.
//
// Idempotentny: wyłącznie INSERT IGNORE. Ponowne uruchomienie niczego nie
// zdubluje i nie przesunie. --rebuild czyści tabelę i liczy od zera.
//
// UWAGA: zmiana SIZES_M w Utils\DiscoveryGrid unieważnia TĘ tabelę razem
// z discovery_cells — po takiej zmianie: --rebuild tutaj oraz pełny rebuild
// Discovery (patrz ostrzeżenie przy SIZES_M).
//
// ŹRÓDŁA (2026-09-01). Plików może być KILKA i domyślnie są dwa:
//   data/wojewodztwa.geojson — granice województw (ppatrzyk/polska-geojson),
//   data/regiony.geojson     — obrysy narysowane ręcznie w /admin/regiony-mapa
//                              (kraje bez oficjalnego podziału: Czechy, Słowacja).
// Jeden przebieg na wszystkich plikach naraz, a nie osobny na każdy — przebiegi
// 2 i 3 (styki i dziedziczenie) muszą widzieć SĄSIADA ZZA GRANICY, inaczej pas
// wzdłuż granicy PL/CZ zostawałby bez regionu po każdym imporcie z osobna.
//
// Feature wskazuje region KODEM słownika (`properties.code`) albo, dla pliku
// województw, nazwą (`properties.nazwa` + mapowanie \$codeByName niżej).
//
// UŻYCIE:
//   php backfill_regions.php                     — dosypuje brakujące (oba pliki)
//   php backfill_regions.php --rebuild           — czyści i liczy od zera
//   php backfill_regions.php a.geojson b.geojson — wskazane pliki źródłowe
//
// Na produkcji uruchamiać PO run_migrations.php (tabela i słownik muszą istnieć).
//
// APP_ENV=prod WYMUSZONE TUTAJ (2026-09-10, zgłoszenie usera: skrypt łączył
// się jako dev/skrobi na produkcji). CLI nie przechodzi przez Apache, więc
// `SetEnv APP_ENV prod` w .htaccess nic tu nie znaczy — bez tej linii
// `getenv('APP_ENV')` w bootstrap.php widzi pustkę i spada na 'dev'. Ten sam
// wzorzec co w run_migrations.php, który miał to od początku.
putenv('APP_ENV=prod');

require __DIR__ . '/core/bootstrap.php';

use Core\Database;
use Models\Dictionary;
use Models\RegionOutline;
use Utils\DiscoveryGrid;

ini_set('memory_limit', '512M'); // zbiór przypisanych heksów (~550 tys. kluczy) trzymamy w RAM

$rebuild = in_array('--rebuild', $argv, true);
$args = array_values(array_filter($argv, fn(string $a): bool => !str_starts_with($a, '--')));

$files = array_slice($args, 1);
if (!$files) {
    // Domyślnie OBA źródła. Plik ręcznych obrysów jest opcjonalny — środowisko,
    // w którym nikt jeszcze nic nie narysował, ma działać bez zmian.
    $files = [__DIR__ . '/data/wojewodztwa.geojson'];
    if (is_file(__DIR__ . '/data/regiony.geojson')) {
        $files[] = __DIR__ . '/data/regiony.geojson';
    }
}
foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Brak pliku źródłowego: $file\n");
        exit(1);
    }
}

// Nazwy z GeoJSONA → kody słownika (migracja 070). Tablica PRZENIESIONA
// 2026-09-01 do `Models\RegionOutline`, bo czyta ją także narzędzie
// /admin/regiony-mapa (pokazuje województwa na mapie) — dwie kopie rozjechałyby
// się przy pierwszej zmianie nazwy i to CICHO: import przypiąłby heksy gdzie
// indziej niż narzędzie je rysuje.
$codeByName = RegionOutline::VOIVODESHIP_CODES;

$pdo = Database::connection();

if ($rebuild) {
    echo "Czyszczenie region_cells (--rebuild)...\n";
    // TRUNCATE poza transakcją — skrypt konserwacyjny, nie request aplikacji.
    $pdo->exec('TRUNCATE TABLE region_cells');
}

// ---------------------------------------------------------------
// Geometria: FeatureCollection → lista województw z id słownika, ringami
// ([lon, lat]) i prostokątem otaczającym.
// ---------------------------------------------------------------
$regions = [];
foreach ($files as $file) {
$geo = json_decode((string) file_get_contents($file), true);
if (!is_array($geo['features'] ?? null)) {
    fwrite(STDERR, "Nieprawidłowy GeoJSON: $file\n");
    exit(1);
}

foreach ($geo['features'] as $feature) {
    // KOD WPROST wygrywa z nazwą. Pliki rysowane w /admin/regiony-mapa zapisują
    // `code`, bo region jest tam wybierany ze słownika i nie ma powodu, żeby
    // przechodził jeszcze przez tabelę tłumaczeń nazw. Plik województw kodu nie
    // ma i rozwiązuje się jak dotąd — po nazwie.
    $code = (string) ($feature['properties']['code'] ?? '');
    if ($code === '') {
        $name = (string) ($feature['properties']['nazwa'] ?? '');
        if (!isset($codeByName[$name])) {
            fwrite(STDERR, "Nieznany region w GeoJSON ($file): \"$name\" — dodaj go do mapowania \$codeByName.\n");
            exit(1);
        }
        $code = $codeByName[$name];
    }
    $itemId = Dictionary::id('region', $code);
    if ($itemId === null) {
        fwrite(STDERR, "Brak kodu '$code' w słowniku regionów ($file) — dodaj pozycję w /admin/regiony-mapa.\n");
        exit(1);
    }
    if (isset($regions[$code])) {
        fwrite(STDERR, "Kod '$code' występuje w więcej niż jednym pliku źródłowym — usuń duplikat.\n");
        exit(1);
    }

    $geom = $feature['geometry'];
    // Ujednolicamy do listy POLIGONÓW; poligon = [pierścień zewnętrzny, dziury...].
    $polygons = $geom['type'] === 'MultiPolygon'
        ? $geom['coordinates']
        : [$geom['coordinates']];

    $rings = [];
    $bbox = ['w' => INF, 's' => INF, 'e' => -INF, 'n' => -INF];
    foreach ($polygons as $polygon) {
        foreach ($polygon as $ring) {
            $rings[] = $ring;
            foreach ($ring as [$lon, $lat]) {
                $bbox['w'] = min($bbox['w'], $lon); $bbox['s'] = min($bbox['s'], $lat);
                $bbox['e'] = max($bbox['e'], $lon); $bbox['n'] = max($bbox['n'], $lat);
            }
        }
    }
    $regions[$code] = [
        'id' => $itemId,
        'rings' => $rings,
        'bbox' => $bbox,
        // PIERWSZEŃSTWO PRZY SPORNYM HEKSIE (2026-09-01, decyzja usera:
        // „zawsze ma priorytet edytowany"). Obrysy rysowane ręką niosą
        // `priority` = znacznik czasu zapisu; województwa nie mają go wcale
        // i dostają 0. Przebieg 1 idzie od najwyższego priorytetu, a heks
        // przypisany raz już nie zmienia właściciela (`$assigned`) — więc
        // region zapisany JAKO OSTATNI zabiera sporne pola wszystkim
        // wcześniejszym. To jedyne miejsce, w którym ten konflikt się
        // rozstrzyga: cudzego wielokąta nikt tu nie przycina.
        'priority' => (int) ($feature['properties']['priority'] ?? 0),
    ];
}
}

// Sortowanie MALEJĄCO po priorytecie — patrz komentarz przy 'priority' wyżej.
// `uasort`, nie `usort`: klucz tablicy (kod regionu) jest używany w raportach.
uasort($regions, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

echo 'Plików źródłowych: ', count($files), ', regionów: ', count($regions), "\n";

// ---------------------------------------------------------------
// Test punktu w poligonie. SAM RAY CASTING mieszka od 2026-09-01 w
// `RegionOutline::insideRings()` — tę samą granicę sprawdza narzędzie
// /admin/regiony-mapa (raport „wchodzisz na lubelskie"), a dwie kopie
// wzoru dałyby dwie różne odpowiedzi na to samo pytanie.
// Tutaj zostaje wyłącznie odsiew po prostokącie otaczającym: to on robi
// robotę wydajnościową przy 1,5 mln heksów.
//
// Pierścienie GeoJSON-a trzymają [lon, lat] i w tej samej kolejności podajemy
// punkt — `insideRings` jest nieczuła na konwencję, byle była spójna.
// ---------------------------------------------------------------
$inside = function (array $region, float $lon, float $lat): bool {
    if ($lon < $region['bbox']['w'] || $lon > $region['bbox']['e']
        || $lat < $region['bbox']['s'] || $lat > $region['bbox']['n']) {
        return false;
    }
    return RegionOutline::insideRings($region['rings'], $lon, $lat);
};

// Kolejność pierścieni w tym pliku jest niezależna od semantyki dziur —
// dla rozłącznych województw bez enklaw even-odd po SUMIE pierścieni daje
// ten sam wynik co formalny test „w zewnętrznym, poza dziurami".

// ---------------------------------------------------------------
// PRZEBIEG 1 — środek heksu w poligonie.
// Iterujemy PO KAŻDYM województwie we własnym prostokącie: suma prostokątów
// jest tylko nieznacznie większa od kraju, a każdy heks testujemy jednym
// poligonem zamiast szesnastoma. INSERT IGNORE załatwia ewentualne duble na
// stykach prostokątów.
// ---------------------------------------------------------------
$assigned = [];          // cell_id => true — pod przebiegi 2 i 3
$cellRegion = [];        // cell_id => region_item_id — głosy dla dziedziczenia
$total1 = 0;

$flush = function (string $code, array &$rows) use ($pdo): void {
    if (!$rows) { return; }
    $values = rtrim(str_repeat('(?,?),', count($rows)), ',');
    $stmt = $pdo->prepare("INSERT IGNORE INTO region_cells (region_item_id, cell_id) VALUES $values");
    $params = [];
    foreach ($rows as [$itemId, $cellId]) { $params[] = $itemId; $params[] = $cellId; }
    $stmt->execute($params);
    $rows = [];
};

foreach ($regions as $code => $region) {
    $range = DiscoveryGrid::axialRangeForBounds(
        $region['bbox']['s'], $region['bbox']['w'], $region['bbox']['n'], $region['bbox']['e']
    );
    $rows = [];
    $count = 0;
    for ($r = $range['rMin']; $r <= $range['rMax']; $r++) {
        for ($q = $range['qMin']; $q <= $range['qMax']; $q++) {
            $cellId = DiscoveryGrid::encode(DiscoveryGrid::RES_CELL, $q, $r);
            if (isset($assigned[$cellId])) { continue; } // styk prostokątów dwóch województw
            [$lat, $lon] = DiscoveryGrid::cellCenter($cellId);
            if (!$inside($region, $lon, $lat)) { continue; }
            $assigned[$cellId] = true;
            $cellRegion[(int) $cellId] = $region['id'];
            $rows[] = [$region['id'], $cellId];
            if (count($rows) >= 1000) { $flush($code, $rows); }
            $count++;
        }
    }
    $flush($code, $rows);
    $total1 += $count;
    echo '  1. ', str_pad($code, 22), str_pad((string) $count, 8), " heksów\n";
}

// ---------------------------------------------------------------
// PRZEBIEG 2 — styki: środek poza każdym poligonem, ale którykolwiek z
// 7 punktów heksa (środek + wierzchołki) wewnątrz. Bez tego na granicach
// województw zostałyby pojedyncze „niewidzialne" pasy bez regionu.
// ---------------------------------------------------------------
$country = ['w' => INF, 's' => INF, 'e' => -INF, 'n' => -INF];
foreach ($regions as $region) {
    $country['w'] = min($country['w'], $region['bbox']['w']);
    $country['s'] = min($country['s'], $region['bbox']['s']);
    $country['e'] = max($country['e'], $region['bbox']['e']);
    $country['n'] = max($country['n'], $region['bbox']['n']);
}

$size = DiscoveryGrid::sizeM(DiscoveryGrid::RES_CELL);
$range = DiscoveryGrid::axialRangeForBounds($country['s'], $country['w'], $country['n'], $country['e']);
$rows = [];
$count2 = 0;

for ($r = $range['rMin']; $r <= $range['rMax']; $r++) {
    for ($q = $range['qMin']; $q <= $range['qMax']; $q++) {
        $cellId = DiscoveryGrid::encode(DiscoveryGrid::RES_CELL, $q, $r);
        if (isset($assigned[$cellId])) { continue; }

        [$clat, $clon] = DiscoveryGrid::cellCenter($cellId);
        // Siedem punktów próbkowania: środek i wierzchołki (30°, 90°, ...).
        $points = [[$clat, $clon]];
        for ($i = 0; $i < 6; $i++) {
            $angle = deg2rad(60 * $i - 30);
            $points[] = [$clat + $size * sin($angle), $clon + $size * cos($angle)];
            // Uproszczenie świadome: na tej szerokości geograficznej metr
            // Mercatora i metr terenu różnią się o kosinus szerokości,
            // czyli o ~15% w poprzek kraju — przy szczelinach rzędu metrów
            // na styku poligonów to pomijalne, a unika prywatnych funkcji
            // projekcji klasy siatki.
        }

        foreach ($regions as $region) {
            foreach ($points as [$plat, $plon]) {
                if ($inside($region, $plon, $plat)) {
                    $assigned[$cellId] = true;
                    $cellRegion[(int) $cellId] = $region['id'];
                    $rows[] = [$region['id'], $cellId];
                    if (count($rows) >= 1000) { $flush('styki', $rows); }
                    $count2++;
                    continue 3;
                }
            }
        }
    }
}
$flush('styki', $rows);

// ---------------------------------------------------------------
// PRZEBIEG 3 — dziedziczenie od sąsiadów. Kafle, które przeszły i test środka,
// i test rogu, a MAJĄ przypisanych sąsiadów, to wewnętrzne dziury na stykach
// (zaokrąglenia siatki). Dziedziczą region większości sąsiadów — i tylko wtedy,
// gdy assigned są CO NAJMNIEJ TRZY z sześciu: szczelina wewnątrz kraju ma
// zwykle 4–6 przypisanych sąsiadów, natomiast pas za granicą (bbox kraju
// obejmuje skrawki Bałtyku, Niemiec, Czech...) sąsiaduje z frędzlą przebiegu 2
// jednym-dwoma bokami. Heurystyka świadomie konserwatywna: lepiej zostawić
// raportowany kafelek bez regionu, niż przypisać województwo komuś za miedzą.
// ---------------------------------------------------------------
$dirs = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];
$count3 = 0;
for ($sweep = 0; $sweep < 4; $sweep++) {
    $rows = [];
    $filled = 0;
    for ($r = $range['rMin']; $r <= $range['rMax']; $r++) {
        for ($q = $range['qMin']; $q <= $range['qMax']; $q++) {
            $cellId = DiscoveryGrid::encode(DiscoveryGrid::RES_CELL, $q, $r);
            if (isset($assigned[$cellId])) { continue; }

            $votes = [];
            foreach ($dirs as [$dq, $dr]) {
                $nb = DiscoveryGrid::encode(DiscoveryGrid::RES_CELL, $q + $dq, $r + $dr);
                if (isset($assigned[$nb])) {
                    // Region sąsiada czytamy prosto z bazy raz na cały przebieg:
                    // mapa cell_id → region_item_id dla przypisanej części.
                    $votes[$cellRegion[(int) $nb]] = ($votes[$cellRegion[(int) $nb]] ?? 0) + 1;
                }
            }
            if (max($votes ?: [0]) < 3) { continue; }
            arsort($votes);
            $regionId = array_key_first($votes);
            $assigned[$cellId] = true;
            $cellRegion[(int) $cellId] = $regionId;
            $rows[] = [$regionId, $cellId];
            if (count($rows) >= 1000) { $flush('dziedziczenie', $rows); }
            $filled++;
        }
    }
    $flush('dziedziczenie', $rows);
    $count3 += $filled;
    if (!$filled) { break; }
}

// Ile kafli z przypisanym sąsiadem NADAL zostało pustych — czyli realnych
// „dziur" w pokryciu. Reszta pustych w kadrze to teren poza Polską i jej
// nie liczy.
$holes = 0;
for ($r = $range['rMin']; $r <= $range['rMax']; $r++) {
    for ($q = $range['qMin']; $q <= $range['qMax']; $q++) {
        $cellId = (int) DiscoveryGrid::encode(DiscoveryGrid::RES_CELL, $q, $r);
        if (isset($assigned[$cellId])) { continue; }
        foreach ($dirs as [$dq, $dr]) {
            if (isset($assigned[(int) DiscoveryGrid::encode(DiscoveryGrid::RES_CELL, $q + $dq, $r + $dr)])) {
                $holes++;
                break;
            }
        }
    }
}

// ---------------------------------------------------------------
// RAPORT
// ---------------------------------------------------------------
echo "\nPrzypisania: ", number_format($total1, 0, ',', ' '),
     ' (środek w poligonie) + ', number_format($count2, 0, ',', ' '), ' (styki)',
     ($count3 ? ' + ' . number_format($count3, 0, ',', ' ') . ' (dziedziczenie)' : ''),
     "\n";
if ($holes) {
    echo "UWAGA: $holes kafli z przypisanym sąsiadem pozostało bez regionu — dziury w pokryciu.\n";
} else {
    echo "Dziury w pokryciu (kafelek obok przypisanego bez regionu): brak.\n";
}

$dbTotal = (int) $pdo->query('SELECT COUNT(*) FROM region_cells')->fetchColumn();
echo 'region_cells po imporcie: ', number_format($dbTotal, 0, ',', ' '), " wierszy\n";

// MATERIALIZOWANY MIANOWNIK (migr. 071). Backfill jest JEDYNYM pisarzem tej
// tabeli — mianownik zmienia się tylko przy imporcie geometrii, a liczenie
// go w locie kosztowało ~1,5 s skanu na każde wejście na stronę.
$refreshed = $pdo->exec('
    INSERT INTO region_cell_counts (region_item_id, cells_total)
    SELECT region_item_id, COUNT(*) FROM region_cells GROUP BY region_item_id
    ON DUPLICATE KEY UPDATE cells_total = VALUES(cells_total)
');
echo 'region_cell_counts odświeżone: ', $refreshed, " regionów\n";

echo "\nNa województwo:\n";
foreach ($pdo->query('
    SELECT di.code, COUNT(*) AS cells
      FROM region_cells rc JOIN dictionary_items di ON di.id = rc.region_item_id
     GROUP BY di.code ORDER BY cells DESC
') as $row) {
    echo '  ', str_pad($row['code'], 22), number_format((int) $row['cells'], 0, ',', ' '), "\n";
}

// Referencje, których remap z migracji 070 nie objął (kody spoza piątki
// znanych pasm): wyświetlą się dalej ze swoją starą nazwą, ale nie liczą się
// w liściach. Decyzja o nich należy do człowieka — stąd raport, nie milczenie.
// NAPRAWA 2026-09-01: dwa pierwsze warunki pytały o `events.region_item_id`
// i `known_routes.region_item_id` — kolumny USUNIĘTE migracją 075 (region stał
// się zbiorem: `event_regions` / `known_route_regions`). Zapytanie wywalało się
// więc na „Unknown column" i przewracało CAŁY skrypt na ostatnim kroku —
// po zapisaniu pokrycia, ale przed raportem, przez co import wyglądał na
// nieudany, mimo że dane były już w bazie.
$leftovers = $pdo->query('
    SELECT "events" AS tbl, COUNT(DISTINCT er.event_id) AS n FROM event_regions er
      JOIN dictionary_items di ON di.id = er.region_item_id WHERE di.is_active = 0
    UNION ALL SELECT "known_routes", COUNT(DISTINCT krr.route_id) FROM known_route_regions krr
      JOIN dictionary_items di ON di.id = krr.region_item_id WHERE di.is_active = 0
    UNION ALL SELECT "treasures", COUNT(*) FROM treasures t
      JOIN dictionary_items di ON di.id = t.region_item_id WHERE di.is_active = 0
    UNION ALL SELECT "organizer_profiles", COUNT(*) FROM organizer_profiles op
      JOIN dictionary_items di ON di.id = op.region_item_id WHERE di.is_active = 0
')->fetchAll();
$n = array_sum(array_column($leftovers, 'n'));
if ($n) {
    echo "\nUWAGA: $n referencji wskazuje na dezaktywowane regiony (kody spoza mapowania migracji 070):\n";
    foreach ($leftovers as $l) {
        if ((int) $l['n']) { echo "  {$l['tbl']}: {$l['n']}\n"; }
    }
} else {
    echo "\nReferencje do dezaktywowanych regionów: brak.\n";
}
