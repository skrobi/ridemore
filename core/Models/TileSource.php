<?php
// core/Models/TileSource.php
// CO NARYSOWAĆ NA KAFLU O DANYM KLUCZU — jedno miejsce, w którym adres kafla
// zamienia się w zbiór śladów albo pól.
//
// ADRES KAFLA TO /assets/tiles/{warstwa}/{klucz}/{z}/{x}/{y}.png
//
//   warstwa `slady`  klucz `all`      — ślady z odbytych wyjazdów (bez solo —
//                                       powód przy samym kluczu niżej)
//                          `u-{slug}` — ślady jednej osoby (profil rowerzysty)
//                          `me`       — ślady zalogowanego (mapa prywatna)
//                          `e-{id}`   — ślady jednego turnusu (kronika)
//                          `ev-{id}`  — trasy zapowiadane wydarzenia
//                          `kr-{id}`  — znana trasa
//                          `kr`       — WSZYSTKIE aktywne znane trasy (warstwa
//                                       „Trasy" na mapach; od 2026-08-20 jedyny
//                                       sposób ich rysowania — patrz niżej)
//                          `kd-{slug}` — trasy, które TA OSOBA ukończyła
//                                       (Etap 3 warstw mapy, 2026-08-26 —
//                                       tasks/done/warstwy-mapy.md; „ukończona"
//                                       to ta sama definicja co na karcie postępu,
//                                       patrz KnownRoute::progressForUser)
//                          `kn-{slug}` — trasy, których TA OSOBA JESZCZE NIE
//                                       ukończyła (zgłoszenie usera 2026-08-29:
//                                       mapa osobista pokazywała WYŁĄCZNIE
//                                       ukończone, więc nieukończone znikały
//                                       z niej całkiem, w odróżnieniu od mapy
//                                       społeczności, która pokazuje cały
//                                       katalog). DOKŁADNE LUSTRO `kd-{slug}` —
//                                       ta sama definicja „ukończona"
//                                       (KnownRoute::progressForUser), tylko
//                                       zanegowana.
//   warstwa `hex`    te same klucze, pola odkryć zamiast linii
//
// DLACZEGO KLUCZ, A NIE PARAMETRY W ADRESIE
// -----------------------------------------
// Kafel musi mieć JEDEN kanoniczny adres, bo adres jest ścieżką pliku na dysku
// i jednocześnie kluczem cache'u przeglądarki. Parametry zapytania („?user=5&
// styl=ciemny") dałyby ten sam obraz pod wieloma adresami, a Apache serwowałby
// z dysku tylko trafienia w dokładnie ten sam ciąg znaków.
//
// PRYWATNOŚĆ: NA DYSKU LĄDUJE WYŁĄCZNIE TO, CO I TAK JEST PUBLICZNE
// -----------------------------------------------------------------
// To jest najważniejsza zasada tego pliku i jedyna, której złamanie boli.
// Kafel zapisany pod /assets/ jest dostępny dla każdego, kto zna adres — a
// adres to slug (publiczny) plus z/x/y (do zgadnięcia w sekundę). Dlatego:
//
//   * klucz `u-{slug}` przechodzi PRZEZ Support::visibleRider() — dokładnie ten
//     sam warunek co /api/discovery/cells?scope=rider, wywołany, a nie
//     przepisany. Ta mapa i tak jest publiczna, więc jej kafel może leżeć na
//     dysku;
//   * klucz `me` obsługuje osobę, która UKRYŁA SIĘ z list (`roster_visible`).
//     Takiego kafla NIE ZAPISUJEMY — powstaje na każde żądanie i wychodzi
//     z nagłówkiem `private, no-store`. Płacimy za to ok. 20 ms na kafel, ale
//     mapa ukrytego rowerzysty nie leży w katalogu publicznym.
//
// Zmiana widoczności musi więc kasować kafle `u-{slug}` (patrz Models\TileCache
// ::purgeKey, wołane z ustawień konta) — inaczej ktoś, kto się ukrył, zostawia
// za sobą działający adres.
namespace Models;

use Core\Auth;
use Core\Database;
use Controllers\Support;
use Utils\TileGrid;
use Utils\TrackPalette;

class TileSource
{
    public const LAYER_TRACKS = 'slady';
    public const LAYER_HEX = 'hex';

