<?php
// core/Models/EventStage.php
namespace Models;

use Core\Database;

class EventStage
{
    public int $id;
    public int $dayNumber;
    public ?string $stageDate;
    public ?string $title;
    public ?string $startPoint;
    public ?string $endPoint;
    public float $distanceKm;
    public int $elevationGainM;
    public ?string $gpxUrl;
    public ?string $notes;
    public ?string $surfaceLabel;
    public ?int $surfaceAsphaltPct;
    public ?int $surfaceGravelPct;
    public ?int $surfaceTrailPct;
    public ?array $elevationProfile;
    // Nocleg/posiłki — zapisywane od dawna przez replaceForEvent() niżej, ale
    // dotąd nigdy nie odczytywane (żadna strona ich nie pokazywała, patrz
    // szablony/wydarzenie.html "Plan wyjazdu"/.stay). accommodationLabel łączy
    // typ (dict: accommodation_type) z opcjonalną nazwą miejsca — "Schronisko
    // (Górska Chata)" albo sam typ, gdy nazwy nie podano.
    public ?string $accommodationLabel;
    public ?string $accommodationAddress;
    /** @var string[] */
    public array $includedMealLabels;

    public static function findByEventId(int $eventId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT es.id, es.day_number, es.stage_date, es.title, es.start_point, es.end_point,
                   es.distance_km, es.elevation_gain_m, es.gpx_url, es.notes, es.elevation_profile,
                   es.surface_asphalt_pct, es.surface_gravel_pct, es.surface_trail_pct,
                   surf.name AS surface_label,
                   acc.name AS accommodation_name, acc.address AS accommodation_address,
                   acct.name AS accommodation_type_name,
                   (SELECT GROUP_CONCAT(mt.name SEPARATOR ', ') FROM event_stage_meals esm
                       JOIN dictionary_items mt ON mt.id = esm.meal_type_item_id
                      WHERE esm.stage_id = es.id AND esm.is_included = 1) AS included_meals
            FROM event_stages es
            LEFT JOIN dictionary_items surf ON surf.id = es.surface_item_id
            LEFT JOIN event_stage_accommodations acc ON acc.stage_id = es.id
            LEFT JOIN dictionary_items acct ON acct.id = acc.accommodation_type_item_id
            WHERE es.event_id = :event_id ORDER BY es.day_number ASC
        ");
        $stmt->execute(['event_id' => $eventId]);

        return array_map(function (array $row) {
            $s = new self();
            $s->id = (int) $row['id'];
            $s->dayNumber = (int) $row['day_number'];
            $s->stageDate = $row['stage_date'];
            $s->title = $row['title'];
            $s->startPoint = $row['start_point'];
            $s->endPoint = $row['end_point'];
            $s->distanceKm = (float) $row['distance_km'];
            $s->elevationGainM = (int) $row['elevation_gain_m'];
            $s->gpxUrl = $row['gpx_url'];
            $s->notes = $row['notes'];
            $s->surfaceLabel = $row['surface_label'];
            $s->surfaceAsphaltPct = $row['surface_asphalt_pct'] !== null ? (int) $row['surface_asphalt_pct'] : null;
            $s->surfaceGravelPct = $row['surface_gravel_pct'] !== null ? (int) $row['surface_gravel_pct'] : null;
            $s->surfaceTrailPct = $row['surface_trail_pct'] !== null ? (int) $row['surface_trail_pct'] : null;
            $s->elevationProfile = $row['elevation_profile'] !== null ? json_decode($row['elevation_profile'], true) : null;
            $s->accommodationLabel = $row['accommodation_type_name'] !== null
                ? ($row['accommodation_name'] ? $row['accommodation_type_name'] . ' (' . $row['accommodation_name'] . ')' : $row['accommodation_type_name'])
                : null;
            $s->accommodationAddress = $row['accommodation_address'];
            $s->includedMealLabels = $row['included_meals'] ? explode(', ', $row['included_meals']) : [];
            return $s;
        }, $stmt->fetchAll());
    }

    // Usuwa wszystkie etapy eventu (kaskadowo kasuje ich nocleg/posiłki) i wstawia
    // od nowa z formularza — prostsza i równie poprawna strategia niż diffing,
    // wywoływane wewnątrz transakcji przez Event::save().
    //
    // $stages: [['date','title','startLabel','startLat','startLng','endLabel',
    //   'endLat','endLng','distanceKm','elevationM','surface' (kod dict),
    //   'gpxUrl','accommodationType' (kod dict|null),'accommodationName',
    //   'accommodationAddress','meals' (kody dict meal_type, uznane za w cenie),
    //   'notes'], ...]
    public static function replaceForEvent(int $eventId, array $stages): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM event_stages WHERE event_id = :event_id')->execute(['event_id' => $eventId]);

