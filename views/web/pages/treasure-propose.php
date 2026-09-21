<?php
// views/web/pages/treasure-propose.php
// ZGŁOŚ SKARB — formularz rowerzysty (Etap 8D, SKA/4).
//
// UKŁAD HORYZONTALNY (2026-08-25): mapa po lewej, formularz po prawej.
// Wzorzec: panel admina skarbów (.tr-work). Rowerzysta wie GDZIE, a nie wie
// jak to nazwać — klik w mapę jest pierwszym krokiem, a formularz obok pozwala
// opisać miejsce od razu.
//
// Wyszukiwarka miejsc jest W formularzu (pomoc), nie nad mapą — żeby nie
// zajmować miejsca nad mapą i żeby była bliżej pól opisowych.
//
// Warstwa istniejących skarbów na mapie zapobiega zgłaszaniu duplikatów.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
?>

<div class="op-head">
    <div class="op-head__c">
        <h1><?= __('Zgłoś skarb') ?></h1>
        <p class="op-head__sub"><?= __('Znasz miejsce, do którego warto podjechać? Pokaż je na mapie. Punkt pojawi się jako zgłoszenie — stanie się skarbem, gdy potwierdzą go {n} osoby, które tam dojadą.', ['n' => (int) $needed]) ?></p>
    </div>
</div>

<?php if ($info): ?>
<div class="box" style="margin-top:14px;border-color:var(--accent);"><b><?= htmlspecialchars($info) ?></b></div>
<?php endif; ?>

<section class="sec">
    <div class="tr-work tp-work">
        <?php // MAPA — lewa kolumna. ?>
        <div class="tr-work__map tp-work__map" style="position:relative;">
            <div id="zgloszenieMapa" style="height:100%;min-height:480px;"></div>
            <?php // KONTROLKA WARSTW — ten sam partial co na /odkrycia. ?>
            <?php
            $mlId = 'tpLayers';
            // Warstwy kontekstowe ze słownika + własna warstwa antyduplikatowa
            // tego ekranu — to samo, co w wariancie apki (Etap 4 warstwy-mapy.md).
            $mlLayers = array_merge($mapLayers ?? [], [
                ['key' => 'treasures', 'label' => __('Istniejące skarby'), 'hint' => __('żebym nie zgłosił dubla'), 'on' => true],
            ]);
            require __DIR__ . '/../partials/map-layers.php';
            ?>
        </div>

        <?php // FORMULARZ — prawa kolumna. ?>
        <div class="tr-work__side tp-work__side">
            <form class="tr-form tp-form" method="post" action="<?= View::url('/skarby/zglos') ?>">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="lat" id="zgLat" value="">
                <input type="hidden" name="lon" id="zgLon" value="">

                <?php // WYSZUKIWARKA MIEJSC — w formularzu jako pomoc. ?>
                <div class="tp-search">
                    <label class="form-field-label" for="zgSzukaj"><?= __('Szukaj miejsca') ?></label>
                    <input class="search-input" id="zgSzukaj" type="search" autocomplete="off"
                           placeholder="<?= htmlspecialchars(__('Wpisz miejscowość albo nazwę…')) ?>">
                    <ul class="tr-found" id="zgWyniki" hidden></ul>
                    <p class="tp-search__hint"><?= __('Podpowiedzi przybliżą mapę. Klik w mapę stawia pinezkę.') ?></p>
                </div>

                <?php // STATUS MIEJSCA ?>
                <p class="eyebrow" style="margin-top:12px;"><?= __('Miejsce') ?></p>
                <p id="zgPozycja" class="desc" style="margin:0 0 12px;"><?= __('Jeszcze nie wskazane.') ?></p>

                <?php // NAZWA ?>
                <label class="form-field-label" for="zgNazwa"><?= __('Nazwa') ?></label>
                <input class="search-input" id="zgNazwa" name="name" maxlength="160" required
                       placeholder="<?= htmlspecialchars(__('np. Kapliczka na Przełęczy Krowiarki')) ?>">

                <?php // KATEGORIA ?>
                <label class="form-field-label" for="zgKat"><?= __('Co to jest') ?></label>
                <select class="search-input" id="zgKat" name="category_item_id">
                    <option value=""><?= __('— wybierz —') ?></option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <?php // UJAWNIENIE NA MAPIE — pod kategorią ?>
                <label class="form-field-label" for="zgReveal"><?= __('Ujawnienie na mapie') ?></label>
                <select class="search-input" id="zgReveal" name="reveal_level">
                    <option value="2" selected><?= __('Jawny — pinezka od razu') ?></option>
                    <option value="1"><?= __('Trop — wskazówka po odkryciu pola') ?></option>
                    <option value="0"><?= __('Ukryty — znak zapytania w polu') ?></option>
                </select>

                <?php // DLACZEGO WARTO — wiekszy textarea ?>
                <div class="tp-why">
                    <label class="form-field-label" for="zgOpis"><?= __('Dlaczego warto tu podjechać?') ?></label>
                    <textarea class="search-input" id="zgOpis" name="description" rows="4" maxlength="600"
                              placeholder="<?= htmlspecialchars(__('Widok na trzy doliny, ławka i źródełko po drugiej stronie drogi.')) ?>"></textarea>
                </div>

                <?php // AKCJE ?>
                <div class="tp-actions">
                    <button type="submit" class="btn"><?= __('Zgłoś to miejsce') ?></button>
                    <button type="button" class="btn btn-secondary" id="zgGps">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4"/></svg>
                        <?= __('Moja lokalizacja') ?>

                    </button>
                </div>
            </form>
        </div>
    </div>
