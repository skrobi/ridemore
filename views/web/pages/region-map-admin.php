<?php
// views/web/pages/region-map-admin.php
// NARZĘDZIE „REGIONY NA MAPIE" — dodawanie regionów do słownika i rysowanie
// ich OBRYSÓW dla krajów, których podziału nie da się zaimportować z granic
// administracyjnych.
//
// Oczekuje: $regions (lista pozycji słownika: kolor, liczba heksów, stan
// geometrii), $sizeM (rozmiar pola siatki rysowania w metrach Mercatora),
// $sizeMCell (rozmiar POJEDYNCZEGO pola poprawki, ten sam co discovery_cells),
// $info, $error. Geometria NIE idzie tędy — ekran pobiera ją osobno
// z `/admin/regiony-mapa/geometria` (ok. 150 kB współrzędnych).
//
// TRYB POPRAWKI POJEDYNCZEGO POLA (2026-09-10, świadoma decyzja usera —
// dotąd województwa były tu tylko do podglądu). Osobny od trybu rysowania:
// nie dotyka geometrii/pliku, pisze wprost do region_cells przez
// `/admin/regiony-mapa/pole` (patrz `Models\RegionOutline::assignCell()`,
// gdzie jest wyjaśnione, czemu to MUSI być osobna droga, a nie mały obrys
// pod tym samym kodem). Działa na KAŻDYM regionie, także z importu.
//
// ZASADA: KLIKASZ HEKSY WZDŁUŻ GRANICY, NIE WYPEŁNIASZ ŚRODKA. Wypełnienie
// pojawia się samo, gdy obrys ma co najmniej trzy pola — jest podglądem tego,
// co obejmie zapisany wielokąt. Kliknięcie w heks, który już jest w obrysie,
// zdejmuje go: to samo narzędzie dodaje i poprawia.
//
// TRZY RZECZY, KTÓRYCH BRAK ZGŁOSIŁ USER 2026-09-01 („oznaczając np. lubelskie
// powinno mi się zaznaczyć, bo nie widać (…) w jaki sposób mam zobaczyć, że
// któryś z heksów jest białą plamą"):
//   1. WSZYSTKIE regiony są na mapie od wejścia — także województwa z importu.
//      Bez tego nowy obrys rysuje się na ślepo, obok czegoś, czego nie widać.
//   2. BIAŁE PLAMY: pola siatki, które nie należą do żadnego regionu, dostają
//      czerwony obrys i licznik. To jest odpowiedź na „co mam nie pokryte".
//   3. KONFLIKT NA ŻYWO: wchodząc obrysem na cudzy teren, widzisz czyj i ile
//      pól zabierasz — jeszcze przed zapisem.
//
// PIERWSZEŃSTWO MA EDYTOWANY (decyzja usera). Nie przycinamy cudzych
// wielokątów — sporne heksy rozstrzyga import po `priority` (patrz
// `Models\RegionOutline`), a tutaj mówimy o tym wprost, zamiast zostawiać
// niespodziankę na później.
//
// DLACZEGO TA MAPA NIE JEST `ridemoreDiscoveryMap`. Wspólna mapa odkryć
// odpowiada na pytanie „gdzie kto był" — ma mgłę, heatmapę, skarby i trasy.
// Tutaj nie ma ani jednej z tych warstw. Wspólne zostaje to, co naprawdę jest
// wspólne: `ridemoreCreateMap` (ten sam podkład, pełny ekran, limity zoomu)
// i `ridemoreHexGrid` (te same wzory siatki co mgła).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\View;

$regions   = $regions ?? [];
$sizeM     = $sizeM ?? 7500.0;
$sizeMCell = $sizeMCell ?? 500.0;

$parents = array_values(array_filter($regions, static fn(array $r): bool => $r['hasChildren'] || $r['depth'] === 0));
$selected = ctype_digit((string) ($_GET['region'] ?? '')) ? (int) $_GET['region'] : null;

$jsRegions = [];
foreach ($regions as $r) {
    $jsRegions[$r['id']] = [
        'code'     => $r['code'],
        'name'     => $r['name'],
        'color'    => $r['color'],
        'leaf'     => !$r['hasChildren'],
        'geometry' => $r['geometry'],
    ];
}

