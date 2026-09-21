<?php
// core/Utils/Qr.php
// KOD QR DLA SKARBU (Etap 8D, SKA/2).
//
// Cienka warstwa nad endroid/qr-code — istnieje po to, żeby reszta aplikacji
// nie wiedziała, KTÓRA biblioteka to rysuje. Gdyby przyszło ją wymienić (albo
// gdyby na jakimś hostingu zabrakło zależności), zmiana zostaje w tym pliku.
//
// DLACZEGO BIBLIOTEKA, A NIE WŁASNY KODER
// ---------------------------------------
// Kod QR to Reed-Solomon, maskowanie i tablice wersji — kilkaset linijek,
// w których błąd objawia się dopiero tym, że CZYJŚ telefon w terenie nie
// odczytuje naklejki. To jest dokładnie ten rodzaj kodu, którego nie pisze się
// samemu, mając sprawdzoną implementację. Reszta modułu skarbów jest napisana
// od zera, bo tam decyzje są produktowe; tu decyzja jest wyłącznie techniczna.
//
// WYMAGA `composer install` NA PRODUKCJI — tak samo jak logowanie społecznościowe
// (league/oauth2-client). Bez tego panel skarbów pokaże komunikat zamiast kodu,
// a nie wywróci się.
namespace Utils;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class Qr
{
    /**
     * Poziom korekcji błędów.
     *
     * WYSOKI (30%) świadomie, mimo że robi gęstszy kod: naklejka wisi w terenie,
     * więc będzie zachlapana, przetarta, częściowo zaklejona i fotografowana pod
     * kątem w słońcu. Kod, którego nie da się odczytać po miesiącu, nie spełnia
     * swojego jedynego zadania.
     */
    private const CORRECTION = ErrorCorrectionLevel::High;

    /** Czy biblioteka jest w ogóle dostępna — panel ma o tym mówić, nie wybuchać. */
    public static function available(): bool
    {
        return class_exists(Builder::class);
    }

    /**
     * PNG z kodem QR jako dane binarne.
     *
     * @param string $url adres, który ma się otworzyć po zeskanowaniu
     * @param int $size bok obrazka w pikselach
     */
    public static function png(string $url, int $size = 420): string
    {
        // Interfejs płynny, nie parametry nazwane — ta wersja biblioteki (5.1)
        // nie przyjmuje ich w konstruktorze.
        return Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            ->errorCorrectionLevel(self::CORRECTION)
            ->size($size)
            // Margines („quiet zone") jest częścią standardu, a nie ozdobą:
            // czytnik potrzebuje jasnego pasa wokół kodu, żeby znaleźć jego
            // krawędzie. Naklejka drukowana bez marginesu bywa nieczytelna.
            ->margin(16)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build()
            ->getString();
    }

    /** Ten sam kod jako `data:` — żeby widok do druku był JEDNYM plikiem. */
    public static function dataUri(string $url, int $size = 420): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($url, $size));
    }
}
