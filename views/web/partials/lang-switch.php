<?php
// views/web/partials/lang-switch.php
// PRZEŁĄCZNIK JĘZYKA (2026-09-16, tasks/active/wielojezycznosc.md). Jeden
// partial dla belki web, stopki i belki apki — trzy kopie rozjechałyby się
// przy pierwszym nowym języku.
//
// TO SĄ ZWYKŁE LINKI do tej samej strony w drugim języku (z zachowanym query
// stringiem), nie formularz: działają bez JS, a roboty widzą w nich wersje
// językowe. Kliknięcie dodatkowo ZAPAMIĘTUJE wybór (ui.js → POST /api/jezyk):
// ciasteczko, a u zalogowanego `users.lang`, od którego zależy język maili.
//
// Przy jednym włączonym języku (flaga na produkcji) nie renderuje nic.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$rmLangs = Core\Lang::supported();
if (count($rmLangs) < 2) {
    return;
}
?>
<span class="lang-switch" data-lang-save="<?= htmlspecialchars(Utils\View::url('/api/jezyk')) ?>" data-csrf="<?= htmlspecialchars(Core\Csrf::token()) ?>">
    <?php foreach ($rmLangs as $rmLangIdx => $rmLang): ?>
        <?php if ($rmLangIdx > 0): ?><span class="lang-switch-sep" aria-hidden="true">·</span><?php endif; ?>
        <?php if ($rmLang === Core\Lang::current()): ?>
            <b class="lang-switch-current" lang="<?= htmlspecialchars($rmLang) ?>" title="<?= htmlspecialchars(Core\Lang::name($rmLang)) ?>" aria-current="true"><?= htmlspecialchars(strtoupper($rmLang)) ?></b>
        <?php else: ?>
            <a href="<?= htmlspecialchars(Core\Lang::switchUrl($rmLang)) ?>" hreflang="<?= htmlspecialchars($rmLang) ?>" lang="<?= htmlspecialchars($rmLang) ?>" title="<?= htmlspecialchars(Core\Lang::name($rmLang)) ?>" data-lang-switch="<?= htmlspecialchars($rmLang) ?>"><?= htmlspecialchars(strtoupper($rmLang)) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</span>