$etykiety = ['brak' => 'bez geometrii', 'obrys' => 'obrys narysowany', 'import' => 'granice z importu'];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display">Regiony na mapie</h1>
<p class="desc spaced-below">
    Dodaj region, a potem <strong>kliknij heksy wzdłuż jego granicy</strong> — środek wypełni się sam.
    Obrys zapisuje się do <code>data/regiony.geojson</code>, czyli w tym samym formacie, z którego
    aplikacja czyta granice województw. Pokrycie heksami (procent odkrycia, region skarbu, region
    trasy) buduje z tego pliku <code>backfill_regions.php</code> — patrz ramka na dole.
</p>
<p class="desc spaced-below">
    <strong>Popraw pojedyncze pole</strong> to inny tryb: bez rysowania granic, klikasz JEDEN heks
    (poziom ~<?= number_format($sizeMCell, 0, ',', ' ') ?> m — ten sam co mapa odkryć) i przypisujesz go
    wprost do wybranego regionu z listy, z pominięciem obrysu i importu. Działa też na województwach —
    do poprawiania pojedynczych, źle przypisanych pól przy granicy, zapisuje się od razu, bez
    <code>backfill_regions.php</code>.
</p>

<?php if ($error): ?>
<p class="form-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
<?php if ($info): ?>
<p class="form-success"><?= htmlspecialchars($info) ?></p>
<?php endif; ?>

