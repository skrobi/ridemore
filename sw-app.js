// sw-app.js — SERVICE WORKER WYŁĄCZNIE DLA APLIKACJI MOBILNEJ.
// Kontrakt: tasks/active/apka-offline.md, Etap 2. Decyzja usera 2026-08-29:
// „service worker tylko w apce".
//
// ============================================================================
// DLACZEGO TO OSOBNY PLIK OD `/sw.js` — PRZECZYTAJ, ZANIM COKOLWIEK ZMIENISZ
// ============================================================================
// `/sw.js` w tym samym katalogu to KILL-SWITCH po starej aplikacji One Page:
// jego jedynym zadaniem jest wyrejestrowanie się i skasowanie WSZYSTKICH
// cache'ów u przeglądarek, które wciąż są przez niego kontrolowane. Nie wolno
// go użyć ani rozbudować — dopisanie tu logiki cache'owania zabrałoby mu tę
// rolę, a ludzie z pamięcią po starej aplikacji zostaliby z nią na zawsze.
//
// REJESTROWANY TYLKO POD `APP_IS_APP` (patrz partials/head.php). Zwykła
// przeglądarka NIGDY go nie dostaje, więc ryzyko „zmiany nie wchodzą na www",
// dla którego projekt świadomie nie miał service workera, zostaje zerowe.
// Rejestracja jest per-przeglądarka: skoro www nigdy jej nie wywoła, www nigdy
// nie będzie kontrolowane.
//
// ============================================================================
// ZASADA NADRZĘDNA: NIE PRZESZKADZAĆ
// ============================================================================
// Ten worker kontroluje KAŻDE żądanie w apce — łącznie z logowaniem, zapisami
// na wyjazd i wysyłką śladu. Błąd tutaj nie psuje jednego ekranu, tylko całą
// apkę. Dlatego `fetch` obsługuje WYŁĄCZNIE wąską białą listę, a wszystko poza
// nią przepuszcza BEZ wywołania `respondWith()` — czyli dokładnie tak, jakby
// workera nie było. Żadnego POST-a, żadnej treści użytkownika, żadnego API.
const WERSJA = 'v2';
const C_STATIC = 'rm-static-' + WERSJA;   // arkusze i skrypty serwisu
const C_CDN    = 'rm-cdn-' + WERSJA;      // Leaflet/MapLibre z CDN
const C_TILES  = 'rm-tiles-' + WERSJA;    // kafle mapy
const C_PODKLAD = 'rm-podklad-' + WERSJA; // podklad mapy (OpenFreeMap / OSM)

// Limit kafli. Kafel to ok. 5-25 kB, więc 600 sztuk to rząd kilkunastu MB —
// tyle, żeby przejechany szlak został pod ręką, i nie tyle, żeby apka rosła
// w nieskończoność na telefonie z zapchaną pamięcią.
const LIMIT_KAFLI = 600;

// Podklad wektorowy (.pbf) jest gestszy w zadaniach niz nasze PNG, ale lzejszy
// na sztuke — stad wyzszy limit przy porownywalnym rozmiarze na dysku.
const LIMIT_PODKLADU = 900;

self.addEventListener('install', (event) => {
    // Bez precache'owania listy plików: adresy assetów niosą `?v=` z czasem
    // modyfikacji (Utils\View::asset), więc wpisana tu na sztywno lista
    // rozjechałaby się przy pierwszym wdrożeniu. Cache napełnia się tym,
    // czego apka FAKTYCZNIE użyła — to też lepiej odwzorowuje teren, w którym
    // user był, niż jakikolwiek zgadywany zestaw.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        // KASUJEMY WYŁĄCZNIE WŁASNE cache'e, po prefiksie `rm-`. `caches.keys()`
        // bez tego filtra zabrałoby wszystko, co ma origin — dokładnie to robi
        // kill-switch w `/sw.js`, i tam jest to zamierzone, a tu byłoby błędem.
        const nasze = ['rm-static-', 'rm-cdn-', 'rm-tiles-', 'rm-doc-', 'rm-podklad-'];
        const aktualne = [C_STATIC, C_CDN, C_TILES, C_PODKLAD];
        const klucze = await caches.keys();
        await Promise.all(klucze.map((k) => {
            if (aktualne.includes(k)) { return null; }
            if (!nasze.some((p) => k.startsWith(p))) { return null; }
            return caches.delete(k);
        }));
        await self.clients.claim();
    })());
});

