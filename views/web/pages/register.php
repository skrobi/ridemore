<?php
// views/web/pages/register.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$sent  = $sent  ?? false;
$email = $email ?? '';
?>
<div class="auth-page">
    <h1 class="display"><?= __('Załóż konto') ?></h1>

    <?php if ($sent): ?>
    <p class="subline"><?= __('Sprawdź skrzynkę') ?> <b><?= htmlspecialchars($email) ?></b> <?= __('— wysłaliśmy link, który dokończy rejestrację.') ?></p>
    <?php else: ?>
    <p class="subline"><?= __('Podaj e-mail, na który wyślemy link aktywacyjny.') ?></p>

    <?php require __DIR__ . '/../partials/form-error.php'; ?>

    <form class="auth-form" method="post" action="<?= Utils\View::url('/rejestracja') ?>">
        <?= Core\Csrf::field() ?>
        <input class="search-input" type="email" name="email" placeholder="<?= htmlspecialchars(__('ty@przyklad.pl')) ?>" required value="<?= htmlspecialchars($email) ?>">
        <button class="btn" type="submit"><?= __('Załóż konto') ?></button>
    </form>

    <?php require __DIR__ . '/../partials/social-login.php'; ?>
    <?php endif; ?>
</div>
