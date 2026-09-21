<?php
// views/web/pages/organizer-admin-edit.php
// Oczekuje: $organizer (Models\Organizer), $regions (Dictionary::groupedLeaves('region')),
// $billingProfile (?Models\OrganizerBillingProfile), $error (?string), $info (?string),
// $billingError (?string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\View;

$regions = $regions ?? [];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display" style="font-size:28px;">Edytuj: <?= htmlspecialchars($organizer->name) ?></h1>
<p class="desc spaced-below">
    <a href="<?= View::url('/organizatorzy/' . $organizer->slug) ?>" target="_blank" rel="noopener">Zobacz publiczny profil →</a>
</p>

<div class="action-row" style="margin-bottom:20px;">
    <form method="post" action="<?= View::url('/organizatorzy/' . $organizer->slug . '/weryfikacja') ?>">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-secondary"><?= $organizer->isVerified ? 'Cofnij weryfikację' : 'Zweryfikuj organizatora' ?></button>
    </form>
    <form method="post" action="<?= View::url('/admin/organizatorzy/' . $organizer->slug . '/aktywnosc') ?>" onsubmit="return <?= $organizer->isActive ? "confirm('Dezaktywować ten profil? Zniknie z publicznej listy i wyszukiwania — dane i wydarzenia zostają.')" : 'true' ?>;">
        <?= Csrf::field() ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars(View::url('/admin/organizatorzy/' . $organizer->slug . '/edytuj')) ?>">
        <button type="submit" class="btn btn-secondary"><?= $organizer->isActive ? 'Dezaktywuj profil' : 'Aktywuj profil' ?></button>
    </form>
</div>
<?php if (!$organizer->isActive): ?>
<div class="claim-link-box" style="margin-bottom:20px;">
    <div class="claim-link-label" style="margin-bottom:0;">Ten profil jest obecnie nieaktywny — niewidoczny na publicznej liście i w wyszukiwaniu.</div>
</div>
<?php endif; ?>

<?php
    // Ten sam partial co samoobsługowy formularz organizatora (billing-profile.php)
    // — patrz Resources\OrganizerProfileFormInput, które przetwarza oba POSTy
    // jednym kodem. $typeError/$typeSuccess to nazwy zmiennych, których oczekuje
    // partial (dopasowane do konwencji billing-profile.php).
    $formAction  = View::url('/admin/organizatorzy/' . $organizer->slug . '/edytuj');
    $typeError   = $error ?? null;
    $typeSuccess = $info ?? null;
    require __DIR__ . '/../partials/organizer-profile-form.php';
?>

<?php
    // Ten sam partial co samoobsługowa strona "Profil organizatora"
    // (billing-profile.php) — bez tego admin nie miał skąd sprawdzić, na jakie
    // konto/dane firmowe wypłacić organizatorowi.
    $formAction = View::url('/admin/organizatorzy/' . $organizer->slug . '/rozliczenia');
    require __DIR__ . '/../partials/organizer-billing-form.php';
?>