</section>

<?php if ($mine): ?>
<section class="sec">
    <div class="sec-head">
        <h2><?= __('Twoje zgłoszenia w poczekalni') ?></h2>
        <span><?= count($mine) ?></span>
    </div>
    <div class="box" style="padding:0;overflow-x:auto;">
        <table class="adm-table">
            <thead><tr><th><?= __('Punkt') ?></th><th><?= __('Potwierdzenia') ?></th></tr></thead>
            <tbody>
            <?php foreach ($mine as $t): ?>
            <tr>
                <td><b><?= htmlspecialchars($t['name']) ?></b>
                    <?php if ($t['category_label']): ?>
                    <div class="desc"><?= htmlspecialchars($t['category_label']) ?></div>
                    <?php endif; ?>
                </td>
                <td><b><?= (int) $t['confirmations'] ?></b> z <?= (int) $needed ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="desc" style="margin:8px 0 0;"><?= __('Potwierdzenia zbierają się same — wystarczy,
        że ktoś tamtędy przejedzie i kliknie w punkt na mapie.') ?></p>
</section>
<?php endif; ?>

<script>
// Wskazanie miejsca. Mapa po lewej, formularz po prawej — pinezka i opis
// w formularzu aktualizują się jednocześnie.
(function () {
    var poleLat = document.getElementById('zgLat');
    var poleLon = document.getElementById('zgLon');
    var podpis = document.getElementById('zgPozycja');

    // ridemoreCreateMap zamiast L.map — ta sama fabryka co wszędzie w serwisie
    // (daje pełny ekran, spójne ikony, tę samą konfigurację kafelków).
    var mapa = ridemoreCreateMap(document.getElementById('zgloszenieMapa'), [52.0, 19.4]);

    // WARSTWA ISTNIEJĄCYCH SKARBÓW — zarządzana przez map-layers.php.
    var existingTreasures = <?= json_encode($treasurePins ?? [], JSON_UNESCAPED_UNICODE) ?>;
    var treasureIcon = L.divIcon({ className: 'tp-pin tp-pin--existing', iconSize: [20, 20], iconAnchor: [10, 10] });
    var proposedIcon = L.divIcon({ className: 'tp-pin tp-pin--proposed', iconSize: [20, 20], iconAnchor: [10, 10] });
    var retiredIcon  = L.divIcon({ className: 'tp-pin tp-pin--retired',  iconSize: [20, 20], iconAnchor: [10, 10] });
    var treasureLayer = L.layerGroup().addTo(mapa);

    function rysujIstniejace() {
        // Bez progu zoomu: ~100 pinezek Leaflet rysuje bez wysiłku na każdym
        // oddaleniu, a próg oznaczałby pustą mapę przy starcie (kadr startowy
        // to cała Polska, zoom 6) — wyglądało to jak niedziałające ładowanie.
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

    // PRZEŁĄCZANIE WARSTW — checkbox z map-layers.php → warstwa Leaflet.
    var layerBox = document.getElementById('tpLayers');
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


    // PIN ZGŁOSZENIA — przeciągalna, zielona, wyróżniona.
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
    };

    mapa.on('click', function (e) { ustaw(e.latlng.lat, e.latlng.lng, __('z mapy')); });

    // WYSZUKIWARKA MIEJSC (Nominatim) — w formularzu jako pomoc.
    var poleSzukania = document.getElementById('zgSzukaj');
    var wyniki = document.getElementById('zgWyniki');
    var token = 0;
    var timerSzukania = null;

    poleSzukania.addEventListener('input', function () {
        if (timerSzukania) { clearTimeout(timerSzukania); }
        var q = poleSzukania.value.trim();
        if (q.length < 3) { wyniki.hidden = true; return; }
        timerSzukania = setTimeout(function () { szukaj(q); }, 400);
    });

    function szukaj(q) {
        var moj = ++token;
        fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=6&countrycodes=pl&q='
              + encodeURIComponent(q), { headers: { 'Accept-Language': 'pl' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .catch(function () { return []; })
            .then(function (data) {
                if (moj !== token) { return; }
                wyniki.innerHTML = '';
                if (!data.length) { wyniki.hidden = true; return; }
                data.forEach(function (r) {
                    var li = document.createElement('li');
                    li.textContent = r.display_name;
                    li.addEventListener('click', function () {
                        mapa.setView([parseFloat(r.lat), parseFloat(r.lon)], 15);
                        wyniki.hidden = true;
                        poleSzukania.value = '';
                        if (!poleLat.value) {
                            podpis.textContent = __('Kliknij w mapę, żeby wskazać punkt.');
                        }
                    });
                    wyniki.appendChild(li);
                });
                wyniki.hidden = false;
            });
    }

    document.getElementById('zgGps').addEventListener('click', function () {
        RM.native.position().then(function (poz) {
            ustaw(poz.lat, poz.lon, __('z lokalizacji'));
            mapa.setView([poz.lat, poz.lon], 16);
        }).catch(function (err) { podpis.textContent = err.message; });
    });

    // Walidacja ukrytych pól — formularz nie może wyjechać bez współrzędnych.
    document.querySelector('form[action$="zglos"]').addEventListener('submit', function (e) {
        if (!poleLat.value || !poleLon.value) {
            e.preventDefault();
            podpis.textContent = __('Najpierw wskaż miejsce na mapie.');
            document.getElementById('zgloszenieMapa').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();
</script>
