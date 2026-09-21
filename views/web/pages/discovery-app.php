<?php
// views/web/pages/discovery-app.php
// PEŁNOEKRANOWA MAPA ODKRYĆ — WYŁĄCZNIE w apce mobilnej (APP_IS_APP), Etap 1
// przebudowy apki mobilnej (tasks/active/apka-mobilna.md, uwaga usera
// 2026-08-28: „mapa powinna być centralnym przyciskiem, widok mapy powinien
// obejmować w 100% całość ekranu, jak w MysteryHike").
//
// RENDEROWANA Z TYCH SAMYCH DANYCH co views/web/pages/discovery.php — patrz
// Controllers\DiscoveryController::index(), który wybiera TEN szablon zamiast
// tamtego wyłącznie na podstawie APP_IS_APP. Inny szablon, nie inne dane.
//
// CZEGO TU ŚWIADOMIE NIE MA względem discovery.php (decyzje usera z tej
// sesji, żeby ekran zostawał czytelny na telefonie):
//   - hero, kafli statystyk na całą szerokość, karty „Następny cel",
//   - panelu „Ostatnie przejazdy" (partials/ride-feed.php),
//   - przełącznika moja/wspólnota — ten zostaje na pełnej stronie web.
// Statystyka to mały chip w rogu (X pól · Y/Z skarbów), nie kafle.
//
// SILNIK MAPY JEST TEN SAM (assets/js/discovery-map.js →
// window.ridemoreDiscoveryMap) i kontrolka warstw też ta sama
// (partials/map-layers.php) — nic z tego nie jest przepisane.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

$tab          = $tab ?? 'all';
$isLoggedIn   = $isLoggedIn ?? false;
$summary      = $summary ?? null;
$treasures    = $treasures ?? null;
$treasuresAll = $treasuresAll ?? null;
$regionsWorld = $regionsWorld ?? null;

$num = static fn($n) => number_format((int) $n, 0, ',', ' ');

