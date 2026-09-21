// assets/js/discovery-map.js
// Etap 8 — warstwa odkryć na mapie Leaflet serwisu. Cztery zastosowania, jeden kod:
//   scope 'all'   — wspólna mapa społeczności (/odkrycia?mapa=spolecznosc),
//   scope 'me'    — moja mapa (/odkrycia),
//   scope 'rider' — mapa konkretnej osoby, dorysowana na mapie jej profilu,
//   plus strona znanej trasy (/trasy/{slug}), gdzie na tę samą mapę kładzie
//   się jeszcze przebieg szlaku z pliku GPX.
//
// CO JEST WEKTOREM, A CO KAFLEM (stan od 2026-08-20)
// --------------------------------------------------
//   pola/mgła i skarby — WEKTOR: mgła musi pokrywać kadr natychmiast po
//     przesunięciu, a skarb ma być klikalny;
//   znane trasy (`options.trailsTiles`) — KAFLE, klucz `kr`. Wcześniej i one
//     szły wektorem, ale geometria brała się ze ŚRODKÓW PÓL siatki (pole ma
//     ok. 500 m), więc linia była zygzakiem obok drogi zamiast przebiegiem
//     szlaku. Kafel rysuje prawdziwy ślad z pliku GPX. Cena: obrazek nie jest
//     klikalny, więc szlaki straciły dymki.
//
// KADR STARTOWY NALEŻY DO STRONY, NIE DO MAPY (2026-08-19). `options.bounds`
// = {south, west, north, east}, policzony po stronie serwera z tego, co dana
// mapa pokazuje. Bez niego mapa startuje w środku Polski przy oddaleniu 6
// i dopiero po pierwszej odpowiedzi próbuje się dociągnąć (`fitToCells`) —
// czyli pyta o pola dla całego kraju, pokazuje zły obszar i skacze w oczach.
// Podany kadr wygrywa z `fitToCells` i jest ustawiany PO `invalidateSize()`.
//
// JEDNA SKALA, NIE DWIE WARSTWY (projekt grafika, 2026-08-13)
// -----------------------------------------------------------
// Historia tej mapy w trzech krokach, bo bez niej kolejne decyzje wyglądają
// na przypadkowe:
//   1. Pierwsza wersja malowała to, co ODKRYTE, na czystej mapie.
//   2. Odwrócone na mgłę: świat startuje zamglony, jazda mgłę zdejmuje.
//      Powód produktowy — przy podświetlaniu nowy użytkownik widzi czystą mapę
//      i nie ma na niej nic do zrobienia; przy mgle od razu widzi, ile świata
//      przed nim stoi. Do tego doszła osobna, przełączana heatmapa przejazdów.
//   3. Projekt grafika łączy jedno z drugim w JEDEN ciąg: szary (nieodkryte) →
//      zielony → żółty → pomarańcz → czerwony (im częściej, tym cieplej).
//      Jedna legenda zamiast dwóch, jeden obraz zamiast przełącznika warstw.
//   4. 2026-08-14, uwaga usera: krok 3 zamalowywał odkryty teren, a przy
//      typowych danych (prawie wszystko odkryte RAZ) dawało to jednolitą
//      zieloną plamę zasłaniającą drogi, nazwy i własny ślad. Pierwszy stopień
//      skali dostaje więc krycie 0 — ODKRYCIE ZNOWU ODSŁANIA — a kolor zostaje
//      wyłącznie tam, gdzie niesie informację, której z mapy nie widać: że tędy
//      jeździ się wielokrotnie.
//
// Z kroku 2 zostaje mechanika, która się sprawdziła: teren NIEODKRYTY to jeden
// wielokąt obejmujący cały świat, w którym każde odkryte pole jest DZIURĄ (SVG
// fill-rule evenodd, domyślne w Leaflecie). Jedna warstwa zamiast tysięcy
// szarych heksagonów, a nieodkryty obszar jest pokryty zawsze — także zaraz po
// przesunięciu mapy, zanim doleci kolejna odpowiedź z serwera. Po kroku 4 ten
// jeden wielokąt niesie też OBRYS wszystkich pól, bo każde z nich jest jego
// dziurą — jedna ścieżka SVG rysuje całą granicę odkrytego świata.
//
// Z kroku 2 zostaje też zasada: zasłaniamy STAN ODKRYCIA, nie GEOGRAFIĘ.
// Wszystkie stopnie skali są półprzezroczyste, łącznie z czerwienią — drogi,
// nazwy i rzeki mają być czytelne również tam, gdzie jeździ się najczęściej.
//
// PALETA: szarość dla nieodkrytego jest CHŁODNA i świadomie bez zieleni —
// kafle OSM w Polsce są mocno zielone (lasy, pola), więc zielona warstwa
// zlewałaby się z podkładem. Zieleń pojawia się dopiero jako PIERWSZY stopień
// odkrycia, gdzie niesie znaczenie.
//
// PODZIAŁ PRACY Z PHP
// -------------------
// Cała matematyka siatki (przypisanie punktu do pola, zaokrąglanie
// współrzędnych osiowych, agregacja) żyje WYŁĄCZNIE w Utils\DiscoveryGrid.
// Tutaj jest jedyny fragment, który musi być powtórzony: odwzorowanie Web
// Mercator, użyte do narysowania sześciokąta wokół gotowego środka przysłanego
// przez serwer. To celowo najnudniejszy kawałek — dwa wzory z definicji
// odwzorowania, bez decyzji i bez zaokrągleń, więc nie ma się czemu rozjechać.