    /**
     * Style linii — te same kolory i grubości co w wersji wektorowej map.
     * Trzymane tutaj, bo od teraz decydują o TREŚCI PLIKU: zmiana koloru musi
     * iść w parze z podbiciem epoki, inaczej stare kafle zostaną na dysku
     * i w przeglądarkach.
     */
    public const STYLES = [
        // Ślad z odbytego wyjazdu — pełna linia w kolorze szlaku.
        'real'    => ['color' => '#2C6B4F', 'weight' => 3, 'alpha' => 1.0,  'dash' => false],
        // Trasa zapowiadana, której nikt nie potwierdził śladem — przerywana
        // i wyblakła, bo to informacja o BRAKU, nie o dokonaniu.
        'planned' => ['color' => '#7C8A80', 'weight' => 2, 'alpha' => 0.75, 'dash' => true],
        // Przebieg znanej trasy — grubszy, bo na jej stronie jest bohaterem.
        // KOLOR JEST TU WARTOŚCIĄ ZAPASOWĄ: od migracji 064 każdą znaną trasę
        // rysuje jej WŁASNY kolor (`known_routes.color_index` -> Models\
        // KnownRoute::colorOf), przekazywany w grupie kluczem `color`. Ten
        // wpis obowiązuje trasy jeszcze nieprzydzielone i trasy wydarzeń,
        // które własnego koloru nie mają.
        //
        // OBWÓDKA (2026-08-27, zgłoszenie usera: „znane trasy i ślady mają
        // jeden styl i nie da się ich odróżnić tam, gdzie się nakładają").
        // `casing` jest STAŁA dla WSZYSTKICH grup tego stylu — celowo NIE
        // idzie kolumną per trasa jak `color`, bo sens obwódki jest inny niż
        // sens koloru: kolor odróżnia trasę OD INNEJ TRASY, obwódka odróżnia
        // „to jest referencja" (znana trasa I trasa zapowiadana wydarzenia —
        // `ev-{id}` używa tego samego stylu) OD „to jest zarejestrowany
        // przejazd" (style `real`/`planned`, bez obwódki, nigdy). `--ink`
        // z palety CSS serwisu (`#15201A`) — ciemny, więc czyta się na
        // każdym z sześciu kolorów `KnownRoute::COLORS` i na jasnym podkładzie.
        'route'   => ['color' => '#2C6B4F', 'weight' => 4, 'alpha' => 1.0,  'dash' => false,
                      'casing' => '#15201A', 'casingWidth' => 1.5],

        // HEATMAPA SPOŁECZNOŚCI (2026-08-28, uwaga usera: „co mi da, że będę
        // miał 500 śladów po tej samej drodze w ramach społeczności").
        //
        // WYŁĄCZNIE dla klucza `all` — patrz `tracks()` niżej. Reszta kluczy
        // (`me`/`u-{slug}`/`e-{id}`/`ev-{id}`) zostaje przy migr. 073 (osobna
        // grupa, osobny kolor na ślad): tam obiektów jest mało i odróżnienie
        // ich ma wartość. Na mapie społeczności jest odwrotnie — przy skali
        // rzędu tysięcy przejazdów dziennie każda popularna droga i tak
        // zbiera dziesiątki nakładających się śladów, a osobny kolor na
        // każdy zamienia się w confetti zamiast czytelnej informacji
        // „tędy się jeździ". Ten sam wniosek co przy Strava Heatmap: JEDEN
        // kolor, NISKA krycie — GD samo sumuje przezroczystość tam, gdzie
        // ślady się nakładają (`imagealphablending` włączone w `canvas()`),
        // więc popularna trasa „wypala się" ciemniej bez liczenia w bazie,
        // ile razy dany fragment przejechano. Rzadka trasa zostaje ledwo
        // widoczna — i to jest treść komunikatu, nie usterka.
        //
        // KOLOR POMARAŃCZOWY, NIE ZIELEŃ BRANDOWA (2026-08-29, uwaga usera:
        // zielona linia ginie na szarej mgle, a na standardowym OSM ginie
        // jeszcze bardziej — w lasach). `#D2731A` to `Utils\TrackPalette::
        // COLORS[4]` — TA SAMA baza kolorów, którą maluje się „Ślady własne"
        // (migr. 073), więc heatmapa nie wprowadza siódmego, osobnego
        // odcienia do aplikacji. Pomarańczowy, nie dowolny inny kolor z tej
        // palety: ciepła barwa czyta się jak „gorąco" i jest tym samym
        // językiem, którym warstwa „Heatmapa" (pola, `TileController::SCALE`)
        // już mówi o natężeniu — żółty/pomarańczowy/czerwony = częściej tu
        // bywają. Literał, nie referencja do stałej: `STYLES` trzyma gotowe
        // hexy dla WSZYSTKICH stylów (patrz `real`/`route` wyżej), więc
        // trzymanie się tej konwencji jest tu ważniejsze niż DRY.
        'heat'    => ['color' => '#D2731A', 'weight' => 3, 'alpha' => 0.25, 'dash' => false],
    ];

    /**
     * Czy klucz jest w ogóle poprawny i wolno go pokazać temu, kto pyta.
     *
     * Wzorzec jest ciasny NIE dla porządku, tylko dlatego, że klucz staje się
     * nazwą katalogu na dysku: brak kropek i ukośników to jedyne, co dzieli ten
     * kod od przejścia po katalogach przez adres kafla.
     */
    public static function isAllowed(string $key): bool
    {
        if (!preg_match('/^(all|me|kd-me|kn-me|u-[a-z0-9-]{1,60}|kd-[a-z0-9-]{1,60}|kn-[a-z0-9-]{1,60}|e-\d{1,12}|ev-\d{1,12}|kr-\d{1,12}|kr)$/', $key)) {
            return false;
        }
        // `kd-me`/`kn-me` PRZED ogólnym `kd-`/`kn-` — sprawdzana RÓWNOŚĆ, nie
        // prefiks, bo inaczej trafiłby tu też jako (niepoprawny) slug „me" niżej.
        if ($key === 'me' || $key === 'kd-me' || $key === 'kn-me') {
            return Auth::check();
        }
        if (str_starts_with($key, 'u-') || str_starts_with($key, 'kd-') || str_starts_with($key, 'kn-')) {
            return Support::visibleRider(substr($key, str_starts_with($key, 'u-') ? 2 : 3)) !== null;
        }
        return true;
    }

    /**
     * Czy kafel tego klucza wolno ZAPISAĆ na dysku.
     *
     * Fałsz dla `me`/`kd-me`/`kn-me` — wszystkie trzy to mapa osoby ukrytej
     * z list albo bez publicznego profilu. Reszta kluczy pokazuje dane, które
     * i tak są publicznie dostępne innymi drogami.
     */
    public static function isCacheable(string $key): bool
    {
        return $key !== 'me' && $key !== 'kd-me' && $key !== 'kn-me';
    }

