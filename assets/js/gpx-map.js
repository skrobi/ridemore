// assets/js/gpx-map.js
// Współdzielona konfiguracja mapy Leaflet + wczytywania śladów GPX. Jedno
// źródło prawdy dla ikon markerów (start/meta/cień/punkt), PODKŁADU mapowego
// i warstwy kafelków — bez tego każde miejsce z mapą (strona wydarzenia,
// wyniki wyszukiwania, formularz eventu) powielało ten sam blok osobno, i
// poprawki (np. brakujący wptIconUrls dla znaczników <wpt>) trzeba było
// nanosić wszędzie z osobna.

const RIDEMORE_GPX_ICON_BASE = 'https://cdn.jsdelivr.net/npm/leaflet-gpx@1.7.0/';

// PODKŁADY MAPOWE (2026-08-25). Domyślny jest OpenFreeMap „Positron" —
// wektorowy podkład znacznie bardziej minimalistyczny niż raster OSM, żeby
// trasy, segmenty i pozostałe nakładki Ridemore były czytelniejsze.
//
// OpenFreeMap NIE MA kafli rastrowych (serwuje wyłącznie vector tiles pbf +
// styl MapLibre), dlatego podkład idzie przez oficjalny binding
// @maplibre/maplibre-gl-leaflet (L.maplibreGL; skrypty dokłada
// Support::leafletHead()). Binding rysuje GL-a w tilePane (z-index 200),
// czyli POD wszystkimi warstwami Ridemore: ridemoreTilesHex (350) <
// ridemoreTilesTracks (360) < overlayPane (400) < markery. Kolejność nie
// zależy od tego, co się wczyta pierwsze — pane ma własny z-index.
//
// Atrybucja: styl Positron nie niesie atrybucji źródeł (zweryfikowane na
// JSON-ie stylu), więc podajemy ją sami przez attributionControl.customAttribution
// — getAttribution() bindingu zwraca wtedy dokładnie ten tekst, a Leaflet
// sam go zdejmuje przy zdjęciu warstwy.
//
// Brak WebGL (stare WebView, wyłączona akceleracja) albo brak bindingu:
// spadamy na raster OSM zamiast zostawić szarą mapę.
const RIDEMORE_BASE_LAYERS = {
    ofm: {
        label: 'OpenFreeMap – Positron',
        styleUrl: 'https://tiles.openfreemap.org/styles/positron',
        attribution: '&copy; <a href="https://openfreemap.org" target="_blank">OpenFreeMap</a>'
            + ' &copy; <a href="https://www.openmaptiles.org" target="_blank">OpenMapTiles</a>'
            + ' &middot; dane &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
    },
    osm: {
        label: 'OpenStreetMap',
        tileUrl: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
    },
};
// PODKŁAD DOMYŚLNY: RASTER OPENSTREETMAP (decyzja usera 2026-08-29 — „przełączmy
// w całej aplikacji webowej warstwę na OpenStreetMap, bo kafle mapy bazowej nie
// chcą się ładować"). Dotyczy CAŁEGO serwisu, nie tylko apki: przez tę stałą
// przechodzi każda mapa (ridemoreCreateMap → ridemoreSetBaseLayer).
//
// OpenFreeMap (`ofm`) ZOSTAJE w zestawie jako wybór, nie znika — zmienia się
// wyłącznie to, co dostaje ktoś, kto niczego nie wybrał. Powód praktyczny:
// wektor wymaga WebGL, pobrania stylu, glifów i sprite'ów, zanim narysuje
// pierwszy piksel, a przy niedostępnym hoście MapLibre potrafi zostawić szarą
// mapę zamiast zgłosić błąd. Raster rysuje się kafel po kaflu i pierwszy widok
// pojawia się od razu — na telefonie w terenie to ważniejsze niż uroda mapy.
const RIDEMORE_BASE_DEFAULT = 'osm';

function ridemoreWebGlAvailable() {
    try {
        const canvas = document.createElement('canvas');
        return !!(window.WebGLRenderingContext && (canvas.getContext('webgl') || canvas.getContext('experimental-webgl')));
    } catch (e) { return false; }
}

// key wg RIDEMORE_BASE_LAYERS. Zwraca warstwę BEZ dodania do mapy — dodaje
// ridemoreSetBaseLayer, który pilnuje też zdjęcia poprzedniego podkładu.
function ridemoreCreateBaseLayer(key) {
    let def = RIDEMORE_BASE_LAYERS[key] || RIDEMORE_BASE_LAYERS[RIDEMORE_BASE_DEFAULT];
    if (def.styleUrl && !(window.L && L.maplibreGL && ridemoreWebGlAvailable())) {
        def = RIDEMORE_BASE_LAYERS.osm;
    }
    if (def.styleUrl) {
        try {
            return L.maplibreGL({
                style: def.styleUrl,
                attributionControl: { customAttribution: def.attribution },
            });
        } catch (e) {
            // Konstruktor GL rzuca przy niemożliwym kontekście WebGL.
            return L.tileLayer(RIDEMORE_BASE_LAYERS.osm.tileUrl, { attribution: RIDEMORE_BASE_LAYERS.osm.attribution });
        }
    }
    return L.tileLayer(def.tileUrl, { attribution: def.attribution });
}

// KOLEJKA NA SKRYPTY GL (2026-08-25): maplibre-gl i binding idą z `defer`,
// żeby ~1 MB JS nie blokował renderu całej strony. Defer wykonuje się dopiero
// PO parsowaniu HTML, a kilka stron tworzy mapy już w inline `<script>` —
// taka mapa dostaje OD RAZU raster OSM (nic nie czeka na sieć), a gdy binding
// dojdzie, tick podmienia jej podkład na wektorowy OpenFreeMap. Próby są
// liczone, żeby przy trwale niedostępnym CDN nie kręcić interwałem w nieskończoność —
// wtedy po prostu zostaje OSM, czyli degradacja do dawnego wyglądu.
const RIDEMORE_GL_CZEKA = [];
let ridemoreGlTimer = null;
let ridemoreGlProby = 0;

function ridemoreCzekajNaGl() {
    if (ridemoreGlTimer || !RIDEMORE_GL_CZEKA.length) return;
    ridemoreGlTimer = setInterval(function () {
        if (window.L && L.maplibreGL) {
            clearInterval(ridemoreGlTimer);
            ridemoreGlTimer = null;
            while (RIDEMORE_GL_CZEKA.length) {
                const para = RIDEMORE_GL_CZEKA.shift();
                // `_ridemoreOczekuje` gaśnie, gdy ktoś zdążył ręcznie ustawić
                // inny podkład — wtedy kolejka nie nadpisuje jego decyzji.
                if (para[0]._ridemoreOczekuje === true) ridemoreSetBaseLayer(para[0], para[1]);
            }
            return;
        }
        if (++ridemoreGlProby > 200) { // 200 × 150 ms = ~30 s; dalej bez sensu
            clearInterval(ridemoreGlTimer);
            ridemoreGlTimer = null;
        }
    }, 150);
}

