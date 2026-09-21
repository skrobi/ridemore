<?php
// views/web/pages/reset-password.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div class="auth-page">
    <h1 class="display"><?= __('Ustaw nowe hasło') ?></h1>

    <?php if (!$valid): ?>
    <p class="subline"><?= __('Link jest nieprawidłowy albo wygasł. Poproś o nowy.') ?></p>
    <p><a class="btn" href="<?= Utils\View::url('/odzyskaj-haslo') ?>"><?= __('Odzyskaj hasło jeszcze raz') ?></a></p>
    <?php else: ?>

    <?php require __DIR__ . '/../partials/form-error.php'; ?>

    <form class="auth-form" method="post" action="<?= Utils\View::url('/odzyskaj-haslo/nowe') ?>">
        <?= Core\Csrf::field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input class="search-input" type="password" name="password" placeholder="<?= htmlspecialchars(__('Nowe hasło')) ?>" minlength="8" required>
        <input class="search-input" type="password" name="password_repeat" placeholder="<?= htmlspecialchars(__('Powtórz nowe hasło')) ?>" minlength="8" required>
        <button class="btn" type="submit"><?= __('Ustaw hasło') ?></button>
    </form>
    <?php endif; ?>
</div>
