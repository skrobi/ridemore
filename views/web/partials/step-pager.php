<?php
// views/web/partials/step-pager.php
// STRONICOWANIE KROKOWE SEKCJI NA JEDNEJ STRONIE — „‹ Nowsze · 11–20 z 200 · Starsze ›".
//
// Powód (2026-09-13, pytanie usera: „a co, jeśli zdobędę 300 skarbów albo
// zahaczę o 1000 znanych tras?"): profil rowerzysty ma kilka list, które rosną
// bez końca (wyjazdy, gablota tras, skarby, dziennik). Dziennik miał już
// stronicowanie wpisane na sztywno; ten partial jest jego wyciągnięciem, żeby
// każda lista zachowywała się tak samo.
//
// ZWYKŁE LINKI, NIE PRZYCISKI (ta sama decyzja co w dzienniku z 2026-08-14):
// adres z numerem strony da się podesłać, „wstecz" działa, a kotwica trzyma
// widok na sekcji. Link ZACHOWUJE pozostałe parametry adresu — przejście na
// drugą stronę skarbów nie zeruje strony dziennika ani filtra rzadkości.
//
// UŻYCIE:
//   require_once __DIR__ . '/../partials/step-pager.php';
//   renderStepPager(['base' => $url, 'param' => 'skarby', 'anchor' => 'skarby',
//                    'page' => 2, 'pages' => 25, 'from' => 13, 'to' => 24, 'total' => 300,
//                    'prev' => '‹ Poprzednie', 'next' => 'Następne ›']);
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('stepPagerHref')) {
    /**
     * Adres bieżącej strony z podmienionymi parametrami (`null` usuwa parametr).
     * Czyta tylko proste wartości z `$_GET` — tablice w adresie nie mają tu sensu.
     */
    function stepPagerHref(string $base, array $set, string $anchor): string
    {
        $q = [];
        foreach ($_GET as $k => $v) {
            if (is_scalar($v) && $v !== '') { $q[(string) $k] = (string) $v; }
        }
        foreach ($set as $k => $v) {
            if ($v === null) { unset($q[$k]); } else { $q[$k] = (string) $v; }
        }
        return $base . ($q ? '?' . http_build_query($q) : '') . ($anchor !== '' ? '#' . $anchor : '');
    }

    function renderStepPager(array $o): void
    {
        $page = (int) $o['page'];
        $pages = (int) $o['pages'];
        if ($pages <= 1) {
            return;
        }
        $href = static fn(int $p): string => stepPagerHref($o['base'], [$o['param'] => $p > 1 ? $p : null], $o['anchor']);
        $prev = $o['prev'] ?? __('‹ Poprzednie');
        $next = $o['next'] ?? __('Następne ›');
        ?>
        <div class="rf-nav">
            <?php if ($page > 1): ?>
            <a class="rf-nav__btn" href="<?= htmlspecialchars($href($page - 1)) ?>" rel="prev"><?= htmlspecialchars($prev) ?></a>
            <?php else: ?>
            <span class="rf-nav__btn is-off"><?= htmlspecialchars($prev) ?></span>
            <?php endif; ?>
            <span class="rf-nav__pos"><?= (int) $o['from'] ?>–<?= (int) $o['to'] ?> z <?= (int) $o['total'] ?></span>
            <?php if ($page < $pages): ?>
            <a class="rf-nav__btn" href="<?= htmlspecialchars($href($page + 1)) ?>" rel="next"><?= htmlspecialchars($next) ?></a>
            <?php else: ?>
            <span class="rf-nav__btn is-off"><?= htmlspecialchars($next) ?></span>
            <?php endif; ?>
        </div>
        <?php
    }
}
