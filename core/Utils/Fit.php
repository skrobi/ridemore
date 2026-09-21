<?php
// core/Utils/Fit.php
// PLIKI FIT — format, w którym nagrywa KAŻDY licznik rowerowy.
//
// DLACZEGO KONWERSJA DO GPX, A NIE DRUGI TOR DANYCH. Cały serwis liczy odkrycia,
// dystans, przewyższenie i skarby z jednego wejścia: `Utils\Gpx::parse()`. Gdyby
// FIT szedł własną drogą, każda z tych rzeczy istniałaby w dwóch wersjach, które
// prędzej czy później dałyby dwa różne wyniki dla tego samego przejazdu. Dlatego
// FIT jest tu WYŁĄCZNIE FORMATEM WEJŚCIA: dekodujemy punkty, zapisujemy GPX
// i od tego miejsca wszystko dzieje się dokładnie tak jak zawsze.
//
// Skutek uboczny jest zamierzony: na dysku i w bazie dalej leżą same GPX-y,
// więc mapy, kafle, edition_tracks i podgląd pliku nie wiedzą, że coś się
// zmieniło. Tracimy moc (FIT niesie tętno, moc, kadencję) — ale tych danych
// ten moduł i tak nigdzie nie używa, a zapisywanie ich „na zapas" byłoby
// trzymaniem cudzych danych zdrowotnych bez powodu.
//
// PO CO NAM TO. FIT to jedyny format, jaki oddają Wahoo, COROS i Suunto, i to,
// co licznik zapisuje na własnej pamięci (katalog /Activities na Edge, ELEMNT,
// Bryton, iGPSPORT). Bez tego dekodera „wgraj ślad z licznika" znaczy „najpierw
// wrzuć go gdzieś, gdzie zrobią ci z niego GPX".
//
// BIBLIOTEKA: adriangibbons/php-fit-file-analysis. Oznaczona przez autora jako
// `abandoned` i to jest świadomie przyjęte ryzyko — format FIT jest stabilny od
// lat (rekordy `record` z pozycją nie zmieniły się od 2015 r.), a paczka jest
// jednym plikiem bez zależności. Gdyby kiedyś przestała działać, wymiana dotyka
// wyłącznie tego pliku: reszta serwisu widzi GPX.
namespace Utils;

class Fit
{
    /** Minimalna liczba punktów, żeby to w ogóle był ślad, a nie pojedynczy odczyt. */
    private const MIN_POINTS = 2;

    /** Czy ta nazwa pliku wygląda na FIT-a (jedyne miejsce, gdzie to rozstrzygamy). */
    public static function looksLikeFit(string $filename): bool
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'fit';
    }

    /**
     * FIT z dysku -> treść pliku GPX.
     *
     * @throws \RuntimeException gdy pliku nie da się odczytać albo nie ma w nim śladu
     */
    public static function toGpx(string $absolutePath, ?string $trackName = null): string
    {
        if (!is_file($absolutePath)) {
            throw new \RuntimeException(__('Nie ma takiego pliku FIT.'));
        }

        try {
            // `fix_data` NIE jest tu włączone celowo: biblioteka potrafi
            // interpolować brakujące odczyty, a my chcemy zapisać to, co licznik
            // naprawdę zmierzył. Zmyślony punkt to zmyślone pole na mapie.
            $fit = new \adriangibbons\phpFITFileAnalysis($absolutePath, ['units' => 'metric']);
        } catch (\Throwable $e) {
            // Komunikat biblioteki bywa techniczny („not a FIT file", CRC) —
            // wywołujący i tak pokazuje własny tekst, a ten idzie do logu.
            throw new \RuntimeException('Nie udało się odczytać pliku FIT: ' . $e->getMessage());
        }

        $records = $fit->data_mesgs['record'] ?? [];
        $lats = $records['position_lat'] ?? null;
        $lons = $records['position_long'] ?? null;
        if (!is_array($lats) || !is_array($lons)) {
            // FIT z trenażera albo z siłowni — nagranie jest, ale bez GPS-u.
            // To NIE jest uszkodzony plik i komunikat ma to rozróżniać.
            throw new \RuntimeException(__('Ten plik FIT nie ma zapisanej trasy (nagranie bez GPS).'));
        }

        // Klucze tablic to znaczniki czasu (sekundy uniksowe) — biblioteka
        // indeksuje nimi WSZYSTKIE serie, więc pozycja, wysokość i czas
        // zestawiają się po tym samym kluczu, bez zgadywania kolejności.
        $altitudes = $records['altitude'] ?? [];
        $punkty = [];
        foreach ($lats as $ts => $lat) {
            if (!isset($lons[$ts])) {
                continue;
            }
            $lat = (float) $lat;
            $lon = (float) $lons[$ts];
            // Licznik, który jeszcze nie złapał fixa, zapisuje pozycję „zerową"
            // albo wartość spoza zakresu — taki punkt przesunąłby ślad na
            // Zatokę Gwinejską i zamalował po drodze pół Afryki.
            if ($lat === 0.0 && $lon === 0.0) {
                continue;
            }
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                continue;
            }
            $punkty[] = [
                'lat' => $lat,
                'lon' => $lon,
                'ele' => isset($altitudes[$ts]) ? (float) $altitudes[$ts] : null,
                'ts'  => (int) $ts,
            ];
        }

        if (count($punkty) < self::MIN_POINTS) {
            throw new \RuntimeException(__('Ten plik FIT nie ma zapisanej trasy (nagranie bez GPS).'));
        }

        return self::buildGpx($punkty, $trackName);
    }

    /**
     * To samo, ale dla treści trzymanej w pamięci — pliki pobrane z API
     * liczników (Wahoo, COROS, Suunto oddają wyłącznie FIT).
     *
     * Biblioteka czyta z DYSKU, więc zapisujemy plik tymczasowy i kasujemy go
     * niezależnie od wyniku (`finally`): katalog uploadów nie może zbierać
     * śmieci po nieudanych konwersjach.
     */
    public static function toGpxFromString(string $bytes, ?string $trackName = null): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ridemore_fit_');
        if ($tmp === false) {
            throw new \RuntimeException(__('Brak miejsca na plik tymczasowy.'));
        }

        try {
            file_put_contents($tmp, $bytes);
            return self::toGpx($tmp, $trackName);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * GPX 1.1 z listy punktów — od 2026-09-03 składa go `Utils\Gpx::fromPoints()`,
     * bo ten sam XML potrzebny jest też przy pobieraniu przejazdu ze strony
     * `/przejazd/{id}`. Tu zostaje sam podpis twórcy, żeby po pliku dało się
     * poznać, że przyszedł z licznika przez konwersję FIT.
     *
     * @param list<array{lat:float,lon:float,ele:?float,ts:int}> $punkty
     */
    private static function buildGpx(array $punkty, ?string $trackName): string
    {
        return Gpx::fromPoints($punkty, $trackName, 'ridemore.bike (FIT)');
    }
}
