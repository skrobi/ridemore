// assets/js/app-tracking.js
// NAGRYWANIE ŚLADU W TLE + ALERTY „ZBLIŻASZ SIĘ DO SKARBU" — Etap 5b
// przebudowy apki mobilnej (tasks/active/apka-mobilna.md), 2026-08-28.
// Ładowany WYŁĄCZNIE w trybie apki (APP_IS_APP), z layout.php — działa
// niezależnie od tego, która podstrona jest akurat otwarta, bo tło może się
// włączyć z każdego ekranu, nie tylko z mapy.
//
// PODZIAŁ PRACY: ten plik odpowiada za zgodę, start/stop nagrywania, bufor
// pozycji, wysyłkę śladu na koniec i sprawdzanie odległości do skarbów
// z lokalnego cache'a. NIE RYSUJE NICZEGO NA MAPIE — gdy skarb wejdzie/wyjdzie
// z promienia alertu, wysyła zdarzenia `rm:treasure-near`/`rm:treasure-far`
// na `window`, które łapie (jeśli akurat jest otwarty) skrypt mapy w
// discovery-app.php. Rozdział świadomy: ten plik działa też wtedy, gdy user
// jest na zupełnie innym ekranie apki, gdzie żadnej mapy Leaflet nie ma.
//
// ŚLAD IDZIE NA ISTNIEJĄCY ENDPOINT (SoloRideController::upload) — zero
// nowej tabeli i zero nowego kodu serwerowego pod samo nagrywanie. Dzięki
// temu pola odkryte, punkty, kronika i automatyczne zaliczenie skarbów po
// drodze (Treasure::claimAlongTrack) działają tak samo, jak przy ręcznie
// wgranym GPX.
(function () {
    'use strict';

    // Upload wymaga sesji — gościa nie ma po co śledzić, a bez URL-i (globalne
    // zmienne z layout.php, tylko w trybie apki) ten plik nie ma czego robić.
    if (!window.RM_LOGGED_IN || !window.RM_UPLOAD_URL) { return; }
    if (!window.RM || !window.RM.native) { return; }

    var native = RM.native;

    // --- Preferencje: Capacitor Preferences z fallbackiem do localStorage,
    // ten sam wzorzec co w discovery-map.js dla zwiniętych paneli. ---
    var Prefs = (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Preferences) || null;
    function prefGet(key) {
        if (Prefs) { return Prefs.get({ key: key }).then(function (r) { return r.value; }); }
        try { return Promise.resolve(localStorage.getItem(key)); } catch (e) { return Promise.resolve(null); }
    }
    function prefSet(key, value) {
        if (Prefs) { return Prefs.set({ key: key, value: value }); }
        try { localStorage.setItem(key, value); } catch (e) {}
        return Promise.resolve();
    }

    var K_CONSENT = 'rm_bg_consent';  // '1' | '0' | brak = jeszcze nie pytano
    var K_BUFFER  = 'rm_bg_points';   // JSON — bufor bieżącego/przerwanego nagrania
    var K_SESSION = 'rm_bg_session';  // JSON {watcherId, startedAt, lastAt} — TRWAJĄCY przejazd
    var K_ALERTED = 'rm_bg_alert_';   // + treasure id -> epoch ms ostatniego alertu

    // PRZEJAZD TRWA DO „ZATRZYMAJ" I PRZEŻYWA PRZEŁADOWANIE STRONY (decyzja
    // usera 2026-08-29). Apka chodzi na `server.url`, więc KAŻDE przejście
    // między ekranami to pełne przeładowanie i drugie uruchomienie tego pliku.
    // Do tej pory znaczyło to: wyślij bufor jako osobny przejazd, zacznij nowy
    // watcher, a stary zostaw w usłudze natywnej (jego id żyło tylko w pamięci
    // strony, więc nie było już czym go zdjąć). Ślad z jednej jazdy rozpadał
    // się na kawałki, KAŻDY z własnym przycięciem 400 m od końców — czyli
    // znikały też hexy ze środka trasy. Stan sesji trzymamy więc w Preferences.
    var STALE_MS = 30 * 60 * 1000; // tyle bez nowego punktu = przejazd uznajemy za skończony
    // ILE CZEKAMY NA POTWIERDZENIE STARTU. Stan „Włączam…" był do 2026-08-30
    // ŚLEPY: wchodziło się w niego zawsze, a wychodziło WYŁĄCZNIE wtedy, gdy
    // obietnica z `addWatcher()` się rozstrzygnęła. Gdy nie rozstrzygnęła się
    // nigdy (a most Capacitora potrafi tak zrobić — patrz `startSession`),
    // chip zostawał na „Włączam…" do końca życia procesu, dotknięcia były
    // ignorowane, a usługa natywna spokojnie nagrywała z własnym
    // powiadomieniem. Dokładnie to zgłoszenie: „dostaję powiadomienie, że
    // rozpoczyna, ale status się nie zmienia". Teraz stan przejściowy MA
    // TERMIN — po nim albo mamy dowód, że nagrywanie idzie, albo mówimy, że
    // nie wyszło i pozwalamy spróbować ponownie.
    var START_TIMEOUT_MS = 20 * 1000;
    var FLUSH_CO = 5;               // co ile punktów zrzut na dysk (~125 m przy distanceFilter 25 m)

    var STATIONARY_MS    = 20 * 60 * 1000; // auto-stop po 20 min bez ruchu
    var MOVE_MIN_M        = 10;             // poniżej tego = „stoję”

    // --- OSZCZĘDZANIE ŚLADU NA POSTOJU (2026-08-29, pytanie usera: „nie
    // potrzebuję pobierać lokalizacji, jeśli stoję w miejscu i walić w miejscu
    // 1000 punktów") ---
    //
    // Wtyczka tła ma własny `distanceFilter` (15 m, native.js) i liczy go od
    // POPRZEDNIEGO ODCZYTU. Na szum to za mało: pozycja skacząca ±16 m wokół
    // jednego miejsca przeskakuje ten próg za każdym razem, a kolejne odczyty
    // dzieli 32 m — czyli filtr odległości ich nie odsieje. Zmierzone
    // symulacją: 40 odczytów szumu na postoju = 40 punktów w śladzie.
    //
    // Odsiewają je dopiero DWA sygnały, których dotąd nie czytaliśmy, choć most
    // je przekazuje:
    //   1. DOKŁADNOŚĆ — skok o 30 m przy `accuracy` rzędu 50 m to nie ruch,
    //      tylko zgadywanie odbiornika. Taki odczyt odrzucamy w całości.
    //   2. PRĘDKOŚĆ — z Dopplera, znacznie pewniejsza niż różnica dwóch pozycji.
    //      Prawie zero = stoję, więc podnosimy próg przesunięcia tak, żeby
    //      wszedł dopiero prawdziwy ruch, a nie dryf.
    var MAX_ACC_M          = 40;   // gorszy odczyt niż to = pozycja zgadywana, nie zmierzona
    var STOI_MAX_MS        = 1.0;  // m/s (3,6 km/h) — poniżej traktujemy jak postój
    // 40 m, nie 30: szum potrafi skakać ±16 m wokół miejsca, czyli 32 m między
    // kolejnymi odczytami — próg 30 m przepuszczał co trzeci (zmierzone).
    // Wyższy próg jest tu prawie darmowy, bo dotyczy WYŁĄCZNIE stanu „prędkość
    // poniżej 3,6 km/h": gdy tylko ruszysz, próg sam spada do 10 m.
    var MOVE_MIN_STOJAC_M  = 40;   // ile trzeba się przemieścić STOJĄC, żeby to był ruch
    var ALERT_BUFFER_M    = 150;            // zapas nad claim_radius_m skarbu
    var ALERT_COOLDOWN_MS = 24 * 60 * 60 * 1000; // jeden alert na skarb na dobę
    var NEARBY_MARGIN_DEG = 0.15;           // ~15-16 km wokół ostatniego pobrania

    // JEDEN STAN NAGRYWANIA, CZTERY WARTOŚCI (przebudowa 2026-08-29 po
    // zgłoszeniu: „dostałem powiadomienie, że Ridemore nagrywa, wchodzę w mapę
    // — mam »Nagraj«, klikam i przenosi mnie do uploadu GPX").
    //
    // PRZYCZYNA BYŁA JEDNA, choć objawów kilka: stan żył w TRZECH miejscach —
    // w usłudze natywnej (powiadomienie), w Preferences (`rm_bg_session`)
    // i w pamięci JS tej konkretnej strony. Pamięć JS ginie przy KAŻDEJ
    // nawigacji, usługa nie ginie nigdy, a Preferences czyta się
    // asynchronicznie — więc UI malował się z pamięci, zanim ktokolwiek
    // sprawdził, co jest prawdą.
    //
    // Zasada, która z tego zostaje: **nic nie malujemy, dopóki nie wiemy**,
    // a `boolean` nie wystarczy, bo „próbuję wystartować" to osobny stan —
    // to w nim tkwiło zgłoszone kliknięcie.
    var STAN = 'off';   // 'off' | 'startuje' | 'on' | 'blad'
    function trwa() { return STAN === 'on' || STAN === 'startuje'; }
    var watcherId        = null;
    var buffer            = [];
    var lastPoint         = null;
    var lastMoveAt         = Date.now();
    var startedAt          = null;
    var stationaryCheckId = null;
    var startTimeoutId    = null;   // termin dla stanu 'startuje' — patrz START_TIMEOUT_MS
    var pillTimerId       = null;
    var nearby             = null;   // {bbox, items:[...]}
    var currentlyNear     = {};      // treasure id -> true, do zdarzeń „far"

    // BEZ LEAFLETA CELOWO: ten plik działa na KAŻDYM ekranie apki (nagrywanie
    // jest globalne), a Leaflet ładuje się tylko na stronach z mapą
    // (Support::gpxMapHead()/leafletMapHead()). Ekran mapy do WŁASNEGO
    // rysowania i tak używa L.latLng().distanceTo() — to tu liczy WYŁĄCZNIE
    // próg alertu, poza kontekstem jakiejkolwiek mapy.
    function haversineM(a, b) {
        var R = 6371000, toRad = function (d) { return d * Math.PI / 180; };
        var dLat = toRad(b.lat - a.lat), dLon = toRad(b.lon - a.lon);
        var s = Math.sin(dLat / 2) * Math.sin(dLat / 2)
            + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(s)));
    }

    // --- Bufor punktów: pamięć + okresowy zrzut do Preferences, żeby
    // ubicie procesu przez system nie zabrało całego nagrania naraz. ---
    //
    // PUNKT, KTÓRY NIE PRZESUWA ŚLADU, NIE WCHODZI DO BUFORA (2026-08-29,
    // pytanie usera: „nie potrzebuję pobierać lokalizacji, jeśli stoję
    // w miejscu i walić w miejscu 1000 punktów").
    //
    // Wtyczka tła ma własny filtr odległości (`distanceFilter`, patrz
    // native.js) i na stojącym telefonie w otwartym terenie faktycznie milczy.
    // Ale filtr liczy dystans od POPRZEDNIEGO ODCZYTU, a nie od miejsca, więc
    // szum GPS pod drzewami czy między blokami potrafi go przeskoczyć w kółko:
    // 16 m w lewo, 16 m w prawo, i tak co kilkanaście sekund, bez ruszania się
    // z miejsca. Każdy taki odczyt dopisywał punkt do śladu.
    //
    // Dlatego liczymy dystans od OSTATNIEGO ZAPISANEGO punktu, nie od
    // ostatniego widzianego — inaczej dryf po 9 m sumowałby się w nieskończoność
    // i nigdy nie przekroczył progu.
    /** Ile metrów musi dzielić punkt od poprzedniego, żeby w ogóle był ruchem.
     *  W jeździe niski (ślad ma mieć kształt), na postoju wysoki (ma nie mieć
     *  szumu). `speed` bywa `null` — wtedy zostajemy przy progu jazdy, bo
     *  odsianie prawdziwego ruchu jest gorsze niż zapisanie paru punktów
     *  za dużo. */
    function progRuchu(p) {
        var v = (typeof p.speed === 'number' && p.speed >= 0) ? p.speed : null;
        return (v !== null && v < STOI_MAX_MS) ? MOVE_MIN_STOJAC_M : MOVE_MIN_M;
    }

    function pushPoint(p) {
        var ostatni = buffer.length ? buffer[buffer.length - 1] : null;
        // Dystans liczony od OSTATNIEGO ZAPISANEGO punktu, nie od ostatniego
        // widzianego — inaczej dryf po 9 m sumowałby się w nieskończoność
        // i nigdy nie przekroczył progu.
        if (ostatni && haversineM(ostatni, p) < progRuchu(p)) { return; }
        buffer.push(p);
        if (buffer.length % FLUSH_CO === 0) { flushBuffer(); }
    }
    function flushBuffer() {
        var zapis = prefSet(K_BUFFER, JSON.stringify(buffer));
        if (STAN === 'on') { zapiszSesje(); }
        return zapis;
    }

    /** Znacznik trwającego przejazdu. `lastAt` rozstrzyga przy następnym
     *  uruchomieniu, czy jazda jeszcze trwa, czy system ubił apkę na dobre. */
    function zapiszSesje() {
        return prefSet(K_SESSION, JSON.stringify({
            watcherId: watcherId,
            startedAt: startedAt,
            lastAt: Date.now()
        }));
    }

    // OSTATNI ZRZUT PRZED ODEJŚCIEM ZE STRONY. Bez tego przy każdym przejściu
    // między ekranami ginęły punkty zebrane od ostatniego zrzutu. Best effort —
    // Preferences zapisuje asynchronicznie i strona może nie doczekać; stąd
    // zrzut co pięć punktów, a nie co dwadzieścia.
    window.addEventListener('pagehide', function () {
        if (trwa()) { flushBuffer(); }
    });

    // PRZESTRZEŃ NAZW GPX JEST OBOWIĄZKOWA, NIE OZDOBNA (2026-08-29).
    // `Utils\Gpx::parse` szuka punktów xpath-em `//gpx:trkpt` z prefiksem
    // związanym z `http://www.topografix.com/GPX/1/1`, więc dokument bez
    // `xmlns` ma trkpt-y w ŻADNEJ przestrzeni — parser nie widzi ani jednego
    // i odrzuca plik („Brak punktów trasy w pliku GPX"). Tak kończyło się
    // KAŻDE nagranie z apki: ani jednego odkrytego pola, ani jednego skarbu
    // po drodze. Każdy inny generator GPX w projekcie (`Utils\Fit::toGpx`,
    // pliki testowe) deklaruje tę samą przestrzeń — ten jeden jej nie miał.
    function toGpx(points) {
        var trkpts = points.map(function (p) {
            var t = p.time ? new Date(p.time).toISOString() : new Date().toISOString();
            return '<trkpt lat="' + p.lat + '" lon="' + p.lon + '"><time>' + t + '</time></trkpt>';
        }).join('');
        return '<?xml version="1.0" encoding="UTF-8"?>'
            + '<gpx version="1.1" creator="ridemore-app" xmlns="http://www.topografix.com/GPX/1/1">'
            + '<trk><name>Nagranie w tle</name><trkseg>'
            + trkpts + '</trkseg></trk></gpx>';
    }

    // Wysyłka na ISTNIEJĄCY endpoint solo-przejazdów — patrz nagłówek pliku.
    //
    // PYTAMY O JSON (`Accept`), a nie o zwykłe PRG, z dwóch powodów naraz:
    //   1. `fetch` ŚLEDZI przekierowanie, więc przy PRG to ON konsumował
    //      jednorazową sesję z podsumowaniem jazdy — otwarcie podsumowania
    //      trafiało już na pusty klucz i wracało na listę zamiast pokazać wynik;
    //   2. po tym samym przekierowaniu `r.ok` jest prawdą RÓWNIEŻ dla strony
    //      z `?blad=...`, więc ODRZUCONY ślad wyglądał stąd identycznie jak
    //      policzony — i bufor lądował w koszu razem z całym nagraniem.
    // Zwracamy trzy stany, bo każdy znaczy co innego dla bufora: 'ok' (skasuj),
    // 'odrzucony' (skasuj, ponowienie nic nie da, ale powiedz o tym człowiekowi),
    // 'siec' (zostaw — spróbujemy przy następnym uruchomieniu apki).
    function uploadTrack(points) {
        // 'pusto' ≠ 'ok'. Wołający MUSI móc je rozróżnić: po zatrzymaniu
        // nagrania, które nie zebrało ani jednego odcinka, skok na ekran
        // wyniku kończył się przekierowaniem na listę wgrywania GPX — bo ten
        // ekran nie miał czego pokazać. To jest druga połowa zgłoszenia
        // „klikam Nagraj i przenosi mnie do uploadu gpx".
        if (!points || points.length < 2) { return Promise.resolve('pusto'); }
        var blob = new Blob([toGpx(points)], { type: 'application/gpx+xml' });
        var fd = new FormData();
        fd.append('csrf_token', window.RM_CSRF || '');
        fd.append('gpx', blob, 'nagranie-' + Date.now() + '.gpx');
        return fetch(window.RM_UPLOAD_URL, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) { return 'siec'; }
                if (!d.ok) { return 'odrzucony'; }
                // Liczby z zapisanego przejazdu idą zdarzeniem, nie nawigacją —
                // patrz `stopSession`. Kto chce, ten je zobaczy; nikt nie jest
                // nigdzie przenoszony.
                try {
                    window.dispatchEvent(new CustomEvent('rm:ride-saved', {
                        detail: { pola: Number(d.pola) || 0, duplikat: !!d.duplikat }
                    }));
                } catch (e) {}
                return 'ok';
            })
            // Tu ląduje też HTML zamiast JSON-a (padnięty deploy) — świadomie
            // jako 'siec': nagranie ma przeżyć złą odpowiedź serwera.
            .catch(function () { return 'siec'; });
    }

    // ODRZUCONY ŚLAD MUSI BYĆ SŁYSZALNY. Ponowienie go nie naprawi (plik jest
    // taki, jaki jest), więc bufor kasujemy — ale cisza w tym miejscu jest
    // gorsza niż błąd: dokładnie ona ukryła to, że ani jedno nagranie z apki
    // nigdy się nie policzyło. Powiadomienie idzie tym samym mostem co alerty
    // o skarbach, bo apka bywa wtedy w tle i żadnego ekranu nikt wtedy nie ogląda.
    function zglosOdrzucenie() {
        native.notify({
            title: __('Nagranie nie zostało policzone'),
            body: __('Ridemore nie przyjął śladu z tego przejazdu — pola i skarby po drodze nie zostały zaliczone.')
        });
    }

    // PRZYCISK NAGRYWANIA NIE NAWIGUJE — DECYZJA USERA 2026-08-29:
    // „powinien informować o tym, czy się nagrywa, czy nie, lub możliwość
    // zatrzymania lub rozpoczęcia i nic poza tym. Nie chcę, aby odwoływał się
    // do jakichś uploadów".
    //
    // Do tej pory świadomy stop przerzucał na ekran wyniku (§11 audytu UX
    // 2026-08-28), a gdy ten nie miał czego pokazać — dalej, na listę
    // wgrywania GPX. Wyrywanie człowiekowi ekranu spod palca po dotknięciu
    // przełącznika jest złe nawet wtedy, gdy trafia we właściwy ekran.
    //
    // CO ZAJMUJE JEGO MIEJSCE: powiadomienie z wynikiem (`rm:ride-saved`
    // niżej). Można je zignorować, można w nie dotknąć — i dopiero wtedy
    // otwiera się podsumowanie. Wybór należy do człowieka, nie do przycisku.
    window.addEventListener('rm:ride-saved', function (e) {
        var d = (e && e.detail) || {};
        if (d.duplikat) { return; } // ten sam plik drugi raz — nie ma o czym mówić
        var pola = d.pola || 0;
        native.notify({
            title: __('Przejazd zapisany'),
            body: pola > 0
                ? __('Odkryte pola: {n}. Dotknij, żeby zobaczyć podsumowanie.', { n: pola })
                : __('Dotknij, żeby zobaczyć podsumowanie.'),
            // Ścieżka BEZ base_path — dokłada go most (native.js), tak samo
            // jak przy powiadomieniu o zaliczonym skarbie.
            url: '/admin/moje-przejazdy/podsumowanie'
        });
    });

    // Bufor z POPRZEDNIEJ, przerwanej sesji (ubicie procesu) — wysyłamy raz,
    // na starcie, zanim ruszy nowa sesja. Nieudana wysyłka zostawia bufor —
    // spróbujemy przy następnym uruchomieniu; pełna kolejka offline to
    // Etap 7 kontraktu, świadomie poza zakresem tego etapu.
    function recoverLeftoverBuffer() {
        return prefGet(K_BUFFER).then(function (raw) {
            if (!raw) { return; }
            var pts;
            try { pts = JSON.parse(raw); } catch (e) { pts = []; }
            if (!pts || pts.length < 2) { return prefSet(K_BUFFER, '[]'); }
            return uploadTrack(pts).then(function (wynik) {
                if (wynik === 'siec') { return; }
                if (wynik === 'odrzucony') { zglosOdrzucenie(); }
                return prefSet(K_BUFFER, '[]');
            });  // 'pusto' też czyści — dwa punkty to nie jest przejazd
        });
    }

    // --- Cache skarbów pod alerty (GET /api/discovery/nearby-treasures) ---
    function bboxAround(p) {
        return {
            south: p.lat - NEARBY_MARGIN_DEG, north: p.lat + NEARBY_MARGIN_DEG,
            west:  p.lon - NEARBY_MARGIN_DEG, east:  p.lon + NEARBY_MARGIN_DEG
        };
    }
    function withinBbox(p, bbox) {
        // Margines 20% od krawędzi — bez tego pozycja tuż przy granicy
        // wywoływałaby odświeżenie przy każdym kolejnym punkcie.
        var m = NEARBY_MARGIN_DEG * 0.2;
        return p.lat > bbox.south + m && p.lat < bbox.north - m
            && p.lon > bbox.west + m && p.lon < bbox.east - m;
    }
    function refreshNearby(p) {
        if (!window.RM_NEARBY_URL) { return Promise.resolve(); }
        var bbox = bboxAround(p);
        var qs = 'north=' + bbox.north + '&south=' + bbox.south
            + '&east=' + bbox.east + '&west=' + bbox.west;
        return fetch(window.RM_NEARBY_URL + '?' + qs, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { treasures: [] }; })
            .then(function (d) { nearby = { bbox: bbox, items: d.treasures || [] }; })
            .catch(function () {});
    }

    // --- Alerty: powiadomienie lokalne (throttlowane) + zdarzenia dla mapy
    // (bez throttlu — mapa ma pokazywać zbliżanie na bieżąco, jak w Yanosiku). ---
    function checkAlerts(p, moving) {
        if (!nearby) { return; }
        var stillNear = {};
        nearby.items.forEach(function (t) {
            var d = haversineM(p, { lat: t.lat, lon: t.lon });
            var promienZaliczenia = Number(t.claim_radius_m) || 40;

            // DWA PROMIENIE, DWIE RÓŻNE RZECZY: szerszy (z zapasem) ostrzega
            // „zbliżasz się", węższy — ten prawdziwy — zalicza. Skarb ukryty
            // i tropiony ma tu współrzędne ŚRODKA POLA, nie swoje (patrz
            // `Treasure::reveal`), więc zaliczenie i tak rozstrzyga serwer po
            // prawdziwym położeniu; to tutaj decyduje tylko, kiedy zapytać.
            if (d <= promienZaliczenia) { zaliczSkarb(t, p); }

            if (d > promienZaliczenia + ALERT_BUFFER_M) { return; }
            stillNear[t.id] = true;
            window.dispatchEvent(new CustomEvent('rm:treasure-near', { detail: { treasure: t, distance: d } }));
            // „Zbliżasz się — sprawdź mapę" po zaliczeniu jest już nieprawdą
            // i drugim powiadomieniem o tym samym. Zaliczenie ma pierwszeństwo:
            // mówi więcej i kończy sprawę. (`zaliczone` ustawia się synchronicznie
            // w `zaliczSkarb`, więc ten warunek widzi je jeszcze w tym obiegu.)
            if (moving && !zaliczone[t.id]) { maybeAlert(t); }
        });
        Object.keys(currentlyNear).forEach(function (id) {
            if (!stillNear[id]) {
                window.dispatchEvent(new CustomEvent('rm:treasure-far', { detail: { id: Number(id) } }));
            }
        });
        currentlyNear = stillNear;
    }

    // --- ZALICZENIE SKARBU MIJANEGO PO DRODZE (prośba usera 2026-08-29:
    // „jeśli przejeżdżamy koło niego w odpowiedniej granicy, to powinno
    // zaliczyć i podać informacje o nim — tak jakby przewodnik turystyczny") ---
    //
    // Reguła „jestem w promieniu = zaliczone" NIE JEST tu wymyślana: robi to
    // `POST /api/treasures/claim` (SKA/6, „droga bez wlepki"), a odległość
    // liczy serwer po WŁASNYCH współrzędnych skarbu — przysłany punkt jest
    // deklaracją pozycji telefonu, nie dowodem. Apka po prostu nigdy tego
    // endpointu nie wołała; alert „zbliżasz się" był jedynym, co robiła.
    //
    // PRYWATNOŚĆ: pozycja wychodzi na serwer WYŁĄCZNIE w tej jednej chwili
    // i jest to punkt przy skarbie o współrzędnych, które serwer i tak zna —
    // dokładnie tyle, ile wysyła zaliczenie kodem QR. Zasada „w tle nie
    // strumieniujemy pozycji" zostaje nietknięta: nie ma tu żadnej wysyłki
    // poza momentem zaliczenia.
    var zaliczone = {};   // id skarbu -> true, żeby nie wysyłać przy każdym odczycie GPS

    function skrot(tekst, ile) {
        if (!tekst) { return ''; }
        var t = String(tekst).replace(/\s+/g, ' ').trim();
        if (t.length <= ile) { return t; }
        var ciety = t.slice(0, ile);
        var spacja = ciety.lastIndexOf(' ');
        return (spacja > 40 ? ciety.slice(0, spacja) : ciety) + '…';
    }

    // NOTKA TERAZ, PEŁNA KARTA NA ŻĄDANIE (decyzja usera): tyle, ile da się
    // przeczytać zerkając na telefon, a tapnięcie otwiera istniejący ekran
    // skarbu ze zdjęciem, galerią i postępem kolekcji — drugiego ekranu na to
    // samo nie budujemy.
    function notkaPrzewodnika(c) {
        var opis = skrot(c.description, 140);
        native.notify({
            id: c.id ? (800000000 + (c.id % 90000000)) : undefined,
            title: __('Zaliczono: {nazwa}', { nazwa: c.name }),
            body: '+' + (c.points || 0) + __(' pkt')
                + (c.category ? ' · ' + c.category : '')
                + (opis ? ' — ' + opis : ''),
            url: c.code ? '/skarb/' + c.code : null
        });
    }

    function zaliczSkarb(t, p) {
        if (zaliczone[t.id] || !window.RM_CLAIM_URL) { return; }
        zaliczone[t.id] = true;

        native.isOnline().then(function (online) {
            if (!online) { return zakolejkuj(t, p); }
            var dane = new FormData();
            dane.append('csrf_token', window.RM_CSRF || '');
            dane.append('lat', p.lat);
            dane.append('lon', p.lon);
            return fetch(window.RM_CLAIM_URL, {
                method: 'POST', body: dane, credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d) { return zakolejkuj(t, p); }
                    (d.claimed || []).forEach(notkaPrzewodnika);
                })
                .catch(function () { return zakolejkuj(t, p); });
        });
    }

    // Bez zasięgu: pozycja ląduje w kolejce mostu (ta sama, co zaległe skany)
    // i zaliczy się sama, gdy wróci sieć. Notkę pokazujemy OD RAZU z tego, co
    // telefon już ma — dla skarbu jawnego to nazwa i opis, dla ukrytego tyle,
    // ile pozwala jego poziom ujawnienia. Punkty przychodzą przy synchronizacji,
    // bo lista pod alerty świadomie ich nie niesie.
    function zakolejkuj(t, p) {
        var opis = skrot(t.description, 140);
        native.notify({
            id: 820000000 + (t.id % 70000000),
            title: t.reveal === 'exact' ? __('Mijasz: {nazwa}', { nazwa: t.name }) : __('Minąłeś skarb'),
            body: (opis ? opis + ' ' : '') + __('· Bez zasięgu — zaliczy się, gdy wróci sieć.')
        });
        return native.queueClaim(p.lat, p.lon);
    }

    // ==================================================================
    // ALERT „JESTEŚ BLISKO BRAKUJĄCEGO KAWAŁKA TRASY" (Etap 1a, 2026-09-11)
    // ==================================================================
    // Bliźniak alertów o skarbach i celowo tą samą drogą: pobieramy raz na
    // prostokąt (`/api/discovery/nearby-gaps`), a odległości liczymy u siebie.
    // Pozycja NIE WYCHODZI na serwer — zasada „w tle nie strumieniujemy
    // pozycji" zostaje nietknięta.
    //
    // POWIADAMIAMY O OKAZJI, NIE O BRAKU. Serwer oddaje wyłącznie pola tras,
    // które ten człowiek już zaczął, a my odzywamy się dopiero, gdy jest
    // BLISKO — wtedy prośba jest rozsądna, bo nadłożenie drogi małe. To jest
    // wprost reguła usera z 2026-09-11 („nie prosimy o rzecz nierozsądną"),
    // i dlatego nie ma tu żadnego „zostały Ci 3 pola" wysyłanego ot tak.
    var K_GAP_ALERTED   = 'rm_bg_gap_';        // + route id -> epoch ms
    var GAP_ALERT_M     = 600;                 // ~jedno pole drogi w bok
    var GAP_COOLDOWN_MS = 24 * 60 * 60 * 1000; // jedna trasa raz na dobę
    var gaps = null;                           // { bbox, items } — jak `nearby`

    function refreshGaps(p) {
        if (!window.RM_GAPS_URL) { return Promise.resolve(); }
        var bbox = bboxAround(p);
        var qs = 'north=' + bbox.north + '&south=' + bbox.south
            + '&east=' + bbox.east + '&west=' + bbox.west;
        return fetch(window.RM_GAPS_URL + '?' + qs, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { gaps: [] }; })
            .then(function (d) { gaps = { bbox: bbox, items: d.gaps || [] }; })
            .catch(function () {});
    }

    function checkGapAlerts(p, moving) {
        if (!gaps || !moving) { return; }

        // Najbliższe brakujące pole PER TRASA — powiadomienie mówi o trasie,
        // nie o polu, więc dwa sąsiednie braki tej samej trasy to wciąż jedna
        // wiadomość. Bez tego przejazd wzdłuż luki sypałby alertami.
        var najblizsze = {};
        gaps.items.forEach(function (g) {
            var d = haversineM(p, { lat: g.lat, lon: g.lon });
            if (d > GAP_ALERT_M) { return; }
            if (!najblizsze[g.route_id] || d < najblizsze[g.route_id].d) {
                najblizsze[g.route_id] = { d: d, gap: g };
            }
        });

        Object.keys(najblizsze).forEach(function (routeId) {
            maybeGapAlert(najblizsze[routeId].gap);
        });
    }

    function maybeGapAlert(g) {
        var key = K_GAP_ALERTED + g.route_id;
        prefGet(key).then(function (raw) {
            var last = raw ? parseInt(raw, 10) : 0;
            if (Date.now() - last < GAP_COOLDOWN_MS) { return; }
            prefSet(key, String(Date.now()));
            native.notify({
                // Osobna przestrzeń identyfikatorów od skarbów — te same liczby
                // w obu zbiorach nadpisywałyby sobie powiadomienia w systemie.
                id: 900000 + g.route_id,
                title: __('Kawałek trasy tuż obok'),
                // Mówimy, ILE brakuje, dopiero w kontekście „jesteś obok" —
                // sama liczba bez okazji byłaby powiadomieniem o STANIE,
                // a te w tym programie nie istnieją.
                body: g.name + __(' — masz to prawie po drodze.'),
                url: '/trasy/' + g.slug
            });
        });
    }

    // Treść WPROST z poziomów `Treasure::reveal()` — powiadomienie nigdy nie
    // mówi więcej, niż powiedziałby widok „odkrytego" skarbu na mapie.
    function maybeAlert(t) {
        var key = K_ALERTED + t.id;
        prefGet(key).then(function (raw) {
            var last = raw ? parseInt(raw, 10) : 0;
            if (Date.now() - last < ALERT_COOLDOWN_MS) { return; }
            prefSet(key, String(Date.now()));
            native.notify({
                id: t.id,
                title: t.reveal === 'exact' ? t.name : __('Skarb w pobliżu'),
                body: t.reveal === 'hint' ? t.hint
                    : (t.reveal === 'exact' ? __('Jesteś blisko — sprawdź mapę.') : __('Coś ciekawego jest niedaleko — sprawdź mapę.'))
            });
        });
    }

    function onPosition(p) {
        // PRZYCHODZĄCA POZYCJA JEST NAJMOCNIEJSZYM MOŻLIWYM DOWODEM, ŻE
        // NAGRYWANIE IDZIE (2026-08-30). Do tej pory stan zmieniała WYŁĄCZNIE
        // obietnica z `addWatcher()` — czyli UI wierzył mostowi, a nie faktom,
        // choć fakty (punkty!) płyną tym samym kanałem callbacka. Ten warunek
        // jest przed filtrem dokładności celowo: nawet odczyt zbyt zgrubny na
        // ślad dowodzi, że usługa natywna żyje i woła nas.
        if (STAN === 'startuje') {
            potwierdzStart();
        }
        // ODCZYT BEZ DOKŁADNOŚCI NIE JEST POZYCJĄ. Odrzucamy go w całości —
        // także dla alertów o skarbach i dla wykrywania postoju: zaliczenie
        // skarbu z pozycji obarczonej 80-metrowym błędem byłoby zgadywaniem,
        // a serwer i tak liczy odległość od własnych współrzędnych.
        // Pastylka z czasem ma własny licznik (setInterval), więc pominięcie
        // odczytu jej nie zatrzymuje.
        if (typeof p.accuracy === 'number' && p.accuracy > MAX_ACC_M) { return; }

        var moved = lastPoint ? haversineM(lastPoint, p) : Infinity;
        if (moved >= MOVE_MIN_M) { lastMoveAt = Date.now(); }
        var moving = moved >= MOVE_MIN_M;
        lastPoint = p;

        pushPoint(p);

        // POZYCJA IDZIE DALEJ ZA DARMO (2026-08-29, po zgłoszeniu „z pełnej
        // baterii zrobiło się 0 po 40 minutach"). Ekran mapy rysował kropkę
        // „tu jestem" z WŁASNEGO `watchPosition` — czyli przy nagrywaniu
        // pracowały DWA niezależne klienty lokalizacji naraz, a ten drugi
        // z `highAccuracy:true` i `maximumAge:0`, czyli w najdroższej możliwej
        // konfiguracji. Skoro ten plik i tak dostaje pozycje, mapa może je
        // dostać w prezencie zamiast zamawiać drugi strumień.
        window.dispatchEvent(new CustomEvent('rm:position', { detail: p }));

        if (!nearby || !withinBbox(p, nearby.bbox)) { refreshNearby(p); }
        checkAlerts(p, moving);

        // Luki w trasach — ten sam rytm co skarby: dociągnij, gdy wyjechaliśmy
        // z prostokąta, i sprawdzaj przy każdej pozycji. Osobny cache, bo to
        // inne dane i inne okno ważności.
        if (!gaps || !withinBbox(p, gaps.bbox)) { refreshGaps(p); }
        checkGapAlerts(p, moving);
        updatePillTime();
    }

    // --- Pastylka „Nagrywam ślad" — widoczny wskaźnik i przycisk stop,
    // wymóg App Store/Play dla śledzenia w tle i zwykłej uczciwości wobec
    // usera. Tap na (wyłącz) cofa zgodę na przyszłość — bez osobnego ekranu
    // ustawień, ten sam element robi za wskaźnik i za kontrolę. ---
    function showPill() {
        if (document.getElementById('rmBgPill')) { return; }
        var el = document.createElement('div');
        el.id = 'rmBgPill';
        el.className = 'rm-bg-pill';
        el.innerHTML =
            '<span class="rm-bg-pill__dot" aria-hidden="true"></span>' +
            __('<span class="rm-bg-pill__t">Nagrywam ślad · <b data-rm-bg-time>00:00</b></span>') +
            '<button type="button" data-rm-bg-stop>Zatrzymaj</button>' +
            __('<button type="button" class="rm-bg-pill__off" data-rm-bg-off title="Wyłącz automatyczne nagrywanie">wyłącz</button>');
        document.body.appendChild(el);
        el.querySelector('[data-rm-bg-stop]').addEventListener('click', function () { stopSession(); });
        el.querySelector('[data-rm-bg-off]').addEventListener('click', function () {
            prefSet(K_CONSENT, '0');
            stopSession();
        });
        pillTimerId = setInterval(updatePillTime, 1000);
        updatePillTime();
    }
    function updatePillTime() {
        var el = document.getElementById('rmBgPill');
        if (!el || !startedAt) { return; }
        var s = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
        var mm = String(Math.floor(s / 60)).padStart(2, '0');
        var ss = String(s % 60).padStart(2, '0');
        var t = el.querySelector('[data-rm-bg-time]');
        if (t) { t.textContent = mm + ':' + ss; }
    }
    function hidePill() {
        var el = document.getElementById('rmBgPill');
        if (el) { el.remove(); }
        if (pillTimerId) { clearInterval(pillTimerId); pillTimerId = null; }
    }

    // --- WIDOCZNY STAN NAGRYWANIA (zgłoszenie usera 2026-08-29: „nie
    // widziałem wcześniej nigdzie zatrzymania ani ikonki, że jestem w trybie
    // trakingu; może warto dodać to koło punktów") ---
    //
    // Do tej pory jedynym śladem nagrywania była pastylka pokazywana WYŁĄCZNIE
    // w trakcie trwającej sesji. Gdy sesja nie ruszyła — bo w banerze padło
    // „Nie teraz" (a ten świadomie nie wraca), bo system odmówił lokalizacji
    // w tle, albo bo plugin nie wstał — nie było NIC: ani kontrolki, ani
    // komunikatu. To jest ten brakujący stan, a zarazem JEDYNA droga powrotu
    // po „Nie teraz".
    //
    // Markup: `views/web/pages/discovery-app.php`, przy licznikach na mapie.
    // Delegacja na `document` (ten sam wzorzec co `[data-rm-scan]` w native.js),
    // więc przycisk nie musi istnieć w chwili startu tego skryptu.
    // ETYKIETY MÓWIĄ O STANIE, NIE O MOŻLIWOŚCI (poprawka 2026-08-29, zgłoszenie
    // z telefonu: „mam szare pole Nagrywaj — nie wiem, co to oznacza, czy
    // nagrywa, czy nie"). „Nagrywaj" dawało się przeczytać i jako polecenie
    // („dotknij, a zacznę"), i jako stan („nagrywam") — czyli nie mówiło nic.
    // Teraz stan niesie CZASOWNIK W INNEJ FORMIE plus kolor kropki: szara
    // i „Nagraj" = stoi, czerwona pulsująca i „Nagrywam" = idzie.
    var STANY = {
        off:      { tekst: 'Nagraj',   opis: __('Dotknij, żeby zacząć nagrywać przejazd — pola i skarby po drodze zaliczą się po zakończeniu') },
        startuje: { tekst: __('Włączam…'), opis: __('Uruchamiam nagrywanie — czekam, aż system odda dostęp do lokalizacji') },
        on:   { tekst: 'Nagrywam',   opis: __('Nagrywam przejazd — dotknij, żeby zakończyć i policzyć') },
        blad: { tekst: __('Brak zgody'), opis: __('System nie pozwolił śledzić pozycji w tle — sprawdź uprawnienia aplikacji w ustawieniach telefonu') }
    };

    function renderStan(stan) {
        if (stan) { STAN = stan; }
        var stanNagrywania = STAN;
        // Mapa musi wiedzieć, czy MOŻE liczyć na nasze pozycje — od tego zależy,
        // czy uruchomi własny (drugi) nasłuch GPS. Zdarzenie leci przy każdej
        // zmianie stanu, bo to jedyny moment, w którym ta odpowiedź się zmienia.
        try {
            window.dispatchEvent(new CustomEvent('rm:tracking', {
                detail: { active: stanNagrywania === 'on' }
            }));
        } catch (e) {}
        var opis = STANY[stanNagrywania] || STANY.off;
        var lista = document.querySelectorAll('[data-rm-bg-toggle]');
        for (var i = 0; i < lista.length; i++) {
            var el = lista[i];
            el.hidden = false;
            el.classList.toggle('is-on', stanNagrywania === 'on');
            el.classList.toggle('is-error', stanNagrywania === 'blad');
            el.setAttribute('aria-pressed', stanNagrywania === 'on' ? 'true' : 'false');
            el.setAttribute('title', opis.opis);
            el.setAttribute('aria-label', opis.opis);
            var etykieta = el.querySelector('[data-rm-bg-label]');
            if (etykieta) { etykieta.textContent = opis.tekst; }
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-rm-bg-toggle]');
        if (!btn) { return; }
        // STARTUJE = system jeszcze nie oddał dostępu. Dotknięcie NIE MOŻE
        // być tu bezczynne (poprawka 2026-08-30): przez chwilę wyglądało to
        // jak ostrożność, a w praktyce było jedynym zamkiem w pułapce —
        // gdy start nie doszedł do skutku, przycisk przestawał odpowiadać
        // na cokolwiek. Teraz dotknięcie w tym stanie ANULUJE próbę i wraca
        // na „Nagraj", więc człowiek zawsze może spróbować jeszcze raz.
        // (Zeruje też bufor — z niedoszłego startu nie ma czego wysyłać.)
        if (STAN === 'startuje') {
            if (startTimeoutId) { clearTimeout(startTimeoutId); startTimeoutId = null; }
            zdejmijWatcher();
            hidePill();
            buffer = [];
            prefSet(K_SESSION, '');
            renderStan('off');
            return;
        }
        if (STAN === 'on') { stopSession(); return; }
        // Dotknięcie przycisku JEST zgodą — pytanie banerem o to, o co człowiek
        // właśnie poprosił, byłoby pytaniem dwa razy o to samo. Zapis zgody
        // odblokowuje też automatyczny start przy kolejnych uruchomieniach.
        prefSet(K_CONSENT, '1');
        var baner = document.getElementById('rmBgConsent');
        if (baner) { baner.remove(); }
        wlaczNagrywanie();
    });

    /** Zgoda systemowa (powiadomienia) + start sesji — jedna droga dla banera,
     *  przycisku i wznowienia przerwanego przejazdu.
     *
     *  `startSession` wołane w opakowaniu, NIE jako `.then(startSession)`:
     *  tamto wpychałoby wynik zgody w pierwszy parametr, czyli od teraz
     *  w `kontynuacja` — i każdy start kasowałby albo zachowywał bufor
     *  przypadkiem, zależnie od tego, co zwróci plugin powiadomień. */
    function wlaczNagrywanie(kontynuacja) {
        // ZGODA NA POWIADOMIENIA NIE JEST WARUNKIEM NAGRYWANIA (2026-08-30).
        // Do tej pory `startSession` wisiało w `.then` tej obietnicy — więc
        // jej odrzucenie (brak wtyczki, odmowa, wyjątek w moście) kończyło
        // się odrzuceniem CAŁEGO łańcucha: nagrywanie nie ruszało, żaden stan
        // się nie zmieniał, a w konsoli zostawało tylko nieobsłużone
        // odrzucenie. Powiadomienia są potrzebne alertom o skarbach, nie
        // zbieraniu pozycji — więc pytamy o nie i jedziemy dalej niezależnie
        // od odpowiedzi.
        var zgoda;
        try { zgoda = native.requestBackgroundPermission(); } catch (e) { zgoda = null; }
        if (!zgoda || typeof zgoda.then !== 'function') { zgoda = Promise.resolve(); }
        return zgoda.catch(function () {}).then(function () {
            startSession(kontynuacja === true);
        });
    }

    // --- Zgoda — jawny baner, nie systemowy confirm(): dwa przyciski,
    // decyzja zapamiętana. Odmowa NIE pyta ponownie przy każdym otwarciu —
    // to byłby spam, nie uczciwość. ---
    function showConsentBanner() {
        if (document.getElementById('rmBgConsent')) { return; }
        var el = document.createElement('div');
        el.id = 'rmBgConsent';
        el.className = 'rm-bg-consent';
        el.innerHTML =
            __('<p><strong>Nagrywać przejazdy w tle?</strong> Ridemore będzie śledzić trasę, ') +
            __('nawet gdy zwiniesz apkę — odkryjesz pola i skarby po drodze, a gdy będziesz blisko ') +
            __('jednego z nich (także jeszcze nieodkrytego), dostaniesz powiadomienie.</p>') +
            '<div class="rm-bg-consent__act">' +
            __('<button type="button" data-rm-bg="tak">Włącz</button>') +
            __('<button type="button" data-rm-bg="nie">Nie teraz</button>') +
            '</div>';
        document.body.appendChild(el);
        el.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('[data-rm-bg]');
            if (!btn) { return; }
            var yes = btn.getAttribute('data-rm-bg') === 'tak';
            prefSet(K_CONSENT, yes ? '1' : '0');
            el.remove();
            if (yes) { wlaczNagrywanie(); }
        });
    }

    // --- Cykl życia sesji ---
    // `kontynuacja` = wracamy do PRZEJAZDU, KTÓRY JUŻ TRWA (przeładowanie
    // strony przy nawigacji albo ponowne otwarcie apki) — bufor i czas startu
    // zostają nietknięte, żeby wyszedł z tego jeden ślad, a nie kawałki.
    function startSession(kontynuacja) {
        // WARUNEK NA WATCHER, NIE NA NAMALOWANY STAN (poprawka 2026-08-30).
        // Stało tu `if (trwa()) return;` — i to zabijało KAŻDĄ kontynuację:
        // `wznowSesje()` maluje „Nagrywam" (bo usługa natywna faktycznie
        // pracuje), a dopiero potem woła `wlaczNagrywanie(true)`. Trafiało
        // więc w ten warunek i wracało — po tym, jak `wznowSesje` zdjęło
        // osierocony watcher. Efekt: pierwsze przejście na inny ekran
        // w trakcie jazdy CICHO kończyło zbieranie pozycji, a chip do końca
        // pokazywał „Nagrywam". `watcherId` żyje w pamięci strony, więc na
        // świeżo wczytanej jest pusty — dokładnie tak, jak trzeba: chroni
        // przed drugim watcherem na TEJ stronie i nie blokuje wznowienia.
        if (watcherId) { return; }
        // 'on' DOPIERO Z ID WATCHERA W RĘKU (niżej, w `.then`). Do tego czasu
        // stan jest przejściowy i widać go na chipie — bo „próbuję" to nie
        // to samo co „nagrywam", a udawanie jednego drugim było źródłem
        // zgłoszonego błędu.
        // Przy KONTYNUACJI pokazujemy od razu „Nagrywam": z punktu widzenia
        // człowieka przejazd trwa nieprzerwanie, a to, że podmieniamy watcher
        // pod spodem, jest szczegółem implementacji przeładowania strony.
        renderStan(kontynuacja ? 'on' : 'startuje');
        if (kontynuacja) { showPill(); }
        if (!kontynuacja) {
            buffer = [];
            startedAt = Date.now();
        }
        lastPoint = null;
        lastMoveAt = Date.now();

        // TERMIN NA POTWIERDZENIE. Bez niego „Włączam…" nie miało wyjścia
        // awaryjnego: jeśli obietnica mostu nie wróciła (a callback z pozycją
        // jeszcze nie przyszedł — na zimnym GPS to i minuta), chip zostawał
        // w stanie, w którym dotknięcia są ignorowane. Termin zamyka tę pułapkę.
        if (startTimeoutId) { clearTimeout(startTimeoutId); }
        startTimeoutId = setTimeout(function () {
            startTimeoutId = null;
            if (STAN !== 'startuje') { return; }
            // Nie mamy ANI id watchera, ANI jednej pozycji. Cokolwiek robi
            // usługa natywna, my o tym nie wiemy — i lepiej powiedzieć to
            // wprost (stan 'blad' daje się dotknąć i spróbować jeszcze raz)
            // niż udawać, że wciąż się uruchamiamy.
            hidePill();
            prefSet(K_SESSION, '');
            renderStan('blad');
        }, START_TIMEOUT_MS);

        native.startTracking({
            title: __('Ridemore nagrywa przejazd'),
            message: __('Śledzimy trasę, żeby odkryć pola i skarby po drodze.')
        }, function (p, err) {
            if (err || !p) { return; } // pojedynczy błędny odczyt — czekamy na następny
            onPosition(p);
        }).then(function (id) {
            // KOLEJNOŚĆ NIE JEST OBOJĘTNA. Najpierw zapisujemy FAKT (id watchera
            // + stan), dopiero potem rysujemy. Odwrotnie — jak było — wyjątek
            // w rysowaniu (byle pomyłka w DOM pastylki) leciał do `.catch`,
            // czyli do gałęzi „nie udało się włączyć": kasowała znacznik sesji
            // i pokazywała „Brak zgody", podczas gdy watcher DZIAŁAŁ, a usługa
            // wisiała z powiadomieniem. Dokładnie ten rodzaj rozjazdu, który
            // ta przebudowa likwiduje.
            watcherId = id;
            potwierdzStart();
        }).catch(function () {
            // Brak zgody systemowej albo brak pluginu. DAWNIEJ kończyło się to
            // ciszą („user dowie się przy kolejnej okazji") — i właśnie ta cisza
            // sprawiała, że nie dało się odróżnić odmowy uprawnienia od apki,
            // która po prostu nie nagrywa. Dziś odmowa ma swój stan na przycisku.
            //
            // Sprzątamy też znacznik sesji: skoro watcher nie wstał, nie ma
            // czego wznawiać, a zostawiony wpis kazałby następnej stronie
            // pokazać „Nagrywam" dla usługi, której nie ma.
            if (startTimeoutId) { clearTimeout(startTimeoutId); startTimeoutId = null; }
            hidePill();
            prefSet(K_SESSION, '');
            renderStan('blad');
        });
    }

    /** WYJŚCIE ZE STANU „Włączam…" W GÓRĘ — jedno miejsce dla obu dowodów,
     *  że nagrywanie ruszyło: id watchera z mostu ALBO pierwsza pozycja
     *  z callbacka. Który przyjdzie pierwszy, jest nieistotne; ważne, żeby
     *  którykolwiek wystarczał, bo do 2026-08-30 wystarczał tylko pierwszy
     *  i to on potrafił nie przyjść nigdy.
     *
     *  Idempotentne: drugi dowód po pierwszym niczego nie psuje. */
    function potwierdzStart() {
        if (startTimeoutId) { clearTimeout(startTimeoutId); startTimeoutId = null; }
        // BEZ SKRÓTU „już jest 'on', nie ma co robić": przy KONTYNUACJI stan
        // jest 'on' od samego początku (usługa natywna pracuje nieprzerwanie),
        // a mimo to trzeba zapisać ŚWIEŻE id watchera — inaczej znacznik sesji
        // niesie id z poprzedniej strony i „Zatrzymaj" zdejmuje nie ten
        // watcher, co trzeba. Wszystko poniżej jest idempotentne.
        // KOLEJNOŚĆ NIE JEST OBOJĘTNA — najpierw FAKT (znacznik sesji + stan),
        // potem rysowanie. Odwrotnie wyjątek w rysowaniu leciałby do gałęzi
        // „nie udało się włączyć", podczas gdy watcher DZIAŁA.
        zapiszSesje();
        renderStan('on');
        if (!stationaryCheckId) {
            stationaryCheckId = setInterval(function () {
                if (Date.now() - lastMoveAt > STATIONARY_MS) { stopSession(); }
            }, 60000);
        }
        try { showPill(); } catch (e) {}
    }

    // ZATRZYMANIE MA JEDNĄ POSTAĆ. Do 2026-08-29 przyjmowało flagę `manual`,
    // która rozstrzygała, czy po wysyłce przerzucić człowieka na ekran wyniku.
    // Odkąd przycisk nie nawiguje (decyzja usera — patrz nota przy
    // `rm:ride-saved`), świadomy stop i auto-stop z bezruchu robią DOKŁADNIE
    // to samo, więc rozróżnienie zniknęło razem z parametrem.
    /**
     * ZATRZYMANIE MUSI DZIAŁAĆ Z KAŻDEJ STRONY, TAKŻE Z TAKIEJ, KTÓRA NIE
     * PAMIĘTA ID WATCHERA (2026-08-29). Pamięć JS ginie przy nawigacji, a
     * usługa natywna nie — więc świeżo otwarty ekran znał stan z Preferences,
     * ale nie miał czym zdjąć watchera. Efekt: „Zatrzymaj" zostawiało
     * pracujący GPS i wiszące powiadomienie, a kolejne dotknięcie startowało
     * DRUGIEGO watchera obok pierwszego. Dlatego id bierzemy z pamięci ALBO
     * z Preferences — to drugie jest jedynym miejscem, które przeżywa stronę.
     */
    function zdejmijWatcher() {
        if (watcherId) {
            var id = watcherId;
            watcherId = null;
            return native.stopTracking(id);
        }
        return prefGet(K_SESSION).then(function (raw) {
            var sesja = null;
            try { sesja = raw ? JSON.parse(raw) : null; } catch (e) { sesja = null; }
            if (sesja && sesja.watcherId) { return native.stopTracking(sesja.watcherId); }
        }).catch(function () {});
    }

    function stopSession() {
        if (STAN === 'off') { return; }
        if (startTimeoutId) { clearTimeout(startTimeoutId); startTimeoutId = null; }
        if (stationaryCheckId) { clearInterval(stationaryCheckId); stationaryCheckId = null; }
        zdejmijWatcher();
        hidePill();
        renderStan('off');

        flushBuffer();
        prefSet(K_SESSION, ''); // przejazd zamknięty — następny start zaczyna nowy ślad
        var pts = buffer.slice();
        buffer = [];
        uploadTrack(pts).then(function (wynik) {
            // nieudana wysyłka: bufor zostaje w Preferences, spróbujemy przy
            // następnym uruchomieniu apki (recoverLeftoverBuffer wyżej).
            if (wynik === 'siec') { return; }
            prefSet(K_BUFFER, '[]');
            if (wynik === 'odrzucony') { zglosOdrzucenie(); }
            // Świadomie BEZ nawigacji — patrz nota przy `rm:ride-saved` wyżej.
            // Odpowiedzią przycisku jest sam przycisk: wrócił na „Nagraj".
        });
    }

    // PONÓW WYSYŁKĘ PO POWROCIE NA PIERWSZY PLAN — udokumentowane ograniczenie
    // pluginu tła (README @capacitor-community/background-geolocation): po
    // 5 minutach w tle Android dławi żądania HTTP z WebView, więc `uploadTrack`
    // wywołany w tle (auto-stop przy braku ruchu) mógł nie dojść. Bufor został
    // w Preferences (`stopSession` go stamtąd nie czyści przy porażce) —
    // spróbuj jeszcze raz, gdy user faktycznie wróci do apki. Dostęp wprost do
    // `Capacitor.Plugins.App`, nie przez most w native.js: to lokalna potrzeba
    // tego pliku, tak samo jak `Prefs` wyżej, a nie ogólna funkcja natywna.
    var appPlugin = (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App) || null;
    if (appPlugin) {
        appPlugin.addListener('appStateChange', function (s) {
            // Warunek `STAN === 'off'` jest tu KLUCZOWY: przy trwającym
            // przejeździe ta gałąź wysłałaby jego bufor w środku jazdy
            // i ucięła ślad.
            if (s && s.isActive && STAN === 'off') { recoverLeftoverBuffer(); }
        });
    }

    /**
     * CO ZASTALIŚMY PO URUCHOMIENIU TEGO PLIKU — trzy sytuacje, trzy różne
     * odpowiedzi (decyzja usera 2026-08-29, „jeden przejazd do Zatrzymaj"):
     *
     *   1. jest znacznik sesji i ostatni punkt jest ŚWIEŻY → przejazd trwa
     *      (przeszliśmy na inny ekran albo wróciliśmy do apki): wczytujemy
     *      bufor i nagrywamy DALEJ, tym samym śladem;
     *   2. jest znacznik, ale ostatni punkt jest starszy niż `STALE_MS` →
     *      system ubił apkę i nikt nie wrócił: zamykamy przejazd i wysyłamy;
     *   3. nie ma znacznika → ewentualny bufor po awarii idzie na serwer.
     *
     * OSIEROCONY WATCHER ZDEJMUJEMY ZAWSZE. Jego callback wisiał w kontekście
     * strony, której już nie ma, więc nic nie zbiera — ale w usłudze natywnej
     * zostaje zarejestrowany do końca życia procesu. Bez tego każda nawigacja
     * dokładała kolejny. Kilka sekund między zdjęciem starego a podpięciem
     * nowego jest bez punktów i to jest świadomy koszt przeładowania strony.
     */
    function wznowSesje() {
        return prefGet(K_SESSION).then(function (raw) {
            if (!raw) {
                // Nie ma znacznika = nic nie nagrywa. DOPIERO TERAZ wolno
                // pokazać „Nagraj" — wcześniej byłoby to zgadywanie.
                renderStan('off');
                return recoverLeftoverBuffer();
            }

            var sesja = null;
            try { sesja = JSON.parse(raw); } catch (e) { sesja = null; }
            if (sesja && sesja.watcherId) {
                try { native.stopTracking(sesja.watcherId); } catch (e) {}
            }

            if (!sesja || (Date.now() - (sesja.lastAt || 0)) > STALE_MS) {
                renderStan('off');
                return prefSet(K_SESSION, '').then(recoverLeftoverBuffer);
            }

            // ZNACZNIK ŚWIEŻY = USŁUGA NATYWNA PRACUJE. To jedyna rzecz, jaką
            // ten wpis może znaczyć, i jedyna prawda, jaką mamy — wtyczka nie
            // udostępnia żadnego „czy nagrywasz?" (ma tylko addWatcher/
            // removeWatcher/openSettings, sprawdzone w jej definicjach typów).
            // Malujemy więc „Nagrywam" OD RAZU, zanim ruszy cokolwiek
            // asynchronicznego: to naprawia zgłoszony rozjazd, w którym
            // powiadomienie systemowe mówiło „nagrywam", a chip „Nagraj".
            renderStan('on');
            showPill();

            return prefGet(K_BUFFER).then(function (surowy) {
                var pkt;
                try { pkt = JSON.parse(surowy || '[]'); } catch (e) { pkt = []; }
                buffer = pkt || [];
                startedAt = sesja.startedAt || Date.now();
                return wlaczNagrywanie(true);
            });
        });
    }

    // --- Start ---
    // NIC NIE MALUJEMY, DOPÓKI NIE WIEMY (2026-08-29). Stało tu
    // `renderStan('off')` przed odczytem Preferences — czyli chip twierdził
    // „nie nagrywam" na każdym wejściu na ekran, także w środku przejazdu,
    // i dopiero po chwili (albo nigdy) się poprawiał. Znacznik w markupie ma
    // `hidden`, więc do czasu rozstrzygnięcia po prostu go nie widać: brak
    // odpowiedzi jest uczciwszy niż zła odpowiedź.
    wznowSesje().then(function () {
        // Zgoda ODBLOKOWUJE nagrywanie, ale go nie zaczyna — przejazd zaczyna
        // świadome dotknięcie „Nagraj". Automatyczny start przy każdym otwarciu
        // ekranu był właśnie tym, co siekało ślad na kawałki.
        if (trwa()) { return null; }
        return prefGet(K_CONSENT);
    }).then(function (v) {
        if (trwa() || v === '1' || v === '0') { return; }
        showConsentBanner();
    });
})();
