<?php
// views/web/partials/organizer-billing-form.php
// Oczekuje: $billingProfile (?Models\OrganizerBillingProfile), $formAction (string),
// $billingError (?string). Współdzielone przez samoobsługowy profil rozliczeniowy
// (billing-profile.php, POSTuje do /admin/profil-rozliczeniowy) i edycję z panelu
// admina (organizer-admin-edit.php, POSTuje do /admin/organizatorzy/{slug}/rozliczenia)
// — dane potrzebne, żeby wiedzieć, na jakie konto wypłacić/rozliczyć organizatora.
//
// $sectioned (bool, domyślnie false) — jak w organizer-profile-form.php, owija
// całość w .section-panel[data-section=rozliczenia] pod szynę billing-profile.php.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;

$legalName         = $billingProfile->legalName         ?? '';
$address           = $billingProfile->address           ?? '';
$taxId             = $billingProfile->taxId             ?? '';
$bankAccountHolder = $billingProfile->bankAccountHolder  ?? '';
$bankAccount       = $billingProfile->bankAccount        ?? '';
$isComplete        = $billingProfile && $billingProfile->completedAt !== null;
$sectioned         = $sectioned ?? false;
?>
<?php if ($sectioned): ?><div class="section-panel" data-section="rozliczenia"><?php endif; ?>

<?php if ($isComplete): ?>
<p style="padding:13px 16px;background:var(--accent-soft);color:var(--accent-dark);border-radius:11px;font-size:14px;margin-bottom:16px;"><?= __('Dane kompletne — możesz publikować płatne wyjazdy.') ?></p>
<?php endif; ?>
<?php if (!empty($billingError)): ?>
<p class="form-error"><?= htmlspecialchars($billingError) ?></p>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($formAction) ?>">
    <?= Csrf::field() ?>

    <div class="box">
        <h3><?= __('Dane do faktury') ?> <span class="vis vis--priv"><?= __('niewidoczne') ?></span></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Trafią na faktury, które wystawisz uczestnikom.') ?></p>
        <div class="two-col">
            <div class="form-field">
                <label for="legal_name"><?= __('Nazwa firmy lub imię i nazwisko') ?></label>
                <input id="legal_name" class="search-input" type="text" name="legal_name" required value="<?= htmlspecialchars($legalName) ?>">
            </div>
            <div class="form-field">
                <label for="tax_id"><?= __('NIP') ?></label>
                <input id="tax_id" class="search-input" type="text" name="tax_id" placeholder="<?= htmlspecialchars(__('opcjonalnie')) ?>" value="<?= htmlspecialchars($taxId) ?>">
            </div>
        </div>
        <div class="form-field">
            <label for="address"><?= __('Adres') ?></label>
            <input id="address" class="search-input" type="text" name="address" required value="<?= htmlspecialchars($address) ?>">
        </div>
        <div class="stg-save">
            <button class="btn btn--sm" type="submit"><?= __('Zapisz dane do faktury') ?></button>
        </div>
    </div>

    <div class="box">
        <h3><?= __('Konto do wypłat') ?> <span class="vis vis--priv"><?= __('niewidoczne') ?></span></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Posiadacz konta może być inną osobą lub podmiotem niż dane do faktury — np. firma rozlicza się prywatnym kontem właściciela.') ?></p>
        <div class="two-col">
            <div class="form-field">
                <label for="bank_account_holder"><?= __('Posiadacz konta') ?></label>
                <input id="bank_account_holder" class="search-input" type="text" name="bank_account_holder" placeholder="<?= htmlspecialchars(__('Imię i nazwisko lub nazwa firmy')) ?>" required value="<?= htmlspecialchars($bankAccountHolder) ?>">
            </div>
            <div class="form-field">
                <label for="bank_account"><?= __('Numer konta (IBAN)') ?></label>
                <input id="bank_account" class="search-input mono" type="text" name="bank_account" placeholder="<?= htmlspecialchars(__('PL00 0000 0000 0000 0000 0000 0000')) ?>" required value="<?= htmlspecialchars($bankAccount) ?>">
            </div>
        </div>
        <div class="stg-save">
            <button class="btn btn--sm" type="submit"><?= __('Zapisz konto do wypłat') ?></button>
        </div>
    </div>

    <div class="box">
        <h3><?= __('Jak działają płatności') ?></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 0;"><?= __('Nie pośredniczymy w płatnościach i nie pobieramy prowizji. Uczestnik płaci
            bezpośrednio Tobie, a Ty potwierdzasz wpłatę w panelu. Te dane trzymamy po to, żeby wygenerować dokumenty
            i żeby uczestnik wiedział, komu płaci.') ?> <a href="<?= Utils\View::url('/jak-to-dziala') ?>"><?= __('Jak to działa →') ?></a></p>
    </div>
</form>

<?php if ($sectioned): ?></div><?php endif; ?>
