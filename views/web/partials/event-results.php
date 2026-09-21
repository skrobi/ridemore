<?php
// views/web/partials/event-results.php
// Oczekuje w scope: $events (array kart), $total (int), $limit (int), $sort (string),
// $view ('upcoming'|'completed', opcjonalnie — domyślnie 'upcoming'),
// $mine (bool, opcjonalnie — domyślnie false; oba wymiary łączą się dowolnie),
// $filters, $filterOptions (patrz Controllers\EventsListController — dostępne
// już w gałęzi ajax=1, potrzebne tu do podpisania tagów aktywnych filtrów).
// To jest fragment podmieniany przez JS przy filtrowaniu (patrz events-list.php) —
// całość opakowana w #results-region. Widok mapy NIE jest tu renderowany —
// żyje poza tym regionem (patrz events-list.php), bo mapa nie jest paginowana jak
// karty i musi przetrwać podmianę tego fragmentu (patrz Event::mapPins()).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;

$view          = $view ?? 'upcoming';
$mine          = $mine ?? false;
$filters       = $filters ?? [];
$filterOptions = $filterOptions ?? [];
$hasMore       = $limit < $total;
$countLabel    = match (true) {
    $mine && $view === 'completed' => __('Twoich zakończonych wydarzeń'),
    $mine                          => __('Twoich wydarzeń'),
    $view === 'completed'          => __('zakończonych wydarzeń'),
    default                        => __('nadchodzących wydarzeń'),
};

// Tagi aktywnych filtrów — tylko te sterowane z panelu bocznego + wyszukiwarka
// (NIE odzwierciedlamy tu chipów "Darmowe"/"Ten weekend"/"Szukają towarzystwa"
// z paska nad panelem — te są już widoczne jako podświetlony chip, drugi,
// redundantny tag niczego by nie dodawał). $filters['paid']==='free' jest
// wyjątkiem: może pochodzić z panelu ("Tylko bezpłatne") ALBO z chipa
// "Darmowe" — pokazujemy go i tak, usuwanie (patrz events-list.php,
// removeFilterValue) czyści oba źródła naraz.
$labelMaps = [];
foreach (['eventTypes', 'difficulties', 'paces', 'bikeTypes'] as $g) {
    $labelMaps[$g] = [];
    foreach ($filterOptions[$g] ?? [] as $opt) {
        $labelMaps[$g][$opt['code']] = $opt['name'];
    }
}
$labelMaps['regions'] = [];
foreach ($filterOptions['regions'] ?? [] as $group) {
    foreach ($group['items'] as $opt) {
        $labelMaps['regions'][$opt['code']] = $opt['name'];
    }
}
$durationLabels = ['1' => __('1 dzień'), '2-3' => __('2–3 dni'), '4plus' => __('4 dni i więcej')];

$tags = [];
foreach (['eventTypes', 'difficulties', 'paces', 'bikeTypes', 'regions'] as $g) {
    foreach ($filters[$g] ?? [] as $code) {
        $tags[] = ['group' => $g, 'value' => $code, 'label' => $labelMaps[$g][$code] ?? $code];
    }
}
foreach ($filters['durationBuckets'] ?? [] as $b) {
    $tags[] = ['group' => 'durationBuckets', 'value' => $b, 'label' => $durationLabels[$b] ?? $b];
}
if (!empty($filters['q'])) {
    $tags[] = ['group' => 'q', 'value' => '', 'label' => '„' . $filters['q'] . '”'];
}
if (($filters['maxDistanceKm'] ?? null) !== null && $filters['maxDistanceKm'] < 300) {
    $tags[] = ['group' => 'maxDistanceKm', 'value' => '', 'label' => __('trasa do {n} km', ['n' => (int) $filters['maxDistanceKm']])];
}
if (($filters['radiusKm'] ?? null) !== null && $filters['radiusKm'] < 300) {
    $tags[] = ['group' => 'radiusKm', 'value' => '', 'label' => __('do {n} km od Ciebie', ['n' => (int) $filters['radiusKm']])];
}
if (($filters['maxPriceAmount'] ?? null) !== null && $filters['maxPriceAmount'] < 2000) {
    $tags[] = ['group' => 'maxPriceAmount', 'value' => '', 'label' => __('budżet do {n} zł', ['n' => (int) $filters['maxPriceAmount']])];
}
if (!empty($filters['verifiedOnly'])) {
    $tags[] = ['group' => 'verifiedOnly', 'value' => '', 'label' => __('Tylko zweryfikowani')];
}
if (!empty($filters['hasSpotsOnly'])) {
    $tags[] = ['group' => 'hasSpotsOnly', 'value' => '', 'label' => __('Są wolne miejsca')];
}
if (($filters['paid'] ?? null) === 'free') {
    $tags[] = ['group' => 'paid', 'value' => '', 'label' => __('Tylko bezpłatne')];
}
if (!empty($filters['dateFrom'])) {
    $tags[] = ['group' => 'dateFrom', 'value' => '', 'label' => __('od {data}', ['data' => htmlspecialchars(Format::dateShort($filters['dateFrom']))])];
}
if (!empty($filters['dateTo'])) {
    $tags[] = ['group' => 'dateTo', 'value' => '', 'label' => __('do {data}', ['data' => htmlspecialchars(Format::dateShort($filters['dateTo']))])];
}

