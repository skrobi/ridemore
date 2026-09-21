<?php
// core/Utils/EventSourceFetcher.php
// Serwerowy odpowiednik przeglądarkowego scripts/dom-features.js z rozszerzenia
// Chrome: pobiera stronę „łatwego" źródła (publiczny HTML, bez logowania) i
// zamienia ją na DOKŁADNIE ten sam kształt payloadu, którego oczekuje
// ai-engine/analyze.py — dzięki temu importer serwerowy i rozszerzenie karmią
// TEN SAM ekstraktor (patrz Models\EventImport i import_events.php).
//
// Świadome uproszczenie względem dom-features.js: nie mamy geometrii layoutu
// (rect/style) — na serwerze nie renderujemy strony. analyze.py radzi sobie
// z ich brakiem (serialize_elements czyta rect/style przez .get(...), None jest
// bezpieczne), a fakty i tak bierze z tekstu/linków/JSON-LD, nie z pozycji.
//
// Źródła „trudne" (Facebook/Instagram/za logowaniem/za JS-em) NIE przechodzą
// tędy — te zostają przy rozszerzeniu Chrome (zalogowany admin). Serwerowy
// crawl by się o nie rozbił (antybot/TOS), patrz tasks/active/importer-wydarzen.md.
namespace Utils;

class EventSourceFetcher
{
    private const UA = 'Mozilla/5.0 (compatible; RidemoreImporter/1.0; +https://ridemore.bike)';
    private const TIMEOUT = 20;
    private const MAX_ELEMENTS = 300;
    private const MAX_LINKS = 120;
    private const MAX_IMAGES = 40;

    // Pobiera URL i buduje payload. Rzuca RuntimeException z bezpieczną treścią,
    // gdy strony nie da się pobrać (worker CLI pokazuje to w logu i idzie dalej).
    public static function fetch(string $url): array
    {
        $html = self::httpGet($url);
        return self::buildPayload($html, $url);
    }

    // Sygnały „to link do POJEDYNCZEGO wydarzenia", nie do nawigacji/regulaminu.
    // Szukane w ścieżce URL-a ALBO w tekście linku (case-insensitive).
    private const EVENT_URL_HINTS = '/wydarzeni|\/event|\/e\/|wyscig|wyścig|zawody|\brajd|maraton|impreza|\/p\/|edycja|\/20\d{2}[\/-]|\d{4}-\d{2}-\d{2}/i';
    // Linki, których NA PEWNO nie chcemy (nawigacja/prawne/pliki/paginacja/social).
    private const NON_EVENT_HINTS = '/facebook|instagram|youtube|twitter|tiktok|linkedin|dodaj-wydarzenie|dodaj-event|add-event|\/login|logowanie|rejestracja-konta|regulamin|polityk|prywatn|cookie|kontakt|\/o-nas|\/about|\/page\/|[?&]page=|\/tag\/|\/kategoria\/|\.(jpg|jpeg|png|gif|webp|pdf|gpx|zip|doc|docx)(\?|$)/i';
    // Platformy wydarzeń, którym ufamy nawet spoza hosta listy (link zewnętrzny
    // z kalendarza klubu do strony biletowej/zapisowej wydarzenia).
    private const EVENT_PLATFORM_HOSTS = ['eventbrite.com', 'eventbrite.pl', 'evenea.pl', 'zmierzymyczas.pl', 'dostartu.pl'];

    // Wydzielone z fetch() jako CZYSTA funkcja (HTML + URL -> payload), żeby dało
    // się ją przetestować bez sieci (patrz tests/importer_test.php).
    public static function buildPayload(string $html, string $url): array
    {
        $doc = new \DOMDocument();
        // Wymuszenie UTF-8: bez tego loadHTML zgaduje kodowanie po nagłówku meta
        // albo spada na ISO-8859-1 i psuje polskie znaki. libxml_use_internal_errors
        // wycisza tysiące ostrzeżeń o niepoprawnym HTML-u realnych stron.
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);

        $pageTitle = self::firstText($xp, '//title');

        $meta = [];
        foreach (['description' => "//meta[@name='description']/@content",
                  'ogTitle'     => "//meta[@property='og:title']/@content",
                  'ogDescription' => "//meta[@property='og:description']/@content",
                  'ogImage'     => "//meta[@property='og:image']/@content"] as $key => $q) {
            $val = self::firstText($xp, $q);
            if ($val !== null) {
                $meta[$key] = $val;
            }
        }