(function () {
    var EARTH_RADIUS_M = 6378137;

    // Pierścień mgły. Celowo cały świat, nie kadr powiększony o margines:
    // przy szybkim przesuwaniu mapy warstwa jedzie razem z podkładem, a
    // odpowiedź z serwera przychodzi z opóźnieniem — kadr z marginesem
    // pokazywałby wtedy przez chwilę nagi, „odkryty" brzeg mapy.
    var WORLD_RING = [[-85, -179.9], [-85, 179.9], [85, 179.9], [85, -179.9]];

    function toMercator(lat, lon) {
        return [
            (lon * Math.PI) / 180 * EARTH_RADIUS_M,
            EARTH_RADIUS_M * Math.log(Math.tan(Math.PI / 4 + ((lat * Math.PI) / 180) / 2)),
        ];
    }

    function fromMercator(x, y) {
        return [
            ((2 * Math.atan(Math.exp(y / EARTH_RADIUS_M)) - Math.PI / 2) * 180) / Math.PI,
            (x / EARTH_RADIUS_M * 180) / Math.PI,
        ];
    }

    // Sześciokąt "pointy-top" wokół środka — pierwszy wierzchołek 30° od osi X,
    // dokładnie jak DiscoveryGrid::cellPolygon().
    function hexagon(lat, lon, sizeM) {
        var c = toMercator(lat, lon);
        var ring = [];
        for (var i = 0; i < 6; i++) {
            var angle = ((60 * i - 30) * Math.PI) / 180;
            ring.push(fromMercator(c[0] + sizeM * Math.cos(angle), c[1] + sizeM * Math.sin(angle)));
        }
        return ring;
    }

    // SIATKA MGŁY — OBRYSY PÓL, KTÓRYCH NIKT JESZCZE NIE ZDJĄŁ
    // (2026-08-30, prośba usera: „na mgle delikatne obramowanie hexów, o ton
    // ciemniejsze niż sama mgła — pozwoli userowi zobaczyć, jakie hexy ma koło
    // siebie i gdzie powinien skręcić").
    //
    // To jest DRUGI i ostatni fragment matematyki siatki powtórzony w JS —
    // patrz nota „PODZIAŁ PRACY Z PHP" na górze pliku. Serwer przysyła
    // WYŁĄCZNIE pola odkryte, więc obrysów pól NIEODKRYTYCH nie ma jak dostać
    // z odpowiedzi: to byłaby lista wszystkiego, czego nie ma. Wzory przepisane
    // 1:1 z `Utils\DiscoveryGrid` (pointToAxial / axialToPoint / roundAxial) —
    // MUSZĄ dawać dokładnie te same pola, bo inaczej siatka rozjechałaby się
    // z dziurami wyciętymi w mgle i wyglądałaby po prostu na zepsutą.
    function roundAxial(q, r) {
        // Przez współrzędne sześcienne (x+y+z=0): naiwne round() na parze
        // osiowej wskazuje czasem sąsiada zamiast właściwego pola.
        var x = q, z = r, y = -x - z;
        var rx = Math.round(x), ry = Math.round(y), rz = Math.round(z);
        var dx = Math.abs(rx - x), dy = Math.abs(ry - y), dz = Math.abs(rz - z);
        if (dx > dy && dx > dz) { rx = -ry - rz; }
        else if (dy > dz) { ry = -rx - rz; }
        else { rz = -rx - ry; }
        return [rx, rz];
    }
    function pointToAxial(x, y, size) {
        return roundAxial((Math.sqrt(3) / 3 * x - y / 3) / size, (2 / 3 * y) / size);
    }
    function axialToPoint(q, r, size) {
        return [size * Math.sqrt(3) * (q + r / 2), size * 3 / 2 * r];
    }

    // Klucz pola w osiach — jedyny sposób, żeby porównać pole z siatki
    // z polem przysłanym przez serwer (ten daje środek jako lat/lon).
    function axialKey(lat, lon, sizeM) {
        var c = toMercator(lat, lon);
        var ax = pointToAxial(c[0], c[1], sizeM);
        return ax[0] + ':' + ax[1];
    }

    // OD JAKIEJ WIELKOŚCI POLA SIATKA MA SENS (2026-08-30).
    //
    // Prośba brzmiała „żeby user wiedział, jakie hexy ma koło siebie i gdzie
    // powinien skręcić" — to jest pytanie zadawane W TERENIE, przy oddaleniu
    // jazdy, a nie nad mapą całego kraju. Zmierzone na ekranie 1199×520 px:
    // w widoku domyślnym (cały kraj) w kadr wchodzi ~2000 pól po ~21 px.
    // To jest dokładnie ten „plaster miodu", przed którym ostrzega §24:
    // gęsta sieć kresek, która nie mówi nic, a kosztuje 14 tysięcy punktów
    // ścieżki SVG przy KAŻDYM ruchu mapy.
    //
    // Dlatego siatka pojawia się dopiero, gdy pole ma na ekranie co najmniej
    // tyle pikseli — czyli mniej więcej od oddalenia, przy którym widać ulice.
    // Poniżej progu mgła zostaje gładka, tak jak była.
    var GRID_MIN_PX = 34;

    // Bezpiecznik na wypadek kadru albo `sizeM`, których nikt się nie
    // spodziewał: lepiej nie narysować siatki, niż zawiesić przeglądarkę
    // na pętli po milionach pól. Przy progu wyżej realnie nie ma prawa
    // zadziałać — pole 34 px daje na ekranie 4K najwyżej kilkaset pól.
    var LATTICE_MAX = 1500;

    /**
     * Obrysy pól siatki w kadrze, Z POMINIĘCIEM tych już odkrytych.
     * Pusta tablica, gdy pole jest na ekranie za małe, żeby cokolwiek znaczyć.
     * @param {L.Map}  map            mapa (kadr + przeliczenie na piksele)
     * @param {number} sizeM         rozmiar pola z odpowiedzi serwera
     * @param {Object} odkryte       zbiór kluczy osiowych do pominięcia
     * @return {Array} lista zamkniętych pierścieni [[lat,lon], ...]
     */
    function hexLattice(map, sizeM, odkryte) {
        return latticeCells(map, sizeM, { minPx: GRID_MIN_PX, max: LATTICE_MAX, skip: odkryte })
            .map(function (cell) {
                var ring = hexagon(cell.lat, cell.lon, sizeM);
                ring.push(ring[0]); // polyline nie domyka się sama
                return ring;
            });
    }

    /**
     * POLA SIATKI W KADRZE — wspólne jądro dla mgły i dla narzędzia admina.
     *
     * `hexLattice` wyżej dostaje z tego gotowe pierścienie i swoje sztywne
     * progi; narzędzie „Regiony na mapie" potrzebuje TYCH SAMYCH pól, ale
     * z osiami (`q`/`r` są kluczem, po którym rozpoznaje pole pomalowane)
     * i z innymi progami — malowanie kraju odbywa się przy oddaleniu, przy
     * którym mgła słusznie siatki nie rysuje. Rozdzielenie „co to za pola"
     * od „jak je narysować" jest tańsze niż trzecia kopia wzorów Mercatora.
     *
     * @param {L.Map}  map    mapa (kadr + przeliczenie na piksele)
     * @param {number} sizeM  rozmiar pola w metrach Mercatora
     * @param {Object} opts   {minPx, max, skip} — próg czytelności, limit
     *                        bezpieczeństwa, zbiór kluczy 'q:r' do pominięcia
     * @return {Array} [{q, r, lat, lon}, ...]
     */
    function latticeCells(map, sizeM, opts) {
        opts = opts || {};
        var minPx = opts.minPx != null ? opts.minPx : GRID_MIN_PX;
        var max = opts.max != null ? opts.max : LATTICE_MAX;
        var odkryte = opts.skip;
        if (!map || !sizeM) { return []; }
        var bounds = map.getBounds();

        // WIELKOŚĆ POLA MIERZONA, NIE ZGADYWANA Z ODDALENIA. Zoom sam nic tu
        // nie mówi: `sizeM` przychodzi z serwera i zmienia się skokowo razem
        // z drabinką poziomów, a piksel na metr zależy też od szerokości
        // geograficznej. Mierzymy więc wprost — środek kadru i jeden jego
        // wierzchołek, przeliczone tym samym rzutowaniem, którym rysuje mapa.
        var srodekKadru = bounds.getCenter();
        var probny = hexagon(srodekKadru.lat, srodekKadru.lng, sizeM);
        var pSrodek = map.latLngToLayerPoint(srodekKadru);
        var pRog = map.latLngToLayerPoint(L.latLng(probny[0][0], probny[0][1]));
        if (Math.sqrt(3) * pSrodek.distanceTo(pRog) < minPx) { return []; }

        var sw = bounds.getSouthWest();
        var ne = bounds.getNorthEast();
        var a = toMercator(sw.lat, sw.lng);
        var b = toMercator(ne.lat, ne.lng);
        var xMin = Math.min(a[0], b[0]), xMax = Math.max(a[0], b[0]);
        var yMin = Math.min(a[1], b[1]), yMax = Math.max(a[1], b[1]);

        // ZAKRES `q` LICZONY OSOBNO DLA KAŻDEGO WIERSZA, NIE RAZ DLA CAŁEGO
        // KADRU. To nie jest optymalizacja, tylko poprawka błędu: oś `q` zależy
        // OD OBU współrzędnych (x = size·√3·(q + r/2)), więc prostokąt w osiach
        // `q`/`r` opisuje na mapie RÓWNOLEGŁOBOK, a nie kadr. Zmierzone na
        // ekranie 1199 px: wersja z jednym zakresem dawała 2164 pola zamiast
        // ~350 — sześć razy więcej pierścieni do narysowania przy każdym ruchu
        // mapy, w większości poza kadrem. Ta sama pułapka, którą opisuje
        // `DiscoveryGrid::boundsFromAxialExtremes` od drugiej strony.
        var kolumna = Math.sqrt(3) * sizeM;   // odstęp środków w poziomie
        var wiersz  = 1.5 * sizeM;            // odstęp środków w pionie
        var rMin = Math.floor(yMin / wiersz) - 1;
        var rMax = Math.ceil(yMax / wiersz) + 1;
        if ((rMax - rMin + 1) > max) { return []; }

        var pola = [];
        for (var r = rMin; r <= rMax; r++) {
            var qMin = Math.floor(xMin / kolumna - r / 2) - 1;
            var qMax = Math.ceil(xMax / kolumna - r / 2) + 1;
            for (var q = qMin; q <= qMax; q++) {
                if (odkryte && odkryte[q + ':' + r]) { continue; }
                var c = axialToPoint(q, r, sizeM);
                var srodek = fromMercator(c[0], c[1]);
                pola.push({ q: q, r: r, lat: srodek[0], lon: srodek[1] });
                if (pola.length > max) { return []; }
            }
        }
        return pola;
    }

    // Udostępnione na zewnątrz, bo mapa kroniki rysuje pola tego jednego
    // przejazdu i nie ma po co powtarzać wzorów Mercatora trzeci raz. Dostaje
    // gotowy środek pola z serwera (Models\Discovery::rideCellsForEdition) —
    // sama matematyka siatki nadal żyje wyłącznie w Utils\DiscoveryGrid.
    window.ridemoreHexRing = hexagon;

    // SIATKA DLA NARZĘDZIA ADMINA (2026-09-01, „Regiony na mapie").
    //
    // Malowanie regionu potrzebuje trzech rzeczy, których sama mgła nie
    // potrzebowała: pól kadru Z OSIAMI, pola POD KURSOREM (żeby przeciąganie
    // nie musiało trafiać w wielokąt) i środka pola o danych osiach (pędzel
    // większy niż jedno pole). Wszystkie trzy to już policzone wzory z góry
    // tego pliku — wystawione, a nie przepisane. Trzecia kopia matematyki
    // siatki w tym projekcie NIE POWSTAJE; właścicielem pozostaje
    // Utils\DiscoveryGrid, a serwer i tak przelicza to, co dostanie.
    window.ridemoreHexGrid = {
        lattice: latticeCells,
        ring: hexagon,
        // [q, r] pola zawierającego punkt.
        cellAt: function (lat, lon, sizeM) {
            var c = toMercator(lat, lon);
            return pointToAxial(c[0], c[1], sizeM);
        },
        // [lat, lon] środka pola o danych osiach.
        center: function (q, r, sizeM) {
            var c = axialToPoint(q, r, sizeM);
            return fromMercator(c[0], c[1]);
        },
    };

    // JEDNA SKALA ZAMIAST DWÓCH WARSTW (projekt grafika, 2026-08-13)
    // ----------------------------------------------------------------
    // Wcześniej mapa miała mgłę (szarość nad nieodkrytym) i osobną,
    // przełączaną heatmapę. Projekt połączył je w jeden ciąg: szarość znaczy
    // „jeszcze nikt", a kolor — natężenie. Jedna legenda zamiast dwóch.
    //
    // ODKRYTE POLE ODSŁANIA MAPĘ (2026-08-14, uwaga usera: „odkrycia powinny
    // ujawniać trasę, czyli być przezroczyste").
    //
    // Poprzednia wersja malowała odkryty teren na zielono i przy typowych danych
    // — prawie wszystko odkryte RAZ — dawała jednolitą zieloną plamę, pod którą
    // nie było widać ani drogi, ani nazwy, ani własnego śladu. Odkrycie ma
    // ZDEJMOWAĆ mgłę, a nie podmieniać teren na kolor.
    //
    // Dlatego pierwszy stopień ma krycie 0: „odkryte" = goła mapa. Kolor wraca
    // dopiero tam, gdzie niesie informację, której z mapy nie widać — że tędy
    // jeździ się wielokrotnie — i dlatego krycia są niskie (0,18 → 0,38).
    // OBRAMOWANIE PRZESZŁO NA MGŁĘ, patrz hexPolygon niżej.
    // `grid` — obrys pól WEWNĄTRZ mgły (2026-08-30). Ten sam kolor co granica
    // odkrytego (`outline`), ale cieńszy i bledszy, i to rozróżnienie niesie
    // treść: mocna linia = koniec Twojego zasięgu, delikatna = zwykły podział
    // siatki. Gdyby obie wyglądały tak samo, granica własnych odkryć — jedyna
    // linia na tej mapie, która coś MÓWI — utonęłaby w plastrze miodu.
    var UNDISCOVERED = { color: '#8B95A3', opacity: 0.55, outline: '#63707F' };
    var GRID = { color: '#63707F', weight: 0.5, opacity: 0.3 };

    var SCALE = [
        { color: '#5BA969', opacity: 0.00 },  // odkryte — czysta mapa
        { color: '#EFC248', opacity: 0.18 },  // kilka razy
        { color: '#E28446', opacity: 0.28 },  // często
        { color: '#DA5244', opacity: 0.38 }   // bardzo często
    ];

    // Etykiety zależą od tego, CZYJĄ mapę oglądamy: na wspólnej natężenie
    // znaczy „ilu ludzi tędy jeździ", na własnej „jak często jeżdżę TAM JA".
    // W mockupie stały obok siebie „Odkryte" i „Odkryte rzadko", co się nie
    // składa w jedną drabinkę — rozdzielone na dwa zestawy.
    var SCALE_LABELS = {
        all: [__('Odkryte'), __('Rzadko'), __('Popularne'), __('Bardzo popularne')],
        me:  [__('Przejechane'), __('Kilka razy'), __('Często'), __('Bardzo często')]
    };

    // Ile stopni ma sens przy danym maksimum. Gdy najsilniejsze pole w kadrze
    // ma jeden przejazd, nie ma czego stopniować — wszystko jest po prostu
    // „odkryte" i dostaje pierwszy stopień. Malowanie takiej mapy na czerwono
    // sugerowałoby natężenie, którego nie ma.
    function scaleFor(max) {
        var steps = Math.max(1, Math.min(SCALE.length, max));
        if (steps === 1) { return [SCALE[0]]; }
        var out = [];
        for (var i = 0; i < steps; i++) {
            out.push(SCALE[Math.round((i * (SCALE.length - 1)) / (steps - 1))]);
        }
        return out;
    }

    function levelOf(intensity, max, steps) {
        if (max <= 0) { return 0; }
        return Math.min(steps - 1, Math.max(0, Math.ceil((steps * intensity) / max) - 1));
    }

    function swatch(step) {
        // Stopień o kryciu 0 znaczy „widać mapę" i nie da się go pokazać
        // wypełnieniem — w legendzie dostaje sam obrys, czyli dokładnie to,
        // czym jest na mapie: pustym polem z rąbkiem.
        if (!step.opacity) {
            return '<i><b style="background:transparent;border:1px solid ' + (UNDISCOVERED.outline || '#63707F') + '"></b></i>';
        }
        return '<i><b style="background:' + step.color + ';opacity:' + step.opacity + '"></b></i>';
    }

    function renderLegend(el, scale, scope, res, max) {
        if (!el) { return; }
        var labels = SCALE_LABELS[scope === 'all' ? 'all' : 'me'];
        var html = __('<span class="hex-legend__ttl">Poziom odkrycia pól</span>')
            + '<span class="hex-legend__item">' + swatch(UNDISCOVERED) + 'Nieodkryte</span>';
        for (var i = 0; i < scale.length; i++) {
            // Przy mniejszej liczbie stopni bierzemy etykiety z obu końców
            // drabinki, nie pierwsze z brzegu — inaczej mapa z dwoma stopniami
            // opisywałaby najmocniejszy jako „Rzadko".
            var li = scale.length === 1 ? 0
                : Math.round((i * (labels.length - 1)) / (scale.length - 1));
            html += '<span class="hex-legend__item">' + swatch(scale[i]) + labels[li]
                + (res >= 4 && i === scale.length - 1 && max > 1 ? __(' (do ') + max + ')' : '')
                + '</span>';
        }
        el.innerHTML = html;
    }

    // ================================================================
    // PANEL PRZY MAPIE — ZWIJANIE (2026-08-24, prośba usera: „wprowadź na
    // wszystkich mapach możliwość zwinięcia .disc-panel, dla usera na Ostatnie
    // aktywności oraz w społeczności do Skarby").
    //
    // DLACZEGO TUTAJ, A NIE W discovery.php: panel jest nakładką NA MAPIE, więc
    // należy do mapy, a nie do jednej strony. Ten plik ładuje każda strona
    // z mapą odkryć (odkrycia, profil rowerzysty, strona trasy, wydarzenie,
    // kronika), więc każdy panel — także taki, który dopiero powstanie —
    // dostaje zwijanie bez pamiętania o niczym. Do tej daty kod szuflady
    // siedział w `discovery.php` i dotyczył wyłącznie tamtego ekranu.
    //
    // DWIE KONTROLKI, BO TO DWA RÓŻNE ZACHOWANIA:
    //   * na telefonie panel jest SZUFLADĄ od dołu i domyślnie ZAMKNIĘTĄ
    //     (`.is-open`) — inaczej przy 343 px zasłania całą mapę,
    //   * na desktopie stoi otwarty w rogu i można go ZWINĄĆ (`.is-folded`),
    //     bo zasłania kawałek mapy, na który czasem trzeba spojrzeć.
    // Jedna klasa na oba przypadki znaczyłaby dwa różne stany domyślne pod tą
    // samą nazwą — i pierwszy człowiek, który zmieni szerokość okna, dostałby
    // panel w stanie, którego nie wybierał.
    //
    // WYBÓR JEST ZAPAMIĘTYWANY (localStorage, per panel). Zwinięcie, które
    // wraca po każdym przeładowaniu, nie jest zwinięciem, tylko drganiem.
    var STRZALKA = {
        zwin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"'
            + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>',
        rozwin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"'
            + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
    };

    function ridemoreSetupPanel(panel) {
        if (panel.dataset.panelReady) { return; }
        panel.dataset.panelReady = '1';

        var tytul = panel.querySelector('h3');
        var nazwa = panel.dataset.drawer || (tytul ? tytul.textContent.trim() : __('Szczegóły'));

        // --- TELEFON: uchwyt szuflady -------------------------------------
        // Dokładany z JS-a, a nie wpisany w oba panele: to jedno zachowanie,
        // a nie dwa kawałki treści — w HTML-u byłby kopią.
        var uchwyt = document.createElement('button');
        uchwyt.type = 'button';
        uchwyt.className = 'disc-panel__handle';
        uchwyt.setAttribute('aria-expanded', 'false');
        var podpis = document.createElement('span');
        podpis.textContent = nazwa;
        // Licznik uzupełnia strona (discObszar w discovery.php) — dopóki mapa
        // nie odpowie, uchwyt nie kłamie liczbą, tylko jej nie ma.
        var licznik = document.createElement('em');
        licznik.dataset.drawerCount = '';
        uchwyt.appendChild(podpis);
        uchwyt.appendChild(licznik);
        uchwyt.addEventListener('click', function () {
            uchwyt.setAttribute('aria-expanded', panel.classList.toggle('is-open') ? 'true' : 'false');
        });
        panel.insertBefore(uchwyt, panel.firstChild);

        // --- DESKTOP: zwijanie --------------------------------------------
        var zwijak = document.createElement('button');
        zwijak.type = 'button';
        zwijak.className = 'disc-panel__fold';
        var etykieta = document.createElement('span');
        etykieta.textContent = nazwa;
        var ikona = document.createElement('i');
        zwijak.appendChild(etykieta);
        zwijak.appendChild(ikona);

        var klucz = 'ridemore.panel.' + (panel.id || nazwa);

        function ustaw(zwiniety) {
            panel.classList.toggle('is-folded', zwiniety);
            zwijak.setAttribute('aria-expanded', zwiniety ? 'false' : 'true');
            zwijak.title = zwiniety ? __('Rozwiń: ') + nazwa : __('Zwiń panel');
            zwijak.setAttribute('aria-label', zwijak.title);
            ikona.innerHTML = zwiniety ? STRZALKA.rozwin : STRZALKA.zwin;
        }

        zwijak.addEventListener('click', function () {
            var zwiniety = !panel.classList.contains('is-folded');
            ustaw(zwiniety);
            // Prywatny tryb przeglądarki potrafi rzucić przy zapisie — wybór
            // ma wtedy działać do końca wizyty, a nie wywalać skryptu.
            try { localStorage.setItem(klucz, zwiniety ? '1' : '0'); } catch (e) {}
        });

        // Panel z tytułem dostaje przycisk W NAGŁÓWKU (nie ma sensu pisać
        // nazwy dwa razy); panel bez tytułu — jak lista skarbów, która
        // zaczyna się od zakładek — dostaje własny pasek na górze i przy
        // okazji zyskuje podpis, którego na desktopie dotąd nie miał.
        if (tytul) {
            tytul.classList.add('disc-panel__title');
            tytul.appendChild(zwijak);
        } else {
            zwijak.classList.add('disc-panel__fold--solo');
            panel.insertBefore(zwijak, uchwyt.nextSibling);
        }

        var zapisany = null;
        try { zapisany = localStorage.getItem(klucz); } catch (e) {}
        ustaw(zapisany === '1');
    }

    function ridemoreSetupPanels() {
        document.querySelectorAll('.disc-panel').forEach(ridemoreSetupPanel);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ridemoreSetupPanels);
    } else {
        ridemoreSetupPanels();
    }
    // Wystawiane na zewnątrz dla paneli dokładanych po starcie strony.
    window.ridemoreSetupPanels = ridemoreSetupPanels;

    // ================================================================
    // OSTATNIA AKTYWNOŚĆ — „pokaż na mapie" (2026-08-24).
    //
    // Zgłoszenie usera: „klikanie na aktywność nic nie wnosi, a powinno".
    // Wiersze renderuje PHP (partials/ride-feed.php), tutaj zostaje jedna
    // rzecz, której PHP zrobić nie może: ustawienie kadru mapy.
    //
    // STRONICOWANIA JUŻ TU NIE MA (ta sama sesja, druga uwaga usera: „są tylko
    // 3 aktywności i trzeba przełączać paginacją; jeśli będzie dziennie 200
    // aktywności, to co mi z takiego okna"). Panel bierze teraz całą wolną
    // wysokość kolumny, a lista przewija się w środku — wysokość dopasowuje
    // FLEXBOX, nie JavaScript, więc zwinięcie panelu skarbów albo zgaszenie
    // ich warstwy od razu oddaje miejsce tej liście, bez żadnego przeliczania.
    // PODŚWIETLENIE JEDNEGO ŚLADU WEKTOROWO (2026-08-25). Wspólny helper dla
    // panelu „Ostatnia aktywność” (ridemoreRideFeed) i dla wpisów aktywności
    // na profilu rowerzysty — jedno miejsce zna kolor WYBORU, grubość i sposób
    // dopasowania kadru. Zwraca warstwę albo null, gdy na stronie nie ma czym
    // rysować (brak Leafletu).
    //
    // KOLOR WYBORU JEST ZAREZERWOWANY (od 2026-08-27 formalnie, `Utils\
    // TrackPalette::SELECTED` po stronie PHP). Do tej daty „zieleń znaczy ślad
    // w ogóle" trzymało tę rezerwację samo z siebie, bo wszystkie ślady były
    // zielone — ale odkąd każdy ślad ma własny kolor z palety, jedyne, co dzieli
    // „zaznaczony" od „siódmego z brzegu przejazdu", to fakt, że tego niebieskiego
    // NIE MA W PALECIE. Przy okazji wyleciał stamtąd `#1F6FB2` (indeks 1 do
    // migr. 073), który był na tyle blisko, że trasa nim pomalowana wyglądała
    // jak kliknięta.
    //
    // POWTÓRZENIE WARTOŚCI W PHP I W JS jest świadome i ograniczone do jednej
    // stałej — ta sama zasada i ten sam powód co przy `TileController::SCALE`
    // (kolory pól odkryć): jedna strona rysuje w GD, druga w Leaflecie. Zmiana
    // tutaj wymaga zmiany w `Utils\TrackPalette::SELECTED`.
    var RIDEMORE_SELECTED_COLOR = '#2B57C8';

    // ROZPAKOWANIE GEOMETRII Z API (`d6v`, 2026-09-02). Lustro kodera
    // z `Models\GpxGeometry::packedForFile` — TU I TAM opisuje ten sam format,
    // więc zmiana kodowania wymaga zmiany w obu miejscach. Kolejno: base64 ->
    // bajty, varint po 7 bitów, zygzak (ostatni bit niesie znak), a na końcu
    // sumowanie różnic, bo w strumieniu stoją ODLEGŁOŚCI między punktami,
    // nie same punkty. Współrzędne idą w milionowych częściach stopnia.
    window.ridemoreUnpackTrack = function (b64) {
        var s;
        try { s = atob(b64); } catch (e) { return []; }
        var i = 0, n = s.length, lat = 0, lon = 0, out = [];
        function liczba() {
            var wynik = 0, przesun = 0, b;
            do {
                b = s.charCodeAt(i++);
                wynik += (b & 0x7F) * Math.pow(2, przesun);
                przesun += 7;
            } while (b & 0x80 && i < n);
            // Zygzak: parzyste to liczby dodatnie, nieparzyste — ujemne.
            return (wynik % 2) ? -(wynik + 1) / 2 : wynik / 2;
        }
        while (i < n) {
            lat += liczba();
            if (i >= n) { break; }
            lon += liczba();
            out.push([lat / 1e6, lon / 1e6]);
        }
        return out;
    };

    // PODŚWIETLENIE ŚLADU — GEOMETRIA Z API, NIE PLIK GPX (2026-09-02).
    //
    // Zgłoszenie usera: „klikam na aktywność 200 km i czekam, aż wczyta się cały
    // GPX, a potrzebujemy tylko ją oznaczyć". Do tej daty szło to przez
    // leaflet-gpx, czyli pobranie i sparsowanie 10+ MB XML-a z tętnem, mocą
    // i kadencją po to, żeby wyjąć z każdego punktu dwie liczby. Teraz `url`
    // wskazuje endpoint geometrii (`/api/rides/{id}/track`,
    // `/api/tracks/{id}/geometry`), a ten oddaje spakowaną linię — kilkanaście
    // razy mniej bajtów i zero parsowania XML-a.
    //
    // ZWRACA WARSTWĘ OD RAZU, jeszcze pustą, i dopiero potem ją wypełnia.
    // Wołający (panel aktywności niżej, wpisy na profilu rowerzysty) trzymają
    // ją, żeby zdjąć poprzedni wybór — obietnica byłaby dla nich zmianą
    // sposobu użycia, a linia bez punktów niczego na mapie nie psuje.
    // Ślad bez geometrii (plik skasowany, nieczytelny, brak dostępu) sam się
    // sprząta: warstwa znika z mapy, kadr zostaje ten, który był.
    //
    // `opts.color` (2026-09-03, strona `/przejazd/{id}`) — ta sama funkcja
    // rysuje ślad, który jest TEMATEM strony, a nie jednym z wielu wybranym
    // z listy. Tam kolor WYBORU byłby kłamstwem: nie ma z czego wybierać,
    // a przejazd ma własny kolor z palety (migr. 073), po którym poznaje się
    // go na każdej innej mapie. Domyślnie zostaje niebieski wyboru, więc
    // wszystkie dotychczasowe wywołania zachowują się bez zmian.
    window.ridemoreFocusTrack = function (map, url, opts) {
        if (!map || !url || !window.L) { return null; }
        opts = opts || {};

        var linia = L.polyline([], {
            color: opts.color || RIDEMORE_SELECTED_COLOR, weight: 5, opacity: 1,
        }).addTo(map);

        function sprzataj() {
            try { map.removeLayer(linia); } catch (e) {}
        }

        // Pomiar dla monitora, jeśli ta mapa go ma (patrz `ridemoreMapBusy`).
        // Podświetlenie śladu jest tym, co user klika i na co patrzy, więc ma
        // się liczyć do tego samego cyklu co reszta.
        var busy = map.ridemoreBusy || null;
        if (busy) { busy.start('slad', __('ślad przejazdu')); }

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (o) {
                if (busy) { busy.koniec('slad', o && o.n ? o.n + __(' punktów') : __('bez geometrii')); }
                if (!o || !o.pts) { sprzataj(); return; }
                var punkty = window.ridemoreUnpackTrack(o.pts);
                if (!punkty.length) { sprzataj(); return; }
                linia.setLatLngs(punkty);
                try {
                    // Kadr ze skrajnych pikseli policzonych przez serwer — ta
                    // sama wartość, którą zna renderer kafli, i nie trzeba na
                    // nią czekać dłużej niż na samą linię.
                    var b = o.bounds
                        ? [[o.bounds.south, o.bounds.west], [o.bounds.north, o.bounds.east]]
                        : linia.getBounds();
                    map.fitBounds(b, { padding: [40, 40], maxZoom: 14, animate: true });
                } catch (err) {}
            })
            .catch(function () { if (busy) { busy.koniec('slad', __('błąd')); } sprzataj(); });

        return linia;
    };

    function ridemoreRideFeed(map, root, opts) {
        if (!root || root.dataset.feedReady) { return; }
        root.dataset.feedReady = '1';
        opts = opts || {};

        // PODŚWIETLANIE ŚLADU Z LISTY (2026-08-25, spójność z profilem
        // rowerzysty: „osobista mapa nie koreluje funkcjonalnością").
        // Opcja `tracks` to mapa „id przejazdu → adres GPX". Gdy wiersz ma
        // swój plik, klik rysuje ślad WEKTOROWO na wierzchu (niebieski —
        // jedyny kolor WYBORU w SEMANTYCE KOLORÓW MAPY; zieleń znaczy ślad
        // w ogóle, fiolet skarba) i to on wyznacza kadr. Rastrowa warstwa
        // wszystkich śladów zostaje pod spodem bez zmian — kontekst „na tle
        // reszty" jest połową wartości tej mapy, więc nic nie znika.
        // Ponowny klik tej samej pozycji zdejmuje wybór — bez tego dałoby się
        // wejść w tryb bez wyjścia. Przejazdy bez pliku w mapie (solo, skarby)
        // dostają jak dotąd samo dopasowanie kadru.
        var trackUrls = opts.tracks || {};
        var wektor = null;
        var wybranyId = null;

        function zdejmijWektor() {
            if (wektor && map && typeof map.removeLayer === 'function') {
                map.removeLayer(wektor);
            }
            wektor = null;
            wybranyId = null;
        }

        root.addEventListener('click', function (e) {
            var wiersz = e.target.closest('.disc-feed__row[data-bounds]');
            if (!wiersz || !map || typeof map.fitBounds !== 'function') { return; }

            var b;
            try { b = JSON.parse(wiersz.dataset.bounds); } catch (err) { return; }
            if (!b || typeof b.south !== 'number') { return; }

            root.querySelectorAll('.disc-feed__row.is-on').forEach(function (w) {
                w.classList.remove('is-on');
            });

            var rid = wiersz.dataset.ride || null;
            var url = rid && trackUrls[rid];

            if (url) {
                if (wybranyId === rid) {
                    zdejmijWektor();
                    return; // stan „wszystkie równo” — kadr zostaje, jak na profilu
                }
                zdejmijWektor();
                wektor = window.ridemoreFocusTrack(map, url);
                if (wektor) {
                    wybranyId = rid;
                    wiersz.classList.add('is-on');
                    return; // kadr ustawi wczytany ślad — dokładniejszy niż prostokąt pól
                }
                wybranyId = null;
            }

            wiersz.classList.add('is-on');
            zdejmijWektor();

            // `padding` zostawia margines na kontrolki mapy, żeby zdarzenie nie
            // wylądowało pod przełącznikiem warstw albo pod tym panelem.
            map.fitBounds(
                [[b.south, b.west], [b.north, b.east]],
                { padding: [40, 40], maxZoom: 14, animate: true }
            );
        });
    }

    window.ridemoreRideFeed = ridemoreRideFeed;

    // STAN KONTROLKI WARSTW — JEDEN CZYTNIK DLA CAŁEGO SERWISU (Etap 1, 2026-08-26).
    //
    // Powstał, bo ten sam kod stał skopiowany w czterech miejscach, za każdym
    // razem pod inną nazwą: `on()` w discovery.php, `wlaczona()` w trail.php,
    // w rider-profile.php i w event-page.php. Cztery kopie jednego `querySelector`.
    //
    // ZWRACA WYŁĄCZNIE KLUCZE, KTÓRE KONTROLKA FAKTYCZNIE MA, i to jest
    // najważniejsza własność tej funkcji, a nie oszczędność linijek. Warstwa
    // bez przełącznika NIE MOŻE wyjść stąd jako `false`, bo `false` znaczy
    // „człowiek ją zgasił"; ma jej tu w ogóle nie być, żeby zadziałał stan
    // domyślny modułu. Na tym stoi np. strona trasy, która nie daje wyboru
    // „Ślady", a mimo to ma je zapalone.
    //
    // Ta sama zasada obsługuje warstwę zależną (`treasuresFound`): kontrolka
    // bez niej nie oddaje klucza, więc moduł widzi `undefined`, czyta to jako
    // „tej warstwy tu nie ma" i gasi ją razem z rodzicem. Dokładnie tak, jak
    // strony robiły to dotąd ręcznie, przekazując `null`.
    window.ridemoreReadLayers = function (box) {
        var stan = {};
        if (!box) { return stan; }
        box.querySelectorAll('[data-layer]').forEach(function (cb) {
            // DZIECKO GAŚNIE RAZEM Z RODZICEM (Etap 2, drzewiasta kontrolka
            // 2026-08-26) — niezależnie od WŁASNEGO stanu checkboksa. Rodzic
            // wynika z tego, w którym `[data-children-of]` fizycznie stoi ten
            // checkbox (partial renderuje je zawsze zagnieżdżone), nie
            // z osobnej listy w JS, więc dołożenie kolejnego poziomu drzewa
            // w słowniku działa tu bez zmiany kodu.
            var rodzicBlok = cb.closest('[data-children-of]');
            var rodzicWlaczony = true;
            if (rodzicBlok) {
                var rodzicCb = box.querySelector('[data-layer="' + rodzicBlok.dataset.childrenOf + '"]');
                rodzicWlaczony = !rodzicCb || rodzicCb.checked;
            }
            stan[cb.dataset.layer] = !!cb.checked && rodzicWlaczony;
        });
        return stan;
    };

    // PODPIĘCIE KONTROLKI DO MAPY — JEDNO MIEJSCE (2026-08-30, zgłoszenie
    // usera: „zaznaczanie checkboxa w kontrolce Warstwy w ogóle nie działa").
    //
    // Kontrolka jest WSPÓLNA od 2026-08-19 (`partials/map-layers.php`), ale
    // jej PODPIĘCIE do mapy zostało po stronie każdej strony z osobna —
    // `discovery.php`, `trail.php`, `rider-profile.php` i `event-page.php`
    // mają po własnej kopii `addEventListener('change', ...)`. Piąty ekran,
    // `discovery-app.php` (pełnoekranowa mapa apki), dostał kontrolkę i nie
    // dostał podpięcia: czytał stan checkboxów RAZ, przy tworzeniu mapy,
    // a potem nie słuchał już niczego. Checkbox się zaznaczał i nie robił
    // dosłownie nic.
    //
    // To jest dokładnie ta klasa błędu, przed którą miał chronić wspólny
    // partial — tyle że przeniesiono do niego WYGLĄD, a nie ZACHOWANIE.
    // Stąd ta funkcja: nowa mapa z kontrolką ma mieć działające przełączniki
    // z jednego wywołania, a nie z przepisanej kopii.
    //
    // ZAPIS STANU W ADRESIE zostaje po stronie `discovery.php` — to jedyny
    // ekran, który go ma, i jedyny, na którym adres da się komuś podesłać.
    //
    // STAN CZYTAMY CZYTNIKIEM, NIE Z `cb.checked`: dziecko gaśnie razem
    // z rodzicem, więc jego stan SKUTECZNY to `checked ∧ rodzic` — a silnik
    // ma dostać to samo, co `ridemoreReadLayers` oddało przy starcie mapy.
    window.ridemoreBindLayers = function (map, box) {
        if (!map || !box || typeof map.ridemoreSetLayer !== 'function') { return; }
        box.addEventListener('change', function (e) {
            var cb = e.target.closest && e.target.closest('[data-layer]');
            if (!cb) { return; }
            var stan = ridemoreReadLayers(box);
            map.ridemoreSetLayer(cb.dataset.layer, stan[cb.dataset.layer]);

            // PRZEŁĄCZENIE RODZICA ZMIENIA TAKŻE STAN SKUTECZNY DZIECI —
            // silnik musi się o tym dowiedzieć osobno, bo trzyma je jako
            // niezależne klucze.
            var blok = box.querySelector('[data-children-of="' + cb.dataset.layer + '"]');
            if (!blok) { return; }
            blok.querySelectorAll('[data-layer]').forEach(function (dziecko) {
                map.ridemoreSetLayer(dziecko.dataset.layer, stan[dziecko.dataset.layer]);
            });
        });
    };

    // SKŁADANIE PARAMETRU FILTRA Z ZAPALONYCH DZIECI (Etap 2, 2026-08-26).
    // `filters` to {kluczRodzica: {param, children: {kluczDziecka: wartość}}} —
    // dla „Skarbów" dziś {treasures: {param:'stan', children:{treasuresFound:
    // 'moje', treasuresNew:'nowe'}}}. Generyczne CELOWO: dołożenie drugiej
    // rodziny filtrowanych markerów (inny słownikowy `filterParam`) nie
    // dotyka tej funkcji, tylko wiersza w słowniku i wpisu w `filters`
    // budowanego przez kontroler z `Models\MapLayer::tree()`.
    //
    // Zwraca: `''` (komplet, brak filtra), konkretną wartość (dokładnie jedno
    // dziecko zapalone), albo `null` (nie pytamy serwera wcale — rodzic
    // zgaszony, albo zapalony bez żadnego zapalonego dziecka mimo że dzieci
    // W OGÓLE ISTNIEJĄ). Rodzina BEZ dzieci (np. „Skarby" na profilu
    // rowerzysty — zawężenie do osoby robi tam sam adres endpointu, nie ten
    // parametr) to osobny przypadek: rodzic zapalony = brak filtra.
    function ridemoreComposeFilter(parentKey, layers, filters) {
        var opis = filters && filters[parentKey];
        if (!opis) {
            // ZAPAS na wypadek strony, która nie zbudowała opisu (nie powinno
            // się zdarzać po Etapie 2) — stara, dwuwartościowa reguła skarbów,
            // żeby luka w konfiguracji zawężała funkcję, a nie wyłączała ją.
            if (parentKey !== 'treasures') { return layers[parentKey] ? '' : null; }
            return layers.treasures
                ? (layers.treasuresFound ? '' : 'nowe')
                : (layers.treasuresFound ? 'moje' : null);
        }
        if (!layers[parentKey]) { return null; }
        var klucze = Object.keys(opis.children || {});
        if (klucze.length === 0) { return ''; }
        var wlaczone = klucze.filter(function (k) { return !!layers[k]; });
        if (wlaczone.length === 0) { return null; }
        if (wlaczone.length === klucze.length) { return ''; }
        return opis.children[wlaczone[0]];
    }

    // ================================================================
    // CO SIĘ TERAZ ŁADUJE — MONITOR MAPY (2026-09-02).
    //
    // Zgłoszenie usera: „ślad ładuje się szybko, ale muszę długo czekać, aż
    // heksy pokrycia się wyrenderują — i jest bezwładność, nie wiem, co się
    // dzieje". Sedno jest w drugim zdaniu: czekanie boli mniej, gdy widać, NA
    // CO się czeka, a bez tego nie da się też powiedzieć, co naprawiać.
    //
    // ZMIERZONE PRZY POWSTAWANIU (dev, przesunięcie kadru na /odkrycia):
    // API pól 82 ms, API skarbów 50 ms, a POJEDYNCZE KAFLE ŚLADÓW 0,9–3,2 s,
    // po kilka naraz. Czyli wąskim gardłem nie jest ani zapytanie o pola, ani
    // rysowanie w przeglądarce, tylko GENEROWANIE KAFLI na serwerze przy
    // pierwszym wejściu w dany obszar (patrz Controllers\TileController: drugi
    // raz ten sam kafel oddaje już Apache prosto z dysku).
    //
    // DWIE RZECZY NARAZ, ŚWIADOMIE:
    //   1. mały panel na mapie — dla człowieka, żeby wiedział, że coś się
    //      dzieje, i co dokładnie,
    //   2. dziennik (`window.ridemoreMapLog`, w dev dodatkowo
    //      `storage/map-perf.log`) — dla nas, żeby dało się WSKAZAĆ winowajcę
    //      zamiast go zgadywać.
    //
    // PANEL NIE JEST MODALEM (choć user tak go nazwał): nie przechwytuje
    // kliknięć (`pointer-events: none` w CSS) i nie blokuje mapy. Mapa w czasie
    // ładowania jest UŻYWALNA — przesuwanie jej dalej jest właśnie tym, co
    // człowiek chce wtedy robić, a okno modalne odebrałoby mu to na kilka sekund.
    //
    // JEDNA INSTANCJA NA MAPĘ, trzymana na obiekcie mapy: profil rowerzysty
    // woła `ridemoreDiscoveryMap` na mapie stworzonej wcześniej dla śladów,
    // więc drugie wywołanie ma dostać ten sam monitor, a nie drugi panel.
    var BUSY_PROG_MS = 1500;   // od kiedy mówimy, że to długo (patrz `rysuj`)
    var BUSY_LOG_MAX = 200;    // ile wpisów trzymamy w pamięci karty

    window.ridemoreMapLog = window.ridemoreMapLog || [];

    /** Etykieta warstwy kafli z jej adresu — `/assets/tiles/{warstwa}/{klucz}/…`. */
    function ridemoreTileLabel(url) {
        var m = /\/assets\/tiles\/([^/]+)\/([^/]+)\//.exec(url || '');
        // Podkład (OSM/OpenFreeMap) też jest warstwą kafli i też potrafi
        // zająć łącze — ma się nazywać po ludzku, a nie „kafle".
        if (!m) { return /openstreetmap|openfreemap|tile\./.test(url || '') ? __('podkład mapy') : 'kafle'; }
        var warstwa = m[1];
        var klucz = m[2];
        if (warstwa === 'hex') { return __('pola odkryć (kafle)'); }
        if (klucz.indexOf('kd-') === 0) { return __('trasy ukończone'); }
        if (klucz.indexOf('kn-') === 0) { return __('trasy nieukończone'); }
        if (klucz === 'kr') { return __('znane trasy'); }
        return __('ślady przejazdów');
    }

    function ridemoreFmtMs(ms) {
        return ms < 1000 ? Math.round(ms) + ' ms' : (Math.round(ms / 100) / 10) + ' s';
    }

    window.ridemoreMapBusy = function (map, container) {
        if (!map) { return null; }
        if (map.ridemoreBusy) { return map.ridemoreBusy; }

        var box = document.createElement('div');
        box.className = 'map-busy';
        box.setAttribute('aria-live', 'polite');
        box.hidden = true;
        (container || map.getContainer()).appendChild(box);

        var zadania = {};        // klucz -> { etykieta, start, info }
        var ile = 0;
        var tyk = null;
        var cykl = null;         // pomiar CAŁEGO przesunięcia kadru

        // DZIENNIK. Wpis powstaje na ZAKOŃCZENIU zadania, bo dopiero wtedy zna
        // czas — a czas jest tu jedyną interesującą liczbą.
        var doWyslania = [];
        function zapisz(wpis) {
            wpis.t = new Date().toISOString();
            window.ridemoreMapLog.push(wpis);
            if (window.ridemoreMapLog.length > BUSY_LOG_MAX) { window.ridemoreMapLog.shift(); }
            // console.debug, nie log: to jest ślad diagnostyczny, więc ma być
            // pod ręką w konsoli, ale nie ma zaśmiecać jej domyślnego widoku.
            if (window.console && console.debug) {
                console.debug(__('[mapa]'), wpis.co,
                    wpis.ms !== undefined ? ridemoreFmtMs(wpis.ms) : '', wpis.szczegoly || '');
            }
            if (window.RIDEMORE_MAP_PERF) {
                doWyslania.push(wpis);
                zaplanujWysylke();
            }
        }

        // WYSYŁKA ZBIORCZA I TYLKO W DEV (adres wstawia `Support::leafletHead`
        // wyłącznie przy APP_ENV=dev). Zbiorczo, bo pojedyncze żądanie na kafel
        // dołożyłoby ruchu dokładnie tam, gdzie go mierzymy.
        var wysylkaTimer = null;
        function zaplanujWysylke() {
            if (wysylkaTimer) { return; }
            wysylkaTimer = setTimeout(wyslij, 4000);
        }
        function wyslij() {
            wysylkaTimer = null;
            if (!doWyslania.length || !window.RIDEMORE_MAP_PERF) { return; }
            var paczka = doWyslania.splice(0, doWyslania.length);
            try {
                fetch(window.RIDEMORE_MAP_PERF, {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': window.RIDEMORE_MAP_PERF_TOKEN || '',
                    },
                    body: JSON.stringify({ strona: location.pathname + location.search, wpisy: paczka }),
                }).catch(function () {});
            } catch (e) {}
        }
        // Ostatnia paczka przy wyjściu ze strony — inaczej najciekawszy wpis
        // („zamknąłem kartę, bo się nie doczekałem") nigdy by nie dojechał.
        window.addEventListener('pagehide', wyslij);

        function rysuj() {
            var teraz = performance.now();
            var linie = [];
            var dlugie = false;
            Object.keys(zadania).forEach(function (k) {
                var z = zadania[k];
                var ms = teraz - z.start;
                if (ms > BUSY_PROG_MS) { dlugie = true; }
                linie.push('<li><span>' + z.etykieta + (z.info ? ' <b>' + z.info + '</b>' : '')
                    + '</span><i>' + ridemoreFmtMs(ms) + '</i></li>');
            });

            if (!linie.length) {
                box.hidden = true;
                box.innerHTML = '';
                return;
            }

            // PODPOWIEDŹ, A NIE PRZEPROSINY. Przy pierwszym wejściu w obszar
            // kafle POWSTAJĄ (GD + zapytania o geometrię), więc czekanie ma
            // konkretną przyczynę i konkretny koniec — drugie wejście w to samo
            // miejsce jest natychmiastowe. Bez tego zdania „3,1 s" wygląda jak
            // usterka, a jest jednorazowym kosztem.
            box.innerHTML = '<p class="map-busy__t">'
                + (dlugie ? __('Przygotowuję ten fragment mapy…') : __('Ładuję mapę…'))
                + '</p><ul>' + linie.join('') + '</ul>'
                + (dlugie
                    ? __('<p class="map-busy__hint">Kafle tego obszaru powstają przy pierwszym')
                        + __(' wejściu. Następnym razem pojawią się od razu.</p>')
                    : '');
            box.hidden = false;
        }

        function tykaj() {
            if (tyk || !ile) { return; }
            // 250 ms: dość, żeby licznik nie stał w miejscu, i mało, żeby nic
            // nie kosztować.
            tyk = setInterval(rysuj, 250);
        }
        function stop() {
            if (tyk) { clearInterval(tyk); tyk = null; }
        }

        var api = {
            /** Zaczyna zadanie o tym kluczu (powtórzony klucz restartuje pomiar). */
            start: function (klucz, etykieta) {
                if (!zadania[klucz]) { ile++; }
                zadania[klucz] = { etykieta: etykieta || klucz, start: performance.now(), info: '' };
                if (!cykl) { cykl = { start: performance.now(), zadan: 0 }; }
                cykl.zadan++;
                tykaj();
                rysuj();
                return klucz;
            },
            /** Dopisuje to, co wiadomo w trakcie (np. „12/40"). */
            info: function (klucz, tekst) {
                if (zadania[klucz]) { zadania[klucz].info = tekst; rysuj(); }
            },
            /** Kończy zadanie i zapisuje jego czas w dzienniku. */
            koniec: function (klucz, szczegoly) {
                var z = zadania[klucz];
                if (!z) { return; }
                delete zadania[klucz];
                ile--;
                zapisz({ co: z.etykieta, ms: performance.now() - z.start, szczegoly: szczegoly || z.info || '' });
                rysuj();
                if (!ile) {
                    stop();
                    if (cykl) {
                        // CAŁY CYKL, nie suma zadań: zadania jadą równolegle,
                        // więc dopiero ta liczba mówi, ile człowiek naprawdę
                        // czekał, patrząc na mapę.
                        zapisz({ co: 'cykl mapy', ms: performance.now() - cykl.start, szczegoly: cykl.zadan + __(' zadań') });
                        cykl = null;
                    }
                }
            },
            /** Owija obietnicę: zadanie kończy się razem z nią, także błędem. */
            czekaj: function (klucz, etykieta, promise) {
                api.start(klucz, etykieta);
                return promise.then(
                    function (v) { api.koniec(klucz); return v; },
                    function (e) { api.koniec(klucz, __('błąd')); throw e; }
                );
            },
            log: zapisz,
        };

        // WARSTWY KAFLI PODPINAJĄ SIĘ SAME, przez `layeradd` — a nie przez
        // wyliczenie ich z nazwy w module niżej. Warstwa dołożona później
        // (przełącznik „Ślady", nowa warstwa ze słownika) trafia tu bez
        // dopisywania czegokolwiek, a to jest dokładnie ta klasa zmian, która
        // w tym pliku zdarza się najczęściej.
        function podepnij(w) {
            if (!w || !(window.L && L.GridLayer) || !(w instanceof L.GridLayer) || w.ridemoreBusyOn) { return; }
            w.ridemoreBusyOn = true;
            var klucz = 'kafle:' + (w._url || Math.random());
            var etykieta = ridemoreTileLabel(w._url);
            var stan = null;

            function zacznij() {
                if (stan) { return; }
                stan = { zamowione: 0, gotowe: 0, bledy: 0 };
                api.start(klucz, etykieta);
            }

            w.on('loading', zacznij);
            w.on('tileloadstart', function () {
                zacznij();
                stan.zamowione++;
                api.info(klucz, stan.gotowe + '/' + stan.zamowione);
            });
            w.on('tileload', function () {
                if (!stan) { return; }
                stan.gotowe++;
                api.info(klucz, stan.gotowe + '/' + stan.zamowione);
            });
            w.on('tileerror', function () {
                if (stan) { stan.bledy++; }
            });
            w.on('load', function () {
                var s = stan;
                stan = null;
                api.koniec(klucz, s
                    ? s.gotowe + '/' + s.zamowione + ' kafli' + (s.bledy ? __(', błędów ') + s.bledy : '')
                    : '');
            });
        }

        map.eachLayer(podepnij);
        map.on('layeradd', function (e) { podepnij(e.layer); });

        // POJEDYNCZE POWOLNE ŻĄDANIE trafia do dziennika osobno, bo licznik
        // „12/40" mówi ILE, a nie KTÓRY kafel zjadł trzy sekundy. Resource
        // Timing zna to bez żadnej instrumentacji po naszej stronie.
        if (window.PerformanceObserver) {
            try {
                new PerformanceObserver(function (lista) {
                    lista.getEntries().forEach(function (r) {
                        if (r.duration < BUSY_PROG_MS) { return; }
                        if (!/\/assets\/tiles\/|\/api\//.test(r.name)) { return; }
                        zapisz({
                            co: __('wolne żądanie'),
                            ms: r.duration,
                            szczegoly: r.name.replace(location.origin, ''),
                        });
                    });
                }).observe({ type: 'resource', buffered: true });
            } catch (e) {}
        }

        map.ridemoreBusy = api;
        return api;
    };

    window.ridemoreDiscoveryMap = function (el, options) {
        options = options || {};
        // KONTEKST MAPY — 'all' (społeczność), 'me' (moja), 'rider' (cudzy profil).
        // Nazwa `context`, a nie dawne `scope`, bo od Etapu 1 to jest JEDNO
        // pojęcie dla wszystkich rodzin warstw, a nie parametr samych pól:
        // z niego rozwiązuje się także źródło kafli (`options.sources`)
        // i zawężenie skarbów. W adresie API zostaje `scope=`, bo to kontrakt
        // serwera i nie ma powodu go ruszać.
        var scope = options.context || 'all';
        var endpoint = options.endpoint;
        var emptyEl = options.emptyEl || null;
        var legendEl = options.legendEl || null;
        var extraQuery = options.query || '';
        // SLUG DOKLEJANY CENTRALNIE, nie wpisany w adres przez wolajacego.
        // Przy scope 'rider' potrzebuja go DWA rozne endpointy (pola i skarby),
        // a kazdy z nich sam buduje swoj query string. Podanie slugu w gotowym
        // adresie konczylo sie drugim znakiem zapytania w URL-u skarbow
        // (zmierzone: 400 z API) i brakiem slugu w adresie pol (404).
        var riderQuery = options.slug ? '&slug=' + encodeURIComponent(options.slug) : '';

        // KADR STARTOWY Z SERWERA (2026-08-19, uwaga usera: „centrujesz mapę
        // w środku Polski, a nie w miejscu wystąpienia mapy").
        //
        // `options.bounds` = {south, west, north, east} — prostokąt policzony
        // po stronie serwera z tego, CO TA MAPA POKAZUJE: pól odkrytych przez
        // widza, pól trasy, śladów przejazdów. Poprzednio każda mapa startowała
        // w środku kraju przy oddaleniu 6 i dopiero po odpowiedzi serwera
        // dociągała się do tego, co dostała (`fitToCells`). To było błędne
        // potrójnie: pierwsze żądanie szło dla całej Polski, obraz skakał
        // w oczach, a dociągnięcie i tak umiało trafić wyłącznie w to, co
        // wpadło do pierwszego (za szerokiego) kadru.
        //
        // Kadr z serwera WYGRYWA z `fitToCells` — stąd `fitted = true` niżej.
        var startBounds = null;
        if (options.bounds
            && typeof options.bounds.south === 'number'
            && typeof options.bounds.north === 'number') {
            startBounds = L.latLngBounds(
                [options.bounds.south, options.bounds.west],
                [options.bounds.north, options.bounds.east]
            );
        }

        // Mapa może być WŁASNA (strony Discovery) albo PODANA Z ZEWNĄTRZ
        // (profil rowerzysty, gdzie warstwa mgły kładzie się na istniejącą mapę
        // ze śladami GPX — jedna mapa, dwie warstwy, zamiast dwóch map obok
        // siebie mówiących o tym samym).
        var attached = !!options.map;
        var map = options.map || ridemoreCreateMap(el, options.center || [52.0, 19.3]);
        // Przy kadrze z serwera nie ustawiamy oddalenia — policzy je fitBounds
        // (w init niżej, PO invalidateSize, bo inaczej Leaflet dobiera zoom do
        // kontenera o zerowej szerokości).
        if (!attached && !startBounds) { map.setZoom(options.zoom || 6); }

        // Własny panel poniżej domyślnego overlayPane (z-index 400). Bez tego
        // kolejność rysowania zależałaby od tego, co wczyta się pierwsze —
        // a ślady GPX na profilu ładują się asynchronicznie i potrafiłyby
        // wylądować POD polami, czyli zniknąć.
        if (!map.getPane('discoveryHex')) {
            map.createPane('discoveryHex').style.zIndex = 350;
        }

        var layer = L.layerGroup().addTo(map);
        // MONITOR ŁADOWANIA (2026-09-02) — panel „co się teraz dzieje" plus
        // dziennik czasów. Tworzony TU, zanim powstaną warstwy kafli, bo
        // podpina się do nich przez `layeradd` i musi zdążyć przed pierwszą.
        var busy = window.ridemoreMapBusy ? window.ridemoreMapBusy(map) : null;
        var lastData = null;
        var pending = null;
        var timer = null;
        // Kadr podany przez stronę jest ostateczny — `fitToCells` nie ma go
        // nadpisywać danymi, które sam dopiero co ograniczył.
        var fitted = !!startBounds;

        // WARSTWY (2026-08-13). Trzy NIEZALEŻNE przełączniki, nie zakładki:
        //   odkrycia — mgła i odkryte pola; zgaszona zostawia samą mapę,
        //   heatmapa — natężenie przejazdów; ZGASZONA DOMYŚLNIE, bo w stanie
        //              podstawowym pole ma znaczyć „odkryte", a nie „popularne",
        //   trasy    — przebiegi znanych tras z bazy, rysowane na wierzchu.
        //
        // Heatmapa domyślnie wyłączona jest decyzją usera: „po wyłączeniu
        // pojawiają się tylko białe plamy odkrytych elementów" — czyli warstwa
        // podstawowa ma odpowiadać na pytanie „gdzie byłem", a natężenie jest
        // dodatkiem, nie stanem wyjściowym.
        //
        // TRASY DOMYŚLNIE WŁĄCZONE (2026-08-19, decyzja usera; do tej daty było
        // odwrotnie). Powód jest ten sam co przy skarbach: szlak nie jest
        // dodatkiem do odpowiedzi „gdzie byłem", tylko ZAPROSZENIEM („co
        // jeszcze możesz zaliczyć"), a schowany za przełącznikiem nie zaprasza
        // nikogo. Wypłynęło przy błędzie, przez który świeżo dodana trasa nie
        // rysowała się w ogóle — dwie różne przyczyny tego samego „nie widzę
        // swojej trasy".
        // STAN WARSTW = DOMYŚLNE ⊕ TO, CO NAPRAWDĘ JEST W KONTROLCE (Etap 2,
        // 2026-08-26). Od Etapu 1 `options.layers` to zawsze wynik
        // `ridemoreReadLayers(box)`, a ten czyta WYŁĄCZNIE checkboksy, które
        // kontrolka faktycznie ma — łącznie z regułą „dziecko gaśnie razem
        // z rodzicem" (patrz ta funkcja niżej). Dzięki temu nie trzeba już
        // osobno sprawdzać, CZY strona ma dany przełącznik: klucza po prostu
        // nie będzie w `options.layers`, więc `Object.assign` zostawi
        // domyślną. To jest dokładnie ten sam mechanizm, który do 2026-08-23
        // wymagał ręcznego rozróżniania „false" od „nie ma takiej warstwy" —
        // teraz robi to jedna reguła w jednym miejscu, nie w każdym wywołaniu.
        var layers = Object.assign({
            cells: true,
            heat: false,
            // „ŚLADY"/„SKARBY" DOMYŚLNIE ZAPALONE, w odróżnieniu od heatmapy:
            // to zaproszenia („tędy jeżdżą ludzie", „jest tu coś do znalezienia"),
            // nie dodatki analityczne. Dzieci „Skarbów" idą tą samą zasadą —
            // kolekcja to fakt o tym, co zebrane, nie coś do chowania na start.
            slady: true, trails: true,
            treasures: true, treasuresFound: true, treasuresNew: true,
        }, options.layers || {});
        // PANEL IDZIE ZA SWOJĄ WARSTWĄ (2026-08-24, prośba usera: „Skarby
        // w pobliżu powinny się pojawiać, jeśli tylko mamy zaznaczoną warstwę
        // skarby"). Panel, który mówi o warstwie zgaszonej na mapie, opisuje
        // coś, czego nie widać — i zabiera miejsce liście, która widać ma.
        //
        // Wiązanie idzie atrybutem `data-layer-panel`, nie po `id`: to jest
        // zachowanie MAPY, więc ma działać dla każdego panelu, który się na
        // nią kiedyś dołoży, bez dopisywania tu kolejnych identyfikatorów.
        function syncPanels() {
            document.querySelectorAll('[data-layer-panel]').forEach(function (panel) {
                var klucze = panel.dataset.layerPanel.split(',');
                var widoczny = klucze.some(function (k) { return !!layers[k.trim()]; });
                panel.hidden = !widoczny;
            });
        }

        var treasureLayer = L.layerGroup().addTo(map);
        var treasurePending = null;
        // Adres ŻĄDANIA W LOCIE — po to, żeby nie przerywać go i nie ponawiać,
        // gdy scheduleRefresh pyta o dokładnie ten sam kadr (patrz refreshTreasures).
        var treasurePendingUrl = null;
        // Rejestr znaczników po id — potrzebny, żeby dało się WSKAZAĆ konkretny
        // skarb z listy obok mapy (`ridemoreFocusTreasure`). Bez niego panel
        // „Skarby, które czekają" mógł tylko wypisać nazwę i na tym kończył
        // (zgłoszenie usera 2026-08-20: „nie da się na nie kliknąć, więc trudno
        // je zlokalizować").
        var treasureMarkers = {};
        var focusPending = null;

        // OBRYS NALEŻY DO MGŁY, NIE DO POLA ODKRYTEGO (2026-08-14).
        //
        // Kolejność decyzji, bo bez niej ten kod wygląda na chwiejny:
        //   1. Najpierw obrysu nie było wcale (§24: „obrys zrobi plaster miodu").
        //   2. User poprosił o LEKKI border — dostały go pola odkryte, na biało,
        //      żeby dało się policzyć sąsiadujące pola tego samego stopnia.
        //   3. User: „odkrycia powinny ujawniać trasę, czyli być przezroczyste,
        //      a mgła powinna mieć obramowanie". Pole odkryte nie ma już
        //      wypełnienia, więc nie ma czego rozdzielać — obrys przechodzi na
        //      MGŁĘ i staje się granicą między odkrytym a nieodkrytym. To jest
        //      linia, która niesie treść: pokazuje kształt własnego zasięgu.
        //
        // Kolor: ciemniejszy odcień mgły, nie biel. Biel działała na polu
        // ZAMALOWANYM (czytała się jak fuga między kaflami), ale na jasnej
        // szarości mgły po prostu znika — zmierzone na wygenerowanym kaflu:
        // obrys był nieodróżnialny od antyaliasingu krawędzi.
        //
        // Obawa z §24 nadal nie występuje, bo chroni przed nią DRABINKA POZIOMÓW
        // z api/routes.php: co dwa poziomy zoomu pole rośnie czterokrotnie, więc
        // na ekranie ma stale 34,5–69 px (policzone dla 52°N w progach 4/6/8/10/12).
        // 0.6 px to najwyżej 1,7% szerokości pola i ten udział NIE rośnie przy
        // oddalaniu.
        //
        // Mapa kroniki załatwia to samo inaczej (obrys dopiero od zoomu 12), bo
        // rysuje stały poziom RES_CELL w kadrze dopasowanym do całego przejazdu
        // — tam pole potrafi mieć 3 px. Powód opisany w chronicle.php.
        function hexPolygon(rings, step, outline) {
            return L.polygon(rings, {
                pane: 'discoveryHex',
                color: step.outline || UNDISCOVERED.outline,
                fillColor: step.color,
                weight: outline ? 0.6 : 0,
                stroke: !!outline,
                opacity: outline ? 0.85 : 0,
                fillOpacity: step.opacity,
                interactive: false,
            });
        }

        function draw(data) {
            lastData = data;
            layer.clearLayers();

            // Warstwa odkryć zgaszona — nie rysujemy ani mgły, ani pól.
            // Zostaje goła mapa (i ewentualnie trasy), co jest sensownym
            // stanem „chcę zobaczyć teren bez naszej nakładki".
            if (!layers.cells) {
                if (emptyEl) { emptyEl.hidden = true; }
                if (legendEl) { legendEl.innerHTML = ''; }
                return;
            }

            var cells = data.cells || [];
            // Natężenie to LICZBA PRZEJAZDÓW (`p`), nie liczba odkrywców.
            // Na wspólnej mapie mówi „ilu ludzi tędy jeździ", na własnej „jak
            // często jeżdżę tam ja" — w obu razach to samo pytanie o
            // popularność miejsca, więc jedna skala je obsługuje.
            // Bez heatmapy skala ma JEDEN stopień: każde odkryte pole wygląda
            // tak samo. To jest właśnie „same białe plamy odkrytych elementów"
            // — informacja binarna (byłem / nie byłem), bez gradientu
            // popularności, który przy zgaszonej warstwie nie ma prawa nic mówić.
            var max = layers.heat ? (data.maxP || 1) : 1;
            var scale = layers.heat ? scaleFor(max) : [SCALE[0]];

            if (emptyEl) { emptyEl.hidden = cells.length > 0; }
            renderLegend(legendEl, scale, scope, data.res, max);

            // 1. NIEODKRYTE — cały świat minus dziury tam, gdzie ktoś już był.
            //    Jeden wielokąt z dziurami zamiast tysięcy szarych heksagonów:
            //    wygląda tak samo, a kosztuje jedną warstwę. Rysowany ZAWSZE,
            //    także gdy odkryć jeszcze nie ma — pusta mapa ma wyglądać na
            //    nieodkrytą, a nie na zepsutą.
            var holes = [];
            var buckets = [];
            var bounds = [];
            // Odkryte pola w OSIACH — siatka mgły niżej ma je pominąć, żeby
            // obrysy nie pojawiły się nad terenem, który odkrycie miało odsłonić.
            var odkryte = {};
            for (var b = 0; b < scale.length; b++) { buckets.push([]); }

            cells.forEach(function (cell) {
                var ring = hexagon(cell.a, cell.o, data.sizeM);
                holes.push(ring);
                buckets[levelOf(cell.p, max, scale.length)].push([ring]);
                bounds.push([cell.a, cell.o]);
                odkryte[axialKey(cell.a, cell.o, data.sizeM)] = true;
            });

            // MGŁA DOSTAJE OBRYS — i to jeden wielokąt załatwia obrysowanie
            // WSZYSTKICH dziur naraz, bo każde odkryte pole jest jej dziurą.
            // Ramka wokół WORLD_RING też się narysuje, ale leży na ±85°/±179,9°,
            // czyli poza każdym realnym kadrem tej mapy.
            hexPolygon([WORLD_RING].concat(holes), UNDISCOVERED, true).addTo(layer);

            // 1b. SIATKA W MGLE (2026-08-30) — obrysy pól, których jeszcze nikt
            //     nie zdjął. Bez nich mgła jest jednolitą plamą i nie widać
            //     w niej NICZEGO, co dałoby się zaplanować: prośba usera brzmiała
            //     „żeby user wiedział, jakie hexy ma koło siebie i gdzie
            //     powinien skręcić". Rysowana PO mgle (leży na niej) i PRZED
            //     odkrytymi stopniami skali, żeby heatmapa została na wierzchu.
            //
            //     Tylko w kadrze, nie na całym świecie jak mgła: mgła jest
            //     jednym wielokątem niezależnym od oddalenia, a tu każde pole
            //     to osobny pierścień — „cały świat" byłby milionami pierścieni.
            //     Konsekwencja: przy szybkim przesuwaniu siatka na chwilę
            //     kończy się na brzegu poprzedniego kadru. To jest widoczne
            //     dopiero, gdy się tego szuka, i tańsze niż jakakolwiek
            //     alternatywa.
            var siatka = hexLattice(map, data.sizeM, odkryte);
            if (siatka.length) {
                L.polyline(siatka, {
                    pane: 'discoveryHex',
                    color: GRID.color,
                    weight: GRID.weight,
                    opacity: GRID.opacity,
                    interactive: false,
                }).addTo(layer);
            }

            // 2. ODKRYTE — po jednym wielokącie złożonym na stopień skali.
            //    Nie nakładają się na warstwę z punktu 1 (tam są dziury), więc
            //    każde pole ma dokładnie jeden kolor, bez sumowania krycia.
            buckets.forEach(function (rings, i) {
                // Stopień o kryciu 0 („odkryte") nie ma czego rysować — dziura
                // w mgle JEST już całą jego treścią. Pominięcie go oszczędza
                // warstwę Leafletu na najliczniejszym stopniu skali.
                if (!rings.length || !scale[i].opacity) { return; }
                hexPolygon(rings, scale[i], false).addTo(layer);
            });

            // Pierwsze wczytanie ustawia kadr na to, co user faktycznie ma.
            // Zapas jest DUŻY (0.6), bo przy mgle sens kadru robi to, co
            // dookoła: odkryty skrawek ciasno przycięty do granic wyglądałby
            // jak cała mapa, zamiast jak początek. Przy mapie podanej z
            // zewnątrz nie ruszamy kadru — tam decydują ślady GPX.
            if (!fitted && !attached && bounds.length && options.fitToCells !== false) {
                fitted = true;
                map.fitBounds(L.latLngBounds(bounds).pad(0.6));
            }
        }

        /**
         * Kadr startowy z serwera — nakładany DOPIERO, gdy kontener ma
         * sensowny rozmiar.
         *
         * Rozmiar jest tu warunkiem, a nie szczegółem: `fitBounds` na
         * kontenerze wysokim na kilka pikseli nie potrafi zmieścić prostokąta
         * przy żadnym rozsądnym oddaleniu, więc Leaflet zwraca oddalenie
         * MAKSYMALNE. Zmierzone na świeżo otwartej karcie: kadr o zerowej
         * wysokości (`north === south`) i zapytania przy zoomie 18 zamiast 6 —
         * czyli dokładnie ten sam objaw, przed którym broni bramka w refresh(),
         * tylko zamrożony na stałe, bo kadr nakładaliśmy raz.
         *
         * Stąd próg 40 px i powtarzanie próby z ResizeObserver aż do skutku.
         */
        var startFitDone = false;
        function applyStartBounds() {
            if (startFitDone || !startBounds || attached) { return; }
            var size = map.getSize();
            if (size.x < 40 || size.y < 40) { return; }
            startFitDone = true;
            // `animate: false` NIE JEST kosmetyką. Bez tego Leaflet traktuje
            // dojście do kadru jak przesunięcie mapy i ANIMUJE je — a to
            // znaczy dwie rzeczy naraz: użytkownik widzi start w środku kraju
            // i dopiero przelot do właściwego miejsca (czyli dokładnie ten
            // „skok", dla którego kadr z serwera powstał), a sama animacja
            // wisi na requestAnimationFrame, który w karcie otwartej w tle
            // nie odpala — zmierzone: mapa zostawała wtedy na widoku domyślnym
            // na stałe, mimo poprawnie wywołanego fitBounds. Kadr startowy ma
            // być USTAWIONY, nie dojechany.
            map.fitBounds(startBounds, { padding: [18, 18], animate: false });
        }

        function refresh() {
            // NIE PYTAMY, ZANIM MAPA STANIE NA WŁAŚCIWYM KADRZE. Strona podała
            // prostokąt, więc żądanie dla widoku domyślnego (środek kraju)
            // byłoby pytaniem o obszar, którego nikt nie ogląda — a to jest
            // dokładnie ta usterka, dla której `bounds` powstało.
            if (startBounds && !startFitDone) {
                if (!attached) { map.invalidateSize(); }
                applyStartBounds();
                if (!startFitDone) {
                    scheduleRefresh();
                    return;
                }
            }

            // BRAMKA NA ZEROWY KADR — najważniejsza linia obrony w tym pliku.
            //
            // Dopóki kontener mapy nie ma rozmiaru, getBounds() zwraca
            // prostokąt o zerowej szerokości (east === west). Serwer słusznie
            // odpowiada wtedy zerem pól, więc mapa zostaje w całości zamglona —
            // objaw jest mylący, bo kafle rysują się normalnie i wszystko
            // wygląda na sprawne.
            //
            // Zdarza się to realnie: w karcie otwartej W TLE przeglądarka
            // odkłada pierwsze przeliczenie układu, więc nawet invalidateSize()
            // wywołane z setTimeout(0) potrafi zastać kontener 0 px szerokości
            // (zmierzone — document.visibilityState === 'hidden'). Zamiast
            // zgadywać opóźnienie, po prostu NIE PYTAMY o pusty prostokąt i
            // próbujemy ponownie.
            if (map.getSize().x < 1 || map.getSize().y < 1) {
                if (!attached) { map.invalidateSize(); }
                scheduleRefresh();
                return;
            }

            var b = map.getBounds();
            var url = endpoint
                + '?scope=' + encodeURIComponent(scope)
                + riderQuery
                + extraQuery
                + '&zoom=' + map.getZoom()
                + '&north=' + b.getNorth() + '&south=' + b.getSouth()
                + '&east=' + b.getEast() + '&west=' + b.getWest();

            if (pending) { pending.abort(); }
            pending = new AbortController();
            // POBRANIE I RYSOWANIE MIERZONE OSOBNO — bo to dwie różne przyczyny
            // tego samego „czekam". Zmierzone w dev: żądanie ok. 80 ms, więc gdy
            // pola pojawiają się z opóźnieniem, winne jest RYSOWANIE tysięcy
            // wielokątów albo kafle obok, a nie serwer.
            if (busy) { busy.start('pola', __('pola odkryć')); }
            // TOŻSAMOŚĆ ŻĄDANIA — pomiar ma opisywać CYKL, nie każdą próbę.
            // Szybkie przesunięcie przerywa poprzednie żądanie i zaczyna nowe
            // pod tym samym kluczem; bez tego porównania przerwane zamykało
            // pomiar tego, które właśnie leci, i w dzienniku zostawało
            // „bez odpowiedzi" zamiast prawdziwego czasu.
            var mojePola = pending;
            fetch(url, { signal: pending.signal })
                .then(function (r) { return r.ok ? r.json() : null; })
                // catch PRZED draw(), nie po: gdyby stał na końcu łańcucha,
                // połykałby też błędy rysowania i mapa myliłaby się w ciszy.
                .catch(function () { return null; /* przerwane żądanie przy szybkim przesuwaniu */ })
                .then(function (data) {
                    var moje = !!busy && pending === mojePola;
                    // Rysowanie ZGŁASZA SIĘ, ZANIM skończy się pobieranie —
                    // inaczej monitor widziałby chwilę zera zadań i zamykał
                    // cykl w środku, tuż przed najcięższą częścią.
                    if (moje && data) { busy.start('rysowanie', __('rysowanie pól')); }
                    if (moje) {
                        busy.koniec('pola', data ? (data.cells || []).length + __(' pól') : 'bez odpowiedzi');
                    }
                    if (!data) { return; }
                    draw(data);
                    if (moje) { busy.koniec('rysowanie', (data.cells || []).length + __(' pól')); }
                });
        }

        // WARSTWA ZNANYCH TRAS — KAFLE, nie wektor (2026-08-20).
        //
        // Do tej daty szlaki rysowały się wektorowo z /api/discovery/trails,
        // czyli z geometrii sklejonej ze ŚRODKÓW PÓL siatki odkryć. Pole ma
        // ok. 500 m, więc taka linia była ZYGZAKIEM obok drogi, a nie
        // przebiegiem szlaku — a ludzie przychodzą tu zobaczyć, którędy trasa
        // faktycznie idzie. Kafel (`TileRenderer` + `GpxGeometry`, klucz `kr`)
        // rysuje prawdziwy ślad z pliku GPX i przy okazji nie wysyła do
        // przeglądarki ani jednego punktu geometrii.
        //
        // CENA, ŚWIADOMIE ZAPŁACONA: kafel to obrazek, więc szlak przestał być
        // klikalny — zniknął dymek z nazwą, dystansem i wejściem na stronę
        // trasy. Przywrócenie go wymaga osobnej warstwy trafień, a nie powrotu
        // do wektora.
        // ŹRÓDŁA KAFLI PRZYCHODZĄ JEDNĄ MAPĄ `sources` (Etap 1, 2026-08-26),
        // kluczowaną NAZWĄ WARSTWY — dawniej każda warstwa miała własną opcję
        // (`trailsTiles`, `sladyTiles`), więc dołożenie kolejnej znaczyło
        // dopisanie opcji tutaj i pamiętanie o niej na każdej stronie.
        //
        // Szablon URL nadal buduje SERWER i to się nie zmieni: niesie epokę
        // `?v=`, która unieważnia kafle po zmianie treści, a przeglądarka nie
        // ma jak jej znać. Kontekst decyduje, KTÓRY klucz kafla strona poda —
        // np. `slady` to `all` na mapie społeczności i `u-{slug}` na profilu.
        var sources = options.sources || {};

        // JEDNA MAPA `tileLayers` DLA WSZYSTKICH WARSTW KAFLOWYCH (Etap 2,
        // 2026-08-26), zamiast osobnej zmiennej + osobnego bloku na każdą
        // (`trailTiles`, `sladTiles`) — dołożenie trzeciej warstwy kaflowej
        // w słowniku (`meta.kind: 'tiles'`) nie wymaga już zmiany tutaj.
        //
        // KOLEJNOŚĆ JEST STAŁA, NIE Z KLUCZY OBIEKTU `sources`: trasy pod
        // spodem, ślady na wierzchu (ta sama warstwa Leafletu, więc o z-index
        // decyduje kolejność DODANIA). Strony podają `sources` w różnej
        // kolejności właściwości (dyktowanej wygodą PHP), a kolejność kluczy
        // obiektu JS nie może o tym decydować — inaczej ten sam widok
        // wyglądałby różnie zależnie od tego, w jakiej kolejności kontroler
        // złożył tablicę.
        //
        // „ŚLADY" (2026-08-25) — przejechane trasy kaflami. Odróżnia się od
        // „Znanych tras": tamto są celami (oficjalne szlaki, każdy swoim
        // kolorem), to jest śladem po tym, jak ludzie faktycznie jeżdżą.
        // BEZ PRZEJAZDÓW SOLO (2026-08-26): plik solo jest surowy, więc
        // zaczyna się pod czyimś domem — na publicznej warstwie byłby mapą
        // adresów (§27, powód przy kluczu `all` w Models\TileSource).
        var tileLayers = {};
        // `trailsDone`/`trailsRemaining` (2026-08-29): na mapie osobistej/
        // profilu „Znane trasy" nie jest już samo jedną warstwą kafli, tylko
        // rodzicem dwóch (ukończone/nieukończone, patrz Models\MapLayer —
        // `trails` samo nie niesie już `source` w tych kontekstach). Na mapie
        // społeczności `sources.trailsDone`/`.trailsRemaining` po prostu nie
        // istnieją (dzieci nie mają tam klucza kontekstu), więc pętla niżej
        // pomija je tak samo jak zawsze pomijała nieużywane klucze.
        ['trails', 'trailsDone', 'trailsRemaining', 'slady'].forEach(function (key) {
            if (!sources[key]) { return; }
            tileLayers[key] = ridemoreAddTileLayer(map, sources[key], { kind: 'tracks' });
            if (!layers[key]) { map.removeLayer(tileLayers[key]); }
        });

        // KLIK W MAPĘ — JEDEN DYMEK NA KLIKNIĘCIE, choć pytań bywa dwoje
        // (2026-09-11, zgłoszenie usera: „tam, gdzie po jednym śladzie idzie
        // ślad solo i znana trasa, pokazują się tylko solo").
        //
        // CO BYŁO ŹLE: do tej daty stały tu DWA niezależne `map.on('click')` —
        // jeden pytał o szlaki, drugi o przejazdy — i każdy kończył swoją
        // odpowiedź `L.popup(...).openOn(map)`. `openOn` w Leaflecie ZAMYKA
        // dymek, który akurat jest otwarty. Przy dwóch zapalonych warstwach
        // oba żądania leciały równolegle i ta odpowiedź, która przyszła druga,
        // kasowała pierwszą. Szlaki zwykle wracają szybciej (serwer liczy
        // trafienie na gotowych polach trasy), więc widać było wyłącznie solo —
        // dane o szlaku przychodziły poprawne i szły do kosza w przeglądarce.
        //
        // TERAZ: jedno kliknięcie zbiera odpowiedzi z obu źródeł
        // (`Promise.all`) i składa JEDEN dymek. Kolejność w środku jest stała —
        // najpierw szlaki, potem przejazdy — bo to ta sama kolejność, w jakiej
        // te warstwy leżą na mapie (trasy pod spodem, ślady na wierzchu) i nie
        // może zależeć od tego, które żądanie akurat wróciło pierwsze.
        //
        // PYTANIE DO SERWERA, NIE WARSTWA TRAFIEŃ W PRZEGLĄDARCE (2026-08-20,
        // po zgłoszeniu usera, że kliknięcie zniknęło razem z wektorem).
        // Wektorowa warstwa trafień cofnęłaby dokładnie to, po co weszły kafle:
        // geometria znowu jechałaby do przeglądarki przy każdym przesunięciu
        // mapy — i to ta ze środków pól, czyli przesunięta względem narysowanej
        // linii, więc klik w widoczny szlak potrafiłby chybić. Tutaj lecą małe
        // żądania NA KLIKNIĘCIE, a trafienie liczy serwer.
        //
        // JEDEN `AbortController` NA KLIKNIĘCIE, nie po jednym na warstwę:
        // kolejne kliknięcie unieważnia CAŁĄ poprzednią parę pytań, a nie
        // połowę. (Wcześniej kontrolery musiały być dwa właśnie dlatego, że
        // handlery były dwa i wspólna zmienna kasowałaby żądanie sąsiada.)
        var hitPending = null;
        if (options.trailsHitEndpoint || options.ridesHitEndpoint) {
            map.on('click', function (e) {
                // Pytamy WYŁĄCZNIE o warstwy, które widać. Zgaszona warstwa nie
                // może odpowiadać dymkiem — to byłoby mówienie o czymś, czego
                // na mapie nie ma.
                var pytamOSzlaki = !!options.trailsHitEndpoint && !!layers.trails;
                var pytamOSlady = !!options.ridesHitEndpoint && !!layers.slady;
                if (!pytamOSzlaki && !pytamOSlady) { return; }

                if (hitPending) { hitPending.abort(); }
                hitPending = new AbortController();
                var sygnal = hitPending.signal;

                var wspolrzedne = 'lat=' + e.latlng.lat + '&lon=' + e.latlng.lng
                    + '&zoom=' + map.getZoom();

                // Pudło jednej warstwy nie może przewrócić drugiej — stąd
                // `catch` na każdym żądaniu z osobna, a nie jeden na całości.
                var pobierz = function (adres) {
                    return fetch(adres, { signal: sygnal })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .catch(function () { return null; });
                };

                var zapytania = [
                    // Separator, nie sztywne `?` (2026-09-10): strona jednej
                    // trasy podaje endpoint z gotowym `?route={id}`, bo
                    // zawężenie klika do TEJ trasy należy do adresu, który
                    // składa kontroler — tak jak każdy inny adres API tutaj.
                    pytamOSzlaki
                        ? pobierz(options.trailsHitEndpoint
                            + (options.trailsHitEndpoint.indexOf('?') === -1 ? '?' : '&')
                            + wspolrzedne)
                        : Promise.resolve(null),
                    // `rider` (2026-09-10) — TEN SAM parametr i TA SAMA zasada
                    // co przy `treasuresEndpoint`: na profilu rowerzysty serwer
                    // ma wiedzieć, CZYICH solo szukać (przycięta geometria,
                    // obcy dostaje to samo co właściciel — patrz komentarz przy
                    // `ridesAt` w RiderController). Na /odkrycia `options.slug`
                    // nie istnieje, więc zachowanie zostaje takie jak było
                    // (własne, pełne ślady z sesji).
                    pytamOSlady
                        ? pobierz(options.ridesHitEndpoint + '?' + wspolrzedne
                            + (options.slug ? '&rider=' + encodeURIComponent(options.slug) : ''))
                        : Promise.resolve(null)
                ];

                Promise.all(zapytania).then(function (odpowiedzi) {
                    // Kliknięcie już nieaktualne (poszło następne) — nic nie
                    // otwieramy, bo nowsze żądanie i tak jest w drodze.
                    if (sygnal.aborted) { return; }

                    var trasy = (odpowiedzi[0] && odpowiedzi[0].trails) || [];
                    var przejazdy = (odpowiedzi[1] && odpowiedzi[1].rides) || [];
                    // Pudło zostawia mapę w spokoju — dymek „nic tu nie ma"
                    // byłby karą za kliknięcie w tło.
                    if (!trasy.length && !przejazdy.length) { return; }

                    var box = L.DomUtil.create('div', 'map-pop');
                    if (trasy.length) {
                        dymekTras(trasy, box);
                    }
                    if (przejazdy.length) {
                        // Kreska rozdziela dwa ŹRÓDŁA tak samo, jak rozdziela
                        // dwa ślady w środku jednego źródła — czytelnik ma
                        // widzieć, że to osobne rzeczy, a nie ciąg dalszy.
                        if (trasy.length) { L.DomUtil.create('hr', 'map-pop__sep', box); }
                        dymekPrzejazdow(przejazdy, box);
                    }

                    L.popup({ closeButton: true })
                        .setLatLng(e.latlng)
                        .setContent(box)
                        .openOn(map);
                });
            });
        }

        /**
         * Dymek przejazdu (albo kilku, gdy ślady leżą na sobie w miejscu kliknięcia).
         *
         * MÓWI TO SAMO, CO WIERSZ W ZAKŁADCE „Przejazdy solo" w „Moich
         * przejazdach" — ta sama kolejność liczb i te same podpisy. Dwa ekrany
         * opisujące ten sam byt dwoma różnymi zestawami liczb czytają się jak
         * dwa różne byty; ta sama zasada, którą kieruje się dymek szlaku wobec
         * karty trasy.
         *
         * Budowany element po elemencie (`textContent`), nie sklejany z HTML-a —
         * nazwa przejazdu przychodzi z licznika (Garmin/Polar), czyli spoza
         * serwisu, i nigdy nie trafia do DOM-u jako znaczniki. Ta sama zasada
         * co przy dymku szlaku i skarbu.
         */
        function dymekPrzejazdow(przejazdy, box) {
            // SEKCJA, NIE CAŁY DYMEK (2026-09-11): od scalenia kliknięcia
            // w jeden dymek obie funkcje dopisują się do WSPÓLNEGO `.map-pop`,
            // zamiast tworzyć własny. Bez tego w jednym kliknięciu powstawały
            // dwa dymki i drugi zamykał pierwszy (patrz komentarz przy
            // `hitPending` wyżej).
            przejazdy.forEach(function (p, i) {
                if (i > 0) { L.DomUtil.create('hr', 'map-pop__sep', box); }

                // KROPKA W KOLORZE LINII przed nazwą — od migracji 073 każdy
                // ślad ma własny kolor, więc przy dwóch przejazdach w jednym
                // dymku to jedyna rzecz, która mówi, który jest który.
                var naglowek = L.DomUtil.create('b', 'map-pop__trail', box);
                if (p.color) {
                    L.DomUtil.create('i', 'trail-dot', naglowek).style.background = p.color;
                }
                L.DomUtil.create('span', '', naglowek).textContent = p.name;

                var meta = [];
                if (p.dateLabel) { meta.push(p.dateLabel); }
                if (p.distanceLabel) { meta.push(p.distanceLabel); }
                // Przewyższenie NIEZNANE po prostu się nie pokazuje — „0 m"
                // byłoby kłamstwem, bo zero to poprawna wartość dla płaskiej trasy.
                if (p.elevation !== null && p.elevation !== undefined) {
                    meta.push(p.elevation + __(' m w górę'));
                }
                if (meta.length) {
                    L.DomUtil.create('span', 'disc-trail__meta', box).textContent = meta.join(' · ');
                }

                // CO TEN PRZEJAZD WNIÓSŁ — pola i punkty, czyli to, po co
                // w ogóle wgrywa się ślad. Bez tego dymek mówiłby tylko „to
                // był przejazd", co widać i bez klikania.
                var stopka = L.DomUtil.create('div', 'disc-trail__foot', box);
                L.DomUtil.create('span', '', stopka).textContent =
                    p.cellsNew + (p.cellsNew === 1 ? __(' nowe pole') : __(' nowych pól'));
                L.DomUtil.create('span', '', stopka).textContent = '+' + p.points + __(' pkt');

                if (p.url) {
                    var link = L.DomUtil.create('a', 'btn btn--sm', box);
                    link.href = p.url;
                    // JEDNA ETYKIETA OD 2026-09-03: adres prowadzi teraz na
                    // stronę TEGO przejazdu (`/przejazd/{id}`) niezależnie od
                    // tego, czy był solo, czy z wyjazdu — a stamtąd jest link
                    // do wydarzenia. „Zobacz wyjazd" obiecywałoby co innego.
                    link.textContent = __('Zobacz przejazd');
                }
            });
        }

        /**
         * Dymek szlaku (albo kilku, gdy krzyżują się w miejscu kliknięcia).
         *
         * MÓWI TO SAMO, CO KARTA TRASY w sekcji „Znane trasy" pod mapą
         * (uwaga usera 2026-08-20: dymek miał mniej niż lista). Ta sama
         * kolejność i te same klasy (`.disc-trail__meta`, `.disc-trail__foot`,
         * `.trail__bar`), bo to ten sam byt opisany w dwóch miejscach — dwa
         * różne zestawy liczb czytałyby się jak dwa różne szlaki.
         *
         * POSTĘP TYLKO DLA ZALOGOWANEGO (`pct === null` u gościa): „0%" dla
         * kogoś, kto nie ma konta, nie jest informacją o nim. Gość dostaje
         * w tym miejscu to samo zdanie co pod listą — zaproszenie.
         *
         * Budowany element po elemencie (`textContent`), nie sklejany z HTML-a —
         * nazwa trasy nigdy nie trafia do DOM-u jako znaczniki. Ta sama zasada
         * co przy dymku skarbu.
         */
        function dymekTras(trasy, box) {
            // SEKCJA, NIE CAŁY DYMEK — patrz ta sama uwaga przy
            // `dymekPrzejazdow` wyżej.
            trasy.forEach(function (t, i) {
                if (i > 0) { L.DomUtil.create('hr', 'map-pop__sep', box); }

                // KROPKA W KOLORZE LINII przed nazwą (2026-08-20). Od kiedy
                // każda trasa ma własny kolor, dymek jest jedynym miejscem,
                // gdzie „ta kreska na mapie" spotyka się ze swoją nazwą —
                // a przy dwóch szlakach w jednym dymku bez kropki nadal nie
                // wiadomo, który jest który.
                var naglowek = L.DomUtil.create('b', 'map-pop__trail', box);
                if (t.color) {
                    L.DomUtil.create('i', 'trail-dot', naglowek).style.background = t.color;
                }
                L.DomUtil.create('span', '', naglowek).textContent = t.name;

                // Dystans · region · przewyższenie. Przewyższenie NIEZNANE
                // (trasa sprzed migr. 063) po prostu się nie pokazuje — „0 m"
                // byłoby kłamstwem, bo zero to poprawna wartość dla płaskiej.
                var meta = [];
                if (t.distanceLabel) { meta.push(t.distanceLabel); }
                if (t.region) { meta.push(t.region); }
                if (t.elevation !== null && t.elevation !== undefined) {
                    meta.push(t.elevation + __(' m przewyższenia'));
                }
                if (meta.length) {
                    L.DomUtil.create('span', 'disc-trail__meta', box).textContent = meta.join(' · ');
                }

                if (t.pct === null || t.pct === undefined) {
                    L.DomUtil.create('span', '', box).textContent =
                        __('Załóż konto, żeby śledzić postęp.');
                } else {
                    var stopka = L.DomUtil.create('div', 'disc-trail__foot', box);
                    var lewa = L.DomUtil.create('span', t.isComplete ? 'is-done' : '', stopka);
                    lewa.textContent = t.isComplete ? __('UKOŃCZONA') : t.pct + '%';
                    L.DomUtil.create('span', '', stopka).textContent =
                        t.matched + ' / ' + t.cellsTotal + __(' pól');

                    var pasek = L.DomUtil.create('div', 'trail__bar', box);
                    L.DomUtil.create('i', '', pasek).style.width = Math.min(100, t.pct) + '%';

                    L.DomUtil.create('span', '', box).textContent =
                        __('Zalicza się sama, gdy Twoje przejazdy pokryją kolejne fragmenty.');
                }

                if (t.url) {
                    var link = L.DomUtil.create('a', 'btn btn--sm', box);
                    link.href = t.url;
                    link.textContent = __('Zobacz trasę');
                }
            });
        }

        // WARSTWA SKARBÓW — 💎, nie ikonka QR. Użytkownik ma widzieć „jest tu
        // coś do znalezienia"; kod QR pojawia się dopiero fizycznie, na miejscu.
        // Pokazanie go na mapie zdradzałoby mechanikę zanim zacznie się zabawa.
        // Dymek z jedna akcja: zaliczyc albo potwierdzic. Budowany w JS, a nie
        // wklejany jako HTML z serwera, bo tresc zalezy od stanu, ktory zna
        // tylko ta warstwa (czy zgloszony, czy juz mam).
        // KSZTALTY PINEZEK (Lucide, ISC). Wczesniej byly tu emoji 💎 ✓ ? ! —
        // ten sam blad, ktory siedzial w kategoriach skarbow: emoji renderuje
        // sie inaczej na kazdym systemie, a na pinezce o boku 28 px roznica
        // miedzy platformami byla widoczna golym okiem. SVG dziedziczy
        // currentColor, wiec kolor pinezki ustawia CSS, a nie krój pisma.
        var ZNAKI = {
            skarb: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.5 3 8 9l4 13 4-13-2.5-6"/><path d="M17 3a2 2 0 0 1 1.6.8l3 4a2 2 0 0 1 .013 2.382l-7.99 10.986a2 2 0 0 1-3.247 0l-7.99-10.986A2 2 0 0 1 2.4 7.8l2.998-3.997A2 2 0 0 1 7 3z"/><path d="M2 9h20"/></svg>',
            mam:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            ukryty:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
            zgloszony: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>',
            // IKONY LICZB W DYMKU (2026-08-22, zgłoszenie usera: „za dużo treści
            // a nic nie wnoszą, jak już to jakiś tooltip do ikonek"). Ten sam
            // zestaw i ta sama licencja co pinezki wyżej — nie zaczynamy
            // drugiego zbioru ikon w tym pliku.
            punkty:  '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
            ludzie:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            promien: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2"/></svg>'
        };

        // NAZWY RZADKOŚCI — jedyne miejsce w JS, które tłumaczy ENUM z bazy.
        var RZADKOSC = {
            COMMON: __('zwykły'), RARE: __('rzadki'), EPIC: __('epicki'), LEGENDARY: __('legendarny')
        };

        // KLASA RZADKOŚCI NA PINEZCE/CHIPIE — CSS niżej różnicuje grubość
        // obwódki (i, przy legendarnym, dorzuca złoty rant), a dymek dostaje
        // tym samym kolorowy chip zamiast płaskiego słowa w linii meta
        // (zgłoszenie usera 2026-08-27: „skarby legendarne i zwykłe wyglądają
        // dziś tak samo"). Jedna klasa robi oba te zadania naraz.
        function klasaRzadkosci(rarity) {
            return rarity ? 'is-rarity-' + rarity.toLowerCase() : '';
        }

        // IKONY KATEGORII SKARBÓW NA PINEZCE (zgłoszenie usera: „inne ikony
        // w markerze"). Te same kształty co `Utils\Icon::ICONS['tre-*']` po
        // stronie PHP (Lucide, ISC) — skopiowane tutaj, bo panel skarbów w
        // przeglądarce nie ma jak sięgnąć do tamtego pliku, a to jedyny język
        // ikon, jaki serwis ma. `dictionary_items.icon` nosi ten sam klucz.
        //
        // UŻYWANE TYLKO DLA WIDOCZNEGO, NIEZDOBYTEGO skarbu (patrz drawTreasures):
        // znaleziony/zgłoszony/ukryty mają własne znaki, bo mówią o STANIE
        // („masz", „w toku", „coś tu jest"), nie o tym, CO to za miejsce —
        // mieszanie tych dwóch komunikatów w jednej ikonie było źródłem
        // spłaszczenia, o które chodziło w zgłoszeniu.
        var KATEGORIE = {
            'tre-viewpoint': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 3 4 8 5-5 5 15H2L8 3z"/><path d="M4.14 15.08c2.62-1.57 5.24-1.43 7.86.42 2.74 1.94 5.49 2 8.23.19"/></svg>',
            'tre-pass':      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 3 4 8 5-5 5 15H2L8 3z"/></svg>',
            'tre-hut':       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="4" cy="4" r="2"/><path d="m14 5 3-3 3 3"/><path d="m14 10 3-3 3 3"/><path d="M17 14V2"/><path d="M17 14H7l-5 8h20Z"/><path d="M8 14v8"/><path d="m9 14 5 8"/></svg>',
            'tre-water':     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"/><path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"/></svg>',
            'tre-monument':  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 18v-7"/><path d="M11.119 2.205a2 2 0 0 1 1.762 0l7.84 3.846A.5.5 0 0 1 20.5 7h-17a.5.5 0 0 1-.22-.949z"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M3 22h18"/><path d="M6 18v-7"/></svg>',
            'tre-service':   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z"/></svg>',
            'tre-curiosity': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/></svg>'
        };

        /**
         * CZY TA OSOBA MOŻE TAM STAĆ — czyli czy ma sens przycisk „Jestem tutaj".
         *
         * Zgłoszenie usera 2026-08-20: „skarby, które przeglądamy i klikamy na
         * komputerze, nie powinny mieć przycisku »jestem tutaj«, nie ma to
         * najmniejszego sensu". I tak jest: zaliczenie wymaga stania przy
         * skarbie, więc przy biurku kończyło się to zawsze komunikatem „nie ma tu
         * skarbu w zasięgu" — przycisk obiecywał operację, która z definicji
         * musiała się nie udać.
         *
         * `pointer: coarse` znaczy „podstawowym wskaźnikiem jest palec", czyli
         * telefon albo tablet — sprzęt, który realnie bywa w terenie. Laptop
         * z ekranem dotykowym, ale i myszą, raportuje `fine` i przycisku nie
         * dostaje. To nie jest wykrywanie systemu, tylko sposobu obsługi.
         */
        var wTerenie = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);

        /**
         * Dymek skarbu — na telefonie AKCJA, na komputerze OPIS.
         *
         * Do 2026-08-20 dymek miał nazwę, jedno zdanie i przycisk. Wszystko, co
         * skarb o sobie wie — opis, kategoria, rzadkość, region, zdjęcie, ilu
         * ludzi go już znalazło, w jakim promieniu się zalicza — leżało w bazie
         * i nie było pokazywane nigdzie (uwaga usera: „rozbudowałbym o okienko
         * z informacjami, które już są zawarte w skarbie").
         *
         * CZEGO TU NIE MA I NIE BĘDZIE: kodu z naklejki oraz tego, KTO go
         * znalazł. Pierwsze pozwalałoby zaliczyć skarb z fotela, drugie jest
         * cudzą historią (§27) — liczba owszem, nazwiska nie.
         */
        /**
         * DOCIĄGNIĘCIE GALERII (SKA/14) — jeden skarb, na żądanie.
         *
         * Idzie przez `/api/treasures/{id}`, czyli przez tę samą bramkę co
         * reszta danych o skarbie (Models\Treasure::reveal): jeśli oglądający
         * nie ma prawa widzieć zdjęć, endpoint odda pustą listę i nie ma
         * znaczenia, że przycisk dało się kliknąć.
         *
         * Zdjęcia wstawiamy jako `.ph-link`, czyli kafelki WSPÓLNEGO lightboksa
         * serwisu — skrypt podglądu słucha na dokumencie przez delegację, więc
         * działa też wewnątrz dymka Leafletu, mimo że ten powstaje długo po
         * załadowaniu strony.
         */
        function pokazGalerie(id, przycisk) {
            // Adres pojedynczego skarbu to adres kolekcji + `/{id}` — taka jest
            // trasa w api/routes.php. Dlatego NIE przeciskamy przez cztery
            // kontrolery i cztery widoki kolejnej opcji, ktora zawsze mialaby
            // te sama wartosc: `treasuresEndpoint` juz tu jest i niesie
            // base_path aplikacji.
            var adres = options.treasuresEndpoint;
            if (!adres) { return; }
            przycisk.disabled = true;
            przycisk.textContent = __('Wczytuję…');
            fetch(adres + '/' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (o) {
                    var zdjecia = (o && o.treasure && o.treasure.photos) || [];
                    if (!zdjecia.length) { przycisk.remove(); return; }
                    // DWA ADRESY NA ZDJĘCIE, oba złożone po stronie serwera
                    // (api/routes.php): `thumb` do kafelka, `full` do podglądu.
                    // Przeglądarka nie wie, gdzie stoi aplikacja ani jakie
                    // warianty leżą na dysku, więc nie ma prawa sklejać ani
                    // jednego, ani drugiego.
                    var siatka = L.DomUtil.create('div', 'tre-pop__gal-grid');
                    zdjecia.forEach(function (foto) {
                        var a = L.DomUtil.create('a', 'ph-link', siatka);
                        a.href = foto.full;
                        a.target = '_blank';
                        a.rel = 'noopener';
                        a.setAttribute('aria-label', __('Powiększ zdjęcie'));
                        a.style.backgroundImage = "url('" + foto.thumb + "')";
                    });
                    przycisk.replaceWith(siatka);
                })
                .catch(function () {
                    // Brak sieci — przycisk wraca do stanu wyjściowego zamiast
                    // zostać na „Wczytuję…" do końca życia dymka.
                    przycisk.disabled = false;
                    przycisk.textContent = __('Pokaż zdjęcia');
                });
        }

        function dymekSkarbu(t, znacznik) {
            var box = L.DomUtil.create('div', 'tre-pop');
            var zgloszony = t.reveal === 'proposed';
            var dokladny = t.reveal === 'exact';

            var naglowek = L.DomUtil.create('b', '', box);
            naglowek.textContent = t.name;

            // LINIA METRYCZKI: kategoria · region. Tylko to, co jest — puste
            // człony nie zostawiają wiszących kropek.
            // Region jest LINKIEM do swojej strony, gdy serwer podał adres
            // (`region_url`, 2026-09-14) — składany z węzłów DOM, nie z HTML-a,
            // bo nazwy przychodzą z bazy.
            if (t.category_label || t.region_label) {
                var metaEl = L.DomUtil.create('span', 'tre-pop__meta', box);
                if (t.category_label) { metaEl.appendChild(document.createTextNode(t.category_label)); }
                if (t.region_label) {
                    if (t.category_label) { metaEl.appendChild(document.createTextNode(' · ')); }
                    if (t.region_url) {
                        var regionA = document.createElement('a');
                        regionA.href = t.region_url;
                        regionA.textContent = t.region_label;
                        metaEl.appendChild(regionA);
                    } else {
                        metaEl.appendChild(document.createTextNode(t.region_label));
                    }
                }
            }

            // RZADKOŚĆ — osobny kolorowy chip, nie słowo w linii meta (zgłoszenie
            // usera: „nie widać różnicy między zwykłym a legendarnym"). Ten sam
            // kolor co obwódka pinezki i chip w Pulsie/profilu — jeden słownik
            // wizualny na cały serwis.
            if (t.rarity && RZADKOSC[t.rarity]) {
                L.DomUtil.create('span', 'rarity-chip ' + klasaRzadkosci(t.rarity), box)
                    .textContent = RZADKOSC[t.rarity];
            }

            // ZDJĘCIE, jeśli skarb je ma. Przy punktach ukrytych serwer i tak
            // przysyła null (patrz Models\Treasure::reveal) — tak jak nazwę
            // i opis.
            // ZDJĘCIE GŁÓWNE. `photo_url` przychodzi jako wariant `thumb`
            // z prawidłowym base_path (patrz api/routes.php) — do 2026-08-22 szedł
            // tu surowy adres z bazy, który na instalacji w podkatalogu dawał 404,
            // i oryginał 1200 px do kafelka wysokiego na 110 px.
            if (t.photo_url) {
                // Klikalne, jeśli serwer podał pełny plik — ten sam lightbox
                // co w galerii. Bez `photo_full` zostaje zwykły obrazek.
                var ramka = t.photo_full
                    ? L.DomUtil.create('a', 'ph-link tre-pop__imgw', box)
                    : box;
                if (t.photo_full) {
                    ramka.href = t.photo_full;
                    ramka.target = '_blank';
                    ramka.rel = 'noopener';
                    ramka.setAttribute('aria-label', __('Powiększ zdjęcie'));
                }
                var foto = L.DomUtil.create('img', 'tre-pop__img', ramka);
                foto.src = t.photo_url;
                foto.alt = '';
                foto.loading = 'lazy';
            }

            // GALERIA (SKA/14) — w dymku stoi TYLKO LICZBA, zdjęcia dociągają
            // się po kliknięciu. Mapa oddaje do 300 skarbów naraz; wsypanie
            // galerii każdego z nich do odpowiedzi znaczyłoby setki adresów,
            // z których nikt nie ogląda ani jednego.
            //
            // `photos_count` przychodzi jako null, a nie 0, dla punktów, których
            // oglądający nie ma prawa widzieć (Trop i Ukryty przed znalezieniem —
            // patrz Models\Treasure::reveal). Dlatego sprawdzamy > 0, a nie
            // „czy istnieje": null i 0 mają tu znaczyć to samo, czyli nic nie
            // pokazujemy.
            if (t.photos_count > 0) {
                var galeria = L.DomUtil.create('button', 'tre-pop__gal', box);
                galeria.type = 'button';
                galeria.textContent = t.photos_count + (t.photos_count === 1
                    ? __(' zdjęcie tego miejsca') : __(' zdjęcia tego miejsca'));
                L.DomEvent.on(galeria, 'click', function (ev) {
                    L.DomEvent.stop(ev);
                    pokazGalerie(t.id, galeria);
                });
            }

            if (t.description) {
                L.DomUtil.create('span', 'tre-pop__desc', box).textContent = t.description;
            }

            // Trop pokazujemy TYLKO wtedy, gdy skarb nie jest jeszcze odsłonięty
            // — przy dokładnej pinezce wskazówka „szukaj przy dębie" jest już
            // tylko szumem obok opisu.
            if (t.hint && !dokladny) {
                L.DomUtil.create('span', 'tre-pop__hint', box).textContent = 'Trop: ' + t.hint;
            }

            // LICZBY JAKO IKONY, NIE ZDANIE (2026-08-22).
            //
            // Zgłoszenie usera: „+50 pkt · nikt jeszcze go nie znalazł · zalicza
            // się w promieniu 150 m — nie lepiej to ikonkami zrobić? Za dużo
            // treści, a nic nie wnoszą. Jak już to jakiś tooltip do ikonek".
            // Racja: to były trzy liczby rozpisane na dziewięć słów, w dymku
            // szerokim na 240 px, czytane najczęściej z telefonu w terenie.
            // Teraz to trzy chipy; SŁOWA NIE ZNIKAJĄ, tylko schodzą do tooltipa.
            //
            // TOOLTIP DZIAŁA NA DOTYK, a nie tylko pod myszą: sam `title` jest
            // na telefonie martwy, a to jest ekran przede wszystkim terenowy.
            // Stąd `title` (hover przy biurku) ORAZ kliknięcie w chip, które
            // wypisuje pełne zdanie w linijce pod spodem. Ta sama informacja,
            // dwa sposoby dotarcia do niej.
            var stats = [];
            if (zgloszony) {
                stats.push({
                    ikona: 'zgloszony',
                    wartosc: t.confirmations + '/' + t.needed,
                    opis: __('Zgłoszony przez społeczność — {a} z {b} potwierdzeń', { a: t.confirmations, b: t.needed })
                });
            } else {
                stats.push({
                    ikona: 'punkty',
                    wartosc: '+' + (t.points || 0),
                    opis: __('Płaci {n} punktów Ridemore', { n: t.points || 0 })
                });
                if (t.finders !== null && t.finders !== undefined) {
                    // „Nikt jeszcze" zostaje ZAPROSZENIEM, nie brakiem danych —
                    // ta sama zasada co w panelu „Skarby, które czekają". Chip
                    // pokazuje zero, ale tooltip mówi, co z tego wynika.
                    stats.push({
                        ikona: 'ludzie',
                        wartosc: String(t.finders),
                        opis: t.finders > 0
                            ? (t.finders === 1 ? __('Znaleziony przez 1 osobę') : __('Znaleziony przez {n} riderów', { n: t.finders }))
                            : __('Nikt jeszcze go nie znalazł — możesz być pierwszy'),
                        wyroznij: t.finders === 0
                    });
                }
                if (t.claim_radius_m) {
                    stats.push({
                        ikona: 'promien',
                        wartosc: t.claim_radius_m + ' m',
                        opis: __('Zalicza się w promieniu {n} m od skarbu', { n: t.claim_radius_m })
                    });
                }
            }

            var pasek = L.DomUtil.create('div', 'tre-pop__stats', box);
            // Linijka na rozwinięcie chipa ORAZ na komunikaty z zaliczania
            // („Bez zgody na lokalizację się nie da"). Jedno miejsce na tekst
            // stanu, zamiast nadpisywania paska ikon — inaczej pierwszy
            // komunikat kasowałby liczby i już by nie wróciły.
            var opis = L.DomUtil.create('span', 'tre-pop__msg', box);

            stats.forEach(function (st) {
                var chip = L.DomUtil.create('button', 'tre-pop__stat' + (st.wyroznij ? ' is-first' : ''), pasek);
                chip.type = 'button';
                chip.title = st.opis;
                chip.setAttribute('aria-label', st.opis);
                chip.innerHTML = ZNAKI[st.ikona];
                L.DomUtil.create('span', '', chip).textContent = st.wartosc;
                L.DomEvent.on(chip, 'click', function (ev) {
                    L.DomEvent.stop(ev);
                    // Drugie kliknięcie w ten sam chip chowa opis — bez tego
                    // linijka zostaje na ekranie i nie ma jak jej zamknąć.
                    var toSamo = opis.textContent === st.opis;
                    opis.textContent = toSamo ? '' : st.opis;
                    [].forEach.call(pasek.children, function (c) { c.classList.remove('is-on'); });
                    if (!toSamo) { chip.classList.add('is-on'); }
                });
            });

            // Klikniecie w dymku NIE MOZE dolecic do mapy — inaczej Leaflet
            // potraktuje je jako klikniecie w podklad i zamknie dymek w trakcie
            // pobierania lokalizacji.
            L.DomEvent.disableClickPropagation(box);

            // PRZY BIURKU NIE MA PRZYCISKU, tylko zdanie, które tłumaczy, gdzie
            // on jest. Bez tego zdania brak przycisku wyglądałby jak usterka.
            // Gość dostaje zaproszenie zamiast akcji — zaliczyć i tak nie ma
            // czym się podpisać.
            if (!options.treasureActions) {
                L.DomUtil.create('span', 'tre-pop__note', box).textContent =
                    __('Załóż konto, żeby zaliczać skarby i zbierać za nie punkty.');
                return box;
            }
            if (!wTerenie) {
                L.DomUtil.create('span', 'tre-pop__note', box).textContent = zgloszony
                    ? __('Potwierdzisz go telefonem, stojąc na miejscu.')
                    : __('Zaliczysz go telefonem na miejscu — albo sam wskoczy po wgraniu śladu z przejazdu.');
                return box;
            }

            var przycisk = L.DomUtil.create('button', 'btn btn--sm', box);
            przycisk.type = 'button';
            przycisk.textContent = zgloszony ? __('Potwierdzam, że tu jest') : 'Jestem tutaj';

            przycisk.addEventListener('click', function () {
                przycisk.disabled = true;
                przycisk.textContent = __('Sprawdzam gdzie jesteś…');

                // POZYCJA PRZEZ MOST (assets/js/native.js): w aplikacji pyta
                // natywny plugin, w przeglądarce `navigator.geolocation`.
                // Tu, przy skarbie, różnica jest realna — natywny GPS daje
                // dokładność, od której zależy trafienie w promień zaliczenia.
                RM.native.position().then(function (poz) {
                    var dane = new FormData();
                    dane.append('csrf_token', options.csrf);
                    dane.append('lat', poz.lat);
                    dane.append('lon', poz.lon);
                    if (zgloszony) { dane.append('id', t.id); }

                    return fetch(zgloszony ? options.confirmEndpoint : options.claimEndpoint,
                          { method: 'POST', body: dane, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (o) {
                            if (o.error) {
                                opis.textContent = o.error;
                                przycisk.disabled = false;
                                przycisk.textContent = zgloszony ? __('Potwierdzam, że tu jest') : 'Jestem tutaj';
                                return;
                            }
                            if (zgloszony) {
                                opis.textContent = o.activated
                                    ? __('Punkt aktywny — od teraz płaci punktami.')
                                    : __('Zapisane: {a} z {b} potwierdzeń.', { a: o.count, b: o.needed });
                            } else {
                                opis.textContent = (o.claimed && o.claimed.length)
                                    ? 'Masz go. +' + o.points
                                    : __('Nie ma tu skarbu w zasięgu.');
                            }
                            przycisk.remove();
                            znacznik.closePopup();
                            refreshTreasures();
                        })
                        .catch(function () {
                            opis.textContent = __('Nie udało się połączyć.');
                            przycisk.disabled = false;
                        });
                }).catch(function (err) {
                    // Komunikat układa most — rozróżnia odmowę zgody od braku
                    // sygnału, czego dawne „Bez zgody na lokalizację się nie da"
                    // nie potrafiło (mówiło o zgodzie także wtedy, gdy zgoda była).
                    opis.textContent = err.message;
                    przycisk.disabled = false;
                });
            });

            return box;
        }

        // PĘCZKI SKARBÓW przy oddaleniu (uwaga usera 2026-08-15).
        //
        // Kółko z liczbą, nie ikona skarbu: pęczek NIE JEST skarbem i nie może
        // wyglądać jak on — inaczej ktoś pojedzie pod „skarb", którego tam nie
        // ma. Rozmiar rośnie z liczbą, żeby gęstość dało się ocenić wzrokiem
        // bez czytania cyfr.
        //
        // PĘCZEK ZACZYNA SIĘ OD DWÓCH (2026-08-20). Pole z jednym skarbem
        // przychodzi z serwera jako zwykły skarb i rysuje się pinezką — patrz
        // Models\Treasure::clustersInBounds. Warunek niżej jest asekuracją na
        // wypadek starej odpowiedzi z cache'u przeglądarki, nie drugą regułą.
        function drawClusters(items) {
            items.forEach(function (c) {
                if (c.count < 2) { return; }
                var duzy = c.count >= 100 ? ' is-xl' : (c.count >= 10 ? ' is-lg' : '');
                var bok = c.count >= 100 ? 46 : (c.count >= 10 ? 40 : 34);
                // Ile z tego pęczka oglądający już ma — bez tego kolekcjonujący
                // nie widzi przy oddaleniu żadnego postępu.
                var podpis = c.found > 0
                    ? __('{n} skarbów, masz {m}', { n: c.count, m: c.found })
                    : __('{n} skarbów do znalezienia', { n: c.count });

                L.marker([c.lat, c.lon], {
                    icon: L.divIcon({
                        className: 'tre-cluster' + duzy + (c.found >= c.count ? ' is-done' : ''),
                        html: '<i>' + c.count + '</i>',
                        iconSize: [bok, bok],
                        iconAnchor: [bok / 2, bok / 2]
                    })
                }).bindTooltip(podpis, { direction: 'top' })
                  .on('click', function () {
                      // KLIK PRZYBLIŻA DO ZASIĘGU PĘCZKA (decyzja usera).
                      // Skok o trzy poziomy, nie o jeden: pęczek powstaje
                      // z pola siatki, a jeden krok zoomu zwykle nie wystarcza,
                      // żeby się rozpadł — użytkownik klikałby w kółko kilka
                      // razy z rzędu, nie wiedząc, czy cokolwiek się dzieje.
                      map.setView([c.lat, c.lon], Math.min(map.getZoom() + 3, 16));
                  })
                  .addTo(treasureLayer);
            });
        }

        function drawTreasures(items) {
            items.forEach(function (t) {
                // Znaleziony wygląda inaczej niż nieznaleziony — bez tego mapa
                // nie pokazuje postępu, a kolekcjonowanie traci sens.
                var znaleziony = !!Number(t.mine);
                // POZIOMY UJAWNIENIA (SKA/7). Serwer przysyla juz przyciete dane
                // — tutaj tylko dobieramy ikonke i podpis. Dla ukrytych przychodzi
                // SRODEK POLA SIATKI, nie prawdziwe miejsce, wiec pinezka celowo
                // wyglada inaczej: pelna pinezka na przyblizonej pozycji
                // obiecywalaby dokladnosc, ktorej tam nie ma.
                var ukryty = t.reveal === 'hidden' || t.reveal === 'hint';
                var zgloszony = t.reveal === 'proposed';
                // Widoczny, niezdobyty skarb dostaje ikonę SWOJEJ kategorii,
                // jeśli słownik ją niesie (`dictionary_items.icon`) — w
                // przeciwnym razie zostaje ogólny diament. Pozostałe trzy stany
                // mają własne znaki znaczenia (patrz komentarz przy KATEGORIE).
                var ikona = znaleziony ? ZNAKI.mam
                          : (zgloszony ? ZNAKI.zgloszony
                          : (ukryty ? ZNAKI.ukryty
                          : (KATEGORIE[t.category_icon] || ZNAKI.skarb)));

                var podpis;
                if (zgloszony) {
                    // Zgloszony punkt nie jest jeszcze skarbem i podpis ma to
                    // mowic wprost — inaczej ktos pojedzie po punkty, ktorych
                    // tam (jeszcze) nie ma.
                    podpis = t.name + ' · ' + __('zgłoszony, czeka na potwierdzenie ({a}/{b})', { a: t.confirmations, b: t.needed });
                } else if (znaleziony) {
                    podpis = (t.category_label ? t.category_label + ' · ' : '') + t.name + ' · ' + __('masz');
                } else if (ukryty) {
                    // Nazwy nie ma czego pokazywac przy poziomie 0 — serwer
                    // przyslal juz „Cos tu jest". Dopisujemy skad ta niepewnosc,
                    // zeby nikt nie uznal przesunietej pinezki za blad mapy.
                    podpis = t.name + (t.hint ? ' · ' + t.hint : '')
                           + ' · ' + __('gdzieś w tym polu') + ' · +' + t.points;
                } else {
                    podpis = (t.category_label ? t.category_label + ' · ' : '') + t.name + ' · +' + t.points;
                }

                var znacznik = L.marker([t.lat, t.lon], {
                    icon: L.divIcon({
                        className: 'tre-pin' + (znaleziony ? ' is-found'
                                   : (zgloszony ? ' is-proposed' : (ukryty ? ' is-hidden' : '')))
                                   + ' ' + klasaRzadkosci(t.rarity),
                        html: '<i>' + ikona + '</i>',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15]
                    })
                }).bindTooltip(podpis, { direction: 'top' }).addTo(treasureLayer);
                treasureMarkers[t.id] = znacznik;

                // DYMEK Z AKCJA. Bez niego SKA/3 i SKA/6 istnialy w API, ale
                // uzytkownik nie mial jak ich wywolac — potwierdzenie punktu
                // wymaga wskazania KTOREGO, a jedynym miejscem, gdzie widac
                // konkretny punkt, jest ta pinezka.
                //
                // OD 2026-08-20 DYMEK DOSTAJE TAKŻE GOŚĆ. Odkąd niesie opis,
                // kategorię, zdjęcie i liczbę znalazców, jest przede wszystkim
                // INFORMACJĄ — a informacja o tym, co stoi w terenie, nie ma
                // powodu być za logowaniem (to ta sama zasada, co przy dymku
                // znanej trasy). Akcja zostaje przy tych, którzy mogą jej użyć:
                // przycisk dokłada się w `dymekSkarbu` tylko na telefonie
                // i tylko przy `treasureActions`.
                if (!znaleziony) {
                    znacznik.bindPopup(dymekSkarbu(t, znacznik));

                    // ZNIKAJĄCY DYMEK (zgłoszenie usera 2026-09-04): klik w pinezkę
                    // blisko krawędzi mapy każe Leafletowi dosunąć widok pod dymek
                    // (`autoPan`, domyślnie włączony) — a to jest zwykły `moveend`,
                    // ten sam, który niżej uruchamia `scheduleRefresh(true)`.
                    // Odświeżenie czyści WARSTWĘ (`treasureLayer.clearLayers()`)
                    // i rysuje ją od nowa, więc znacznik pod otwartym dymkiem
                    // znika — a Leaflet, usuwając znacznik z mapy, sam zamyka
                    // jego dymek (`remove: this.closePopup` w rdzeniu biblioteki).
                    // Efekt: dymek gaśnie bez udziału użytkownika, dokładnie po
                    // czasie, jaki zajmie ten jeden dodatkowy przelot do serwera —
                    // ułamek sekundy tutaj, realne „kilka sekund" w terenie na
                    // wolniejszym łączu.
                    //
                    // LEK JEST TEN SAM, CO PRZY LIŚCIE OBOK MAPY: `focusPending`
                    // każe otworzyć TEN SAM skarb na nowym znaczniku zaraz po
                    // przerysowaniu (patrz `map.ridemoreFocusTreasure` i komentarz
                    // przy niej). Tu włączamy go przy KAŻDYM otwarciu dymka, nie
                    // tylko z listy — żeby zwykły klik w pinezkę przeżył
                    // przypadkowe odświeżenie, które sam wywołał.
                    //
                    // TOOLTIP POD DYMKIEM (druga część tego samego zgłoszenia):
                    // znacznik ma i tooltip (`podpis`), i dymek — klik najpierw
                    // najeżdża (tooltip się pokazuje), dopiero potem otwiera
                    // dymek NA TYM SAMYM zaczepie (`direction:'top'` u obu), więc
                    // dolna krawędź dużego dymka nachodzi na tooltip tuż pod nim.
                    // Dymek mówi to samo i więcej, więc tooltip pod nim jest
                    // czystym szumem — chowamy go na czas otwarcia dymka zamiast
                    // zdejmować mu binding (na hover wraca normalnie po
                    // zamknięciu dymka).
                    znacznik.on('popupopen', function () {
                        focusPending = { id: t.id, until: Date.now() + 2500 };
                        znacznik.closeTooltip();
                    });
                }
            });
        }

        function refreshTreasures() {
            // DWIE WARSTWY, JEDEN ENDPOINT (2026-08-20). Zgaszenie obu znaczy
            // „nie pokazuj skarbów" i nie ma o co pytać serwera; zgaszenie
            // jednej idzie parametrem `stan`, bo filtrowanie MUSI stać się
            // przed grupowaniem w pęczki (patrz Models\Treasure::mineCondition).
            // Składa GENERYCZNA funkcja (Etap 2) — patrz `ridemoreComposeFilter`.
            var stan = ridemoreComposeFilter('treasures', layers, options.filters);
            if (stan === null || !options.treasuresEndpoint) { treasureLayer.clearLayers(); return; }

            // BRAMKA NA ZEROWY KADR — TA SAMA CO W refresh() WYŻEJ, i to nie
            // jest powtórzenie dla ozdoby (zgłoszenie usera 2026-08-23: „skarby
            // się na produkcji od razu nie ładują, trzeba rozzoomować").
            //
            // Do tej daty stało tu samo `return`, bez ponowienia i bez sprawdzenia
            // WYSOKOŚCI. Skutki były dwa i oba ciche: kontener bez rozmiaru
            // znaczył „nie pytaj i nigdy nie wracaj", a kontener o zerowej
            // WYSOKOŚCI przechodził dalej i wysyłał kadr, w którym north === south
            // — serwer słusznie odpowiadał pustą listą, warstwa czyściła się
            // do zera i tak zostawało. Pola odkryć w identycznej sytuacji
            // ponawiały próbę, więc mapa wyglądała na sprawną: mgła znikała,
            // kafle się rysowały, brakowało wyłącznie skarbów.
            if (map.getSize().x < 1 || map.getSize().y < 1) {
                if (!attached) { map.invalidateSize(); }
                scheduleRefresh();
                return;
            }

            var b = map.getBounds();
            var adresZadania = options.treasuresEndpoint
                + '?north=' + b.getNorth() + '&south=' + b.getSouth()
                + '&east=' + b.getEast() + '&west=' + b.getWest()
                + '&zoom=' + map.getZoom()
                // `scope` jedzie RAZEM ZE `stan` (Etap 3, warstwy-mapy.md) —
                // serwer musi wiedzieć, czyje znaleziska liczyć: na mapie
                // społeczności „odkryte" znaczy „przez KOGOKOLWIEK", na mojej
                // i na profilu — jak dotąd, przez PYTAJĄCEGO. Bez `stan`
                // scope nic by nie zmieniał, więc nie ma po co go wysyłać.
                + (stan ? '&stan=' + stan + '&scope=' + encodeURIComponent(scope) : '')
                + (options.slug ? '&rider=' + encodeURIComponent(options.slug) : '');

            // TO SAMO PYTANIE W LOCIE = NIE PYTAMY DRUGI RAZ.
            //
            // `scheduleRefresh` woła tę funkcję co 250 ms, a przerwanie żądania
            // było bezwarunkowe — więc na łączu, na którym odpowiedź idzie
            // dłużej niż te 250 ms (czyli na telefonie w terenie), kolejne
            // wywołanie ubijało poprzednie, zanim zdążyło wrócić, i skarby nie
            // pojawiały się NIGDY. Przerywamy tylko wtedy, gdy kadr faktycznie
            // się zmienił i stara odpowiedź jest już nieaktualna.
            if (treasurePending && treasurePendingUrl === adresZadania) { return; }
            if (treasurePending) { treasurePending.abort(); }
            treasurePending = new AbortController();
            treasurePendingUrl = adresZadania;
            var mojeZadanie = treasurePending;
            if (busy) { busy.start('skarby', __('skarby w kadrze')); }
            fetch(adresZadania, { signal: treasurePending.signal })
                .then(function (r) { return r.ok ? r.json() : null; })
                .catch(function () { return null; })
                .then(function (data) {
                    // ZWALNIAMY SLOT, i to jest konieczne, nie porządkowe:
                    // blokada „to samo pytanie w locie" wyżej patrzy na
                    // `treasurePending`, więc gdyby zostało tu ustawione po
                    // zakończeniu, drugie żądanie o TEN SAM kadr nigdy by nie
                    // poszło — a robi je m.in. zaliczenie skarbu z lokalizacji
                    // (window.ridemoreRefreshTreasures), które musi przerysować
                    // ikonkę bez ruszania mapą. Sprawdzamy tożsamość kontrolera,
                    // żeby spóźniona odpowiedź nie skasowała slotu nowszej.
                    if (treasurePending === mojeZadanie) {
                        treasurePending = null;
                        treasurePendingUrl = null;
                    }
                    if (busy && (treasurePending === null || treasurePending === mojeZadanie)) {
                        // Ten sam warunek tożsamości co przy polach wyżej:
                        // pomiar zamyka wyłącznie żądanie, które jeszcze liczy
                        // się dla tego kadru.
                        busy.koniec('skarby', data
                            ? ((data.treasures || []).length + (data.clusters || []).length) + ' pinezek'
                            : 'bez odpowiedzi');
                    }
                    if (!data) { return; }
                    // Serwer sam decyduje, czy przy tym powiększeniu ma sens
                    // pokazywać pinezki, czy pęczki — przeglądarka tylko rysuje
                    // to, co dostała. Próg w JEDNYM miejscu, nie w dwóch.
                    //
                    // ODPOWIEDŹ POTRAFI NIEŚĆ OBA NARAZ (2026-08-20): przy
                    // oddaleniu pola z jednym skarbem wracają jako `treasures`,
                    // bo pęczek zaczyna się od dwóch. Czyścimy warstwę TUTAJ,
                    // raz — obie funkcje rysujące tylko dokładają.
                    treasureLayer.clearLayers();
                    treasureMarkers = {};
                    if (data.clustered) { drawClusters(data.clusters || []); }
                    drawTreasures(data.treasures || []);

                    // Skarb wskazany z listy obok mapy: kadr już poleciał, więc
                    // dymek otwieramy dopiero teraz, gdy znacznik istnieje —
                    // i przez chwilę PONAWIAMY to przy każdym przerysowaniu.
                    // Zmierzone: `setView` potrafi wywołać drugie odświeżenie
                    // (osobno `zoomend`, osobno `moveend`), a każde czyści
                    // warstwę i kasuje dymek razem ze znacznikiem. Jednorazowe
                    // otwarcie ginęło wtedy w ułamku sekundy.
                    if (focusPending) {
                        if (Date.now() > focusPending.until) {
                            focusPending = null;
                        } else if (treasureMarkers[focusPending.id]) {
                            otworzZnacznik(treasureMarkers[focusPending.id]);
                        }
                    }

                    // Lista „W obszarze" obok mapy dostaje TO SAMO, co przed
                    // chwilą narysowaliśmy — bez drugiego żądania o ten sam
                    // kadr. Strona decyduje, co z tym zrobi.
                    if (typeof options.onTreasures === 'function') {
                        options.onTreasures({
                            clustered: !!data.clustered,
                            treasures: data.treasures || [],
                            clusters: data.clusters || []
                        });
                    }
                });
        }

        /** Otwiera to, co znacznik ma: dymek z akcją, a u gościa sam podpis. */
        function otworzZnacznik(m) {
            if (m.getPopup && m.getPopup()) { m.openPopup(); } else { m.openTooltip(); }
        }

        /**
         * WSKAŻ SKARB NA MAPIE — dla list obok mapy (2026-08-20, zgłoszenie
         * usera: „nie da się na nie kliknąć, więc trudno je zlokalizować").
         *
         * POZYCJĘ PODAJE WOŁAJĄCY, bo tylko on wie, skąd ją ma — a jedyne
         * legalne źródła to odpowiedzi API, które przeszły przez bramkę
         * ujawnienia (`/api/treasures` albo `/api/treasures/{id}`). Moduł nie
         * szuka skarbu po id w bazie i nie ma jak obejść tej bramki.
         *
         * Dymek otwiera się DOPIERO po przerysowaniu warstwy: przesunięcie kadru
         * kasuje znaczniki i pobiera je od nowa, więc ten sprzed skoku i tak by
         * zniknął (patrz `focusPending`).
         */
        map.ridemoreFocusTreasure = function (id, lat, lon) {
            if (typeof lat !== 'number' || typeof lon !== 'number') { return; }
            // ZNACZNIK OTWIERAMY OD RAZU (gdy już jest w kadrze) I ZOSTAWIAMY
            // ZAMÓWIENIE na po przerysowaniu. Zmierzone: samo otwarcie od razu
            // nie wystarcza — `setView` wywołuje `moveend`, warstwa skarbów
            // czyści się i pobiera od nowa, a razem ze starym znacznikiem
            // znika jego dymek. Bez pierwszego otwarcia widać z kolei ćwierć
            // sekundy „nic się nie stało" po kliknięciu w listę.
            focusPending = { id: id, until: Date.now() + 2500 };
            if (treasureMarkers[id]) { otworzZnacznik(treasureMarkers[id]); }
            map.setView([lat, lon], Math.max(map.getZoom(), 15), { animate: true });
        };

        // Publiczne przełączanie warstw — kontrolka w widoku nie musi nic
        // wiedzieć o środku tego modułu, woła jedną metodę. Dane z ostatniej
        // odpowiedzi są w pamięci, więc przełączenie mgły albo heatmapy
        // przerysowuje mapę BEZ pytania serwera; tylko trasy mają własny
        // endpoint i dociągają się przy pierwszym włączeniu.
        // Czy `name` sam JEST rodziną filtrowanych markerów, czy JEDNYM Z JEJ
        // DZIECI — czytane z `options.filters` (Etap 2), zamiast wymieniać
        // 'treasures'/'treasuresFound'/'treasuresNew' po nazwie. Dołożenie
        // trzeciego dziecka w słowniku nie dotyka tej funkcji.
        function jestWFiltrowanejRodzinie(name) {
            var opis = options.filters && options.filters.treasures;
            return name === 'treasures'
                || !!(opis && opis.children && Object.prototype.hasOwnProperty.call(opis.children, name));
        }

        map.ridemoreSetLayer = function (name, on) {
            if (!(name in layers)) { return; }
            layers[name] = !!on;
            if (name in tileLayers) {
                // Warstwa kafli — zapalenie i zgaszenie to dołożenie albo
                // zdjęcie gotowej warstwy Leafletu. Bez pytania serwera
                // o cokolwiek: kafle, które przeglądarka już ma, zostają
                // w jej cache'u.
                on ? tileLayers[name].addTo(map) : map.removeLayer(tileLayers[name]);
                return;
            }
            if (jestWFiltrowanejRodzinie(name)) {
                // Zgaszenie rodziny (albo ostatniego zapalonego dziecka) chowa
                // też jej panel — i oddaje jego wysokość liście aktywności
                // (robi to flexbox w CSS).
                syncPanels();
                // Skarby mają własny endpoint, jak trasy. Każda zmiana idzie do
                // serwera, bo to ON dzieli zbiór wg `stan` — inaczej pęczek
                // „2 skarby, masz 1" nie miałby jak zamienić się w jedną
                // pinezkę. `refreshTreasures` sam czyści warstwę, gdy
                // `ridemoreComposeFilter` nie ma czego pokazać.
                refreshTreasures();
                return;
            }
            if (lastData) { draw(lastData); } else { refresh(); }
        };

        // PYTAMY OD RAZU, A DOPIERO POWTÓRKI CZEKAJĄ (2026-09-02, zgłoszenie
        // usera: „najpierw widzę tracki, później renderuje się hex w tle").
        //
        // Kafle rusza Leaflet w momencie `moveend`, a pola i skarby czekały tu
        // ZAWSZE 250 ms — stąd wrażenie, że warstwy idą po kolei: to nie była
        // kolejność rysowania, tylko ćwierć sekundy fory dla kafli, dokładana
        // do każdego gestu. Teraz pierwszy ruch po chwili spokoju leci NATYCHMIAST
        // (razem z kaflami), a odstęp obowiązuje tylko wtedy, gdy ruchy idą
        // seriami — czyli tam, gdzie faktycznie chroni serwer przed zalewem.
        //
        // Ponowienia z bramki „zerowy kadr" wołają to BEZ argumentu, więc nadal
        // czekają — inaczej pusty kontener kręciłby pętlę żądań bez pauzy.
        var ostatniStrzal = 0;
        function odswiezOba() {
            ostatniStrzal = Date.now();
            refresh();
            refreshTreasures();
        }

        function scheduleRefresh(natychmiast) {
            if (timer) { clearTimeout(timer); timer = null; }
            if (natychmiast === true && Date.now() - ostatniStrzal > 250) {
                odswiezOba();
                return;
            }
            timer = setTimeout(odswiezOba, 250);
        }

        // Stan początkowy paneli musi zgadzać się z checkboxami OD RAZU, a nie
        // dopiero po pierwszym przełączeniu — inaczej panel skarbów mignąłby
        // przy wejściu na mapę z wyłączoną warstwą.
        syncPanels();

        // `true` = „to jest gest człowieka, nie ponowienie" — patrz scheduleRefresh.
        map.on('moveend zoomend', function () { scheduleRefresh(true); });

        // Kontener potrafi dostać rozmiar długo po starcie skryptu — karta w
        // tle, powrót z bfcache, doczytane fonty, zmiana szerokości okna.
        // ResizeObserver łapie dokładnie ten moment, zamiast odmierzać go
        // czasem. Bramka w refresh() i tak jest niezależnym zabezpieczeniem,
        // bo obserwatora nie ma w najstarszych przeglądarkach.
        if (typeof ResizeObserver === 'function' && el) {
            new ResizeObserver(function () {
                if (!attached) { map.invalidateSize(); }
                // Dopiero tutaj kontener zwykle dostaje prawdziwy rozmiar —
                // i dopiero tutaj kadr z serwera da się nałożyć poprawnie.
                applyStartBounds();
                scheduleRefresh();
            }).observe(el);
        }

        // Mgła kładzie się OD RAZU, jeszcze przed pierwszą odpowiedzią serwera
        // — inaczej przez ułamek sekundy (a przy wolnym łączu dłużej) świat
        // byłby w całości odsłonięty, czyli pokazywałby stan, który jest
        // dokładnym przeciwieństwem prawdy.
        //
        // Ale TYLKO gdy warstwa pól jest zapalona: przy zgaszonej ten wstępny
        // welon i tak zniknąłby po pierwszej odpowiedzi (draw() czyści warstwę),
        // czyli byłby wyłącznie mignięciem szarości na mapie, która z założenia
        // ma być goła (np. strona trasy oglądana przez gościa).
        if (layers.cells) {
            hexPolygon([WORLD_RING], UNDISCOVERED, false).addTo(layer);
        }

        // Pierwsze żądanie DOPIERO po invalidateSize(). Bez tego Leaflet
        // zapamiętuje kontener o zerowej szerokości (mapa powstaje, zanim
        // układ strony się ustabilizuje) i getBounds() zwraca prostokąt, w
        // którym east === west — pusty kadr, na który baza słusznie odpowiada
        // zerem pól. Objaw jest mylący: kafle rysują się normalnie, tylko
        // mgła nigdy nie znika.
        //
        // setTimeout, a NIE requestAnimationFrame: rAF nie odpala się w karcie
        // otwartej w tle (zmierzone — document.visibilityState === 'hidden'
        // wstrzymuje klatki), więc mapa otwarta przez „otwórz w nowej karcie"
        // zostawałaby zamglona do momentu przełączenia się na nią.
        setTimeout(function () {
            if (!attached) { map.invalidateSize(); }

            // KADR Z SERWERA USTAWIANY TUTAJ, a nie przy tworzeniu mapy —
            // dopiero po invalidateSize() Leaflet zna prawdziwy rozmiar
            // kontenera, a fitBounds na kontenerze bez rozmiaru dobiera
            // oddalenie z sufitu (patrz nota przy applyStartBounds).
            applyStartBounds();
            // fitBounds wyżej wysłał `moveend`, czyli zaplanował odświeżenie za
            // 250 ms. Kasujemy je, bo za chwilę pytamy o to samo wprost —
            // bez tego każde wejście na mapę robiło KOMPLET żądań dwa razy.
            if (timer) { clearTimeout(timer); timer = null; }

            refresh();
            refreshTreasures();
        }, 0);

        // Wystawiamy JEDNO odswiezenie warstwy skarbow na zewnatrz — potrzebuje
        // go zaliczenie z lokalizacji (SKA/6), ktore zmienia stan skarbu bez
        // przeladowania strony. Bez tego ikonka po zaliczeniu dalej wyglada na
        // nieznaleziona az do nastepnego ruchu mapa.
        window.ridemoreRefreshTreasures = refreshTreasures;

        return map;
    };
})();