// Przełącznik podkładu dla JEDNEJ mapy — na przyszły UI wyboru warstw
// (kontrolka map-layers.php dotyczy nakładek aplikacyjnych, nie podkładu).
// ridemoreSetBaseLayer(map, 'osm') wraca do OpenStreetMap.
function ridemoreSetBaseLayer(map, key) {
    map._ridemoreOczekuje = false;
    if (map._ridemoreBase && map.hasLayer(map._ridemoreBase)) map.removeLayer(map._ridemoreBase);
    const def = RIDEMORE_BASE_LAYERS[key] || RIDEMORE_BASE_LAYERS[RIDEMORE_BASE_DEFAULT];
    if (def.styleUrl && !(window.L && L.maplibreGL)) {
        // Binding jeszcze nie wykonany (defer) — raster OSM na start, wektor
        // wchodzi sam po dojściu skryptów (ridemoreCzekajNaGl).
        map._ridemoreOczekuje = true;
        map._ridemoreBase = L.tileLayer(RIDEMORE_BASE_LAYERS.osm.tileUrl, { attribution: RIDEMORE_BASE_LAYERS.osm.attribution }).addTo(map);
        RIDEMORE_GL_CZEKA.push([map, key]);
        ridemoreCzekajNaGl();
        return map._ridemoreBase;
    }
    map._ridemoreBase = ridemoreCreateBaseLayer(key).addTo(map);
    return map._ridemoreBase;
}

// PODŁOGA ZOOMU (2026-08-25, zgłoszenie usera: „startujemy zbyt małego
// zooma, na samym początku nic się nie ładuje"). Chodzi WYŁĄCZNIE o zoom,
// nie o geografię — serwis może się rozrastać na kolejne kraje, więc żadnych
// ograniczeń przesuwania po mapie. Widok kontynentu (z2–4) niczego nie wnosi,
// a wygląda jak niedziałająca mapa: podkład wektorowy jest tam prawie pusty,
// a własne kafle Ridemore i tak mają dolny próg minZoom 4 (patrz
// ridemoreAddTileLayer).
const RIDEMORE_MAP_LIMITS = {
    minZoom: 5,
};

function ridemoreCreateMap(el, view) {
    // maxZoom 18 jawnie: dotąd limit brał się z warstwy kafli OSM (TileLayer
    // nadaje mapie swój maxZoom); warstwa wektorowa nie jest GridLayer-em,
    // więc bez tej wartości suwak zoomu pozwalałby dalej niż dziś.
    const map = L.map(el, Object.assign({ maxZoom: 18 }, RIDEMORE_MAP_LIMITS)).setView(view || [52.0, 19.3], 6);
    ridemoreSetBaseLayer(map, RIDEMORE_BASE_DEFAULT);
    // Pełny ekran dla KAŻDEJ mapy — patrz nota nad ridemoreAddFullscreenControl.
    ridemoreAddFullscreenControl(map);
    // „Tu jestem" dla KAŻDEJ mapy — ta sama zasada co linijkę wyżej.
    ridemoreAddLocateControl(map);
    return map;
}

// PEŁNY EKRAN MAPY (2026-08-23, zgłoszenie usera: „w mapach brakuje nam jeszcze
// ikonki w rogu ekranu (prawy dolny) full screen mapy, obecnie to strasznie
// małe mapy").
//
// DLACZEGO TUTAJ, A NIE NA STRONACH: przez `ridemoreCreateMap` przechodzi
// KAŻDA mapa w serwisie (odkrycia, profil, trasa, wydarzenie, kronika, lista
// wyjazdów, picker w kreatorze, panel skarbów). Wpięcie kontrolki w fabrykę
// znaczy, że nowa mapa dostaje ją bez pamiętania o niczym — ta sama zasada co
// przy wspólnej kontrolce warstw (`partials/map-layers.php`).
//
// BEZ WTYCZKI (leaflet.fullscreen i pokrewne): natywne Fullscreen API daje
// wszystko, czego tu trzeba — wyjście Escape'em, ukrycie paska przeglądarki
// i obsługę na poziomie systemu. Wtyczka byłaby kolejnym plikiem do ładowania
// i kolejną zależnością, która musi przeżyć aktualizację Leafletu.

/**
 * CO rozciągamy na pełny ekran. Kontrolki mapy (warstwy, legenda) są
 * RODZEŃSTWEM kontenera Leafletu, nie jego dziećmi — `map-layers.php` kładzie
 * je w rodzicu z `position: relative`. Rozciągając sam kontener, zostawilibyśmy
 * je poza ekranem, czyli pełny ekran zabierałby przełącznik warstw i legendę
 * dokładnie wtedy, gdy jest najwięcej co oglądać.
 */
function ridemoreFullscreenBox(container) {
    var parent = container.parentElement;
    return parent && parent.querySelector('.map-layers, .hex-legend') ? parent : container;
}