    /** Id użytkownika stojącego za kluczem `me` / `kd-me` / `kn-me` / `u-{slug}` / `kd-{slug}` / `kn-{slug}`; null dla pozostałych. */
    public static function userIdFor(string $key): ?int
    {
        if ($key === 'me' || $key === 'kd-me' || $key === 'kn-me') {
            // `Auth::id()` NIE ISTNIEJE — to jest samodzielny błąd, zastany
            // w tym kodzie (sprawdzone: był tu już PRZED tą sesją, w commicie
            // startowym), nie coś, co wprowadziła zmiana z 2026-08-26.
            // Każde żądanie klucza `me`/`kd-me` od kogokolwiek bez publicznego
            // slugu kończyło się fatalnym błędem PHP zamiast pustą mapą albo
            // kaflem — a to jest ŚCIEŻKA, którą realnie się przechodzi (`me`
            // to jedyny klucz dla zalogowanego bez slugu). Poprawka: właściwy
            // dostęp do id to `Auth::user()->id`.
            return Auth::check() ? Auth::user()->id : null;
        }
        if (str_starts_with($key, 'u-') || str_starts_with($key, 'kd-') || str_starts_with($key, 'kn-')) {
            $user = Support::visibleRider(substr($key, str_starts_with($key, 'u-') ? 2 : 3));
            return $user?->id;
        }
        return null;
    }

