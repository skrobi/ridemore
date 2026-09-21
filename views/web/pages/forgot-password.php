<?php
// views/web/pages/forgot-password.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$sent  = $sent  ?? false;
$email = $email ?? '';
?>
<div class="auth-page">
    <h1 class="display"><?= __('Odzyskaj hasło') ?></h1>

    <?php if ($sent): ?>
    <p class="subline"><?= __('Jeśli konto z adresem') ?> <b><?= htmlspecialchars($email) ?></b> <?= __('istnieje, wysłaliśmy na nie link do ustawienia nowego hasła.') ?></p>
    <?php else: ?>
    <p class="subline"><?= __('Podaj e-mail, na który wyślemy link do ustawienia nowego hasła.') ?></p>

    <?php require __DIR__ . '/../partials/form-error.php'; ?>

    <form class="auth-form" method="post" action="<?= Utils\View::url('/odzyskaj-haslo') ?>">
        <?= Core\Csrf::field() ?>
        <input class="search-input" type="email" name="email" placeholder="<?= htmlspecialchars(__('ty@przyklad.pl')) ?>" required value="<?= htmlspecialchars($email) ?>">
        <button class="btn" type="submit"><?= __('Wyślij link') ?></button>
    </form>
    <?php endif; ?>

    <p class="subline"><a href="<?= Utils\View::url('/logowanie') ?>"><?= __('← Wróć do logowania') ?></a></p>
</div>