function ridemoreAddFullscreenControl(map) {
    if (!window.L || !L.Control) { return; }

    var box = ridemoreFullscreenBox(map.getContainer());
    box.classList.add('map-fs');

    var wejscie = box.requestFullscreen || box.webkitRequestFullscreen;
    var wyjscie = document.exitFullscreen || document.webkitExitFullscreen;
    var przycisk = null;

    var IKONA_WEJDZ = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>';
    var IKONA_WYJDZ = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3M16 21v-3a2 2 0 0 1 2-2h3"/></svg>';

    function wPelnym() {
        return document.fullscreenElement === box
            || document.webkitFullscreenElement === box
            || box.classList.contains('is-map-fs');
    }

    function opisz() {
        if (!przycisk) { return; }
        var pelny = wPelnym();
        przycisk.innerHTML = pelny ? IKONA_WYJDZ : IKONA_WEJDZ;
        // Tytuł ORAZ aria-label: pierwszy to dymek dla myszy, drugi jedyna
        // treść dla czytnika ekranu (w środku jest samo SVG).
        przycisk.title = pelny ? __('Zamknij pełny ekran') : __('Pełny ekran');
        przycisk.setAttribute('aria-label', przycisk.title);
    }

    // Leaflet mierzy kontener przy tworzeniu i nie zauważa sam, że urósł do
    // całego ekranu — bez invalidateSize kafle zostają na starym obszarze,
    // a reszta mapy jest szara. Z opóźnieniem, bo przejście do pełnego ekranu
    // nie kończy się w tej samej klatce, w której pada zdarzenie.
    function poZmianie() {
        setTimeout(function () { map.invalidateSize(); opisz(); }, 120);
    }

    // ZASTĘPCZY PEŁNY EKRAN — nakładka `position: fixed`. Potrzebny, bo
    // natywnego API nie ma iOS Safari dla zwykłych elementów (tylko wideo),
    // a WebView aplikacji mobilnej potrafi go odmówić. Bez tego przycisk
    // istniałby na telefonie i nie robił nic — czyli dokładnie ta usterka,
    // którą właśnie naprawiamy, tylko gdzie indziej.
    function zastepczyWejdz() {
        box.classList.add('is-map-fs');
        document.addEventListener('keydown', naEscape);
        poZmianie();
    }

    function naEscape(e) {
        if (e.key === 'Escape') { wyjdz(); }
    }

    function wejdzPelny() {
        if (wejscie) {
            var wynik;
            try {
                wynik = wejscie.call(box);
            } catch (e) {
                zastepczyWejdz();
                return;
            }
            // Odmowa przychodzi odrzuconą obietnicą (np. przy braku gestu
            // użytkownika albo w osadzonej ramce bez `allowfullscreen`).
            if (wynik && wynik.catch) { wynik.catch(zastepczyWejdz); }
            return;
        }
        zastepczyWejdz();
    }

    function wyjdz() {
        if (box.classList.contains('is-map-fs')) {
            box.classList.remove('is-map-fs');
            document.removeEventListener('keydown', naEscape);
            poZmianie();
            return;
        }
        if (wyjscie) { wyjscie.call(document); }
    }

    var Kontrolka = L.Control.extend({
        // PRAWY DOLNY RÓG — wprost z prośby usera. Zoom siedzi w lewym górnym,
        // warstwy w lewym górnym rogu kontenera, więc ten róg jest jedynym
        // wolnym; atrybucja Leafletu ustawia się pod przyciskiem sama.
        options: { position: 'bottomright' },
        onAdd: function () {
            var wrap = L.DomUtil.create('div', 'leaflet-bar leaflet-control map-fs__ctrl');
            przycisk = L.DomUtil.create('a', '', wrap);
            przycisk.href = '#';
            przycisk.setAttribute('role', 'button');
            opisz();
            // Bez tego kliknięcie w przycisk łapie też mapa pod spodem —
            // przy podwójnym kliknięciu skończyłoby się przybliżeniem.
            L.DomEvent.disableClickPropagation(wrap);
            L.DomEvent.on(przycisk, 'click', function (e) {
                L.DomEvent.preventDefault(e);
                wPelnym() ? wyjdz() : wejdzPelny();
            });
            return wrap;
        }
    });

    map.addControl(new Kontrolka());

    // Wyjście Escape'em albo systemowym gestem idzie POZA naszym przyciskiem,
    // więc ikonę i rozmiar mapy poprawiamy ze zdarzenia, nie z kliknięcia.
    document.addEventListener('fullscreenchange', poZmianie);
    document.addEventListener('webkitfullscreenchange', poZmianie);
}


// „TU JESTEM" — WYŚRODKOWANIE MAPY NA POZYCJI GPS (2026-09-10, zgłoszenie
// usera: „centrowanie po kliknięciu na mapę w miejscu lokalizacji GPS. Jeśli
// nie ma, to pokazać, że czekasz na sygnał").
//
// DLACZEGO W FABRYCE, A NIE NA JEDNEJ STRONIE. Mechanizm centrowania istniał
// dotąd w JEDNYM miejscu — środkowy slot dolnego paska apki, i tylko na
// `/odkrycia` (`[data-rm-map-here]`, obsługa w `discovery-app.php`). Miał
// dwie wady, obie zgłoszone: nie było go PRZY MAPIE, czyli tam, gdzie ręka
// szuka go w każdej innej aplikacji z mapą, i **milczał, gdy pozycji nie
// było** — `.catch(function () {})`, po czym przez trzydzieści sekund nie
// działo się dosłownie nic. Wpięcie w `ridemoreCreateMap` znaczy, że każda
// mapa (odkrycia, trasa, wydarzenie, profil, zgłoszenie skarbu, kronika,
// picker w kreatorze) dostaje go bez pamiętania o niczym — ta sama zasada,
// dla której stoi tu pełny ekran i dla której warstwy są wspólnym partialem.
//
// PRZEZ MOST, NIE PRZEZ `navigator.geolocation` (assets/js/native.js): w apce
// pyta natywna wtyczka, w przeglądarce silnik przeglądarki. Jedno API, jeden
// komunikat błędu, ta sama ścieżka na obu.
//
// STAN JEST TU CAŁĄ FUNKCJĄ, NIE OZDOBĄ. Zimny fix GPS na dworze to zwykle
// 15–30 s (dlatego most ma domyślny timeout 30 s) — bez pokazanego „szukam"
// każde dotknięcie wygląda przez pół minuty jak przycisk, który nie działa.
// Stąd trzy stany widoczne dla oka: szukam / mam / nie mam, a przy „nie mam"
// ODRÓŻNIONA odmowa zgody od braku sygnału (rozróżnia je `positionError`
// w moście — dotąd nikt tego rozróżnienia nie pokazywał).
function ridemoreAddLocateControl(map) {
    if (!window.L || !L.Control) { return; }

    var IKONA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.2" fill="currentColor" stroke="none"/>'
        + '<path d="M12 1.6v3.2M12 19.2v3.2M22.4 12h-3.2M4.8 12H1.6"/></svg>';

    var wrap = null, przycisk = null, komunikat = null;
    var wTrakcie = false, timerUkrycia = null;

    function powiedz(tekst, klasa) {
        if (!komunikat || !wrap) { return; }
        clearTimeout(timerUkrycia);
        wrap.classList.remove('is-busy', 'is-err');
        if (klasa) { wrap.classList.add(klasa); }
        if (!tekst) { komunikat.hidden = true; komunikat.textContent = ''; return; }
        komunikat.textContent = tekst;
        komunikat.hidden = false;
    }

    // Komunikat o błędzie znika sam po chwili — mapa jest tu treścią, a nie
    // tłem dla powiadomienia. „Szukam" NIE znika po czasie: znika dopiero
    // wtedy, gdy szukanie się skończy, bo inaczej skłamałoby o stanie.
    function powiedzNaChwile(tekst, klasa) {
        powiedz(tekst, klasa);
        timerUkrycia = setTimeout(function () { powiedz(null); }, 6000);
    }

    function lokalizuj() {
        if (wTrakcie) { return; }
        if (!window.RM || !RM.native || typeof RM.native.position !== 'function') {
            powiedzNaChwile(__('Lokalizacja nie jest tu dostępna.'), 'is-err');
            return;
        }
        wTrakcie = true;
        powiedz(__('Szukam sygnału GPS…'), 'is-busy');

        // ŚWIADOME DOTKNIĘCIE CZEKA DŁUŻEJ NIŻ AUTOMAT. Kadr startowy pyta
        // z krótkim czasem i `maximumAge`, bo ma czym wypełnić ekran, gdy GPS
        // milczy (prostokąt odkryć z serwera). Tutaj wypełniacza nie ma:
        // człowiek nacisnął, BO CHCE swojej pozycji, więc czekamy tyle, ile
        // realnie trwa zimny fix, zamiast po ośmiu sekundach oddać błąd.
        // `maximumAge: 10000` zostaje mimo to — pozycja sprzed dziesięciu
        // sekund jest dobra, a pojawia się natychmiast.
        RM.native.position({ highAccuracy: true, timeout: 30000, maximumAge: 10000 })
            .then(function (poz) {
                // Zdarzenie PRZED przesunięciem kadru: strona, która pilnuje
                // własnych flag („user ruszył mapą palcem" w discovery-app.php),
                // ma zdążyć je zdjąć, zanim mapa ruszy.
                map.fire('ridemore:locate', { lat: poz.lat, lon: poz.lon, accuracy: poz.accuracy });
                map.setView([poz.lat, poz.lon], Math.max(map.getZoom(), 15));
                powiedz(null);
            })
            .catch(function (err) {
                // Treść układa most (`RM.native.positionError`) — odmowa zgody
                // brzmi inaczej niż brak sygnału, i o to tu chodzi.
                powiedzNaChwile((err && err.message) || __('Nie udało się ustalić pozycji.'), 'is-err');
            })
            .then(function () { wTrakcie = false; });
    }

    // Uchwyt na mapie — ten sam wzorzec co `map.ridemoreSetLayer`
    // i `map.ridemoreFocusTreasure`. Dzięki niemu przycisk „Mapa" w dolnym
    // pasku apki nie ma własnej kopii tej logiki, tylko woła tę samą drogę.
    map.ridemoreLocate = lokalizuj;

    var Kontrolka = L.Control.extend({
        // PRAWY DOLNY RÓG, POD pełnym ekranem: kciuk trzyma telefon od dołu,
        // a w apce zoom i pełny ekran są schowane (app.css), więc ten róg
        // należy w całości do tego przycisku.
        options: { position: 'bottomright' },
        onAdd: function () {
            wrap = L.DomUtil.create('div', 'leaflet-control map-loc');

            komunikat = L.DomUtil.create('span', 'map-loc__msg', wrap);
            komunikat.hidden = true;
            // `polite`, nie `assertive`: to podpowiedź o stanie, a nie alarm —
            // ma doczekać końca zdania czytnika, a nie wchodzić mu w słowo.
            komunikat.setAttribute('role', 'status');
            komunikat.setAttribute('aria-live', 'polite');

            var bar = L.DomUtil.create('div', 'leaflet-bar map-loc__ctrl', wrap);
            przycisk = L.DomUtil.create('a', '', bar);
            przycisk.href = '#';
            przycisk.setAttribute('role', 'button');
            przycisk.innerHTML = IKONA;
            przycisk.title = __('Pokaż moją pozycję');
            przycisk.setAttribute('aria-label', przycisk.title);

            L.DomEvent.disableClickPropagation(wrap);
            L.DomEvent.on(przycisk, 'click', function (e) {
                L.DomEvent.preventDefault(e);
                lokalizuj();
            });
            return wrap;
        }
    });

    map.addControl(new Kontrolka());
}


