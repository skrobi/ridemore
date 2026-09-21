<?php
// views/web/pages/treasure-propose-app.php
// PEŁNOEKRANOWE ZGŁOSZENIE SKARBU — WYŁĄCZNIE w apce mobilnej (APP_IS_APP),
// Faza 3 przebudowy UX apki (tasks/done/apka-mobilna-ux.md, 2026-08-29).
//
// RENDEROWANA Z TYCH SAMYCH DANYCH co views/web/pages/treasure-propose.php —
// patrz Controllers\TreasureProposalController::form(), który wybiera TEN
// szablon zamiast tamtego wyłącznie na podstawie APP_IS_APP. Ten sam wzorzec
// co discovery.php → discovery-app.php w Etapie 1: inny szablon, nie inne dane.
//
// Dziś (web): mapa lewo / formularz prawo (.tr-work/.tp-work, wzorzec panelu
// admina). Tu: mapa PEŁNOEKRANOWA jak na discovery-app.php, tap w mapę stawia
// pinezkę i OD RAZU otwiera formularz jako dolny arkusz — wzorzec „dodaj
// miejsce" z map konsumenckich/MysteryHike. Formularz mieszka w `.disc-panel`
// (ten sam generyczny komponent szuflady co lista skarbów na /odkrycia,
// `assets/js/discovery-map.js:ridemoreSetupPanel` — nic nowego nie wymyślamy),
// domyślnie ZWINIĘTY: zanim user wskaże punkt, mapa ma całą wysokość ekranu
// do celowania pinezką. Warstwa istniejących skarbów (antyduplikat) zostaje,
// przez ten sam partial co wszędzie (`partials/map-layers.php`).
//
// CZEGO TU ŚWIADOMIE NIE MA względem treasure-propose.php (ten sam odruch co
// discovery-app.php): tabeli „Twoje zgłoszenia w poczekalni" — ekran terenowy
// ma jedno zadanie (wskaż i opisz punkt), przegląd własnych zgłoszeń zostaje
// na pełnej stronie web/w apce w innym miejscu (np. profil), nie tutaj.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

