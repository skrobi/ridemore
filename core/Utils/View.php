<?php
// core/Utils/View.php
namespace Utils;

class View
{
    // section: 'web' | 'admin' — wybiera views/{section}/layout.php
    // page: nazwa pliku w views/{section}/pages/ (bez .php)
    public static function render(string $section, string $page, array $data = []): void
    {
        $pagePath = CORE_PATH . '/../views/' . $section . '/pages/' . $page . '.php';

        // CAŁA STRONA W INNYM JĘZYKU (2026-09-16) — długie teksty ciągłe
        // (regulamin, prywatność, „jak to działa") nie nadają się na setki
        // kluczy słownika, więc mają osobny plik `pages/{kod}/{strona}.php`.
        // Brak pliku = zwykły widok ze słownikiem.
        if (!\Core\Lang::isDefault()) {
            $localized = CORE_PATH . '/../views/' . $section . '/pages/' . \Core\Lang::current() . '/' . $page . '.php';
            if (file_exists($localized)) {
                $pagePath = $localized;
            }
        }

        if (!file_exists($pagePath)) {
            throw new \RuntimeException("Widok nie istnieje: $section/$page");
        }

        extract($data);

        ob_start();
        require $pagePath;
        $content = ob_get_clean();

        // WYBÓR SKORUPY (2026-08-29, tasks/done/apka-mobilna-skorupa.md).
        // Apka mobilna dostaje `layout-app.php` — belkę bez nawigacji i menu
        // konta, dolny pasek bez warunku, bez stopki. TE SAME dane i ten sam
        // widok strony; zmienia się wyłącznie to, co je otacza. Dzięki temu
        // ani kontrolery, ani trasy, ani żadne wywołanie render() nie musi
        // wiedzieć, że apka istnieje.
        //
        // TYLKO SEKCJA 'web'. Panel organizatora/admina ('admin') nie jest
        // ekranem apki — ma własny layout i własną nawigację, a wciągnięcie
        // go tutaj znaczyłoby utrzymywanie trzeciej skorupy bez powodu.
        $layout = ($section === 'web' && APP_IS_APP) ? 'layout-app.php' : 'layout.php';
        require CORE_PATH . '/../views/' . $section . '/' . $layout;
    }

    // Jedyne miejsce, które składa adres wewnętrzny — href, src, fetch(),
    // Location:, wszystko. Dokleja base_path z config.php (np. '/ridemore'
    // na dev, '' na prod).
    //
    // OD 2026-09-03 ZWRACA PEŁNY ADRES (schemat + host), na prośbę usera:
    // „chodziło mi o to, żebyś linki budował o tak: http://localhost/ridemore/…".
    //
    // HOST I SCHEMAT BIERZEMY Z BIEŻĄCEGO ŻĄDANIA, NIE Z `app_url`, i to jest
    // sedno tej zmiany. `app_url` jest wpisany w konfigurację na sztywno
    // (`http://localhost/ridemore` na dev), a serwis bywa otwierany pod innym
    // hostem, na którym localhost znaczy zupełnie co innego:
    //   · apka mobilna ładuje ŻYWY serwis z `server.url`, przy testach z telefonu
    //     ustawiony na IP maszyny (`RIDEMORE_APP_URL=http://192.168.1.10/ridemore`,
    //     patrz app/capacitor.config.ts) — adres z `app_url` wysłałby telefon
    //     do niego samego,
    //   · na prod ten sam kod odpowiada pod http i https.
    // Stąd: host z żądania, `app_url` wyłącznie jako zapas dla CLI (cron,
    // migracje, testy), gdzie żądania nie ma.
    //
    // `absoluteUrl()` niżej NIE zmienia się i dalej bierze `app_url`: maile,
    // push, JSON-LD, canonical i og:image mają wskazywać adres KANONICZNY,
    // a nie ten, którym akurat ktoś wszedł (na tym stoi konsolidacja
    // www→bez-www z .htaccess). Nagłówek `Host` jest do podrobienia, więc
    // wpuszczanie go do maila byłoby dziurą — tu wraca do tego samego
    // przeglądającego, którego przyszedł.
    //
    // ADRES JUŻ BEZWZGLĘDNY (albo protokołowo-względny `//host/...`) wychodzi
    // NIETKNIĘTY — do `url()` trafiają też wartości z bazy, np. awatar z Google.
    public static function url(string $path): string
    {
        if (preg_match('~^(?:[a-z][a-z0-9+.\-]*:)?//~i', $path) === 1) {
            return $path;
        }

        // Prefiks języka bieżącego żądania (`/en/…`) — dzięki temu KAŻDY link,
        // formularz i fetch() zbudowany tą metodą zostaje w wersji językowej,
        // w której jest człowiek, bez jednej zmiany w widokach. Wyjątki (pliki,
        // callbacki OAuth i webhooki) zna Core\Lang::localizePath().
        $path = \Core\Lang::localizePath($path);

        return self::origin() . rtrim(APP_CONFIG['base_path'] ?? '', '/') . '/' . ltrim($path, '/');
    }

