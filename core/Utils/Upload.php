<?php
// core/Utils/Upload.php
namespace Utils;

class Upload
{
    private const MAX_GPX_SIZE    = 20 * 1024 * 1024; // 20MB
    private const MAX_COVER_SIZE  = 8 * 1024 * 1024;  // 8MB
    private const MAX_AVATAR_SIZE = 4 * 1024 * 1024;  // 4MB
    private const COVER_MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    // Maksymalny dłuższy bok po przeskalowaniu — dobrany pod NAJWIĘKSZY
    // kontekst wyświetlania danego typu zdjęcia (patrz CSS): avatar renderuje
    // się maksymalnie jako koło 108px (.org-avatar-big), okładka eventu jako
    // pas 260px wysokości na szerokości kontenera (.photo-block), a galeria/
    // zdjęcia hero organizatora jako kafelki 70-150px (.org-cover/.oc-cover/
    // .hero-photo-thumb). Z zapasem pod ekrany retina/HiDPI, bez dążenia do
    // wielu wariantów rozmiaru na jeden upload — jeden, odpowiednio duży plik.
    private const MAX_AVATAR_DIMENSION  = 400;
    private const MAX_COVER_DIMENSION   = 1600;
    private const MAX_GALLERY_DIMENSION = 1200;

    /**
     * Ile zdjęć przyjmujemy za jednym wysłaniem galerii.
     *
     * PUBLICZNA, bo formularz musi napisać tę liczbę użytkownikowi. Wpisana tam
     * z palca rozjechałaby się z tą przy pierwszej zmianie, a objawem byłoby
     * ciche ucinanie nadmiaru: user czyta „do 5", wysyła 8 i nie wie, czemu trzy
     * zniknęły.
     */
    public const GALLERY_MAX = 5;

