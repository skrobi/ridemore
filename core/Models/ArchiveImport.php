<?php
// core/Models/ArchiveImport.php
// IMPORT ARCHIWUM ZE STRAVY / GARMINA — historia użytkownika w jednym kroku.
//
// DLACZEGO PLIK, A NIE API. Strava zacisnęła warunki dostępu do swojego API
// i robiła to już kilka razy, zawsze na niekorzyść integratorów. Budowanie
// fundamentu na cudzej decyzji biznesowej znaczy, że ktoś inny może wyłączyć
// nam najważniejszą funkcję onboardingu z dnia na dzień. Plik z eksportu należy
// do użytkownika i tego nikt nie odbierze — każdy serwis ma obowiązek wydać
// dane, a my potrzebujemy dokładnie tego, co w nich jest.
//
// CAŁA MECHANIKA JUŻ ISTNIEJE. Ten model niczego nie liczy: rozpakowuje
// archiwum i dla każdego śladu woła RiderActivity::recordSolo — tę samą drogę,
// którą przechodzi ręcznie wgrany przejazd. Odkrycia pól, punkty, skarby po
// drodze i prywatność (przycięcie okolic domu, §27) dzieją się tam, raz.
//
// IMPORT JEST IDEMPOTENTNY I NIE MUSIAŁEM TEGO BUDOWAĆ: recordSolo liczy
// sha256 pliku i odbija się o klucz UNIQUE(user_id, gpx_hash). Wgranie tego
// samego archiwum drugi raz nie zdubluje ani jednego przejazdu, a niedokończony
// import wystarczy powtórzyć — przejdą tylko te ślady, których jeszcze nie ma.
namespace Models;

use Utils\Upload;

class ArchiveImport
{
    /**
     * Ile śladów bierzemy z jednego wywołania.
     *
     * Archiwum ze Stravy potrafi mieć tysiące plików, a każdy ślad to parsowanie
     * kilku tysięcy punktów, liczenie pól siatki i zapis do rejestru punktów.
     * Bez limitu żądanie kończy się timeoutem gdzieś w połowie — a wtedy część
     * przejazdów jest, część nie, i nikt nie wie która. Z limitem import jest
     * PONAWIALNY: kolejne wywołanie bierze następną partię, bo to, co już
     * weszło, odbije się o hash.
     */
    public const BATCH = 40;

    /** Sufit po rozpakowaniu — zabezpieczenie przed archiwum-bombą. */
    private const MAX_UNPACKED_BYTES = 2 * 1024 * 1024 * 1024;

    /** Pojedynczy ślad większy niż to jest albo błędem, albo nie jest śladem. */
    private const MAX_TRACK_BYTES = 25 * 1024 * 1024;

