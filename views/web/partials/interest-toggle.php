<?php
// views/web/partials/interest-toggle.php
// Oczekuje $e i $myStatus w scope (jak w event-page.php). Najlżejsze możliwe
// oznaczenie "obserwuję to wydarzenie" — pokazywane obok głównego CTA we
// wszystkich gałęziach (darmowe/płatne, internal/external), nie blokuje
// głównej ścieżki zapisu. 'anulowany' liczy się tu jak brak zapisu (spójnie
// z guardami przy /zapisz-zewnetrzne i /zainteresowany) — po "Przestań
// obserwować" (-> anulowany) można oznaczyć zainteresowanie ponownie. Gdy
// $myStatus to jakikolwiek PRAWDZIWY zapis — nic się nie renderuje, przełącznik
// nie ma sensu.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<?php if ($myStatus === 'zainteresowany'): ?>
<form method="post" action="<?= Utils\View::url('/wydarzenia/' . $e['slug'] . '/anuluj-udzial') ?>" onsubmit="return confirm(__('Przestać obserwować to wydarzenie?'));">
    <?= Core\Csrf::field() ?>
    <input type="hidden" name="edition_id" value="<?= $e['selectedEditionId'] ?>">
    <button type="submit" class="btn btn-secondary"><?= __('Przestań obserwować') ?></button>
</form>
<?php elseif (in_array($myStatus, [null, 'anulowany'], true)): ?>
<form method="post" action="<?= Utils\View::url('/wydarzenia/' . $e['slug'] . '/zainteresowany') ?>">
    <?= Core\Csrf::field() ?>
    <input type="hidden" name="edition_id" value="<?= $e['selectedEditionId'] ?>">
    <button type="submit" class="btn btn-secondary"><?= __('Zainteresowany') ?></button>
</form>
<?php endif; ?>