    /**
     * `schemat://host` bieżącego żądania — bez base_path, bez ukośnika na końcu.
     *
     * Za odwrotnym proxy (Cloudflare, nginx przed Apache) prawdziwy schemat
     * niesie `X-Forwarded-Proto`: bez niego strona podana po https składałaby
     * linki `http://`, czyli treść mieszaną, którą przeglądarka zablokuje.
     * Bierzemy PIERWSZĄ wartość listy, bo przy kilku proxy nagłówek bywa
     * sklejony przecinkami.
     *
     * Host przepuszczamy przez wzorzec (litery, cyfry, kropka, myślnik, port):
     * to jest wartość spod kontroli klienta, a wychodzi do atrybutu HTML.
     * Cokolwiek nie pasuje — spadamy na `app_url`, tak samo jak w CLI.
     */
    private static function origin(): string
    {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
        $host = trim(explode(',', (string) $host)[0]);

        if ($host === '' || preg_match('~^[A-Za-z0-9.\-]+(?::\d+)?$~', $host) !== 1) {
            // CLI albo podejrzany nagłówek — zostaje adres z konfiguracji,
            // ten sam, którym posługują się maile.
            $appUrl = rtrim(APP_CONFIG['app_url'] ?? '', '/');
            $parts = parse_url($appUrl);
            return isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : '';
        }

        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $proto = strtolower(trim(explode(',', (string) $proto)[0]));
        if ($proto !== 'http' && $proto !== 'https') {
            $proto = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
                ? 'https'
                : 'http';
        }

        return $proto . '://' . $host;
    }

    // Pliki statyczne (css/js/img) — dokleja ?v=<mtime> pliku, żeby przeglądarka
    // nie serwowała starej wersji z cache po deployu (złapane na .star-rating:
    // user widział zachowanie sprzed poprawki mimo świeżego kodu na serwerze).
    public static function asset(string $path): string
    {
        $fsPath = CORE_PATH . '/../' . ltrim($path, '/');
        $mtime = @filemtime($fsPath);
        return self::url($path) . ($mtime !== false ? '?v=' . $mtime : '');
    }

    // Pełny adres (ze schematem i domeną) — potrzebny w treści e-maili,
    // gdzie link względny nie ma znaczenia. Też oparty na config.php (app_url).
    public static function absoluteUrl(string $path): string
    {
        // W języku BIEŻĄCYM — w mailu/pushu to język ODBIORCY, bo wysyłka idzie
        // w Core\Lang::with($odbiorca). Adres już z prefiksem (canonical złożony
        // z REQUEST_URI) wychodzi bez zmian — patrz Lang::localizePath().
        $path = \Core\Lang::localizePath($path);

        return rtrim(APP_CONFIG['app_url'] ?? '', '/') . '/' . ltrim($path, '/');
    }

    // Domyślny <link rel="canonical"> — adres bieżącego żądania (bez query
    // stringa, bez base_path — absoluteUrl() dokłada go z powrotem przez
    // app_url, który już go zawiera na dev). Strony z własnym filtrowaniem
    // (np. /organizatorzy?typ=...) powinny nadpisać $canonical na wersję bez
    // parametrów, żeby nie rozmywać się na warianty tego samego adresu.
    // Jak currentCanonicalUrl(), ale dla DOWOLNEGO lokalnego adresu (nie tylko
    // bieżącego żądania) — np. $crumb['url'] z breadcrumbs.php, który już
    // przeszedł przez View::url() i ma base_path z przodu. Bez zdjęcia go
    // najpierw, absoluteUrl() doklei app_url (który na dev SAM już zawiera
    // ten sam base_path) i zdubluje segment ('/ridemore/ridemore/...').
    public static function absoluteFromLocal(string $localUrl): string
    {
        $path = parse_url($localUrl, PHP_URL_PATH) ?: '/';
        $basePath = rtrim(APP_CONFIG['base_path'] ?? '', '/');
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        return self::absoluteUrl($path !== '' ? $path : '/');
    }

    public static function currentCanonicalUrl(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $basePath = rtrim(APP_CONFIG['base_path'] ?? '', '/');
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        return self::absoluteUrl($path !== '' ? $path : '/');
    }
}