// "Moje wydarzenia" — rezerwacje oczekujące na płatność mają się rzucać w
// oczy jako osobna, wyróżniona sekcja NAD zwykłą siatką, nie zmieszane z resztą
// (Event::forParticipant() sortuje je już jako pierwsze, tu tylko rozdzielamy
// na listy do renderu). Zainteresowania — odwrotnie, najmniej pilne, osobna
// stonowana sekcja POD główną siatką. Ten cały podział na 3 grupy (i stary
// event-card.php z jego stanami pending/watched) dotyczy WYŁĄCZNIE $mine —
// makieta przebudowy (szablony/wydarzenia.html) go nie obejmuje, więc zwykła
// lista (nie-moja) dostaje nowy, bogatszy komponent karty niżej.
$pendingEvents = [];
$interestedEvents = [];
$restEvents = $events;
if ($mine) {
    $pendingEvents = array_values(array_filter($events, fn($ev) => ($ev['myRsvpStatus'] ?? null) === 'oczekuje_platnosci'));
    $interestedEvents = array_values(array_filter($events, fn($ev) => ($ev['myRsvpStatus'] ?? null) === 'zainteresowany'));
    $restEvents = array_values(array_filter($events, fn($ev) => !in_array($ev['myRsvpStatus'] ?? null, ['oczekuje_platnosci', 'zainteresowany'], true)));
}

// Separator miesięczny w siatce ("Sierpień 2026", patrz szablony/wydarzenia.html)
// — tylko dla zwykłej listy, grupowanie po miesiącu daty startu. Miesiące po
// polsku przez IntlDateFormatter byłyby "poprawniejsze", ale ta apka nigdzie
// indziej nie zakłada rozszerzenia intl — prosta tablica nazw wystarcza.
$monthNames = [1 => __('Styczeń'), __('Luty'), __('Marzec'), __('Kwiecień'), __('Maj'), __('Czerwiec'), __('Lipiec'), __('Sierpień'), __('Wrzesień'), __('Październik'), __('Listopad'), __('Grudzień')];
$monthLabelFor = function (string $date) use ($monthNames): string {
    $ts = strtotime($date);
    return $monthNames[(int) date('n', $ts)] . ' ' . date('Y', $ts);
};
?>
<div id="results-region" class="results-region">
    <div class="toolbar">
        <button class="btn btn-secondary mobfilt" type="button" data-role="mobile-filter-toggle" aria-expanded="false" aria-controls="filters">
            <?= Utils\Icon::render('grid') ?> Filtry<?= $tags ? ' (' . count($tags) . ')' : '' ?>
        </button>
        <span class="toolbar__n"><b><?= $total ?></b> <?= $countLabel ?></span>
        <?php if ($view === 'upcoming' && !$mine): ?>
        <label class="sort-row"><?= __('Sortuj') ?>

            <select data-role="sort">
                <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>><?= __('Najbliżej w czasie') ?></option>
                <option value="spots" <?= $sort === 'spots' ? 'selected' : '' ?>><?= __('Najwięcej miejsc') ?></option>
                <option value="route_asc" <?= $sort === 'route_asc' ? 'selected' : '' ?>><?= __('Najkrótszy dystans') ?></option>
                <option value="route_desc" <?= $sort === 'route_desc' ? 'selected' : '' ?>><?= __('Najdłuższy dystans') ?></option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>><?= __('Cena rosnąco') ?></option>
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>><?= __('Ostatnio dodane') ?></option>
                <?php if ($sort === 'near'): ?>
                <option value="near" selected><?= __('Najbliżej mnie') ?></option>
                <?php endif; ?>
            </select>
        </label>
        <?php endif; ?>
    </div>

    <?php if ($tags): ?>
    <div class="active-filters" aria-label="<?= htmlspecialchars(__('Aktywne filtry')) ?>">
        <?php foreach ($tags as $tag): ?>
        <span class="tagf"><?= htmlspecialchars($tag['label']) ?> <button type="button" data-role="remove-filter" data-filter-group="<?= htmlspecialchars($tag['group']) ?>" data-filter-value="<?= htmlspecialchars($tag['value']) ?>" aria-label="<?= htmlspecialchars(__('Usuń filtr')) ?>">×</button></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($events)): ?>
    <p class="desc"><?= __('Brak wydarzeń pasujących do wybranych filtrów.') ?></p>
    <?php elseif ($mine): ?>

    <?php if (!empty($pendingEvents)): ?>
    <div class="pending-payment-section">
        <div class="pending-payment-title"><?= __('Oczekuje płatności ({n})', ['n' => count($pendingEvents)]) ?></div>
        <div class="event-grid">
            <?php foreach ($pendingEvents as $ev): ?>
            <?php require __DIR__ . '/event-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($restEvents)): ?>
    <div class="event-grid">
        <?php foreach ($restEvents as $ev): ?>
        <?php require __DIR__ . '/event-card.php'; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($interestedEvents)): ?>
    <div class="watched-section">
        <div class="watched-title">Obserwowane (<?= count($interestedEvents) ?>)</div>
        <div class="event-grid">
            <?php foreach ($interestedEvents as $ev): ?>
            <?php require __DIR__ . '/event-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>

    <div class="event-grid-wide">
        <?php $lastMonth = null; ?>
        <?php foreach ($events as $ev): ?>
        <?php $month = $monthLabelFor($ev['startDate']); ?>
        <?php if ($month !== $lastMonth): $lastMonth = $month; ?>
        <div class="month-sep"><b><?= htmlspecialchars($month) ?></b><hr></div>
        <?php endif; ?>
        <?php require __DIR__ . '/home-event-card.php'; ?>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <?php if ($hasMore): ?>
    <div class="more">
        <button type="button" class="btn btn-secondary" data-role="load-more" data-next-limit="<?= $limit + 24 ?>"><?= __('Pokaż kolejne {n} wyjazdów', ['n' => min(12, $total - $limit)]) ?></button>
    </div>
    <?php endif; ?>
</div>