        $jsonLd = [];
        foreach ($xp->query("//script[@type='application/ld+json']") as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (is_array($decoded)) {
                $jsonLd[] = $decoded;
            }
        }

        $links = [];
        $i = 0;
        foreach ($xp->query('//a[@href]') as $a) {
            $href = self::absolutize(trim($a->getAttribute('href')), $url);
            if ($href === null) {
                continue;
            }
            $text = self::squish($a->textContent);
            $links[] = ['index' => $i, 'text' => $text, 'href' => $href];
            $i++;
            if ($i >= self::MAX_LINKS) {
                break;
            }
        }

        $images = [];
        $i = 0;
        foreach ($xp->query('//img') as $img) {
            $src = self::absolutize(trim($img->getAttribute('src')), $url);
            if ($src === null) {
                continue;
            }
            $images[] = ['index' => $i, 'src' => $src, 'alt' => self::squish($img->getAttribute('alt'))];
            $i++;
            if ($i >= self::MAX_IMAGES) {
                break;
            }
        }

        $elements = [];
        $id = 0;
        foreach ($xp->query('//h1|//h2|//h3|//h4|//h5|//h6|//p|//li') as $el) {
            $text = self::squish($el->textContent);
            if ($text === '') {
                continue;
            }
            $elements[] = ['id' => $id, 'tag' => strtolower($el->nodeName), 'text' => $text];
            $id++;
            if ($id >= self::MAX_ELEMENTS) {
                break;
            }
        }

