<?php
// core/Models/EventRouteVariant.php
namespace Models;

use Core\Database;

// Wariant trasy: jeden (jednodniowy) event może mieć kilka alternatywnych tras
// ("pętli", np. 300/200/100 km), każda z własnym GPX, ceną i limitem miejsc.
// Uczestnik wybiera jeden wariant przy zapisie (event_rsvps.variant_id).
// Warstwa opcjonalna — event bez wariantów działa jak dotąd. Kształt trasy 1:1
// jak w Models\EventStage (ta sama obsługa GPX/nawierzchni/profilu). Patrz
// migration_031_route_variants.sql.
class EventRouteVariant
{
    public int $id;
    public string $name;
    public int $sortOrder;
    public float $distanceKm;
    public int $elevationGainM;
    public ?string $gpxUrl;
    public ?string $surfaceLabel;
    public ?int $surfaceAsphaltPct;
    public ?int $surfaceGravelPct;
    public ?int $surfaceTrailPct;
    public ?array $elevationProfile;
    public ?float $priceAmount;
    public ?float $depositAmount;
    public ?int $maxParticipants;

    public static function findByEventId(int $eventId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT v.id, v.name, v.sort_order, v.distance_km, v.elevation_gain_m, v.gpx_url,
                   v.elevation_profile, v.surface_asphalt_pct, v.surface_gravel_pct, v.surface_trail_pct,
                   v.price_amount, v.deposit_amount, v.max_participants,
                   surf.name AS surface_label
            FROM event_route_variants v
            LEFT JOIN dictionary_items surf ON surf.id = v.surface_item_id
            WHERE v.event_id = :event_id
            ORDER BY v.sort_order ASC, v.id ASC
        ");
        $stmt->execute(['event_id' => $eventId]);

        return array_map(function (array $row) {
            $v = new self();
            $v->id = (int) $row['id'];
            $v->name = $row['name'];
            $v->sortOrder = (int) $row['sort_order'];
            $v->distanceKm = (float) $row['distance_km'];
            $v->elevationGainM = (int) $row['elevation_gain_m'];
            $v->gpxUrl = $row['gpx_url'];
            $v->surfaceLabel = $row['surface_label'];
            $v->surfaceAsphaltPct = $row['surface_asphalt_pct'] !== null ? (int) $row['surface_asphalt_pct'] : null;
            $v->surfaceGravelPct = $row['surface_gravel_pct'] !== null ? (int) $row['surface_gravel_pct'] : null;
            $v->surfaceTrailPct = $row['surface_trail_pct'] !== null ? (int) $row['surface_trail_pct'] : null;
            $v->elevationProfile = $row['elevation_profile'] !== null ? json_decode($row['elevation_profile'], true) : null;
            $v->priceAmount = $row['price_amount'] !== null ? (float) $row['price_amount'] : null;
            $v->depositAmount = $row['deposit_amount'] !== null ? (float) $row['deposit_amount'] : null;
            $v->maxParticipants = $row['max_participants'] !== null ? (int) $row['max_participants'] : null;
            return $v;
        }, $stmt->fetchAll());
    }

    // Usuwa wszystkie warianty eventu i wstawia od nowa z formularza — ta sama
    // strategia (delete-then-insert w transakcji Event::save) co EventStage.
    // ON DELETE SET NULL na event_rsvps.variant_id sprawia, że przy edycji stare
    // zapisy nie znikają — tracą tylko powiązanie z konkretną (usuniętą) pętlą.
    //
    // $variants: [['name','distanceKm','elevationM','surface' (kod dict|null),
    //   'surfaceAsphaltPct','surfaceGravelPct','surfaceTrailPct','gpxUrl',
    //   'elevationProfile','priceAmount' (float|null),'depositAmount' (float|null),
    //   'maxParticipants' (int|null)], ...]
    public static function replaceForEvent(int $eventId, array $variants): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM event_route_variants WHERE event_id = :event_id')->execute(['event_id' => $eventId]);

        $stmt = $pdo->prepare('
            INSERT INTO event_route_variants
              (event_id, name, sort_order, distance_km, elevation_gain_m, surface_item_id,
               surface_asphalt_pct, surface_gravel_pct, surface_trail_pct, gpx_url, elevation_profile,
               price_amount, deposit_amount, max_participants)
            VALUES
              (:event_id, :name, :sort_order, :distance_km, :elevation_gain_m, :surface_item_id,
               :surface_asphalt_pct, :surface_gravel_pct, :surface_trail_pct, :gpx_url, :elevation_profile,
               :price_amount, :deposit_amount, :max_participants)
        ');

        foreach (array_values($variants) as $i => $variant) {
            $name = trim($variant['name'] ?? '');
            if ($name === '') continue; // pusty wariant (organizator dodał wiersz i nie wypełnił) — pomijamy
            $stmt->execute([
                'event_id'            => $eventId,
                'name'                => $name,
                'sort_order'          => $i,
                'distance_km'         => ($variant['distanceKm'] ?? null) ?: 0,
                'elevation_gain_m'    => ($variant['elevationM'] ?? null) ?: 0,
                'surface_item_id'     => Dictionary::id('surface_type', $variant['surface'] ?? null),
                'surface_asphalt_pct' => $variant['surfaceAsphaltPct'] ?? null,
                'surface_gravel_pct'  => $variant['surfaceGravelPct'] ?? null,
                'surface_trail_pct'   => $variant['surfaceTrailPct'] ?? null,
                'gpx_url'             => ($variant['gpxUrl'] ?? null) ?: null,
                'elevation_profile'   => !empty($variant['elevationProfile']) ? json_encode($variant['elevationProfile']) : null,
                'price_amount'        => isset($variant['priceAmount']) && $variant['priceAmount'] !== null ? (float) $variant['priceAmount'] : null,
                'deposit_amount'      => isset($variant['depositAmount']) && $variant['depositAmount'] !== null ? (float) $variant['depositAmount'] : null,
                'max_participants'    => isset($variant['maxParticipants']) && $variant['maxParticipants'] !== null ? (int) $variant['maxParticipants'] : null,
            ]);
        }
    }

    // Ilu potwierdzonych uczestników zajmuje miejsce w danym wariancie danego
    // turnusu — pod limit per wariant (max_participants). Ten sam status
    // ('potwierdzony') co liczenie zapełnienia turnusu (Event::confirmedCountByEditionSql).
    public static function confirmedCount(int $editionId, int $variantId): int
    {
        $stmt = Database::connection()->prepare("
            SELECT COUNT(*) FROM event_rsvps r
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id
            WHERE r.edition_id = :edition_id AND r.variant_id = :variant_id AND rdi.code = 'potwierdzony'
        ");
        $stmt->execute(['edition_id' => $editionId, 'variant_id' => $variantId]);
        return (int) $stmt->fetchColumn();
    }
}
