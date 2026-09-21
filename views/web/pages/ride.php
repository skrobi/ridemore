<?php
// views/web/pages/ride.php
// JEDEN PRZEJAZD — /przejazd/{id} (2026-09-03, tasks/done/strona-przejazdu.md).
//
// Kolejność sekcji jak na stronie trasy, bo to ten sam rodzaj strony („co to
// jest" → „liczby" → „przebieg" → „konteksty dookoła"):
//   1. czym był ten przejazd (nazwa, data, autor, pobranie GPX-a)
//   2. PASEK STATYSTYK  ← dystans, przewyższenie, czas, pola, punkty, skarby
//   3. przebieg na mapie + profil wysokości z podjazdami
//   4. co minąłeś po drodze (skarby)
//   5. znane trasy, przez które prowadził
//   6. wydarzenie, z którego pochodzi (+ zdjęcia z niego)
//   7. punkty — za co
//   8. inne własne przejazdy tędy
//
// ANI JEDNEGO NOWEGO KOMPONENTU: `.op-head`, `stat-tiles` (partial),
// `.disc-map` + `partials/map-layers.php`, `.profbox`/`.day-profile-big`/
// `.climbs`, karty `.disc-trail` — wszystko stąd, gdzie już stoi.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
require __DIR__ . '/../partials/rider-avatar.php';
require_once __DIR__ . '/../partials/stat-tiles.php';
require_once __DIR__ . '/../partials/region-link.php';

