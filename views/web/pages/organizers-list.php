<?php
// views/web/pages/organizers-list.php
// Oczekuje: $organizers (array wierszy z Models\Organizer::search(), każdy
// z doklejonym 'coverPhotos'), $total (int), $filters, $sort ('rating'|'events'),
// $filterOptions ('regions' z Dictionary::groupedLeaves(), 'organizerTypes',
// 'bikeTypes' z Dictionary::items()).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Icon;
use Utils\View;

$organizers    = $organizers ?? [];
$total         = $total ?? 0;
$filters       = $filters ?? [];
$sort          = $sort ?? 'rating';
$filterOptions = $filterOptions ?? [];

$isChecked = fn(string $group, string $code) => in_array($code, $filters[$group] ?? [], true) ? 'checked' : '';

// Strona celowo bez JS/AJAX (w odróżnieniu od events-list.php) — JEDEN <form method="get">
// obejmujący wyszukiwarkę i cały sidebar filtrów (auto-submit na zmianie),
// prostszy, w pełni serwerowy wariant, wystarczający dla tej strony.
$renderRegionFilterGroup = function () use ($filterOptions, $isChecked) {
    if (empty($filterOptions['regions'])) return;
    ?>
    <div class="filter-group">
        <h4><?= __('Region') ?></h4>
        <?php foreach ($filterOptions['regions'] as $group): ?>
        <?php if ($group['label'] !== null): ?>
        <div class="filter-subheading"><?= htmlspecialchars($group['label']) ?></div>
        <?php endif; ?>
        <?php foreach ($group['items'] as $opt): ?>
        <label class="filter-opt<?= $group['label'] !== null ? ' filter-opt-nested' : '' ?>">
            <input type="checkbox" name="regions[]" value="<?= htmlspecialchars($opt['code']) ?>" <?= $isChecked('regions', $opt['code']) ?> onchange="this.form.submit()">
            <?= htmlspecialchars($opt['name']) ?>
        </label>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php
};

// Skrót chip-row — buduje link zachowujący aktualne wyszukiwanie tekstowe,
// ustawiający/czyszczący 'type' LUB 'verified' — dwie NIEZALEŻNE cechy (patrz
// komentarz przy Organizer::search()), stąd chip "Zweryfikowani" i chip
// "społecznościowi" nie są tym samym parametrem. Zwykłe linki, nie JS-owe
// przyciski (ta strona nie ma AJAX-a jak events-list.php) — świadomie NIE próbują
// zachować reszty filtrów sidebaru, to tylko szybki skrót.
$chipUrl = function (array $overrides) use ($filters) {
    $params = [];
    if (($filters['q'] ?? '') !== '') $params['q'] = $filters['q'];
    foreach ($overrides as $k => $v) {
        if ($v !== null) $params[$k] = $v;
    }
    $qs = http_build_query($params);
    return View::url('/organizatorzy') . ($qs !== '' ? '?' . $qs : '');
};
$activeChip = 'all';
if (!empty($filters['verifiedOnly'])) $activeChip = 'verified';
elseif (($filters['type'] ?? null) === 'peer') $activeChip = 'peer';
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<?php // Jedyna publiczna strona bez nagłówka H1 (SEO, 2026-09-14) — ukryty
      // wzorem strony głównej, żeby nie zmieniać układu ekranu. ?>
<h1 class="visually-hidden"><?= __('Organizatorzy wyjazdów rowerowych — kluby, grupy i biura podróży') ?></h1>
<form method="get" action="<?= View::url('/organizatorzy') ?>">
<section class="search-hero">
    <div class="search-row">
        <input class="search-input" type="text" name="q" placeholder="<?= htmlspecialchars(__('Szukaj: nazwa organizatora, miasto...')) ?>" value="<?= htmlspecialchars($filters['q'] ?? '') ?>">
        <button class="locate-btn" type="submit"><?= __('Szukaj') ?></button>
    </div>
    <div class="chip-row">
        <a href="<?= htmlspecialchars($chipUrl(['type' => null, 'verified' => null])) ?>" class="chip <?= $activeChip === 'all' ? 'active' : '' ?>"><?= __('Wszyscy') ?></a>
        <a href="<?= htmlspecialchars($chipUrl(['verified' => '1'])) ?>" class="chip <?= $activeChip === 'verified' ? 'active' : '' ?>"><?= __('✓ Zweryfikowani') ?></a>
        <a href="<?= htmlspecialchars($chipUrl(['type' => 'peer'])) ?>" class="chip <?= $activeChip === 'peer' ? 'active' : '' ?>"><?= __('Organizatorzy społecznościowi') ?></a>
    </div>
