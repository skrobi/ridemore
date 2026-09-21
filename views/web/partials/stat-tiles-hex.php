<?php
// views/web/partials/stat-tiles-hex.php
// KAFLE STATYSTYK — WARIANT „GRYWALIZACJA" (2026-09-05).
//
// Powstał na prośbę usera: obecne Odkrycia wyglądały „mało gamingowo" wobec
// przesłanego rendera. Z rendera bierzemy DWIE rzeczy, które user wskazał jako
// już dobrze zrobione — hex jako element przewodni kafla i osobna ikona przy
// każdej liczbie — i wkomponowujemy je w ISTNIEJĄCE tokeny (`--accent`,
// `--s-green/--s-purple/--s-blue`, `--blaze-dark`), zero nowych kolorów.
//
// CZEGO STĄD NIE MA, ŚWIADOMIE (ta sama zasada co w discovery.php): żadnych
// „+22 w tym tygodniu" naklejek przy kaflach, których render nie ma poparcia
// w danych kontrolera — liczba bez pokrycia w danych jest gorsza niż jej brak.
// Poziomy/XP/odznaki jw. — user wprost powiedział „tego jeszcze nie ma".
//
// UŻYWANE WYŁĄCZNIE PRZEZ `discovery.php` (zbudowane i zatwierdzone jako
// podgląd obok produkcji, wdrożone 2026-09-05) — CELOWO osobny plik od
// `stat-tiles.php`, nie jego zamiennik: `renderStatTiles()`/`.stat-tiles`
// zostają nietknięte i dalej obsługują profil rowerzysty, stronę trasy
// i kronikę, bo tego wariantu nikt tam jeszcze nie widział ani nie zatwierdził.
// Gdyby user kiedyś zechciał ten sam styl gdzie indziej, to świadoma decyzja
// do podjęcia wtedy, nie efekt uboczny promocji /odkrycia.
//
// UŻYCIE — jak `renderStatTiles()`, plus trzy nowe klucze na kafel:
//   'icon' => nazwa z Utils\Icon (np. 'hex', 'pin', 'route', 'coins', 'star'),
//   'tone' => 'pola'|'teren'|'regiony'|'skarby'|'trasy' (kolor plakietki,
//             patrz mapowanie na zmienne w assets/css/style.css),
//   'href' => opcjonalny cel (np. kotwica '#regiony') — kafel renderuje się
//             wtedy jako <a>, nie <div> (2026-09-05, „Regiony" → sekcja
//             rozpoczętych regionów niżej na stronie).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('renderHexStatTiles')) {
    /**
     * @param array<int,array{lbl:string,val:string|int,small?:string,sub?:string,
     *                        icon?:string,tone?:string,hex?:bool,subHex?:bool,
     *                        done?:bool,lead?:bool}|null> $tiles
     * @param array{cols?:int, compact?:bool, class?:string} $opts
     */
    function renderHexStatTiles(array $tiles, array $opts = []): void
    {
        $tiles = array_values(array_filter($tiles));
        if (!$tiles) {
            return;
        }

        $cols = max(1, (int) ($opts['cols'] ?? (!empty($opts['compact']) ? 2 : count($tiles))));
        $class = 'stat-tiles-hex'
            . (!empty($opts['compact']) ? ' stat-tiles-hex--compact' : '')
            . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        ?>
        <div class="<?= htmlspecialchars($class) ?>" style="--st-cols:<?= $cols ?>">
            <?php foreach ($tiles as $tile): ?>
            <?php
            // KAFEL-LINK (2026-09-05, „Regiony" → kotwica z rozpoczętymi
            // regionami niżej na stronie). Kafel bez `href` zostaje `<div>`
            // jak dotąd — nie każdy kafel ma dokąd prowadzić.
            $tag = !empty($tile['href']) ? 'a' : 'div';
            ?>
            <<?= $tag ?> class="stat-tile-hex<?= !empty($tile['lead']) ? ' stat-tile-hex--lead' : '' ?><?= $tag === 'a' ? ' stat-tile-hex--link' : '' ?>"
                 data-tone="<?= htmlspecialchars((string) ($tile['tone'] ?? 'pola')) ?>"
                 <?= $tag === 'a' ? 'href="' . htmlspecialchars((string) $tile['href']) . '"' : '' ?>>
                <div class="stat-tile-hex__top">
                    <?php // PLAKIETKA — hex jako element przewodni (uwaga usera o
                          // renderze). Sam kontur pola jest już znakiem serwisu
                          // (`.hexn` gdzie indziej); tutaj hex niesie DOWOLNĄ ikonę
                          // kategorii, nie tylko heksagon, żeby pięć kafli dało się
                          // odróżnić na pierwszy rzut oka, tak jak w renderze. ?>
                    <span class="stat-tile-hex__badge">
                        <?= Utils\Icon::render((string) ($tile['icon'] ?? 'hex')) ?>
                    </span>
                    <span class="stat-tile-hex__lbl"><?= htmlspecialchars((string) ($tile['lbl'] ?? '')) ?></span>
                </div>
                <span class="stat-tile-hex__val<?= !empty($tile['done']) ? ' is-done' : '' ?>">
                    <?php if (!empty($tile['hex'])): ?>
                    <span class="hexn"><?= Utils\Icon::render('hex') ?><?= htmlspecialchars((string) $tile['val']) ?></span>
                    <?php else: ?>
                    <?= htmlspecialchars((string) $tile['val']) ?>
                    <?php endif; ?>
                    <?php if (!empty($tile['small'])): ?>
                    <small><?= htmlspecialchars((string) $tile['small']) ?></small>
                    <?php endif; ?>
                </span>
                <?php if (!empty($tile['sub'])): ?>
                <span class="stat-tile-hex__sub">
                    <?php if (!empty($tile['subHex'])): ?>
                    <span class="hexn"><?= Utils\Icon::render('hex') ?></span>
                    <?php endif; ?>
                    <?= htmlspecialchars((string) $tile['sub']) ?>
                </span>
                <?php endif; ?>
            </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
