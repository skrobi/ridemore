<?php
// views/web/partials/treasure-list.php
// SKARBY NA TRASIE — KARTY, jeden komponent dla całego serwisu (2026-09-03).
//
// Powód powstania: ten sam blok kart stał w `trail.php` („Co zobaczysz po
// drodze", 2026-08-23), a strona przejazdu potrzebowała dokładnie tego samego
// („Co minąłeś po drodze"). Kopia rozjechałaby się przy pierwszej zmianie —
// ta sama zasada, dla której wspólne są `stat-tiles.php` i `map-layers.php`.
//
// Oczekuje w zasięgu:
//   $tlList       — wynik `Treasure::listOnRoute()` / `listOnActivity()`,
//                   czyli `['items' => [...], 'hidden' => int]`,
//   $tlHiddenNote — (opcjonalnie) zdanie po liczbie ukrytych skarbów.
//
// UJAWNIENIE ROBI MODEL, nie ten plik (`Treasure::reveal()`): skarb ukryty
// w polu, którego widz nie odkrył, w ogóle tu nie dojedzie — dostajemy tylko
// jego LICZBĘ, żeby lista zgadzała się z kaflem statystyk. Publiczna strona
// jest najłatwiejszym miejscem, w którym dałoby się obejść zagadkę („wejdź,
// przeczytaj listę, jedź prosto pod punkty"), więc warunek musi być TEN SAM
// co wszędzie indziej.
//
// KARTA JEST PRZYCISKIEM, nie linkiem: skarb nie ma własnej strony, ale ma
// dokąd WSKAZAĆ — mapa stoi na tej samej stronie i umie się na nim ustawić
// (`ridemoreFocusTreasure`). Obsługę kliknięcia (`.js-atrakcja`) podpina
// strona, bo tylko ona wie, którą mapę przesuwać.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$tlList = $tlList ?? ['items' => [], 'hidden' => 0];
// Nazwa, opis i wskazówka skarbu w języku strony (wielojęzyczność) — jedno
// pobranie dla całej listy, potem pola każdej karty z pamięci podręcznej.
if (!Core\Lang::isDefault() && !empty($tlList['items'])) {
    $tlTeksty = [];
    foreach ($tlList['items'] as $tlTre) {
        foreach (['name', 'description', 'hint'] as $tlPole) {
            $tlTeksty['treasure:' . (int) $tlTre['id'] . ':' . $tlPole] = $tlTre[$tlPole] ?? null;
        }
    }
    Models\ContentTranslation::prefetch($tlTeksty);
    foreach ($tlList['items'] as &$tlTre) {
        $tlTre = array_merge($tlTre, Models\ContentTranslation::fields([
            'name'        => $tlTre['name'] ?? null,
            'description' => $tlTre['description'] ?? null,
            'hint'        => $tlTre['hint'] ?? null,
        ], 'treasure:' . (int) $tlTre['id']));
    }
    unset($tlTre);
}
$tlHidden = (int) ($tlList['hidden'] ?? 0);
$tlHiddenNote = $tlHiddenNote
    ?? __('pokażą się dopiero, gdy przejedziesz przez ich okolicę. Tyle samo punktów, '
     . 'tylko bez podpowiedzi z fotela.');

// Polska liczba mnoga — ta sama funkcja, której używają strony (Utils\Format).
?>
<?php if (!empty($tlList['items'])): ?>
<div class="disc-trails disc-trails--all" style="margin-top:14px;">
    <?php foreach ($tlList['items'] as $tre): ?>
    <?php
        $ukryty = ($tre['reveal'] ?? 'exact') !== 'exact';
        $mam = !empty($tre['mine']);
        $foto = !empty($tre['photo_url']) ? Utils\Image::src($tre['photo_url'], 'thumb') : null;
    ?>
    <?php // Modyfikator ZDOBYTEGO — złota obwódka w tych samych kolorach co
          // znaleziona pinezka na mapie (patrz `.disc-trail--mine` w style.css). ?>
    <div class="disc-trail<?= $mam ? ' disc-trail--mine' : '' ?> js-atrakcja" role="button" tabindex="0"
         data-id="<?= (int) $tre['id'] ?>"
         data-lat="<?= htmlspecialchars((string) $tre['lat']) ?>"
         data-lon="<?= htmlspecialchars((string) $tre['lon']) ?>">
        <div class="disc-trail__ph"<?= $foto
            ? ' style="background-image:url(\'' . htmlspecialchars($foto) . '\')"'
            : '' ?>></div>
        <div class="disc-trail__b">
            <span class="disc-trail__name"><?= htmlspecialchars($tre['name']) ?></span>
            <span class="disc-trail__meta">
                <?php if ($ukryty): ?>
                    <?php // Wskazówka zamiast kategorii — to cała treść, jaką
                          // reveal() zostawia na poziomie „trop". Przy poziomie
                          // „ukryty" nie ma nawet jej i zostaje sama zapowiedź. ?>
                    <?= !empty($tre['hint'])
                        ? htmlspecialchars($tre['hint'])
                        : __('ukryte — pokaże się w terenie') ?>
                <?php else: ?>
                    <?= htmlspecialchars($tre['category_label'] ?? __('Skarb')) ?>
                <?php endif; ?>
            </span>
            <?php if (!$ukryty && !empty($tre['description'])): ?>
            <span class="disc-trail__meta" style="color:var(--ink-soft);">
                <?= htmlspecialchars(mb_strimwidth((string) $tre['description'], 0, 90, '…')) ?>
            </span>
            <?php endif; ?>
            <span class="disc-trail__foot">
                <span>+<?= (int) $tre['points'] ?> <?= __('pkt') ?></span>
                <?php if ($mam): ?><span class="is-done"><?= __('MASZ') ?></span><?php endif; ?>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($tlHidden > 0): ?>
<?php // BEZ TEGO ZDANIA LISTA WYGLĄDA NA NIEPEŁNĄ — i słusznie, bo jest. Kafel
      // statystyk mówi „12 skarbów", a kart może być pięć. Różnica nie jest
      // błędem, tylko regułą całego modułu. ?>
<p class="desc" style="margin-top:14px;">
    <b><?= $tlHidden ?></b>
    <?= Utils\Format::plural($tlHidden, 'miejsce jest ukryte', 'miejsca są ukryte', 'miejsc jest ukrytych') ?>
    — <?= htmlspecialchars($tlHiddenNote) ?>
</p>
<?php endif; ?>
