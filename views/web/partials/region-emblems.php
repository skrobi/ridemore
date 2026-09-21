<?php
// views/web/partials/region-emblems.php
// REGIONY JAKO EMBLEMATY — jeden komponent dla /odkrycia i profilu rowerzysty
// (2026-09-13, prośba usera: „dodałbym to, co już mam na odkryciach — Twoje
// regiony; możesz użyć tego samego elementu, można przenieść do partiala").
//
// Kod przeniesiony 1:1 z `discovery.php` (grupowanie z 2026-09-05 i markup
// sekcji „Twoje regiony"). Strona decyduje o nagłówku i o tym, CZYJE to są
// regiony; komponent — o grupowaniu po kraju i wyglądzie.
//
// UŻYCIE:
//   require_once __DIR__ . '/../partials/region-emblems.php';
//   $grupy = regionEmblemGroups(Discovery::regionProgress($userId));
//   if ($grupy) { renderRegionEmblems($grupy); }
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('regionEmblemGroups')) {
    /**
     * Regiony ROZPOCZĘTE (≥1 odkryty heks tej osoby), pogrupowane po kraju.
     *
     * Region jest „rozpoczęty" wg TEJ SAMEJ definicji co kafel „Regiony"
     * (`Discovery::regionsForUser`), więc dwie liczby na stronie nie mogą się
     * rozjechać. W obrębie kraju najbardziej zaawansowany pierwszy — pozytywne
     * wzmocnienie, nie alfabet ani rozmiar regionu.
     *
     * GRUPOWANIE PO KRAJU (zgłoszenie usera 2026-09-05): region spoza Polski
     * pokazywał się WMIESZANY w województwa. `$regionsWorld['countries']`
     * przychodzi z `Discovery::regionProgress()` POSORTOWANE (kraj domowy
     * pierwszy), więc kolejność krajów jest tu ta sama co w modelu.
     *
     * KRAJ-REGION PŁASKI (Słowacja: bez rodzica, bez dzieci — sam jest swoim
     * jedynym regionem) dostaje TYLKO emblemat, bez nagłówka kraju — inaczej
     * ta sama nazwa i ten sam procent stałyby na ekranie dwa razy.
     *
     * @return list<array{country:array,showHeader:bool,regions:list<array>}>
     */
    function regionEmblemGroups(array $regionsWorld): array
    {
        $started = array_filter($regionsWorld['regions'] ?? [], static fn(array $r): bool => (int) ($r['mine'] ?? 0) > 0);
        $byCode = [];
        foreach ($started as $r) { $byCode[$r['countryCode']][] = $r; }

        $groups = [];
        foreach ($regionsWorld['countries'] ?? [] as $country) {
            if (!isset($byCode[$country['code']])) { continue; }
            $regions = $byCode[$country['code']];
            usort($regions, static fn(array $a, array $b): int => ($b['pctMine'] ?? 0) <=> ($a['pctMine'] ?? 0));
            $isFlat = count($regions) === 1 && $regions[0]['id'] === $country['id'];
            $groups[] = ['country' => $country, 'showHeader' => !$isFlat, 'regions' => $regions];
        }
        return $groups;
    }

    /**
     * Nagłówek kraju (te same klasy co pasek „Razem odkrywamy Polskę",
     * `.disc-world__box`) i siatka heksów wypełnianych procentem odkrycia.
     */
    function renderRegionEmblems(array $groups): void
    {
        $num = static fn($n): string => number_format((int) $n, 0, ',', ' ');
        $pct = static fn(float $p): string => number_format($p, $p < 10 ? 1 : 0, ',', '') . '%';
        foreach ($groups as $group): ?>
    <?php if ($group['showHeader']): ?>
    <?php $cPct = (float) ($group['country']['pctMine'] ?? 0); ?>
    <div class="disc-world__box region-country">
        <?php $countryPath = Models\Region::pathForCode($group['country']['code'] ?? null); ?>
        <span class="disc-world__lbl"><?php if ($countryPath): ?><a href="<?= htmlspecialchars(Utils\View::url($countryPath)) ?>" style="color:inherit;"><?= htmlspecialchars($group['country']['name']) ?></a><?php else: ?><?= htmlspecialchars($group['country']['name']) ?><?php endif; ?></span>
        <strong class="disc-world__num"><?= $pct($cPct) ?></strong>
        <span class="disc-world__sub"><?= __('{a} z {b} pól kraju', ['a' => $num($group['country']['mine']), 'b' => $num($group['country']['total'])]) ?></span>
    </div>
    <?php endif; ?>
    <div class="region-emblems">
        <?php foreach ($group['regions'] as $r): ?>
        <?php $pctMine = (float) ($r['pctMine'] ?? 0); $done = $pctMine >= 100; ?>
        <?php // EMBLEMAT PROWADZI NA STRONĘ REGIONU (2026-09-14, „wszędzie da się
              // kliknąć w region"). Kod spoza aktywnych regionów zostaje kartą. ?>
        <?php $emblemPath = Models\Region::pathForCode($r['code'] ?? null); ?>
        <<?= $emblemPath ? 'a href="' . htmlspecialchars(Utils\View::url($emblemPath)) . '"' : 'div' ?> class="region-emblem<?= $done ? ' region-emblem--done' : '' ?>">
            <span class="region-emblem__hex" style="--pct:<?= min(100, max(0, $pctMine)) ?>%">
                <span class="region-emblem__fill"></span>
                <span class="region-emblem__pct"><?= $done ? Utils\Icon::render('check') : $pct($pctMine) ?></span>
            </span>
            <span class="region-emblem__name"><?= htmlspecialchars($r['name']) ?></span>
            <span class="region-emblem__sub">
                <?= $done ? __('UKOŃCZONY') : __('{a} z {b} pól', ['a' => $num($r['mine']), 'b' => $num($r['total'])]) ?>
            </span>
        </<?= $emblemPath ? 'a' : 'div' ?>>
        <?php endforeach; ?>
    </div>
        <?php endforeach;
    }
}
