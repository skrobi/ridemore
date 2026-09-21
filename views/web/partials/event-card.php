<?php
// views/web/partials/event-card.php
// Oczekuje $ev w scope (jedna pozycja z EventCardResource::fromRow()).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
?>
<?php
$isPendingPayment = ($ev['myRsvpStatus'] ?? null) === 'oczekuje_platnosci';
$isInterested = ($ev['myRsvpStatus'] ?? null) === 'zainteresowany';
$cardStateClass = $isPendingPayment ? ' event-card-pending' : ($isInterested ? ' event-card-watched' : (!empty($ev['isJoined']) ? ' event-card-joined' : ''));
// Luźny wyjazd (patrz specyfikacja Etapu 1) — bez okładki/dystansu karta
// wyglądałaby na pustą, więc dostaje osobny nagłówek (awatar + "szuka
// towarzystwa") zamiast .card-photo, plus listwa/etykieta typu. Reszta karty
// (siatka, .event-card, stan zapisu) zostaje dokładnie ta sama co dla
// pozostałych dwóch typów.
$isLooseRide = ($ev['eventType'] ?? null) === 'pokrec_z_kims';
$dateRangeLabel = Format::dateRangeShort($ev['startDate'], $ev['endDate']);
?>
<a class="event-card<?= $cardStateClass ?><?= $isLooseRide ? ' event-card-loose' : '' ?>" href="<?= Utils\View::url('/events/' . $ev['slug']) ?>">
    <?php if ($isLooseRide): ?>
    <div class="card-type-stripe"></div>
    <?php endif; ?>
    <?php if ($isLooseRide && empty($ev['coverPhotoUrl'])): ?>
    <div class="card-loose-header">
        <?php if (!empty($ev['organizerAvatarUrl'])): ?>
        <img class="card-loose-avatar" src="<?= htmlspecialchars(Utils\View::url($ev['organizerAvatarUrl'])) ?>" alt="" width="36" height="36">
        <?php else: ?>
        <div class="card-loose-avatar card-loose-avatar-initials"><?= htmlspecialchars(mb_substr($ev['organizerName'] ?? '?', 0, 1)) ?></div>
        <?php endif; ?>
        <div>
            <div class="card-loose-name"><?= htmlspecialchars($ev['organizerName'] ?: __('Ktoś')) ?> szuka towarzystwa</div>
            <div class="card-type-label"><?= __('Pokręcę z kimś') ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="card-photo">
        <?php // `Image::exists`, nie samo „jest adres w bazie" (2026-09-03):
              // skasowany plik dawał ikonę zepsutego obrazka zamiast grafiki
              // zastępczej, którą ta karta ma tuż niżej. ?>
        <?php if (Utils\Image::exists($ev['coverPhotoUrl'] ?? null)): ?>
        <img src="<?= htmlspecialchars(Utils\Image::src($ev['coverPhotoUrl'], 'card')) ?>" alt="<?= htmlspecialchars($ev['title']) ?>" width="400" height="120" loading="lazy">
        <?php else: ?>
        <svg viewBox="0 0 400 120" preserveAspectRatio="none">
            <rect width="400" height="120" fill="#EDECE7"/>
            <polygon points="0,120 0,70 100,40 200,65 300,30 400,55 400,120" fill="#C4C1B6"/>
        </svg>
        <?php endif; ?>
        <div class="card-badges">
            <?php if ($isPendingPayment): ?>
            <span class="badge badge-pending-payment"><?= __('Oczekuje płatności') ?></span>
            <?php elseif ($isInterested): ?>
            <span class="badge badge-watching"><?= __('Obserwujesz') ?></span>
            <?php elseif ($isLooseRide): ?>
            <span class="badge badge-loose"><?= __('Pokręcę z kimś') ?></span>
            <?php endif; ?>
            <?php if (!$isLooseRide): ?>
            <span class="badge <?= $ev['isPaid'] ? 'badge-paid' : 'badge-free' ?>">
                <?= $ev['isPaid'] ? htmlspecialchars(Format::price($ev['priceAmount'], Format::currencySymbol($ev['currency']))) : __('Darmowe') ?>
            </span>
            <?php endif; ?>
        </div>
        <?php if ($ev['distanceKm'] > 0): ?>
        <span class="badge-distance"><?= htmlspecialchars(Format::distance($ev['distanceKm'])) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card-body">
        <div class="card-title"><?= htmlspecialchars($ev['title']) ?></div>
        <div class="card-meta">
            <?php if ($isLooseRide): ?>
            <?= $ev['dateIsFlexible'] ? __('termin do uzgodnienia:') . ' ' : '' ?><?= htmlspecialchars($dateRangeLabel) ?><?= (!$ev['dateIsFlexible'] && $ev['startTime']) ? ', ' . htmlspecialchars(substr($ev['startTime'], 0, 5)) : '' ?>
            <?php if ($ev['regionName']): ?> · <?= htmlspecialchars($ev['regionName']) ?><?php endif; ?>
            <?php if ($ev['bikeTypeNames']): ?> · <?= htmlspecialchars($ev['bikeTypeNames']) ?><?php endif; ?>
            <?php else: ?>
            <?= htmlspecialchars(Format::dateShort($ev['startDate'])) ?> · <?= htmlspecialchars(Format::distance($ev['distanceKm'])) ?>
            <?php if ($ev['durationDays'] > 1): ?> · <?= __('{n} dni', ['n' => $ev['durationDays']]) ?><?php endif; ?>
            <?php endif; ?>
            <?php if ($ev['distanceFromUserKm'] !== null): ?> · <?= __('{km} od Ciebie', ['km' => htmlspecialchars(Format::distance($ev['distanceFromUserKm']))]) ?><?php endif; ?>
        </div>
        <?php if (!empty($ev['otherEditionsCount'])): ?>
        <div class="card-editions">+<?= $ev['otherEditionsCount'] ?> <?= $ev['otherEditionsCount'] === 1 ? __('inny termin') : __('innych terminów') ?></div>
        <?php endif; ?>
        <div class="card-foot">
            <div class="org-meta"><?= $ev['confirmedCount'] > 0 ? $ev['confirmedCount'] . ' zapisanych' : __('Nowe') ?></div>
            <?php if ($ev['spotsLeft'] !== null): ?>
            <div class="spots-left"><?= $ev['spotsLeft'] ?> <?= $ev['spotsLeft'] === 1 ? 'miejsce' : 'miejsc' ?></div>
            <?php endif; ?>
        </div>
    </div>
</a>
