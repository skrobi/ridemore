<?php
// views/web/partials/map-layers.php
// KONTROLKA WARSTW MAPY — jedna dla całej aplikacji.
//
// Oczekuje w zasięgu:
//   $mlId     — id elementu <details>; JS czyta z niego stan checkboxów,
//   $mlLayers — DRZEWO warstw do pokazania (Etap 2, 2026-08-26 —
//               `Models\MapLayer::tree($context, [...])`, ewentualnie
//               przepuszczone przez `MapLayer::withQueryOverrides()`):
//               [['key' => 'cells', 'label' => 'Odkrycia',
//                 'hint' => 'gdzie już byłeś', 'on' => true,
//                 'children' => [...]], ...]
//               Renderuje dowolną głębokość, choć dziś tylko „Skarby" mają
//               dzieci (odkryte/nieodkryte).
//
// DLACZEGO PARTIAL (2026-08-19, prośba usera: „nie chcę, żeby w aplikacji
// używano czegoś innego niż standardowej kontrolki mapy"). Ten sam blok stał
// wcześniej skopiowany w `discovery.php` i `rider-profile.php`, a strona trasy
// nie miała go wcale — miała własną, gołą mapę z samym śladem. Trzecia kopia
// przypieczętowałaby rozjazd: kopie różniły się już wtedy stanem domyślnym
// warstw, a każda nowa warstwa wymagałaby pamiętania o wszystkich miejscach.
//
// Wygląd i zachowanie opisuje `features.md` (sekcja „Warstwy mapy odkryć"):
// ZWIJANA (`<details>`, nie własny JS — otwieranie, klawiatura i stan `open`
// z przeglądarki za darmo), w LEWEJ kolumnie mapy, bo lewa steruje, a prawa
// informuje. Wymaga rodzica z `position: relative` (`.disc-map`, `.rp-map__box`).
//
// Podpis pod nazwą warstwy jest OBOWIĄZKOWY: bez niego „Odkrycia" i „Heatmapa"
// brzmią jak to samo dla kogoś, kto wchodzi pierwszy raz. Treść podpisu należy
// do KONTEKSTU (Etap 2: to `Models\MapLayer` już rozstrzygnął, patrząc na
// `context` przekazany do `tree()`), nie do tej strony z osobna.
//
// DZIECKO GAŚNIE RAZEM Z RODZICEM — CAŁKOWICIE BEZ JS. `.map-layers__children`
// stoi zaraz PO `<label>` swojego rodzica (patrz CSS: `label:has(> input:not(
// :checked)) + .map-layers__children`), więc odznaczenie rodzica chowa blok
// dzieci samą regułą CSS. `data-children-of` na tym bloku to jedyne, czego
// potrzebuje JS (`ridemoreReadLayers` w discovery-map.js) — mówi mu, KTÓRY
// checkbox jest rodzicem, żeby stan dziecka liczyć jako `checked ⊕ rodzic`,
// niezależnie od tego, czy blok akurat widać.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$mlId = $mlId ?? 'mapLayers';
$mlLayers = $mlLayers ?? [];
// $mlDefaultLayers (opcjonalne) — stan warstw SPRZED override'u z adresu.
// Potrzebuje go WYŁĄCZNIE strona, która sama zapisuje warstwy w `$_GET`
// (dziś tylko discovery.php) — reszta tego atrybutu nie renderuje.
$mlDefaultLayers = $mlDefaultLayers ?? null;

if (!function_exists('renderMapLayerNodes')) {
    function renderMapLayerNodes(array $nodes): void
    {
        foreach ($nodes as $node) {
            ?>
            <label><input type="checkbox" data-layer="<?= htmlspecialchars($node['key']) ?>"<?= !empty($node['on']) ? ' checked' : '' ?>>
                <span><?= htmlspecialchars($node['label']) ?><small><?= htmlspecialchars($node['hint'] ?? '') ?></small></span></label>
            <?php if (!empty($node['children'])): ?>
            <div class="map-layers__children" data-children-of="<?= htmlspecialchars($node['key']) ?>">
                <?php renderMapLayerNodes($node['children']); ?>
            </div>
            <?php endif; ?>
            <?php
        }
    }
}
?>
<details class="map-layers" id="<?= htmlspecialchars($mlId) ?>"<?= $mlDefaultLayers !== null
    ? ' data-default-layers="' . htmlspecialchars(json_encode($mlDefaultLayers, JSON_UNESCAPED_UNICODE)) . '"'
    : '' ?>>
    <summary aria-label="<?= htmlspecialchars(__('Warstwy mapy')) ?>">
        <?= Utils\Icon::render('grid') ?><span><?= __('Warstwy') ?></span>
    </summary>
    <div class="map-layers__p">
        <p class="map-layers__hd"><?= __('Co pokazać na mapie') ?></p>
        <?php renderMapLayerNodes($mlLayers); ?>
    </div>
</details>
