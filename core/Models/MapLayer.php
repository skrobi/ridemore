<?php
// core/Models/MapLayer.php
// DRZEWO WARSTW MAPY — Etap 2 (tasks/done/warstwy-mapy.md, 2026-08-26).
//
// Rozwiązuje słownik `map_layer` (struktura + konfiguracja, migr. 072)
// względem KONTEKSTU strony ('all'|'me'|'rider') na gotowe do użycia węzły:
// kontrolka warstw (`views/web/partials/map-layers.php`) je renderuje,
// moduł mapy (`assets/js/discovery-map.js`) czyta ich `kind`/`trackKey`/
// `filterParam`/`filterValue`.
//
// GRANICA DANE/MECHANIZM, RAZ USTALONA: słownik mówi CO i GDZIE, ta klasa
// mówi JAK TO ROZWIĄZAĆ dla konkretnego żądania (kontekst, kto pyta, czyj to
// profil) — a SAMO RYSOWANIE zostaje w JS, bo trzy rodzaje warstw rysuje się
// trzema różnymi mechanizmami (patrz `meta.kind` w migracji 072) i tego się
// nie konfiguruje w JSON-ie.
namespace Models;

class MapLayer
{
    public const DICTIONARY = 'map_layer';

    /**
     * @param string $context 'all' (społeczność), 'me' (własna mapa) albo
     *        'rider' (cudzy profil) — to samo pojęcie, co `context` w
     *        `ridemoreDiscoveryMap` (Etap 1).
     * @param array{loggedIn?:bool,slug?:?string,isOwner?:bool} $opts
     *        `loggedIn` gasi dzieci oznaczone `requiresLogin` (dziś: skarby
     *        zdobyte/nieodkryte) — gość nie ma ani jednego znaleziska, więc
     *        przełącznik nic by mu nie zmienił (ta sama zasada co dawne
     *        `$isLoggedIn` w widokach, teraz w jednym miejscu).
     *        `slug` to publiczny slug OSOBY, której dotyczy ta strona —
     *        potrzebny wyłącznie do rozwiązania źródła `subject` (patrz
     *        `tileKeyFor`). W kontekście 'me' to slug zalogowanego (może być
     *        `null`, patrz zapasowy klucz `me` w `tileKeyFor`); w 'rider' to
     *        slug profilu, NIE widza. W 'all' nie jest używany.
     *        `isOwner` (2026-08-27, zgłoszenie usera: „mam inne ślady niż na
     *        mapie własnej w odkryciach, powinienem mieć to samo") — CZY
     *        PYTAJĄCY JEST TĄ OSOBĄ. Ma znaczenie WYŁĄCZNIE w kontekście
     *        'rider': zmienia rozwiązanie źródła `subject` z publicznego
     *        `u-{slug}` na prywatne `me`, czyli DOKŁADNIE to, co ta osoba
     *        widzi na własnej mapie. Dla każdego INNEGO widza `rider` zostaje
     *        publiczny jak dotychczas — patrz `tileKeyFor`. W kontekście 'me'
     *        nie ma znaczenia (tam `subject-private` i tak zawsze daje `me`).
     *        `only` (opcjonalne) zawęża do TOP-LEVEL kluczy z tej listy —
     *        dla stron, które mapy odkryć NIE są, tylko dokładają jej fragment
     *        obok czegoś innego (strona trasy, panel dnia wydarzenia): tam
     *        „Ślady" nigdy nie miały sensu, bo strona nie jest o niczyich
     *        przejechanych trasach, tylko o JEDNEJ konkretnej. Kontekst
     *        (all/me/rider) i tak decyduje o WARTOŚCIACH; `only` decyduje,
     *        które z nich ta strona w ogóle POKAZUJE — to jest wiedza o
     *        UKŁADZIE STRONY, nie o warstwie, więc siedzi w wołaniu, nie
     *        w słowniku.
     * @return list<array{
     *     key:string, label:string, hint:string, on:bool, kind:?string,
     *     trackKey:?string, filterParam:?string, filterValue:?string,
     *     children:array
     * }>
     */
    public static function tree(string $context, array $opts = []): array
    {
        $loggedIn = !empty($opts['loggedIn']);
        $slug = $opts['slug'] ?? null;
        $only = $opts['only'] ?? null;
        $isOwner = !empty($opts['isOwner']);

        $out = [];
        foreach (Dictionary::tree(self::DICTIONARY) as $node) {
            if ($only !== null && !in_array($node['code'], $only, true)) {
                continue;
            }
            $built = self::buildNode($node, $context, $loggedIn, $slug, $isOwner);
            if ($built !== null) {
                $out[] = $built;
            }
        }
        return $out;
    }

