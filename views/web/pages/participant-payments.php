<?php
// views/web/pages/participant-payments.php
// Oczekuje: $event (Models\Event), $participant (Models\User), $rsvp (EventRsvp::forEditionAndUser()),
// $payments (EventRsvp::paymentsForRsvp()).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

$currencySymbol = Format::currencySymbol($event->pricing->currencyCode);
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display" style="font-size:28px;"><?= __('Historia wpłat') ?></h1>
<p class="subline"><?= htmlspecialchars($participant->displayName()) ?> — <?= htmlspecialchars($event->title) ?></p>

<div class="dash-table" style="margin-top:20px; max-width:520px;">
    <div class="dash-row">
        <div class="dash-cell"><?= __('Wpłacono łącznie') ?></div>
        <div class="dash-cell"><?= htmlspecialchars(Format::price((float) $rsvp['amount_paid'], $currencySymbol)) ?></div>
    </div>
    <div class="dash-row">
        <div class="dash-cell"><?= __('Kwota wymagana') ?></div>
        <div class="dash-cell"><?= htmlspecialchars(Format::price($event->pricing->amount, $currencySymbol)) ?></div>
    </div>
</div>

<?php if (empty($payments)): ?>
<p class="desc" style="margin-top:16px;"><?= __('Brak zarejestrowanych wpłat.') ?></p>
<?php else: ?>
<div class="dash-table" style="margin-top:20px;">
    <div class="dash-row dash-head">
        <div class="dash-cell"><?= __('Data') ?></div>
        <div class="dash-cell"><?= __('Kwota') ?></div>
        <div class="dash-cell"><?= __('Potwierdził') ?></div>
    </div>
    <?php foreach ($payments as $p): ?>
    <div class="dash-row">
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Data')) ?>"><?= htmlspecialchars(Format::dateShort($p['confirmed_at'])) ?></div>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Kwota')) ?>"><?= htmlspecialchars(Format::price((float) $p['amount'], $currencySymbol)) ?></div>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Potwierdził')) ?>"><?= htmlspecialchars($p['confirmed_by_name']) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p style="margin-top:20px;"><a href="<?= View::url('/wydarzenia/' . $event->slug . '/uczestnicy') ?>"><?= __('← Wróć do uczestników') ?></a></p>