// PRZYCINANIE JEST DROGIE — `cache.keys()` wylicza CAŁĄ zawartość cache'u.
// Pierwsza wersja (2026-08-29) wołała je po KAŻDYM zapisanym kaflu, a mapa
// zapisuje ich dziesiątki na jedno przesunięcie. To była realna część
// zgłoszonego „strasznie wolno działa". Teraz liczymy zapisy i sprawdzamy
// rozmiar co `CO_ILE_SPRZATAC` — limit i tak jest miękki, więc chwilowe
// przekroczenie o kilkadziesiąt wpisów niczego nie psuje.
const CO_ILE_SPRZATAC = 50;
const licznikZapisow = {};

/** Przycięcie cache'u do limitu — najstarsze wpisy wychodzą pierwsze. */
async function przytnij(nazwa, limit) {
    licznikZapisow[nazwa] = (licznikZapisow[nazwa] || 0) + 1;
    if (licznikZapisow[nazwa] % CO_ILE_SPRZATAC !== 0) { return; }

    const cache = await caches.open(nazwa);
    const klucze = await cache.keys();
    if (klucze.length <= limit) { return; }
    // `cache.keys()` zwraca w kolejności wstawiania, więc początek listy to
    // najstarsze wpisy — nie trzeba trzymać własnych znaczników czasu.
    await Promise.all(klucze.slice(0, klucze.length - limit).map((k) => cache.delete(k)));
}

/** Najpierw sieć, cache jako zapas. Dla rzeczy, które MUSZĄ być świeże. */
async function siecPotemCache(request, nazwa) {
    const cache = await caches.open(nazwa);
    try {
        const odp = await fetch(request);
        // Odpowiedzi nieudanych żądań nie zapisujemy. `type === 'opaque'`
        // (CDN bez CORS) przepuszczamy, bo o jej statusie nie da się nic
        // powiedzieć — a bez Leafletu mapa offline i tak nie ruszy.
        if (odp && (odp.ok || odp.type === 'opaque')) {
            cache.put(request, odp.clone());
        }
        return odp;
    } catch (e) {
        const zCache = await cache.match(request);
        if (zCache) { return zCache; }
        throw e;
    }
}