</section>

<div class="layout">
    <aside class="filters-panel">
        <?php $renderRegionFilterGroup(); ?>

        <?php if (!empty($filterOptions['organizerTypes'])): ?>
        <div class="filter-group">
            <h4><?= __('Typ organizatora') ?></h4>
            <?php foreach ($filterOptions['organizerTypes'] as $opt): ?>
            <label class="filter-opt"><input type="radio" name="type" value="<?= htmlspecialchars($opt['code']) ?>" <?= ($filters['type'] ?? '') === $opt['code'] ? 'checked' : '' ?> onchange="this.form.submit()"><?= $opt['code'] === 'professional_operator' ? __('Operator turystyczny') : htmlspecialchars($opt['name']) ?></label>
            <?php endforeach; ?>
            <label class="filter-opt"><input type="radio" name="type" value="" <?= empty($filters['type']) ? 'checked' : '' ?> onchange="this.form.submit()"><?= __('dowolny') ?></label>
        </div>
        <?php endif; ?>

        <?php if (!empty($filterOptions['bikeTypes'])): ?>
        <div class="filter-group">
            <h4><?= __('Specjalizacja') ?></h4>
            <?php foreach ($filterOptions['bikeTypes'] as $opt): ?>
            <label class="filter-opt"><input type="checkbox" name="bikeTypes[]" value="<?= htmlspecialchars($opt['code']) ?>" <?= $isChecked('bikeTypes', $opt['code']) ?> onchange="this.form.submit()"><?= htmlspecialchars($opt['name']) ?></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="filter-group">
            <h4><?= __('Minimalna ocena') ?></h4>
            <?php // .filter-opt, jak WSZYSTKIE pozostałe grupy filtrów na tej
                  // stronie. Wcześniej ta jedna grupa miała własne klasy
                  // (.rating-filter/.rating-opt), których w style.css nigdy nie
                  // było — przez co jako jedyna renderowała się bez stylu
                  // (zgłoszenie usera 2026-08-13: „niespójny widok CSS"). ?>
            <label class="filter-opt"><input type="radio" name="minRating" value="4.5" <?= ($filters['minRating'] ?? null) == 4.5 ? 'checked' : '' ?> onchange="this.form.submit()"><?= __('4.5 i wyżej') ?></label>
            <label class="filter-opt"><input type="radio" name="minRating" value="4.0" <?= ($filters['minRating'] ?? null) == 4.0 ? 'checked' : '' ?> onchange="this.form.submit()"><?= __('4.0 i wyżej') ?></label>
            <label class="filter-opt"><input type="radio" name="minRating" value="" <?= empty($filters['minRating']) ? 'checked' : '' ?> onchange="this.form.submit()"><?= __('dowolna') ?></label>
        </div>
    </aside>

    <main>
        <div class="results-head">
            <div class="results-count"><b><?= $total ?></b> <?= __n((int) $total, 'organizator', 'organizatorów', 'organizatorów') ?></div>
            <select class="sort-select" name="sort" onchange="this.form.submit()">
                <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>><?= __('Najwyżej oceniani') ?></option>
                <option value="events" <?= $sort === 'events' ? 'selected' : '' ?>><?= __('Najwięcej wyjazdów') ?></option>
            </select>
        </div>

        <?php if (empty($organizers)): ?>
        <p class="desc"><?= __('Brak organizatorów spełniających wybrane kryteria.') ?></p>
        <?php else: ?>
        <div class="org-grid">
            <?php foreach ($organizers as $o): ?>
            <?php require __DIR__ . '/../partials/organizer-card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</div>
</form>
