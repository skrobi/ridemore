<?php
// core/Controllers/Admin/KnownRouteController.php
// Panel znanych tras (Etap 8, §10) — Velo Czorsztyn, Green Velo, Szlak Orlich
// Gniazd i tak dalej.
//
// Cały sens tego ekranu: §10 mówi wprost „nie należy hardcodować tych tras w
// kodzie". Dodanie kolejnego szlaku ma być wgraniem pliku GPX przez człowieka,
// a nie wdrożeniem. Wyłącznie dla adminów — to globalne dane referencyjne
// serwisu, jak taksonomia, a nie samoobsługowy formularz organizatora.
//
// EDYCJA (2026-08-19). Do tej pory trasy dało się wyłącznie dodać, wyłączyć,
// podmienić jej zdjęcie i skasować — literówka w nazwie albo źle wgrany plik
// oznaczały skasowanie trasy i dodanie jej od nowa, czyli utratę postępu
// wszystkich uczestników (nowe id = progi w rejestrze wskazujące na nieistniejący
// byt) i martwy adres /trasy/{slug}. `update()` zmienia dane trasy w miejscu,
// a podmiana przebiegu (KnownRoute::replaceGpx) zachowuje id i slug.
//
// EKRAN JEST LISTĄ, NIE STOSEM FORMULARZY (poprawka tego samego dnia, pytanie
// usera „a jak będę miał 200 tras?"). Pierwsza wersja edycji siedziała
// w rozwijanym `<details>` przy każdym wierszu — przy dwustu trasach to dwieście
// formularzy w jednym dokumencie, każdy z pełną listą regionów, i żadnego
// sposobu, żeby znaleźć konkretną trasę. Teraz: lista ze szukaniem, filtrami,
// sortowaniem i stronicowaniem (ten sam wzorzec co `UsersController`), a każdy
// formularz na WŁASNEJ podstronie.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Csrf;
use Models\Emblem;
use Models\KnownRoute;
use Models\TileCache;
use Models\TileSource;
use Utils\Gpx;
use Utils\Upload;
use Utils\View;

class KnownRouteController
{
    private const BLEDY = [
        'sesja'       => 'Sesja wygasła — formularz jest wypełniony, wyślij go jeszcze raz.',
        'brak_pliku'  => 'Wybierz plik GPX z przebiegiem trasy.',
        'brak_nazwy'  => 'Podaj nazwę trasy.',
        'zly_plik'    => 'Nie udało się odczytać pliku GPX.',
        'zle-zdjecie' => 'Nie udało się wgrać zdjęcia — dozwolone są pliki JPG i PNG.',
        'brak_trasy'  => 'Nie ma takiej trasy.',
        'brak_gpx'    => 'Ta trasa nie ma zapisanego pliku GPX — wgraj plik w formularzu edycji.',
    ];

    public static function index(): void
    {
        $wynik = KnownRoute::search([
            'szukaj' => $_GET['q'] ?? '',
            'filtr'  => $_GET['filtr'] ?? '',
            'sort'   => $_GET['sort'] ?? '',
            'strona' => (int) ($_GET['strona'] ?? 1),
        ]);

        View::render('web', 'known-routes-admin', [
            'title'     => 'Znane trasy — ridemore.bike',
            'noindex'   => true,
            'wynik'     => $wynik,
            'liczniki'  => KnownRoute::counters(),
            // Ostrzeżenie „nie rysuje się na mapie" liczone TYLKO dla widocznej
            // strony — przy dwustu trasach nie ma powodu pytać o wszystkie.
            'unordered' => KnownRoute::unorderedCounts(array_map(
                static fn(array $r): int => (int) $r['id'],
                $wynik['items']
            )),
            'error'     => self::BLEDY[$_GET['blad'] ?? ''] ?? null,
            'info'      => match ($_GET['info'] ?? '') {
                'dodane'      => 'Trasa dodana i podzielona na pola.',
                'zapisane'    => 'Zmiany zapisane.',
                'przebieg'    => 'Przebieg podmieniony — pola i postęp przeliczone.',
                'przeliczone' => 'Pola trasy przeliczone z zapisanego pliku GPX.',
                'usunieta'    => 'Trasa usunięta. Odkrycia rowerzystów zostały nietknięte.',
                default       => null,
            },
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Znane trasy']],
        ]);
    }

