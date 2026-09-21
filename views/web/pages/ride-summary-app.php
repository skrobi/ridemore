<?php
// views/web/pages/ride-summary-app.php
// EKRAN WYNIKU JAZDY — WYŁĄCZNIE w apce mobilnej (SoloRideController::summary,
// §11 audytu UX 2026-08-28: brakujący "core loop" moment — dotąd wgranie/
// zakończenie jazdy kończyło się jednym zdaniem na liście przejazdów, bez
// ekranu-nagrody analogicznego do treasure-scan.php.
//
// Ten sam język wizualny co ekran skarbu (`.ts`/treasure-scan.php — duża
// liczba jako bohater, jedna akcja na dole), świadomie NIE te same klasy:
// `.ts` nazwą i CSS-em należy do skarbu, tu jest inny właściciel danych
// (RiderActivity::record), więc osobny prefiks `.rs`, ta sama sylwetka.
//
// Dane wyłącznie z sesji (jednorazowe, patrz kontroler) — zero nowych zapytań
// tutaj, widok tylko formatuje to, co już policzył RiderActivity::record().
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

$summary = $summary ?? [];

// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);
?>

<?php if (!empty($summary['duplicate'])): ?>
<section class="rs">
    <p class="rs__eyebrow"><?= __('Ten ślad już mamy') ?></p>
    <h1 class="rs__name"><?= __('Nic się nie zdublowało') ?></h1>
    <p class="rs__desc"><?= __('Ten sam plik był już policzony wcześniej — punkty i pola zostały takie,
        jakie były.') ?></p>
    <div class="rs__act">
        <a class="btn" href="<?= View::url('/odkrycia') ?>"><?= __('Moja mapa') ?></a>
        <a class="btn btn-secondary" href="<?= View::url('/admin/moje-przejazdy') ?>"><?= __('Wszystkie przejazdy') ?></a>
    </div>
</section>
<?php else:
    $cellsNew  = (int) ($summary['cellsNew'] ?? 0);
    $distance  = (float) ($summary['distanceKm'] ?? 0);
    $elevation = (int) ($summary['elevationGainM'] ?? 0);
    $treasures = $summary['treasures'] ?? [];
    $trails    = $summary['trailsReached'] ?? [];
    $totalPoints = (int) ($summary['pointsRide'] ?? 0)
        + (int) ($summary['pointsEvent'] ?? 0)
        + (int) ($summary['pointsDiscovery'] ?? 0)
        + (int) ($summary['pointsExploration'] ?? 0)
        + (int) ($summary['pointsTrails'] ?? 0)
        + array_sum(array_column($treasures, 'points'));
?>
<section class="rs rs--win">
    <p class="rs__eyebrow"><?= __('Jazda zapisana') ?></p>
    <p class="rs__points">+<?= number_format($totalPoints, 0, ',', ' ') ?></p>
    <p class="rs__unit"><?= __('punktów Ridemore') ?></p>

    <h1 class="rs__name"><?= number_format($distance, 1, ',', ' ') ?> km</h1>
    <?php if ($elevation > 0): ?>
    <p class="rs__meta"><?= __('{n} m w górę', ['n' => number_format($elevation, 0, ',', ' ')]) ?></p>
    <?php endif; ?>

    <?php // ODKRYŁEŚ — te same trzy kategorie co punktacja (§33 core/discovery.php),
          // opisane jak ludzie, nie jak rejestr: co się stało, nie skąd punkty. ?>
    <ul class="rs__list">
        <?php if ($cellsNew > 0): ?>
        <li><?= Utils\Icon::render('hex') ?>
            <b><?= number_format($cellsNew, 0, ',', ' ') ?></b>
            <?= $plural($cellsNew, __('nowe pole na mapie'), __('nowe pola na mapie'), __('nowych pól na mapie')) ?></li>
        <?php endif; ?>
        <?php foreach ($treasures as $t): ?>
        <li><?= Utils\Icon::render('tre-curiosity') ?>
            <?= __('Skarb') ?> <b><?= htmlspecialchars($t['name']) ?></b> · +<?= (int) $t['points'] ?> <?= __('pkt') ?></li>
        <?php endforeach; ?>
        <?php foreach ($trails as $route): ?>
        <?php
            // TRZY RÓŻNE ZDANIA, BO TO SĄ TRZY RÓŻNE RZECZY (Etap 1a, 2026-09-11):
            //   * trasa domknięta — nagroda, kropka;
            //   * przebity próg — fakt z punktami;
            //   * postęp bez progu — „zbliżyłeś się", z liczbą PÓL, nie procentem.
            // Ostatni przypadek to jedyna zachęta w tym programie pokazywana
            // bez powiadomienia: człowiek sam patrzy na ten ekran, więc nie
            // przerywamy mu niczego.
            $prog = $route['reached'] ? max($route['reached']) : null;
            $brakuje = $route['brakuje'] ?? null;
        ?>
        <li><?= Utils\Icon::render('route') ?>
            <b><?= htmlspecialchars($route['name']) ?></b> —
            <?php if ($prog !== null && $prog >= 100): ?>
                <?= __('trasa ukończona') ?>

            <?php elseif ($prog !== null): ?>
                <?= __('{n}% trasy poznane', ['n' => (int) $prog]) ?><?php if ($brakuje): ?><span class="rs__gap"><?= __('zostało') ?> <?= (int) $brakuje ?> <?= $plural($brakuje, 'pole', 'pola', 'pól') ?></span><?php endif; ?>
            <?php else: ?>
                <?= __('zbliżyłeś się') ?><?php if ($brakuje): ?><span class="rs__gap"><?= __('zostało') ?> <?= (int) $brakuje ?> <?= $plural($brakuje, 'pole', 'pola', 'pól') ?></span><?php endif; ?>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if ($cellsNew === 0 && !$treasures && !$trails): ?>
        <li><?= Utils\Icon::render('check') ?> <?= __('Znany teren — bez nowych pól tym razem.') ?></li>
        <?php endif; ?>
    </ul>

    <div class="rs__act">
        <a class="btn" href="<?= View::url('/odkrycia') ?>"><?= __('Moja mapa') ?></a>
        <a class="btn btn-secondary" href="<?= View::url('/admin/moje-przejazdy') ?>"><?= __('Wszystkie przejazdy') ?></a>
    </div>
</section>
<?php endif; ?>