    /**
     * Grupy śladów do narysowania, w kolejności od spodu.
     *
     * `color` jest OPCJONALNY i nadpisuje kolor stylu — dziś używają go znane
     * trasy, z których każda ma własny (migr. 064).
     *
     * @return array<int,array{hashes:string[],style:string,color?:string}>
     */
    public static function tracks(string $key): array
    {
        $db = Database::connection();

        if ($key === 'all') {
            // Mapa społeczności: ślady odbytych WYJAZDÓW. Bez tras
            // zapowiadanych — na wspólnej mapie „ktoś tędy jechał" i „ktoś tędy
            // zamierza" to dwa różne komunikaty, a tylko pierwszy jest
            // o społeczności.
            //
            // JEDNA GRUPA NA ŹRÓDŁO, JEDEN (NISKI) STYL — odwrotnie niż
            // `me`/`u-{slug}` (2026-08-28, patrz `STYLES['heat']` wyżej po
            // uzasadnienie). Do 2026-08-27 szło stąd PO JEDNEJ GRUPIE NA ŚLAD
            // (migr. 073) — dobre rozwiązanie, gdy śladów jest kilkadziesiąt
            // i różnią się przebiegiem. Przy tysiącach przejazdów dziennie
            // WIĘKSZOŚĆ z nich powtarza te same drogi — a per-ślad kolor
            // sprawdza się w odróżnianiu RÓŻNYCH tras, nie w pokazywaniu, że
            // tą samą drogą jechało 500 osób. Stąd powrót do worka hashy, ale
            // NIE do stylu `real` z migracji sprzed 073 (pełna krycie, jedna
            // zielona plama) — `heat` ma niską alfę właśnie po to, żeby
            // nakładanie się śladów SAMO rysowało natężenie.
            $eventGroup = [
                'hashes' => self::hashesFor($db->query(
                    'SELECT DISTINCT gpx_url FROM edition_tracks'
                )->fetchAll(\PDO::FETCH_COLUMN)),
                'style' => 'heat',
            ];

            // SOLO WCHODZI DO HEATMAPY OD 2026-08-28 (migr. 076) — zgłoszenie
            // usera: był przekonany, że ta warstwa liczy WSZYSTKIE przejazdy,
            // bo pola odkryć (`Odkrycia`) liczą wszystkie, a „Ślady" tylko
            // `edition_tracks`; solo to WIĘKSZOŚĆ tego, co ludzie realnie
            // jeżdżą, więc odkryte pole nie miało pod sobą śladu — rozjazd
            // dla oka między dwiema warstwami tej samej strony.
            //
            // DO 2026-08-27 SOLO BYŁO STĄD WYŁĄCZONE CELOWO — plik zaczyna się
            // i kończy pod domem, a ten klucz leży publicznie na dysku pod
            // adresem do zgadnięcia. TO NIE JEST COFNIĘCIE TAMTEJ DECYZJI:
            // `self::trimmedHashesFor()` niżej bierze geometrię z DRUGIEJ pary
            // tabel (`gpx_geometry_trimmed`/`gpx_tiles_trimmed`), gdzie okolice
            // domu są WYCIĘTE, zanim cokolwiek trafi na dysk — dokładnie tym
            // samym promieniem, którym `RiderActivity::recordSolo` przycina
            // punkty przed policzeniem pól. Surowy plik nadal nigdy nie
            // wchodzi na klucz `all`; wchodzi jego bezpieczna, przycięta kopia.
            //
            // `source: 'trimmed'` mówi `TileController::renderTracks`, z KTÓREJ
            // pary tabel czytać geometrię tych hashy — inaczej niż reszta
            // kluczy, które zawsze czytają `gpx_geometry`/`gpx_tiles`.
            $soloStmt = $db->prepare(
                'SELECT DISTINCT gpx_url FROM rider_activities
                  WHERE source_code = :s AND gpx_url IS NOT NULL'
            );
            $soloStmt->execute(['s' => RiderActivity::SOURCE_SOLO]);
            $soloGroup = [
                'hashes' => self::trimmedHashesFor($soloStmt->fetchAll(\PDO::FETCH_COLUMN)),
                'style'  => 'heat',
                'source' => 'trimmed',
            ];

            return array_values(array_filter(
                [$eventGroup, $soloGroup],
                static fn (array $g): bool => $g['hashes'] !== []
            ));
        }

        if (str_starts_with($key, 'ev-')) {
            $eventId = (int) substr($key, 3);
            // Trasy zapowiadane wydarzenia: etapy wielodniówki i warianty.
            $stmt = $db->prepare('
                SELECT gpx_url FROM event_stages WHERE event_id = :id AND gpx_url IS NOT NULL
                UNION
                SELECT gpx_url FROM event_route_variants WHERE event_id = :id2 AND gpx_url IS NOT NULL
            ');
            $stmt->execute(['id' => $eventId, 'id2' => $eventId]);
            // Wielodniówka ma osobny plik na KAŻDY etap i na każdy wariant,
            // a te schodzą się na wspólnych odcinkach — bez własnego koloru
            // na etap mapa wydarzenia jest tą samą plamą co reszta.
            return self::trackGroups(self::hashesFor($stmt->fetchAll(\PDO::FETCH_COLUMN)), 'route');
        }

        // WSZYSTKIE aktywne znane trasy — warstwa „Trasy" na mapie odkryć,
        // profilu rowerzysty i stronie trasy (2026-08-20).
        //
        // Zastąpiła rysowanie wektorowe z /api/discovery/trails, które brało
        // geometrię ze ŚRODKÓW PÓL siatki. Pole ma ok. 500 m, więc linia
        // sklejona z ich środków była zygzakiem obok drogi, a nie przebiegiem
        // szlaku. Kafel rysuje prawdziwy ślad z pliku GPX i przy okazji nie
        // wysyła do przeglądarki ani jednego punktu geometrii.
        //
        // `is_active = 1`, bo wyłączenie trasy ma ją zdejmować z serwisu,
        // a nie tylko z listy (ta sama reguła co w KnownRoute::findBySlug).
        //
        // KAŻDA TRASA OSOBNĄ GRUPĄ, BO KAŻDA MA WŁASNY KOLOR (migr. 064,
        // zgłoszenie usera 2026-08-20: „wszystkie są zielone i nie wiadomo,
        // który jest który"). Wcześniej szedł stąd jeden worek hashy i jeden
        // styl, więc nakładające się szlaki zlewały się w jedną plamę.
        // Kolor bierze się z kolumny, a nie z tego, co widać na kaflu — patrz
        // KnownRoute::assignColor.
        if ($key === 'kr') {
            return self::routeGroups($db->query(
                'SELECT id, gpx_url, color_index FROM known_routes
                  WHERE is_active = 1 AND gpx_url IS NOT NULL
                  ORDER BY id ASC'
            )->fetchAll());
        }

        if (str_starts_with($key, 'kr-')) {
            $stmt = $db->prepare(
                'SELECT id, gpx_url, color_index FROM known_routes
                  WHERE id = :id AND gpx_url IS NOT NULL'
            );
            $stmt->execute(['id' => (int) substr($key, 3)]);
            return self::routeGroups($stmt->fetchAll());
        }

        // ZNANE TRASY, KTÓRE TA OSOBA UKOŃCZYŁA — warstwa „Trasy" w kontekście
        // 'me'/'rider', gdy strona ma pokazywać nie CAŁY katalog, tylko to, co
        // czyjeś jest (Etap 3, tasks/done/warstwy-mapy.md, 2026-08-26; user:
        // „Trasy również w kontekście społeczności dla całości, a usera to
        // tylko usera"). „Ukończona" to DOKŁADNIE ta sama definicja, której
        // używa karta postępu na profilu i liście tras — `KnownRoute::
        // progressForUser` (matched >= cells_total, patrz `HAVING` niżej) —
        // żeby trasa, która świeci na 100% na liście, nie mogła nagle
        // zniknąć z tej warstwy przez inną regułę policzoną gdzie indziej.
        //
        // `cells_total > 0`: trasa bez policzonych pól (jeszcze nieprzeliczona
        // w panelu) nigdy nie jest „ukończona" — inaczej pusty mianownik
        // zaliczałby ją za darmo, zanim ktokolwiek nią pojechał.
        if (str_starts_with($key, 'kd-')) {
            $userId = self::userIdFor($key);
            if ($userId === null) {
                return [];
            }
            $stmt = $db->prepare(
                'SELECT kr.id, kr.gpx_url, kr.color_index
                   FROM known_routes kr
                  WHERE kr.is_active = 1 AND kr.gpx_url IS NOT NULL
                    AND kr.cells_total > 0
                    AND kr.cells_total <= (
                          SELECT COUNT(*) FROM known_route_cells krc
                            JOIN discovery_cells dc
                              ON dc.cell_id = krc.cell_id AND dc.user_id = :uid
                           WHERE krc.route_id = kr.id
                        )
                  ORDER BY kr.id ASC'
            );
            $stmt->execute(['uid' => $userId]);
            return self::routeGroups($stmt->fetchAll());
        }

        // ZNANE TRASY, KTÓRYCH TA OSOBA JESZCZE NIE UKOŃCZYŁA — LUSTRO `kd-`
        // powyżej (zgłoszenie usera 2026-08-29: mapa osobista pokazywała
        // WYŁĄCZNIE trasy ukończone, więc reszta katalogu na niej w ogóle nie
        // istniała, inaczej niż na mapie społeczności). Warunek jest DOKŁADNĄ
        // NEGACJĄ warunku `kd-` (włącznie z `cells_total = 0` — trasa jeszcze
        // nieprzeliczona nigdy nie jest „ukończona", więc tu się łapie), żeby
        // każda aktywna trasa trafiała do DOKŁADNIE JEDNEJ z tych dwóch warstw,
        // nigdy do obu i nigdy do żadnej.
        if (str_starts_with($key, 'kn-')) {
            $userId = self::userIdFor($key);
            if ($userId === null) {
                return [];
            }
            $stmt = $db->prepare(
                'SELECT kr.id, kr.gpx_url, kr.color_index
                   FROM known_routes kr
                  WHERE kr.is_active = 1 AND kr.gpx_url IS NOT NULL
                    AND NOT (
                          kr.cells_total > 0
                      AND kr.cells_total <= (
                          SELECT COUNT(*) FROM known_route_cells krc
                            JOIN discovery_cells dc
                              ON dc.cell_id = krc.cell_id AND dc.user_id = :uid
                           WHERE krc.route_id = kr.id
                        )
                    )
                  ORDER BY kr.id ASC'
            );
            $stmt->execute(['uid' => $userId]);
            return self::routeGroups($stmt->fetchAll());
        }

        if (str_starts_with($key, 'e-')) {
            $stmt = $db->prepare('SELECT gpx_url FROM edition_tracks WHERE edition_id = :id');
            $stmt->execute(['id' => (int) substr($key, 2)]);
            // Kronika turnusu: kilkanaście śladów TEJ SAMEJ trasy, jeden na
            // uczestnika. Własny kolor na ślad jest tu jedyną rzeczą, która
            // pokazuje, że to kilka przejazdów, a nie jedna gruba kreska.
            return self::trackGroups(self::hashesFor($stmt->fetchAll(\PDO::FETCH_COLUMN)), 'real');
        }

        // me / u-{slug} — mapa jednej osoby. Dwie warstwy, bo to dwie różne
        // rzeczy: ślad z realizacji (z niego naliczyły się pola) i trasa
        // zapowiadana wyjazdu, którego nikt nie potwierdził. Usunięcie tej
        // drugiej ukryłoby powód, dla którego mapa jest pustsza niż lista
        // wyjazdów.
        $userId = self::userIdFor($key);
        if ($userId === null) {
            return [];
        }
        $real = EditionTrack::effectiveForUser($userId);
        $realHashes = self::hashesFor(array_column($real, 'gpx_url'));
        $withTrack = array_flip(array_map('intval', array_column($real, 'edition_id')));

        // PRZEJAZDY SOLO — WYŁĄCZNIE NA PRYWATNYM KLUCZU `me` (2026-08-26, błąd
        // zgłoszony przez usera: „ślady, które wgrałem, nie pojawiają się na
        // mapie"). Do tej daty solo nie wchodziło tu W OGÓLE — ta gałąź rysowała
        // wyłącznie ślady z wyjazdów (EditionTrack), mimo że solo to WIĘKSZOŚĆ
        // tego, co ludzie realnie jeżdżą.
        //
        // KLUCZ `u-{slug}` (publiczny, cache'owalny na dysku) TEGO NIE DOSTAJE —
        // plik solo jest surowy i zaczyna się pod domem (§27, ten sam powód, dla
        // którego solo zniknęło z warstwy społeczności `all`, patrz RiderActivity
        // ::recordSolo). `u-{slug}` jest funkcją SAMEGO SLUGU, nie widza — nie ma
        // jak sprawdzić „czy pytający to właściciel", więc treść musi być
        // bezpieczna dla KAŻDEGO, kto ją poprosi. `me` jest tego przeciwieństwem:
        // liczony na żądanie, nigdy nie ląduje na dysku (`isCacheable`), a
        // `userIdFor('me')` zwraca coś tylko wtedy, gdy pytający jest ZALOGOWANY
        // JAKO TA OSOBA (`Auth::user()->id`) — więc to zawsze WŁASNA mapa
        // właściciela.
        //
        // Konsekwencja: `Models\MapLayer` (Etap 2/3 warstw mapy) musi dla warstwy
        // „Ślady" w kontekście 'me' rozwiązywać ZAWSZE do klucza `me`, NIGDY do
        // `u-{slug}` — inaczej ktoś z publicznym profilem nie zobaczyłby swoich
        // solo na własnej mapie. Token `subject-private` (meta.contexts.me.source
        // w migracji 072) właśnie to wymusza; `rider` zostaje przy `subject`
        // (`u-{slug}`), bo to WCIĄŻ jest widok PUBLICZNY, nawet gdy patrzy na
        // niego właściciel profilu.
        if ($key === 'me') {
            $solo = $db->prepare(
                'SELECT gpx_url FROM rider_activities
                  WHERE user_id = :uid AND source_code = :src AND gpx_url IS NOT NULL'
            );
            $solo->execute(['uid' => $userId, 'src' => RiderActivity::SOURCE_SOLO]);
            $realHashes = array_values(array_unique(array_merge(
                $realHashes,
                self::hashesFor($solo->fetchAll(\PDO::FETCH_COLUMN))
            )));
        }

        // SOLO NA PUBLICZNYM PROFILU (`u-{slug}`) — OSOBNA GRUPA, NIE dopisana
        // do $realHashes wyżej (2026-09-10, zgłoszenie usera: „widzę odkryte
        // hexy, ale jego śladów z solo przejazdów nie mam na mapie" — nowy
        // użytkownik jeździ WYŁĄCZNIE solo, bez żadnego zapisu na wyjazd, więc
        // $realHashes wychodziło puste i publiczny profil nie rysował ANI
        // JEDNEJ linii, mimo że pola odkryć liczą KAŻDY przejazd, solo
        // włącznie — `hexCells()` niżej pyta `Discovery::communityCells()`,
        // zupełnie inną ścieżkę, której ta luka nigdy nie dotyczyła).
        //
        // TRIMMED, NIE PEŁNA GEOMETRIA — ten sam powód co solo w kluczu `all`
        // (migr. 076): `u-{slug}` jest PUBLICZNY i leży na dysku pod adresem
        // do zgadnięcia (sam slug), a surowy plik solo zaczyna/kończy się pod
        // domem (§27). `me` wyżej może brać PEŁNĄ geometrię, bo nigdy nie
        // ląduje na dysku i pyta wyłącznie o WŁASNE dane zalogowanego
        // właściciela — tu nie ma jak sprawdzić „czy pytający to właściciel",
        // więc treść musi być bezpieczna dla każdego, kto poprosi.
        //
        // OSOBNA GRUPA (PER ŚLAD, NIE JEDNA WSPÓLNA) — `$realHashes` schodzi
        // niżej przez `trackGroups()`, która czyta geometrię z PEŁNEJ tabeli
        // (`gpx_geometry`); zmieszanie z hashami z `gpx_geometry_trimmed`
        // w jednej grupie próbowałoby odczytać przycięty hash z niewłaściwej
        // tabeli. Dlatego solo idzie przez TĘ SAMĄ `trackGroups()`, osobnym
        // wywołaniem z parametrem `$source = 'trimmed'` (patrz niżej).
        //
        // PER-ŚLAD KOLOR, TEN SAM CO NA `me` (2026-09-10, poprawka po
        // zgłoszeniu usera: „na swoim profilu każdy przejazd jest kolorowany,
        // na czyimś powinno być tak samo" — pierwsza wersja tej naprawy dawała
        // wszystkim solo jeden wspólny kolor stylu, bo `gpx_geometry_trimmed`
        // nie ma kolumny `color_index`). Rozwiązanie BEZ zmiany schematu:
        // wołamy TAKŻE `hashesFor()` (PEŁNE `ensure()`) na tych samych
        // adresach — ten sam hash pliku, więc `gpx_geometry.color_index`
        // (przydzielany przez `GpxGeometry::assignColor()` wewnątrz `ensure()`)
        // jest tu w pełni miarodajny, mimo że do RYSOWANIA i tak bierzemy
        // geometrię PRZYCIĘTĄ. Efekt uboczny jest pożądany, nie przypadkowy:
        // ten sam plik dostaje TEN SAM kolor wszędzie, gdziekolwiek się
        // pojawia (`me`, `u-{slug}`), bo kolor jest własnością PLIKU
        // (`gpx_hash`), nie warstwy, która go akurat rysuje.
        $soloHashes = [];
        if (str_starts_with($key, 'u-')) {
            $soloTrimmed = $db->prepare(
                'SELECT gpx_url FROM rider_activities
                  WHERE user_id = :uid AND source_code = :src AND gpx_url IS NOT NULL'
            );
            $soloTrimmed->execute(['uid' => $userId, 'src' => RiderActivity::SOURCE_SOLO]);
            $soloUrls = $soloTrimmed->fetchAll(\PDO::FETCH_COLUMN);
            self::hashesFor($soloUrls); // WYŁĄCZNIE po to, żeby przydzielić/odczytać kolor — patrz wyżej
            $soloHashes = self::trimmedHashesFor($soloUrls);
        }

        $stmt = $db->prepare('
            SELECT ed.id AS edition_id, COALESCE(st.gpx_url, rv.gpx_url) AS gpx_url
              FROM event_rsvps r
              JOIN event_attendance a ON a.rsvp_id = r.id AND a.attended = 1
              JOIN event_editions ed ON ed.id = r.edition_id
              LEFT JOIN event_stages st ON st.event_id = ed.event_id AND st.gpx_url IS NOT NULL
              LEFT JOIN event_route_variants rv ON rv.event_id = ed.event_id AND rv.gpx_url IS NOT NULL
             WHERE r.user_id = :uid
        ');
        $stmt->execute(['uid' => $userId]);
        $planned = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['gpx_url'] === null || isset($withTrack[(int) $row['edition_id']])) {
                continue;
            }
            $planned[$row['gpx_url']] = true;
        }

        // TRASY ZAPOWIADANE ZOSTAJĄ JEDNĄ SZARĄ GRUPĄ — i to jest decyzja, nie
        // przeoczenie. Szarość stylu `planned` NIE jest tu „kolorem tej trasy",
        // tylko komunikatem: „to jest zapowiedź, której nikt nie potwierdził
        // śladem". Rozbicie ich na paletę zabrałoby tej warstwie całe znaczenie
        // — sześć wesołych kolorów mówiłoby dokładnie to samo co przejazdy,
        // które naprawdę się odbyły. Kolor odróżnia ślad OD ŚLADU; szarość
        // odróżnia BRAK od dokonania.
        //
        // Ślady rzeczywiste — po jednej grupie na ślad (migr. 073). To jest ta
        // gałąź, o którą poszło zgłoszenie: własna mapa („moje ślady generują
        // się w jednym kolorze").
        // SOLO NA `u-{slug}` IDZIE PRZEZ TĘ SAMĄ `trackGroups()`, osobnym
        // wywołaniem z `$source = 'trimmed'` — po jednej grupie na ślad,
        // każda w SWOIM kolorze (patrz komentarz przy `$soloHashes` wyżej
        // i przy samej `trackGroups()`), dokładnie jak ślady z wyjazdów.
        return array_merge(
            [['hashes' => self::hashesFor(array_keys($planned)), 'style' => 'planned']],
            self::trackGroups($realHashes, 'real'),
            self::trackGroups($soloHashes, 'real', 'trimmed')
        );
    }