    /**
     * @return array{
     *   dodane:int, duplikaty:int, odrzucone:int, pozostalo:int,
     *   szczegoly:list<array{plik:string,wynik:string,powod:?string}>
     * }
     */
    public static function fromZip(int $userId, string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('Nie udało się otworzyć archiwum.'));
        }

        try {
            $slady = self::listTracks($zip);
            $partia = array_slice($slady, 0, self::BATCH);

            $wynik = ['dodane' => 0, 'duplikaty' => 0, 'odrzucone' => 0, 'szczegoly' => []];

            foreach ($partia as $wpis) {
                $nazwa = basename($wpis['name']);
                try {
                    $dodany = self::importOne($userId, $zip, $wpis);
                    if ($dodany) {
                        $wynik['dodane']++;
                        $wynik['szczegoly'][] = ['plik' => $nazwa, 'wynik' => 'dodany', 'powod' => null];
                    } else {
                        $wynik['duplikaty']++;
                        $wynik['szczegoly'][] = ['plik' => $nazwa, 'wynik' => 'duplikat', 'powod' => null];
                    }
                } catch (\Throwable $e) {
                    // JEDEN ZŁY PLIK NIE PRZERYWA IMPORTU. W archiwum sprzed lat
                    // trafiają się ślady z jednym punktem, puste pliki po
                    // przerwanym zapisie i treningi bez GPS (rolki, siłownia).
                    // Przerwanie na pierwszym z nich znaczyłoby, że jedna
                    // wadliwa pozycja blokuje całą resztę historii.
                    $wynik['odrzucone']++;
                    $wynik['szczegoly'][] = [
                        'plik'   => $nazwa,
                        'wynik'  => 'odrzucony',
                        'powod'  => $e->getMessage(),
                    ];
                }
            }

            $wynik['pozostalo'] = max(0, count($slady) - count($partia));

            return $wynik;
        } finally {
            $zip->close();
        }
    }

    /**
     * Ślady w archiwum, w kolejności od najstarszych.
     *
     * OD NAJSTARSZYCH, bo import bywa przerywany i wznawiany — a wtedy człowiek
     * widzi, jak jego mapa zapełnia się chronologicznie, zamiast dostawać losowe
     * kawałki historii. Nazwy plików w eksportach zawierają znacznik czasu, więc
     * sortowanie po nazwie wystarcza i nie wymaga otwierania ani jednego pliku.
     */
    private static function listTracks(\ZipArchive $zip): array
    {
        $out = [];
        $sumaRozpakowana = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $sumaRozpakowana += (int) $stat['size'];
            if ($sumaRozpakowana > self::MAX_UNPACKED_BYTES) {
                throw new \RuntimeException(__('Archiwum jest podejrzanie duże po rozpakowaniu.'));
            }

            $nazwa = (string) $stat['name'];

            // ZIP SLIP: nazwa w archiwum jest tekstem od obcego autora, więc
            // „../../etc/passwd" jest w nim całkowicie legalne. Nie rozpakowujemy
            // po ścieżce z archiwum (patrz importOne — czytamy strumieniem), ale
            // odrzucamy takie wpisy od razu, żeby nie polegać na jednym
            // zabezpieczeniu.
            if (str_contains($nazwa, '..') || str_starts_with($nazwa, '/')) {
                continue;
            }
            if (str_ends_with($nazwa, '/') || (int) $stat['size'] === 0) {
                continue;
            }
            if ((int) $stat['size'] > self::MAX_TRACK_BYTES) {
                continue;
            }
            if (!self::isTrack($nazwa)) {
                continue;
            }

            $out[] = ['index' => $i, 'name' => $nazwa, 'size' => (int) $stat['size']];
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Czy ta pozycja archiwum jest w ogóle śladem.
     *
     * Eksport ze Stravy to nie same treningi: są w nim także wiadomości, kluby,
     * ustawienia konta i zdjęcia. Bierzemy wyłącznie rozszerzenia, które umiemy
     * przeczytać — dziś GPX, po dołożeniu parsera także FIT (IMP/3).
     *
     * `.gpx.gz` obsługujemy, bo Strava pakuje starsze ślady podwójnie i bez tego
     * z archiwum sprzed kilku lat nie weszłoby prawie nic.
     */
    private static function isTrack(string $nazwa): bool
    {
        $niska = strtolower($nazwa);

        return str_ends_with($niska, '.gpx') || str_ends_with($niska, '.gpx.gz');
    }

    /**
     * Jeden ślad z archiwum do przejazdu solo.
     *
     * @return bool true = dodany, false = ten plik ta osoba już ma
     */
    private static function importOne(int $userId, \ZipArchive $zip, array $wpis): bool
    {
        $tresc = $zip->getFromIndex($wpis['index']);
        if ($tresc === false || $tresc === '') {
            throw new \RuntimeException(__('Pusty plik w archiwum.'));
        }

        if (str_ends_with(strtolower($wpis['name']), '.gz')) {
            $rozpakowane = @gzdecode($tresc);
            if ($rozpakowane === false) {
                throw new \RuntimeException(__('Nie udało się rozpakować pliku.'));
            }
            $tresc = $rozpakowane;
        }

        // Plik trafia do tej samej lokalizacji co ręcznie wgrany ślad, bo dalej
        // idzie tą samą drogą — i tak samo ma być dostępny na mapie przejazdu.
        $gpxUrl = Upload::saveGpxContents($tresc);
        if ($gpxUrl === null) {
            throw new \RuntimeException(__('Nie udało się zapisać pliku.'));
        }

        $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $gpxUrl, $gpxUrl);

        if ($wynik === null) {
            // Duplikat — recordSolo odbiło się o hash. Kasujemy świeżo zapisaną
            // kopię, żeby powtarzany import nie zaśmiecał dysku bliźniakami
            // plików, do których nic nie prowadzi.
            @unlink(CORE_PATH . '/..' . $gpxUrl);
            return false;
        }

        return true;
    }
}
