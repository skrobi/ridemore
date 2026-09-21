<?php
// views/web/pages/review-form.php
// Oczekuje: $event (Models\Event), $error (?string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<div class="auth-page auth-page-wide">
    <h1 class="display"><?= __('Wystaw opinię') ?></h1>
    <p class="subline"><?= htmlspecialchars($event->title) ?></p>

    <?php require __DIR__ . '/../partials/form-error.php'; ?>

    <form class="auth-form" method="post" action="<?= View::url('/wydarzenia/' . $event->slug . '/opinia') ?>" enctype="multipart/form-data">
        <?= Core\Csrf::field() ?>

        <div class="rating-picker" data-role="rating-picker">
            <?php for ($n = 1; $n <= 5; $n++): ?>
            <button type="button" class="rating-btn" onclick="selectRating(this.closest('[data-role=rating-picker]'), <?= $n ?>)"><?= $n ?></button>
            <?php endfor; ?>
            <input type="hidden" name="rating" value="">
        </div>

        <textarea class="search-input" name="comment" rows="4" maxlength="2000" placeholder="<?= htmlspecialchars(__('Jak było? (opcjonalnie)')) ?>"></textarea>

        <label class="form-field-label"><?= __('Zdjęcia (opcjonalnie, do 5)') ?></label>
        <input class="search-input" type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp">

        <button class="btn" type="submit" onclick="return validateRating(this.closest('form'))"><?= __('Wyślij opinię') ?></button>
    </form>
</div>
