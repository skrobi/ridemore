<?php
// views/web/partials/home-event-card.php
// Karta "Najbliższe wyjazdy" na stronie głównej — bogatszy wariant niż
// .event-card z /wydarzenia (patrz assets/css/style.css, sekcja .hcard).
// Oczekuje $ev w scope: jedna pozycja z Controllers\HomeController::index()
// ($featuredEvents), czyli Resources\EventCardResource::fromRow() +
// 'routeThumbnailPath' (?array{area,line} z Utils\Gpx, patrz
// HomeController::routeThumbnailFor()).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;

$isLooseRide = ($ev['eventType'] ?? null) === 'pokrec_z_kims';
$dateRangeLabel = Format::dateRangeShort($ev['startDate'], $ev['endDate']);

// Znacznik formatu — te same kolory co sekcja "Cztery formaty" niżej na
// stronie, żeby karta i sekcja formatów uczyły się tego samego kodu barw.
[$formatLabel, $formatColorVar] = match ($ev['eventType'] ?? null) {
    'wycieczka_wielodniowa' => [__('Wielodniówka'), '--s-blue'],
    'pokrec_z_kims'         => [__('Pokręcę z kimś'), '--s-green'],
    'wyscig'                => [__('Wyścig'), '--s-purple'],
    default                 => [__('Zorganizowane wydarzenie'), '--s-red'],
};
$thumb = $ev['routeThumbnailPath'] ?? null;
?>
<a class="hcard" href="<?= Utils\View::url('/events/' . $ev['slug']) ?>">
    <div class="hcard__v">
        <?php // `Image::exists`, nie samo „jest adres" (2026-09-03) — bez tego
              // skasowany plik dawał pusty prostokąt zamiast miniatury trasy
              // albo grafiki zastępczej z gałęzi niżej. ?>
        <?php if (Utils\Image::exists($ev['coverPhotoUrl'] ?? null)): ?>
        <div class="ph" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($ev['coverPhotoUrl'], 'card')) ?>')" role="img" aria-label="<?= htmlspecialchars($ev['title']) ?>"></div>
        <?php elseif ($thumb): ?>
        <svg viewBox="0 0 400 158" preserveAspectRatio="none" aria-hidden="true">
            <path d="<?= htmlspecialchars($thumb['area']) ?>" fill="var(<?= $formatColorVar ?>)" fill-opacity=".10"></path>
            <path d="<?= htmlspecialchars($thumb['line']) ?>" fill="none" stroke="var(<?= $formatColorVar ?>)" stroke-width="2"></path>
        </svg>
        <?php else: ?>
        <svg viewBox="0 0 400 158" preserveAspectRatio="none" aria-hidden="true">
            <rect width="400" height="158" fill="var(--tint)"/>
            <polygon points="0,158 0,92 100,52 200,86 300,40 400,73 400,158" fill="var(--hair-strong)"/>
        </svg>
        <?php endif; ?>
        <span class="hcard__f"><span class="blaze" style="--bz:var(<?= $formatColorVar ?>);width:9px;height:15px;margin-right:6px;"></span><?= htmlspecialchars($formatLabel) ?></span>
        <?php if ($ev['durationDays'] > 1): ?>
        <span class="hcard__d"><?= __('{n} dni', ['n' => $ev['durationDays']]) ?> · <?= $ev['durationDays'] - 1 ?> <?= ($ev['durationDays'] - 1) === 1 ? __('nocleg') : __('noclegi') ?></span>
        <?php elseif ($ev['distanceFromUserKm'] !== null): ?>
        <span class="hcard__d"><?= __('{km} stąd', ['km' => htmlspecialchars(Format::distance($ev['distanceFromUserKm']))]) ?></span>
        <?php endif; ?>
        <?php // ZNACZNIK GPX w prawym dolnym rogu (2026-08-13) — „jest plik do
              // pobrania", widoczne zanim ktokolwiek wejdzie w kartę. Róg prawy
              // dolny, bo lewy górny zajmuje format wyjazdu, a prawy górny
              // długość/odległość: to trzecia informacja o tej samej trasie
              // i nie ma się z nimi bić o miejsce. ?>
        <?php if (!empty($ev['hasGpx'])): ?>
        <span class="hcard__gpx" title="<?= htmlspecialchars(__('Trasa GPX dostępna')) ?>" aria-label="<?= htmlspecialchars(__('Trasa GPX dostępna')) ?>">
            <?= Utils\Icon::render('route') ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="hcard__b">
        <h3 class="hcard__t"><?= htmlspecialchars($ev['title']) ?></h3>
        <p class="hcard__data">
            <?php if ($isLooseRide): ?>
            <span><?= $ev['dateIsFlexible'] ? __('termin do uzgodnienia:') . ' ' : '' ?><?= htmlspecialchars($dateRangeLabel) ?></span>
            <?php else: ?>
            <span><?= htmlspecialchars(Format::dateShort($ev['startDate'])) ?><?= $ev['startTime'] ? ', ' . htmlspecialchars(substr($ev['startTime'], 0, 5)) : '' ?></span>
            <?php if ($ev['distanceKm'] > 0): ?><span>·</span><span><?= htmlspecialchars(Format::distance($ev['distanceKm'])) ?></span><?php endif; ?>
            <?php endif; ?>
            <?php if ($ev['difficultyLabel']): ?><span>·</span><span><?= htmlspecialchars($ev['difficultyLabel']) ?></span><?php endif; ?>
            <?php if ($ev['regionName']): ?><span>·</span><span><?= htmlspecialchars($ev['regionName']) ?></span><?php endif; ?>
        </p>
        <div class="hcard__ft">
            <span class="hcard-org">
                <?php if (!empty($ev['organizerAvatarUrl'])): ?>
                <img class="hcard-av" src="<?= htmlspecialchars(Utils\View::url($ev['organizerAvatarUrl'])) ?>" alt="" width="24" height="24">
                <?php else: ?>
                <span class="hcard-av"><?= htmlspecialchars(mb_substr($ev['organizerName'] ?? '?', 0, 1)) ?></span>
                <?php endif; ?>
                <?= htmlspecialchars($ev['organizerName'] ?: __('Ktoś')) ?><?php if ($ev['organizerVerified']): ?> <span class="hcard-ver"><?= __('✓ zweryfikowany') ?></span><?php endif; ?>
            </span>
            <span class="hcard-price<?= !$ev['isPaid'] ? ' hcard-price--free' : '' ?>">
                <?= $ev['isPaid'] ? htmlspecialchars(Format::price($ev['priceAmount'], Format::currencySymbol($ev['currency']))) . ' / os.' : __('Bezpłatnie') ?><br>
                <?php if ($ev['spotsLeft'] !== null): ?><span style="color:var(--ink-mute);font-weight:400;"><?= $ev['spotsLeft'] ?> <?= $ev['spotsLeft'] === 1 ? 'miejsce' : 'miejsc' ?></span>
                <?php else: ?><span style="color:var(--ink-mute);font-weight:400;"><?= $ev['confirmedCount'] > 0 ? $ev['confirmedCount'] . ' zapisanych' : __('Nowe') ?></span><?php endif; ?>
            </span>
        </div>
    </div>
</a>
