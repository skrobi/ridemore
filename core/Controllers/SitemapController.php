<?php
// core/Controllers/SitemapController.php
// Sitemapy — index + trzy pod-sitemapy zamiast jednej (jak w pozostałych
// serwisach tej skali), żeby każdy typ treści dało się łatwo rozdzielić/
// rozbudować osobno w przyszłości (patrz Utils\Sitemap). Statyczne strony
// (sitemap-pages) mają zawyżony priority/changefreq względem realnej
// częstotliwości zmian — to zwyczajowa konwencja sitemapy, nie deklaracja
// faktycznego harmonogramu aktualizacji.
namespace Controllers;

use Models\Event;
use Models\KnownRoute;
use Models\Organizer;
use Models\Region;
use Utils\Sitemap;
use Utils\View;

class SitemapController
{
    public static function index(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        echo Sitemap::index([
            View::absoluteUrl('/sitemap-pages.xml'),
            View::absoluteUrl('/sitemap-events.xml'),
            View::absoluteUrl('/sitemap-organizers.xml'),
            View::absoluteUrl('/sitemap-trails.xml'),
            View::absoluteUrl('/sitemap-regions.xml'),
        ]);
    }

    public static function pages(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        // /regulamin i /prywatnosc świadomie POMINIĘTE — obie renderują się z
        // noindex (patrz PageController::terms()/privacy()), bo to na razie
        // strony-zaślepki bez realnej treści; sitemapa nie powinna zachęcać
        // Google do indeksowania czegoś, czego samą stroną każemy nie indeksować.
        echo Sitemap::urlset([
            ['loc' => View::absoluteUrl('/'), 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => View::absoluteUrl('/wydarzenia'), 'changefreq' => 'hourly', 'priority' => '1.0'],
            ['loc' => View::absoluteUrl('/organizatorzy'), 'changefreq' => 'daily', 'priority' => '0.7'],
            ['loc' => View::absoluteUrl('/jak-to-dziala'), 'changefreq' => 'monthly', 'priority' => '0.4'],
            ['loc' => View::absoluteUrl('/dla-organizatorow'), 'changefreq' => 'monthly', 'priority' => '0.4'],
            ['loc' => View::absoluteUrl('/relacje'), 'changefreq' => 'weekly', 'priority' => '0.3'],
            ['loc' => View::absoluteUrl('/odkrycia'), 'changefreq' => 'daily', 'priority' => '0.7'],
            ['loc' => View::absoluteUrl('/trasy'), 'changefreq' => 'weekly', 'priority' => '0.7'],
            ['loc' => View::absoluteUrl('/puls'), 'changefreq' => 'daily', 'priority' => '0.5'],
        ]);
    }

    public static function events(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $urls = array_map(fn($row) => [
            'loc'        => View::absoluteUrl('/events/' . $row['slug']),
            'lastmod'    => date('Y-m-d', strtotime($row['updated_at'])),
            'changefreq' => 'weekly',
            'priority'   => '0.8',
        ], Event::allIndexableForSitemap());
        echo Sitemap::urlset($urls);
    }

    public static function organizers(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $urls = array_map(fn($row) => [
            'loc'        => View::absoluteUrl('/organizatorzy/' . $row['slug']),
            'lastmod'    => date('Y-m-d', strtotime($row['created_at'])),
            'changefreq' => 'weekly',
            'priority'   => '0.6',
        ], Organizer::allSlugsForSitemap());
        echo Sitemap::urlset($urls);
    }

    /**
     * Znane trasy. Osobna sitemapa, bo to jedyna treść tego serwisu, która NIE
     * ma daty ważności — wydarzenie mija, Velo Czorsztyn zostaje. Stąd wysoki
     * priorytet i rzadka częstotliwość: strona zmienia się tylko wtedy, gdy
     * admin poprawi opis albo zdjęcie.
     */
    public static function trails(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $urls = [];
        foreach (KnownRoute::all() as $route) {
            if (empty($route['slug'])) {
                continue;
            }
            $urls[] = [
                'loc'        => View::absoluteUrl('/trasy/' . $route['slug']),
                'lastmod'    => date('Y-m-d', strtotime($route['created_at'])),
                'changefreq' => 'monthly',
                'priority'   => '0.7',
            ];
        }
        echo Sitemap::urlset($urls);
    }

    /**
     * Strony regionów (2026-09-14, tasks/done/strony-regionow.md): spis,
     * kraje i WSZYSTKIE aktywne regiony — bez progu treści, decyzja usera.
     */
    public static function regions(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $urls = [['loc' => View::absoluteUrl('/regiony'), 'changefreq' => 'weekly', 'priority' => '0.6']];
        foreach (Region::countries() as $country) {
            $urls[] = ['loc' => View::absoluteUrl(Region::countryPath($country)), 'changefreq' => 'weekly', 'priority' => '0.6'];
            foreach ($country['regions'] as $region) {
                $urls[] = [
                    'loc'        => View::absoluteUrl('/regiony/' . $country['code'] . '/' . $region['code']),
                    'changefreq' => 'daily',
                    'priority'   => '0.8',
                ];
            }
        }
        echo Sitemap::urlset($urls);
    }
}