/** Najpierw cache; przy pudle sieć, a zapis NIE blokuje odpowiedzi. */
async function cachePotemSiec(request, nazwa, limit) {
    const cache = await caches.open(nazwa);
    const zCache = await cache.match(request);
    if (zCache) {
        return zCache;
    }
    const odp = await fetch(request);
    if (odp && (odp.ok || odp.type === 'opaque')) {
        // ODPOWIEDŹ IDZIE DO STRONY OD RAZU, zapis leci obok.
        // Pierwsza wersja robiła `await cache.put(...)` PRZED zwróceniem
        // odpowiedzi, czyli każdy kafel mapy czekał na zapis do dysku, zanim
        // w ogóle się narysował. Przy dziesiątkach kafli na jedno przesunięcie
        // mapy to była druga połowa zgłoszonego „strasznie wolno działa".
        const kopia = odp.clone();
        cache.put(request, kopia).then(function () {
            if (limit) { przytnij(nazwa, limit); }
        }).catch(function () { /* brak miejsca na dysku nie może zabrać kafla */ });
    }
    return odp;
}

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // TYLKO GET. POST-y (logowanie, zapisy, wysyłka śladu, zgłoszenie skarbu)
    // przechodzą nietknięte — cache'owanie ich byłoby błędem, a przepuszczanie
    // przez workera niepotrzebnym ryzykiem.
    if (req.method !== 'GET') { return; }

    let url;
    try { url = new URL(req.url); } catch (e) { return; }

    // Tylko http(s). `chrome-extension:` i podobne odpadają.
    if (url.protocol !== 'http:' && url.protocol !== 'https:') { return; }

    const tenSamOrigin = url.origin === self.location.origin;

    // 1. NASZE KAFLE (mgła odkryć, ślady, znane trasy) — najpierw cache.
    //    ADRES SPRAWDZONY W KODZIE, NIE ZGADNIĘTY: leżą jako statyczne PNG pod
    //    `/assets/tiles/{warstwa}/{klucz}/{z}/{x}/{y}.png` (patrz `sources`
    //    przekazywane do ridemoreDiscoveryMap). `tiles.php` NIE jest ich
    //    adresem — .htaccess blokuje ten plik dla ruchu HTTP, generuje z CLI.
    if (tenSamOrigin && url.pathname.includes('/assets/tiles/')) {
        event.respondWith(cachePotemSiec(req, C_TILES, LIMIT_KAFLI));
        return;
    }

    // 2. PODKŁAD MAPY — bez niego offline zostaje samo szare tło z naszą mgłą,
    //    czyli obraz, po którym nie da się nawigować w terenie. Domyślnie
    //    OpenFreeMap Positron (wektor, MapLibre), zapasowo raster OSM —
    //    obsługujemy OBA, bo o wyborze decyduje dostępność WebGL na urządzeniu.
    if (!tenSamOrigin && /(^|\.)openfreemap\.org$|(^|\.)tile\.openstreetmap\.org$/.test(url.hostname)) {
        event.respondWith(cachePotemSiec(req, C_PODKLAD, LIMIT_PODKLADU));
        return;
    }

    // 3. ARKUSZE I SKRYPTY SERWISU — adres niesie `?v=` z czasem modyfikacji
    //    pliku (Utils\View::asset), więc nowa wersja to NOWY klucz cache'u
    //    i nie ma mowy o zaserwowaniu starego kodu po wdrożeniu. To jest
    //    dokładnie to ryzyko, dla którego ten projekt nie miał SW na www.
    if (tenSamOrigin && /\.(css|js)$/.test(url.pathname)) {
        event.respondWith(cachePotemSiec(req, C_STATIC));
        return;
    }

    // 4. LEAFLET I MAPLIBRE Z CDN — bez nich mapa nie ruszy offline. Najpierw
    //    sieć, żeby zatruty wpis (odpowiedź nieprzezroczysta, o której statusie
    //    nic nie wiadomo) nie został z nami na stałe.
    if (!tenSamOrigin && /^(unpkg\.com|cdn\.jsdelivr\.net)$/.test(url.hostname)) {
        event.respondWith(siecPotemCache(req, C_CDN));
        return;
    }

    // 5. DOKUMENTÓW NIE DOTYKAMY. ANI JEDNEGO.
    //
    //    Pierwsza wersja (2026-08-29) przechwytywała nawigację do `/odkrycia`,
    //    żeby ekran mapy otwierał się bez sieci. ZEPSUŁO TO APKĘ i zostało
    //    wycofane tego samego dnia — user: „klikanie na ikonę mapy kieruje nas
    //    do odkryć, a nie do dedykowanej mapy na mobile".
    //
    //    MECHANIZM, dla pamięci: `APP_IS_APP` w `core/bootstrap.php` zależy
    //    WYŁĄCZNIE od User-Agenta (doklejka `ridemore-app` z Capacitora).
    //    Przechwycona nawigacja leci ponownie przez `fetch()` z workera, a
    //    `User-Agent` jest nagłówkiem ZABRONIONYM — worker nie może go ustawić,
    //    i na Android WebView doklejka Capacitora do tych żądań nie dochodzi.
    //    Serwer widział więc zwykłą przeglądarkę i słusznie oddawał
    //    `discovery.php` zamiast `discovery-app.php`. Odpowiedź lądowała potem
    //    w cache'u, więc usterka utrwalała się na stałe.
    //
    //    ZASADA NA PRZYSZŁOŚĆ: dopóki tryb apki rozpoznaje się po User-Agencie,
    //    ŻADEN dokument nie może przejść przez `fetch()` service workera.
    //    Cache'owanie ekranu mapy do trybu offline wymaga najpierw innego
    //    sygnału trybu apki niż UA — patrz tasks/active/apka-offline.md.

    // WSZYSTKO POZOSTAŁE: bez `respondWith()`, czyli tak, jakby workera nie było.
});