    private static function uploadsDir(string $sub): string
    {
        $dir = CORE_PATH . '/../assets/uploads/' . $sub;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function randomToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * ŚLAD Z LICZNIKA — GPX ALBO FIT (2026-08-23).
     *
     * FIT to format, w którym nagrywa każdy licznik rowerowy (i jedyny, jaki
     * oddają API Wahoo, COROS i Suunto). Konwertujemy go NA WEJŚCIU do GPX-a
     * (`Utils\Fit`), więc dalej cały serwis widzi dokładnie to samo co zawsze:
     * jeden format na dysku, jeden parser, jeden komplet liczb.
     *
     * Osobna metoda, a nie rozszerzenie `saveGpxTemp()`: tamta obsługuje też
     * GPX-y TRAS (kreator wydarzenia, planowany przebieg), gdzie plik z licznika
     * nie ma czego szukać, a zmiana zachowania dotknęłaby ekranów spoza tego
     * zadania.
     *
     * `move_uploaded_file` zostaje w obu gałęziach — to ono odróżnia plik
     * przysłany formularzem od dowolnej ścieżki podanej przez żądanie.
     */
    public static function saveTrackTemp(array $file): string
    {
        if (!Fit::looksLikeFit((string) $file['name'])) {
            return self::saveGpxTemp($file);
        }

        self::assertUploadOk($file, self::MAX_GPX_SIZE);
        $token = self::randomToken();
        $fitPath = self::uploadsDir('gpx/tmp') . '/' . $token . '.fit';
        if (!move_uploaded_file($file['tmp_name'], $fitPath)) {
            throw new \RuntimeException(__('Nie udało się zapisać pliku FIT'));
        }

        try {
            $gpx = Fit::toGpx($fitPath, pathinfo((string) $file['name'], PATHINFO_FILENAME));
        } finally {
            // FIT jest nam potrzebny wyłącznie na czas konwersji. Zostawiony
            // w katalogu tymczasowym nie miałby kto posprzątać: cleanup patrzy
            // na *.gpx.
            @unlink($fitPath);
        }

        file_put_contents(self::uploadsDir('gpx/tmp') . '/' . $token . '.gpx', $gpx);

        return $token;
    }

    // Zapisuje surowy GPX do katalogu tymczasowego i zwraca token (do ukrytego
    // pola formularza) — plik trafia na docelowe miejsce dopiero w promoteGpxTemp(),
    // przy finalnym zapisie wydarzenia. Unika ponownego wysyłania tego samego pliku.
    public static function saveGpxTemp(array $file): string
    {
        self::assertUploadOk($file, self::MAX_GPX_SIZE);
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'gpx') {
            throw new \RuntimeException(__('Dozwolone są tylko pliki .gpx'));
        }

        $token = self::randomToken();
        $dest  = self::uploadsDir('gpx/tmp') . '/' . $token . '.gpx';
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException(__('Nie udało się zapisać pliku GPX'));
        }
        return $token;
    }

    /**
     * Zapis GPX-a, ktory NIE przyszedl formularzem — z archiwum (Models\ArchiveImport).
     *
     * Osobna metoda, bo saveGpxTemp opiera sie na move_uploaded_file, a ta
     * funkcja z zalozenia odrzuca wszystko, co nie przyszlo w $_FILES. To dobre
     * zabezpieczenie i nie chcemy go obchodzic — wiec import ma wlasne wejscie,
     * ktore przyjmuje TRESC, nie sciezke, i nigdy nie dotyka danych z zewnatrz
     * inaczej niz przez ten jeden punkt.
     *
     * Rozmiar sprawdzamy tak samo jak przy uploadzie: plik z archiwum jest
     * dokladnie tak samo obcy jak plik z formularza.
     */
    public static function saveGpxContents(string $contents): ?string
    {
        if ($contents === '' || strlen($contents) > self::MAX_GPX_SIZE) {
            return null;
        }

        $finalName = self::randomToken() . '.gpx';
        $dest = self::uploadsDir('gpx') . '/' . $finalName;

        return file_put_contents($dest, $contents) !== false
            ? '/assets/uploads/gpx/' . $finalName
            : null;
    }

    /**
     * Ścieżka na dysku do GPX-a czekającego w katalogu tymczasowym.
     *
     * Potrzebna tam, gdzie plik trzeba SPRAWDZIĆ, zanim wyląduje na docelowym
     * miejscu — nieudana walidacja PO promocji zostawia w publicznym katalogu
     * plik, do którego nic już nie prowadzi, i nie sprząta go nawet
     * cleanupOrphanedGpxTemp() (ten patrzy wyłącznie na gpx/tmp).
     *
     * Zwraca null dla tokenu spoza formatu albo pliku, którego już nie ma —
     * dokładnie te same dwa warunki co promoteGpxTemp().
     */
    public static function gpxTempPath(?string $token): ?string
    {
        if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $path = self::uploadsDir('gpx/tmp') . '/' . $token . '.gpx';
        return is_file($path) ? $path : null;
    }

    // Przenosi plik z katalogu tymczasowego (token z formularza) do docelowej,
    // publicznej lokalizacji. Zwraca ścieżkę bez base_path (dokleja Utils\View::url()),
    // albo null gdy token jest pusty/nieprawidłowy/plik już nie istnieje.
    public static function promoteGpxTemp(?string $token): ?string
    {
        if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $src = self::uploadsDir('gpx/tmp') . '/' . $token . '.gpx';
        if (!is_file($src)) {
            return null;
        }
        $finalName = self::randomToken() . '.gpx';
        $dest = self::uploadsDir('gpx') . '/' . $finalName;
        if (!rename($src, $dest)) {
            return null;
        }
        return '/assets/uploads/gpx/' . $finalName;
    }

    // Porządek po porzuconych formularzach: użytkownik wgrywa GPX (trafia do
    // gpx/tmp, patrz saveGpxTemp()), ale nigdy nie dokańcza zapisu eventu —
    // promoteGpxTemp() wtedy nigdy nie zostaje wywołane i plik zostaje tam na
    // zawsze. Wywoływane z cron.php; zwraca liczbę usuniętych plików.
    public static function cleanupOrphanedGpxTemp(int $olderThanSeconds = 86400): int
    {
        $dir = self::uploadsDir('gpx/tmp');
        $cutoff = time() - $olderThanSeconds;
        $removed = 0;
        foreach (glob($dir . '/*.gpx') ?: [] as $path) {
            if ((@filemtime($path) ?: 0) < $cutoff && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    // Zdjęcie okładki — pole opcjonalne, zwraca null gdy nic nie wybrano.
    public static function saveCoverPhoto(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return self::saveImage($file, 'covers', self::MAX_COVER_SIZE, self::MAX_COVER_DIMENSION);
    }

    // Avatar/logo organizatora — pojedynczy plik, mniejszy limit niż okładka
    // eventu (rzadko potrzeba dużej rozdzielczości na kółku 108px).
    public static function saveAvatar(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return self::saveImage($file, 'avatars', self::MAX_AVATAR_SIZE, self::MAX_AVATAR_DIMENSION);
    }

    // Wspólny rdzeń save{CoverPhoto,Avatar,GalleryPhotos}() — walidacja
    // MIME/rozmiar, ewentualny resize, zapis pod losową nazwą. Trzy publiczne
    // metody różniły się wcześniej tylko katalogiem/limitami, ze skopiowanym
    // 1:1 resztą ciała.
    private static function saveImage(array $file, string $dir, int $maxSize, int $maxDimension): string
    {
        self::assertUploadOk($file, $maxSize);

        $mime = mime_content_type($file['tmp_name']);
        if (!isset(self::COVER_MIME_EXT[$mime])) {
            throw new \RuntimeException(__('Dozwolone są tylko obrazy JPEG, PNG lub WebP'));
        }
        self::resizeIfOversized($file['tmp_name'], $mime, $maxDimension);

        $filename = self::randomToken() . '.' . self::COVER_MIME_EXT[$mime];
        $dest = self::uploadsDir($dir) . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException(__('Nie udało się zapisać zdjęcia'));
        }
        return '/assets/uploads/' . $dir . '/' . $filename;
    }

    // Zdjęcia do opinii/relacji/hero profilu organizatora — pierwszy przypadek
    // wielo-plikowego uploadu w tej apce (dotąd wszystko było pojedyncze).
    // $filesInput to surowy $_FILES['...'] w kształcie PHP (name[]/tmp_name[]/
    // error[]/size[] jako równoległe tablice) — reshape'ujemy do pojedynczych
    // plików i reużywamy tę samą walidację MIME/rozmiar co saveCoverPhoto().
    // Nadmiarowe pliki powyżej $max są po prostu pomijane, nie blokują reszty
    // zapisu. $dir wybiera podkatalog docelowy (różne wywołania trzymają swoje
    // pliki osobno: 'gallery' dla opinii/relacji, 'organizer-covers' dla hero).
    /**
     * @param string[] $errors WYJŚCIOWY — powód odrzucenia każdego pominiętego
     *        pliku. Przez referencję, żeby nie zmieniać kształtu zwracanej
     *        wartości i nie ruszać pozostałych wołających (ReviewController,
     *        OrganizerProfileFormInput).
     */
    public static function saveGalleryPhotos(
        array $filesInput,
        int $max = self::GALLERY_MAX,
        string $dir = 'gallery',
        array &$errors = []
    ): array {
        $errors = [];
        if (empty($filesInput['name']) || !is_array($filesInput['name'])) {
            return [];
        }

        $urls = [];
        foreach ($filesInput['name'] as $i => $name) {
            if (count($urls) >= $max) {
                $errors[] = htmlspecialchars((string) $name) . ' — limit ' . $max . ' zdjęć na raz';
                continue;
            }
            $error = $filesInput['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $file = [
                'name'     => $name,
                'type'     => $filesInput['type'][$i] ?? '',
                'tmp_name' => $filesInput['tmp_name'][$i] ?? '',
                'error'    => $error,
                'size'     => $filesInput['size'][$i] ?? 0,
            ];

            // JEDEN ZŁY PLIK NIE MOŻE ZABRAĆ POZOSTAŁYCH (błąd zgłoszony
            // 2026-08-14: wpis z czterema zdjęciami zapisał się bez żadnego).
            //
            // Wcześniej saveImage() rzucał wyjątkiem, co przerywało CAŁĄ pętlę,
            // a wołający łapał go na zewnątrz i wyrzucał do kosza także adresy
            // plików JUŻ PRZENIESIONYCH na dysk. Efekt: pliki leżały
            // w assets/uploads/gallery, w bazie nie było ani jednego wiersza,
            // a użytkownik nie dostawał żadnego komunikatu. Zmierzone na
            // zgłoszonym przypadku: 2 osierocone pliki na dysku, 0 wierszy.
            //
            // Teraz pętla idzie dalej, a powód odrzucenia wraca do wołającego,
            // żeby miał co pokazać. Zapisujemy tyle, ile się da — to jest
            // ta sama zasada, którą kieruje się wołający: relacja jest już
            // zapisana i błąd zdjęcia nie może jej cofnąć.
            try {
                $urls[] = self::saveImage($file, $dir, self::MAX_COVER_SIZE, self::MAX_GALLERY_DIMENSION);
            } catch (\Throwable $e) {
                $errors[] = htmlspecialchars((string) $name) . ' — ' . $e->getMessage();
            }
        }

        return $urls;
    }

    // Skaluje w miejscu (nadpisuje $tmpName) TYLKO gdy dłuższy bok przekracza
    // $maxDimension — mniejsze zdjęcia zostają nietknięte, żeby nie tracić
    // jakości na podwójnej kompresji JPEG czegoś, co już jest małe. GD (bez
    // Imagick/composer — brak takiej zależności gdziekolwiek w projekcie).
    // Cicho pomija (no-op) przy nieobsłużonym MIME albo błędzie samego GD —
    // walidacja formatu i tak już przeszła w assertUploadOk()/MIME-check
    // wywołującego; awaria samej kompresji nie może wywalić całego uploadu.
    // Zwraca bool — czy faktycznie przeskalowano (patrz resizeExistingFile()
    // niżej, korzysta z tego do liczenia postępu w backfillu).
    private static function resizeIfOversized(string $tmpName, string $mime, int $maxDimension): bool
    {
        $create = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png'  => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default      => null,
        };
        if ($create === null || !function_exists($create)) {
            return false;
        }

        $size = @getimagesize($tmpName);
        if (!$size || max($size[0], $size[1]) <= $maxDimension) {
            return false;
        }

        $src = @$create($tmpName);
        if (!$src) {
            return false;
        }

        $ratio     = $maxDimension / max($size[0], $size[1]);
        $newWidth  = max(1, (int) round($size[0] * $ratio));
        $newHeight = max(1, (int) round($size[1] * $ratio));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime === 'image/png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $size[0], $size[1]);

        match ($mime) {
            'image/jpeg' => imagejpeg($dst, $tmpName, 82),
            'image/png'  => imagepng($dst, $tmpName, 6),
            'image/webp' => imagewebp($dst, $tmpName, 82),
        };

        imagedestroy($src);
        imagedestroy($dst);
        return true;
    }

    // Wariant resizeIfOversized() dla PLIKU JUŻ LEŻĄCEGO na dysku (nie
    // świeżo wgranego przez $_FILES) — pod backfill_image_sizes.php, żeby
    // skalowanie istniejących, sprzed tej logiki zapisanych zdjęć używało
    // DOKŁADNIE tego samego algorytmu co nowe uploady, nie kopii. Zwraca
    // bool: true = faktycznie przeskalowano, false = plik nie istniał, miał
    // nieobsługiwany MIME, albo już mieścił się w limicie.
    public static function resizeExistingFile(string $absolutePath, int $maxDimension): bool
    {
        if (!is_file($absolutePath)) {
            return false;
        }
        $mime = @mime_content_type($absolutePath);
        if (!$mime || !isset(self::COVER_MIME_EXT[$mime])) {
            return false;
        }
        return self::resizeIfOversized($absolutePath, $mime, $maxDimension);
    }

    public static function avatarMaxDimension(): int { return self::MAX_AVATAR_DIMENSION; }
    public static function coverMaxDimension(): int { return self::MAX_COVER_DIMENSION; }
    public static function galleryMaxDimension(): int { return self::MAX_GALLERY_DIMENSION; }

    private const MAX_FETCH_BYTES = 15 * 1024 * 1024; // 15MB — hojne, i tak zeskalujemy do MAX_COVER_DIMENSION

    // Zapisuje okładkę eventu z ZEWNĘTRZNEGO linku zamiast $_FILES — dodane
    // 2026-08-09: rozszerzenie Chrome ("Importer eventów") wcześniej pobierało
    // zdjęcie SAMO w przeglądarce i wstrzykiwało jako plik do formularza, co
    // przy dużych zdjęciach robiło żądanie POST na tyle duże, że firewall
    // hostingu (WAF) blokował je 403-ką ZANIM dotarło do aplikacji (zgłoszenie
    // usera, potwierdzone żywo). Rozwiązanie: przeglądarka wysyła sam LINK
    // (kilkadziesiąt znaków, formularz zawsze mały), a POBIERANIE dzieje się
    // tutaj, po stronie serwera — ten sam serwer, który i tak by odebrał plik,
    // tylko inny kierunek transferu.
    //
    // UWAGA BEZPIECZEŃSTWA: ten formularz jest dostępny też dla niezalogowanych
    // gości (patrz EventController::createForm — "Zgłoś wydarzenie" bez
    // konta), więc to serwer PUBLICZNIE ściągający DOWOLNY podany URL —
    // klasyczna okazja do SSRF (ktoś każe nam odpytać 169.254.169.254 albo
    // localhost:xxxx zamiast prawdziwego zdjęcia). Warstwy ochrony:
    // 1) tylko http(s) (walidacja przez FILTER_VALIDATE_URL),
    // 2) hostname rozwiązywany na IP i sprawdzany przez
    //    FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE (odrzuca
    //    loopback/prywatne/link-local — w tym adresy metadanych chmury —
    //    dla IPv4 I IPv6 na raz, bez ręcznego wypisywania zakresów CIDR),
    // 3) przekierowania WYŁĄCZONE (follow_location=0) — inaczej "bezpieczny"
    //    publiczny URL mógłby przekierować na coś wewnętrznego, omijając
    //    punkt 2 (walidujemy tylko host z ORYGINALNEGO adresu),
    // 4) limit czasu i limit rozmiaru pobierania (strumieniowo, nie
    //    file_get_contents całości na raz),
    // 5) zawartość zweryfikowana jako PRAWDZIWY obraz (getimagesizefromstring)
    //    dopiero PO pobraniu — nie ufamy Content-Type z odpowiedzi ani
    //    rozszerzeniu w URL-u.
    // Świadomie NIE chronione: DNS rebinding (host rozwiązuje się bezpiecznie
    // w kroku 2, ale mogłby wskazywać gdzie indziej w kroku pobierania) —
    // wymagałoby ręcznego pinowania IP przez cURL (CURLOPT_RESOLVE), pominięte
    // jako nieproporcjonalne do realnego ryzyka dla tego serwisu; do
    // ewentualnego dociążenia, jeśli kiedyś się to okaże realnym wektorem.
    // Rate-limiting per IP — patrz wywołujący (Resources\EventFormInput).
    public static function saveCoverPhotoFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException(__('Nieprawidłowy link do zdjęcia.'));
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            throw new \RuntimeException(__('Nieprawidłowy link do zdjęcia.'));
        }
        self::assertHostIsPublic($host);

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 10,
                'follow_location' => 0, // patrz punkt 3 wyżej — przekierowania celowo wyłączone
                'header'          => "User-Agent: ridemore.bike CoverPhotoFetcher/1.0\r\n",
                'ignore_errors'   => true, // żeby dostać $http_response_header nawet przy 4xx/5xx zamiast ostrzeżenia
            ],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream) {
            throw new \RuntimeException(__('Nie udało się pobrać zdjęcia spod podanego linku.'));
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) || (int) $m[1] < 200 || (int) $m[1] >= 300) {
            fclose($stream);
            throw new \RuntimeException(__('Serwer źródłowy zwrócił błąd przy pobieraniu zdjęcia (') . ($m[1] ?? '?') . ').');
        }

        $data = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
            if (strlen($data) > self::MAX_FETCH_BYTES) {
                fclose($stream);
                throw new \RuntimeException('Zdjęcie pod podanym linkiem jest zbyt duże (max ' . round(self::MAX_FETCH_BYTES / 1024 / 1024) . ' MB).');
            }
        }
        fclose($stream);

        $imageInfo = @getimagesizefromstring($data);
        if (!$imageInfo || !isset(self::COVER_MIME_EXT[$imageInfo['mime']])) {
            throw new \RuntimeException(__('Podany link nie prowadzi do prawidłowego obrazu JPEG, PNG ani WebP.'));
        }
        $mime = $imageInfo['mime'];

        // Zapis WPROST do katalogu docelowego — bez sys_get_temp_dir()+rename()
        // (poprawka 2026-08-09, zgłoszenie usera: wpis w bazie był, pliku na
        // serwerze nie). Pierwsza wersja pisała do systemowego katalogu
        // tymczasowego i przenosiła plik przez rename(). Na Linuksie (hosting)
        // /tmp bywa OSOBNYM systemem plików, a rename() między systemami
        // plików zawodzi ("Invalid cross-device link") — na Windowsie (dev)
        // oba są na tym samym dysku, więc lokalnie działało bezbłędnie.
        // Klasyczny błąd przenośności: żaden inny upload w tej klasie tak nie
        // robi (saveImage() używa move_uploaded_file wprost do katalogu
        // docelowego), więc ta ścieżka była jedyną narażoną.
        $dir = self::uploadsDir('covers');
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException(__('Katalog na zdjęcia jest niedostępny do zapisu na serwerze.'));
        }
        $filename = self::randomToken() . '.' . self::COVER_MIME_EXT[$mime];
        $dest = $dir . '/' . $filename;
        if (file_put_contents($dest, $data) === false) {
            throw new \RuntimeException(__('Nie udało się zapisać pobranego zdjęcia na serwerze.'));
        }
        self::resizeIfOversized($dest, $mime, self::MAX_COVER_DIMENSION);

        // Twarda weryfikacja PRZED zwróceniem ścieżki — bez tego dowolna cicha
        // awaria zapisu/skalowania kończyła się wpisem w bazie wskazującym na
        // nieistniejący plik (dokładnie objaw zgłoszony przez usera: "jest w
        // cover, ale nie ma go w plikach"). Lepiej błąd niż martwy odnośnik.
        if (!is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);
            throw new \RuntimeException(__('Zapis zdjęcia na serwerze nie powiódł się.'));
        }
        return '/assets/uploads/covers/' . $filename;
    }

    // Rozwiązuje hostname na WSZYSTKIE jego adresy IP (A + AAAA) i odrzuca,
    // jeśli KTÓRYKOLWIEK jest prywatny/zastrzeżony/loopback/link-local —
    // patrz komentarz bezpieczeństwa przy saveCoverPhotoFromUrl() wyżej.
    private static function assertHostIsPublic(string $host): void
    {
        // Host bywa już literalnym adresem IP (np. http://127.0.0.1/...) —
        // gethostbyname()/dns_get_record() na czymś, co nie jest prawdziwą
        // nazwą hosta, zwraca ten sam string albo nic (myląco wygląda jak
        // "nie udało się rozwiązać", a nie jak realne, poprawne odrzucenie).
        // Sprawdzamy to wprost przed próbą rozwiązywania.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException(__('Ten link nie jest dozwolony.'));
            }
            return;
        }
        $ips = [];
        foreach (['A' => 'ip', 'AAAA' => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, constant("DNS_$type")) ?: [];
            foreach ($records as $r) {
                if (!empty($r[$field])) {
                    $ips[] = $r[$field];
                }
            }
        }
        if (!$ips) {
            // dns_get_record() bywa zawodne na niektórych hostingach — fallback
            // na starszy, prostszy resolver (tylko IPv4).
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                $ips[] = $ip;
            }
        }
        if (!$ips) {
            throw new \RuntimeException(__('Nie udało się rozwiązać adresu podanego linku.'));
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException(__('Ten link nie jest dozwolony.'));
            }
        }
    }

    private static function assertUploadOk(array $file, int $maxSize): void
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException(__('Nieprawidłowy przesłany plik'));
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Błąd przesyłania pliku (kod ' . $file['error'] . ')');
        }
        if ($file['size'] > $maxSize) {
            throw new \RuntimeException('Plik zbyt duży (max ' . round($maxSize / 1024 / 1024) . ' MB)');
        }
    }
}
