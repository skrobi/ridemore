<?php
// views/web/pages/known-route-edit.php
// Znana trasa — WŁASNA PODSTRONA formularza (dodawanie i edycja).
//
// Oczekuje: $route (wiersz known_routes albo null = dodawanie), $unordered
// (int — ile pól tej trasy nie ma kolejności), $riders, $elevation,
// $mapSources, $mapBounds, $mapEndpoint, $zapisano, $error, $stare, $wroc.
//
// Powstała 2026-08-19 po pytaniu usera „a jak będę miał 200 tras?". Wcześniej
// edycja siedziała w rozwijanym `<details>` przy każdym wierszu listy.
//
// PRZEBUDOWANA NA WARSZTAT 2026-09-12 (zgłoszenie usera: „na dzień dzisiejszy
// nie da się tego używać"). Poprzedni układ był jedną kolumną ośmiu pól
// rozciągniętych na 1360 px, bez ani jednej informacji o TRASIE, którą się
// właśnie zmienia — żeby sprawdzić cokolwiek, trzeba było otworzyć
// `/trasy/{slug}` w drugiej karcie. Cztery zmiany:
//
//   1. PODGLĄD PO LEWEJ, POLA PO PRAWEJ. Przebieg na mapie, profil wysokości
//      i nawierzchnia stoją obok formularza, nie w innej karcie przeglądarki.
//   2. FAKTY NA PASKU `.trust-bar` — tym samym, którym lista tras podsumowuje
//      katalog. Dotąd były cienką linijką „458 pól · 212,3 km" pod tytułem,
//      bez przewyższenia, bez nawierzchni i bez informacji, czy ktokolwiek
//      tą trasą jeździ.
//   3. OSTRZEŻENIE NIESIE SWOJĄ NAPRAWĘ. „Trasa nie rysuje się na mapie" stało
//      na górze strony, a przycisk „Przelicz pola" — 600 px niżej, w osobnej
//      karcie „Pozostałe działania". Nic ich ze sobą nie łączyło.
//   4. STREFA RYZYKA ODDZIELONA. „Usuń trasę" wyglądał identycznie jak
//      „Przelicz pola" i stał z nim w jednym rzędzie.
//
// STRONA PUBLICZNA `/trasy/{slug}` ZOSTAJE BEZ ZMIAN (decyzja usera
// 2026-09-12) — ta przebudowa dotyczy wyłącznie panelu.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\Format;
use Utils\View;

$route = $route ?? null;
$unordered = (int) ($unordered ?? 0);
$wroc = $wroc ?? [];
$stare = $stare ?? null;
$riders = $riders ?? ['started' => 0, 'completed' => 0];
$elevation = $elevation ?? null;
$mapSources = $mapSources ?? [];
$mapBounds = $mapBounds ?? null;
$zapisano = !empty($zapisano);
$isEdit = $route !== null;

// Link powrotny z zachowanym stanem listy (szukanie, filtr, sortowanie, strona).
$listaUrl = View::url('/admin/znane-trasy') . ($wroc ? '?' . http_build_query($wroc) : '');

// Hidden `wroc_*` dla formularzy akcji stojących obok zapisu (przelicz,
// przełącz, usuń) — ten sam mechanizm co w formularzu głównym.
$powrot = static function () use ($wroc): string {
    $out = '';
    foreach ($wroc as $k => $v) {
        $out .= '<input type="hidden" name="wroc_' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string) $v) . '">';
    }
    return $out;
};

// Czy jest z czego złożyć podgląd. Trasa bez pliku GPX (świeżo dodawana) nie
// dostaje pustej ramki — kolumna po prostu się nie renderuje.
$maPodglad = $isEdit && !empty($route['gpx_url']);
$maNawierzchnie = $isEdit && $route['surface_asphalt_pct'] !== null;
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>

<div class="kr-head">
    <div class="kr-head__c">
        <?php if ($isEdit): ?>
        <?php // STAN TRASY JAKO ZNACZNIKI, nie jako zdanie do przeczytania.
              // „Aktywna / rysuje się na mapie / region" to trzy niezależne
              // fakty, które sprawdza się rzutem oka przed każdą decyzją. ?>
        <div class="kr-tags">
            <span class="kr-tag <?= (bool) $route['is_active'] ? 'kr-tag--on' : 'kr-tag--off' ?>">
                <i></i><?= (bool) $route['is_active'] ? 'Aktywna' : 'Wyłączona' ?>
            </span>
            <?php if (!empty($route['region_label'])): ?>
            <span class="kr-tag"><?= htmlspecialchars((string) $route['region_label']) ?></span>
            <?php endif; ?>
            <span class="kr-tag <?= $unordered > 0 ? 'kr-tag--warn' : '' ?>">
                <?= $unordered > 0 ? 'nie rysuje się na mapie' : 'rysuje się na mapie' ?>
            </span>
            <?php if (!(bool) $route['bonus_enabled']): ?>
            <span class="kr-tag">bez punktów</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <h1 class="kr-title"><?= $isEdit ? htmlspecialchars($route['name']) : 'Nowa trasa' ?></h1>
        <?php if (!$isEdit): ?>
        <p class="op-head__sub">
            Po wgraniu pliku GPX trasa zostaje podzielona na te same pola co przejazdy —
            postęp każdego uczestnika liczy się dalej sam, bez niczyjego udziału.
        </p>
        <?php endif; ?>
    </div>
    <div class="kr-head__act">
        <a class="btn btn-secondary btn--sm" href="<?= htmlspecialchars($listaUrl) ?>">← Lista tras</a>
        <?php if ($isEdit): ?>
        <a class="btn btn-secondary btn--sm" href="<?= View::url('/trasy/' . $route['slug']) ?>" target="_blank" rel="noopener">
            Otwórz stronę trasy ↗
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($zapisano): ?>
<p class="form-success">Zmiany zapisane.</p>
<?php endif; ?>