    private static function buildNode(array $node, string $context, bool $loggedIn, ?string $slug, bool $isOwner = false): ?array
    {
        if (!$node['isActive']) {
            return null;
        }
        $meta = $node['meta'];
        $ctx = $meta['contexts'][$context] ?? null;
        // Kontekst nieobecny w `meta.contexts` — warstwa nie istnieje na tej
        // stronie. To jest dzisiejszy odpowiednik tego, co robił `rider-profile.php`
        // ręcznie, nigdy nie dopisując `treasuresFound` do swojej tablicy.
        if ($ctx === null) {
            return null;
        }
        if (($ctx['shown'] ?? true) === false) {
            return null;
        }
        // `requiresLogin` gasi WYŁĄCZNIE dla gościa — nie ma potrzeby osobnej
        // flagi „tylko zalogowani widzą tę warstwę w ogóle": dziś dotyczy to
        // wyłącznie dzieci „Skarbów", a semantyka jest identyczna na każdym
        // kontekście, który w ogóle je pokazuje.
        if (!empty($meta['requiresLogin']) && !$loggedIn) {
            return null;
        }

        $children = [];
        foreach ($node['children'] as $child) {
            $builtChild = self::buildNode($child, $context, $loggedIn, $slug, $isOwner);
            if ($builtChild !== null) {
                $children[] = $builtChild;
            }
        }

        $kind = $meta['kind'] ?? null;

        return [
            'key'          => $node['code'],
            'label'        => $node['name'],
            'hint'         => __((string) ($ctx['hint'] ?? '')),
            'on'           => (bool) ($ctx['on'] ?? true),
            'kind'         => $kind,
            // Tylko `kind === 'tiles'` niesie klucz kafla — reszta zostaje `null`,
            // żeby wołający nie musiał sprawdzać `kind` DWA razy.
            'trackKey'     => $kind === 'tiles'
                ? self::tileKeyFor((string) ($ctx['source'] ?? ''), $slug, $isOwner)
                : null,
            'filterParam'  => $meta['filterParam'] ?? null,
            'filterValue'  => $meta['filterValue'] ?? null,
            'children'     => $children,
        ];
    }

    /**
     * Tłumaczy ABSTRAKCYJNY token źródła (z `meta.contexts.{ctx}.source`) na
     * klucz kafla `Models\TileSource`. To jest MECHANIZM, nie dane — stąd
     * w kodzie, a nie w słowniku (patrz nagłówek pliku).
     *
     * `subject` bez slugu spada na `me`: kafle klucza `me` to własna,
     * niepubliczna mapa zalogowanego bez konta ze slugiem — DOKŁADNIE ta
     * sama reguła, jaką dziś stosują kontrolery wprost
     * (`$viewer->publicSlug ? 'u-'.$viewer->publicSlug : 'me'`).
     */
    public static function tileKeyFor(string $source, ?string $slug, bool $isOwner = false): ?string
    {
        return match ($source) {
            'community'    => 'all',
            'known-routes' => 'kr',
            // WŁAŚCICIEL PATRZĄCY NA WŁASNY PROFIL DOSTAJE `me` (2026-08-27,
            // zgłoszenie usera: „mam inne ślady niż na mapie własnej w
            // odkryciach, powinienem mieć to samo"). Do tej daty `rider`
            // ZAWSZE zostawał publiczny, nawet dla właściciela — decyzja
            // z 2026-08-26 (patrz niżej), świadomie napisana wprost w tym
            // miejscu. Zmieniona TERAZ, bo pokazała się myląca z drugiej
            // strony: właściciel patrzący na WŁASNY profil oczekuje tego
            // samego zestawu, który widzi na `/odkrycia` (55 śladów u
            // zgłaszającego kontra 3 na profilu — różnica to WYŁĄCZNIE
            // przejazdy solo, zdjęte tu wcześniej z powodów prywatności,
            // które właściciela nie dotyczą — to JEGO własne pliki).
            // KAŻDY INNY WIDZ (gość, inny zalogowany) dalej dostaje `u-{slug}`
            // — dla nich to WCIĄŻ jest widok publiczny i solo ma pozostać
            // niewidoczne (§27, plik zaczyna się pod czyimś domem).
            'subject'      => $isOwner
                ? 'me'
                : ($slug !== null && $slug !== '' ? 'u-' . $slug : 'me'),
            // WŁASNA MAPA, ZAWSZE PRYWATNIE (2026-08-26, naprawa błędu: „ślady,
            // które wgrałem, nie pojawiają się na mapie") — w odróżnieniu od
            // `subject`, IGNORUJE slug i zawsze zwraca `me`. Powód: klucz `me` to
            // JEDYNE miejsce, gdzie `TileSource::tracks()` dorysowuje przejazdy
            // SOLO (surowy plik, zaczyna się pod domem, §27) — publiczny
            // `u-{slug}` nigdy ich nie dostanie, bo leży na dysku i nie wie, KTO
            // pyta. Kontekst `me` (własna mapa) MUSI więc zostać na `me` nawet dla
            // kogoś z publicznym profilem, żeby zobaczył WŁASNE solo.
            'subject-private' => 'me',
            // TRASY UKOŃCZONE PRZEZ TĘ OSOBĘ (Etap 3, 2026-08-26). Zapasowy
            // klucz `kd-me` (bez slugu) to własna, prywatna wersja `kd-{slug}` —
            // ta sama para co `me`/`u-{slug}` przy śladach: pozwala widzieć
            // WŁASNE ukończenia komuś, kto nie ma publicznego profilu.
            'subject-done' => $slug !== null && $slug !== '' ? 'kd-' . $slug : 'kd-me',
            // TRASY, KTÓRYCH TA OSOBA JESZCZE NIE UKOŃCZYŁA (zgłoszenie usera
            // 2026-08-29: mapa osobista miała TYLKO `subject-done`, więc cały
            // katalog poza ukończonymi trasami znikał z niej całkiem, w
            // odróżnieniu od mapy społeczności, gdzie widać wszystko). Ten sam
            // wzorzec zapasowego klucza bez slugu co `subject-done`.
            'subject-remaining' => $slug !== null && $slug !== '' ? 'kn-' . $slug : 'kn-me',
            default        => null,
        };
    }

