<?php
// views/web/pages/strava-complete.php
// Krok „podaj e-mail" po autoryzacji Strava — Strava nie udostępnia adresu
// e-mail, a users.email jest wymagany. Dane atlety (imię/avatar) są już w
// sesji (SocialAuthController::handleStrava). Po podaniu e-maila tworzymy +
// podpinamy konto (patrz stravaComplete).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$suggestedName = $suggestedName ?? '';
?>
<div class="auth-page">
    <h1 class="display"><?= __('Jeszcze jeden krok') ?></h1>
    <p class="subline"><?= __('Strava nie udostępnia adresu e-mail, więc podaj go — użyjemy go do logowania
        i powiadomień o wyjazdach.') ?><?php if ($suggestedName !== ''): ?> <?= __('Cześć, {imie}!', ['imie' => htmlspecialchars($suggestedName)]) ?><?php endif; ?></p>

    <?php require __DIR__ . '/../partials/form-error.php'; ?>

    <form class="auth-form" method="post" action="<?= Utils\View::url('/auth/strava/dokoncz') ?>">
        <?= Core\Csrf::field() ?>
        <input class="search-input" type="email" name="email" placeholder="<?= htmlspecialchars(__('ty@przyklad.pl')) ?>" required autofocus>
        <button class="btn" type="submit"><?= __('Dokończ i zaloguj') ?></button>
    </form>
</div>