<?php if ($error): ?>
<p class="form-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($isEdit): ?>
<?php // FAKTY O TRASIE — ten sam komponent, którym lista podsumowuje katalog.
      // Przewyższenie i liczby rowerzystów nie były dotąd na tym ekranie
      // w ogóle, a to one rozstrzygają, czy trasę wolno wyłączyć albo usunąć. ?>
<div class="trust-bar">
    <div class="trust-item">
        <div class="trust-item-label">Długość</div>
        <div class="trust-item-value"><?= (float) $route['distance_km'] > 0
            ? number_format((float) $route['distance_km'], 1, ',', ' ') : '—' ?><small>km</small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Przewyższenie</div>
        <?php // NULL to „nie wiem", nie „płasko" — trasa sprzed migr. 063 albo
              // plik bez wysokości. Zero jest poprawną wartością dla płaskiej
              // pętli, więc nie może udawać braku danych. ?>
        <div class="trust-item-value"><?= $route['elevation_gain_m'] !== null
            ? number_format((int) $route['elevation_gain_m'], 0, ',', ' ') . '<small>m</small>'
            : '—<small>brak danych</small>' ?></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Pól siatki</div>
        <div class="trust-item-value"><?= (int) $route['cells_total'] ?><small><?= $unordered > 0
            ? $unordered . ' bez kolejności' : 'wszystkie z kolejnością' ?></small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Zaczęło</div>
        <div class="trust-item-value"><?= (int) $riders['started'] ?><small><?= Format::plural((int) $riders['started'], 'osoba', 'osoby', 'osób') ?></small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Ukończyło</div>
        <div class="trust-item-value"><?= (int) $riders['completed'] ?><small><?= Format::plural((int) $riders['completed'], 'osoba', 'osoby', 'osób') ?></small></div>
    </div>
</div>
<?php endif; ?>

<?php // OSTRZEŻENIE RAZEM ZE SWOJĄ NAPRAWĄ. Trasa bez kolejności pól NIE
      // RYSUJE SIĘ na mapie odkryć — warstwa pomija pola bez sort_order, bo
      // linia poprowadzona po kolejności wstawiania do bazy byłaby zygzakiem,
      // a nie szlakiem (migr. 048). ?>
<?php if ($unordered > 0): ?>
<div class="kr-alert">
    <div class="kr-alert__b">
        <?= Utils\Icon::render('warning') ?>
        <div>
            <b>Ta trasa nie pojawia się na mapie odkryć</b>
            <p><?= $unordered ?> z <?= (int) $route['cells_total'] ?>
                <?= Format::plural((int) $route['cells_total'], 'pola', 'pól', 'pól') ?>
                nie ma zapisanej kolejności wzdłuż śladu (trasa sprzed migracji 048).</p>
        </div>
    </div>
    <form method="post" action="<?= View::url('/admin/znane-trasy/' . (int) $route['id'] . '/przelicz') ?>"
          onsubmit="return confirm('Przeliczyć pola trasy z zapisanego pliku GPX? Postęp uczestników zostanie doprowadzony do zgodności z nowym podziałem.');">
        <?= Csrf::field() ?><?= $powrot() ?>
        <button class="btn btn--sm" type="submit">Przelicz pola</button>
    </form>
</div>
<?php endif; ?>