    /**
     * `warstwa => klucz kafla` dla wszystkich węzłów `kind === 'tiles'` drzewa,
     * które w tym kontekście w ogóle mają skąd wziąć kafle (`trackKey` nie
     * jest `null`). CZYSTA FUNKCJA, bez bazy — budowa URL-i szablonów
     * (`TileCache::urlTemplate`, wymaga epoki z bazy) zostaje po stronie
     * wołającego, który i tak ma już otwarte połączenie.
     *
     * @return array<string,string> np. ['slady' => 'u-jan', 'trails' => 'kr']
     */
    public static function tileKeysFor(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            if (($node['kind'] ?? null) === 'tiles' && $node['trackKey'] !== null) {
                $out[$node['key']] = $node['trackKey'];
            }
            if ($node['children']) {
                $out += self::tileKeysFor($node['children']);
            }
        }
        return $out;
    }

    /**
     * Opis filtrów dla `assets/js/discovery-map.js` (`ridemoreComposeFilter`)
     * — jeden wpis na węzeł niosący `filterParam` (dziś: `treasures`), złożony
     * z `filterValue` jego dzieci. Rodzina bez dzieci (np. „Skarby" na profilu
     * rowerzysty, gdzie zawężenie robi sam adres endpointu) dostaje pusty
     * `children`, co JS czyta jako „rodzic zapalony = brak filtra".
     *
     * @return array<string,array{param:string,children:array<string,string>}>
     */
    public static function filtersFor(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            if ($node['filterParam'] !== null) {
                $children = [];
                foreach ($node['children'] as $child) {
                    if ($child['filterValue'] !== null) {
                        $children[$child['key']] = $child['filterValue'];
                    }
                }
                $out[$node['key']] = ['param' => $node['filterParam'], 'children' => $children];
            }
            if ($node['children']) {
                $out += self::filtersFor($node['children']);
            }
        }
        return $out;
    }

    /**
     * Drzewo -> płaska mapa `klucz => on`, dowolna głębokość. Pod zapis stanu
     * warstw w adresie (patrz `discovery.php`): trzeba znać stan SPRZED
     * override'u z `$_GET`, żeby zapisywać w adresie tylko ODCHYLENIE od
     * domyślnego, a nie każdy klucz przy każdym kliknięciu.
     *
     * @return array<string,bool>
     */
    public static function flatten(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            $out[$node['key']] = $node['on'];
            if ($node['children']) {
                $out += self::flatten($node['children']);
            }
        }
        return $out;
    }

    /**
     * Nakłada override ze stanu w adresie (`$_GET`) na drzewo z `tree()`.
     * Klucz parametru to ZAWSZE nazwa warstwy (`?trails=0`, `?treasuresFound=0`
     * — Etap 2 ujednolica to, co dotąd było osobną nazwą po polsku na
     * stronę: `trasy`, `skarby`, `zdobyte`). Wartość `'0'` gasi, każda inna
     * (albo brak klucza) zostawia stan domyślny ze słownika.
     *
     * Modele w tym serwisie nie czytają `$_GET` same (patrz `md/models.md`) —
     * dlatego przyjmuje tablicę, a nie sięga po superglobalną, i wołający
     * (widok/kontroler) decyduje, czy w ogóle chce persystencji w adresie.
     *
     * @param array<string,mixed> $query zwykle `$_GET`
     */
    public static function withQueryOverrides(array $tree, array $query): array
    {
        foreach ($tree as &$node) {
            if (array_key_exists($node['key'], $query)) {
                $node['on'] = $query[$node['key']] !== '0';
            }
            if ($node['children']) {
                $node['children'] = self::withQueryOverrides($node['children'], $query);
            }
        }
        unset($node);
        return $tree;
    }
}