function ridemoreGpxMarkerOptions() {
    // Domyślny fallback biblioteki dla zwykłych <wpt> (bez rozpoznanego
    // sym/type) to URL względny 'pin-icon-wpt.png' — u nas nieistniejący plik,
    // stąd puste/rozbite markery punktów oznaczonych w GPX-ie.
    const wptIconUrls = { '': RIDEMORE_GPX_ICON_BASE + 'pin-icon-wpt.png' };
    return {
        startIconUrl: RIDEMORE_GPX_ICON_BASE + 'pin-icon-start.png',
        endIconUrl: RIDEMORE_GPX_ICON_BASE + 'pin-icon-end.png',
        shadowUrl: RIDEMORE_GPX_ICON_BASE + 'pin-shadow.png',
        wptIconUrls: wptIconUrls,
        wptIconTypeUrls: wptIconUrls,
    };
}

// onLoaded(e) dostaje standardowy event leaflet-gpx (e.target === track) —
// wywołujący dobiera co z nim zrobić (fitBounds pojedynczo czy łącznie).
//
// opacity/dashArray obsługiwane, bo profil rowerzysty rysuje na jednej mapie
// dwie klasy linii: ślad z odbytego wyjazdu (pełna) i trasę zapowiadaną, której
// nikt nie potwierdził (przerywana, wyblakła). Bez tego różnica dałaby się
// pokazać tylko kolorem, a kolor na tej mapie znaczy już co innego.
//
// hideMarkers — pinezki startu/mety mają sens dla POJEDYNCZEJ trasy; na mapie
// całej historii kilkanaście par pinezek zasłania to, co pokazują.
function ridemoreAddGpxTrack(map, url, options) {
    options = options || {};
    const style = { color: options.color || '#D14E1E', weight: options.weight || 4 };
    if (options.opacity !== undefined) style.opacity = options.opacity;
    if (options.dashArray) style.dashArray = options.dashArray;
    const track = new L.GPX(url, {
        async: true,
        polyline_options: style,
        marker_options: options.hideMarkers
            ? { startIconUrl: null, endIconUrl: null, shadowUrl: null, wptIconUrls: {}, wptIconTypeUrls: {} }
            : ridemoreGpxMarkerOptions(),
    });
    if (options.onLoaded) track.on('loaded', options.onLoaded);
    track.addTo(map);
    return track;
}

// KAFLE MAP (migr. 051) — od 2026-08-14 to jest DOMYŚLNY sposób pokazywania
// śladów i pól odkryć na każdej mapie w serwisie.
//
// Zastępuje pętlę „pobierz N plików GPX i narysuj je wektorowo", która nie
// skalowała się z liczbą śladów: zmierzone na profilu z 600 przejazdami to
// ok. 108 MB pobierania, ok. 25 s do pierwszego obrazu i ok. 2,7 s zwiechy na
// każdy krok zoomu. Kafel kosztuje tyle samo przy 6 śladach co przy 6000, bo
// jest gotowym obrazkiem — przeglądarka nie parsuje ani nie rzutuje niczego.
//
// maxNativeZoom: 18 (od 2026-08-20) — kafle generują się do samego końca skali,
// więc Leaflet nie rozciąga już ostatniego obrazu. Wcześniej stało tu 14 i przy
// maksymalnym przybliżeniu oglądało się kafel powiększony szesnastokrotnie:
// przy śladzie GPS bywało to niewidoczne, ale linia ZNANEJ TRASY robiła się
// rozmytym pasem. Poziomy głębsze niż 14 nie są zapisywane na dysku (patrz
// Utils\TileGrid::MAX_Z i Controllers\TileController) — powstają na żądanie
// i są tanie, bo taki kafel obejmuje ok. 150 m terenu.
//
// Panele własne, bo kolejność warstw nie może zależeć od tego, co wczyta się
// pierwsze: pola pod spodem (350), ślady nad nimi (360), a warstwa wektorowa
// pojedynczego podświetlonego śladu w overlayPane (400) — nad wszystkim.
const RIDEMORE_TILE_PANES = { hex: 350, tracks: 360 };

