<?php
// core/Resources/StageResource.php
namespace Resources;

use Models\EventStage;
use Utils\Gpx;

class StageResource
{
    public static function fromModel(EventStage $stage): array
    {
        $profile = $stage->elevationProfile;
        return [
            'dayNumber'      => $stage->dayNumber,
            'date'           => $stage->stageDate,
            'title'          => $stage->title,
            'startPoint'     => $stage->startPoint,
            'endPoint'       => $stage->endPoint,
            'distanceKm'     => $stage->distanceKm,
            'elevationGainM' => $stage->elevationGainM,
            'gpxUrl'         => $stage->gpxUrl,
            'notes'          => $stage->notes,
            'surfaceLabel'   => $stage->surfaceLabel,
            'accommodationLabel'   => $stage->accommodationLabel,
            'accommodationAddress' => $stage->accommodationAddress,
            'includedMealLabels'   => $stage->includedMealLabels,
            // null gdy etap nie ma wykrytego podziału (brak GPX albo detekcja
            // Overpass się nie powiodła) — front wtedy pokazuje surfaceLabel
            // (ręczny wybór ze słownika) zamiast paska km/%.
            'surfaceBreakdown' => self::surfaceBreakdown($stage),
            'elevationProfile' => $profile,
            // Wysokość startu i wykryte szczyty — liczone z profilu na
            // żądanie, nie trzymane osobno w bazie (elevation_profile to
            // jedyne źródło prawdy, mniej do synchronizowania przy edycji).
            'startElevationM'  => !empty($profile) ? $profile[0]['e'] : null,
            'peaks'            => !empty($profile) ? Gpx::detectPeaks($profile) : [],
        ];
    }

    private static function surfaceBreakdown(EventStage $stage): ?array
    {
        if ($stage->surfaceAsphaltPct === null) {
            return null;
        }
        $km = fn(int $pct) => round($stage->distanceKm * $pct / 100, 1);
        return [
            'asphaltPct' => $stage->surfaceAsphaltPct,
            'gravelPct'  => $stage->surfaceGravelPct,
            'trailPct'   => $stage->surfaceTrailPct,
            'asphaltKm'  => $km($stage->surfaceAsphaltPct),
            'gravelKm'   => $km($stage->surfaceGravelPct),
            'trailKm'    => $km($stage->surfaceTrailPct),
        ];
    }
}
