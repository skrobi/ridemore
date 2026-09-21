<?php
// views/web/pages/register-complete.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div class="auth-page">
    <h1 class="display"><?= __('Ustaw hasło') ?></h1>

    <?php if (!$valid): ?>
    <p class="subline"><?= __('Link jest nieprawidłowy albo wygasł. Załóż konto jeszcze raz.') ?></p>
    <p><a class="btn" href="<?= Utils\View::url('/rejestracja') ?>"><?= __('Wróć do rejestracji') ?></a></p>
    <?php else: ?>

    <?php require __DIR__ . '/../partials/form-error.php'; ?>

    <form class="auth-form" method="post" action="<?= Utils\View::url('/rejestracja/dokoncz') ?>">
        <?= Core\Csrf::field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input class="search-input" type="password" name="password" placeholder="<?= htmlspecialchars(__('Ustaw hasło')) ?>" minlength="8" required>
        <input class="search-input" type="password" name="password_repeat" placeholder="<?= htmlspecialchars(__('Powtórz hasło')) ?>" minlength="8" required>
        <button class="btn" type="submit"><?= __('Aktywuj konto') ?></button>
    </form>
    <?php endif; ?>
</div>
