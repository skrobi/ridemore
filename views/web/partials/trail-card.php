<?php
// views/web/partials/trail-card.php
// KARTA ZNANEJ TRASY (`.disc-trail`) — jedna dla całej aplikacji.
//
// Powód powstania (2026-09-10): ten sam kafelek stał skopiowany w `trails.php`
// (katalog /trasy) i w `discovery.php` (sekcja „Znane trasy" pod mapą odkryć),
// a sekcja „W okolicy tej trasy" na stronie trasy byłaby TRZECIĄ kopią. Kopie
// zdążyły się już rozjechać: jedna pokazywała gościowi stopkę „N pól do
// zaliczenia", druga nie pokazywała mu nic, a mechanizm „prawie" (odległość do
// następnego progu) istniał tylko w jednej z nich. Trzecia kopia przypieczętowałaby
// rozjazd — ta sama zasada, co przy `partials/map-layers.php`.
//
// Funkcja, nie zwykły require — kartę renderujemy w pętli po kilkanaście razy
// na stronę (patrz `rider-avatar.php`, ta sama decyzja z tego samego powodu).
//
// RÓŻNICE MIĘDZY EKRANAMI ZOSTAJĄ OPCJAMI, nie osobnym plikiem:
//   $opts['near']      — dopisek „do progu X% — brakuje Y" przy trasie w toku
//                        (mechanizm „prawie", Faza C; dziś /odkrycia),
//   $opts['guestFoot'] — stopka dla niezalogowanego („N pól do zaliczenia";
//                        dziś /trasy, gdzie karta bez niej byłaby samą nazwą),
//   $opts['note']      — własny podpis zamiast stopki postępu (strona trasy:
//                        „krzyżuje się z tą trasą" / „biegnie w pobliżu").
//   $opts['emblem']    — zdobyty emblemat tej trasy (wiersz z `Emblem::forUser`):
//                        znaczek w prawym górnym rogu zdjęcia (2026-09-13,
//                        gablota na profilu rowerzysty),
//   $opts['href']      — inny adres niż /trasy/{slug} (karta emblematu za
//                        trasę WYDARZENIA prowadzi na wydarzenie).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('renderTrailCard')) {
    function renderTrailCard(array $route, bool $loggedIn, array $opts = []): void
    {
        $pct = (int) ($route['pct'] ?? 0);
        $dist = (float) ($route['distance_km'] ?? 0);
        $note = $opts['note'] ?? null;
        ?>
        <?php $em = $opts['emblem'] ?? null; ?>
        <a class="disc-trail" href="<?= htmlspecialchars(Utils\View::url($opts['href'] ?? '/trasy/' . $route['slug'])) ?>">
            <div class="disc-trail__ph"<?= !empty($route['cover_photo_url'])
                ? ' style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($route['cover_photo_url'], 'card')) . '\')"' : '' ?>>
                <?php if ($em): ?>
                <?php // EMBLEMAT W ROGU — nazwa i data w podpowiedzi i dla czytnika;
                      // grafika emblematu, a bez niej złoty heks z haczykiem. ?>
                <?php // Wariant 'av' (96 px), nie 'thumb' (320 px): znaczek ma ~48 px,
                      // a 320 px ściskane przez przeglądarkę wychodziło rozmyte.
                      // Kształt i biała obwódka jak pieczątka na stronie trasy. ?>
                <span class="disc-trail__emb"
                      title="<?= htmlspecialchars('Emblemat: ' . $em['name'] . ' · ' . (Utils\Format::dateShort(substr((string) $em['awardedAt'], 0, 10)) ?? '')) ?>">
                    <span class="disc-trail__emb-in<?= empty($em['imageUrl']) ? ' disc-trail__emb--plain' : '' ?>"
                          <?= !empty($em['imageUrl']) ? 'style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($em['imageUrl'], 'av')) . '\')"' : '' ?>>
                        <?= empty($em['imageUrl']) ? Utils\Icon::render('check') : '' ?>
                    </span>
                    <span class="visually-hidden">Emblemat: <?= htmlspecialchars($em['name']) ?></span>
                </span>
                <?php endif; ?>
            </div>
            <div class="disc-trail__b">
                <span class="disc-trail__name"><i class="trail-dot" style="background:<?= htmlspecialchars(Models\KnownRoute::colorOf($route['color_index'] ?? null)) ?>"></i><span><?= htmlspecialchars($route['name']) ?></span></span>
                <span class="disc-trail__meta"><?php
                    if ($dist > 0) {
                        echo htmlspecialchars(Utils\Format::distance($dist));
                    }
                    if (!empty($route['region_label'])) {
                        echo ($dist > 0 ? ' · ' : '') . htmlspecialchars($route['region_label']);
                    }
                ?></span>
                <?php if ($note !== null): ?>
                <div class="disc-trail__foot"><span><?= htmlspecialchars($note) ?></span></div>
                <?php elseif ($loggedIn): ?>
                <?php // MECHANIZM „PRAWIE" (Faza C): przy trasie w toku obok
                      // procentu stoi odległość do następnego progu — „brakuje
                      // 7%" działa mocniej niż „mam 68%". Progi są ustawieniem
                      // admina (DiscoveryScoring::trailThresholds()), nie stałą
                      // wpisaną tutaj. Ukończona dostaje wyróżnienie `.is-done`. ?>
                <div class="disc-trail__foot">
                    <span<?= $pct >= 100 ? ' class="is-done"' : '' ?>><?= $pct >= 100 ? __('UKOŃCZONA') : $pct . '%' ?><?php
                        if (!empty($opts['near']) && $pct > 0 && $pct < 100) {
                            foreach (Models\DiscoveryScoring::trailThresholds() as $th) {
                                if ($pct < $th) {
                                    echo ' <em class="near">' . __('do progu {prog}% — brakuje {ile}', ['prog' => $th, 'ile' => $th - $pct]) . '</em>';
                                    break;
                                }
                            }
                        }
                    ?></span>
                    <span class="hexn"><?= Utils\Icon::render('hex') ?><?= (int) ($route['matched'] ?? 0) ?> / <?= (int) $route['cells_total'] ?></span>
                </div>
                <div class="trail__bar"><i style="width:<?= min(100, $pct) ?>%"></i></div>
                <?php elseif (!empty($opts['guestFoot'])): ?>
                <div class="disc-trail__foot"><span><?= __('{n} pól do zaliczenia', ['n' => (int) $route['cells_total']]) ?></span></div>
                <?php endif; ?>
            </div>
        </a>
        <?php
    }
}