    /** Podstrona „Dodaj trasę" — ten sam formularz co edycja, bez trasy. */
    public static function createForm(): void
    {
        self::renderForm(null);
    }

    /** Podstrona edycji JEDNEJ trasy. */
    public static function editForm(string $id): void
    {
        $route = KnownRoute::find((int) $id);
        if (!$route) {
            self::toList('brak_trasy', null);
        }

        self::renderForm($route);
    }

    public static function create(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::toForm(null, 'sesja');
        }

        // PLIK PRZYJMUJEMY NAJPIERW — do katalogu tymczasowego i jeszcze przed
        // walidacją nazwy. Dzięki temu ŻADEN późniejszy błąd nie każe wybierać
        // go od nowa: token wraca ukrytym polem formularza, dokładnie tak jak
        // w kreatorze wydarzenia (Resources\EventFormInput czyta `gpx_token`).
        $token = null;
        try {
            $token = self::gpxToken();
        } catch (\Throwable $e) {
            // Wyjątek z saveGpxTemp() = złe rozszerzenie albo za duży plik.
            // Nie ma czego oddawać formularzowi, bo nic nie wylądowało w tmp.
            self::toForm(null, 'zly_plik');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            self::toForm(null, 'brak_nazwy', $token);
        }

        if ($token === null) {
            self::toForm(null, 'brak_pliku');
        }

        try {
            // WALIDACJA PRZED PROMOCJĄ. Plik, którego parser nie przyjmie, ma
            // zostać w gpx/tmp: człowiek dostaje go z powrotem w formularzu,
            // a katalog publiczny nie zbiera plików, do których nic nie
            // prowadzi (cleanupOrphanedGpxTemp sprząta wyłącznie gpx/tmp).
            // Znana trasa to szlak, nie przejazd — stąd próg długiej trasy.
            Gpx::parse((string) Upload::gpxTempPath($token), Gpx::LONG_ROUTE_MAX_DISTANCE_KM);

            $gpxUrl = Upload::promoteGpxTemp($token);
            if ($gpxUrl === null) {
                self::toForm(null, 'zly_plik');
            }

            // Zdjęcie tą samą drogą co okładka wydarzenia — walidacja typu,
            // limit rozmiaru i auto-skalowanie są już w Upload. Brak pliku
            // zwraca null i trasa po prostu nie ma fotografii.
            $coverPhotoUrl = !empty($_FILES['cover_photo']['name'])
                ? Upload::saveCoverPhoto($_FILES['cover_photo'])
                : null;

            KnownRoute::createFromGpx(
                mb_substr($name, 0, 200),
                self::description(),
                self::gpxPath($gpxUrl),
                $gpxUrl,
                $coverPhotoUrl,
                self::surfaceOf(self::gpxPath($gpxUrl)),
                self::emblemId()
            );
        } catch (\Throwable $e) {
            self::toForm(null, 'zly_plik', $token);
        }

        // EMBLEMATY WSTECZ (migr. 087). Trasa z emblematem dostaje go od razu
        // wszystkim, którzy ją już mają całą — a to jest reguła, nie wyjątek:
        // katalog tras rośnie wolniej niż przejazdy, więc świeżo dodana trasa
        // niemal zawsze ma już kogoś, kto przez nią przejechał. Bez tego
        // wywołania emblemat czekałby na czyjś następny upload albo na cron.
        Emblem::sync();

