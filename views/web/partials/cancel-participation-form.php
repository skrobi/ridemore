<?php
// views/web/partials/cancel-participation-form.php
// Oczekuje $e i $cancellationDeadlinePassed w scope (jak w event-page.php) —
// mały, powtarzalny formularz "Anuluj udział" używany w kilku gałęziach CTA
// (płatne, internal i external). Gdy termin z EventPricing::isCancellationDeadlinePassed()
// minął, przycisk się nie pokazuje — trzeba napisać do organizatora (patrz
// event-participants.php, tam anulowanie w imieniu uczestnika nie ma tego limitu).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<?php if (!empty($cancellationDeadlinePassed)): ?>
<p class="desc" style="font-size:13px;margin:0;"><?= __('Termin samodzielnego anulowania minął — napisz do organizatora.') ?></p>
<?php else: ?>
<form method="post" action="<?= Utils\View::url('/wydarzenia/' . $e['slug'] . '/anuluj-udzial') ?>" onsubmit="return confirm(__('Na pewno chcesz zrezygnować z udziału? Jeśli już zapłaciłeś/aś, organizator zostanie poproszony o zwrot.'));">
    <?= Core\Csrf::field() ?>
    <input type="hidden" name="edition_id" value="<?= $e['selectedEditionId'] ?>">
    <button type="submit" class="btn btn-secondary"><?= __('Anuluj udział') ?></button>
</form>
<?php endif; ?>
