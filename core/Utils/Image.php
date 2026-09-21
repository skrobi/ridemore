<?php
// core/Utils/Image.php
// WARIANTY ROZMIAROWE ZDJĘĆ — jeden plik wgrany, kilka rozmiarów do wyświetlenia.
//
// POWÓD (user 2026-08-14): „zamiast dostosować wielkość skryptem, walisz
// oryginalny rozmiar; wolę przechować więcej zdjęć w różnych wielkościach niż
// obciążać usera ładowaniem pliku po kilka MB".
//
// Stan przed zmianą, zmierzony na katalogu uploadów (22 pliki, 6,32 MB):
//   * miniatura w kronice ma 96 px, a serwowany plik 1200x900 i 229 kB,
//   * awatar w kółku 28 px dostaje plik 399x400 i 245 kB,
//   * okładka na karcie wydarzenia — 1600x900 i 2,08 MB.
// `Upload::resizeIfOversized()` skalowało wyłącznie ORYGINAŁ przy wgrywaniu
// (do 400/1200/1600 px), więc pilnowało górnej granicy pliku, ale nie miało nic
// wspólnego z tym, w jakim rozmiarze zdjęcie jest POKAZYWANE.
//
// GENEROWANIE NA ŻĄDANIE, jak kafle map (migr. 051): wariant powstaje przy
// pierwszym użyciu i od tej chwili jest zwykłym plikiem statycznym, który
// serwuje Apache. Nie ma osobnego kroku „przelicz wszystko po wdrożeniu"
// i — co ważniejsze — pliki wgrane WCZEŚNIEJ dostają warianty tak samo jak
// nowe, bez backfillu.
//
// DLACZEGO NIE PRZEZ ROUTER (inaczej niż kafle): kafla żąda przeglądarka i jest
// ich tysiące, więc opłaca się przechwytywać 404. Wariantów zdjęcia jest kilka
// na plik, a adres trafia do atrybutu `src` w HTML-u — musi więc istnieć już
// w chwili renderowania strony, inaczej wysłalibyśmy przeglądarce adres,
// pod którym nic nie ma.
namespace Utils;

class Image
{
    /**
     * Nazwane rozmiary — DŁUŻSZY BOK w pikselach.
     *
     * Wartości są dwukrotnością rozmiaru w CSS, bo ekrany są dziś w większości
     * o podwojonej gęstości: awatar rysowany na 40 px potrzebuje 80 px pliku,
     * żeby nie był rozmyty. Stąd `av` = 96, a nie 48.
     *
     * Presety są NAZWANE, nie liczbowe, żeby nie dało się wygenerować dowolnego
     * rozmiaru z adresu — inaczej ktoś obcy mógłby zapełnić dysk, prosząc
     * o 10 000 wariantów jednego zdjęcia.
     */
    private const PRESETS = [
        'av'    => 96,    // awatary w listach i składach (28–48 px w CSS)
        'thumb' => 320,   // miniatury galerii i kafelki (96–160 px)
        'card'  => 640,   // okładki na kartach wydarzeń, tras, organizatorów
        'wide'  => 1280,  // hero i pełna szerokość treści
    ];

    /** Jakość JPEG/WebP wariantów — 82 to próg, poniżej którego widać artefakty na zdjęciach terenu. */
    private const QUALITY = 82;

    /**
     * Adres wariantu gotowy do wstawienia w `src`.
     *
     * Zwraca ORYGINAŁ, gdy: preset nieznany, pliku nie ma na dysku, format nie
     * jest rastrem (SVG), oryginał jest już mniejszy niż preset, albo cokolwiek
     * pójdzie nie tak przy skalowaniu. Zasada jest taka, że ta funkcja NIGDY
     * nie może spowodować braku obrazka — najgorsze, co robi, to oddaje to,
     * co oddawała przed jej wprowadzeniem.
     */
    public static function src(?string $url, string $preset = 'card'): string
    {
        if ($url === null || $url === '') {
            return '';
        }
        return View::url(self::path($url, $preset));
    }

    /**
     * Ścieżka wariantu względem aplikacji (bez base_path) — dla miejsc, które
     * same wołają View::url() albo budują URL bezwzględny (np. JSON-LD, maile).
     */
    public static function path(?string $url, string $preset = 'card'): string
    {
        $url = (string) $url;
        if ($url === '' || !isset(self::PRESETS[$preset])) {
            return $url;
        }
        // Wyłącznie własne uploady. Adresy zewnętrzne (np. avatar z Google przy
        // logowaniu społecznościowym) zostają nietknięte — nie mamy tam czego
        // ani gdzie zapisać.
        if (!str_starts_with($url, '/assets/uploads/')) {
            return $url;
        }

        $absolute = dirname(CORE_PATH) . $url;
        if (!is_file($absolute)) {
            return $url;
        }

        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $url;
        }

