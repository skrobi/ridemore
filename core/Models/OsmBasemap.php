<?php
// core/Models/OsmBasemap.php
// MAPA BAZOWA OSM NA DYSKU — jedyne miejsce w serwisie, które samo (z serwera,
// nie z przeglądarki) ściąga cudzy kafel mapy. Pod złożony obrazek "Trasa
// dnia" na stronie głównej (Controllers\TileController::routeOfDayMap) —
// decyzja usera 2026-09-05: "Statyczny, składany obrazek z prawdziwych
// kafli", żeby strona główna nie ciągnęła Leafletu/JS-a tylko dla jednej
// dekoracyjnej karty. KAŻDA INNA mapa serwisu (ridemoreDiscoveryMap) ładuje
// ten sam basemap BEZPOŚREDNIO w przeglądarce — to nie jest nowy sposób
// pokazywania mapy, tylko jednorazowe spłaszczenie jej do PNG po stronie
// serwera.
//
// POLITYKA UŻYCIA KAFLI OSM (Tile Usage Policy): własny, opisowy User-Agent
// zamiast domyślnego klienta HTTP, rotacja poddomen a/b/c, i — najważniejsze —
// TRZYMANIE KAFLA NA DYSKU zamiast pobierania go przy każdym wejściu. Ruch
// jest i tak znikomy (jeden obrazek dziennie na wyróżnioną trasę/wydarzenie),
// ale znane trasy WRACAJĄ w rotacji KnownRoute::routeOfDay(), więc te same
// kafle bazowe przydadzą się ponownie za kilka-kilkanaście dni — cache
// oszczędza wtedy realny ruch do OSM, nie tylko czas odpowiedzi.
namespace Models;

class OsmBasemap
{
    private const SUBDOMAINS = ['a', 'b', 'c'];
    private const TIMEOUT_S = 5;
    private const USER_AGENT = 'ridemore.bike RouteOfDayMap/1.0 (+https://ridemore.bike)';

    /** Katalog na dysku — publiczny jak reszta assets/tiles, bo to i tak dane publiczne. */
    private const DIR = 'assets/tiles/osm';

    /**
     * Bajty PNG kafla bazowego (z dysku, a przy pierwszym użyciu — z sieci),
     * albo null, gdy się nie udało (brak sieci, OSM nie odpowiedział) — kafel
     * bazowy jest wtedy po prostu pusty w złożonym obrazie, nie błędem.
     */
    public static function tile(int $z, int $x, int $y): ?string
    {
        $path = self::root() . "/$z/$x/$y.png";
        if (is_file($path)) {
            $bytes = @file_get_contents($path);
            return $bytes !== false ? $bytes : null;
        }

        $sub = self::SUBDOMAINS[($x + $y) % count(self::SUBDOMAINS)];
        $ch = curl_init("https://$sub.tile.openstreetmap.org/$z/$x/$y.png");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::USER_AGENT],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $code !== 200) {
            return null;
        }

        self::store($path, $body);
        return $body;
    }

    private static function root(): string
    {
        return dirname(CORE_PATH) . '/' . self::DIR;
    }

    /** Zapis atomowy — ten sam wzorzec co Models\TileCache::store(). */
    private static function store(string $path, string $bytes): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }
}
