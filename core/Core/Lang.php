<?php
// core/Core/Lang.php
namespace Core;

/**
 * JĘZYK SERWISU (2026-09-16, tasks/active/wielojezycznosc.md).
 *
 * ============================================================================
 * TRZY ZASADY, NA KTÓRYCH STOI CAŁA WIELOJĘZYCZNOŚĆ
 * ============================================================================
 * 1. O JĘZYKU STRONY DECYDUJE ADRES. Polski bez prefiksu (adresy sprzed zmiany
 *    zostają co do znaku — SEO), każdy inny pod `/{kod}/…`. Preferencja
 *    (ciasteczko `rm_lang`, `users.lang`) nie przekierowuje po serwisie — działa
 *    wyłącznie przy wejściu na `/`, w mailach i pushach. Google odradza
 *    przekierowania wg „wykrytego" języka, a adres, który zawsze znaczy to samo,
 *    daje się udostępnić, zcache'ować i przetestować.
 *
 * 2. KLUCZEM SŁOWNIKA JEST POLSKI TEKST. `__('Zapisz się')` w polskiej wersji
 *    zwraca argument bez żadnego szukania, w innej — wpis z `core/lang/{kod}.php`,
 *    a przy jego braku ZNOWU polski tekst. Nie ma nazw kluczy do wymyślania
 *    (~4 tys. tekstów), a brak tłumaczenia nigdy nie wywraca strony.
 *
 * 3. ZERO ZALEŻNOŚCI. Zwykłe tablice PHP zamiast gettext (wymaga locale
 *    zainstalowanych w systemie) i ICU (`ext-intl` nie ma nawet na lokalnym
 *    XAMPP-ie). Liczba mnoga i nazwy miesięcy dla obsługiwanych języków są tu.
 *
 * Prefiks zdejmuje `Router::stripBasePath()` (dotąd jedyne miejsce, które znało
 * `base_path`), dokłada `Utils\View::url()` / `absoluteUrl()` — ta klasa zna
 * tylko reguły, nie składa adresów sama.
 */
final class Lang
{
    public const DOMYSLNY = 'pl';

    /** Kod języka → locale dla `og:locale` i atrybutów. */
    private const LOCALE = ['pl' => 'pl_PL', 'en' => 'en_GB', 'de' => 'de_DE', 'cs' => 'cs_CZ', 'sk' => 'sk_SK'];

    /** Nazwa języka W TYM JĘZYKU — przełącznik mówi „English", nie „angielski". */
    private const NAZWY = ['pl' => 'Polski', 'en' => 'English', 'de' => 'Deutsch', 'cs' => 'Čeština', 'sk' => 'Slovenčina'];

    /**
     * ADRESY, KTÓRE NIGDY NIE DOSTAJĄ PREFIKSU.
     *
     * Każdy z nich jest zapisany POZA naszym kodem — w panelu dostawcy OAuth,
     * w panelu webhooków licznika, w robots.txt, w pliku na dysku — więc
     * `/en/…` nie istniałoby dla drugiej strony. Pliki (cokolwiek z
     * rozszerzeniem: kafle, assety, sitemapy, service worker) nie mają języka.
     */
    private const BEZ_PREFIKSU = [
        '~^/auth/(google|strava)/callback$~',
        '~^/auth/app$~',
        '~^/admin/moje-przejazdy/licznik/[^/]+/callback$~',
        '~^/api/liczniki/[^/]+/webhook$~',
        '~^/assets/~',
        '~\.[A-Za-z0-9]{2,5}$~',
    ];

    private static ?string $biezacy = null;

    /** @var array<string, array<string, string|array>> */
    private static array $slowniki = [];

    /** @var array<string, true> brakujące tłumaczenia z tego żądania (tylko dev) */
    private static array $brakujace = [];

    // ------------------------------------------------------------------
    // Który język
    // ------------------------------------------------------------------

    /** @return string[] języki włączone flagą `languages` w configu; domyślny zawsze pierwszy. */
    public static function supported(): array
    {
        $lista = defined('APP_CONFIG') ? (APP_CONFIG['languages'] ?? [self::DOMYSLNY]) : [self::DOMYSLNY];
        $lista = array_values(array_unique(array_merge([self::DOMYSLNY], $lista)));
        return $lista;
    }

