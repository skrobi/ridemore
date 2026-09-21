<?php
// core/Utils/RouteCells.php
namespace Utils;

// Etap 2 (dopasowania) — dzieli ślad trasy na komórki siatki o boku ~200m,
// pod wykrywanie nakładania się tras dwóch wydarzeń (patrz Models\MatchEngine).
// Uzasadnienie rozmiaru komórki (z docs/etap2): 50m daje mnóstwo fałszywych
// negatywów przy zwykłym szumie GPS/odchyleniu śladu na tej samej drodze;
// 800m zlewa różne drogi leżące w tej samej dolinie w jedną komórkę.
class RouteCells
{
    private const CELL_SIZE_M = 200.0;

    // Stała szerokość referencyjna zamiast lokalnej (per-punkt) korekcji
    // cos(lat) — gdyby każdy punkt liczył swoją własną szerokość metra
    // długości geograficznej, siatka przestałaby się pokrywać między
    // trasami leżącymi na nieco innych szerokościach (np. Bieszczady vs
    // Mazury), co jest dokładnie tym, czego siatka ma unikać. 52°N to
    // środek Polski (jedyny kraj w słowniku region na dziś) — błąd
    // wynikający z tego uproszczenia jest znikomy w granicach kraju
    // (Polska: 49–54.8°N, rozstęp cos() rzędu kilku procent).
    private const REF_LAT_DEG = 52.0;
    private const METERS_PER_DEG_LAT = 111320.0;

    private static function metersPerDegLon(): float
    {
        return self::METERS_PER_DEG_LAT * cos(deg2rad(self::REF_LAT_DEG));
    }

    // Pojedynczy punkt -> [cell_x, cell_y]. Publiczne, bo Models\MatchEngine
    // potrzebuje tej samej funkcji do zamiany punktu startu na komórkę przy
    // liczeniu bliskości (nie tylko całych tras).
    public static function pointToCell(float $lat, float $lon): array
    {
        $x = (int) floor($lon * self::metersPerDegLon() / self::CELL_SIZE_M);
        $y = (int) floor($lat * self::METERS_PER_DEG_LAT / self::CELL_SIZE_M);
        return [$x, $y];
    }

    // event_stages.elevation_profile (patrz Utils\Gpx::sampleProfile) ma co
    // najwyżej 50 próbek na cały etap — przy 83 km trasie to ok. 1,66 km
    // między próbkami, więc wprost zamienione na komórki 200m ominęłyby
    // większość terenu między nimi. Interpolujemy liniowo (lat/lon) wzdłuż
    // odcinka między każdą kolejną parą próbek, w krokach nie większych niż
    // rozmiar komórki — dla odcinka prostego (a na tej skali odległości
    // każdy odcinek trasy rowerowej jest z grubsza prosty) to wystarczające
    // przybliżenie rzeczywistego śladu.
    //
    // Zwraca listę unikalnych par [cell_x, cell_y]. Pusta tablica, jeśli
    // profil nie ma lat/lon (stare wiersze sprzed backfill_elevation_profiles.php
    // — patrz core/Utils/Gpx.php) albo ma mniej niż 2 punkty.
    public static function fromElevationProfile(?array $profile): array
    {
        if ($profile === null || count($profile) < 2 || !isset($profile[0]['lat'], $profile[0]['lon'])) {
            return [];
        }

        $cells = [];
        for ($i = 1; $i < count($profile); $i++) {
            $prev = $profile[$i - 1];
            $curr = $profile[$i];
            if (!isset($prev['lat'], $prev['lon'], $curr['lat'], $curr['lon'])) {
                continue;
            }

            $segmentKm = Gpx::haversineKm((float) $prev['lat'], (float) $prev['lon'], (float) $curr['lat'], (float) $curr['lon']);
            $steps = max(1, (int) ceil(($segmentKm * 1000) / self::CELL_SIZE_M));

            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / $steps;
                $lat = $prev['lat'] + ($curr['lat'] - $prev['lat']) * $t;
                $lon = $prev['lon'] + ($curr['lon'] - $prev['lon']) * $t;
                [$x, $y] = self::pointToCell((float) $lat, (float) $lon);
                $cells[$x . ':' . $y] = [$x, $y];
            }
        }

        return array_values($cells);
    }
}