// CHIP „X pól · Y/Z skarbów". Zalogowany na zakładce osobistej dostaje SWOJE
// liczby (te same źródła co kafle na pełnej stronie: Discovery::summaryForUser,
// Treasure::statsForUser). Gość zawsze ląduje na zakładce wspólnej (patrz
// kontroler) i nie ma osobistych liczb — dostaje odpowiedniki społeczności,
// którymi pełna strona karmi pasek „Razem odkrywamy Polskę" i kafle skarbów,
// żeby chip nigdy nie został pusty.
$chipCells = $summary['cells'] ?? ($regionsWorld['grand']['community'] ?? 0);
$chipFound = $treasures['found'] ?? ($treasuresAll['taken'] ?? 0);
$chipTotal = $treasures['total'] ?? ($treasuresAll['total'] ?? 0);
?>
<div class="app-map-page">
    <div id="discoveryMap" class="app-map"></div>

    <div class="app-map__stats" aria-label="<?= htmlspecialchars(__('Twoje odkrycia')) ?>">
        <span class="app-map__stat"><?= Utils\Icon::render('hex') ?><?= $num($chipCells) ?></span>
        <span class="app-map__stat" aria-hidden="false"><?= Utils\Icon::render('tre-curiosity') ?><?= $num($chipFound) ?>/<?= $num($chipTotal) ?></span>

        <!-- STAN NAGRYWANIA I WŁĄCZNIK (zgłoszenie usera 2026-08-29: „nie
             widziałem nigdzie zatrzymania ani ikonki, że jestem w trybie
             trakingu; może warto dodać to koło punktów"). Do tej pory jedynym
             śladem nagrywania była pastylka widoczna WYŁĄCZNIE w trakcie
             trwającej sesji — gdy sesja nie ruszyła, nie było nic.
             Stan i obsługę nadaje `assets/js/app-tracking.js`, tu zostaje
             sam kształt; `hidden` do czasu, aż tamten plik potwierdzi, że most
             natywny działa — przycisk obiecujący funkcję, której nie ma, to
             błąd, który w tym projekcie zdarzył się już kilka razy. -->
        <button type="button" class="app-map__stat app-map__rec" data-rm-bg-toggle aria-pressed="false" hidden>
            <span class="app-map__rec-dot" aria-hidden="true"></span>
            <span data-rm-bg-label><?= __('Nagrywaj') ?></span>
        </button>
    </div>

    <?php
    // WARSTWY — TA SAMA kontrolka co wszędzie indziej (prośba usera
    // 2026-08-19: „nie chcę, żeby w aplikacji używano czegoś innego niż
    // standardowej kontrolki mapy"). Wymaga rodzica position:relative —
    // .app-map-page go daje (patrz style.css).
    $mlId            = 'discoveryLayersApp';
    $mlLayers        = $mapLayers ?? [];
    $mlDefaultLayers = $mapLayersDefault ?? null;
    require __DIR__ . '/../partials/map-layers.php';
    ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('discoveryLayersApp');

    <?php // Te same opcje co discovery.php:657 — sam wywołujący ekran jest
          // mniejszy, silnik mapy dostaje identyczne dane. ridesHitEndpoint
          // (klik we własny ślad) tylko na zakładce osobistej, z tego samego
          // powodu co tam: bez niej endpoint i tak oddałby 404. ?>
    var map = ridemoreDiscoveryMap(document.getElementById('discoveryMap'), {
        context: <?= json_encode($tab === 'me' ? 'me' : 'all') ?>,
        treasuresEndpoint: <?= json_encode(View::url('/api/treasures')) ?>,
        treasureActions: <?= $isLoggedIn ? 'true' : 'false' ?>,
        claimEndpoint: <?= json_encode(View::url('/api/treasures/claim')) ?>,
        confirmEndpoint: <?= json_encode(View::url('/api/treasures/confirm')) ?>,
        csrf: <?= json_encode(Core\Csrf::token()) ?>,
        endpoint: <?= json_encode(View::url('/api/discovery/cells')) ?>,
        sources: <?= json_encode($mapSources ?? [], JSON_UNESCAPED_SLASHES) ?>,
        filters: <?= json_encode($mapFilters ?? [], JSON_UNESCAPED_SLASHES) ?>,
        trailsHitEndpoint: <?= json_encode(View::url('/api/discovery/trails/at')) ?>,
        <?php if ($isLoggedIn && $tab === 'me'): ?>
        ridesHitEndpoint: <?= json_encode(View::url('/api/discovery/rides/at')) ?>,
        <?php endif; ?>
        <?php // KADR: BIEŻĄCA LOKALIZACJA, NIE PROSTOKĄT ODKRYĆ (zgłoszenie usera
              // 2026-08-29, kontrakt tasks/done/mapa-apka-kadr-na-mnie.md).
              // W apce `bounds` NIE idzie do silnika — inaczej applyStartBounds()
              // nałożyłby kadr odkryć i skasował wycentrowanie na człowieku
              // (ta funkcja czeka, aż kontener urośnie do 40 px, i ponawia próbę
              // z ResizeObserver, więc potrafi odpalić PO pierwszym odczycie GPS).
              // Prostokąt z serwera schodzi niżej, do JS-a, jako kadr AWARYJNY.
              // `fitToCells:false` z tego samego powodu: dociągnięcie do
              // wczytanych pól też przeskoczyłoby pozycję użytkownika. ?>
        bounds: null,
        fitToCells: false,
        zoom: <?= $tab === 'me' ? 8 : 6 ?>,
        layers: ridemoreReadLayers(box),
    });

    // PRZEŁĄCZNIKI WARSTW — jedno wywołanie, wspólna obsługa (2026-08-30).
    // Ten ekran renderował kontrolkę i CZYTAŁ ją raz, przy tworzeniu mapy,
    // ale nikt nie słuchał zmian: checkbox się zaznaczał i nie robił nic.
    // Podpięcie mieszka teraz w discovery-map.js, żeby nie było szóstej kopii
    // tego samego `addEventListener`.
    ridemoreBindLayers(map, box);

    // KADR STARTOWY — KOLEJNOŚĆ PIERWSZEŃSTWA: (1) pozycja użytkownika,
    // (2) po 6 s bez pozycji prostokąt odkryć z serwera (czyli zachowanie
    // sprzed tej zmiany), (3) nic, jeśli user sam ruszył mapą palcem.
    //
    // CENA, ŚWIADOMIE ZAPŁACONA: bez kadru z serwera silnik pyta o pola dla
    // widoku domyślnego (cały kraj), zanim GPS zdąży odpowiedzieć. To jedno
    // żądanie więcej — ale to ta sama ścieżka, którą i tak chodzi dziś każde
    // konto bez ani jednego odkrytego pola, a `refresh()` przerywa je
    // (`pending.abort()`), gdy tylko kadr się przesunie.
    var kadrZSerwera = <?= json_encode($mapBounds ?? null) ?>;
    var kadrUstawiony = false;
    var userRuszylMapa = false;

    // ZAŁOŻENIE, KTÓRE BYŁO FAŁSZYWE (naprawa 2026-08-29, zgłoszenie z telefonu:
    // „bez logowania bieżąca lokalizacja jest podana, po zalogowaniu wskakuje
    // środek Polski").
    //
    // Stało tu: „`zoomstart` przed pierwszym kadrem może pochodzić TYLKO od
    // palca, bo silnik nie rusza mapą sam". Rusza. Przy `bounds:null` robi
    // `map.setZoom(options.zoom)` (discovery-map.js), a Leaflet ODPALA
    // `zoomstart` DOPIERO W NASTĘPNEJ KLATCE (animacja zoomu idzie przez
    // requestAnimationFrame) — czyli już PO podpięciu tych słuchaczy.
    //
    // I stąd dokładnie ta różnica, którą user zobaczył: gość dostaje
    // `zoom: 6`, czyli tyle, ile mapa ma po utworzeniu — `setZoom` nie zmienia
    // nic i żadne zdarzenie nie leci. Zalogowany na zakładce „moja" dostaje
    // `zoom: 8`, więc oddalenie SIĘ ZMIENIA, `zoomstart` przychodzi klatkę
    // później, a my liczymy to jako „user ruszył mapą" i blokujemy GPS
    // na zawsze. Mapa zostaje tam, gdzie ją silnik postawił — w środku Polski.
    //
    // `dragstart` zostaje bez okna: przeciągnięcie ZAWSZE pochodzi od palca,
    // silnik nigdy nie przesuwa mapy sam.
    // TYLKO PRZECIĄGNIĘCIE ZNACZY „PRZEJMUJĘ MAPĘ" (zmiana 2026-08-29).
    //
    // Dotąd blokował też `zoomstart` — z komentarzem, że „silnik nie rusza mapą
    // sam". Silnik rusza: przy `bounds:null` woła `map.setZoom(options.zoom)`.
    // Zmierzone jednak (podgląd, mapa tworzona dokładnie jak tutaj): zdarzenie
    // po podpięciu słuchaczy NIE przychodzi, a oddalenie i tak zostaje na
    // wyjściowym — czyli `zoomstart` jest tu sygnałem, na którym nie da się
    // polegać w żadną stronę. Zamiast zgadywać jego moment, przestajemy go
    // pytać: `dragstart` jest jednoznaczny, bo silnik nigdy nie PRZESUWA mapy.
    //
    // Koszt świadomy: kto uszczypnie mapę w pierwszych sekundach i NIE
    // przesunie jej palcem, temu pierwszy odczyt GPS jeszcze raz ustawi kadr.
    // Jedno szarpnięcie jest tańsze niż mapa, która nigdy nie trafia tam,
    // gdzie stoi człowiek — a to jest zgłoszony objaw („po zalogowaniu
    // wskakuje środek Polski").
    function zapamietajRuchUsera() {
        if (!kadrUstawiony) { userRuszylMapa = true; }
    }
    map.on('dragstart', zapamietajRuchUsera);

    // POZYCJA WYGRYWA Z KADREM AWARYJNYM, NAWET GDY PRZYJDZIE PÓŹNIEJ
    // (poprawka 2026-08-29 — user: „mapa nie centruje na mojej lokalizacji").
    //
    // Pierwsza wersja blokowała się wspólną flagą `kadrUstawiony`: awaryjny
    // prostokąt odkryć nakładał się po 6 s, podnosił flagę, a pierwszy fix
    // GPS — który na telefonie potrafi przyjść po kilkunastu sekundach — nie
    // miał już prawa nic zmienić. Efekt: mapa NIGDY nie centrowała się na
    // człowieku, choć dokładnie po to powstała.
    //
    // Teraz jedyną rzeczą, która blokuje GPS, jest RUCH PALCEM. Kadr awaryjny
    // to tylko wypełniacz na czas oczekiwania i wolno go nadpisać.
    var gpsUstawilKadr = false;
    function kadrNaMnie(lat, lon) {
        if (gpsUstawilKadr || userRuszylMapa) { return; }
        gpsUstawilKadr = true;
        kadrUstawiony = true;
        map.setView([lat, lon], 15);
    }

    // DOTKNIĘCIE „MAPA" NA PASKU, GDY JUŻ JESTEŚ NA MAPIE = WRÓĆ DO SIEBIE
    // (2026-08-30). Slot jest wtedy przyciskiem, nie linkiem (app-nav.php),
    // więc nic się nie przeładowuje. To także jedyna droga POWROTU po tym,
    // jak ktoś odjechał mapą palcem — `userRuszylMapa` blokuje automatyczne
    // centrowanie na zawsze i celowo tego nie zmieniamy: automat nie ma prawa
    // wyrywać mapy z ręki, ale świadome dotknięcie ma prawo ją oddać.
    //
    // OD 2026-09-10 TEN PRZYCISK NIE MA WŁASNEJ LOGIKI. Woła `map.ridemoreLocate()`
    // — tę samą drogę, co nowa kontrolka „tu jestem" w rogu mapy (wpięta
    // w `ridemoreCreateMap`, patrz `assets/js/gpx-map.js`). Poprzednia wersja
    // była kopią: pytała o pozycję z własnymi ustawieniami (timeout 8 s) i —
    // co gorsza — kończyła się `.catch(function () {})`, czyli przy braku
    // sygnału NIE MÓWIŁA NIC. To jest dokładnie zgłoszona usterka („jeśli nie
    // ma, to pokazać, że czekasz na sygnał"), a naprawianie jej w dwóch
    // kopiach osobno skończyłoby się trzecią.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-rm-map-here]');
        if (!btn || typeof map.ridemoreLocate !== 'function') { return; }
        map.ridemoreLocate();
    });

    // ZDJĘCIE BLOKADY, GDY POZYCJA PRZYSZŁA ZE ŚWIADOMEGO DOTKNIĘCIA.
    // `userRuszylMapa` blokuje automatyczne centrowanie NA ZAWSZE i celowo
    // tego nie zmieniamy — automat nie ma prawa wyrywać mapy z ręki. Ale
    // dotknięcie przycisku to prośba o oddanie jej z powrotem, więc od tej
    // chwili kolejne odczyty z nasłuchu znowu mogą prowadzić kadr.
    // Kontrolka wysyła to zdarzenie PRZED własnym `setView`, więc flagi są
    // zdjęte, zanim mapa ruszy.
    map.on('ridemore:locate', function () {
        userRuszylMapa = false;
        gpsUstawilKadr = true;
        kadrUstawiony = true;
    });

    // SZYBKI PIERWSZY ODCZYT, RÓWNOLEGLE DO NASŁUCHU. `maximumAge` pozwala
    // wziąć pozycję, którą system ma już w ręku — pojawia się natychmiast,
    // zamiast czekać na zimny fix. Niska dokładność jest tu bez znaczenia:
    // do wycentrowania mapy w skali 15 wystarczy z zapasem, a `watchPosition`
    // niżej i tak dociągnie precyzję i kropkę „tu jestem".
    if (window.RM && RM.native && typeof RM.native.position === 'function') {
        RM.native.position({ highAccuracy: false, maximumAge: 120000, timeout: 8000 })
            .then(function (poz) { kadrNaMnie(poz.lat, poz.lon); })
            .catch(function () { /* cisza — od tego jest kadr awaryjny niżej */ });
    }

    // KADR AWARYJNY OD RAZU, NIE PO SZEŚCIU SEKUNDACH (poprawka 2026-08-30,
    // zgłoszenie: „wchodzę na mapę i dla zalogowanego usera ustawia na środku
    // Polski"). Środek Polski to był po prostu widok domyślny Leafletu, w którym
    // mapa siedziała przez pierwsze 6 s KAŻDEGO wejścia — czekając na GPS,
    // a gdy ten milczał, jeszcze chwilę na ten timeout. Prostokąt odkryć jest
    // na serwerze policzony ZANIM strona wyszła, więc nie ma po co go trzymać:
    // lepszy przybliżony kadr od razu niż dobry po sześciu sekundach.
    //
    // GPS DALEJ WYGRYWA. `kadrNaMnie` blokuje wyłącznie `userRuszylMapa`
    // (przeciągnięcie palcem), więc pierwszy odczyt nadal przestawia mapę na
    // człowieka — teraz po prostu z jego okolicy, a nie z całego kraju.
    // Dlatego NIE podnosimy tu `kadrUstawiony`: to nie jest kadr ostateczny.
    (function () {
        if (userRuszylMapa || !kadrZSerwera || !window.L) { return; }
        // `animate:false` z tego samego powodu co w applyStartBounds(): kadr
        // startowy ma być USTAWIONY, nie dojechany — animacja wisi na
        // requestAnimationFrame, który w tle nie odpala.
        map.fitBounds(
            L.latLngBounds(
                [kadrZSerwera.south, kadrZSerwera.west],
                [kadrZSerwera.north, kadrZSerwera.east]
            ),
            { padding: [18, 18], animate: false }
        );
    })();

    // ŻYWA POZYCJA „TU JESTEM" — kropka + okrąg dokładności.
    //
    // JEDEN KLIENT LOKALIZACJI NARAZ, NIE DWA (przebudowa 2026-08-29, po
    // zgłoszeniu „z pełnej baterii zrobiło się 0 po 40 minutach").
    //
    // Do tej pory ten ekran bezwarunkowo otwierał WŁASNY `watchPosition`
    // z `highAccuracy:true` i `maximumAge:0` — najdroższą konfiguracją, jaka
    // istnieje — i NIGDY go nie zamykał: ani gdy apka szła w tło, ani gdy
    // nagrywanie w tle i tak już trzymało odbiornik. Przy nagrywaniu z otwartą
    // mapą pracowały więc DWA niezależne strumienie GPS jednocześnie.
    //
    // Dla porównania, jak to rozwiązuje OwnTracks (sprawdzone w ich
    // dokumentacji, nie z pamięci): mają JEDEN klient i przełączane tryby —
    // „significant change" na co dzień (balanced power, fix co ~15 min,
    // z cell/WiFi) i „move" na czas jazdy (GPS co 10 s), o którym sami piszą,
    // że kosztuje jak nawigacja i ma być włączany tylko na przejazd.
    // Nasze nagrywanie JEST odpowiednikiem ich trybu „move" — więc nie da się
    // zejść z kosztu poniżej nawigacji. Da się natomiast nie płacić go DWA RAZY.
    //
    // Zasada: pozycje z nagrywania są DARMOWE (odbiornik i tak pracuje), więc
    // gdy nagrywanie trwa — bierzemy je zdarzeniem `rm:position`. Własny nasłuch
    // otwieramy TYLKO wtedy, gdy nikt inny nie trzyma GPS-u, i zamykamy go, gdy
    // ekran znika z oczu albo gdy nagrywanie startuje.
    if (window.L && window.RM && RM.native) {
        var meDot = null, meRing = null;
        var watchId = null;
        var nagrywaniemTrzyma = false;

        function rysujPozycje(poz) {
            if (!poz) { return; }
            var ll = [poz.lat, poz.lon];
            // PIERWSZY ODCZYT USTAWIA KADR, kolejne przesuwają samą kropkę.
            // Centrowanie przy każdej aktualizacji zabrałoby userowi możliwość
            // obejrzenia czegokolwiek obok siebie — mapa uciekałaby spod palca.
            kadrNaMnie(poz.lat, poz.lon);
            if (!meDot) {
                meRing = L.circle(ll, { radius: poz.accuracy || 30, color: '#2E7DD6', weight: 0, fillOpacity: .12 }).addTo(map);
                meDot  = L.circleMarker(ll, { radius: 7, color: '#fff', weight: 2, fillColor: '#2E7DD6', fillOpacity: 1 }).addTo(map);
            } else {
                meDot.setLatLng(ll);
                meRing.setLatLng(ll).setRadius(poz.accuracy || 30);
            }
        }

        function stopWlasnegoNasluchu() {
            if (watchId === null) { return; }
            RM.native.clearWatch(watchId);
            watchId = null;
        }

        function startWlasnegoNasluchu() {
            if (watchId !== null || nagrywaniemTrzyma || document.hidden) { return; }
            if (typeof RM.native.watchPosition !== 'function') { return; }
            RM.native.watchPosition({ highAccuracy: true }, function (poz, err) {
                if (err || !poz) { return; }
                rysujPozycje(poz);
            }).then(function (id) {
                // Nagrywanie mogło ruszyć albo ekran zniknąć, ZANIM obietnica
                // oddała id — wtedy zamykamy od razu, zamiast zostawić watcha,
                // do którego nikt już nie ma uchwytu.
                watchId = id;
                if (nagrywaniemTrzyma || document.hidden) { stopWlasnegoNasluchu(); }
            }).catch(function () {});
        }

        // Pozycje z nagrywania w tle — za darmo, odbiornik i tak pracuje.
        window.addEventListener('rm:position', function (e) { rysujPozycje(e.detail); });
        window.addEventListener('rm:tracking', function (e) {
            nagrywaniemTrzyma = !!(e.detail && e.detail.active);
            if (nagrywaniemTrzyma) { stopWlasnegoNasluchu(); } else { startWlasnegoNasluchu(); }
        });

        // EKRAN ZNIKA Z OCZU = NIE MA KOMU PATRZEĆ NA KROPKĘ. Bez tego mapa
        // zwinięta do tła trzymała GPS w nieskończoność, nic nie rysując.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stopWlasnegoNasluchu(); } else { startWlasnegoNasluchu(); }
        });

        startWlasnegoNasluchu();
    }

    // ZBLIŻASZ SIĘ DO SKARBU (Yanosik-style, Etap 5b, 2026-08-28) — zdarzenia
    // wysyła assets/js/app-tracking.js, niezależnie od tego, czy ten ekran
    // jest akurat otwarty; tu tylko RYSUJEMY, jeśli akurat jest. Znacznik
    // działa też dla skarbów jeszcze nieodkrytych jazdą (decyzja usera) —
    // treść przychodzi już przycięta do właściwej precyzji przez
    // Treasure::nearbyForAlerts, więc mapa nie musi nic ukrywać sama.
    if (window.L) {
        var nearMarkers = {};
        window.addEventListener('rm:treasure-near', function (e) {
            var t = e.detail.treasure;
            if (nearMarkers[t.id]) {
                nearMarkers[t.id].setLatLng([t.lat, t.lon]);
                return;
            }
            nearMarkers[t.id] = L.marker([t.lat, t.lon], {
                icon: L.divIcon({ className: '', html: '<div class="rm-near-marker"></div>', iconSize: [26, 26] }),
                interactive: false,
                keyboard: false
            }).addTo(map);
        });
        window.addEventListener('rm:treasure-far', function (e) {
            var m = nearMarkers[e.detail.id];
            if (m) { map.removeLayer(m); delete nearMarkers[e.detail.id]; }
        });
    }
});
</script>
