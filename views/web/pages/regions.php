<?php
// views/web/pages/regions.php
// SPIS REGIONÓW — /regiony, strona kraju /regiony/{kraj} i 404 regionu
// (2026-09-14, tasks/done/strony-regionow.md). Jeden widok na trzy przypadki,
// bo to ta sama treść: kraje z listą regionów, różni się tylko zakresem.
//
// Kafel regionu = `.regions-grid .region-link` ze strony głównej („Przeglądaj
// po regionach") — nazwa i liczba nadchodzących wyjazdów. Nagłówek = `.op-head`
// ze strony trasy. Żadnego nowego komponentu.
//
// Oczekuje: $countries (Region::countries(), już zawężone przez kontroler),
// $counts (Region::contentCounts()), $heading, $sub, opcjonalnie $notFound.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Models\Region;
use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';

$countries = $countries ?? [];
$counts = $counts ?? [];
$notFound = $notFound ?? false;
$plural = Format::plural(...);

// Podpis kafla: nadchodzące wyjazdy, a gdy ich nie ma — to, co w regionie
// jest trwałe (trasy). Pusty region dostaje sam kafel bez liczby.
$regionNote = static function (string $code) use ($counts, $plural): string {
    $c = $counts[$code] ?? null;
    if ($c === null) {
        return '';
    }
    if ($c['upcoming'] > 0) {
        return $c['upcoming'] . ' ' . $plural($c['upcoming'], 'wyjazd', 'wyjazdy', 'wyjazdów');
    }
    if ($c['trails'] > 0) {
        return $c['trails'] . ' ' . $plural($c['trails'], 'trasa', 'trasy', 'tras');
    }
    return '';
};
?>

<div class="op-head">
    <div class="op-head__c">
        <div class="tags">
            <span class="tag" style="--bz:var(--s-green);"><span class="blaze"></span><?= __('Regiony') ?></span>
        </div>
        <h1><?= htmlspecialchars($heading) ?></h1>
        <p class="op-head__sub"><?= htmlspecialchars($sub) ?></p>
        <?php if ($notFound): ?>
        <div class="op-head__act">
            <a class="btn" href="<?= View::url('/regiony') ?>"><?= __('Wszystkie regiony') ?></a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($countries as $country): ?>
<section class="sec" id="kraj-<?= htmlspecialchars($country['code']) ?>">
    <div class="sec-head">
        <?php // Na stronie jednego kraju jego nazwa stoi już w <h1> — drugi raz
              // w nagłówku sekcji byłaby powtórzeniem. ?>
        <h2><?php if (count($countries) > 1): ?><a href="<?= View::url(Region::countryPath($country)) ?>"><?= htmlspecialchars(Region::displayName($country['name'])) ?></a><?php else: ?><?= __('Wybierz region') ?><?php endif; ?></h2>
        <?php if (!$country['isFlat']): ?>
        <span><?= count($country['regions']) ?> <?= $plural(count($country['regions']), 'region', 'regiony', 'regionów') ?></span>
        <?php endif; ?>
    </div>
    <div class="regions-grid">
        <?php if ($country['isFlat']): ?>
        <a class="region-link" href="<?= View::url(Region::countryPath($country)) ?>">
            <span><?= htmlspecialchars(Region::displayName($country['name'])) ?></span>
            <span class="mono"><?= htmlspecialchars($regionNote($country['code'])) ?></span>
        </a>
        <?php else: ?>
        <?php foreach ($country['regions'] as $r): ?>
        <a class="region-link" href="<?= View::url('/regiony/' . $country['code'] . '/' . $r['code']) ?>">
            <span><?= htmlspecialchars(Region::displayName($r['name'])) ?></span>
            <span class="mono"><?= htmlspecialchars($regionNote($r['code'])) ?></span>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
<?php endforeach; ?>

<div class="disc-cta">
    <div>
        <h3><?= __('Nie widzisz swojego regionu na liście wyjazdów?') ?></h3>
        <p><?= __('Ogłoś wyjazd — pojawi się na stronie regionu i zobaczą go ludzie, którzy tam jeżdżą.') ?></p>
    </div>
    <a class="btn" href="<?= View::url('/wydarzenia/nowe') ?>"><?= __('Dodaj wyjazd') ?></a>
</div>
