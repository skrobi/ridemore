<?php
// views/web/pages/payments.php
// Oczekuje: $rows (EventRsvp::pendingForOrganizer()) — wiersze ze statusem
// 'oczekuje_platnosci', 'oczekuje_doplaty' LUB 'oczekuje_zwrotu' ze WSZYSTKICH
// wydarzeń organizatora. amount_paid to SUMA z rejestru wpłat (event_rsvp_payments).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

$pendingPayments = array_values(array_filter($rows, fn($r) => $r['status_code'] === 'oczekuje_platnosci'));
$pendingDeposits = array_values(array_filter($rows, fn($r) => $r['status_code'] === 'oczekuje_doplaty'));
$pendingRefunds  = array_values(array_filter($rows, fn($r) => $r['status_code'] === 'oczekuje_zwrotu'));

// Kwota faktycznie należna TERAZ (podpowiedź w polu formularza, organizator
// może ją nadpisać dowolną faktycznie otrzymaną kwotą):
// - oczekuje_platnosci: zaliczka (jeśli skonfigurowana), inaczej cała cena
//   (nic jeszcze nie wpłacono na tym etapie — amount_paid zawsze 0).
// - oczekuje_doplaty: dokładna reszta ceny (cena - suma dotychczasowych wpłat).
// - oczekuje_zwrotu: to, co FAKTYCZNIE wpłynęło przed rezygnacją — suma z
//   rejestru wpłat, niekoniecznie cała cena.
$amountFor = function (array $row): ?float {
    if ($row['price_amount'] === null) return null;
    $price = (float) $row['price_amount'];
    $deposit = $row['deposit_amount'] !== null ? (float) $row['deposit_amount'] : null;
    $paid = (float) $row['amount_paid'];
    return match ($row['status_code']) {
        'oczekuje_platnosci' => $deposit ?? $price,
        'oczekuje_doplaty'   => max(0, $price - $paid),
        'oczekuje_zwrotu'    => $paid,
        default              => $price,
    };
};
$amountNoteFor = function (array $row): ?string {
    return match ($row['status_code']) {
        'oczekuje_platnosci' => $row['deposit_amount'] !== null ? 'sugerowana zaliczka' : null,
        'oczekuje_doplaty'   => __('pozostało z') . ' ' . Format::price((float) $row['price_amount'], Format::currencySymbol($row['currency_code'] ?? 'PLN')),
        'oczekuje_zwrotu'    => __('wpłacono łącznie'),
        default              => null,
    };
};

$renderTable = function (array $items, string $action, string $actionLabel, string $dateLabel, string $dateField) use ($amountFor, $amountNoteFor) {
    ?>
    <div class="dash-table" style="margin-top:12px;">
        <div class="dash-row dash-head">
            <div class="dash-cell"><?= __('Wydarzenie') ?></div>
            <div class="dash-cell"><?= __('Uczestnik') ?></div>
            <div class="dash-cell"><?= __('Kwota') ?></div>
            <div class="dash-cell"><?= htmlspecialchars($dateLabel) ?></div>
            <div class="dash-cell"><?= __('Akcje') ?></div>
        </div>
        <?php foreach ($items as $row): ?>
        <div class="dash-row">
            <div class="dash-cell" data-label="<?= htmlspecialchars(__('Wydarzenie')) ?>">
                <a href="<?= View::url('/events/' . $row['event_slug']) ?>"><?= htmlspecialchars($row['event_title']) ?></a>
            </div>
            <div class="dash-cell" data-label="<?= htmlspecialchars(__('Uczestnik')) ?>">
                <?= htmlspecialchars($row['name'] ?: $row['email']) ?>
                <div class="dash-sub"><?= htmlspecialchars($row['email']) ?></div>
            </div>
            <div class="dash-cell" data-label="<?= htmlspecialchars(__('Kwota')) ?>">
                <?php $amount = $amountFor($row); $note = $amountNoteFor($row); ?>
                <?= $amount !== null ? htmlspecialchars(Format::price($amount, Format::currencySymbol($row['currency_code'] ?? 'PLN'))) : '—' ?>
                <?php if ($note): ?><div class="dash-sub"><?= htmlspecialchars($note) ?></div><?php endif; ?>
            </div>
            <div class="dash-cell" data-label="<?= htmlspecialchars($dateLabel) ?>"><?= htmlspecialchars(Format::dateShort($row[$dateField])) ?></div>
            <div class="dash-cell" data-label="<?= htmlspecialchars(__('Akcje')) ?>">
                <?php if ($action === 'potwierdz-platnosc'): ?>
                <form method="post" action="<?= View::url('/wydarzenia/' . $row['event_slug'] . '/uczestnicy/' . $row['user_id'] . '/potwierdz-platnosc') ?>" class="inline-amount-form">
                    <?= Core\Csrf::field() ?>
                    <input type="number" step="0.01" min="0.01" name="kwota" value="<?= htmlspecialchars((string) $amount) ?>" style="width:80px;">
                    <button type="submit" class="link-button"><?= htmlspecialchars($actionLabel) ?></button>
                </form>
                <?php else: ?>
                <form method="post" action="<?= View::url('/wydarzenia/' . $row['event_slug'] . '/uczestnicy/' . $row['user_id'] . '/' . $action) ?>">
                    <?= Core\Csrf::field() ?>
                    <button type="submit" class="link-button"><?= htmlspecialchars($actionLabel) ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
};
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display" style="font-size:28px;"><?= __('Płatności') ?></h1>
<p class="subline"><?= __('Wszystko, co wymaga Twojego działania, w jednym miejscu.') ?></p>

<?php if (empty($rows)): ?>
<p class="desc" style="margin-top:16px;"><?= __('Brak oczekujących płatności ani zwrotów.') ?></p>
<?php else: ?>

<?php if (!empty($pendingPayments)): ?>
<div class="pending-payment-section">
    <div class="pending-payment-title"><?= __('Oczekujące wpłaty ({n})', ['n' => count($pendingPayments)]) ?></div>
    <?php $renderTable($pendingPayments, 'potwierdz-platnosc', __('Potwierdź wpłatę'), __('Zapisano'), 'joined_at'); ?>
</div>
<?php endif; ?>

<?php if (!empty($pendingDeposits)): ?>
<div class="pending-payment-section">
    <div class="pending-payment-title"><?= __('Oczekujące dopłaty ({n})', ['n' => count($pendingDeposits)]) ?></div>
    <?php $renderTable($pendingDeposits, 'potwierdz-platnosc', __('Potwierdź wpłatę'), __('Ostatnia wpłata'), 'last_payment_confirmed_at'); ?>
</div>
<?php endif; ?>

<?php if (!empty($pendingRefunds)): ?>
<div class="pending-payment-section">
    <div class="pending-payment-title"><?= __('Oczekujące zwroty ({n})', ['n' => count($pendingRefunds)]) ?></div>
    <?php $renderTable($pendingRefunds, 'potwierdz-zwrot', __('Potwierdź zwrot'), __('Zgłoszono'), 'refund_requested_at'); ?>
</div>
<?php endif; ?>

<?php endif; ?>
