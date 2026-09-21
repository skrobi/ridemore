<?php
// views/web/pages/billing-profile.php
// Przebudowa wg szablony/profil-organizatora.html (2026-08-01) — pionowa
// szyna sekcji (Zdjęcia/Kim jesteście/Lokalizacja i kontakt/Bezpieczeństwo/
// Dane rozliczeniowe) zamiast jednego długiego formularza. Pola/formularze
// bez zmian — patrz partials/organizer-profile-form.php (z $sectioned=true)
// i partials/organizer-billing-form.php, oba współdzielone z panelem admina
// (organizer-admin-edit.php, $sectioned tam zostaje false — jedna strona
// przewijana, bez zakładek).
//
// Oczekuje: $profile (?Models\OrganizerBillingProfile), $error (?string),
// $organizer (?Models\Organizer, null gdy user nie ma jeszcze organizer_profiles
// — dopiero pierwszy dodany event go tworzy, patrz Organizer::ensureProfile()),
// $completeness (?array, patrz Models\Organizer::completeness() — null gdy
// $organizer null), $typeError (?string), $typeSuccess (?string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

$regions = $regions ?? [];
$completeness = $completeness ?? null;

// Domyślnie pierwsza dostępna zakładka — "zdjecia" gdy mamy już profil
// organizatora, inaczej od razu "rozliczenia" (jedyna dostępna bez niego).
$activeSection = $organizer
    ? (!empty($typeError) ? 'dane' : 'zdjecia')
    : 'rozliczenia';
if (!empty($error)) $activeSection = 'rozliczenia';
$isActive = fn(string $s) => $activeSection === $s;

// Karta "Kompletność profilu" — pierwsza w sekcji Zdjęcia, patrz
// Models\Organizer::completeness() (jedno źródło prawdy współdzielone z
// bannerem "Widok właściciela" na publicznym profilu). Budowana tu (nie w
// partialu) bo dotyczy WYŁĄCZNIE samoobsługowej strony, nie panelu admina.
$extraZdjeciaBoxHtml = null;
if ($completeness) {
    ob_start();
    ?>
    <div class="box">
        <h3><?= __('Kompletność profilu') ?></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Uzupełniony profil to jedyna rzecz, po której ktoś oceni, czy z Tobą pojechać.') ?></p>
        <div class="prog">
            <div class="prog__bar"><i style="width:<?= $completeness['percent'] ?>%;"></i></div>
            <p class="prog__lbl"><span><?= __('{a} z {b} pól', ['a' => $completeness['done'], 'b' => $completeness['total']]) ?></span><span><?= $completeness['percent'] ?>%</span></p>
        </div>
        <ul class="stg-todo">
            <?php foreach ($completeness['items'] as $item): ?>
            <li class="<?= $item['done'] ? 'on' : '' ?>"><i><?= $item['done'] ? '✓' : '' ?></i><?= htmlspecialchars($item['label']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
    $extraZdjeciaBoxHtml = ob_get_clean();
}
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display"><?= __('Profil organizatora') ?></h1>
<p class="desc spaced-below"><?= __('To, co widzą ludzie, gdy zastanawiają się, czy pojechać z Tobą. Ustawienia Ciebie jako
    uczestnika są') ?> <a href="<?= View::url('/admin/moje-konto') ?>"><?= __('w Moim koncie') ?></a>.</p>

<div class="settings-layout">
    <nav class="settings-rail section-tabs" id="sectionTabs" aria-label="<?= htmlspecialchars(__('Sekcje profilu organizatora')) ?>">
        <?php if ($organizer): ?>
        <button type="button" class="section-tab<?= $isActive('zdjecia') ? ' active' : '' ?>" data-section="zdjecia" onclick="showSection('zdjecia', this)"><?= __('Zdjęcia') ?></button>
        <button type="button" class="section-tab<?= $isActive('dane') ? ' active' : '' ?>" data-section="dane" onclick="showSection('dane', this)"><?= __('Kim jesteście') ?><?php if (empty($organizer->bio) || $organizer->foundedYear === null): ?><span class="warn-dot" title="<?= htmlspecialchars(__('Do uzupełnienia')) ?>"></span><?php endif; ?></button>
        <button type="button" class="section-tab<?= $isActive('kontakt') ? ' active' : '' ?>" data-section="kontakt" onclick="showSection('kontakt', this)"><?= __('Lokalizacja i kontakt') ?></button>
        <button type="button" class="section-tab<?= $isActive('bezpieczenstwo') ? ' active' : '' ?>" data-section="bezpieczenstwo" onclick="showSection('bezpieczenstwo', this)"><?= __('Bezpieczeństwo') ?></button>
        <div class="settings-rail__sep"></div>
        <?php endif; ?>
        <button type="button" class="section-tab<?= $isActive('rozliczenia') ? ' active' : '' ?>" data-section="rozliczenia" onclick="showSection('rozliczenia', this)"><?= __('Dane rozliczeniowe') ?></button>
        <div class="settings-rail__sep"></div>
        <p class="settings-rail__note"><?= __('Ustawienia konta?') ?> <a href="<?= View::url('/admin/moje-konto') ?>"><?= __('Moje konto →') ?></a></p>
    </nav>

    <div class="settings-content">
        <?php if (!$organizer): ?>
        <p class="desc spaced-below"><?= __('Sekcje o organizatorze (zdjęcia, opis, kontakt, bezpieczeństwo) pojawią się tutaj,
            gdy dodasz swoje pierwsze wydarzenie.') ?></p>
        <?php endif; ?>

        <?php if ($organizer): ?>
        <?php
            $formAction = View::url('/admin/profil-rozliczeniowy/info');
            $sectioned = true;
            require __DIR__ . '/../partials/organizer-profile-form.php';
        ?>
        <?php endif; ?>

        <?php
            $billingProfile = $profile;
            $billingError   = $error ?? null;
            $formAction     = View::url('/admin/profil-rozliczeniowy');
            $sectioned      = true;
            require __DIR__ . '/../partials/organizer-billing-form.php';
        ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var radios = Array.prototype.slice.call(document.querySelectorAll('input[name=organizer_type]'));
    var regBox = document.getElementById('regBox');
    if (radios.length && regBox) {
        var sync = function () {
            var checked = document.querySelector('input[name=organizer_type]:checked');
            regBox.hidden = !checked || checked.value !== 'professional_operator';
        };
        radios.forEach(function (r) { r.addEventListener('change', sync); });
    }

    var bio = document.getElementById('bio'), bioCnt = document.getElementById('bioCnt');
    if (bio && bioCnt) {
        bio.addEventListener('input', function () { bioCnt.textContent = bio.value.length + ' / 2000'; });
    }
});
</script>
