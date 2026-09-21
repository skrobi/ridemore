<?php
// views/web/pages/trails.php
// KATALOG ZNANYCH TRAS — /trasy.
//
// Publiczny odpowiednik sekcji „Znane trasy" z /odkrycia, ale bez ograniczenia
// do pięciu pozycji i dostępny bez logowania. Ta sama karta (.disc-trail), bo
// ten sam byt: szlak z procentem ukończenia. Gość widzi karty bez paska
// postępu — procent bez konta byłby obietnicą bez pokrycia.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
require_once __DIR__ . '/../partials/trail-card.php';

$routes = $routes ?? [];
$isLoggedIn = $isLoggedIn ?? false;

$done = 0;
foreach ($routes as $r) { if (!empty($r['isComplete'])) { $done++; } }
?>
<h1 class="display"><?= __('Znane trasy') ?></h1>
<p class="desc spaced-below">
    <?= __('Szlaki, które zalicza się samym jeżdżeniem: Velo, Green Velo, trasy zawodów i lokalne
    klasyki. Nie ma tu zapisów ani terminów — przejedź kawałek, a postęp doliczy się sam
    z Twoich śladów.') ?>

</p>

<?php if (!$routes): ?>
<div class="card">
    <p class="desc" style="margin:0;"><?= __('Nie ma jeszcze żadnej znanej trasy w katalogu.') ?></p>
</div>
<?php else: ?>

<?php if ($isLoggedIn): ?>
<p class="desc spaced-below">
    <b><?= count($routes) ?></b> <?= __('tras w katalogu') ?><?php if ($done > 0): ?> · <b><?= $done ?></b> <?= __('masz ukończonych') ?><?php endif; ?>
</p>
<?php endif; ?>

<div class="disc-trails disc-trails--all">
    <?php foreach ($routes as $route): ?>
    <?php // Karta = WSPÓLNY PARTIAL (2026-09-10). Wcześniej stała tu kopia
          // tego samego bloku co w `discovery.php`, obie już lekko rozjechane. ?>
    <?php renderTrailCard($route, (bool) $isLoggedIn, ['guestFoot' => true]); ?>
    <?php endforeach; ?>
</div>

<div class="disc-cta">
    <div>
        <h3><?= $isLoggedIn ? __('Zaliczaj trasy jadąc') : __('Załóż konto i śledź postęp') ?></h3>
        <p><?= __('Postęp na szlaku liczy się z Twoich przejazdów — nie musisz nic zgłaszać ani
            odhaczać. Wystarczy wgrać ślad z wyjazdu.') ?></p>
    </div>
    <a class="btn" href="<?= View::url($isLoggedIn ? '/odkrycia' : '/rejestracja') ?>">
        <?= $isLoggedIn ? __('Moja mapa odkryć') : __('Dołącz') ?>
    </a>
</div>
<?php endif; ?>
