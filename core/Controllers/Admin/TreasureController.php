<?php
// core/Controllers/Admin/TreasureController.php
// ZARZĄDZANIE SKARBAMI (Etap 8D, krok 1) — wstawianie punktów na mapę.
//
// Odpowiednik „zarządzania POI": mapa z wgranymi trasami referencyjnymi jako
// tłem, klik w mapę ustawia lokalizację, modal uzupełnia resztę.
//
// AUTORYZACJA JEST W OSOBNEJ METODZIE, NIE W `if` na górze akcji — i to jest
// jedyna nieoczywista decyzja w tym pliku. Docelowo ten sam formularz dostają
// TRZY różne role (§SKA/4): admin bez ograniczeń, organizator dla swojego
// wydarzenia, rowerzysta jako zgłoszenie do moderacji. Gdyby warunek siedział
// wpisany w akcję, dołożenie każdej kolejnej roli znaczyłoby przepisanie
// kontrolera; tak znaczy dopisanie gałęzi w jednym miejscu.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Auth;
use Core\Csrf;
use Models\Dictionary;
use Models\KnownRoute;
use Models\MapLayer;
use Models\TileCache;
use Models\TileSource;
use Models\Treasure;
use Models\TreasurePhoto;
use Utils\Upload;
use Utils\View;

class TreasureController
{
    public static function index(): void
    {
        $editId = (int) ($_GET['skarb'] ?? 0);

        // LISTA STRONICOWANA, NIE CAŁA TABELA (2026-08-20, pytanie usera: „co
        // w przypadku 1000 punktów?"). Ten sam kształt wyniku i te same nazwy
        // parametrów co w panelu znanych tras — widok stronicuje się tym samym
        // kawałkiem kodu, a admin ma jeden nawyk na oba ekrany.
        $lista = Treasure::search([
            'szukaj' => (string) ($_GET['szukaj'] ?? ''),
            'filtr'  => (string) ($_GET['filtr'] ?? ''),
            'sort'   => (string) ($_GET['sort'] ?? ''),
            'strona' => (int) ($_GET['strona'] ?? 1),
        ]);

        // WARSTWY KONTEKSTOWE NA MAPIE PANELU (Etap 4 `tasks/done/warstwy-mapy.md`,
        // decyzja usera 2026-08-29: „kontrolka tak, silnik nie").
        //
        // Do tej pory panel miał warstwy WPISANE NA SZTYWNO w szablonie
        // (`layers: {cells:true, heat:false, trails:false, treasures:false}`),
        // więc admin stawiający skarb widział mgłę i nic poza nią — ani śladów,
        // ani katalogu tras, choć jedno i drugie mówi o tym miejscu tyle samo.
        //
        // `only` BEZ WĘZŁA „Skarby" — i to jest sedno tej decyzji: skarby rysuje
        // na tej mapie SAM PANEL, własnymi, przeciąganymi pinezkami. Warstwa
        // skarbów z silnika postawiłaby obok nich drugi komplet znaczników
        // tych samych punktów. Ten sam mechanizm zawężania, którym strona trasy
        // i panel dnia wydarzenia odcinają „Ślady".
        $mapLayers = MapLayer::tree('all', [
            'loggedIn' => true,
            'only'     => ['cells', 'heat', 'slady', 'trails'],
        ]);
        $mapSources = [];
        foreach (MapLayer::tileKeysFor($mapLayers) as $layerKey => $trackKey) {
            $mapSources[$layerKey] = TileCache::urlTemplate(TileSource::LAYER_TRACKS, $trackKey);
        }

        View::render('web', 'treasures-admin', [
            'mapLayers'  => $mapLayers,
            'mapSources' => $mapSources,
            'title'      => 'Skarby — ridemore.bike',
            'noindex'    => true,
            'lista'      => $lista,
            'liczniki'   => Treasure::counters(),
            'pending'    => Treasure::pending(),
            'editing'    => $editId > 0 ? Treasure::find($editId) : null,
            // GALERIA edytowanego skarbu (SKA/14) — tylko przy edycji; przy
            // dodawaniu nie ma jeszcze do czego przypiac zdjec.
            'editingPhotos' => $editId > 0 ? TreasurePhoto::forTreasure($editId) : [],
            'photoLimit' => TreasurePhoto::PER_USER_LIMIT,
            'categories' => Dictionary::items('treasure_category'),
            'regions'    => Dictionary::items('region'),
            // TRASY REFERENCYJNE JAKO TŁO MAPY. Skarb prawie zawsze stoi przy
            // jakiejś trasie — bez nich admin stawia pinezki na pustej mapie
            // i nie widzi, czy miejsce w ogóle jest po drodze.
            'trails'     => array_values(array_filter(
                KnownRoute::all(true),
                static fn(array $r): bool => !empty($r['gpx_url'])
            )),
            'defaults'   => [
                'points' => Treasure::defaultPoints(),
                'radius' => Treasure::defaultRadius(),
            ],
            'info'       => match ($_GET['info'] ?? '') {
                'zapisane'  => 'Skarb zapisany.',
                'nowy-kod'  => 'Kod wymieniony — stara naklejka przestała działać. Wydrukuj nową.',
                'brak-danych' => 'Brakuje nazwy albo lokalizacji.',
                'aktywny'   => 'Punkt aktywowany — od teraz płaci punktami.',
                'odrzucony' => 'Punkt odrzucony. Zostaje w bazie jako wycofany, więc historia się nie rozjeżdża.',
                'usuniete'  => 'Skarb skasowany — nikt go nie znalazł, więc nie zostawił po sobie żadnych punktów.',
                'ma-znalezienia' => 'Tego skarbu nie da się skasować: ktoś go już znalazł i dostał za niego punkty. '
                    . 'Użyj „Wycofaj" — zniknie z mapy, a historia zostanie nietknięta.',
                default     => null,
            },
            // gpxMapHead() daje Leaflet + leaflet-gpx (trasy referencyjne),
            // ale NIE warstwę odkryć — ta ma własny skrypt. Bez niego
            // `ridemoreDiscoveryMap` jest niezdefiniowane, a strażnik `typeof`
            // w widoku po cichu pomija całą warstwę: mapa działa, tylko nie
            // widać, co odkryte.
            'extraHead'  => Support::gpxMapHead() . "
"
                          . '<script src="' . View::asset('/assets/js/discovery-map.js') . '"></script>',
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Skarby']],
        ]);
    }