        return [
            'sourceUrl'    => $url,
            'pageTitle'    => $pageTitle,
            'meta'         => $meta,
            'jsonLd'       => $jsonLd,
            'links'        => $links,
            'images'       => $images,
            'elements'     => $elements,
            // Ten sam słownik, którym karmi się rozszerzenie przez /api/dictionaries
            // — analyze.py waliduje wobec niego kody region/surface/pace/... .
            'dictionaries' => \Controllers\Support::eventFormDictOptions(),
        ];
    }

    // Poziom 2 importera (patrz tasks/active/importer-wydarzen.md, Etap 2):
    // pobiera stronę-LISTĘ (kalendarz/„nadchodzące wyścigi"/archiwum) i wyłuskuje
    // z niej adresy POJEDYNCZYCH wydarzeń do przetworzenia workerem. Rozwiązuje
    // „skąd wziąć listę adresów" bez ręcznego klikania po kalendarzu.
    public static function harvest(string $url): array
    {
        $html = self::httpGet($url);
        $payload = self::buildPayload($html, $url);
        return self::extractEventLinks($payload['links'], $url);
    }

    // CZYSTA funkcja (lista linków + adres bazowy -> adresy wydarzeń), testowalna
    // bez sieci. Heurystyka celowo ZACHOWAWCZA: fałszywy „to nie wydarzenie" co
    // najwyżej pominie jedną pozycję (człowiek i tak przegląda), a fałszywy „to
    // wydarzenie" kosztuje wywołanie modelu i śmieciowego kandydata — więc wolimy
    // przepuścić za mało niż za dużo. Zwraca [['url','text'], ...], odduplikowane,
    // z limitem. Reużywa buildPayload (linki już zabsolutyzowane, mailto/#
    // odsiane).
    public static function extractEventLinks(array $links, string $baseUrl): array
    {
        $baseHost = self::hostOf($baseUrl);
        $baseNorm = self::normalizeLink($baseUrl);
        $out = [];
        $seen = [];
        foreach ($links as $l) {
            $href = trim((string) ($l['href'] ?? ''));
            $text = trim((string) ($l['text'] ?? ''));
            if ($href === '' || preg_match(self::NON_EVENT_HINTS, $href)) {
                continue;
            }
            $norm = self::normalizeLink($href);
            if ($norm === $baseNorm || isset($seen[$norm])) {
                continue; // sama lista albo już zebrane
            }
            $host = self::hostOf($href);
            $sameHost = $host !== null && $host === $baseHost;
            $trustedPlatform = $host !== null && in_array($host, self::EVENT_PLATFORM_HOSTS, true);
            $hasHint = preg_match(self::EVENT_URL_HINTS, $href . ' ' . $text) === 1;
            // Wpuszczamy: (ten sam host + sygnał wydarzenia) ALBO zaufana platforma
            // wydarzeń. Sam „ten sam host" bez sygnału to zwykle menu/stopka.
            if (($sameHost && $hasHint) || $trustedPlatform) {
                $seen[$norm] = true;
                $out[] = ['url' => $href, 'text' => $text];
                if (count($out) >= 60) {
                    break;
                }
            }
        }
        return $out;
    }

    // Hosty, których serwerowy import NIE tknie: treść jest za logowaniem/JS
    // i/lub scraping łamie ich regulamin (Facebook, Instagram, X…). To źródła
    // „trudne" z założeń projektu — obsługuje je WYŁĄCZNIE rozszerzenie Chrome
    // (działa na wyrenderowanej, zalogowanej stronie). Odmawiamy WPROST, zanim
    // w ogóle spróbujemy pobrać (żadnego wiszącego 20-sekundowego fetcha ani
    // mylącego wyniku „skorupa SPA").
    private const UNSUPPORTED_HOSTS = [
        'facebook.com', 'm.facebook.com', 'fb.com', 'fb.me', 'web.facebook.com',
        'instagram.com', 'twitter.com', 'x.com', 'tiktok.com', 'linkedin.com',
    ];

    public static function isUnsupportedHost(string $url): bool
    {
        $host = self::hostOf($url);
        if ($host === null) {
            return false;
        }
        foreach (self::UNSUPPORTED_HOSTS as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                return true;
            }
        }
        return false;
    }

    // Czy pobrany payload wygląda na SKORUPĘ aplikacji renderowanej JavaScriptem
    // (SPA), a nie na realną stronę z treścią. Serwerowe `file_get_contents` nie
    // odpala JS, więc dla takich stron dostajemy sam szkielet (menu + parę
    // elementów, zero JSON-LD) — treść wydarzeń pojawia się dopiero w
    // przeglądarce. Realne strony wydarzeń mają dziesiątki elementów tekstowych
    // i zwykle JSON-LD. Taki sygnał kieruje usera na rozszerzenie Chrome (widzi
    // stronę PO wyrenderowaniu), zamiast po cichu zwracać „0 wyników".
    public static function looksJsRendered(array $payload): bool
    {
        return count($payload['elements'] ?? []) < 5 && count($payload['jsonLd'] ?? []) === 0;
    }

    private static function hostOf(string $url): ?string
    {
        $h = parse_url($url, PHP_URL_HOST);
        return $h ? preg_replace('/^www\./', '', strtolower($h)) : null;
    }

    // Klucz deduplikacji adresu: bez fragmentu (#...) i bez końcowego ukośnika.
    private static function normalizeLink(string $url): string
    {
        $url = preg_replace('/#.*$/', '', $url) ?? $url;
        return rtrim($url, '/');
    }

    private static function httpGet(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Nieobsługiwany adres (tylko http/https): ' . $url);
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: " . self::UA . "\r\nAccept: text/html,application/xhtml+xml\r\n",
                'timeout'       => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $html = @file_get_contents($url, false, $context);
        if ($html === false || $html === '') {
            throw new \RuntimeException('Nie udało się pobrać strony: ' . $url);
        }
        return $html;
    }

    // Zamiana adresu względnego na absolutny wobec adresu strony. Zwraca null dla
    // pustych/nie-nawigacyjnych (mailto:, javascript:, #kotwica) — takie linki
    // nie są kandydatem na trasę/zapisy, a wpuszczone tylko zaśmiecałyby listę.
    private static function absolutize(string $href, string $base): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if (in_array($scheme, ['mailto', 'tel', 'javascript', 'data'], true)) {
            return null;
        }
        if ($scheme === 'http' || $scheme === 'https') {
            return $href;
        }
        $b = parse_url($base);
        if (empty($b['scheme']) || empty($b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        // Względny do katalogu bieżącej ścieżki.
        $path = $b['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1) ?: '/';
        return $origin . $dir . $href;
    }

    private static function firstText(\DOMXPath $xp, string $query): ?string
    {
        $nodes = $xp->query($query);
        if (!$nodes || $nodes->length === 0) {
            return null;
        }
        $text = self::squish($nodes->item(0)->textContent);
        return $text === '' ? null : $text;
    }

    private static function squish(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }
}
