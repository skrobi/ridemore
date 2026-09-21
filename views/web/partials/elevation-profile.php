<?php
// views/web/partials/elevation-profile.php
// PROFIL WYSOKOŚCI + CHIPY PODJAZDÓW — jeden blok dla całego serwisu
// (2026-09-03).
//
// Powód powstania: ten sam markup stał skopiowany w `trail.php` i (od strony
// przejazdu) w `ride.php`, razem z nieoczywistym drobiazgiem, który łatwo
// zgubić przy kopiowaniu — patrz `.day-panel` niżej. Trzecia kopia
// przypieczętowałaby rozjazd, dokładnie jak przy kontrolce warstw mapy
// (`partials/map-layers.php`).
//
// Oczekuje w zasięgu:
//   $epProfile — próbki `[{d,e,lat,lon}, ...]` (Gpx::parse → `elevationProfile`
//                albo `KnownRoute::elevationProfile()['profile']`),
//   $epPeaks   — szczyty z `Gpx::detectPeaks()` (mogą być puste),
//   $epColor   — kolor linii wykresu; ma być TEN SAM, którym idzie ślad na
//                mapie, inaczej strona pokazuje jedną trasę w dwóch kolorach.
//
// Pusty profil nie renderuje NIC — strona nie musi tego sprawdzać u siebie.
//
// WRAPPER `.day-panel active` NIE JEST OZDOBNIKIEM: po nim
// `ridemoreRenderElevationChart` znajduje chipy podjazdów
// (`container.closest('.day-panel')` → `.peak-chip`), więc bez niego klik
// w podjazd nie stawiałby pinezki na mapie. `active`, bo domyślnie ta klasa
// jest ukryta — na stronie wydarzenia przełącza się nią dni.
//
// Samo podpięcie do mapy robi `ridemoreSetupElevationProfile(map)`
// z `assets/js/gpx-map.js` — jedna linijka po stworzeniu mapy.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$epProfile = $epProfile ?? null;
$epPeaks   = $epPeaks ?? [];
$epColor   = $epColor ?? '';

if (!$epProfile) { return; }
?>
<div class="day-panel active">
    <div class="profbox" style="margin-top:14px;border-top:1px solid var(--hair);border-radius:12px;">
        <div class="day-profile-big" style="width:100%;height:100%;"
             data-elevation-profile='<?= htmlspecialchars(json_encode($epProfile), ENT_QUOTES) ?>'
             data-elevation-peaks='<?= htmlspecialchars(json_encode($epPeaks), ENT_QUOTES) ?>'
             data-elevation-color="<?= htmlspecialchars($epColor) ?>"></div>
    </div>
    <?php if ($epPeaks): ?>
    <?php // Szczyty jako „chipy" — ta sama kontrolka co przy etapie wydarzenia,
          // łącznie z klikaniem, które przenosi pinezkę na mapie. ?>
    <div class="climbs">
        <span><?= __('Podjazdy') ?></span>
        <?php foreach ($epPeaks as $peak): ?>
        <button type="button" class="climb peak-chip<?= $peak['category'] !== null ? ' climb--cat' : '' ?>"
                data-peak-d="<?= $peak['d'] ?>"
                style="--peak-color:<?= htmlspecialchars(Utils\ClimbCategory::color($peak['category'])) ?>;"
                title="<?= htmlspecialchars(__('{km} km podjazdu, śr. {pct}%', ['km' => $peak['climbLengthKm'], 'pct' => $peak['gradientPct']])) ?>">
            <?= (int) $peak['e'] ?> m<?= $peak['category'] !== null
                ? ' · ' . htmlspecialchars((string) Utils\ClimbCategory::label($peak['category'])) : '' ?>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
