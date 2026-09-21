<?php
// core/Utils/Sitemap.php
// XML rendering współdzielone przez trasy /sitemap*.xml w web/routes.php —
// jedno miejsce do budowania <urlset>/<sitemapindex>, żeby nie powielać
// ręcznego escapowania XML w każdej trasie z osobna.
namespace Utils;

class Sitemap
{
    // $urls: [['loc' => string, 'lastmod' => ?string (Y-m-d), 'changefreq' => ?string, 'priority' => ?string], ...]
    //
    // WERSJE JĘZYKOWE (2026-09-16, tasks/active/wielojezycznosc.md). Przy więcej
    // niż jednym włączonym języku każdy adres wchodzi do sitemapy w KAŻDEJ
    // wersji, a każda wersja wymienia wszystkie (łącznie z sobą) + x-default —
    // to samo, co hreflang w <head>, tylko w formie, którą Google czyta bez
    // pobierania stron. Trasy podają adresy polskie; resztę liczy Core\Lang.
    // Z flagą ['pl'] wynik jest bajt w bajt taki jak przed zmianą.
    public static function urlset(array $urls): string
    {
        $wieleJezykow = count(\Core\Lang::supported()) > 1;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= $wieleJezykow
            ? '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
            : '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $wersje = $wieleJezykow ? \Core\Lang::alternates($u['loc']) : [\Core\Lang::DOMYSLNY => $u['loc']];
            foreach ($wersje as $loc) {
                $xml .= '<url>';
                $xml .= '<loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>';
                if (!empty($u['lastmod'])) $xml .= '<lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1) . '</lastmod>';
                if (!empty($u['changefreq'])) $xml .= '<changefreq>' . htmlspecialchars($u['changefreq'], ENT_XML1) . '</changefreq>';
                if (!empty($u['priority'])) $xml .= '<priority>' . htmlspecialchars($u['priority'], ENT_XML1) . '</priority>';
                if ($wieleJezykow) {
                    foreach ($wersje as $lang => $alt) {
                        $xml .= '<xhtml:link rel="alternate" hreflang="' . htmlspecialchars($lang, ENT_XML1) . '" href="' . htmlspecialchars($alt, ENT_XML1) . '"/>';
                    }
                    $xml .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($wersje[\Core\Lang::xDefault()], ENT_XML1) . '"/>';
                }
                $xml .= '</url>' . "\n";
            }
        }
        $xml .= '</urlset>';
        return $xml;
    }

    // $sitemapUrls: string[] — pełne adresy do pod-sitemap.
    public static function index(array $sitemapUrls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($sitemapUrls as $loc) {
            $xml .= '<sitemap><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc></sitemap>' . "\n";
        }
        $xml .= '</sitemapindex>';
        return $xml;
    }
}