    public static function isSupported(?string $lang): bool
    {
        return $lang !== null && in_array($lang, self::supported(), true);
    }

    public static function current(): string
    {
        return self::$biezacy ?? self::DOMYSLNY;
    }

    public static function isDefault(): bool
    {
        return self::current() === self::DOMYSLNY;
    }

    public static function set(string $lang): void
    {
        self::$biezacy = self::isSupported($lang) ? $lang : self::DOMYSLNY;
    }

    /**
     * Wykonuje $fn w innym języku i PRZYWRACA poprzedni — także po wyjątku.
     * Do maili i pushy: organizator dostaje wiadomość w SWOIM języku, choć
     * zapisał się ktoś oglądający serwis w innym.
     */
    public static function with(?string $lang, callable $fn): mixed
    {
        $poprzedni = self::$biezacy;
        self::set($lang ?? self::DOMYSLNY);
        try {
            return $fn();
        } finally {
            self::$biezacy = $poprzedni;
        }
    }

    public static function locale(?string $lang = null): string
    {
        return self::LOCALE[$lang ?? self::current()] ?? 'pl_PL';
    }

    public static function name(string $lang): string
    {
        return self::NAZWY[$lang] ?? strtoupper($lang);
    }

    /**
     * Język, na który wskazuje `x-default` w hreflang — wersja dla tych, których
     * języka nie mamy. Angielski, jeśli jest włączony: Słowak czy Czech prędzej
     * przeczyta angielski niż polski.
     */
    public static function xDefault(): string
    {
        return self::isSupported('en') ? 'en' : self::DOMYSLNY;
    }

    // ------------------------------------------------------------------
    // Prefiks w adresie
    // ------------------------------------------------------------------

    /**
     * Rozdziela ścieżkę (już BEZ base_path) na [język, reszta].
     * `/en` i `/en/…` → ['en', '/…']; wszystko inne → ['pl', ścieżka bez zmian].
     * Prefiks języka, który NIE jest włączony, nie jest prefiksem — `/en/x` na
     * produkcji z flagą `['pl']` to zwykłe 404, dokładnie jak przed zmianą.
     *
     * @return array{0:string,1:string}
     */
    public static function splitPath(string $path): array
    {
        if (preg_match('~^/([a-z]{2})(/.*)?$~', $path, $m) === 1
            && $m[1] !== self::DOMYSLNY
            && self::isSupported($m[1])
        ) {
            $reszta = $m[2] ?? '';
            return [$m[1], $reszta === '' ? '/' : $reszta];
        }
        return [self::DOMYSLNY, $path];
    }

    /** Ustawia bieżący język z adresu żądania (wołane raz, w bootstrapie). */
    public static function initFromRequest(string $requestUri): void
    {
        $sciezka = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $base = defined('APP_CONFIG') ? (APP_CONFIG['base_path'] ?? '') : '';
        if ($base !== '' && str_starts_with($sciezka, $base)) {
            $sciezka = substr($sciezka, strlen($base));
        }
        self::set(self::splitPath($sciezka === '' ? '/' : $sciezka)[0]);
    }

