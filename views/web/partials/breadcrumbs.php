<?php
// views/web/partials/breadcrumbs.php
// Oczekuje $breadcrumbs: [['label' => string, 'url' => ?string], ...] — ostatni
// element bez 'url' (bieżąca strona, nie link). Pomijane, gdy $breadcrumbs puste.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
if (empty($breadcrumbs)) { return; }

// W APCE OKRUSZKÓW NIE MA — I TO JEST DECYZJA SPRZED TEGO PLIKU (2026-09-11).
//
// Ustalono ją przy przebudowie UX apki (tasks/done/apka-mobilna-ux.md, Fazy
// 1/2/4/5) i zastosowano W CZTERECH SZABLONACH z osobna, przez `if (!APP_IS_APP)`
// wokół `require`: `messages.php`, `events-list.php`, `account.php`,
// `event-page.php`. Pozostałe ~20 stron o tej zasadzie nie wiedziało — audyt
// z 2026-09-11 (prawdziwy UA apki, 375 px) pokazał okruszki na stronie trasy,
// przejazdu, „moich przejazdów", kroniki, profilu rowerzysty i skanowania
// skarbu. Na stronie przejazdu zawijały się do DWÓCH linii i zjadały górę
// ekranu telefonu.
//
// Powód, dla którego w apce ich nie chcemy, jest zresztą mocniejszy niż
// oszczędność miejsca: okruszki to nawigacja PIONOWA po drzewie serwisu,
// a apka nawiguje dolnym paskiem i gestem wstecz. Ścieżka „Strona główna /
// Panel / Moje przejazdy" nie opisuje niczego, po czym w apce da się chodzić.
//
// Bramka stoi TUTAJ, a nie w kolejnych dwudziestu szablonach, bo to jedno
// miejsce zna odpowiedź na pytanie „czy pokazywać okruszki". Następna strona
// z okruszkami dostanie tę zasadę bez pamiętania o niej.
//
// JSON-LD `BreadcrumbList` wychodzi razem z nawigacją — świadomie. To dane dla
// robota wyszukiwarki, a żaden robot nie przedstawia się jako `ridemore-app`
// (patrz `core/bootstrap.php` — APP_IS_APP bierze się WYŁĄCZNIE z tego
// dopisku w User-Agencie). W apce ten blok byłby kilkoma setkami bajtów
// transferu komórkowego, których nikt nigdy nie przeczyta.
if (APP_IS_APP) { return; }

// BreadcrumbList obok widocznej nawigacji — ostatni element (bez 'url', patrz
// docblock wyżej) dostaje jako 'item' bieżący canonical URL, Google woli mieć
// go jawnie nawet dla strony, na której już jesteśmy.
$crumbSchema = [];
foreach ($breadcrumbs as $i => $crumb) {
    $crumbSchema[] = [
        '@type'    => 'ListItem',
        'position' => $i + 1,
        'name'     => $crumb['label'],
        'item'     => !empty($crumb['url']) ? Utils\View::absoluteFromLocal($crumb['url']) : Utils\View::currentCanonicalUrl(),
    ];
}
?>
<script type="application/ld+json"><?= json_encode([
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => $crumbSchema,
// Bez JSON_UNESCAPED_SLASHES celowo — patrz komentarz w Utils\JsonLd::render().
], JSON_UNESCAPED_UNICODE) ?></script>
<nav class="breadcrumbs" aria-label="<?= htmlspecialchars(__('Okruszki nawigacyjne')) ?>">
    <?php foreach ($breadcrumbs as $i => $crumb): ?>
    <?php if ($i > 0): ?><span class="breadcrumbs-sep">/</span><?php endif; ?>
    <?php if (!empty($crumb['url'])): ?>
    <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
    <?php else: ?>
    <span class="breadcrumbs-current"><?= htmlspecialchars($crumb['label']) ?></span>
    <?php endif; ?>
    <?php endforeach; ?>
</nav>