$categories   = $categories ?? [];
$treasurePins = $treasurePins ?? [];
$info         = $info ?? null;
// LIMIT SPRAWDZANY PRZED WYPEŁNIENIEM (2026-08-29) — patrz komentarz przy
// `limitOsiagniety` w TreasureProposalController::form(). Bez tego jedyną
// informacją o limicie było przekierowanie po wysłaniu, gubiące zdjęcie
// zrobione w terenie.
$limitOsiagniety = $limitOsiagniety ?? false;
$maxOpen         = $maxOpen ?? 5;
?>
<div class="app-map-page">
    <div id="zgloszenieMapa" class="app-map"></div>

    <?php // KONTROLKA WARSTW — ten sam partial co na /odkrycia i na desktopowym
          // formularzu zgłoszenia. ?>
    <?php
    $mlId = 'tpLayersApp';
    // WARSTWY KONTEKSTOWE ZE SŁOWNIKA + WŁASNA WARSTWA EKRANU (Etap 4
    // warstwy-mapy.md). Mgła, heatmapa, ślady i trasy przychodzą z kontrolera
    // przez `Models\MapLayer` — te same, co na każdej innej mapie w serwisie.
    // „Istniejące skarby" DOPISUJEMY tutaj i tylko tutaj: to warstwa
    // antyduplikatowa tego ekranu, rysowana jego własnym kodem, i nie ma
    // odpowiednika w słowniku (węzeł „Skarby" jest z niego świadomie wycięty,
    // patrz kontroler — inaczej stałyby obok siebie dwa komplety pinezek).
    $mlLayers = array_merge($mapLayers ?? [], [
        ['key' => 'treasures', 'label' => __('Istniejące skarby'), 'hint' => __('żebym nie zgłosił dubla'), 'on' => true],
    ]);
    require __DIR__ . '/../partials/map-layers.php';
    ?>

    <?php if ($info): ?>
    <?php // WŁASNY KOMPONENT, NIE POŻYCZONY CHIP STATYSTYK (2026-08-29).
          // Wcześniej stało tu `.app-map__stats` z pozycją nadpisaną stylem
          // inline — a ta pastylka ma `white-space:nowrap`, bo służy do
          // pokazywania „133 · 0/104". Całe zdanie rozpychało ją poza obie
          // krawędzie ekranu i przykrywało przycisk „Warstwy". ?>
    <div class="app-map__msg"><?= htmlspecialchars($info) ?></div>
    <?php endif; ?>

    <div class="disc-panels">
        <aside class="disc-panel" id="tpFormPanel" data-drawer="Zgłoś to miejsce">
            <h3><?= __('Zgłoś to miejsce') ?></h3>

            <?php if ($limitOsiagniety): ?>
            <?php // FORMULARZA TU NIE MA — świadomie. `save()` i tak odrzuciłby to
                  // zgłoszenie, a odrzuca przekierowaniem, które gubi wszystko:
                  // w apce razem ze zdjęciem zrobionym przed chwilą przy obiekcie.
                  // Puste ręce na wejściu bolą mniej niż stracona robota na wyjściu.
                  // Mapa ZOSTAJE — istniejące skarby i własne pinezki dalej warto
                  // obejrzeć, a to jedyny pełnoekranowy widok mapy skarbów w apce. ?>
            <p class="desc"><?= __('Masz już {n} zgłoszenia czekające na potwierdzenie. Kolejne przyjmiemy, gdy ktoś potwierdzi któreś z nich w terenie — dzięki temu poczekalnia zostaje krótka, a punkty faktycznie ktoś odwiedza.', ['n' => (int) $maxOpen]) ?></p>
            <p class="desc"><a href="<?= View::url('/odkrycia') ?>"><?= __('Wróć na mapę odkryć') ?></a></p>
            <?php else: ?>

            <p class="desc" id="zgPozycja" style="margin:0 0 10px;"><?= __('Stuknij w mapę, żeby wskazać punkt.') ?></p>

            <?php // ENCTYPE — od 2026-08-29 formularz niesie zdjęcie. Bez tego
                  // atrybutu plik nie doszedłby do $_FILES, a reszta pól
                  // działałaby dalej, więc usterka byłaby cicha. ?>
            <form class="tp-form" method="post" action="<?= View::url('/skarby/zglos') ?>" id="tpFormApp"
                  enctype="multipart/form-data">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="lat" id="zgLat" value="">
                <input type="hidden" name="lon" id="zgLon" value="">

                <?php // WYSZUKIWARKI MIEJSC TU NIE MA — świadomie, w odróżnieniu od
                      // treasure-propose.php (web). Służy do zgłaszania z fotela,
                      // czyli odwrotności tego ekranu: tutaj user STOI przy obiekcie,
                      // a pinezkę stawia mu GPS. Zostawiona na wersji webowej. ?>

                <?php // ZDJĘCIE PIERWSZE — user stoi przy obiekcie i patrzy na niego,
                      // więc to najnaturalniejszy pierwszy ruch. Opcjonalne.
                      // Przycisk aparatu wkłada plik do TEGO SAMEGO <input type=file>
                      // przez DataTransfer (delegacja data-rm-camera-for w native.js,
                      // wzorzec 1:1 z galerii skarbu) — formularz zostaje zwykłym
                      // multipart POST-em, serwer nie wie o żadnym aparacie. ?>
                <label class="form-field-label" for="tpPhoto"><?= __('Zdjęcie') ?> <span class="desc"><?= __('(nieobowiązkowe)') ?></span></label>
                <div class="tp-photo">
                    <button type="button" class="btn btn-secondary" data-rm-camera-for="tpPhoto">
                        <?= Utils\Icon::render('camera') ?> <?= __('Zrób zdjęcie') ?>

                    </button>
                    <input type="file" id="tpPhoto" name="photo" accept="image/*">
                    <img class="tp-photo__thumb" id="tpPhotoThumb" alt="" hidden>
                </div>

                <label class="form-field-label" for="zgNazwa"><?= __('Nazwa') ?></label>
                <input class="search-input" id="zgNazwa" name="name" maxlength="160" required
                       placeholder="<?= htmlspecialchars(__('np. Kapliczka na Przełęczy Krowiarki')) ?>">

                <label class="form-field-label" for="zgKat"><?= __('Co to jest') ?></label>
                <select class="search-input" id="zgKat" name="category_item_id">
                    <option value=""><?= __('— wybierz —') ?></option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <?php // RESZTA POD ZWINIĘCIEM — ekran terenowy ma mieć trzy ruchy
                      // (zdjęcie, nazwa, kategoria) i przycisk. Oba pola niżej mają
                      // sensowne wartości domyślne: Jawny (2, tak jak dotąd)
                      // i pusty opis. Kto chce dopisać więcej, rozwija. ?>
                <details class="tp-more">
                    <summary><?= __('Więcej szczegółów') ?></summary>

                    <label class="form-field-label" for="zgReveal"><?= __('Ujawnienie na mapie') ?></label>
                    <select class="search-input" id="zgReveal" name="reveal_level">
                        <option value="2" selected><?= __('Jawny — pinezka od razu') ?></option>
                        <option value="1"><?= __('Trop — wskazówka po odkryciu pola') ?></option>
                        <option value="0"><?= __('Ukryty — znak zapytania w polu') ?></option>
                    </select>
                    <?php // UPRZEDZENIE, NIE OSTRZEŻENIE — Treasure::reveal() zeruje
                          // photo_url dla Tropu i Ukrytego, żeby zdjęcie nie zdradzało
                          // zagadki. Bez tego zdania user pomyśli, że zdjęcie przepadło. ?>
                    <p class="desc"><?= __('Przy Tropie i Ukrytym zdjęcie zobaczy dopiero ten,
                       kto odkryje to pole — inaczej fotka zdradzałaby zagadkę.') ?></p>

                    <label class="form-field-label" for="zgOpis"><?= __('Dlaczego warto tu podjechać?') ?></label>
                    <textarea class="search-input" id="zgOpis" name="description" rows="3" maxlength="600"
                              placeholder="<?= htmlspecialchars(__('Widok na trzy doliny, ławka i źródełko po drugiej stronie drogi.')) ?>"></textarea>
                </details>

                <div class="tp-actions">
                    <button type="submit" class="btn"><?= __('Zgłoś to miejsce') ?></button>
                    <button type="button" class="btn btn-secondary" id="zgGps">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4"/></svg>
                        <?= __('Moja lokalizacja') ?>

                    </button>
                </div>
            </form>
            <?php endif; ?>
        </aside>
    </div>
</div>

<script>
(function () {
    var poleLat = document.getElementById('zgLat');
    var poleLon = document.getElementById('zgLon');
    var podpis = document.getElementById('zgPozycja');
    var panel = document.getElementById('tpFormPanel');

    // OTWARCIE SZUFLADY PROGRAMOWO — to samo, co robi uchwyt doklejany przez
    // ridemoreSetupPanel (discovery-map.js) po kliknięciu, tylko wywołane z
    // kodu zamiast z palca: pin postawiony na mapie ma OD RAZU pokazać
    // formularz, a nie czekać, aż user sam pociągnie uchwyt w dole ekranu.
    function otworzSzufladę() {
        if (!panel) { return; }
        panel.classList.add('is-open');
        var uchwyt = panel.querySelector('.disc-panel__handle');
        if (uchwyt) { uchwyt.setAttribute('aria-expanded', 'true'); }
    }

    var mapa = ridemoreCreateMap(document.getElementById('zgloszenieMapa'), [52.0, 19.4]);

    // WARSTWA ISTNIEJĄCYCH SKARBÓW — ta sama logika co na desktopowym formularzu.
    var existingTreasures = <?= json_encode($treasurePins, JSON_UNESCAPED_UNICODE) ?>;
    var treasureIcon = L.divIcon({ className: 'tp-pin tp-pin--existing', iconSize: [20, 20], iconAnchor: [10, 10] });
    var proposedIcon = L.divIcon({ className: 'tp-pin tp-pin--proposed', iconSize: [20, 20], iconAnchor: [10, 10] });
    var retiredIcon  = L.divIcon({ className: 'tp-pin tp-pin--retired',  iconSize: [20, 20], iconAnchor: [10, 10] });
    var treasureLayer = L.layerGroup().addTo(mapa);

    function rysujIstniejace() {
        treasureLayer.clearLayers();
        existingTreasures.forEach(function (t) {
            var icon = t.status === 'RETIRED' ? retiredIcon
                     : t.status === 'PROPOSED' ? proposedIcon
                     : treasureIcon;
            var marker = L.marker([t.lat, t.lon], { icon: icon, interactive: true });
            marker.bindTooltip(t.name + (t.cat ? ' · ' + t.cat : ''), { direction: 'top', offset: [0, -10] });
            treasureLayer.addLayer(marker);
        });
    }
    rysujIstniejace();

    var layerBox = document.getElementById('tpLayersApp');
    if (layerBox) {
        layerBox.addEventListener('change', function (e) {
            var cb = e.target.closest('[data-layer]');
            if (!cb) return;
            // WŁASNA warstwa ekranu (antyduplikat) kontra warstwy ze słownika:
            // pierwsza to zwykła grupa Leafletu tej strony, pozostałe obsługuje
            // silnik. Jeden przełącznik, dwa adresaty — bo tak wygląda decyzja
            // „kontrolka wspólna, rysowanie własne zostaje".
            if (cb.dataset.layer === 'treasures') {
                cb.checked ? mapa.addLayer(treasureLayer) : mapa.removeLayer(treasureLayer);
            } else if (typeof mapa.ridemoreSetLayer === 'function') {
                mapa.ridemoreSetLayer(cb.dataset.layer, cb.checked);
            }
        });
    }

    // WARSTWY KONTEKSTOWE RYSUJE TEN SAM SILNIK CO WSZĘDZIE (Etap 4
    // warstwy-mapy.md). Silnik dostaje ISTNIEJĄCĄ mapę (`map: mapa`), więc nie
    // tworzy drugiej i nie rusza pinezki ani obsługi kliknięcia — dokładnie ten
    // sam układ, co w panelu skarbów w adminie, gdzie klikanie w mapę też
    // stawia punkt. Warstwa „Skarby" silnika jest wyłączona (nie ma jej nawet
    // w drzewie z kontrolera): istniejące skarby rysuje ten ekran sam.
    if (typeof ridemoreDiscoveryMap === 'function' && typeof ridemoreReadLayers === 'function' && layerBox) {
        ridemoreDiscoveryMap(document.getElementById('zgloszenieMapa'), {
            map: mapa,
            context: 'all',
            endpoint: <?= json_encode(Utils\View::url('/api/discovery/cells'), JSON_UNESCAPED_SLASHES) ?>,
            sources: <?= json_encode($mapSources ?? [], JSON_UNESCAPED_SLASHES) ?>,
            fitToCells: false,
            layers: ridemoreReadLayers(layerBox)
        });
    }


    // PRZY OSIĄGNIĘTYM LIMICIE formularza w ogóle nie ma w dokumencie (patrz
    // bramka $limitOsiagniety wyżej), więc wszystko poniżej — pinezka, GPS,
    // aparat, walidacja — nie ma na czym pracować. Mapa i warstwa istniejących
    // skarbów zostają w pełni sprawne; kończymy dokładnie tutaj, zamiast
    // rozsypywać `if`-y po całej reszcie pliku.
    if (!poleLat) { return; }

    // PIN ZGŁOSZENIA.
    var pinezka = null;
    var pinezkaIcon = L.divIcon({ className: 'tp-pin tp-pin--new', iconSize: [24, 24], iconAnchor: [12, 12] });
    var ustaw = function (lat, lon, skad) {
        poleLat.value = lat.toFixed(5);
        poleLon.value = lon.toFixed(5);
        podpis.textContent = lat.toFixed(5) + ', ' + lon.toFixed(5) + ' · ' + skad;
        if (pinezka) { mapa.removeLayer(pinezka); }
        pinezka = L.marker([lat, lon], { draggable: true, icon: pinezkaIcon }).addTo(mapa);
        pinezka.on('dragend', function () {
            var p = pinezka.getLatLng();
            poleLat.value = p.lat.toFixed(5);
            poleLon.value = p.lng.toFixed(5);
            podpis.textContent = p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) + __(' · poprawione ręcznie');
        });
        otworzSzufladę();
    };

    mapa.on('click', function (e) { ustaw(e.latlng.lat, e.latlng.lng, __('z mapy')); });


    // POBRANIE POZYCJI — jedna funkcja dla automatu przy wejściu i dla przycisku
    // „Moja lokalizacja" (kontrakt tasks/done/zglos-skarb-w-terenie.md).
    //
    // Różni je WYŁĄCZNIE zachowanie przy niepowodzeniu. Przycisk to świadome
    // pytanie użytkownika, więc odpowiedź „nie wyszło" należy mu się wprost.
    // Automat pytania nie zadał — gdyby wpisywał błąd w podpis, każdy, kto
    // wchodzi na ten ekran bez zgody na lokalizację, witany byłby komunikatem
    // o błędzie zamiast instrukcją, co zrobić. Dlatego automat po cichu
    // zostawia ekran w stanie wyjściowym: mapa Polski i „Stuknij w mapę".
    function pobierzPozycje(auto) {
        // MOST MOŻE JESZCZE NIE ISTNIEĆ. `native.js` idzie z layoutu z atrybutem
        // `defer`, czyli wykonuje się DOPIERO po sparsowaniu dokumentu, a ten
        // skrypt stoi w treści i rusza w trakcie parsowania. Przy kliknięciu
        // w przycisk to obojętne (user klika długo później), ale automat niżej
        // startuje natychmiast — bez tej bramki wywaliłby się na `RM undefined`
        // i zabrał ze sobą całą resztę tego IIFE, łącznie z obsługą mapy.
        if (!window.RM || !RM.native) { return; }
        // AUTOMAT bierze pozycję, którą system ma już w ręku (`maximumAge`),
        // żeby pinezka pojawiła się natychmiast; PRZYCISK pyta o świeżą
        // i dokładną, bo to świadome „jestem dokładnie TU". Różnica jest
        // celowa: automat ma zapełnić ekran, przycisk ma trafić w punkt.
        RM.native.position(auto
            ? { highAccuracy: true, maximumAge: 60000, timeout: 30000 }
            : { highAccuracy: true, maximumAge: 0, timeout: 30000 }
        ).then(function (poz) {
            // Automat nie nadpisuje pinezki, którą user zdążył już postawić
            // palcem — GPS bywa wolny, a jego odpowiedź nie może cofać
            // świadomej decyzji człowieka.
            if (auto && poleLat.value) { return; }
            ustaw(poz.lat, poz.lon, auto ? __('Twoja lokalizacja · popraw, jeśli stoisz obok') : __('z lokalizacji'));
            mapa.setView([poz.lat, poz.lon], auto ? 17 : 16);
        }).catch(function (err) {
            // Automat nie zadał pytania, więc nie wypisuje błędu — oddaje
            // ekran do stanu, w którym user wie, co zrobić ręcznie. Bez tego
            // podpis zostałby na „Szukam Twojej lokalizacji…" na zawsze przy
            // odmowie zgody albo pod dachem.
            podpis.textContent = auto
                ? __('Stuknij w mapę, żeby wskazać punkt.')
                : err.message;
        });
    }

    document.getElementById('zgGps').addEventListener('click', function () { pobierzPozycje(false); });

    // WEJŚCIE NA EKRAN = OD RAZU GPS. User stoi przy obiekcie; kazanie mu
    // najpierw znaleźć siebie na mapie kraju to trzy gesty za dużo. Pinezka
    // zostaje przeciągalna, a tap w mapę nadal działa — automat jest SKRÓTEM,
    // nie odebraniem kontroli.
    //
    // PO DOMContentLoaded, nie od razu: skrypty z `defer` (w tym `native.js`
    // z mostem `RM`) wykonują się PRZED tym zdarzeniem, ale PO sparsowaniu
    // treści — czyli po tym miejscu w pliku. Odpalenie GPS-a natychmiast
    // trafiłoby w `RM`, którego jeszcze nie ma.
    function wejscie() {
        // SZUFLADA OTWIERA SIĘ OD RAZU, JESZCZE PRZED GPS-em (poprawka
        // 2026-08-29 po zgłoszeniu usera: „widzę przez chwilę mapę, a później
        // dopiero formularz — po złapaniu lokalizacji").
        //
        // Wcześniej otwierało ją dopiero `ustaw()`, czyli pierwsza pozycja.
        // Na telefonie GPS łapie od ułamka sekundy do kilkunastu, więc ekran
        // najpierw pokazywał samą mapę, a potem — bez żadnego działania
        // użytkownika — wjeżdżał formularz i przykrywał to, na co user
        // właśnie patrzył. Skok układu bez przyczyny widocznej dla człowieka
        // czyta się jak usterka, nawet gdy jest zaplanowany.
        //
        // Teraz układ jest od pierwszej klatki taki sam, a GPS zmienia
        // WYŁĄCZNIE treść podpisu i stawia pinezkę. Nic się nie przesuwa.
        if (!window.RM || !RM.native) { return; }
        otworzSzufladę();
        podpis.textContent = 'Szukam Twojej lokalizacji…';
        pobierzPozycje(true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wejscie);
    } else {
        wejscie();
    }

    // MINIATURA ZROBIONEGO ZDJĘCIA — jedyne potwierdzenie, że aparat faktycznie
    // coś oddał. Bez niej user nie ma jak odróżnić „zdjęcie w formularzu" od
    // „aparat się zamknął i nic nie wrócił", bo <input type=file> w apce nie
    // pokazuje nazwy pliku w sposób czytelny na wąskim ekranie.
    var poleZdjecia = document.getElementById('tpPhoto');
    var miniatura = document.getElementById('tpPhotoThumb');
    poleZdjecia.addEventListener('change', function () {
        var plik = poleZdjecia.files && poleZdjecia.files[0];
        if (!plik) { miniatura.hidden = true; return; }
        // ObjectURL, nie FileReader: zdjęcie z aparatu telefonu potrafi mieć
        // kilka MB, a base64 z FileReadera trafiłby w całości do pamięci
        // i do atrybutu src. Zwalniamy poprzedni przy każdej podmianie.
        if (miniatura.dataset.url) { URL.revokeObjectURL(miniatura.dataset.url); }
        var url = URL.createObjectURL(plik);
        miniatura.dataset.url = url;
        miniatura.src = url;
        miniatura.hidden = false;
    });

    // Walidacja ukrytych pól — bez sensu przy zwiniętej szufladzie (przycisk
    // „Zgłoś to miejsce" jest wtedy niewidoczny/nieklikalny, patrz style.css
    // `.disc-panel:not(.is-open)`), ale zostaje na wypadek, gdyby user otworzył
    // szufladę ręcznie uchwytem i wysłał formularz bez pinezki.
    document.getElementById('tpFormApp').addEventListener('submit', function (e) {
        if (!poleLat.value || !poleLon.value) {
            e.preventDefault();
            podpis.textContent = __('Najpierw wskaż miejsce na mapie.');
        }
    });
})();
</script>