    /** Czy ścieżka należy do tych, które zawsze zostają bez prefiksu. */
    public static function isUnlocalized(string $path): bool
    {
        foreach (self::BEZ_PREFIKSU as $wzorzec) {
            if (preg_match($wzorzec, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dokłada prefiks języka do ścieżki lokalnej (`/wydarzenia?x=1#y`).
     * Idempotentne: ścieżka z prefiksem dowolnego włączonego języka wychodzi
     * bez zmian — dzięki temu adres kanoniczny zbudowany z REQUEST_URI nie
     * dostaje `/en/en/…`.
     */
    public static function localizePath(string $path, ?string $lang = null): string
    {
        $lang ??= self::current();
        if ($lang === self::DOMYSLNY || !self::isSupported($lang)) {
            return $path;
        }

        $podzial = strcspn($path, '?#');
        $sama = substr($path, 0, $podzial);
        $ogon = substr($path, $podzial);
        if ($sama === '' || $sama[0] !== '/') {
            $sama = '/' . $sama;
        }

        if (self::isUnlocalized($sama) || self::splitPath($sama)[0] !== self::DOMYSLNY) {
            return $sama . $ogon;
        }

        return '/' . $lang . ($sama === '/' ? '' : $sama) . $ogon;
    }

    /** Ścieżka bez prefiksu języka (wersja „neutralna", z której buduje się alternatywy). */
    public static function stripPrefix(string $path): string
    {
        return self::splitPath($path)[1];
    }

    /**
     * Wersje językowe adresu kanonicznego strony: ['pl' => url, 'en' => url].
     * Wejście i wyjście to adresy BEZWZGLĘDNE z `app_url` (jak canonical).
     *
     * @return array<string,string>
     */
    public static function alternates(string $canonicalUrl): array
    {
        $appUrl = rtrim(APP_CONFIG['app_url'] ?? '', '/');
        $sciezka = str_starts_with($canonicalUrl, $appUrl)
            ? substr($canonicalUrl, strlen($appUrl))
            : (parse_url($canonicalUrl, PHP_URL_PATH) ?: '/');
        if ($sciezka === '') {
            $sciezka = '/';
        }
        $neutralna = self::stripPrefix($sciezka);

        $wynik = [];
        foreach (self::supported() as $lang) {
            $lokalna = self::localizePath($neutralna, $lang);
            $wynik[$lang] = $appUrl . ($lokalna === '/' ? '/' : $lokalna);
        }
        return $wynik;
    }

    /**
     * Adres BIEŻĄCEJ strony w innym języku — dla przełącznika. W odróżnieniu od
     * alternates() zachowuje query string: przełączenie języka na liście
     * wydarzeń z filtrami nie może tych filtrów zgubić.
     */
    public static function switchUrl(string $lang): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $sciezka = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = rtrim(APP_CONFIG['base_path'] ?? '', '/');
        if ($base !== '' && str_starts_with($sciezka, $base)) {
            $sciezka = substr($sciezka, strlen($base));
        }
        $neutralna = self::stripPrefix($sciezka === '' ? '/' : $sciezka);
        $query = parse_url($uri, PHP_URL_QUERY);
        return self::with($lang, fn() => \Utils\View::url($neutralna)) . ($query ? '?' . $query : '');
    }

    /**
     * Preferowany język odwiedzającego: jawny wybór z przełącznika (ciasteczko),
     * a u zalogowanego `users.lang`. NULL = nigdy nie wybierał.
     */
    public static function preferred(): ?string
    {
        $zCiasteczka = $_COOKIE['rm_lang'] ?? null;
        if (self::isSupported($zCiasteczka)) {
            return $zCiasteczka;
        }
        $zKonta = Auth::user()?->lang;
        return self::isSupported($zKonta) ? $zKonta : null;
    }

    /**
     * Język, w którym piszemy maila na dany adres.
     *  - adres należy do konta: `users.lang`, a gdy pusty — polski (konta sprzed
     *    wielojęzyczności to polscy użytkownicy, nie „ktoś w bieżącym języku"),
     *  - adres spoza kont (kontakt organizatora, zgłaszający bez konta): język
     *    żądania, które maila wywołało — to ten człowiek właśnie klikał.
     * Na dev testy wołają to w CLI bez bazy tylko pośrednio, więc błąd zapytania
     * nie może wywrócić wysyłki: wtedy też język żądania.
     */
    public static function forEmail(string $email): string
    {
        try {
            $stmt = Database::connection()->prepare('SELECT lang FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([trim($email)]);
            $row = $stmt->fetch();
        } catch (\Throwable $e) {
            return self::current();
        }
        if ($row === false) {
            return self::current();
        }
        return self::isSupported($row['lang'] ?? null) ? $row['lang'] : self::DOMYSLNY;
    }

    /** Zapamiętuje wybór w ciasteczku na rok (wołane przez przełącznik). */
    public static function rememberChoice(string $lang): void
    {
        if (!self::isSupported($lang) || headers_sent()) {
            return;
        }
        setcookie('rm_lang', $lang, [
            'expires'  => time() + 60 * 60 * 24 * 365,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['rm_lang'] = $lang;
    }

    // ------------------------------------------------------------------
    // Teksty
    // ------------------------------------------------------------------

    /**
     * Tłumaczenie tekstu interfejsu. `{pole}` podmieniane wartościami z $params
     * — BEZ ucieczki: tekst bywa HTML-em z szablonu, więc to wywołujący wie,
     * czy wartość (np. cudzy tytuł) trzeba najpierw przepuścić przez
     * htmlspecialchars.
     */
    public static function t(string $text, array $params = []): string
    {
        $wynik = $text;
        if (!self::isDefault()) {
            $slownik = self::dictionary(self::current());
            // Klucz bez znaczenia białych znaków: tekst z szablonu bywa złamany
            // w kilku liniach z wcięciem, a w słowniku stoi w jednej.
            $klucz = isset($slownik[$text]) ? $text : preg_replace('/\s+/u', ' ', trim($text));
            if (isset($slownik[$klucz]) && is_string($slownik[$klucz]) && $slownik[$klucz] !== '') {
                $wynik = $slownik[$klucz];
            } else {
                self::$brakujace[$text] = true;
            }
        }
        return $params ? self::fill($wynik, $params) : $wynik;
    }

    /**
     * Liczba mnoga. Argumenty to ZAWSZE trzy polskie formy (`pole`, `pola`,
     * `pól`).
     *
     * Dwie drogi, od najdokładniejszej:
     *  1. Słownik ma wpis `pole|pola|pól` z listą form danego języka — wtedy
     *     wybór wg reguły TEGO języka (angielski/niemiecki: 1 / reszta;
     *     czeski/słowacki: 1 / 2–4 / reszta).
     *  2. Każda forma tłumaczona osobno i wybór wg reguły POLSKIEJ. Dla
     *     angielskiego to jest dokładne: polska forma „1" jest tylko dla
     *     jedynki, a obie pozostałe (2–4 i 5+) po angielsku są tą samą liczbą
     *     mnogą. Dzięki temu lokalne `$plural(…)` w widokach, owinięte po
     *     formie, działają bez przepisywania.
     */
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $lang = self::current();
        if ($lang !== self::DOMYSLNY) {
            $formy = self::dictionary($lang)[$one . '|' . $few . '|' . $many] ?? null;
            if (is_array($formy) && $formy) {
                $formy = array_values($formy);
                $i = match ($lang) {
                    'cs', 'sk' => $n === 1 ? 0 : (($n >= 2 && $n <= 4) ? 1 : 2),
                    default    => $n === 1 ? 0 : 1,
                };
                return $formy[min($i, count($formy) - 1)];
            }
            [$one, $few, $many] = [self::t($one), self::t($few), self::t($many)];
        }

        if ($n === 1) {
            return $one;
        }
        $last = $n % 10;
        $last2 = $n % 100;
        return ($last >= 2 && $last <= 4 && ($last2 < 12 || $last2 > 14)) ? $few : $many;
    }

    /** @param array<string,mixed> $params */
    public static function fill(string $text, array $params): string
    {
        $zamiany = [];
        foreach ($params as $klucz => $wartosc) {
            $zamiany['{' . $klucz . '}'] = (string) $wartosc;
        }
        return strtr($text, $zamiany);
    }

    /** @return array<string, string|array> */
    public static function dictionary(string $lang): array
    {
        if (!isset(self::$slowniki[$lang])) {
            $plik = CORE_PATH . '/lang/' . $lang . '.php';
            $dane = ($lang !== self::DOMYSLNY && preg_match('~^[a-z]{2}$~', $lang) === 1 && is_file($plik)) ? require $plik : [];
            self::$slowniki[$lang] = is_array($dane) ? $dane : [];
        }
        return self::$slowniki[$lang];
    }

    /**
     * Słownik dla JavaScriptu — cały słownik języka minus liczba mnoga.
     * Ładowany do `window.RM_I18N` tylko poza polskim (polski nie potrzebuje
     * niczego: klucz JEST tekstem).
     *
     * @return array<string,string>
     */
    public static function jsDictionary(): array
    {
        if (self::isDefault()) {
            return [];
        }
        $plik = CORE_PATH . '/lang/' . self::current() . '-js.php';
        $dane = is_file($plik) ? require $plik : [];
        return is_array($dane) ? $dane : [];
    }

    /** @return string[] teksty bez tłumaczenia wypisane w tym żądaniu (diagnostyka, testy). */
    public static function missing(): array
    {
        return array_keys(self::$brakujace);
    }

    // ------------------------------------------------------------------
    // Daty i liczby
    // ------------------------------------------------------------------

    /** Nazwy miesięcy: 'long' (w dopełniaczu po polsku: „września"), 'short' („wrz"). */
    public static function months(string $wariant, ?string $lang = null): array
    {
        $lang ??= self::current();
        $pl = [
            'long'  => [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'],
            'short' => [1 => 'sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'],
        ];
        $en = [
            'long'  => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'short' => [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        ];
        $zestaw = match ($lang) {
            'en'    => $en,
            'pl'    => $pl,
            default => (self::dictionary($lang)['@months'] ?? $en),
        };
        return $zestaw[$wariant] ?? $pl[$wariant];
    }

    /** Separator dziesiętny i tysięcy: [',', ' '] po polsku, ['.', ','] po angielsku. */
    public static function numberSeparators(?string $lang = null): array
    {
        return match ($lang ?? self::current()) {
            'en'    => ['.', ','],
            'de'    => [',', '.'],
            default => [',', ' '],
        };
    }

    // ------------------------------------------------------------------
    // Język tekstu z bazy
    // ------------------------------------------------------------------

    /**
     * Zgadywanie języka treści użytkownika (pl/en) BEZ żadnego wywołania.
     * Zwraca NULL, gdy tekst nie przesądza — wtedy decyduje silnik tłumaczenia
     * i jego wynik trafia do cache. Chodzi o najczęstszy przypadek: Polak
     * ogląda polski event — tu nie może być ani zapytania, ani opóźnienia.
     *
     * Świadomie prosto: znaki diakrytyczne i krótkie listy słów funkcyjnych.
     * Polski tekst pisany bez ogonków („zbiorka o 8 przy rondzie") łapią słowa
     * funkcyjne; krótkie tytuły („Gravel 100") zostają NULL.
     */
    public static function guess(?string $text): ?string
    {
        $tekst = mb_strtolower(trim(strip_tags((string) $text)));
        if (mb_strlen($tekst) < 12) {
            return null;
        }

        $pl = preg_match_all('/[ąćęłńśźż]/u', $tekst) * 2;
        $slowa = preg_split('/[^\p{L}]+/u', $tekst, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $plSlowa = ['i', 'w', 'we', 'na', 'z', 'ze', 'się', 'sie', 'nie', 'do', 'jest', 'że', 'ze', 'dla', 'oraz', 'po', 'od', 'przy', 'lub', 'jak', 'będzie', 'bedzie', 'są', 'sa', 'o', 'u', 'dnia', 'trasa', 'jazda', 'zbiórka', 'zbiorka', 'godz'];
        $enSlowa = ['the', 'and', 'of', 'to', 'in', 'is', 'for', 'with', 'on', 'at', 'from', 'we', 'you', 'this', 'will', 'are', 'be', 'our', 'your', 'ride', 'route', 'meeting', 'point', 'day', 'not', 'it'];
        $en = 0;
        foreach ($slowa as $slowo) {
            if (in_array($slowo, $plSlowa, true)) {
                $pl++;
            }
            if (in_array($slowo, $enSlowa, true)) {
                $en++;
            }
        }

        if ($pl === 0 && $en === 0) {
            return null;
        }
        if ($pl >= 2 && $pl >= $en * 2) {
            return 'pl';
        }
        if ($en >= 2 && $en >= $pl * 2) {
            return 'en';
        }
        return null;
    }
}