function ridemoreAddTileLayer(map, url, options) {
    options = options || {};
    const paneName = 'ridemoreTiles' + (options.kind === 'hex' ? 'Hex' : 'Tracks');
    if (!map.getPane(paneName)) {
        map.createPane(paneName).style.zIndex = RIDEMORE_TILE_PANES[options.kind === 'hex' ? 'hex' : 'tracks'];
    }
    return L.tileLayer(url, {
        pane: paneName,
        minZoom: options.minZoom || 4,
        // Kafle generują się do 18 (2026-08-20). Wcześniej stało tu 14 i wyżej
        // Leaflet ROZCIĄGAŁ ostatni obraz — przy zoomie 18 szesnastokrotnie,
        // więc linia trasy robiła się rozmytym pasem. Patrz Utils\TileGrid::MAX_Z.
        maxNativeZoom: 18,
        maxZoom: 18,
        // Kafel pustkowia to 355 B przezroczystego PNG, a nie 404 — dlatego bez
        // errorTileUrl. Braki w siatce zdarzają się tylko przy błędzie serwera
        // i wtedy dziura jest informacją, nie usterką do zamaskowania.
        // KAFEL POWSTAJE NA ŻĄDANIE, WIĘC NIE ZAMAWIAMY GO NA NIBY (2026-09-02).
        //
        // `updateWhenIdle: false` znaczy „ładuj także w trakcie przeciągania",
        // co przy zwykłym serwerze kafli jest darmowe — obrazek albo jest
        // w cache'u CDN-a, albo nie. Tutaj KAŻDY kafel to żądanie do PHP, które
        // renderuje go od zera. Zmierzone przy przeciągnięciu mapy: 30
        // zamówionych kafli, 18 doczekanych — czyli 40% renderów szło do kosza,
        // a mimo to zajmowało serwer i miejsce w sześciu połączeniach
        // przeglądarki, blokując te kafle, na które człowiek naprawdę czekał.
        updateWhenIdle: true,
        keepBuffer: 2,
        crossOrigin: false,
        attribution: options.attribution || null,
    }).addTo(map);
}

// Renderuje wykres profilu wysokości Z OSIAMI (X: dystans, Y: wysokość, siatka,
// podpisane wartości) w realnych pikselach kontenera — świadomie NIE przez
// stały viewBox rozciągany przez preserveAspectRatio="none" jak poprzednio:
// niejednolite skalowanie (inny mnożnik dla X niż dla Y) rozciągało też
// grubość linii, robiąc ją nieproporcjonalną. Tu układ współrzędnych budowany
// jest raz, w prawdziwych px zmierzonego kontenera — stąd wymaga JS, nie da
// się tego policzyć w PHP bez znajomości rzeczywistej szerokości na ekranie.
//
// Dorzuca pasy koloru pod linią profilu dla skategoryzowanych podjazdów (patrz
// Utils\Gpx::describeClimb() — kategorie jak w Garmin ClimbPro/Strava) oraz
// zsynchronizowany punkt: najechanie/przeciągnięcie po wykresie ORAZ klik na
// "chip" szczytu w tym samym dniu rysuje kropkę na wykresie i pinezkę na
// mapie w tym samym miejscu trasy. Bez lat/lon w profilu (stare, sprzed
// backfillu dane) sam wykres z osiami i tak się renderuje — tylko synchronizacja
// z mapą się nie włącza (połowiczna interaktywność myliłaby bardziej niż brak).
//
// profile: [{d, e, lat, lon}, ...] (Utils\Gpx::sampleProfile()).
// peaks: [{d, e, climbStartD, category, categoryColor}, ...] (Utils\Gpx::detectPeaks()).
/**
 * WSPÓLNY KURSOR WYKRESÓW PROFILU (2026-09-11).
 *
 * Od kiedy pod profilem wysokości stoją osobne wykresy tętna, kadencji i mocy
 * (prośba usera: „jak na Stravie — osobne wykresy na jednej skali km"), sama
 * wspólna oś to za mało: żeby porównać „tu był podjazd, a tu tętno", kursor
 * musi stać w tym samym kilometrze NA WSZYSTKICH naraz. Najechanie na
 * którykolwiek przesuwa więc pozostałe i pinezkę na mapie.
 *
 * Rejestr jest MODUŁOWY, nie per strona: strona przejazdu rysuje kilka
 * wykresów jednym wywołaniem i nie ma ich skąd ze sobą poznajomić.
 * `origin` chroni przed pętlą — nadawca nie dostaje własnego zdarzenia.
 */
var ridemoreProfileCursors = [];

function ridemoreProfileCursorRegister(kursor) {
    ridemoreProfileCursors.push(kursor);
}

function ridemoreProfileCursorBroadcast(distanceKm, origin) {
    ridemoreProfileCursors.forEach(function (k) {
        if (k !== origin) { k.show(distanceKm); }
    });
}

function ridemoreProfileCursorHide(origin) {
    ridemoreProfileCursors.forEach(function (k) {
        if (k !== origin) { k.hide(); }
    });
}