    /**
     * Wiersze znanych tras -> grupy do narysowania, po jednej na trasę.
     *
     * Kolejność po `id` rosnąco, czyli trasy starsze pod spodem. Kolejność musi
     * być JAKAŚ ustalona, bo tam, gdzie dwa szlaki biegną tą samą drogą, widać
     * ten narysowany później — losowa kolejność zmieniałaby wynik między
     * kaflami tej samej mapy.
     *
     * ŹRÓDŁEM KOLORU JEST TU `known_routes.color_index`, a NIE kolor geometrii
     * (migr. 073) — i to jest świadome rozdzielenie. Kolor znanej trasy jest
     * własnością TRASY, nie pliku: pokazuje go dymek na mapie, karta trasy
     * i wykres profilu, więc musi zostać ten sam także wtedy, gdy ktoś podmieni
     * przebieg (nowy plik, nowy hash, TA SAMA trasa). Kolor geometrii obsługuje
     * to, co własnego koloru nie ma — czyli wszystkie zwykłe ślady.
     *
     * @param array<int,array{id:int|string,gpx_url:string,color_index:?int}> $rows
     * @return array<int,array{hashes:string[],style:string,color:string}>
     */
    private static function routeGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $hashes = self::hashesFor([$row['gpx_url']]);
            if (!$hashes) {
                continue;
            }
            $groups[] = [
                'hashes' => $hashes,
                'style'  => 'route',
                'color'  => KnownRoute::colorOf($row['color_index']),
            ];
        }
        return $groups;
    }

    /**
     * Ślady -> grupy do narysowania, PO JEDNEJ NA ŚLAD, każda w swoim kolorze
     * (migr. 073, 2026-08-27).
     *
     * ZGŁOSZENIE USERA: „wszystkie te trasy są wygenerowane w kolorze zielonym,
     * niezależnie od tego czy to solo przejazdy czy referencyjne (…) mam jedną
     * wielką zieloną plamę". Do tej daty KAŻDA z tych gałęzi zwracała JEDEN
     * worek hashy i jeden styl — więc 89 przejazdów wokół jednego miasta
     * rysowało się jednym `#2C6B4F` i nie dało się odróżnić, gdzie kończy się
     * jeden ślad, a zaczyna drugi. Kolory per obiekt miały wtedy WYŁĄCZNIE
     * znane trasy (migr. 064) i nigdy nie objęły przejazdów.
     *
     * Rozwiązanie jest DOKŁADNIE tym samym, które już działało dla szlaków —
     * jedna grupa na obiekt plus `color` nadpisujący kolor stylu — tylko
     * źródłem koloru jest `gpx_geometry.color_index` (patrz
     * `GpxGeometry::assignColor`, sąsiedztwo po wspólnych kaflach).
     *
     * KOSZT JEST BLISKI ZERA i to nie jest domysł: `TileController::renderTracks`
     * odsiewa geometrię RAZ dla całego kafla, jednym zapytaniem po WSZYSTKICH
     * hashach naraz, właśnie dlatego, że znane trasy przyzwyczaiły go do wielu
     * grup. Rozbicie worka na N grup po jednym hashu dokłada N obrotów pętli
     * po tablicy jednoelementowej, a nie N zapytań.
     *
     * Ślad BEZ przydzielonego koloru (sprzed backfillu) nie dostaje klucza
     * `color` i spada na kolor stylu — czyli rysuje się dokładnie tak, jak
     * przed tą zmianą. Brak przydziału nie ma prawa niczego zepsuć.
     *
     * `$source` (2026-09-10) — opcjonalny, przekazany dalej BEZ ZMIAN do
     * każdej grupy: `TileController::renderTracks()` czyta go, żeby wiedzieć,
     * z KTÓREJ pary tabel wziąć geometrię (`gpx_geometry`/`gpx_tiles` gdy
     * `null`, `_trimmed` gdy `'trimmed'`). Kolor bierze się jednak ZAWSZE
     * z PEŁNEJ `gpx_geometry.color_index` — ta sama tożsamość pliku
     * (`gpx_hash`), więc solo pokazane PRZYCIĘTE na czyimś publicznym profilu
     * dostaje DOKŁADNIE ten sam kolor co na własnej, prywatnej mapie (`me`).
     * `gpx_geometry_trimmed` świadomie nie ma własnej kolumny koloru —
     * dublowałaby tę samą wartość pod innym kluczem.
     *
     * @param string[] $hashes
     * @return array<int,array{hashes:string[],style:string,color?:string,source?:string}>
     */
    private static function trackGroups(array $hashes, string $style, ?string $source = null): array
    {
        if (!$hashes) {
            return [];
        }
        $kolory = GpxGeometry::colorsFor($hashes);

        $groups = [];
        foreach ($hashes as $hash) {
            $group = ['hashes' => [$hash], 'style' => $style];
            if ($source !== null) {
                $group['source'] = $source;
            }
            if (isset($kolory[$hash])) {
                $group['color'] = TrackPalette::colorOf($kolory[$hash]);
            }
            $groups[] = $group;
        }
        return $groups;
    }

    /**
     * Pola odkryć do narysowania na kaflu, z gotowym stopniem skali.
     *
     * POZIOM AGREGACJI DOBIERANY JAK W api/routes.php i to nie jest kopia dla
     * wygody — to ta sama drabinka, bo obie mapy muszą pokazywać pole tej samej
     * wielkości przy tym samym oddaleniu. Przy zmianie progów trzeba ruszyć oba
     * miejsca ORAZ podbić epokę warstwy `hex`, bo inaczej na dysku zostaną
     * kafle narysowane starą drabinką.
     *
     * @return array{cells:array<int,array{cellId:int,level:int}>,maxP:int}
     */
    public static function hexCells(string $key, int $z, int $x, int $y, int $steps): array
    {
        $res = match (true) {
            $z >= 12 => 4,
            $z >= 10 => 3,
            $z >= 8  => 2,
            $z >= 6  => 1,
            default  => 0,
        };

        // Zapas wokół kafla: pole ma ok. 500 m, więc takie, którego ŚRODEK leży
        // tuż za krawędzią kafla, i tak wchodzi na kafel rogiem. Bez zapasu
        // byłyby braki na stykach — te same, przed którymi chroni MARGIN
        // w rendererze linii.
        $bounds = TileGrid::latLonBounds($z, $x, $y, 24);

        $userId = null;
        if ($key === 'me' || str_starts_with($key, 'u-')) {
            $userId = self::userIdFor($key);
            if ($userId === null) {
                return ['cells' => [], 'maxP' => 1];
            }
        } elseif ($key !== 'all') {
            // Kronika (e-{id}) i wydarzenia nie mają własnej warstwy pól —
            // ich mapy pokazują pola przejazdu inną drogą (Discovery::
            // rideCellsForEdition), więc tutaj po prostu nie ma czego rysować.
            return ['cells' => [], 'maxP' => 1];
        }

        $cells = [];
        foreach (Discovery::communityCells($bounds, $res, $userId) as $c) {
            $cells[] = [
                'cellId' => (int) $c['cellId'],
                'level'  => self::levelOf((int) $c['passes'], max(1, (int) $c['cells']), $steps),
            ];
        }
        return ['cells' => $cells, 'maxP' => 1];
    }

    /**
     * Progi natężenia — ile razy średnio przejechano przez jedno pole.
     *
     * SKALA BEZWZGLĘDNA, A NIE WZGLĘDEM KADRU, i to jest różnica wymuszona
     * przez kafle. Wersja wektorowa liczyła stopnie względem maksimum
     * W WIDOCZNYM PROSTOKĄCIE (`maxP` z API) — kafel nie ma pojęcia o kadrze,
     * w którym się znajdzie, więc skala względna dałaby SĄSIADUJĄCYM kaflom
     * różne odniesienia: to samo pole byłoby zielone w jednym kaflu i czerwone
     * w sąsiednim. Widać to gołym okiem jako szachownicę.
     *
     * Skala bezwzględna jest przy okazji uczciwsza: kolor przestaje zmieniać
     * się przy przesuwaniu mapy, a „czerwony" znaczy zawsze to samo.
     *
     * ŚREDNIA NA POLE, NIE SUMA — bo przy oddaleniu jedno pole rysowane na
     * mapie jest sumą kilkunastu pól poziomu bazowego. Suma rosłaby z każdym
     * krokiem oddalenia i cała Polska robiłaby się czerwona bez powodu.
     * Średnia trzyma ten sam sens na każdym zoomie.
     */
    private const INTENSITY_STEPS = [2, 4, 8];

    private static function levelOf(int $passes, int $cells, int $steps): int
    {
        $avg = $passes / $cells;
        $level = 0;
        foreach (self::INTENSITY_STEPS as $threshold) {
            if ($avg >= $threshold) { $level++; }
        }
        return min($steps - 1, $level);
    }

    /**
     * Ślady w postaci potrzebnej do TRAFIENIA — hash plus to, czym jest, żeby
     * odpowiedź na klik mogła powiedzieć coś sensownego o wyjeździe.
     *
     * @return array<string,array{title:string,url:string,date:?string}>
     */
    public static function trackLabels(string $key): array
    {
        $userId = self::userIdFor($key);
        if ($userId === null) {
            return [];
        }
        $out = [];
        foreach (EditionTrack::effectiveForUser($userId) as $t) {
            $hash = self::hashOf($t['gpx_url']);
            if ($hash !== null) {
                $out[$hash] = [
                    'id'    => (int) $t['id'],
                    'title' => (string) $t['event_title'],
                    'slug'  => (string) $t['event_slug'],
                    'date'  => $t['start_date'] ?? null,
                    'url'   => (string) $t['gpx_url'],
                ];
            }
        }
        return $out;
    }

    /**
     * Zamienia adresy plików na hashe, po drodze ZAPEWNIAJĄC geometrię.
     *
     * Dlaczego zapewnia, a nie tylko czyta: pierwsze żądanie kafla po wgraniu
     * śladu ma go narysować, a nie pominąć. Koszt to jedno parsowanie na plik
     * na zawsze (zmierzone 30 ms) i płaci go pierwszy kafel, który tego pliku
     * potrzebuje — a nie każde wejście na stronę, jak przed kaflami.
     *
     * @param string[] $urls
     * @return string[]
     */
    private static function hashesFor(array $urls): array
    {
        $out = [];
        foreach (array_unique(array_filter($urls)) as $url) {
            $hash = self::hashOf($url);
            if ($hash !== null) {
                $out[] = $hash;
            }
        }
        return array_values(array_unique($out));
    }

    private static function hashOf(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        return GpxGeometry::ensure(self::absolutePath($url));
    }

    /**
     * Bliźniak `hashesFor()` dla solo w heatmapie społeczności (migr. 076) —
     * zapewnia PRZYCIĘTĄ geometrię (`GpxGeometry::ensureTrimmed`), nie pełną.
     * Osobna metoda, nie parametr w `hashesFor()`: pomylenie „przycięte"
     * z „pełne" jednym przestawionym argumentem byłoby dokładnie tym błędem
     * prywatności, którego cała ta migracja miała nie dopuścić.
     *
     * @param string[] $urls
     * @return string[]
     */
    private static function trimmedHashesFor(array $urls): array
    {
        $out = [];
        foreach (array_unique(array_filter($urls)) as $url) {
            $hash = GpxGeometry::ensureTrimmed(self::absolutePath($url));
            if ($hash !== null) {
                $out[] = $hash;
            }
        }
        return array_values(array_unique($out));
    }

    /** Adres z bazy („/assets/uploads/gpx/x.gpx") -> ścieżka na dysku. */
    public static function absolutePath(string $url): string
    {
        return CORE_PATH . '/..' . '/' . ltrim($url, '/');
    }
}
