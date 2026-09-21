<?php
// views/web/pages/translation-form.php
// KOREKTA TŁUMACZENIA TREŚCI (Controllers\TranslationController).
// Oczekuje: $heading, $backUrl, $fields (klucz => label/source/text/origin/previous),
// $action, $targetName, $saved.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div class="auth-page auth-page-wide">
    <h1 class="display"><?= __('Popraw tłumaczenie') ?></h1>
    <p class="subline"><?= htmlspecialchars($heading) ?> · <?= htmlspecialchars($targetName) ?></p>

    <?php if ($saved): ?>
    <p class="form-success"><?= __('Zapisano. Twoja wersja wygrywa z tłumaczeniem automatycznym.') ?></p>
    <?php endif; ?>

    <?php if (!$fields): ?>
    <p class="desc"><?= __('Tu nie ma czego tłumaczyć — pola są puste.') ?></p>
    <?php else: ?>
    <form class="auth-form" method="post" action="<?= htmlspecialchars($action) ?>">
        <?= Core\Csrf::field() ?>
        <?php foreach ($fields as $klucz => $f): ?>
        <label class="form-field-label" for="t-<?= htmlspecialchars($klucz) ?>"><?= htmlspecialchars($f['label']) ?></label>
        <blockquote class="desc" style="margin:0 0 6px;white-space:pre-line;"><?= htmlspecialchars($f['source']) ?></blockquote>
        <textarea class="search-input" id="t-<?= htmlspecialchars($klucz) ?>" name="t[<?= htmlspecialchars($klucz) ?>]"
                  rows="<?= mb_strlen($f['source']) > 120 ? 8 : 2 ?>"><?= htmlspecialchars($f['text']) ?></textarea>
        <p class="hint">
            <?php if ($f['origin'] === 'human'): ?>
            <?= __('Poprawione ręcznie.') ?>
            <?php elseif ($f['origin'] === 'machine'): ?>
            <?= __('Tłumaczenie automatyczne — popraw, jeśli coś brzmi źle.') ?>
            <?php else: ?>
            <?= __('Brak tłumaczenia — wpisz własne albo zostaw puste.') ?>
            <?php endif; ?>
            <?= __('Puste pole przywraca tłumaczenie automatyczne.') ?>
        </p>
        <?php if (!empty($f['previous']) && $f['previous'] !== $f['text']): ?>
        <p class="hint"><?= __('Twoja poprzednia wersja (sprzed zmiany oryginału):') ?> <i><?= htmlspecialchars($f['previous']) ?></i></p>
        <?php endif; ?>
        <?php endforeach; ?>
        <button class="btn" type="submit"><?= __('Zapisz tłumaczenie') ?></button>
    </form>
    <?php endif; ?>

    <p style="margin-top:16px;"><a href="<?= htmlspecialchars($backUrl) ?>">← <?= __('Wróć') ?></a></p>
</div>