<div class="regmap">
    <aside class="regmap__side">
        <div class="regmap__tools">
            <div class="regmap__toolrow">
                <label><input type="checkbox" id="regmapRysuj"> <strong>Tryb rysowania</strong></label>
            </div>
            <div class="regmap__toolrow">
                <?php // Osobny od rysowania — nie dotyka pliku obrysów, działa
                      // też na województwach z importu. Patrz nota na górze pliku. ?>
                <label><input type="checkbox" id="regmapPoprawka"> <strong>Popraw pojedyncze pole</strong></label>
                <small id="regmapPoprawkaInfo" hidden>przybliż, żeby zobaczyć pola</small>
            </div>
            <div class="regmap__toolrow">
                <label><input type="checkbox" id="regmapSiatka" checked> Siatka</label>
                <label><input type="checkbox" id="regmapDziury" checked> Białe plamy</label>
                <small id="regmapSiatkaInfo" hidden>przybliż, żeby zobaczyć siatkę</small>
            </div>
            <div class="regmap__toolrow regmap__buttons">
                <button type="button" class="btn btn-secondary" id="regmapCofnij" disabled>Cofnij punkt</button>
                <button type="button" class="btn btn-secondary" id="regmapWyczysc" disabled>Wyczyść</button>
                <button type="button" class="btn btn-primary" id="regmapZapisz" disabled>Zapisz obrys</button>
            </div>
            <p class="regmap__hint" id="regmapStan">Wybierz region z listy, włącz tryb rysowania i klikaj wzdłuż granicy.</p>
        </div>

        <div class="regmap__list">
            <?php foreach ($regions as $r): ?>
            <label class="regmap__item<?= $r['hasChildren'] ? ' is-container' : '' ?><?= $r['isActive'] ? '' : ' is-off' ?>"
                   style="padding-left: <?= 8 + $r['depth'] * 14 ?>px;">
                <input type="radio" name="regmapRegion" value="<?= (int) $r['id'] ?>"
                       <?= $r['hasChildren'] ? 'disabled' : '' ?>
                       <?= $selected === $r['id'] ? 'checked' : '' ?>>
                <span class="regmap__dot" style="background: <?= htmlspecialchars($r['color']) ?>"></span>
                <span class="regmap__name"><?= htmlspecialchars($r['name']) ?></span>
                <?php // Dwie informacje, bo znaczą co innego: geometria to plik
                      // (narysowane/zaimportowane), liczba to baza (pokrycie
                      // heksami). Rozjazd między nimi JEST sygnałem „puść backfill". ?>
                <span class="regmap__nums">
                    <span class="regmap__flag is-<?= $r['geometry'] ?>"
                          title="<?= htmlspecialchars($etykiety[$r['geometry']] ?? '') ?>"><?= $r['geometry'] === 'brak' ? '—' : ($r['geometry'] === 'import' ? 'import' : 'obrys') ?></span>
                    <span title="heksy pokrycia w bazie (region_cell_counts)"><?= number_format($r['cells'], 0, ',', ' ') ?></span>
                </span>
            </label>
            <?php endforeach; ?>
        </div>

        <form method="post" action="<?= View::url('/admin/regiony-mapa/usun-obrys') ?>"
              onsubmit="return confirm('Usunąć obrys tego regionu z pliku? Heksy, które ma już w bazie, zostaną — żeby je cofnąć, trzeba puścić backfill_regions.php --rebuild.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="region" id="regmapUsunRegion" value="">
            <button type="submit" class="btn btn-secondary" id="regmapUsun" disabled>Usuń obrys</button>
        </form>

        <details class="regmap__add">
            <summary class="btn btn-secondary">Dodaj region / kraj</summary>
            <form class="auth-form" method="post" action="<?= View::url('/admin/regiony-mapa/dodaj') ?>">
                <?= Csrf::field() ?>
                <label>Nazwa
                    <input class="search-input" type="text" name="name" maxlength="128" required placeholder="np. Czechy Północne">
                </label>
                <label>Kod
                    <input class="search-input" type="text" name="code" maxlength="64" required
                           pattern="[a-z0-9_]+" placeholder="np. czechy_polnocne">
                </label>
                <label>Kraj nadrzędny
                    <select class="search-input" name="parent_id">
                        <option value="">— brak (to jest kraj) —</option>
                        <?php foreach ($parents as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">Dodaj</button>
            </form>
        </details>
    </aside>

    <div class="regmap__mapbox">
        <div id="regmapMap" class="regmap__map"></div>
        <div class="regmap__legend">
            <span class="regmap__legend-title">Pola bez regionu w kadrze:</span>
            <strong id="regmapDziuryLiczba">—</strong>
        </div>
    </div>
</div>

<div class="card regmap__import">
    <strong>Po narysowaniu obrysów zbuduj pokrycie:</strong>
    <code>php backfill_regions.php</code>
    <p class="desc">
        Import puszcza jeden przebieg na WSZYSTKICH plikach naraz (województwa + narysowane obrysy) —
        inaczej pas wzdłuż granicy PL/CZ zostawałby bez regionu. Sporne heksy dostaje region zapisany
        NAJPÓŹNIEJ (pierwszeństwo ma edytowany), więc „zabieranie" sąsiadowi dzieje się dopiero tutaj.
        Dodaj <code>--rebuild</code>, jeśli obrys został zmieniony albo usunięty: bez tego stare
        przypisania heksów zostają w bazie.
    </p>
</div>

<script>
(function () {
    var el = document.getElementById('regmapMap');
    if (!el || typeof ridemoreCreateMap !== 'function' || !window.ridemoreHexGrid) { return; }

    var SIZE = <?= json_encode((float) $sizeM) ?>;
    // Poziom poprawki pojedynczego pola — ten sam co discovery_cells/region_cells,
    // dużo mniejszy niż pole obrysu (SIZE wyżej).
    var SIZE_CELL = <?= json_encode((float) $sizeMCell) ?>;
    var CSRF = <?= json_encode(Csrf::token()) ?>;
    var REGIONY = <?= json_encode($jsRegions, JSON_UNESCAPED_UNICODE) ?>;
    var URL_GEO = <?= json_encode(View::url('/admin/regiony-mapa/geometria')) ?>;
    var URL_ZAPISZ = <?= json_encode(View::url('/admin/regiony-mapa/zapisz')) ?>;
    var URL_POLE = <?= json_encode(View::url('/admin/regiony-mapa/pole')) ?>;

    // PRÓG CZYTELNOŚCI NIŻSZY NIŻ NA MGLE (34 px). Mgła rysuje siatkę dla
    // kogoś, kto szuka sąsiedniego pola w terenie; tutaj patrzy się na kraj
    // z góry i pole ma prawo mieć 6 px — inaczej granicy Czech nie dałoby się
    // obrysować inaczej niż odcinkami wielkości gminy.
    //
    // LIMIT 12 000 PÓL, a nie 1 500 jak mgła, BO SIATKA IDZIE NA KANWĘ.
    // Zmierzone: przy oddaleniu 7 w kadr wchodzi ok. 8 200 pól — w domyślnym
    // rendererze SVG to tyle samo elementów DOM i mapa staje.
    var SIATKA = { minPx: 6, max: 12000 };

    // KADR STARTOWY: oddalenie 8, czyli pierwsze, przy którym pole ma na
    // ekranie ok. 20 px. setView z `animate: false`, a NIE setZoom(): setZoom
    // uruchamia animację, a ta na mapie, która jeszcze się układa, potrafi nie
    // dojść do skutku i zostawić kadr na domyślnej szóstce (zmierzone).
    var map = ridemoreCreateMap(el, [49.6, 16.5]);
    map.setView([49.6, 16.5], 8, { animate: false });
    var warstwa = L.layerGroup().addTo(map);
    var kanwa = L.canvas({ padding: 0.2 });

    var GEO = {};      // kod => {name, rings, editable, priority, bbox}
    var obrys = [];    // [[q, r], ...] W KOLEJNOŚCI KLIKNIĘĆ — kolejność JEST daną
    var brudny = false;
    // Pola poprawione W TEJ SESJI, na poziomie SIZE_CELL — czysto wizualne,
    // znika po przeładowaniu strony (region_cells jest źródłem prawdy, nie to).
    // Klucz "q:r" => kod regionu, do którego pole trafiło.
    var poprawki = {};

    function region() {
        var w = document.querySelector('input[name="regmapRegion"]:checked');
        return w ? REGIONY[w.value] : null;
    }
    function regionId() {
        var w = document.querySelector('input[name="regmapRegion"]:checked');
        return w ? w.value : null;
    }
    function kolorKodu(code) {
        for (var id in REGIONY) { if (REGIONY[id].code === code) { return REGIONY[id].color; } }
        return '#64748b';
    }
    function nazwaKodu(code) {
        return (GEO[code] && GEO[code].name) || code;
    }
    function stan(tekst) { document.getElementById('regmapStan').textContent = tekst; }
    function klucz(ax) { return ax[0] + ':' + ax[1]; }
    function srodek(ax) { return window.ridemoreHexGrid.center(ax[0], ax[1], SIZE); }

    // ---------------------------------------------------------------
    // Geometria — ten sam test „w środku" co w PHP (RegionOutline::insideRings)
    // ---------------------------------------------------------------
    // Even-odd po WSZYSTKICH pierścieniach: punkt w nieparzystej liczbie
    // pierścieni jest w środku. Ta sama semantyka co w backfillu, więc
    // „biała plama" na ekranie znaczy dokładnie to samo, co brak przypisania
    // przy imporcie.
    function wPierscieniach(rings, lat, lon) {
        var razem = 0;
        for (var k = 0; k < rings.length; k++) {
            var ring = rings[k], n = ring.length;
            for (var i = 0, j = n - 1; i < n; j = i++) {
                var a1 = ring[j][0], b1 = ring[j][1], a2 = ring[i][0], b2 = ring[i][1];
                if ((b1 > lon) !== (b2 > lon) && lat < (a2 - a1) * (lon - b1) / (b2 - b1) + a1) { razem++; }
            }
        }
        return razem % 2 === 1;
    }

    function bboxOf(rings) {
        var b = { s: Infinity, n: -Infinity, w: Infinity, e: -Infinity };
        rings.forEach(function (ring) {
            ring.forEach(function (p) {
                if (p[0] < b.s) { b.s = p[0]; }
                if (p[0] > b.n) { b.n = p[0]; }
                if (p[1] < b.w) { b.w = p[1]; }
                if (p[1] > b.e) { b.e = p[1]; }
            });
        });
        return b;
    }

    /** Kod regionu zawierającego punkt, albo null. Odsiew po prostokącie
     *  otaczającym robi tu całą robotę wydajnościową: bez niego każde pole
     *  siatki testowałoby się z 8 000 odcinków granic województw. */
    function regionPunktu(lat, lon, pomin) {
        for (var code in GEO) {
            if (code === pomin) { continue; }
            var g = GEO[code];
            if (lat < g.bbox.s || lat > g.bbox.n || lon < g.bbox.w || lon > g.bbox.e) { continue; }
            if (wPierscieniach(g.rings, lat, lon)) { return code; }
        }
        return null;
    }

    /** Pola poziomu obrysu leżące wewnątrz rysowanego pierścienia (z polami
     *  samego obrysu — ich środki leżą na granicy, a należą do regionu
     *  bezspornie). Zakres liczony w OSIACH: pole wewnątrz pętli ma q i r
     *  w przedziale wyznaczonym przez pola pętli. */
    function polaWObrysie() {
        if (obrys.length < 3) { return obrys.slice(); }
        var qs = obrys.map(function (a) { return a[0]; });
        var rs = obrys.map(function (a) { return a[1]; });
        var qMin = Math.min.apply(null, qs), qMax = Math.max.apply(null, qs);
        var rMin = Math.min.apply(null, rs), rMax = Math.max.apply(null, rs);
        var ring = obrys.map(srodek);
        var mam = {};
        obrys.forEach(function (a) { mam[klucz(a)] = a; });
        for (var q = qMin; q <= qMax; q++) {
            for (var r = rMin; r <= rMax; r++) {
                var k = q + ':' + r;
                if (mam[k]) { continue; }
                var s = window.ridemoreHexGrid.center(q, r, SIZE);
                if (wPierscieniach([ring], s[0], s[1])) { mam[k] = [q, r]; }
            }
        }
        return Object.keys(mam).map(function (k) { return mam[k]; });
    }

    /** Ile pól zabieram komu — liczone tak samo jak po stronie serwera. */
    function konflikty() {
        var mojKod = region() ? region().code : null;
        var licznik = {};
        polaWObrysie().forEach(function (ax) {
            var s = srodek(ax);
            var code = regionPunktu(s[0], s[1], mojKod);
            if (code) { licznik[code] = (licznik[code] || 0) + 1; }
        });
        return Object.keys(licznik)
            .map(function (code) { return { code: code, cells: licznik[code] }; })
            .sort(function (a, b) { return b.cells - a.cells; });
    }

    // ---------------------------------------------------------------
    // Rysowanie
    // ---------------------------------------------------------------
    function rysuj() {
        warstwa.clearLayers();
        var mojKod = region() ? region().code : null;

        // 1. WSZYSTKIE REGIONY — od najstarszego priorytetu, żeby ten, który
        //    przy imporcie wygra sporne pola, leżał na wierzchu. Wybrany
        //    zawsze na samej górze i grubszą kreską: „oznaczając lubelskie
        //    powinno mi się zaznaczyć".
        Object.keys(GEO)
            .sort(function (a, b) { return GEO[a].priority - GEO[b].priority; })
            .forEach(function (code) {
                if (code === mojKod) { return; }
                rysujRegion(code, false);
            });
        if (mojKod && GEO[mojKod] && !obrys.length) { rysujRegion(mojKod, true); }

        // 2. OBRYS W EDYCJI — heksy jako pola, wielokąt jako podgląd
        //    wypełnienia. Wypełnienie od trzeciego pola: dopiero wtedy „koło"
        //    da się zamknąć.
        if (obrys.length) {
            var barwa = region() ? region().color : '#2563eb';
            var punkty = obrys.map(srodek);
            if (obrys.length >= 3) {
                L.polygon(punkty, {
                    color: barwa, weight: 3, opacity: 1,
                    fillColor: barwa, fillOpacity: .3, interactive: false
                }).addTo(warstwa);
            } else {
                L.polyline(punkty, { color: barwa, weight: 3, opacity: 1, interactive: false }).addTo(warstwa);
            }
            obrys.forEach(function (ax, i) {
                var s = srodek(ax);
                L.polygon(window.ridemoreHexGrid.ring(s[0], s[1], SIZE), {
                    color: barwa, weight: 1, opacity: 1, fillColor: barwa,
                    // Pierwsze pole mocniejsze: przy zamykaniu obrysu trzeba
                    // wiedzieć, gdzie się zaczęło.
                    fillOpacity: i === 0 ? .8 : .5, interactive: false
                }).addTo(warstwa);
            });
        }

        // 3. SIATKA I BIAŁE PLAMY — po siatce klika się obrys, a plamy są
        //    odpowiedzią na „co mam nie pokryte".
        var chceSiatki = document.getElementById('regmapSiatka').checked;
        var chcePlam = document.getElementById('regmapDziury').checked;
        var siatka = (chceSiatki || chcePlam) ? window.ridemoreHexGrid.lattice(map, SIZE, SIATKA) : [];
        var plam = 0;

        siatka.forEach(function (pole) {
            var pusty = regionPunktu(pole.lat, pole.lon, null) === null;
            if (pusty) { plam++; }
            if (pusty && chcePlam) {
                L.polygon(window.ridemoreHexGrid.ring(pole.lat, pole.lon, SIZE), {
                    color: '#ef4444', weight: 1, opacity: .8, fillColor: '#ef4444',
                    fillOpacity: .12, interactive: false, renderer: kanwa
                }).addTo(warstwa);
            } else if (chceSiatki) {
                L.polygon(window.ridemoreHexGrid.ring(pole.lat, pole.lon, SIZE), {
                    color: '#94a3b8', weight: 1, opacity: .35, fill: false,
                    interactive: false, renderer: kanwa
                }).addTo(warstwa);
            }
        });

        document.getElementById('regmapDziuryLiczba').textContent = siatka.length ? plam : '—';
        // Pusta siatka przy włączonym przełączniku znaczy „za daleko" — bez tej
        // informacji wygląda to na zepsuty przełącznik.
        document.getElementById('regmapSiatkaInfo').hidden = (!chceSiatki && !chcePlam) || siatka.length > 0;

        // 4. TRYB POPRAWKI — siatka DROBNA (SIZE_CELL), żeby było widać
        // dokładnie, w który heks trafi klik. Osobna od siatki obrysu wyżej:
        // przy oddaleniu potrzebnym do narysowania granicy województwa siatka
        // na tym poziomie byłaby tysiącami niewidocznych włosków.
        if (document.getElementById('regmapPoprawka').checked) {
            var drobna = window.ridemoreHexGrid.lattice(map, SIZE_CELL, { minPx: 10, max: 6000 });
            drobna.forEach(function (pole) {
                L.polygon(window.ridemoreHexGrid.ring(pole.lat, pole.lon, SIZE_CELL), {
                    color: '#0f172a', weight: 1, opacity: .3, fill: false,
                    interactive: false, renderer: kanwa
                }).addTo(warstwa);
            });
            document.getElementById('regmapPoprawkaInfo').hidden = drobna.length > 0;
        } else {
            document.getElementById('regmapPoprawkaInfo').hidden = true;
        }

        // 5. POLA POPRAWIONE W TEJ SESJI — wypełnione kolorem regionu,
        //    do którego trafiły, żeby było widać efekt bez przeładowania.
        Object.keys(poprawki).forEach(function (k) {
            var ax = k.split(':').map(Number);
            var s = window.ridemoreHexGrid.center(ax[0], ax[1], SIZE_CELL);
            L.polygon(window.ridemoreHexGrid.ring(s[0], s[1], SIZE_CELL), {
                color: kolorKodu(poprawki[k]), weight: 2, opacity: 1,
                fillColor: kolorKodu(poprawki[k]), fillOpacity: .6, interactive: false
            }).addTo(warstwa);
        });
    }

    function rysujRegion(code, wybrany) {
        var g = GEO[code];
        if (!g) { return; }
        var barwa = kolorKodu(code);
        g.rings.forEach(function (ring) {
            L.polygon(ring, {
                color: barwa,
                weight: wybrany ? 3 : 1.5,
                opacity: wybrany ? 1 : .7,
                // Import rysujemy KRESKĄ: od pierwszego spojrzenia ma być
                // widać, gdzie granica jest oficjalna, a gdzie z ręki.
                dashArray: g.editable ? null : '6 4',
                fillColor: barwa,
                fillOpacity: wybrany ? .3 : .12,
                interactive: false
            }).addTo(warstwa);
        });
    }

    // ---------------------------------------------------------------
    // Dane
    // ---------------------------------------------------------------
    function pobierzGeometrie() {
        return fetch(URL_GEO, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (dane) {
                if (!dane || !dane.regions) { return; }
                GEO = dane.regions;
                Object.keys(GEO).forEach(function (code) { GEO[code].bbox = bboxOf(GEO[code].rings); });
            });
    }

    // ---------------------------------------------------------------
    // Edycja
    // ---------------------------------------------------------------
    map.on('click', function (e) {
        if (document.getElementById('regmapPoprawka').checked) {
            poprawPole(e.latlng.lat, e.latlng.lng);
            return;
        }
        if (!document.getElementById('regmapRysuj').checked) { return; }
        var r = region();
        if (!r) { stan('Najpierw wybierz region z listy.'); return; }
        if (GEO[r.code] && !GEO[r.code].editable) {
            stan('„' + r.name + '" ma granice z importu administracyjnego — tutaj tylko podgląd.');
            return;
        }

        var ax = window.ridemoreHexGrid.cellAt(e.latlng.lat, e.latlng.lng, SIZE);
        var k = klucz(ax);
        var i = obrys.findIndex(function (p) { return klucz(p) === k; });
        // Kliknięcie w pole, które już jest w obrysie, ZDEJMUJE je — to samo
        // narzędzie dodaje i poprawia, bez osobnej gumki.
        if (i >= 0) { obrys.splice(i, 1); } else { obrys.push(ax); }

        brudny = true;
        synchronizuj();
        rysuj();
        pokazKonflikty();
    });

    // ---------------------------------------------------------------
    // Poprawka pojedynczego pola — osobna droga od rysowania obrysu, patrz
    // nota na górze pliku i Models\RegionOutline::assignCell(). Działa na
    // KAŻDYM regionie z listy, także tym z importu (bez sprawdzania
    // `editable` — to jest właśnie ta świadomie dopuszczona zdolność).
    // ---------------------------------------------------------------
    function poprawPole(lat, lon) {
        var r = region();
        if (!r) { stan('Najpierw wybierz region z listy.'); return; }

        // Środek KLIKNIĘTEGO heksa, nie surowy punkt kliknięcia — serwer i tak
        // dosuwa do siatki, ale bez tego wizualne wypełnienie (poprawki[])
        // nie trafiałoby dokładnie w hex, który serwer faktycznie zapisał.
        var ax = window.ridemoreHexGrid.cellAt(lat, lon, SIZE_CELL);
        var s = window.ridemoreHexGrid.center(ax[0], ax[1], SIZE_CELL);
        var k = klucz(ax);

        stan('Zapisywanie pola...');
        fetch(URL_POLE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, region: regionId(), lat: s[0], lon: s[1] })
        })
            .then(function (resp) { return resp.json(); })
            .catch(function () { return { error: 'Zapis się nie udał.' }; })
            .then(function (dane) {
                if (!dane || dane.error) { stan((dane && dane.error) || 'Zapis się nie udał.'); return; }
                poprawki[k] = r.code;
                rysuj();
                stan('Pole przypisane do „' + r.name + '". Liczba przy regionie na liście zaktualizuje się po odświeżeniu strony.');
            });
    }

    function pokazKonflikty() {
        if (obrys.length < 3) {
            stan('Obrys: ' + obrys.length + ' pól (od trzech zamyka się w wielokąt).');
            return;
        }
        var k = konflikty();
        if (!k.length) {
            stan('Obrys: ' + obrys.length + ' pól. Nie wchodzisz na żaden inny region.');
            return;
        }
        stan('Obrys: ' + obrys.length + ' pól. ZABIERASZ: '
            + k.map(function (c) { return nazwaKodu(c.code) + ' (' + c.cells + ')'; }).join(', ')
            + ' — przy imporcie pierwszeństwo ma region zapisany później.');
    }

    document.getElementById('regmapCofnij').addEventListener('click', function () {
        obrys.pop(); brudny = true; synchronizuj(); rysuj(); pokazKonflikty();
    });
    document.getElementById('regmapWyczysc').addEventListener('click', function () {
        obrys = []; brudny = true; synchronizuj(); rysuj(); stan('Obrys wyczyszczony (zapis dopiero potwierdzi zmianę).');
    });
    document.getElementById('regmapSiatka').addEventListener('change', rysuj);
    document.getElementById('regmapDziury').addEventListener('change', rysuj);
    map.on('moveend zoomend', rysuj);

    // DWA TRYBY, NIE NARAZ — inaczej klik miałby dwuznaczne znaczenie
    // (dodaj do obrysu? czy popraw pole?). Włączenie jednego gasi drugi.
    document.getElementById('regmapPoprawka').addEventListener('change', function () {
        if (this.checked) { document.getElementById('regmapRysuj').checked = false; }
        synchronizuj();
        rysuj();
    });

    document.querySelectorAll('input[name="regmapRegion"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (brudny && obrys.length
                && !confirm('Obrys nie został zapisany. Porzucić zmiany i przejść do innego regionu?')) {
                return;
            }
            wczytajObrys();
        });
    });

    function wczytajObrys() {
        var r = region();
        obrys = [];
        brudny = false;

        if (r && GEO[r.code]) {
            var g = GEO[r.code];
            // Kadr na wybranym regionie — inaczej wybranie go z listy niczego
            // nie pokazuje, dopóki ktoś sam tam nie dojedzie.
            map.fitBounds(L.latLngBounds([[g.bbox.s, g.bbox.w], [g.bbox.n, g.bbox.e]]), { padding: [30, 30] });
            if (g.editable) {
                obrys = g.rings[0].map(function (p) {
                    return window.ridemoreHexGrid.cellAt(p[0], p[1], SIZE);
                });
                // Pierścień z pliku jest DOMKNIĘTY (ostatni punkt = pierwszy);
                // edytor pracuje na liście klikniętych pól, więc duplikat spada.
                if (obrys.length > 1 && klucz(obrys[0]) === klucz(obrys[obrys.length - 1])) { obrys.pop(); }
                stan('Wczytano obrys: ' + obrys.length + ' pól.');
            } else if (document.getElementById('regmapPoprawka').checked) {
                // Tryb poprawki DZIAŁA na importowanych granicach — inaczej niż
                // rysowanie obrysu, więc komunikat "tylko podgląd" byłby tu
                // po prostu nieprawdą.
                stan('„' + r.name + '" — granice z importu, ale możesz poprawiać pojedyncze pola.');
            } else {
                stan('„' + r.name + '" — granice z importu administracyjnego, tutaj tylko podgląd rysowania.');
            }
        } else {
            stan(r ? 'Nowy obrys — klikaj wzdłuż granicy.' : 'Wybierz region z listy.');
        }

        synchronizuj();
        rysuj();
    }

    function synchronizuj() {
        var r = region();
        var edytowalny = !!r && (!GEO[r.code] || GEO[r.code].editable);
        document.getElementById('regmapCofnij').disabled = !obrys.length;
        document.getElementById('regmapWyczysc').disabled = !obrys.length;
        document.getElementById('regmapZapisz').disabled = !edytowalny || obrys.length < 3;
        document.getElementById('regmapUsun').disabled = !r || !GEO[r.code] || !GEO[r.code].editable;
        document.getElementById('regmapUsunRegion').value = regionId() || '';
        var rysowanie = document.getElementById('regmapRysuj').checked && edytowalny;
        // Poprawka pojedynczego pola nie sprawdza `editable` — działa też na
        // województwach z importu, to jest cała jej racja bytu.
        var poprawianie = document.getElementById('regmapPoprawka').checked && !!r;
        el.classList.toggle('is-drawing', rysowanie || poprawianie);
    }
    document.getElementById('regmapRysuj').addEventListener('change', function () {
        if (this.checked) { document.getElementById('regmapPoprawka').checked = false; }
        synchronizuj();
        rysuj();
    });

    // ---------------------------------------------------------------
    // Zapis
    // ---------------------------------------------------------------
    document.getElementById('regmapZapisz').addEventListener('click', function () {
        var id = regionId();
        if (!id || obrys.length < 3) { return; }
        var przycisk = this;
        przycisk.disabled = true;
        stan('Zapisywanie...');

        fetch(URL_ZAPISZ, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, region: id, ring: obrys.map(srodek) })
        })
            .then(function (r) { return r.json(); })
            .catch(function () { return { error: 'Zapis się nie udał.' }; })
            .then(function (dane) {
                przycisk.disabled = false;
                if (!dane || dane.error) { stan((dane && dane.error) || 'Zapis się nie udał.'); return; }
                brudny = false;
                var opis = 'Zapisano ' + dane.vertices + ' pól.';
                if (dane.conflicts && dane.conflicts.length) {
                    opis += ' Przy imporcie zabierzesz: ' + dane.conflicts.map(function (c) {
                        return c.name + ' (' + c.cells + ')';
                    }).join(', ') + '.';
                }
                stan(opis + ' Pokrycie zbudujesz komendą backfill_regions.php.');
                // Geometria od nowa: zmienił się nie tylko ten region (jego
                // priorytet przesuwa też to, kto wygra sporne pola).
                pobierzGeometrie().then(function () { synchronizuj(); rysuj(); });
            });
    });

    window.addEventListener('beforeunload', function (e) {
        if (brudny && obrys.length) { e.preventDefault(); e.returnValue = ''; }
    });

    pobierzGeometrie().then(wczytajObrys);
})();
</script>