function ridemoreRenderElevationChart(container, map, profile, peaks, color) {
    if (!container || container.dataset.linked || !profile || profile.length < 2) return;
    container.dataset.linked = '1';
    color = color || '#D14E1E';
    peaks = peaks || [];

    const width = container.getBoundingClientRect().width || 400;
    const height = 150;
    const margin = { top: 12, right: 10, bottom: 22, left: 44 };
    const plotW = width - margin.left - margin.right;
    const plotH = height - margin.top - margin.bottom;

    const elevations = profile.map(function (p) { return p.e; });
    const rawMin = Math.min.apply(null, elevations);
    const rawMax = Math.max.apply(null, elevations);
    // "Nice ticks" — dobiera okrągły krok siatki (10/20/25/50/100/200/250/500/1000 m)
    // celując w ok. 4 podziałki, zamiast dzielić surowy zakres na sztywną
    // liczbę części (dawało nierówne wartości typu "360 m", "470 m").
    const niceSteps = [10, 20, 25, 50, 100, 200, 250, 500, 1000];
    const roughStep = Math.max(rawMax - rawMin, 1) / 4;
    const step = niceSteps.find(function (s) { return s >= roughStep; }) || niceSteps[niceSteps.length - 1];
    const niceMin = Math.floor(rawMin / step) * step;
    const niceMax = Math.max(Math.ceil(rawMax / step) * step, niceMin + step);
    const range = niceMax - niceMin;
    const gridStep = step;
    const maxD = profile[profile.length - 1].d || 1;

    function xOf(d) { return margin.left + (d / maxD) * plotW; }
    function yOf(e) { return margin.top + plotH - ((e - niceMin) / range) * plotH; }

    const ns = 'http://www.w3.org/2000/svg';
    function el(tag, attrs) {
        const node = document.createElementNS(ns, tag);
        for (const key in attrs) node.setAttribute(key, attrs[key]);
        return node;
    }

    container.innerHTML = '';
    const svg = el('svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height });

    // Siatka + etykiety osi Y — co gridStep metrów, od niceMin do niceMax.
    for (let gridE = niceMin; gridE <= niceMax + 0.001; gridE += gridStep) {
        const y = yOf(gridE);
        svg.appendChild(el('line', { x1: margin.left, x2: width - margin.right, y1: y, y2: y, class: 'elevation-gridline' }));
        const label = el('text', { x: margin.left - 6, y: y + 3, class: 'elevation-axis-label', 'text-anchor': 'end' });
        label.textContent = Math.round(gridE) + ' m';
        svg.appendChild(label);
    }

    // Etykiety osi X (dystans) — start / środek / koniec.
    [0, maxD / 2, maxD].forEach(function (d, i) {
        const label = el('text', {
            x: xOf(d), y: height - 6, class: 'elevation-axis-label',
            'text-anchor': i === 0 ? 'start' : (i === 2 ? 'end' : 'middle'),
        });
        label.textContent = d.toFixed(d < 10 ? 1 : 0) + ' km';
        svg.appendChild(label);
    });

    // Pasy skategoryzowanych podjazdów pod linią profilu (jak kolorowane
    // odcinki w Garmin ClimbPro) — od doliny startu podjazdu do szczytu.
    peaks.forEach(function (peak) {
        if (!peak.categoryColor || peak.climbStartD == null) return;
        const segment = profile.filter(function (p) { return p.d >= peak.climbStartD && p.d <= peak.d; });
        if (segment.length < 2) return;
        const top = segment.map(function (p) { return xOf(p.d) + ',' + yOf(p.e); });
        const bottom = margin.top + plotH;
        const points = top.concat([xOf(peak.d) + ',' + bottom, xOf(peak.climbStartD) + ',' + bottom]).join(' ');
        svg.appendChild(el('polygon', { points: points, fill: peak.categoryColor, class: 'climb-band' }));
    });

    // Sama linia profilu — jednolita grubość, bo cała siatka współrzędnych
    // jest już w realnych pikselach (bez zniekształcającego skalowania viewBox).
    const linePoints = profile.map(function (p) { return xOf(p.d) + ',' + yOf(p.e); }).join(' ');
    svg.appendChild(el('polyline', {
        points: linePoints, fill: 'none', stroke: color,
        'stroke-width': '2', 'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));

    // Podpisy szczytów nad linią.
    peaks.forEach(function (peak) {
        const label = el('text', { x: xOf(peak.d), y: yOf(peak.e) - 8, class: 'elevation-peak-label', 'text-anchor': 'middle' });
        label.textContent = peak.e + ' m' + (peak.category ? ' · kat. ' + peak.category : '');
        svg.appendChild(label);
    });

    const cursorLine = el('line', { y1: margin.top, y2: margin.top + plotH, class: 'elevation-cursor-line' });
    cursorLine.style.display = 'none';
    svg.appendChild(cursorLine);
    const cursorDot = el('circle', { r: '4', fill: '#fff', stroke: color, 'stroke-width': '2.5' });
    cursorDot.style.display = 'none';
    cursorDot.style.pointerEvents = 'none';
    svg.appendChild(cursorDot);

    container.appendChild(svg);

    if (profile[0].lat == null || profile[0].lon == null) return;

    let mapMarker = null;

    function nearestPoint(distanceKm) {
        let nearest = profile[0];
        let bestDiff = Infinity;
        for (let i = 0; i < profile.length; i++) {
            const diff = Math.abs(profile[i].d - distanceKm);
            if (diff < bestDiff) { bestDiff = diff; nearest = profile[i]; }
        }
        return nearest;
    }

    // `cichy` = „ustaw się, ale nie rozgłaszaj dalej" — tak wchodzi zdarzenie
    // przyniesione z innego wykresu. Bez tego dwa wykresy odbijałyby sobie
    // kursor w nieskończoność.
    function showAt(p, cichy) {
        const x = xOf(p.d);
        cursorLine.setAttribute('x1', x);
        cursorLine.setAttribute('x2', x);
        cursorLine.style.display = '';
        cursorDot.setAttribute('cx', x);
        cursorDot.setAttribute('cy', yOf(p.e));
        cursorDot.style.display = '';
        if (!mapMarker) {
            mapMarker = L.circleMarker([p.lat, p.lon], { radius: 7, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1 }).addTo(map);
        } else {
            mapMarker.setLatLng([p.lat, p.lon]);
        }
        if (!cichy) { ridemoreProfileCursorBroadcast(p.d, kursor); }
    }

    // Wpis do wspólnego rejestru — pozwala wykresom tętna/kadencji/mocy pod
    // spodem przesuwać TEN kursor (i pinezkę na mapie), i odwrotnie.
    const kursor = {
        show: function (d) { showAt(nearestPoint(d), true); },
        hide: function () { hideSelf(); },
    };
    ridemoreProfileCursorRegister(kursor);

    function handleClientX(clientX) {
        const rect = svg.getBoundingClientRect();
        const px = (clientX - rect.left) * (width / rect.width) - margin.left;
        const fraction = Math.min(Math.max(px / plotW, 0), 1);
        showAt(nearestPoint(fraction * maxD));
    }

    function hideSelf() {
        cursorDot.style.display = 'none';
        cursorLine.style.display = 'none';
    }

    function hide() {
        hideSelf();
        ridemoreProfileCursorHide(kursor);
    }

    svg.addEventListener('mousemove', function (e) { handleClientX(e.clientX); });
    svg.addEventListener('mouseleave', hide);
    svg.addEventListener('touchstart', function (e) { handleClientX(e.touches[0].clientX); }, { passive: true });
    svg.addEventListener('touchmove', function (e) { handleClientX(e.touches[0].clientX); }, { passive: true });
    svg.addEventListener('touchend', hide);

    // Klik na "chip" szczytu w tym samym dniu (data-peak-d="dystans_km") od
    // razu pokazuje ten punkt — nie trzeba trafić najechaniem dokładnie w wierzchołek.
    const panel = container.closest('.day-panel');
    if (panel) {
        panel.querySelectorAll('.peak-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                showAt(nearestPoint(parseFloat(chip.dataset.peakD)));
            });
        });
    }
}

/**
 * WYKRES DODATKOWEGO KANAŁU Z PLIKU LICZNIKA — tętno, kadencja, moc,
 * temperatura (2026-09-11, prośba usera: „jeśli mamy dostępne jakieś inne
 * parametry z GPX, to również powinny być zwizualizowane... jak na Stravie,
 * osobne wykresy na jednej skali km").
 *
 * OSOBNY WYKRES NA KANAŁ, nie jeden z czterema liniami. Tętno (bpm), kadencja
 * (rpm), moc (W) i temperatura (°C) nie mają wspólnej jednostki ani wspólnego
 * rzędu wielkości — na jednej osi Y moc przygniotłaby kadencję do kreski przy
 * zerze. Wspólna jest OŚ X i tylko ona, bo tam pytanie brzmi „co się działo
 * w tym samym kilometrze".
 *
 * TA SAMA SKALA CO PROFIL WYSOKOŚCI wynika z danych, nie z przeliczania:
 * próbki przychodzą z TEJ SAMEJ tablicy (`Gpx::sampleProfile` dokłada kanały
 * obok `e`), więc `maxD` i marginesy są identyczne, a punkty stoją pionowo
 * jeden nad drugim.
 *
 * @param {Element} container  pusty div na wykres
 * @param {Array}   profile    te same próbki, co profil wysokości
 * @param {Object}  spec       {key, label, unit, color, avg, max}
 * @param {boolean} showAxis   czy rysować podpisy kilometrów (ostatni wykres)
 */
function ridemoreRenderMetricChart(container, profile, spec, showAxis) {
    if (!container || container.dataset.linked || !profile || profile.length < 2) return;

    // Próbki BEZ odczytu (czujnik zgubił kontakt na kilka kilometrów) nie mogą
    // udawać zera — linia ma się w tym miejscu PRZERWAĆ. Stąd niżej rysujemy
    // odcinkami, a nie jedną polilinią przez całość.
    const values = profile.map(function (p) {
        const v = p[spec.key];
        return (typeof v === 'number') ? v : null;
    });
    if (!values.some(function (v) { return v !== null; })) return;

    container.dataset.linked = '1';

    const width = container.getBoundingClientRect().width || 400;
    const height = 96;
    // Marginesy IDENTYCZNE jak w wykresie wysokości — inaczej kilometr 30 na
    // tętnie wypadłby w innym miejscu niż kilometr 30 na profilu, a cała
    // wartość wspólnej skali brałaby się z przypadku.
    const margin = { top: 18, right: 10, bottom: showAxis ? 18 : 6, left: 44 };
    const plotW = width - margin.left - margin.right;
    const plotH = height - margin.top - margin.bottom;

    const known = values.filter(function (v) { return v !== null; });
    const rawMin = Math.min.apply(null, known);
    const rawMax = Math.max.apply(null, known);
    // Dolna krawędź na zerze dla kadencji i mocy (tam zero coś znaczy — postój,
    // zjazd bez pedałowania), a dla tętna i temperatury przy najniższym
    // odczycie: wykres tętna od zera byłby paskiem w górnej ćwiartce.
    const zeroBased = spec.key === 'cad' || spec.key === 'pwr';
    const min = zeroBased ? 0 : Math.floor(rawMin / 5) * 5;
    const max = Math.max(Math.ceil(rawMax / 5) * 5, min + 5);
    const range = max - min;
    const maxD = profile[profile.length - 1].d || 1;

    function xOf(d) { return margin.left + (d / maxD) * plotW; }
    function yOf(v) { return margin.top + plotH - ((v - min) / range) * plotH; }

    const ns = 'http://www.w3.org/2000/svg';
    function el(tag, attrs) {
        const node = document.createElementNS(ns, tag);
        for (const key in attrs) node.setAttribute(key, attrs[key]);
        return node;
    }

    container.innerHTML = '';
    const svg = el('svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height });

    // Siatka: tylko dół i góra zakresu. Wykres ma 96 px — cztery podziałki jak
    // w profilu wysokości zamieniłyby go w tabelkę.
    [min, max].forEach(function (v) {
        const y = yOf(v);
        svg.appendChild(el('line', { x1: margin.left, x2: width - margin.right, y1: y, y2: y, class: 'elevation-gridline' }));
        const label = el('text', { x: margin.left - 6, y: y + 3, class: 'elevation-axis-label', 'text-anchor': 'end' });
        label.textContent = Math.round(v);
        svg.appendChild(label);
    });

    if (showAxis) {
        [0, maxD / 2, maxD].forEach(function (d, i) {
            const label = el('text', {
                x: xOf(d), y: height - 5, class: 'elevation-axis-label',
                'text-anchor': i === 0 ? 'start' : (i === 2 ? 'end' : 'middle'),
            });
            label.textContent = d.toFixed(d < 10 ? 1 : 0) + ' km';
            svg.appendChild(label);
        });
    }

    // Nagłówek w rogu wykresu zamiast osobnego wiersza nad nim — cztery kanały
    // to cztery wiersze podpisów, a każdy zjadałby tyle wysokości co ćwierć
    // samego wykresu.
    const tytul = el('text', { x: margin.left, y: 12, class: 'metric-chart__ttl' });
    tytul.textContent = spec.label + ' · ' + __('śr. {avg} {u} · maks. {max} {u}', { avg: spec.avg, max: spec.max, u: spec.unit });
    svg.appendChild(tytul);

    // Linia odcinkami — przerwa w danych zostaje przerwą.
    let biezacy = [];
    const odcinki = [];
    values.forEach(function (v, i) {
        if (v === null) {
            if (biezacy.length > 1) { odcinki.push(biezacy); }
            biezacy = [];
            return;
        }
        biezacy.push(xOf(profile[i].d) + ',' + yOf(v));
    });
    if (biezacy.length > 1) { odcinki.push(biezacy); }

    odcinki.forEach(function (pts) {
        // Wypełnienie pod linią — półprzezroczyste, więc kolor kanału niesie
        // rozpoznanie („czerwone to tętno") bez legendy pod spodem.
        const dol = margin.top + plotH;
        const pierwszy = pts[0].split(',')[0];
        const ostatni = pts[pts.length - 1].split(',')[0];
        svg.appendChild(el('polygon', {
            points: pts.concat([ostatni + ',' + dol, pierwszy + ',' + dol]).join(' '),
            fill: spec.color, class: 'metric-chart__fill',
        }));
        svg.appendChild(el('polyline', {
            points: pts.join(' '), fill: 'none', stroke: spec.color,
            'stroke-width': '1.6', 'stroke-linejoin': 'round', 'stroke-linecap': 'round',
        }));
    });

    const cursorLine = el('line', { y1: margin.top, y2: margin.top + plotH, class: 'elevation-cursor-line' });
    cursorLine.style.display = 'none';
    svg.appendChild(cursorLine);
    const cursorDot = el('circle', { r: '3.5', fill: '#fff', stroke: spec.color, 'stroke-width': '2' });
    cursorDot.style.display = 'none';
    cursorDot.style.pointerEvents = 'none';
    svg.appendChild(cursorDot);
    const cursorVal = el('text', { y: 12, class: 'metric-chart__val', 'text-anchor': 'end' });
    cursorVal.style.display = 'none';
    svg.appendChild(cursorVal);

    container.appendChild(svg);

    function nearestIndex(distanceKm) {
        let best = 0;
        let bestDiff = Infinity;
        for (let i = 0; i < profile.length; i++) {
            const diff = Math.abs(profile[i].d - distanceKm);
            if (diff < bestDiff) { bestDiff = diff; best = i; }
        }
        return best;
    }

    function showAt(distanceKm, cichy) {
        const i = nearestIndex(distanceKm);
        const x = xOf(profile[i].d);
        cursorLine.setAttribute('x1', x);
        cursorLine.setAttribute('x2', x);
        cursorLine.style.display = '';

        if (values[i] === null) {
            // Przerwa w danych: kreska stoi (bo kilometr jest ten sam), ale
            // kropka i liczba znikają — nie ma czego pokazać.
            cursorDot.style.display = 'none';
            cursorVal.style.display = 'none';
        } else {
            cursorDot.setAttribute('cx', x);
            cursorDot.setAttribute('cy', yOf(values[i]));
            cursorDot.style.display = '';
            cursorVal.setAttribute('x', width - margin.right);
            cursorVal.textContent = values[i] + ' ' + spec.unit;
            cursorVal.style.display = '';
        }
        if (!cichy) { ridemoreProfileCursorBroadcast(profile[i].d, kursor); }
    }

    function hideSelf() {
        cursorDot.style.display = 'none';
        cursorLine.style.display = 'none';
        cursorVal.style.display = 'none';
    }

    function hide() {
        hideSelf();
        ridemoreProfileCursorHide(kursor);
    }

    const kursor = {
        show: function (d) { showAt(d, true); },
        hide: hideSelf,
    };
    ridemoreProfileCursorRegister(kursor);

    function handleClientX(clientX) {
        const rect = svg.getBoundingClientRect();
        const px = (clientX - rect.left) * (width / rect.width) - margin.left;
        const fraction = Math.min(Math.max(px / plotW, 0), 1);
        showAt(fraction * maxD);
    }

    svg.addEventListener('mousemove', function (e) { handleClientX(e.clientX); });
    svg.addEventListener('mouseleave', hide);
    svg.addEventListener('touchstart', function (e) { handleClientX(e.touches[0].clientX); }, { passive: true });
    svg.addEventListener('touchmove', function (e) { handleClientX(e.touches[0].clientX); }, { passive: true });
    svg.addEventListener('touchend', hide);
}

/**
 * Rysuje wszystkie dodatkowe wykresy opisane przez `partials/metric-charts.php`.
 *
 * Bliźniak `ridemoreSetupElevationProfile` i z tego samego powodu: zna BRAMKĘ
 * NA ZEROWY KONTENER. Wykres liczy się w realnych pikselach i tylko raz, więc
 * policzony przy szerokości 0 (karta jeszcze nieotwarta, układ nieprzeliczony)
 * zostaje na zawsze w awaryjnych 400 px.
 *
 * @param {Element} root element z `data-metric-charts` (domyślnie pierwszy)
 */
function ridemoreSetupMetricCharts(root) {
    var box = root || document.querySelector('[data-metric-charts]');
    if (!box || !box.dataset.metricProfile) { return; }

    var rysuj = function () {
        var profile = JSON.parse(box.dataset.metricProfile);
        var kanaly = box.querySelectorAll('[data-metric-key]');
        kanaly.forEach(function (el, i) {
            ridemoreRenderMetricChart(
                el.querySelector('.metric-chart__c'),
                profile,
                JSON.parse(el.dataset.metricSpec),
                // Podpisy kilometrów TYLKO pod ostatnim wykresem — oś jest
                // wspólna, więc powtórzona przy każdym byłaby czterokrotnym
                // powiedzeniem tego samego.
                i === kanaly.length - 1
            );
        });
    };

    if (box.getBoundingClientRect().width > 0 || !window.ResizeObserver) {
        rysuj();
        return;
    }
    var ro = new ResizeObserver(function () {
        if (box.getBoundingClientRect().width > 0) {
            ro.disconnect();
            rysuj();
        }
    });
    ro.observe(box);
}

/**
 * PODPIĘCIE PROFILU WYSOKOŚCI DO MAPY — jedno miejsce dla wszystkich stron,
 * które rysują `partials/elevation-profile.php` (2026-09-03).
 *
 * Powstało, bo ten sam kawałek stał SKOPIOWANY w widokach: odczyt trzech
 * atrybutów `data-`, wywołanie `ridemoreRenderElevationChart` i — najmniej
 * oczywiste — BRAMKA NA ZEROWY KONTENER. Wykres buduje się w REALNYCH
 * pikselach i tylko raz (`dataset.linked`), więc policzony przy szerokości 0
 * zostaje na zawsze w awaryjnych 400 px na pełnowymiarowej stronie (zmierzone
 * przy pierwszym otwarciu karty, zanim przeglądarka policzy układ).
 * `ResizeObserver`, a nie `setTimeout` — czekamy na FAKT, że kontener dostał
 * rozmiar, a nie na zgadnięte opóźnienie.
 *
 * @param {Object} map   mapa Leafletu, na której ma się pokazywać pinezka
 * @param {Element} root element z wykresem (domyślnie pierwszy na stronie)
 */
function ridemoreSetupElevationProfile(map, root) {
    var el = root || document.querySelector('.day-profile-big');
    if (!el || !el.dataset.elevationProfile || typeof ridemoreRenderElevationChart !== 'function') {
        return;
    }

    var rysuj = function () {
        ridemoreRenderElevationChart(
            el,
            map,
            JSON.parse(el.dataset.elevationProfile),
            JSON.parse(el.dataset.elevationPeaks || '[]'),
            el.dataset.elevationColor
        );
    };

    if (el.getBoundingClientRect().width > 0 || !window.ResizeObserver) {
        rysuj();
        return;
    }
    var ro = new ResizeObserver(function () {
        if (el.getBoundingClientRect().width > 0) {
            ro.disconnect();
            rysuj();
        }
    });
    ro.observe(el);
}