    public static function save(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        $lat = self::coord($_POST['lat'] ?? null);
        $lon = self::coord($_POST['lon'] ?? null);
        $name = trim((string) ($_POST['name'] ?? ''));

        // Bez współrzędnych i nazwy skarb nie istnieje — reszta pól ma sensowne
        // wartości domyślne, te dwie nie mają.
        if ($lat === null || $lon === null || $name === '') {
            self::back('brak-danych');
            return;
        }

        $id = (int) ($_POST['id'] ?? 0);
        $rarity = self::oneOf($_POST['rarity'] ?? '', ['COMMON', 'RARE', 'EPIC', 'LEGENDARY'], 'COMMON');

        // Puste pole punktów bierze stawkę wyjściową DLA TEJ RZADKOŚCI (SKA/15),
        // a nie jedną globalną. Wcześniej `(int) ''` dawało tu zero, czyli skarb
        // wart tyle, co nic — i to bez żadnego komunikatu, bo formalnie zapis się
        // udawał. Domyślna wartość nie jest tu wygodą, tylko zabezpieczeniem.
        $rawPoints = trim((string) ($_POST['points'] ?? ''));
        $points = $rawPoints === '' ? Treasure::defaultPointsFor($rarity) : max(0, (int) $rawPoints);

        // ZDJECIE GLOWNE (SKA/14). Kolumna `photo_url` byla w bazie od poczatku
        // modulu i cala reszta serwisu juz jej uzywala — brakowalo WYLACZNIE
        // tego pola w formularzu, przez co 0 ze 102 skarbow mialo zdjecie.
        //
        // PRZY EDYCJI BEZ NOWEGO PLIKU MUSIMY PODAC STARY ADRES: `Treasure::save`
        // robi bezwarunkowe `photo_url = :photo`, wiec brak wartosci nie znaczy
        // „zostaw jak bylo", tylko „skasuj". Bez tej linii kazdy zapis formularza
        // gubilby wgrane wczesniej zdjecie.
        $photoUrl = null;
        if ($id > 0) {
            $biezacy = Treasure::find($id);
            $photoUrl = $biezacy['photo_url'] ?? null;
        }
        if (!empty($_POST['photo_remove'])) {
            $photoUrl = null;
        }
        if (!empty($_FILES['photo']['name'])) {
            // Ten sam helper co przy okladkach wydarzen — limity, formaty
            // i skalowanie sa juz rozstrzygniete i nie ma powodu ich dublowac.
            $nowe = Upload::saveCoverPhoto($_FILES['photo']);
            if ($nowe !== null) {
                $photoUrl = $nowe;
            }
        }

        $zapisaneId = Treasure::save($id > 0 ? $id : null, [
            'name'             => mb_substr($name, 0, 160),
            'description'      => trim((string) ($_POST['description'] ?? '')) ?: null,
            'hint'             => trim((string) ($_POST['hint'] ?? '')) ?: null,
            'category_item_id' => (int) ($_POST['category_item_id'] ?? 0) ?: null,
            // Region NIE przychodzi z formularza — wyprowadzamy go z miejsca
            // (patrz Models\Treasure::guessRegion). Przy edycji zachowujemy
            // ręczne ustawienie, gdyby ktoś je kiedyś nadał przez bazę.
            'region_item_id'   => Treasure::guessRegion($lat, $lon),
            'lat'              => $lat,
            'lon'              => $lon,
            'points'           => $points,
            'claim_radius_m'   => (int) ($_POST['claim_radius_m'] ?? Treasure::defaultRadius()),
            'rarity'           => $rarity,
            'origin'           => self::oneOf($_POST['origin'] ?? '', ['OFFICIAL', 'ORGANIZER', 'PARTNER', 'COMMUNITY'], 'OFFICIAL'),
            'status'           => self::oneOf($_POST['status'] ?? '', ['PROPOSED', 'ACTIVE', 'RETIRED'], 'ACTIVE'),
            'reveal_level'     => min(2, max(0, (int) ($_POST['reveal_level'] ?? 2))),
            'is_active'        => empty($_POST['is_active']) ? 0 : 1,
            'photo_url'        => $photoUrl,
        ], Auth::user()?->id);

        // GALERIA — dopiero PO zapisie, bo przy DODAWANIU nowego skarbu id
        // powstaje w tej samej chwili co wiersz. `Treasure::save()` zwraca je
        // i przy edycji, i przy wstawieniu, wiec nie ma czego zgadywac.
        if ($zapisaneId > 0 && !empty($_FILES['gallery']['name'][0])) {
            $bledy = [];
            $adresy = Upload::saveGalleryPhotos($_FILES['gallery'], 10, 'gallery', $bledy);
            // Admin NIE podlega limitowi na osobe (TreasurePhoto::PER_USER_LIMIT) —
            // limit istnieje po to, zeby jeden znalazca nie zapelnil galerii soba,
            // a admin ja kuratoruje.
            TreasurePhoto::add($zapisaneId, $adresy, Auth::user()?->id);
        }

        self::back('zapisane');
    }

