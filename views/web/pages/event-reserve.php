<?php
// views/web/pages/event-reserve.php
// Oczekuje: $event (Models\Event, z pricing), $edition (Models\EventEdition —
// WYBRANY turnus, patrz Support::requirePayableInternalEvent()), $billingProfile
// (?Models\UserBillingProfile), $billingComplete (bool), $deadlineDays (int),
// $billingError (?string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<div class="auth-page auth-page-wide">
    <h1 class="display"><?= __('Rezerwacja miejsca') ?></h1>
    <p class="subline"><?= htmlspecialchars($event->title) ?></p>

    <div class="reserve-summary">
        <div class="reserve-summary-row"><span><?= __('Termin') ?></span><b><?= htmlspecialchars(Format::dateP($edition->startDate)) ?></b></div>
        <div class="reserve-summary-row"><span><?= __('Kwota') ?></span><b><?= htmlspecialchars(Format::price($event->pricing->amount, Format::currencySymbol($event->pricing->currencyCode))) ?></b></div>
        <?php if ($event->pricing->depositAmount !== null): ?>
        <div class="reserve-summary-row"><span><?= __('Zaliczka') ?></span><b><?= htmlspecialchars(Format::price($event->pricing->depositAmount, Format::currencySymbol($event->pricing->currencyCode))) ?></b></div>
        <?php endif; ?>
        <div class="reserve-summary-row"><span><?= __('Termin płatności') ?></span><b><?= __('{n} dni od rezerwacji', ['n' => (int) $deadlineDays]) ?></b></div>
    </div>
    <p class="desc spaced-below"><?= __('To jeszcze nie jest płatność — po rezerwacji dostaniesz mailem numer konta organizatora i termin wpłaty. Miejsce liczy się jako zajęte dopiero po potwierdzeniu wpłaty.') ?></p>

    <?php if (!empty($billingError)): ?>
    <p class="form-error"><?= htmlspecialchars($billingError) ?></p>
    <?php endif; ?>

    <form class="auth-form" method="post" action="<?= View::url('/wydarzenia/' . $event->slug . '/zapisz') ?>">
        <?= Core\Csrf::field() ?>
        <input type="hidden" name="edition_id" value="<?= $edition->id ?>">

        <?php if (!$billingComplete): ?>
        <p class="subline"><?= __('Dane do faktury') ?></p>
        <input class="search-input" type="text" name="legal_name" placeholder="<?= htmlspecialchars(__('Imię i nazwisko (lub nazwa firmy)')) ?>" required maxlength="200" value="<?= htmlspecialchars($billingProfile->legalName ?? '') ?>">
        <input class="search-input" type="text" name="address" placeholder="<?= htmlspecialchars(__('Adres')) ?>" required value="<?= htmlspecialchars($billingProfile->address ?? '') ?>">
        <input class="search-input" type="text" name="tax_id" placeholder="<?= htmlspecialchars(__('NIP (opcjonalnie, dla faktury na firmę)')) ?>" value="<?= htmlspecialchars($billingProfile->taxId ?? '') ?>">
        <button class="btn" type="submit"><?= __('Zarezerwuj miejsce') ?></button>
        <?php else: ?>
        <p class="subline"><?= __('Dane do faktury') ?></p>
        <p class="desc"><?= htmlspecialchars($billingProfile->legalName) ?><br><?= htmlspecialchars($billingProfile->address) ?><?= $billingProfile->taxId ? '<br>NIP: ' . htmlspecialchars($billingProfile->taxId) : '' ?></p>
        <p class="desc spaced-below"><a href="<?= View::url('/admin/moje-konto') ?>"><?= __('Zmień dane') ?></a></p>
        <button class="btn" type="submit"><?= __('Potwierdź rezerwację') ?></button>
        <?php endif; ?>
    </form>
</div>
