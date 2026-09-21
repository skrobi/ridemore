<?php
// views/web/partials/trail-actions.php
// AKCJE STRONY ZNANEJ TRASY — GPX, dojazd na start, wyjazdy w okolicy.
//
// Powstał 2026-09-11 ze zgłoszenia usera: „nawiguj na start trasy i wyjazdy
// w tych stronach powinny znajdować się zaraz z boku Pobierz GPX". Do tej daty
// GPX stał na okładce (przeniesiony 2026-09-10), a pozostałe dwa przyciski
// zostały nad nią — jedna grupa akcji rozdzielona na dwa piętra strony.
//
// DLACZEGO PARTIAL, A NIE DWIE KOPIE: te przyciski mają dwa miejsca
// zamieszkania, bo trasa nie zawsze ma okładkę. Z okładką idą na zdjęcie,
// bez niej zostają w nagłówku — ta sama reguła, którą od 2026-09-10 rządzą się
// nazwa trasy i metryczka. Dwie kopie rozjechałyby się przy pierwszej zmianie.
//
// Oczekuje w zasięgu: $route (wiersz znanej trasy), $startPoint (z
// TrailController::startPoint).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

// KOLEJNOŚĆ: od najbardziej „zabierz to ze sobą" do „co jeszcze jest w okolicy".
// GPX ostatni, czyli najbardziej na prawo — zostaje dokładnie tam, gdzie stał
// sam na okładce, a dwa nowe dochodzą z jego lewej strony.

// DOJAZD NA START (2026-09-10). Trasa bez dowiezienia człowieka pod jej
// początek jest tylko obrazkiem — a start znamy, bo niesie go pierwsza próbka
// profilu wysokości (patrz TrailController::startPoint). Napis mówi DOKĄD:
// samo „Nawiguj" znaczy w apce co innego (prowadzenie po śladzie, kontrakt
// tasks/nawigacja-w-apce-ODLOZONE.md).
$navLat = $startPoint['lat'] ?? null;
$navLon = $startPoint['lon'] ?? null;
$navLabel = __('Nawiguj na start trasy');
$navClass = 'btn btn-secondary';
require __DIR__ . '/nav-button.php';
?>
<?php if (!empty($route['region_code'])): ?>
<?php // PARAMETR NAZYWA SIĘ `regions`, NIE `region` (naprawione 2026-09-11,
      // zgłoszenie usera). Listę wydarzeń czyta EventsListController przez
      // $arr('regions') — `?region=` przelatywał bez śladu i przycisk
      // prowadził na nieprzefiltrowaną listę wszystkich wyjazdów w Polsce.
      // Skalar wystarczy: `(array) 'beskidy'` daje jednoelementową tablicę,
      // więc nie trzeba tu składni `regions[]=`. ?>
<a class="btn btn-secondary" href="<?= View::url('/wydarzenia') ?>?regions=<?= urlencode($route['region_code']) ?>">
    <?= __('Wyjazdy w tych stronach') ?>

</a>
<?php endif; ?>
<?php if (!empty($route['gpx_url'])): ?>
<a class="btn btn-secondary" href="<?= htmlspecialchars(View::url($route['gpx_url'])) ?>"
   download="<?= htmlspecialchars($route['slug']) ?>.gpx"><?= __('Pobierz GPX ↓') ?></a>
<?php endif; ?>
