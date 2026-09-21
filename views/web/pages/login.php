<?php
// views/web/pages/login.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$email = $email ?? '';
$info = $info ?? null;
?>
<div class="auth-page">
    <h1 class="display"><?= __('Zaloguj się') ?></h1>

    <?php if ($info): ?>
    <p class="form-success"><?= htmlspecialchars($info) ?></p>
    <?php endif; ?>

    <?php require __DIR__ . '/../partials/form-error.php'; ?>

    <form class="auth-form" method="post" action="<?= Utils\View::url('/logowanie') ?>">
        <?= Core\Csrf::field() ?>
        <input class="search-input" type="email" name="email" placeholder="<?= htmlspecialchars(__('ty@przyklad.pl')) ?>" required value="<?= htmlspecialchars($email) ?>">
        <input class="search-input" type="password" name="password" placeholder="<?= htmlspecialchars(__('Hasło')) ?>" required>
        <label class="checkbox-opt"><input type="checkbox" name="remember" value="1" checked><?= __('Zapamiętaj mnie') ?></label>
        <button class="btn" type="submit"><?= __('Zaloguj się') ?></button>
    </form>

    <?php require __DIR__ . '/../partials/social-login.php'; ?>

    <p class="subline"><a href="<?= Utils\View::url('/odzyskaj-haslo') ?>"><?= __('Nie pamiętasz hasła?') ?></a></p>
    <p class="subline"><?= __('Nie masz konta?') ?> <a href="<?= Utils\View::url('/rejestracja') ?>"><?= __('Załóż konto') ?></a></p>
</div>