        self::toList(null, 'dodane');
    }

    /**
     * Zapis edycji — dane opisowe, zdjęcie, bonusy i (opcjonalnie) cały przebieg.
     *
     * JEDNA AKCJA NA CAŁY FORMULARZ, także na zdjęcie. Wcześniej fotografia
     * miała własny endpoint `/{id}/zdjecie`, bo panel nie umiał edytować trasy
     * w ogóle; teraz byłaby to druga droga zapisu tej samej kolumny.
     *
     * Podmiana przebiegu idzie NA KOŃCU i tylko wtedy, gdy przyszedł plik:
     * `replaceGpx()` przelicza pola i doprowadza punkty wszystkich dotkniętych
     * osób do zgodności, więc nie ma powodu uruchamiać tego przy poprawianiu
     * literówki w opisie.
     */
    public static function update(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::toForm((int) $id, 'sesja');
        }

        $route = KnownRoute::find((int) $id);
        if (!$route) {
            self::toList('brak_trasy', null);
        }

        // Plik najpierw, z tego samego powodu co w create(): token przeżywa
        // każdy późniejszy błąd, więc podmiana przebiegu nie zaczyna się od
        // nowa tylko dlatego, że ktoś skasował nazwę.
        $token = null;
        try {
            $token = self::gpxToken();
        } catch (\Throwable $e) {
            self::toForm((int) $id, 'zly_plik');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            self::toForm((int) $id, 'brak_nazwy', $token);
        }

        $fields = [
            'name'             => mb_substr($name, 0, 200),
            'description'      => self::description(),
            'bonus_enabled'    => isset($_POST['bonus_enabled']) ? 1 : 0,
            'emblem_id'        => self::emblemId(),
            'bonus_points'     => self::optionalInt('bonus_points'),
            'completion_bonus' => self::optionalInt('completion_bonus'),
        ];

        // Brak nowego pliku = zdjęcie zostaje takie, jakie było. Pole formularza
        // jest puste przy każdym otwarciu (przeglądarka nie wypełnia inputów
        // plikowych), więc traktowanie pustki jako „skasuj" kasowałoby
        // fotografię przy każdej poprawce opisu.
        try {
            if (!empty($_FILES['cover_photo']['name'])) {
                $url = Upload::saveCoverPhoto($_FILES['cover_photo']);
                if ($url !== null) {
                    $fields['cover_photo_url'] = $url;
                }
            }
        } catch (\Throwable $e) {
            self::toForm((int) $id, 'zle-zdjecie', $token);
        }

        KnownRoute::update((int) $id, $fields);

        // Jak przy dodawaniu: przypięcie emblematu do istniejącej trasy ma
        // zadziałać natychmiast, a nie dopiero po czyjejś następnej jeździe.
        Emblem::sync();

        if ($token !== null) {
            try {
                // Walidacja przed promocją — patrz create().
                Gpx::parse((string) Upload::gpxTempPath($token), Gpx::LONG_ROUTE_MAX_DISTANCE_KM);

                $gpxUrl = Upload::promoteGpxTemp($token);
                if ($gpxUrl === null) {
                    self::toForm((int) $id, 'zly_plik');
                }
                KnownRoute::replaceGpx((int) $id, self::gpxPath($gpxUrl), $gpxUrl,
                    self::surfaceOf(self::gpxPath($gpxUrl)));
            } catch (\Throwable $e) {
                self::toForm((int) $id, 'zly_plik', $token);
            }
            self::wrocGdzieTrzeba((int) $id, 'przebieg');
        }

        self::wrocGdzieTrzeba((int) $id, 'zapisane');
    }

    /**
     * Ponowne policzenie pól z pliku, KTÓRY TRASA JUŻ MA.
     *
     * Po co, skoro pola liczą się przy wgraniu: trasy dodane przed migracją 048
     * mają `sort_order` NULL i przez to NIE RYSUJĄ SIĘ na mapie (bez kolejności
     * linia byłaby zygzakiem po kolejności wstawiania do bazy, więc warstwa je
     * pomija). Migracja obiecywała skrypt `backfill_known_route_order.php`,
     * którego nigdy nie napisano — a jest to dokładnie ta sama operacja co
     * podmiana przebiegu, tylko tym samym plikiem. Jeden przycisk zamiast
     * skryptu na serwerze.
     */
    public static function recalculate(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::toList();
        }

        $route = KnownRoute::find((int) $id);
        if (!$route) {
            self::toList('brak_trasy');
        }
        if (empty($route['gpx_url']) || !is_file(self::gpxPath($route['gpx_url']))) {
            self::toList('brak_gpx');
        }

        try {
            KnownRoute::replaceGpx((int) $id, self::gpxPath($route['gpx_url']), $route['gpx_url']);
        } catch (\Throwable $e) {
            self::toList('zly_plik');
        }

        self::toList(null, 'przeliczone');
    }

    public static function toggle(string $id): void
    {
        if (Csrf::check($_POST['csrf_token'] ?? null)) {
            $route = KnownRoute::find((int) $id);
            if ($route) {
                KnownRoute::setActive((int) $id, !(bool) $route['is_active']);
            }
        }
        self::toList();
    }

    public static function delete(string $id): void
    {
        if (Csrf::check($_POST['csrf_token'] ?? null)) {
            // Pola trasy znikają razem z nią (ON DELETE CASCADE). Odkrycia
            // rowerzystów zostają nietknięte — to fakty o ich przejazdach,
            // niezależne od tego, czy ktoś kiedyś oznaczył ten szlak.
            KnownRoute::delete((int) $id);
            self::toList(null, 'usunieta');
        }
        self::toList();
    }

    // ---------------------------------------------------------------
    // Wspólne kawałki formularza dodawania i edycji — obie akcje czytają
    // dokładnie te same pola, bo obie renderują ten sam partial widoku.
    // ---------------------------------------------------------------

    private static function renderForm(?array $route, ?string $error = null, ?array $stare = null): void
    {
        $nazwa = $route === null ? 'Nowa trasa' : $route['name'];

        // PODGLĄD TRASY OBOK PÓL (2026-09-12, zgłoszenie usera: „na dzień
        // dzisiejszy nie da się tego używać"). Do tej daty ekran edycji
        // pokazywał WYŁĄCZNIE pola — żeby sprawdzić cokolwiek o trasie, którą
        // się właśnie zmienia, trzeba było otworzyć `/trasy/{slug}` w drugiej
        // karcie. Teraz przebieg, profil wysokości i nawierzchnia stoją obok
        // formularza.
        //
        // MAPA TO TA SAMA KONTROLKA CO WSZĘDZIE INDZIEJ (`ridemoreDiscoveryMap`
        // + klucz kafli `kr-{id}`, pamięć `feedback-one-standard-map`) —
        // panel nie dostaje własnej, uproszczonej mapy, bo druga implementacja
        // rozjedzie się z pierwszą przy najbliższej zmianie warstw.
        //
        // ZAWĘŻONA DO JEDNEJ TRASY i bez przełącznika warstw: to jest PODGLĄD
        // edytowanego bytu, nie mapa do pracy. Pola odkryć, skarby i sąsiednie
        // szlaki mają swoje miejsce na `/odkrycia` i na stronie trasy.
        $mapSources = [];
        $mapBounds = null;
        $extraHead = '';
        if ($route !== null && !empty($route['gpx_url'])) {
            $mapSources = ['trails' => TileCache::urlTemplate(TileSource::LAYER_TRACKS, 'kr-' . (int) $route['id'])];
            $mapBounds = KnownRoute::boundsFor((int) $route['id']);
            $extraHead = Support::leafletMapHead() . "
"
                . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>';
        }

        View::render('web', 'known-route-edit', [
            'title'     => $nazwa . ' — znane trasy | ridemore.bike',
            'noindex'   => true,
            'route'     => $route,
            'unordered' => $route === null
                ? 0
                : (KnownRoute::unorderedCounts([(int) $route['id']])[(int) $route['id']] ?? 0),
            'error'     => self::BLEDY[$error ?? ($_GET['blad'] ?? '')] ?? null,
            // Potwierdzenie zapisu BEZ opuszczania ekranu — patrz `toForm()`.
            'zapisano'  => ($_GET['ok'] ?? '') === 'zapisane',
            // Ilu ludzi tę trasę tknęło i ilu domknęło. Pierwsze pytanie przy
            // każdej decyzji o trasie („wyłączyć? usunąć?"), a do tej pory
            // nie dało się na nie odpowiedzieć bez wchodzenia do bazy.
            'riders'    => $route === null
                ? ['started' => 0, 'completed' => 0]
                : KnownRoute::ridersProgress((int) $route['id']),
            // Profil wysokości — ten sam kształt danych i ten sam partial, co
            // na stronie trasy i na stronie przejazdu.
            'elevation' => $route === null
                ? null
                : KnownRoute::elevationProfile($route['elevation_profile'] ?? null),
            'mapSources' => $mapSources,
            'mapBounds'  => $mapBounds,
            'mapEndpoint' => View::url('/api/discovery/cells'),
            'extraHead'  => $extraHead,
            // To, co człowiek przed chwilą wpisał — ma pierwszeństwo przed
            // wierszem z bazy. Null przy zwykłym wejściu na formularz.
            'stare'     => $stare,
            // Stan listy, z której się tu przyszło — żeby „Zapisz" wracało
            // dokładnie na tę stronę wyników, a nie na początek katalogu.
            'wroc'      => self::wrocZAdresu(),
            'breadcrumbs' => [
                Support::homeCrumb(),
                Support::panelCrumb(),
                ['label' => 'Znane trasy', 'url' => View::url('/admin/znane-trasy' . self::wrocQuery(self::wrocZAdresu()))],
                ['label' => $nazwa],
            ],
        ]);
    }

    /**
     * Token pliku GPX czekającego w gpx/tmp — z nowego uploadu albo z ukrytego
     * pola po odrzuconym zapisie. Null, gdy nie ma ani jednego.
     *
     * Rozdzielenie „przyjmij plik" od „przenieś na docelowe miejsce" jest tu po
     * to, żeby plik przeżył błąd formularza. Wcześniej jedno wywołanie robiło
     * oba kroki naraz i każdy błąd — także niezwiązany z plikiem, jak pusta
     * nazwa — kazał wskazywać go od nowa.
     */
    private static function gpxToken(): ?string
    {
        if (!empty($_FILES['gpx']['name'])) {
            return Upload::saveGpxTemp($_FILES['gpx']);
        }

        // Token z poprzedniej, odrzuconej próby. Sprawdzamy, czy plik NAPRAWDĘ
        // leży w tmp — token jest danymi z formularza jak każde inne.
        $token = trim((string) ($_POST['gpx_token'] ?? ''));
        return Upload::gpxTempPath($token) !== null ? $token : null;
    }

    /**
     * NAWIERZCHNIA WGRYWANEGO PLIKU (migr. 086, prośba usera 2026-09-11:
     * „podczas uploadu powinna się również pobierać nawierzchnia tak, jak przy
     * dodawaniu eventu").
     *
     * STOI TU, W WARSTWIE HTTP, A NIE W MODELU — z dwóch powodów, z których
     * drugi kosztował już jeden zawieszony zestaw testów:
     *
     *  1. Ta sama warstwa robi to przy wydarzeniu. Kreator woła
     *     `/api/gpx/parse`, ten podnosi limit czasu i dopiero potem odpytuje
     *     Overpass; model wydarzenia dostaje gotowe procenty. Tutaj jest to
     *     samo, tylko bez AJAX-a — formularz znanej trasy to zwykły POST.
     *  2. `KnownRoute::createFromGpx()` jest JEDYNĄ drogą dodania trasy, także
     *     w testach. Overpass ma budżet 70 s na trasę, więc detekcja wewnątrz
     *     zapisu znaczyła kilkanaście minut czekania na zewnętrzny serwis
     *     w `tests/run.php` (złapane na żywo: zestaw przestał się kończyć).
     *
     * LIMIT CZASU PODNIESIONY TAK SAMO jak w `/api/gpx/parse`: 70 s analizy
     * plus parsowanie pliku nie mieści się w domyślnych ustawieniach PHP,
     * a przerwanie w połowie zostawiłoby trasę bez nawierzchni mimo udanej
     * detekcji. Niepowodzenie NIE blokuje zapisu — trasa powstaje bez tych
     * kolumn (patrz `KnownRoute::detectSurface()`).
     */
    private static function surfaceOf(string $gpxPath): ?array
    {
        @set_time_limit(300);
        try {
            $parsed = Gpx::parse($gpxPath, Gpx::LONG_ROUTE_MAX_DISTANCE_KM);
        } catch (\Throwable $e) {
            return null;
        }
        return KnownRoute::detectSurface($parsed['points'], (float) $parsed['distanceKm']);
    }

    /** URL pliku → ścieżka na dysku (parser GPX czyta z dysku, nie z URL-a). */
    private static function gpxPath(string $gpxUrl): string
    {
        return CORE_PATH . '/..' . $gpxUrl;
    }

    private static function description(): ?string
    {
        $description = trim((string) ($_POST['description'] ?? ''));
        return $description !== '' ? $description : null;
    }

    /**
     * Bonus per trasa (migr. 043): puste pole to NULL, czyli „użyj wartości
     * globalnej z konfiguracji punktacji" — a nie zero, które znaczyłoby
     * „ta trasa nie daje nic".
     */
    /**
     * Emblemat wybrany w formularzu (migr. 087) albo `null` („bez emblematu").
     *
     * ZERO I PUSTKA ZNACZĄ TO SAMO: select ma pustą opcję, ale przeglądarka
     * bywa hojna, a zapisanie `0` wywaliłoby się o klucz obcy zamiast po prostu
     * zdjąć emblemat.
     */
    private static function emblemId(): ?int
    {
        $raw = (int) ($_POST['emblem_id'] ?? 0);
        return $raw > 0 ? $raw : null;
    }

    private static function optionalInt(string $key): ?int
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        return ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * Stan listy (szukanie, filtr, sortowanie, strona) przenoszony przez
     * formularze jako `wroc_*`, a przez linki jako zwykłe parametry.
     *
     * Bez tego każda akcja przy dwusetnej trasie odsyłałaby na pierwszą stronę
     * nieprzefiltrowanego katalogu — czyli kazała szukać jej od nowa.
     */
    private static function wrocZAdresu(): array
    {
        $zrodlo = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        $klucz = static fn(string $k): string => $_SERVER['REQUEST_METHOD'] === 'POST' ? 'wroc_' . $k : $k;

        return array_filter([
            'q'      => trim((string) ($zrodlo[$klucz('q')] ?? '')),
            'filtr'  => trim((string) ($zrodlo[$klucz('filtr')] ?? '')),
            'sort'   => trim((string) ($zrodlo[$klucz('sort')] ?? '')),
            // `strona=1` nie trafia do adresu — to ten sam widok co goły adres,
            // a dwa adresy na jeden widok psują historię przeglądarki.
            'strona' => (int) ($zrodlo[$klucz('strona')] ?? 0) > 1
                ? (string) (int) $zrodlo[$klucz('strona')]
                : '',
        ], static fn(string $v): bool => $v !== '');
    }

    private static function wrocQuery(array $wroc, array $extra = []): string
    {
        $params = array_filter(array_merge($wroc, $extra), static fn($v): bool => $v !== null && $v !== '');
        return $params ? '?' . http_build_query($params) : '';
    }

    /** Powrót na listę, z zachowanym stanem wyszukiwania. */
    /**
     * Powrót NA TEN SAM EKRAN EDYCJI po udanym zapisie (2026-09-12).
     *
     * Do tej daty każdy zapis odsyłał na listę — a to znaczyło, że sprawdzenie
     * własnej zmiany wymagało odszukania trasy i wejścia w nią jeszcze raz.
     * Przy poprawianiu jednej trasy w kilku krokach (nazwa, potem opis, potem
     * wartość punktowa) płaciło się ten przejazd za każdym razem.
     *
     * Stan listy jedzie dalej w adresie, więc „Wróć do listy" nadal trafia
     * dokładnie na tę stronę wyników, z której się przyszło.
     */
    /**
     * Dokąd po udanym zapisie — rozstrzyga PRZYCISK, którym wysłano formularz.
     *
     * Formularz ma dwa: „Zapisz" (zostaje na ekranie, żeby dało się od razu
     * zobaczyć skutek i poprawić dalej) i „Zapisz i wróć do listy" (kończy
     * pracę nad tą trasą). Domyślnie ZOSTAJEMY: przy edycji jednej trasy to
     * jest częstszy zamiar, a powrót na listę jest jednym kliknięciem dalej.
     *
     * Nazwa pola (`zapisz_i_wroc`) jest `name` przycisku submit — przeglądarka
     * wysyła TYLKO ten, którym faktycznie kliknięto, więc nie trzeba tu
     * żadnego pola ukrytego ani JS-a. CELOWO bez prefiksu `wroc_`: ten należy
     * do stanu listy (`wrocZAdresu`) i mieszanie obu znaczeń w jednej nazwie
     * skończyłoby się przy pierwszym rozszerzeniu tamtej białej listy.
     */
    private static function wrocGdzieTrzeba(int $id, string $info): void
    {
        if (isset($_POST['zapisz_i_wroc'])) {
            self::toList(null, $info);
        }
        self::toEdit($id, $info);
    }

    private static function toEdit(int $id, ?string $info = null): void
    {
        $params = self::wrocZAdresu();
        if ($info !== null) {
            $params['ok'] = $info;
        }
        header('Location: ' . View::url('/admin/znane-trasy/' . $id . '/edytuj')
            . ($params ? '?' . http_build_query($params) : ''));
        exit;
    }

    private static function toList(?string $error = null, ?string $info = null): void
    {
        header('Location: ' . View::url('/admin/znane-trasy')
            . self::wrocQuery(self::wrocZAdresu(), ['blad' => $error, 'info' => $info]));
        exit;
    }

    /**
     * Powrót na formularz po błędzie — RENDER, nie przekierowanie.
     *
     * Przekierowanie (POST → 302 → GET) czyściło WSZYSTKO, co człowiek zdążył
     * wpisać: literówka w nazwie kazała od nowa wybierać region, przepisywać
     * opis i wskazywać plik GPX. To ten sam wniosek i to samo rozwiązanie co
     * w kreatorze wydarzenia — patrz `$fail` w EventController::create(),
     * które renderuje formularz z `EventFormResource::fromPost($_POST)`.
     *
     * Ceną jest adres POST-a w pasku i pytanie przeglądarki o ponowne wysłanie
     * przy F5. Tę samą cenę płaci kreator wydarzenia i jest ona nieporównanie
     * mniejsza niż wpisywanie wszystkiego drugi raz.
     */
    private static function toForm(?int $id, ?string $error = null, ?string $gpxToken = null): void
    {
        self::renderForm(
            $id === null ? null : KnownRoute::find($id),
            $error,
            self::postValues($gpxToken)
        );
        exit;
    }

    /**
     * Pola formularza tak, jak przed chwilą przyszły POST-em.
     *
     * Klucze są KOLUMNOWE (`name`, `description`, ...) — te same co w wierszu
     * `known_routes`. Dzięki temu partial ma jedną ścieżkę odczytu wartości,
     * a nie dwie rozjeżdżające się przy pierwszym dołożonym polu. Region nie
     * jest tu polem formularza od migr. 074 — wyprowadza się z GPX.
     */
    private static function postValues(?string $gpxToken): array
    {
        return [
            'name'             => trim((string) ($_POST['name'] ?? '')),
            'description'      => (string) ($_POST['description'] ?? ''),
            'bonus_points'     => self::optionalInt('bonus_points'),
            'completion_bonus' => self::optionalInt('completion_bonus'),
            'bonus_enabled'    => isset($_POST['bonus_enabled']) ? 1 : 0,
            'emblem_id'        => self::emblemId(),
            // Plik czeka w gpx/tmp; formularz odda go z powrotem ukrytym polem.
            // Null, gdy tokenu nie ma albo plik zdążył już zostać przeniesiony.
            'gpx_token'        => Upload::gpxTempPath($gpxToken) !== null ? $gpxToken : null,
        ];
    }
}
