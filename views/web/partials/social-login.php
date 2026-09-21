<?php
// views/web/partials/social-login.php
// Przyciski logowania społecznościowego pod formularzem logowania/rejestracji.
// Przycisk providera pokazuje się TYLKO, gdy jest skonfigurowany (clientId +
// clientSecret) — inaczej nie ma martwego przycisku prowadzącego do 400.
//
// `data-rm-oauth` to zaczep dla aplikacji mobilnej (assets/js/native.js).
// W przeglądarce nie znaczy nic i link działa jak zawsze; w apce most przejmuje
// kliknięcie i otwiera adres w SYSTEMOWEJ przeglądarce z parametrem `app=1`.
// Bez tego Google odrzuca logowanie w WebView, a sesja z przeglądarki nie ma
// jak wrócić do apki (zgłoszenie usera 2026-08-23, patrz migr. 067).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\OAuthProvider;
use Utils\View;

$googleOn = OAuthProvider::isConfigured('google');
$stravaOn = OAuthProvider::isConfigured('strava');
if (!$googleOn && !$stravaOn) {
    return;
}
?>
<div class="social-login">
    <div class="social-sep"><span><?= __('albo') ?></span></div>
    <?php if ($googleOn): ?>
    <a class="btn btn--google" href="<?= View::url('/auth/google') ?>" data-rm-oauth>
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        <?= __('Kontynuuj z Google') ?>

    </a>
    <?php endif; ?>
    <?php if ($stravaOn): ?>
    <a class="btn btn--strava" href="<?= View::url('/auth/strava') ?>" data-rm-oauth>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066M10.463 0L3.46 13.828h4.169l2.834 5.598 2.831-5.598h4.173z"/></svg>
        <?= __('Kontynuuj ze Strava') ?>

    </a>
    <?php endif; ?>
</div>
