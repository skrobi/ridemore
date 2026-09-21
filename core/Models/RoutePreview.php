<?php
// core/Models/RoutePreview.php
// „CO MI TO DA" — ile NOWYCH pól i punktów wniosłaby dana trasa dla konkretnej
// osoby (2026-08-14, prośba usera: „może się okazać, że podobną trasę już
// odkrywałem i niewiele to wniesie do mojej jazdy").
//
// TO JEST SZACUNEK I MUSI BYĆ TAK NAZWANY W INTERFEJSIE. Liczy się z trasy
// PLANOWANEJ, a odkrycia naliczają się wyłącznie ze śladu z ODBYTEGO wyjazdu
// (migr. 042). Kto skróci trasę albo pojedzie inaczej, dostanie mniej; kto
// dojedzie na zbiórkę własnym dojazdem, dostanie więcej. Zapowiedź jest tu
// jednak jedyną informacją, jaką w ogóle mamy PRZED wyjazdem — a pytanie
// „czy warto tam jechać" pada właśnie wtedy.
namespace Models;

use Core\Database;
use Utils\DiscoveryGrid;
use Utils\Gpx;

class RoutePreview
{
    /**
     * Pola siatki odkryć dla pliku GPX — z cache'u (migr. 050), liczone raz.
     *
     * Klucz to hash ZAWARTOŚCI pliku, więc ten sam ślad podpięty w kilku
     * miejscach (etap, wariant, znana trasa) liczy się jeden raz dla wszystkich.
     *
     * @return int[] identyfikatory pól; pusta tablica dla pliku nieczytelnego
     */
    public static function cellsForGpx(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            return [];
        }
        $hash = hash_file('sha256', $absolutePath);
        if ($hash === false) {
            return [];
        }

        $db = Database::connection();

        // Znacznik przebiegu sprawdzamy PRZED polami: plik, którego ślad nie
        // dotknął ani jednego pola (uszkodzony, za krótki), nie ma wierszy
        // w gpx_route_cells i bez tego byłby parsowany przy każdym wejściu.
        $stmt = $db->prepare('SELECT 1 FROM gpx_route_cell_runs WHERE gpx_hash = :h');
        $stmt->execute(['h' => $hash]);
        if ($stmt->fetchColumn() !== false) {
            $stmt = $db->prepare('SELECT cell_id FROM gpx_route_cells WHERE gpx_hash = :h');
            $stmt->execute(['h' => $hash]);
            return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        try {
            // Próg długiej trasy — tak jak w GpxGeometry::ensure(): plik jest już
            // na dysku, a niepowodzenie zapisuje się jako "policzone na zero".
            $cells = DiscoveryGrid::cellsForTrack(Gpx::parse($absolutePath, Gpx::LONG_ROUTE_MAX_DISTANCE_KM)['points']);
        } catch (\Throwable $e) {
            // Nieczytelny plik zapisujemy jako policzony-na-zero, żeby nie
            // próbować go parsować przy każdym kolejnym wyświetleniu strony.
            $cells = [];
        }

        foreach (array_chunk($cells, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $cellId) {
                $values[] = '(' . $db->quote($hash) . ',' . (int) $cellId . ')';
            }
            $db->exec('INSERT IGNORE INTO gpx_route_cells (gpx_hash, cell_id) VALUES ' . implode(',', $values));
        }
        $db->prepare('INSERT IGNORE INTO gpx_route_cell_runs (gpx_hash, cells_count) VALUES (:h, :n)')
            ->execute(['h' => $hash, 'n' => count($cells)]);

        return $cells;
    }

    /**
     * Szacunek dla JEDNEJ trasy i JEDNEJ osoby.
     *
     * @param int[] $cells       pola trasy (z cellsForGpx)
     * @param float $distanceKm  dystans trasy — do punktów za sam przejazd
     * @param int   $elevationM  przewyższenie — j.w.
     *
     * @return array{cells:int, newCells:int, knownCells:int, points:int,
     *               ridePoints:int, discoveryPoints:int, explorationPoints:int, freshPct:int}
     */
    public static function forUser(int $userId, array $cells, float $distanceKm, int $elevationM): array
    {
        $total = count($cells);
        $ridePoints = DiscoveryScoring::forRide($distanceKm, $elevationM);

        if ($total === 0) {
            return [
                'cells' => 0, 'newCells' => 0, 'knownCells' => 0,
                'points' => $ridePoints, 'ridePoints' => $ridePoints,
                'discoveryPoints' => 0, 'explorationPoints' => 0, 'freshPct' => 0,
            ];
        }

        $db = Database::connection();
        $list = implode(',', array_map('intval', $cells));

        // Pola, które ta osoba JUŻ MA — reszta jest dla niej nowa.
        $stmt = $db->prepare(
            'SELECT cell_id FROM discovery_cells WHERE user_id = :uid AND cell_id IN (' . $list . ')'
        );
        $stmt->execute(['uid' => $userId]);
        $mine = array_flip(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));

        $newCells = [];
        foreach ($cells as $cellId) {
            if (!isset($mine[(int) $cellId])) {
                $newCells[] = (int) $cellId;
            }
        }

        // Ilu rowerzystów ma te pola DZIŚ — stąd wiadomo, które byłyby nowym
        // terenem dla całej społeczności, a które tylko dla tej osoby. Pole
        // bez wiersza w agregacie nie ma nikogo, więc domyślnie zero.
        $ridersBefore = [];
        if ($newCells) {
            $newList = implode(',', $newCells);
            $rows = $db->query(
                'SELECT cell_id, riders_count FROM discovery_cell_totals WHERE cell_id IN (' . $newList . ')'
            )->fetchAll();
            $byCell = [];
            foreach ($rows as $r) {
                $byCell[(int) $r['cell_id']] = (int) $r['riders_count'];
            }
            foreach ($newCells as $cellId) {
                $ridersBefore[] = $byCell[$cellId] ?? 0;
            }
        }

        // Ta sama metoda, która nalicza naprawdę — szacunek nie ma prawa
        // rozjechać się z tym, co człowiek dostanie po wyjeździe.
        $scored = DiscoveryScoring::forDiscoveries($ridersBefore);

        return [
            'cells'             => $total,
            'newCells'          => count($newCells),
            'knownCells'        => $total - count($newCells),
            'ridePoints'        => $ridePoints,
            'discoveryPoints'   => $scored['discovery'],
            'explorationPoints' => $scored['exploration'],
            'points'            => $ridePoints + $scored['discovery'] + $scored['exploration'],
            'freshPct'          => (int) round(100 * count($newCells) / $total),
        ];
    }
}
