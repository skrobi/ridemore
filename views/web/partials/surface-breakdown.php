<?php
// views/web/partials/surface-breakdown.php
// PODZIAŁ NAWIERZCHNI — pasek segmentowy + legenda km/%, jeden komponent dla
// całego serwisu.
//
// Powstał 2026-09-11, gdy nawierzchnia doszła do znanych tras (migr. 086).
// Do tej daty ten sam markup siedział jako domknięta funkcja
// `$renderSurfaceBreakdown` w `event-page.php` — czyli był niedostępny spoza
// tamtego pliku. Kopia rozjechałaby się przy pierwszej zmianie; ta sama zasada,
// dla której wspólne są `stat-tiles.php`, `map-layers.php` i `treasure-list.php`.
//
// Oczekuje w zasięgu:
//   $sbBreakdown — ['asphaltPct','gravelPct','trailPct','asphaltKm','gravelKm',
//                   'trailKm'] (StageResource/EventResource 'surfaceBreakdown',
//                   albo to samo złożone z kolumn `known_routes`).
//
// ŹRÓDŁEM LICZB JEST `Utils\RoadSurfaceDetector` (Overpass API). Wołający ma
// obowiązek NIE dołączać tego partiala, gdy podziału nie wykryto — brak danych
// to nie „0% asfaltu", tylko „nie wiadomo", a pasek złożony z zer wyglądałby
// jak trasa bez żadnej nawierzchni.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
?>
<div class="surface-bar">
    <span class="surface-seg surface-asphalt" style="width:<?= (int) $sbBreakdown['asphaltPct'] ?>%"></span>
    <span class="surface-seg surface-gravel" style="width:<?= (int) $sbBreakdown['gravelPct'] ?>%"></span>
    <span class="surface-seg surface-trail" style="width:<?= (int) $sbBreakdown['trailPct'] ?>%"></span>
</div>
<div class="surface-legend">
    <span class="surface-legend-item"><i class="surface-dot surface-asphalt"></i><?= __('Asfalt') ?> <b><?= (int) $sbBreakdown['asphaltPct'] ?>%</b> (<?= Format::distance((float) $sbBreakdown['asphaltKm']) ?>)</span>
    <span class="surface-legend-item"><i class="surface-dot surface-gravel"></i><?= __('Gravel/szuter') ?> <b><?= (int) $sbBreakdown['gravelPct'] ?>%</b> (<?= Format::distance((float) $sbBreakdown['gravelKm']) ?>)</span>
    <span class="surface-legend-item"><i class="surface-dot surface-trail"></i><?= __('Ścieżka') ?> <b><?= (int) $sbBreakdown['trailPct'] ?>%</b> (<?= Format::distance((float) $sbBreakdown['trailKm']) ?>)</span>
</div>
