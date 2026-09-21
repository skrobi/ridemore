<?php
// views/web/pages/region.php
// JEDEN REGION — /regiony/{kraj}/{region} (2026-09-14, tasks/done/strony-regionow.md).
//
// SZKIELET = STRONA ZNANEJ TRASY (`trail.php`), decyzja usera: „szablon stwórz
// z istniejących, podstawą strona znanego szlaku oraz już istniejące elementy".
// Ani jednego nowego komponentu wizualnego:
//   .op-head / .op-cover + .hero-bottom  — nagłówek i okładka jak na trasie,
//   renderStatTiles                      — kafle liczb (partial),
//   .disc-map + map-layers.php           — standardowa mapa z warstwami,
//   event-card.php / trail-card.php / organizer-card.php / treasure-list.php,
//   .roster/.avs + renderRiderAvatar      — „Kto tu jeździ" jak „Mają ją całą",
//   .list-seo .chip-links                — linki do innych regionów.
//
// KOLEJNOŚĆ SEKCJI: najpierw „dokąd i kiedy mogę pojechać" (wyjazdy), potem
// „co tu jest" (trasy, skarby), potem „z kim" (organizatorzy, ludzie), na
// końcu „co już było". Sekcja bez treści się nie renderuje — pusty nagłówek
// „Brak tras" nie jest informacją, tylko ścianą. Wyjątek: wyjazdy, bo pusty
// region to zaproszenie do dodania pierwszego.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
require __DIR__ . '/../partials/rider-avatar.php';
require_once __DIR__ . '/../partials/stat-tiles.php';
require_once __DIR__ . '/../partials/trail-card.php';

$upcoming = $upcoming ?? [];
$completed = $completed ?? [];
$routes = $routes ?? [];
$organizers = $organizers ?? [];
$treasures = $treasures ?? ['total' => 0, 'points' => 0, 'pointsLeft' => 0, 'found' => null];
$treasureList = $treasureList ?? ['items' => [], 'hidden' => 0];
$riders = $riders ?? ['people' => [], 'total' => 0];
$siblings = $siblings ?? [];
$photos = $photos ?? [];
$coverSource = $coverSource ?? null;
$isLoggedIn = $isLoggedIn ?? false;
$plural = Format::plural(...);

// Te same dwie funkcje co na stronie trasy przy „Mają ją całą".
$shortName = static function (array $u): string {
    $raw = trim((string) ($u['name'] ?? '')) ?: (string) strstr((string) ($u['email'] ?? ''), '@', true);
    $parts = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) { return __('Rowerzysta'); }
    return count($parts) > 1
        ? $parts[0] . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.'
        : $parts[0];
};
$initials = static function (array $u): string {
    $raw = trim((string) ($u['name'] ?? '')) ?: (string) strstr((string) ($u['email'] ?? ''), '@', true);
    return mb_strtoupper(mb_substr($raw !== '' ? $raw : '?', 0, 2));
};

// Metryczka pod nazwą — jak `$metaLine` na trasie: wypisana raz, w jednym
// z dwóch miejsc (na okładce albo w nagłówku bez okładki).
$metaBits = [];
if (!$isFlat) {
    $metaBits[] = $countryName;
}
if ($upcomingTotal > 0) {
    $metaBits[] = __n($upcomingTotal, '<b>{n} wyjazd</b> przed nami', '<b>{n} wyjazdy</b> przed nami', '<b>{n} wyjazdów</b> przed nami');
}
if ($routes) {
    $metaBits[] = __n(count($routes), '{n} znana trasa', '{n} znane trasy', '{n} znanych tras');
}
if ($riders['total'] > 0) {
    $metaBits[] = $riders['total'] . ' ' . $plural((int) $riders['total'], __('osoba tu jeździ'), __('osoby tu jeżdżą'), __('osób tu jeździ'));
}
$metaLine = implode(' · ', $metaBits);
$regionFilterUrl = View::url('/wydarzenia?regions[]=' . urlencode($region['code']));
?>

<div class="op-head">
    <div class="op-head__c">
        <div class="tags">
            <span class="tag" style="--bz:var(--s-green);"><span class="blaze"></span><?= __('Region') ?></span>
            <?php if (!$isFlat): ?>
            <span class="tag tag--plain"><?= htmlspecialchars($countryName) ?></span>
            <?php endif; ?>
        </div>
        <?php if (empty($cover)): ?>
        <h1><?= htmlspecialchars($regionName) ?></h1>
        <?php if ($metaLine !== ''): ?>
        <p class="op-head__sub"><?= $metaLine ?></p>
        <?php endif; ?>
        <?php endif; ?>
        <div class="op-head__act">
            <a class="btn" href="<?= htmlspecialchars($regionFilterUrl) ?>"><?= __('Wyjazdy w regionie') ?></a>
            <?php if (!$isLoggedIn): ?>
            <a class="btn btn-secondary" href="<?= View::url('/rejestracja') ?>"><?= __('Dołącz do społeczności') ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($cover)): ?>
