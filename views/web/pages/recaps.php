<?php
// views/web/pages/recaps.php
// Indeks kronik wyjazdów (Etap 4). Do 2026-08-12 ta strona była
// placeholderem „Strona w przygotowaniu" — teraz listuje realne kroniki.
//
// Kafle to ISTNIEJĄCE .op-card/.op-grid z profilu organizatora, żeby lista
// kronik wyglądała jak każda inna lista wyjazdów w serwisie.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

$chronicles = $chronicles ?? [];
// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);

require __DIR__ . '/../partials/breadcrumbs.php';
?>

<div class="op-head">
    <div class="op-head__c">
        <h1><?= __('Kroniki wyjazdów') ?></h1>
        <p class="op-head__sub"><?= __('Co się wydarzyło na trasie: kto pojechał, którędy i jak było.
            Kronika powstaje sama po każdym przejechanym wyjeździe — uczestnicy tylko dorzucają
            zdjęcia i kilka zdań.') ?></p>
    </div>
</div>

<?php if (!$chronicles): ?>
<?php
    // Pusty stan bez ponaglania i bez wymyślonych liczników — po prostu
    // uczciwie mówimy, skąd biorą się kroniki.
?>
<section class="sec">
    <div class="box">
        <p class="lead"><?= __('Nie ma jeszcze żadnej kroniki. Pierwsza pojawi się, gdy uczestnicy
            zakończonego wyjazdu potwierdzą, że na nim byli.') ?></p>
        <div class="op-head__act">
            <a class="btn" href="<?= View::url('/wydarzenia') ?>"><?= __('Zobacz nadchodzące wyjazdy') ?></a>
        </div>
    </div>
</section>
<?php else: ?>
<section class="sec" id="kroniki">
    <div class="op-grid">
        <?php foreach ($chronicles as $c): ?>
        <a class="op-card" href="<?= View::url('/kronika/' . $c['slug']) ?>?termin=<?= (int) $c['edition_id'] ?>">
            <div class="op-card__v"<?= $c['cover_photo_url'] ? ' style="background-image:url(\'' . htmlspecialchars(View::url($c['cover_photo_url'])) . '\');"' : '' ?>>
                <span class="op-card__d"><?= (int) $c['people_count'] ?> <?= $plural((int) $c['people_count'], 'osoba', 'osoby', 'osób') ?></span>
            </div>
            <div class="op-card__b">
                <h3 class="op-card__t"><?= htmlspecialchars($c['title']) ?></h3>
                <p class="op-card__m">
                    <?= htmlspecialchars(Format::dateShort($c['start_date']) ?? '') ?>
                    <?= $c['region_label'] ? ' · ' . htmlspecialchars($c['region_label']) : '' ?>
                    <?= (float) $c['distance_km'] > 0 ? ' · ' . htmlspecialchars(Format::distance((float) $c['distance_km'])) : '' ?>
                </p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