    /**
     * KASOWANIE ZDJECIA Z GALERII (SKA/14).
     *
     * Osobna akcja, nie pole w formularzu skarbu: zdjecia dorzucaja takze
     * znalazcy (decyzja usera 2026-08-22), wiec kasowanie musi dzialac
     * niezaleznie od tego, czy ktos akurat edytuje reszte skarbu.
     *
     * Admin kasuje KAZDE zdjecie — galeria jest publiczna i to on za nia
     * odpowiada. Uprawnienie „skasuj swoje" dla znalazcy siedzi w kontrolerze
     * publicznym, nie tutaj.
     */
    public static function deletePhoto(string $photoId): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back('blad');
            return;
        }
        $photo = TreasurePhoto::find((int) $photoId);
        if ($photo === null) {
            self::back('blad');
            return;
        }
        TreasurePhoto::delete((int) $photo['id']);
        self::back('zapisane');
    }

    /**
     * SKARBY W KADRZE MAPY — JSON dla panelu (2026-08-20).
     *
     * Do tej daty widok wsypywał WSZYSTKIE skarby do HTML-a jako JSON i rysował
     * je co do jednego. Przy tysiącu punktów (pytanie usera) to kilkaset
     * kilobajtów na każde wejście, niezależnie od tego, na co admin patrzy.
     *
     * ODPOWIEDŹ MA PEŁNĄ PRECYZJĘ i cały wiersz — panel jest po to, żeby
     * poprawić punkt, więc musi znać jego prawdziwe położenie i wszystkie pola
     * formularza. Dlatego to wejście jest ADMINOWE (`$adminGet`), a nie
     * w api/routes.php obok publicznego `/api/treasures`, które przycina
     * pozycję skarbu ukrytego do środka pola.
     */
    public static function points(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        foreach (['north', 'south', 'east', 'west'] as $key) {
            if (!is_numeric($_GET[$key] ?? null)) {
                http_response_code(400);
                echo json_encode(['error' => 'Brak zakresu mapy']);
                return;
            }
        }

        $rows = Treasure::inBoundsAdmin([
            'north' => (float) $_GET['north'], 'south' => (float) $_GET['south'],
            'east'  => (float) $_GET['east'],  'west'  => (float) $_GET['west'],
        ]);

        echo json_encode(['treasures' => array_map(static fn(array $t): array => [
            'id'          => (int) $t['id'],
            'name'        => (string) $t['name'],
            'description' => (string) ($t['description'] ?? ''),
            'hint'        => (string) ($t['hint'] ?? ''),
            'lat'         => (float) $t['lat'],
            'lon'         => (float) $t['lon'],
            'points'      => (int) $t['points'],
            'radius'      => (int) $t['claim_radius_m'],
            'rarity'      => (string) $t['rarity'],
            'origin'      => (string) $t['origin'],
            'status'      => (string) $t['status'],
            'reveal'      => (int) $t['reveal_level'],
            'category'    => (int) ($t['category_item_id'] ?? 0),
            'isActive'    => (int) $t['is_active'],
            'code'        => (string) $t['code'],
            'claims'      => (int) ($t['claims'] ?? 0),
            'region'      => (string) ($t['region_label'] ?? ''),
        ], $rows)], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Kasowanie punktu (prośba usera 2026-08-20: „brak możliwości usunięcia").
     *
     * Reguła siedzi w modelu (`Treasure::deleteIfUnfound`) i jest jedna: skarb,
     * po który ktoś pojechał, zostaje. Tutaj tylko tłumaczymy odmowę na zdanie,
     * które mówi, co zrobić zamiast tego.
     */
    public static function delete(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        $wynik = Treasure::deleteIfUnfound((int) $id);
        self::back($wynik['ok'] ? 'usuniete' : 'ma-znalezienia');
    }

    /**
     * Decyzja moderacyjna (SKA/3) — aktywuj albo odrzuc zgloszony punkt.
     *
     * Admin OMIJA glosowanie, a nie doklada do niego glosu: gdyby jego decyzja
     * byla jednym z trzech potwierdzen, „aktywuj" nie dzialaloby na swiezym
     * zgloszeniu i przycisk klamalby o tym, co robi.
     */
    public static function setStatus(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        $status = self::oneOf($_POST['status'] ?? '', ['ACTIVE', 'RETIRED'], 'ACTIVE');
        Treasure::setStatus((int) $id, $status);
        self::back($status === 'ACTIVE' ? 'aktywny' : 'odrzucony');
    }

    /**
     * Arkusz naklejek do wydruku.
     *
     * Bez parametru drukuje WSZYSTKIE aktywne — bo pilotaż stawia się partiami
     * i najczęściej chce się cały komplet naraz. `?skarb=ID` drukuje jeden,
     * gdy naklejka zginęła albo trzeba ją wymienić po zmianie kodu.
     */
    public static function printSheet(): void
    {
        $one = (int) ($_GET['skarb'] ?? 0);
        $items = $one > 0
            ? array_filter([Treasure::find($one)])
            : array_values(array_filter(
                Treasure::all(true),
                static fn(array $t): bool => $t['status'] !== 'RETIRED'
            ));

        View::render('web', 'treasure-print', [
            'title'   => 'Naklejki do wydruku — ridemore.bike',
            'noindex' => true,
            'items'   => $items,
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(),
                              ['label' => 'Skarby', 'url' => View::url('/admin/skarby')],
                              ['label' => 'Wydruk']],
        ]);
    }

    /**
     * Wymiana kodu bez ruszania skarbu.
     *
     * Potrzebne, gdy zdjęcie naklejki trafi do internetu: stary kod przestaje
     * działać, a historia znalezień i wszystkie statystyki zostają, bo wiersz
     * jest ten sam. To jest właśnie powód, dla którego `code` jest osobną
     * kolumną, a nie identyfikatorem (migr. 054).
     */
    public static function rotateCode(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }
        Treasure::rotateCode((int) $id);
        self::back('nowy-kod');
    }

    /**
     * Współrzędna z formularza.
     *
     * Przecinek zamieniamy na kropkę, bo pole bywa wypełniane ręcznie, a polska
     * klawiatura numeryczna daje przecinek — i wtedy `(float)` uciąłby wszystko
     * po nim, stawiając skarb na równiku.
     */
    private static function coord($raw): ?float
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $value = (float) str_replace(',', '.', $raw);
        return ($value >= -90 && $value <= 180 && $value != 0.0) ? $value : null;
    }

    /** Wartość ze zbioru albo domyślna — pola ENUM przychodzą z formularza. */
    private static function oneOf($value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $fallback;
    }

    /**
     * Powrót na listę — Z ZACHOWANIEM jej stanu (2026-08-20).
     *
     * Od kiedy lista ma wyszukiwarkę, filtry i strony, zapis skarbu
     * z dwudziestej strony wyników nie może wyrzucać na pierwszą stronę bez
     * filtrów: admin poprawia punkty seriami i po każdym zapisie musiałby
     * odtwarzać, gdzie był. Stan wraca w polach `wroc_*` formularza — ta sama
     * konwencja co w panelu znanych tras.
     */
    private static function back(?string $info = null): void
    {
        $stan = [];
        foreach (['szukaj', 'filtr', 'sort', 'strona'] as $klucz) {
            $wartosc = trim((string) ($_POST['wroc_' . $klucz] ?? ''));
            if ($wartosc !== '') {
                $stan[$klucz] = $wartosc;
            }
        }
        if ($info) {
            $stan['info'] = $info;
        }

        header('Location: ' . View::url('/admin/skarby') . ($stan ? '?' . http_build_query($stan) : ''));
        exit;
    }
}