<?php // PODPIS ŹRÓDŁA OKŁADKI (2026-09-16) — zdjęcie przedstawia konkretny
      // wyjazd albo trasę, nie „region", więc tak jest opisane i podlinkowane. ?>
<div class="op-cover" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($cover, 'wide')) ?>');" role="img" aria-label="<?= htmlspecialchars($regionName . (!empty($coverSource) ? ' — ' . __('zdjęcie: {zrodlo}', ['zrodlo' => $coverSource['label']]) : '')) ?>">
    <div class="op-cover__g"></div>
    <?php if (!empty($coverSource)): ?>
    <a class="op-cover__credit" href="<?= htmlspecialchars($coverSource['url']) ?>"><?= __('Zdjęcie: {zrodlo}', ['zrodlo' => htmlspecialchars($coverSource['label'])]) ?></a>
    <?php endif; ?>
    <div class="hero-bottom">
        <h1 class="hero-ttl"><?= htmlspecialchars($regionName) ?></h1>
        <?php if ($metaLine !== ''): ?>
        <p class="op-head__sub hero-sub"><?= $metaLine ?></p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
// KAFLE — od faktów o regionie do faktów o widzu. Gość nie dostaje zer
// o sobie (zasada ze strony trasy): odkrycie pokazuje mu społeczność.
$coveragePct = $coverage !== null
    ? ($isLoggedIn ? (float) $coverage['pctMine'] : (float) $coverage['pctCommunity'])
    : null;
renderStatTiles([
    [
        'lbl' => __('Wyjazdy'),
        'val' => $upcomingTotal,
        'sub' => $upcomingTotal > 0 ? __('nadchodzące') : __('na razie żadnego — dodaj pierwszy'),
    ],
    [
        'lbl'   => __('Znane trasy'),
        'val'   => count($routes),
        'small' => $routesKm > 0 ? Format::distance($routesKm) : null,
        'sub'   => $routes ? __('przez ten region') : __('jeszcze żadnej w katalogu'),
    ],
    [
        'lbl' => __('Organizatorzy'),
        'val' => $organizersTotal,
        'sub' => $organizersTotal > 0 ? __('z tego regionu') : __('czeka na pierwszego'),
    ],
    [
        'lbl'   => __('Skarby'),
        'val'   => $treasures['found'] !== null ? (int) $treasures['found'] : (int) $treasures['total'],
        'small' => $treasures['found'] !== null ? __('z {n}', ['n' => (int) $treasures['total']]) : null,
        'sub'   => $treasures['total'] === 0
            ? __('żadnego jeszcze nie ukryto')
            : ($treasures['found'] !== null
                ? __('znalezionych · zostało +{n} pkt', ['n' => number_format((int) $treasures['pointsLeft'], 0, ',', ' ')])
                : __('do znalezienia · +{n} pkt', ['n' => number_format((int) $treasures['pointsLeft'], 0, ',', ' ')])),
    ],
    $coveragePct !== null ? [
        'lbl'    => $isLoggedIn ? __('Twoje odkrycie') : __('Odkryte'),
        'val'    => number_format($coveragePct, 1, ',', '') . '%',
        'subHex' => true,
        'sub'    => $isLoggedIn ? __('pól regionu, przez które jechałeś') : __('pól regionu przejechała społeczność'),
    ] : null,
]);
?>