        $stageStmt = $pdo->prepare('
            INSERT INTO event_stages
              (event_id, day_number, stage_date, title, start_point, start_lat, start_lng,
               end_point, end_lat, end_lng, distance_km, elevation_gain_m, surface_item_id,
               surface_asphalt_pct, surface_gravel_pct, surface_trail_pct, gpx_url, notes, elevation_profile)
            VALUES
              (:event_id, :day_number, :stage_date, :title, :start_point, :start_lat, :start_lng,
               :end_point, :end_lat, :end_lng, :distance_km, :elevation_gain_m, :surface_item_id,
               :surface_asphalt_pct, :surface_gravel_pct, :surface_trail_pct, :gpx_url, :notes, :elevation_profile)
        ');
        $accommodationStmt = $pdo->prepare('
            INSERT INTO event_stage_accommodations (stage_id, accommodation_type_item_id, name, address)
            VALUES (:stage_id, :type_id, :name, :address)
        ');
        $mealStmt = $pdo->prepare('
            INSERT INTO event_stage_meals (stage_id, meal_type_item_id, is_included)
            VALUES (:stage_id, :meal_id, 1)
        ');
        $cellStmt = $pdo->prepare('
            INSERT INTO event_stage_cells (event_stage_id, cell_x, cell_y) VALUES (:stage_id, :x, :y)
        ');

        foreach (array_values($stages) as $i => $stage) {
            $stageStmt->execute([
                'event_id'         => $eventId,
                'day_number'       => $i + 1,
                'stage_date'       => ($stage['date'] ?? null) ?: null,
                'title'            => ($stage['title'] ?? null) ?: null,
                'start_point'      => ($stage['startLabel'] ?? null) ?: null,
                'start_lat'        => $stage['startLat'] ?? null,
                'start_lng'        => $stage['startLng'] ?? null,
                'end_point'        => ($stage['endLabel'] ?? null) ?: null,
                'end_lat'          => $stage['endLat'] ?? null,
                'end_lng'          => $stage['endLng'] ?? null,
                'distance_km'      => ($stage['distanceKm'] ?? null) ?: 0,
                'elevation_gain_m' => ($stage['elevationM'] ?? null) ?: 0,
                'surface_item_id'  => Dictionary::id('surface_type', $stage['surface'] ?? null),
                'surface_asphalt_pct' => $stage['surfaceAsphaltPct'] ?? null,
                'surface_gravel_pct'  => $stage['surfaceGravelPct'] ?? null,
                'surface_trail_pct'   => $stage['surfaceTrailPct'] ?? null,
                'gpx_url'          => ($stage['gpxUrl'] ?? null) ?: null,
                'notes'            => ($stage['notes'] ?? null) ?: null,
                'elevation_profile' => !empty($stage['elevationProfile']) ? json_encode($stage['elevationProfile']) : null,
            ]);
            $stageId = (int) $pdo->lastInsertId();

            $accommodationTypeId = Dictionary::id('accommodation_type', $stage['accommodationType'] ?? null);
            if ($accommodationTypeId !== null) {
                $accommodationStmt->execute([
                    'stage_id' => $stageId,
                    'type_id'  => $accommodationTypeId,
                    'name'     => ($stage['accommodationName'] ?? null) ?: null,
                    'address'  => ($stage['accommodationAddress'] ?? null) ?: null,
                ]);
            }

            foreach ($stage['meals'] ?? [] as $mealCode) {
                $mealId = Dictionary::id('meal_type', $mealCode);
                if ($mealId !== null) {
                    $mealStmt->execute(['stage_id' => $stageId, 'meal_id' => $mealId]);
                }
            }

            // Etap 2 (dopasowania): komórki trasy pod wykrywanie nakładania
            // się śladów (patrz Utils\RouteCells, Models\MatchEngine). Brak
            // elevationProfile lub brak lat/lon w profilu (stary GPX sprzed
            // backfill_elevation_profiles.php) po prostu nie daje komórek —
            // to nie błąd, dopasowanie działa wtedy na pozostałych osiach.
            if (!empty($stage['elevationProfile'])) {
                foreach (\Utils\RouteCells::fromElevationProfile($stage['elevationProfile']) as [$x, $y]) {
                    $cellStmt->execute(['stage_id' => $stageId, 'x' => $x, 'y' => $y]);
                }
            }
        }
    }
}