<div class="kr-work<?= $maPodglad ? '' : ' kr-work--solo' ?>">

    <?php if ($maPodglad): ?>
    <!-- ── PODGLĄD TRASY ──────────────────────────────────────────── -->
    <aside class="kr-side">
        <div class="card kr-preview">
            <?php // MAPA = STANDARDOWA KONTROLKA (pamięć `feedback-one-standard-map`).
                  // Warstwa niesie klucz `kr-{id}`, czyli WYŁĄCZNIE tę trasę —
                  // panel nie jest mapą do pracy, tylko podglądem edytowanego
                  // bytu. Bez przełącznika warstw i bez legendy z tego samego
                  // powodu. ?>
            <div id="krMap" class="kr-map"></div>

            <?php if (!empty($elevation['profile'])): ?>
            <div class="kr-preview__sec">
                <h4>Profil wysokości</h4>
                <?php
                // Ten sam partial, co strona trasy i strona przejazdu.
                $epProfile = $elevation['profile'];
                $epPeaks   = $elevation['peaks'] ?? [];
                $epColor   = \Models\KnownRoute::colorOf($route['color_index'] ?? null);
                require __DIR__ . '/../partials/elevation-profile.php';
                ?>
            </div>
            <?php endif; ?>

            <?php if ($maNawierzchnie): ?>
            <div class="kr-preview__sec">
                <h4>Nawierzchnia</h4>
                <?php
                // WSPÓLNY PARTIAL (migr. 086) — ten sam pasek co na stronie
                // trasy i na stronie wydarzenia. Kilometry liczymy tu z procentów
                // i długości, dokładnie jak TrailController::surfaceBreakdown().
                $krKm = (float) $route['distance_km'];
                $sbBreakdown = [
                    'asphaltPct' => (int) $route['surface_asphalt_pct'],
                    'gravelPct'  => (int) $route['surface_gravel_pct'],
                    'trailPct'   => (int) $route['surface_trail_pct'],
                    'asphaltKm'  => round($krKm * (int) $route['surface_asphalt_pct'] / 100, 1),
                    'gravelKm'   => round($krKm * (int) $route['surface_gravel_pct'] / 100, 1),
                    'trailKm'    => round($krKm * (int) $route['surface_trail_pct'] / 100, 1),
                ];
                require __DIR__ . '/../partials/surface-breakdown.php';
                ?>
            </div>
            <?php endif; ?>
        </div>
    </aside>
    <?php endif; ?>

    <!-- ── POLA ───────────────────────────────────────────────────── -->
    <div class="kr-main">
        <?php $krRoute = $route; $krWroc = $wroc; $krStare = $stare; require __DIR__ . '/../partials/known-route-form.php'; ?>
    </div>
</div>

<?php if ($isEdit): ?>
<?php // STREFA RYZYKA — oddzielona i podpisana SKUTKIEM, nie samą nazwą
      // akcji. Wcześniej „Wyłącz trasę" i „Usuń trasę" stały w jednym rzędzie
      // z „Przelicz pola" i wyglądały identycznie (wszystkie `btn-secondary`). ?>
<div class="kr-danger">
    <h3>Strefa ryzyka</h3>
    <div class="kr-danger__grid">
        <div class="kr-danger__item">
            <div>
                <b><?= (bool) $route['is_active'] ? 'Wyłącz trasę' : 'Włącz trasę' ?></b>
                <p><?= (bool) $route['is_active']
                    ? 'Znika z mapy, katalogu i liczenia postępu. Zostaje w bazie — da się włączyć z powrotem.'
                    : 'Wraca na mapę, do katalogu i do liczenia postępu.' ?></p>
            </div>
            <form method="post" action="<?= View::url('/admin/znane-trasy/' . (int) $route['id'] . '/przelacz') ?>">
                <?= Csrf::field() ?><?= $powrot() ?>
                <button class="btn btn-secondary btn--sm" type="submit"><?= (bool) $route['is_active'] ? 'Wyłącz' : 'Włącz' ?></button>
            </form>
        </div>
        <div class="kr-danger__item">
            <div>
                <b>Usuń trasę</b>
                <p>Kasuje trasę i jej <?= (int) $route['cells_total'] ?>
                    <?= Format::plural((int) $route['cells_total'], 'pole', 'pola', 'pól') ?>.
                    Adres <code>/trasy/<?= htmlspecialchars($route['slug']) ?></code> przestanie działać.
                    Odkrycia i punkty rowerzystów zostają nietknięte.</p>
            </div>
            <form method="post" action="<?= View::url('/admin/znane-trasy/' . (int) $route['id'] . '/usun') ?>"
                  onsubmit="return confirm('Usunąć trasę <?= htmlspecialchars(addslashes($route['name'])) ?>? Odkrycia rowerzystów zostaną nietknięte.');">
                <?= Csrf::field() ?><?= $powrot() ?>
                <button class="btn btn-secondary btn--danger" type="submit">Usuń</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($maPodglad && $mapSources): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('krMap');
    if (!el || typeof ridemoreDiscoveryMap !== 'function') { return; }

    // Podgląd, nie mapa do pracy: sama warstwa tej trasy, bez pól odkryć,
    // bez skarbów i bez przełącznika. Kadr liczy serwer z pól trasy, więc
    // mapa nie zaczyna od środka Polski i nie przeskakuje.
    var mapa = ridemoreDiscoveryMap(el, {
        context: 'all',
        endpoint: <?= json_encode($mapEndpoint ?? '') ?>,
        sources: <?= json_encode($mapSources, JSON_UNESCAPED_SLASHES) ?>,
        filters: {},
        bounds: <?= json_encode($mapBounds) ?>,
        layers: { cells: false, heat: false, trails: true, slady: false, treasures: false }
    });

    // Wykres profilu podpina wspólny helper z assets/js/gpx-map.js — on zna
    // bramkę na zerowy kontener i odczyt atrybutów `data-`.
    if (typeof ridemoreSetupElevationProfile === 'function') {
        ridemoreSetupElevationProfile(mapa);
    }
});
</script>
<?php endif; ?>