<?php if (!empty($mapBounds)): ?>
<section class="sec" id="mapa">
    <div class="box">
        <h2><?= __('Mapa regionu') ?></h2>
        <div class="disc-map" style="margin-top:14px;">
            <div id="regionMap" class="disc-map__canvas"></div>
            <?php
            $mlId = 'regionLayers';
            $mlLayers = $mapLayers;
            require __DIR__ . '/../partials/map-layers.php';
            ?>
            <div id="regionLegend" class="hex-legend hex-legend--onmap"></div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('regionMap');
    if (!el || typeof ridemoreDiscoveryMap !== 'function') return;
    var przelacznik = document.getElementById('regionLayers');
    <?php // Ta sama konfiguracja co na stronie trasy (trail.php) — różni się
          // wyłącznie kadrem (obrys regionu) i tym, że klik w szlak nie jest
          // zawężony do jednej trasy. ?>
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
        legendEl: document.getElementById('regionLegend'),
        bounds: <?= json_encode($mapBounds) ?>,
        layers: ridemoreReadLayers(przelacznik),
    });
    if (przelacznik) {
        przelacznik.addEventListener('change', function (e) {
            var cb = e.target.closest('[data-layer]');
            if (cb) { map.ridemoreSetLayer(cb.dataset.layer, cb.checked); }
        });
    }
    <?php // GRANICA REGIONU — gruba czerwona linia (2026-09-14, prośba usera).
          // Własna warstwa (pane) nad kaflami i polami odkryć, pod pinezkami
          // skarbów (markerPane = 600) i dymkami: pola doczytują się później
          // niż ta linia i bez osobnej warstwy przykryłyby ją. Pod czerwienią
          // biała obwódka — ta sama sztuczka co przy śladach tras, żeby linia
          // nie ginęła na ciemnym lesie ani na czerwonych drogach podkładu.
          // Nieinteraktywna: kliknięcie przechodzi do mapy (szlaki, skarby). ?>
    var granica = <?= json_encode($mapOutline ?? []) ?>;
    if (granica.length) {
        map.createPane('regionOutline');
        map.getPane('regionOutline').style.zIndex = 450;
        map.getPane('regionOutline').style.pointerEvents = 'none';
        granica.forEach(function (ring) {
            var zamkniety = ring.concat([ring[0]]);
            L.polyline(zamkniety, { pane: 'regionOutline', color: '#fff', weight: 9, opacity: 0.75, interactive: false, lineJoin: 'round' }).addTo(map);
            L.polyline(zamkniety, { pane: 'regionOutline', color: '#D32F2F', weight: 5, opacity: 0.95, interactive: false, lineJoin: 'round' }).addTo(map);
        });
    }
    <?php // Karta skarbu ustawia na nim mapę — ta sama obsługa co na trasie. ?>
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
});
</script>
<?php endif; ?>

<section class="sec" id="wyjazdy">
    <div class="sec-head">
        <h2><?= __('Nadchodzące wyjazdy') ?></h2>
        <?php if ($upcomingTotal > count($upcoming)): ?>
        <span><a href="<?= htmlspecialchars($regionFilterUrl) ?>"><?= __('Wszystkie ({n})', ['n' => $upcomingTotal]) ?> →</a></span>
        <?php endif; ?>
    </div>
    <?php if ($upcoming): ?>
    <div class="event-grid">
        <?php foreach ($upcoming as $ev): ?>
        <?php require __DIR__ . '/../partials/event-card.php'; ?>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="disc-cta">
        <div>
            <h3><?= __('Nikt jeszcze nie ogłosił tu wyjazdu') ?></h3>
            <p><?= __('Jeździsz w tym regionie? Ogłoś wyjazd — ludzie, którzy tu jeżdżą, zobaczą go jako pierwsi.') ?></p>
        </div>
        <a class="btn" href="<?= View::url('/wydarzenia/nowe') ?>"><?= __('Dodaj wyjazd') ?></a>
    </div>
    <?php endif; ?>
</section>

<?php if ($routes): ?>
<section class="sec" id="trasy">
    <div class="sec-head">
        <h2><?= __('Znane trasy w regionie') ?></h2>
        <span><?= __('Szlaki, które zaliczasz samym jeżdżeniem') ?></span>
    </div>
    <div class="disc-trails">
        <?php foreach ($routes as $route): ?>
        <?php renderTrailCard($route, (bool) $isLoggedIn, ['guestFoot' => !$isLoggedIn]); ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($treasureList['items']) || (int) ($treasureList['hidden'] ?? 0) > 0): ?>
<section class="sec" id="atrakcje">
    <div class="box">
        <h2><?= __('Co zobaczysz w regionie') ?></h2>
        <p class="desc" style="margin-top:6px;">
            <?= __('Miejsca warte przystanku. Za każde są punkty — zaliczają się same, gdy przejedziesz obok') ?>

            <?php if (!$isLoggedIn): ?><?= __('(potrzebne konto)') ?><?php endif; ?>.
        </p>
        <?php
        $tlList = $treasureList;
        require __DIR__ . '/../partials/treasure-list.php';
        $tlShown = count($treasureList['items']) + (int) ($treasureList['hidden'] ?? 0);
        ?>
        <?php if ((int) $treasures['total'] > $tlShown): ?>
        <p class="desc" style="margin-top:12px;">
            <?= __('Pokazujemy {a} z {b} — pozostałe znajdziesz na mapie regionu wyżej.', ['a' => $tlShown, 'b' => (int) $treasures['total']]) ?>

        </p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php // ZDJĘCIA Z REGIONU (2026-09-16) — relacje i opinie uczestników oraz
      // galerie skarbów. Prośba usera: przy każdym zdjęciu ma być wiadomo, skąd
      // pochodzi i czego dotyczy — dlatego podpis jest POD zdjęciem (widać go
      // bez klikania) i powtarza się w lightboksie (`data-caption`). Dane,
      // reguły widoczności i anonimizacja: RegionController::regionPhotos(). ?>