        $variantUrl = preg_replace('/\.[^.]+$/', '', $url) . '_' . $preset . '.' . $ext;
        $variantPath = dirname(CORE_PATH) . $variantUrl;

        // Wariant już jest — jedno wywołanie stat i wychodzimy. To jest ścieżka,
        // którą idzie każde kolejne wyświetlenie, więc musi być tania.
        if (is_file($variantPath)) {
            return $variantUrl;
        }

        return self::generate($absolute, $variantPath, $variantUrl, $url, self::PRESETS[$preset]);
    }

    /**
     * CZY TEN OBRAZEK W OGÓLE JEST (2026-09-03, zgłoszenie usera: „wskazuje to,
     * że nie ma poprawnego dostępu (…) w całej aplikacji jest podobnie").
     *
     * `src()` z założenia NIGDY nie powoduje braku obrazka — przy pliku, którego
     * nie ma na dysku, oddaje oryginalny adres i przeglądarka pokazuje ikonę
     * zepsutego zdjęcia. To jest właściwa odpowiedź dla WYWOŁUJĄCEGO, który nie
     * ma nic w zamian, ale nie dla komponentów, które MAJĄ swój stan zapasowy:
     * awatar umie narysować inicjały, karta wyjazdu — grafikę zastępczą.
     * Zamiast zmieniać zachowanie `src()` (39 wywołań, część bez alternatywy —
     * `<img src="">` bywa gorszy od zepsutej ikony, bo przeglądarka pobiera
     * wtedy bieżącą stronę jako obrazek), komponenty pytają wprost.
     *
     * Adres ZEWNĘTRZNY (awatar z Google przy logowaniu społecznościowym) uznajemy
     * za istniejący — nie mamy jak go sprawdzić bez żądania sieciowego, a jedno
     * takie żądanie na render listy składu byłoby lekarstwem gorszym od choroby.
     *
     * Koszt: jeden `is_file`, ten sam, który `path()` i tak wykonuje przy
     * składaniu wariantu.
     */
    public static function exists(?string $url): bool
    {
        $url = (string) $url;
        if ($url === '') {
            return false;
        }
        if (!str_starts_with($url, '/assets/uploads/')) {
            return true;
        }

        return is_file(dirname(CORE_PATH) . $url);
    }

    /**
     * Rozmiar wariantu w bajtach albo null — pod pomiary i panel admina.
     */
    public static function variantBytes(string $url, string $preset): ?int
    {
        $variantUrl = preg_replace('/\.[^.]+$/', '', $url) . '_' . $preset . '.'
            . strtolower(pathinfo($url, PATHINFO_EXTENSION));
        $path = dirname(CORE_PATH) . $variantUrl;
        return is_file($path) ? filesize($path) : null;
    }

    /** Nazwy presetów — dla skryptu CLI, żeby nie powtarzał listy. */
    public static function presets(): array
    {
        return array_keys(self::PRESETS);
    }

    private static function generate(
        string $source,
        string $destination,
        string $variantUrl,
        string $originalUrl,
        int $maxDimension
    ): string {
        $info = @getimagesize($source);
        if ($info === false) {
            return $originalUrl;
        }
        [$width, $height] = $info;

        // ORYGINAŁ MNIEJSZY NIŻ PRESET — nie powiększamy. Zwracamy oryginał,
        // zamiast robić jego kopię: kopia zajęłaby drugie tyle miejsca i nie
        // dałaby ani jednego zaoszczędzonego bajtu przy pobieraniu.
        if (max($width, $height) <= $maxDimension) {
            return $originalUrl;
        }

        $scale = $maxDimension / max($width, $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default        => null,
        };
        if (!$src) {
            return $originalUrl;
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        // Przezroczystość PNG/WebP musi przetrwać zmniejszenie — bez tych
        // dwóch linijek awatar z przezroczystym tłem dostaje czarny prostokąt.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Zapis przez plik tymczasowy i rename: dwa równoległe żądania tej samej
        // strony wygenerują ten sam wariant, a czytelnik nigdy nie zobaczy pliku
        // zapisanego w połowie. Ta sama zasada co przy kaflach map.
        $tmp = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = match ($info[2]) {
            IMAGETYPE_JPEG => imagejpeg($dst, $tmp, self::QUALITY),
            IMAGETYPE_PNG  => imagepng($dst, $tmp, 6),
            IMAGETYPE_WEBP => imagewebp($dst, $tmp, self::QUALITY),
            default        => false,
        };
        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok || !@rename($tmp, $destination)) {
            @unlink($tmp);
            return $originalUrl;
        }
        return $variantUrl;
    }
}
