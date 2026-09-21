<?php
// views/web/partials/activity-card.php
// Wspólny "kafelek aktywności" (avatar + nazwa + [odznaka] + data + treść) —
// opinie, relacje, komentarze i odpowiedzi na komentarze w event-page.php mają
// identyczny szkielet, różni się tylko odznaka w nagłówku (gwiazdki / tag
// organizatora) i sama treść (tekst / wideo / galeria / formularz odpowiedzi).
//
// Funkcja, nie zwykły "require" jak reszta partiali w tym katalogu — komentarze
// zagnieżdżają odpowiedzi (ta sama karta w karcie), więc dzielenie zmiennych
// przez wspólny scope (jak przy zwykłym require) nadpisywałoby sobie nawzajem
// $name/$time przy renderze rodzica i dziecka. Wywołujący buduje $bodyHtml
// przez ob_start()/ob_get_clean() — treść różni się na tyle między typami, że
// przepychanie jej przez kolejne parametry byłoby mniej czytelne niż to.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('renderActivityCard')) {
    // $authorSlug (2026-08-12) — publiczny profil autora wpisu. Dopisane NA
    // KOŃCU listy parametrów, żeby nie ruszać istniejących wywołań: bez niego
    // karta wygląda dokładnie jak dotąd, tylko awatar i nazwisko nie prowadzą
    // nigdzie. Z nim — jedno i drugie jest linkiem do /rowerzysta/{slug}.
    // $actionsHtml (2026-08-13) — akcje przy KONKRETNYM wpisie (dziś: ołówek
    // „edytuj" na własnym wpisie w kronice). Dosunięte do prawej krawędzi
    // nagłówka, nie doklejone do nazwiska: to sterowanie, nie informacja
    // o autorze. Dopisane NA KOŃCU listy parametrów z tego samego powodu co
    // $authorSlug — bez niego karta wygląda dokładnie jak dotąd.
    // $authorAvatarUrl (2026-08-22) — zdjęcie autora wpisu. Ta sama zasada co
    // w `rider-avatar.php`: zdjęcie ma pierwszeństwo, inicjały są zapasem
    // i zostają w `alt`. Dopisane NA KOŃCU listy z tego samego powodu co dwa
    // poprzednie parametry — wywołanie bez niego wygląda dokładnie jak dotąd.
    function renderActivityCard(string $name, string $time, string $bodyHtml, string $badgeHtml = '', string $extraClass = '', ?string $authorSlug = null, string $actionsHtml = '', ?string $authorAvatarUrl = null): void
    {
        $initials = mb_substr($name, 0, 2);
        $profileUrl = ($authorSlug !== null && $authorSlug !== '')
            ? Utils\View::url('/rowerzysta/' . $authorSlug)
            : null;
        $avatarSrc = ($authorAvatarUrl !== null && $authorAvatarUrl !== '')
            ? Utils\Image::src($authorAvatarUrl, 'av')
            : '';
        ?>
        <div class="review<?= $extraClass ? ' ' . $extraClass : '' ?>">
            <?php
                // Wnętrze kółka składane raz, żeby nie powtarzać warunku
                // „zdjęcie czy inicjały" w obu gałęziach klikalności.
                $avatarInner = $avatarSrc !== ''
                    ? '<img src="' . htmlspecialchars($avatarSrc) . '" alt="' . htmlspecialchars($initials) . '" width="34" height="34" loading="lazy">'
                    : htmlspecialchars($initials);
            ?>
            <?php if ($profileUrl): ?>
            <a class="r-avatar" href="<?= htmlspecialchars($profileUrl) ?>" title="<?= htmlspecialchars($name) ?>"><?= $avatarInner ?></a>
            <?php else: ?>
            <div class="r-avatar"><?= $avatarInner ?></div>
            <?php endif; ?>
            <div class="review-body">
                <div class="review-head">
                    <span class="review-name"><?php if ($profileUrl): ?><a href="<?= htmlspecialchars($profileUrl) ?>"><?= htmlspecialchars($name) ?></a><?php else: ?><?= htmlspecialchars($name) ?><?php endif; ?></span>
                    <?= $badgeHtml ?>
                    <span class="review-time"><?= htmlspecialchars($time) ?></span>
                    <?php if ($actionsHtml !== ''): ?>
                    <span class="review-act"><?= $actionsHtml ?></span>
                    <?php endif; ?>
                </div>
                <?= $bodyHtml ?>
            </div>
        </div>
        <?php
    }
}