<?php if (!empty($photos)): ?>
<section class="sec" id="zdjecia">
    <div class="box">
        <h2><?= __('Zdjęcia z regionu') ?></h2>
        <p class="desc" style="margin-top:6px;">
            <?= __('Najnowsze zdjęcia z relacji i opinii uczestników wyjazdów oraz od osób, które znalazły tu skarby.') ?>

        </p>
        <div class="rph-grid">
            <?php foreach ($photos as $ph): ?>
            <?php
            $phAuthor = $ph['authorName'] !== null ? $shortName(['name' => $ph['authorName']]) : $ph['anonymous'];
            $phDate = Format::dateShort($ph['createdAt']);
            $phCaption = $ph['source'] . ' „' . $ph['subject'] . '" · ' . __('zdjęcie: {autor}', ['autor' => $phAuthor]) . ($phDate ? ' · ' . $phDate : '');
            ?>
            <figure class="rph">
                <a class="ph-link rph__img" href="<?= htmlspecialchars(View::url($ph['url'])) ?>" target="_blank" rel="noopener"
                   data-caption="<?= htmlspecialchars($phCaption) ?>">
                    <img src="<?= htmlspecialchars(Utils\Image::src($ph['url'], 'card')) ?>"
                         alt="<?= htmlspecialchars($phCaption) ?>" width="320" height="240" loading="lazy" decoding="async">
                </a>
                <figcaption class="rph__cap">
                    <span class="rph__src"><?= htmlspecialchars($ph['source']) ?></span>
                    <a class="rph__subj" href="<?= htmlspecialchars($ph['subjectUrl']) ?>"><?= htmlspecialchars($ph['subject']) ?></a>
                    <span class="rph__meta"><?= __('zdjęcie:') ?> <?php if ($ph['authorUrl'] !== null): ?><a href="<?= htmlspecialchars($ph['authorUrl']) ?>"><?= htmlspecialchars($phAuthor) ?></a><?php else: ?><?= htmlspecialchars($phAuthor) ?><?php endif; ?><?= $phDate ? ' · ' . htmlspecialchars($phDate) : '' ?></span>
                </figcaption>
            </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($organizers): ?>
<section class="sec" id="organizatorzy">
    <div class="sec-head">
        <h2><?= __('Organizatorzy z regionu') ?></h2>
        <?php if ($organizersTotal > count($organizers)): ?>
        <span><a href="<?= View::url('/organizatorzy?regions[]=' . urlencode($region['code'])) ?>">Wszyscy (<?= $organizersTotal ?>) →</a></span>
        <?php endif; ?>
    </div>
    <div class="org-grid">
        <?php foreach ($organizers as $o): ?>
        <?php require __DIR__ . '/../partials/organizer-card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php // KTO TU JEŹDZI — „nie będziesz tu sam". Liczba obejmuje wszystkich,
      // awatary tylko publiczne profile (model: RiderActivity::ridersInRegion,
      // ta sama zasada co „Mają ją całą" na stronie trasy). ?>
<?php if ($riders['total'] > 0): ?>
<section class="sec" id="kto">
    <div class="box">
        <h2><?= __('Kto tu jeździ') ?></h2>
        <div class="roster" style="margin-top:12px;">
            <?php if ($riders['people']): ?>
            <span class="avs">
                <?php foreach ($riders['people'] as $u): ?>
                <?php renderRiderAvatar($initials($u), $u['public_slug'] ?? null, $shortName($u), $u['avatar_url'] ?? null); ?>
                <?php endforeach; ?>
            </span>
            <?php endif; ?>
            <span class="roster__names">
                <?= __n((int) $riders['total'], '<b>{n} osoba</b> jeździ w tym regionie', '<b>{n} osoby</b> jeżdżą w tym regionie', '<b>{n} osób</b> jeździ w tym regionie') ?>

                <?php if (!$isLoggedIn): ?><?= __('— dołącz i zobacz, gdzie jeżdżą') ?><?php endif; ?>
            </span>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($completed): ?>
<section class="sec" id="bylo">
    <div class="sec-head">
        <h2><?= __('Już się odbyło') ?></h2>
        <span><?= __('Wyjazdy, które przejechaliśmy w tym regionie') ?></span>
    </div>
    <div class="event-grid">
        <?php foreach ($completed as $ev): ?>
        <?php require __DIR__ . '/../partials/event-card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($siblings): ?>
<section class="wrap list-seo">
    <h2>Inne regiony — <?= htmlspecialchars($countryName) ?></h2>
    <div class="chip-links">
        <?php foreach ($siblings as $s): ?>
        <a class="chip" href="<?= View::url('/regiony/' . $country['code'] . '/' . $s['code']) ?>"><?= htmlspecialchars(Models\Region::displayName($s['name'])) ?></a>
        <?php endforeach; ?>
        <a class="chip" href="<?= View::url('/regiony') ?>"><?= __('Wszystkie regiony') ?></a>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
