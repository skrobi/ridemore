<?php
// views/web/partials/organizer-card.php
// KARTA ORGANIZATORA (`.org-card`) — jedna dla całej aplikacji.
//
// Przeniesiona 1:1 z `organizers-list.php` (2026-09-14), gdy strona regionu
// potrzebowała tej samej karty — druga kopia rozjechałaby się przy pierwszej
// zmianie (ta sama zasada co `trail-card.php` i `event-card.php`).
//
// Oczekuje w zasięgu `$o` — wiersz z `Organizer::search()` z doklejonym
// `coverPhotos` (`Organizer::recentCoverPhotos($o['userId'], 3)`).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<a class="org-card" href="<?= Utils\View::url('/organizatorzy/' . $o['slug']) ?>">
    <div class="oc-cover">
        <?php $tiles = array_pad(array_slice($o['coverPhotos'] ?? [], 0, 3), 3, null); ?>
        <?php foreach ($tiles as $photoUrl): ?>
        <div class="tile">
            <?php if ($photoUrl): ?>
            <?php // width/height obowiazkowe: bez nich lista skacze
                  // przy doczytywaniu zdjec. Wartosci sa proporcja
                  // kafelka (.oc-cover ma 64 px wysokosci i trzy
                  // kolumny), nie realnym rozmiarem pliku. ?>
            <?php // OPIS MÓWI, SKĄD JEST ZDJĘCIE (2026-09-16). Wcześniej zawsze
                  // „zdjęcie z wyjazdu", także gdy to były zdjęcia z profilu
                  // organizatora — źródło podaje `coverPhotosSource`
                  // (Organizer::coverPhotosWithSource). ?>
            <img src="<?= htmlspecialchars(Utils\Image::src($photoUrl, 'thumb')) ?>" alt="<?= htmlspecialchars($o['name']) ?> — <?= ($o['coverPhotosSource'] ?? '') === 'profile' ? __('zdjęcie z profilu organizatora') : __('okładka wyjazdu organizatora') ?>" width="140" height="64" loading="lazy" decoding="async">
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="oc-body">
        <?php if (!empty($o['avatarUrl'])): ?>
        <img class="oc-avatar<?= $o['organizerType'] === 'peer' ? ' peer' : '' ?>" src="<?= htmlspecialchars(Utils\View::url($o['avatarUrl'])) ?>" alt="<?= htmlspecialchars($o['name']) ?>" width="52" height="52" loading="lazy">
        <?php else: ?>
        <div class="oc-avatar<?= $o['organizerType'] === 'peer' ? ' peer' : '' ?>"><?= htmlspecialchars(mb_substr($o['name'], 0, 2)) ?></div>
        <?php endif; ?>
        <div class="oc-name-row">
            <span class="oc-name"><?= htmlspecialchars($o['name']) ?></span>
            <?php if ($o['isVerified']): ?>
            <span class="oc-check" title="<?= htmlspecialchars(__('Zweryfikowany operator')) ?>"><?= Utils\Icon::render('check') ?></span>
            <?php endif; ?>
        </div>
        <div class="oc-type"><?= $o['organizerType'] === 'professional_operator' ? __('Operator turystyczny') : __('Organizator społecznościowy') ?><?= $o['regionName'] ? ' · ' . htmlspecialchars($o['regionName']) : '' ?></div>
        <div class="oc-stats">
            <span class="oc-rating"><?= Utils\Icon::render('star') ?> <?= $o['ratingAvg'] !== null ? $o['ratingAvg'] . ' (' . $o['reviewCount'] . ')' : __('brak ocen') ?></span>
            <span><?= $o['completedCount'] ?> <?= __n((int) $o['completedCount'], 'wyjazd', 'wyjazdów', 'wyjazdów') ?></span>
        </div>
    </div>
</a>