if (!$ride) {
    ?>
    <div class="op-head">
        <div class="op-head__c">
            <h1><?= __('Nie znaleziono przejazdu') ?></h1>
            <p class="op-head__sub">
                <?= __('Ten przejazd nie istnieje, został usunięty albo należy do kogoś,
                kto nie pokazuje swojej historii publicznie.') ?>

            </p>
            <div class="op-head__act">
                <a class="btn" href="<?= View::url('/odkrycia') ?>"><?= __('Zobacz mapę odkryć') ?></a>
            </div>
        </div>
    </div>
    <?php
    return;
}

$isOwner     = $isOwner ?? false;
$isSolo      = $isSolo ?? false;
$isLoggedIn  = $isLoggedIn ?? false;
$isTrimmed   = $isTrimmed ?? false;
$elevation   = $elevation ?? null;
$peaks       = $peaks ?? [];
$points      = $points ?? [];
$regions     = $regions ?? [];
$routes      = $routes ?? [];
$otherRides  = $otherRides ?? [];
$eventPhotos = $eventPhotos ?? [];
$eventCard   = $eventCard ?? null;
$treasures   = $treasures ?? ['total' => 0, 'points' => 0, 'pointsLeft' => 0, 'found' => null];
$treasureList = $treasureList ?? ['items' => [], 'hidden' => 0];

// Średnia prędkość liczy się TU, a nie w bazie: to iloraz dwóch kolumn, które
// już są, więc kolumna na nią byłaby trzecią wartością do utrzymania w zgodzie
// z tamtymi dwiema. Bez czasu ruchu nie ma średniej — i nie udajemy zera.
$czas = Format::duration($ride['moving_seconds'] !== null ? (int) $ride['moving_seconds'] : null);
$srednia = ($ride['moving_seconds'] ?? 0) > 0 && (float) $ride['distance_km'] > 0
    ? round((float) $ride['distance_km'] / ((int) $ride['moving_seconds'] / 3600), 1)
    : null;
?>

<div class="op-head">
    <div class="op-head__c">
        <div class="tags">
            <span class="tag tag--plain"><?= $isSolo ? __('Przejazd solo') : __('Przejazd z wyjazdu') ?></span>
            <?php foreach ($regions as $region): ?>
            <?= renderRegionLinks($region, 'tag tag--plain') ?>
            <?php endforeach; ?>
        </div>
        <?php // WŁASNA NAZWA, EDYCJA WPROST NA STRONIE (migr. 080, zgłoszenie
              // usera: „chciałem mieć to samo co przychodzi z Garmina,
              // a później dodatkowo mieć możliwość zmiany"). Tylko właściciel
              // i tylko solo — przejazd z wyjazdu bierze nazwę wydarzenia,
              // które ma własny ekran edycji, więc pisanie tu nowej nazwy
              // niczego by nie zmieniło. Bez JS formularz po prostu nie ma
              // jak się pokazać — ołóweczek zostaje jedynym elementem, który
              // JS odsłania (patrz styl niżej), więc brak JS = brak przycisku,
              // nie zepsuty przycisk. ?>
        <?php if ($isOwner && $isSolo): ?>
        <?php // WIDOCZNOŚĆ PRZEZ `style.display`, NIE ATRYBUT `hidden` — te dwa
              // elementy i tak potrzebują własnego `display:flex` (poziomy
              // układ H1+ołóweczek / pole+przyciski), a inline `style`
              // ZAWSZE wygrywa z domyślną regułą przeglądarki `[hidden]{
              // display:none}`. Mieszanie obu na tym samym elemencie
              // zostawiłoby oba stany widoczne naraz (znalezione żywym
              // smoke testem). ?>
        <div style="display:flex;align-items:center;gap:8px;" id="rideNameBox" data-rename-id="<?= (int) $ride['id'] ?>">
            <h1 id="rideNameDisplay" style="margin:0;"><?= htmlspecialchars($rideName) ?></h1>
            <button type="button" class="iconbtn" id="rideNameEditBtn"
                    title="<?= htmlspecialchars(__('Zmień nazwę przejazdu')) ?>" aria-label="<?= htmlspecialchars(__('Zmień nazwę przejazdu')) ?>"><?= \Utils\Icon::render('edit') ?></button>
        </div>
        <form id="rideNameForm" class="op-head__renameForm" style="display:none;gap:8px;align-items:center;margin:4px 0 0;">
            <?= \Core\Csrf::field() ?>
            <input type="text" id="rideNameInput" name="nazwa" maxlength="<?= (int) \Models\RiderActivity::NAME_MAX_LENGTH ?>"
                   placeholder="<?= htmlspecialchars(__('Nazwa przejazdu')) ?>" value="<?= htmlspecialchars($rideName) ?>"
                   style="flex:1;min-width:0;">
            <button type="submit" class="btn btn--sm"><?= __('Zapisz') ?></button>
            <button type="button" class="btn btn-secondary btn--sm" id="rideNameCancel"><?= __('Anuluj') ?></button>
        </form>
        <p class="form-error" id="rideNameError" hidden style="margin-top:6px;"></p>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var box = document.getElementById('rideNameBox');
            var display = document.getElementById('rideNameDisplay');
            var editBtn = document.getElementById('rideNameEditBtn');
            var form = document.getElementById('rideNameForm');
            var input = document.getElementById('rideNameInput');
            var cancelBtn = document.getElementById('rideNameCancel');
            var errBox = document.getElementById('rideNameError');
            if (!box || !form) { return; }

            function pokazForm() {
                box.style.display = 'none';
                form.style.display = 'flex';
                errBox.hidden = true;
                input.value = display.textContent;
                input.focus();
                input.select();
            }
            function pokazDisplay(tekst) {
                if (tekst !== undefined) { display.textContent = tekst; }
                form.style.display = 'none';
                box.style.display = 'flex';
            }

            editBtn.addEventListener('click', pokazForm);
            cancelBtn.addEventListener('click', function () { pokazDisplay(); });
            form.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { pokazDisplay(); }
            });

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var przycisk = form.querySelector('button[type="submit"]');
                przycisk.disabled = true;
                errBox.hidden = true;

                var dane = new FormData(form);
                fetch(<?= json_encode(View::url('/api/rides/' . (int) $ride['id'] . '/nazwa')) ?>,
                      { method: 'POST', body: dane, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (o) {
                        przycisk.disabled = false;
                        if (o.error) {
                            errBox.textContent = o.error;
                            errBox.hidden = false;
                            return;
                        }
                        pokazDisplay(o.displayName);
                        // TYTUŁ KARTY PRZEGLĄDARKI niesie tę samą nazwę na
                        // starcie strony (RideController::show, ta sama
                        // końcówka „ — data | ridemore.bike") — bez tej linii
                        // pozostałby przy nazwie sprzed edycji, mimo że treść
                        // na ekranie już się zmieniła.
                        document.title = o.displayName + <?= json_encode(
                            ' — ' . (Format::dateP($ride['ride_date']) ?? __('przejazd')) . ' | ridemore.bike'
                        ) ?>;
                    })
                    .catch(function () {
                        przycisk.disabled = false;
                        errBox.textContent = __('Nie udało się zapisać — spróbuj ponownie.');
                        errBox.hidden = false;
                    });
            });
        });
        </script>
        <?php else: ?>
        <h1><?= htmlspecialchars($rideName) ?></h1>
        <?php endif; ?>
        <p class="op-head__sub">
            <?= htmlspecialchars(Format::dateP($ride['ride_date']) ?? '') ?>
            <?php if ((float) $ride['distance_km'] > 0): ?>
            · <?= htmlspecialchars(Format::distance((float) $ride['distance_km'])) ?>
            <?php endif; ?>
            <?php // Przewyższenie 0 zostawiamy bez komentarza — płaska pętla
                  // jest poprawną odpowiedzią, a „brak danych" wyglądałby tu
                  // jak awaria. ?>
            <?php if ((int) $ride['elevation_gain_m'] > 0): ?>
            · <?= __('{n} m w górę', ['n' => (int) $ride['elevation_gain_m']]) ?>

            <?php endif; ?>
            <?php if ($czas !== null): ?>
            · <?= htmlspecialchars($czas) ?>
            <?php endif; ?>
        </p>

        <?php // AUTOR — każde wystąpienie człowieka prowadzi do jego profilu
              // (zasada z partiala rider-avatar). Na własnym przejeździe też:
              // to jest strona publiczna i ma wyglądać tak samo dla wszystkich. ?>
        <div class="roster" style="margin-top:10px;">
            <span class="avs">
                <?php renderRiderAvatar(
                    mb_strtoupper(mb_substr((string) ($ride['user_name'] ?: '?'), 0, 2)),
                    $ride['user_slug'] ?? null,
                    (string) $ride['user_name'],
                    $ride['user_avatar'] ?? null
                ); ?>
            </span>
            <span class="roster__names"><?= htmlspecialchars((string) ($ride['user_name'] ?: __('Rowerzysta'))) ?></span>
        </div>

        <div class="op-head__act">
            <?php if (!empty($downloadUrl)): ?>
            <a class="btn btn-secondary" href="<?= htmlspecialchars($downloadUrl) ?>"
               download="przejazd-<?= (int) $ride['id'] ?>.gpx"><?= __('Pobierz GPX ↓') ?></a>
            <?php endif; ?>
            <?php if (!$isSolo && !empty($ride['event_slug'])): ?>
            <?php // PUBLICZNA strona wyjazdu to `/events/{slug}` — `/wydarzenia/{slug}`
                  // istnieje wyłącznie z przyrostkami akcji (`/edytuj`, `/uczestnicy`)
                  // i samo w sobie daje 404. Ten sam adres składa `partials/event-card.php`. ?>
            <a class="btn" href="<?= View::url('/events/' . $ride['event_slug']) ?>"><?= __('Strona wyjazdu') ?></a>
            <?php endif; ?>
            <?php if ($isOwner): ?>
            <a class="btn btn-secondary" href="<?= View::url('/admin/moje-przejazdy') ?>?tab=<?= $isSolo ? 'solo' : 'wyjazdy' ?>">
                <?= __('Moje przejazdy') ?>

            </a>
            <?php endif; ?>
        </div>

        <?php // POWIEDZIANE WPROST, ZANIM KTOŚ POBIERZE PLIK. Cudzy przejazd
              // solo wychodzi stąd BEZ okolic domu (§27) — i bez wysokości ani
              // czasów, bo przycięta geometria ich nie trzyma. Człowiek ma to
              // wiedzieć na stronie, a nie odkryć dopiero w nawigacji. ?>
        <?php if ($isTrimmed): ?>
        <p class="desc" style="margin-top:10px;">
            <?= __('To jest cudzy przejazd solo, więc początek i koniec śladu są ukryte —
            zaczyna się i kończy pod czyimś domem. Plik do pobrania niesie sam
            przebieg (bez wysokości i znaczników czasu).') ?>

        </p>
        <?php endif; ?>
    </div>
</div>

<?php
// PASEK STATYSTYK — ten sam partial co na /odkrycia, profilu i stronie trasy.
// Kolejność od faktów o JEŹDZIE (dystans, przewyższenie, czas) do faktów
// o GRZE (pola, punkty, skarby).
renderStatTiles([
    [
        'lbl' => __('Dystans'),
        'val' => (float) $ride['distance_km'] > 0 ? Format::distance((float) $ride['distance_km']) : '—',
        'sub' => $srednia !== null ? __('śr. {v} km/h', ['v' => number_format($srednia, 1, \Core\Lang::numberSeparators()[0], \Core\Lang::numberSeparators()[1])]) : __('z pliku GPX'),
    ],
    [
        'lbl'   => __('Przewyższenie'),
        'val'   => number_format((int) $ride['elevation_gain_m'], 0, ',', ' '),
        'small' => 'm',
        'sub'   => __('suma podjazdów'),
    ],
    $czas !== null
        ? ['lbl' => __('Czas'), 'val' => $czas, 'sub' => __('w ruchu')]
        : ['lbl' => __('Czas'), 'val' => '—', 'sub' => __('plik bez znaczników czasu')],
    [
        // NOWE POLA to sedno tego serwisu — ile mgły zdjął TEN przejazd.
        // `cells_touched` obok, bo różnica między nimi mówi „ile z tego już
        // znałeś", a to jest połowa treści przejazdu po znanej okolicy.
        'lbl'    => __('Nowe pola'),
        'val'    => number_format((int) $ride['cells_new'], 0, ',', ' '),
        'subHex' => true,
        'sub'    => __('z {n} {pol} po drodze', ['n' => number_format((int) $ride['cells_touched'], 0, ',', ' '), 'pol' => Format::plural((int) $ride['cells_touched'], 'pola', 'pól', 'pól')]),
    ],
    [
        'lbl' => __('Punkty'),
        'val' => number_format((int) $ride['points_total'], 0, ',', ' '),
        'sub' => __('za ten przejazd'),
    ],
    [
        // „Po drodze" znaczy: na POLACH tego przejazdu — ta sama reguła, którą
        // stosuje zaliczanie skarbów ze śladu (Treasure::claimAlongTrack).
        'lbl'   => __('Skarby po drodze'),
        'val'   => $treasures['found'] !== null ? (int) $treasures['found'] : (int) $treasures['total'],
        'small' => $treasures['found'] !== null ? 'z ' . (int) $treasures['total'] : null,
        'sub'   => (int) $treasures['total'] === 0
            ? __('na tej trasie żadnego nie ma')
            : ($treasures['found'] !== null ? 'zdobytych' : __('do znalezienia')),
    ],
]);
?>

<?php if (!empty($trackUrl)): ?>
<?php // MAPA = STANDARDOWA KONTROLKA (pamięć `feedback-one-standard-map`).
      // Ślad idzie tym samym endpointem geometrii, którym podświetla się
      // przejazd na każdej innej mapie — z tą różnicą, że TU jest tematem
      // strony, więc ma swój kolor z palety, a nie niebieski WYBORU. ?>
<section class="sec" id="przebieg">
    <div class="box">
        <h2><?= __('Przebieg') ?></h2>
        <div class="disc-map" style="margin-top:14px;">
            <div id="rideMap" class="disc-map__canvas"></div>
            <?php
            $mlId = 'rideLayers';
            $mlLayers = $mapLayers;
            require __DIR__ . '/../partials/map-layers.php';
            ?>
            <div id="rideLegend" class="hex-legend hex-legend--onmap"></div>
        </div>

        <?php // PROFIL WYSOKOŚCI — WSPÓLNY PARTIAL (ten sam co na stronie trasy).
              // Pusty profil (obcy przy przejeździe solo — przycięta geometria
              // nie niesie wysokości) nie renderuje nic. ?>
        <?php
        $epProfile = $elevation;
        $epPeaks   = $peaks;
        $epColor   = $rideColor;
        require __DIR__ . '/../partials/elevation-profile.php';
        ?>

        <?php // TĘTNO / KADENCJA / MOC / TEMPERATURA — osobne wykresy na TEJ
              // SAMEJ skali kilometrów co profil wyżej (2026-09-11, prośba
              // usera). Nie ma tu warunku na „czy plik ma czujniki": partial
              // dostaje listę kanałów, które faktycznie są w pliku, i przy
              // pustej nie renderuje nic. ?>
        <?php
        $mcProfile  = $elevation;
        $mcChannels = $metricChannels ?? [];
        require __DIR__ . '/../partials/metric-charts.php';
        ?>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('rideMap');
    if (!el || typeof ridemoreDiscoveryMap !== 'function') return;

    var przelacznik = document.getElementById('rideLayers');

    var map = ridemoreDiscoveryMap(el, {
        context: <?= json_encode($mapScope ?? 'all') ?>,
        endpoint: <?= json_encode($mapEndpoints['cells']) ?>,
        sources: <?= json_encode($mapSources, JSON_UNESCAPED_SLASHES) ?>,
        filters: <?= json_encode($mapFilters, JSON_UNESCAPED_SLASHES) ?>,
        trailsHitEndpoint: <?= json_encode($mapEndpoints['trailsAt']) ?>,
        treasuresEndpoint: <?= json_encode($mapEndpoints['treasures']) ?>,
        treasureActions: <?= $isLoggedIn ? 'true' : 'false' ?>,
        claimEndpoint: <?= json_encode($mapEndpoints['claim']) ?>,
        confirmEndpoint: <?= json_encode($mapEndpoints['confirm']) ?>,
        csrf: <?= json_encode(Core\Csrf::token()) ?>,
        legendEl: document.getElementById('rideLegend'),
        <?php // Kadr policzony na serwerze z geometrii TEGO śladu (przyciętej,
              // gdy ogląda go ktoś obcy) — mapa zna granice, zanim zapyta
              // o pola. ?>
        bounds: <?= json_encode($mapBounds) ?>,
        layers: ridemoreReadLayers(przelacznik),
    });

    if (przelacznik) {
        przelacznik.addEventListener('change', function (e) {
            var cb = e.target.closest('[data-layer]');
            if (cb) { map.ridemoreSetLayer(cb.dataset.layer, cb.checked); }
        });
    }

    <?php // ŚLAD NA WIERZCHU — `ridemoreFocusTrack` zamiast `ridemoreAddGpxTrack`:
          // pobiera spakowaną geometrię z API (kilkanaście razy mniej bajtów niż
          // plik i bez parsowania XML-a), a przy okazji to JEDYNA droga, którą
          // obcy w ogóle może dostać ten ślad — przyciętą. ?>
    window.ridemoreFocusTrack(map, <?= json_encode($trackUrl, JSON_UNESCAPED_SLASHES) ?>, {
        color: <?= json_encode($rideColor) ?>
    });

    // Klik w kartę skarbu ustawia mapę na tym miejscu — ten sam mechanizm co
    // na stronie trasy i w panelu obok mapy na /odkrycia.
    document.addEventListener('click', function (e) {
        var karta = e.target.closest('.js-atrakcja');
        if (!karta) { return; }
        var lat = parseFloat(karta.dataset.lat);
        var lon = parseFloat(karta.dataset.lon);
        if (isNaN(lat) || isNaN(lon)) { return; }
        map.ridemoreFocusTreasure(parseInt(karta.dataset.id, 10), lat, lon);
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    document.addEventListener('keydown', function (e) {
        if ((e.key !== 'Enter' && e.key !== ' ') || !e.target.classList.contains('js-atrakcja')) { return; }
        e.preventDefault();
        e.target.click();
    });

    // Wykres profilu podpina wspólny helper z `assets/js/gpx-map.js` — on zna
    // bramkę na zerowy kontener i odczyt atrybutów `data-`.
    ridemoreSetupElevationProfile(map);

    // Dodatkowe kanały (tętno, kadencja, moc, temperatura) — osobna funkcja, bo
    // to osobne wykresy, ale wspólny kursor łączy je z profilem wyżej i z mapą.
    if (typeof ridemoreSetupMetricCharts === 'function') { ridemoreSetupMetricCharts(); }
});
</script>
<?php endif; ?>

<?php // CO MINĄŁEŚ PO DRODZE — te same karty co katalog tras i strona trasy.
      // Ujawnienie robi model (`Treasure::listOnActivity` → `reveal()`), więc
      // skarb ukryty w polu, którego widz nie odkrył, tu nie dojedzie. ?>
<?php if (!empty($treasureList['items']) || (int) ($treasureList['hidden'] ?? 0) > 0): ?>
<section class="sec" id="skarby">
    <div class="box">
        <h2><?= $isOwner ? __('Co minąłeś po drodze') : __('Co jest po drodze') ?></h2>
        <p class="desc" style="margin-top:6px;">
            <?= $isOwner
                ? __('Miejsca leżące na polach tego przejazdu. Zdobyte są podpisane — resztę da się jeszcze wziąć.')
                : __('Miejsca leżące na polach tego przejazdu. Zdobyte są podpisane — resztę można wziąć, jadąc tędy.') ?>

        </p>

        <?php // KARTY SKARBÓW — WSPÓLNY PARTIAL, ten sam, który rysuje sekcję
              // „Co zobaczysz po drodze" na stronie trasy. ?>
        <?php
        $tlList = $treasureList;
        $tlHiddenNote = __('pokażą się dopiero temu, kto przejedzie przez ich okolicę.');
        require __DIR__ . '/../partials/treasure-list.php';
        ?>
    </div>
</section>
<?php endif; ?>

<?php // ZNANE TRASY — czy ten przejazd szedł którymś ze szlaków z katalogu.
      // Procent liczy się WZGLĘDEM TRASY („ile z niej objął ten jeden
      // przejazd"), nie względem przejazdu — i nie jest tym samym co postęp
      // osoby, który zbiera się ze wszystkich przejazdów. ?>
<?php if ($routes): ?>
<section class="sec" id="trasy">
    <div class="box">
        <h2><?= __('Znane trasy na tym przejeździe') ?></h2>
        <p class="desc" style="margin-top:6px;">
            <?= __('Ile z każdej z nich objął ten jeden przejazd. Twój łączny postęp
            zbiera się ze wszystkich przejazdów — jest na stronie trasy.') ?>

        </p>
        <?php // `.facts-list` — istniejący komponent „nazwa ... wartość"
              // (wiersz z kreską pod spodem, wartość do prawej). ?>
        <ul class="facts-list" style="margin-top:12px;">
            <?php foreach ($routes as $r): ?>
            <li>
                <span>
                    <a href="<?= View::url('/trasy/' . $r['slug']) ?>"><?= htmlspecialchars($r['name']) ?></a>
                    — <?= (int) $r['matched'] ?> z <?= (int) $r['cells_total'] ?>
                    <?= Format::plural((int) $r['cells_total'], 'pola', 'pól', 'pól') ?>
                </span>
                <b><?= (int) $r['pct'] ?>%</b>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php // WYJAZD, Z KTÓREGO POCHODZI TEN ŚLAD. Przejazd solo tej sekcji nie ma
      // i mieć nie może: powiązanie solo z wyjazdem KASUJE przejazd solo
      // i tworzy ślad uczestnika (RiderActivity::linkSoloToEdition), więc
      // „solo powiązane z eventem" nie istnieje jako stan. ?>
<?php if (!$isSolo && $eventCard): ?>
<section class="sec" id="wyjazd">
    <div class="box">
        <h2><?= __('Z tego wyjazdu') ?></h2>
        <?php // KARTA WYJAZDU TYM SAMYM KOMPONENTEM CO KATALOG I STRONA GŁÓWNA
              // (`event-card.php` w `.event-grid`) — okładka, daty, region, typ
              // i stan zapisu widza za darmo, razem z poprawnym adresem
              // `/events/{slug}`, którego ta strona nie musi znać. ?>
        <div class="event-grid" style="margin-top:12px;">
            <?php $ev = $eventCard; require __DIR__ . '/../partials/event-card.php'; ?>
        </div>
        <div class="op-head__act" style="margin-top:12px;">
            <a class="btn btn-secondary" href="<?= View::url('/kronika/' . $ride['event_slug']) ?>"><?= __('Kronika wyjazdu') ?></a>
        </div>

        <?php if ($eventPhotos): ?>
        <?php // GALERIA TAK JAK NA STRONIE WYJAZDU: `.photo-gallery` + kafelki
              // `.ph-link` obsługiwane przez wspólny `partials/photo-lightbox.php`
              // (dołączony na końcu strony). Bez JS link dalej otwiera PEŁNY
              // plik, a nie miniaturę — to była cała nauka z 2026-08-22.
              //
              // Zdjęcia są WYJAZDU, nie przejazdu, więc podpisane wprost. ?>
        <p class="desc" style="margin-top:14px;"><?= __('Zdjęcia z tego wyjazdu:') ?></p>
        <div class="photo-gallery" style="margin-top:8px;">
            <?php foreach ($eventPhotos as $photoUrl): ?>
            <a class="ph-link" href="<?= htmlspecialchars(View::url((string) $photoUrl)) ?>"
               target="_blank" rel="noopener"><img
                src="<?= htmlspecialchars(Utils\Image::src((string) $photoUrl, 'thumb')) ?>"
                alt="<?= htmlspecialchars((string) $ride['event_title']) ?> — <?= htmlspecialchars(__('zdjęcie z wyjazdu')) ?>"
                loading="lazy"></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php // ZA CO PUNKTY — rejestr `point_transactions` tego przejazdu. To jest
      // źródło prawdy o wyniku (migr. 043), a nie kolumna w przejeździe, więc
      // rozbicie może być dokładne co do wpisu. ?>
<?php if ($points): ?>
<section class="sec" id="punkty">
    <div class="box">
        <h2><?= __('Punkty za ten przejazd') ?></h2>
        <ul class="facts-list" style="margin-top:12px;">
            <?php foreach ($points as $wpis): ?>
            <li>
                <span>
                    <?= htmlspecialchars(Models\PointLedger::label((string) $wpis['source'])) ?>
                    <?php if (!empty($wpis['description'])): ?>
                    — <?= htmlspecialchars((string) $wpis['description']) ?>
                    <?php endif; ?>
                </span>
                <b><?= (int) $wpis['points'] > 0 ? '+' : '' ?><?= number_format((int) $wpis['points'], 0, ',', ' ') ?></b>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php // INNE WŁASNE PRZEJAZDY TĘDY — tylko dla właściciela. Cudzych przejazdów
      // tą samą drogą nie pokazujemy nigdy: to byłaby odpowiedź na pytanie
      // „kto tędy jeździ", którego ten serwis nie zadaje (§27). ?>
<?php if ($otherRides): ?>
<section class="sec" id="inne">
    <div class="box">
        <h2><?= __('Twoje inne przejazdy tędy') ?></h2>
        <p class="desc" style="margin-top:6px;"><?= __('Wspólny teren liczony po kaflach mapy.') ?></p>
        <ul class="facts-list" style="margin-top:12px;">
            <?php foreach ($otherRides as $inny): ?>
            <li>
                <span>
                    <a href="<?= View::url('/przejazd/' . (int) $inny['id']) ?>">
                        <?= htmlspecialchars((string) ($inny['name']
                            ?: ($inny['device_name']
                                ?: ($inny['event_title'] ?: __('Przejazd solo'))))) ?>
                    </a>
                    — <?= htmlspecialchars((string) (Format::dateShort($inny['ride_date']) ?? '')) ?>
                    · <?= __('{n} nowych pól', ['n' => (int) $inny['cells_new']]) ?>

                </span>
                <b><?= htmlspecialchars(Format::distance((float) $inny['distance_km'])) ?></b>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php // WSPÓLNY LIGHTBOX — kafelki `.ph-link` w galerii wyjazdu wyżej otwierają
      // się w nim zamiast w nowej karcie. Dołączany raz, na końcu treści. ?>
<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
