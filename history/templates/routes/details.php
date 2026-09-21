<?php
/* ============================================================================
  Route Details Template v4.1 - Using CSS Classes
  
  Changes from v4.0:
  - Using existing CSS classes instead of inline styles
  - Cleaner, more maintainable code
  - Consistent with design system
  ============================================================================ */

$isAuthor = ($route['user_id'] ?? 0) === (int)($user['user_id'] ?? -1);
$canEdit = $isAuthor || ($user['status'] ?? '') === 'admin';

// Difficulty mapping
$difficultyClass = [
    'easy' => 'difficulty-easy',
    'medium' => 'difficulty-medium',
    'hard' => 'difficulty-hard',
    'extreme' => 'difficulty-expert'
][$route['difficulty_level'] ?? 'medium'] ?? 'difficulty-medium';

$difficultyLabel = [
    'easy' => 'ŁATWA',
    'medium' => 'ŚREDNIA',
    'hard' => 'TRUDNA',
    'extreme' => 'EKSTREMALNA'
][$route['difficulty_level'] ?? 'medium'] ?? 'ŚREDNIA';

// Parse road types
$roadTypes = null;
if (!empty($route['road_types_json'])) {
    $roadTypes = json_decode($route['road_types_json'], true);
}

// Check if we have extra data
$hasPhotos = !empty($route['photos']);
$hasRoadTypes = !empty($roadTypes);
$hasTagline = !empty($route['tagline']);
$hasEstimatedTime = !empty($route['estimated_time_hours']);
$hasLayers = !empty($route['layers']);

// Route purpose
$routePurpose = $route['route_purpose'] ?? 'user_shared';
$isReference = $routePurpose === 'reference';
$isActivity = $routePurpose === 'activity';
?>

<div class="details-container">

    <!-- HEADER -->
    <div class="details-header <?= $route['is_completed'] ? 'completed' : '' ?>">
        <div class="details-title-row">
            <h1 class="details-title"><?= e($route['name']) ?></h1>

            <?php if ($route['is_completed']): ?>
                <div class="completion-badge">
                    <img src="<?= asset('icons/check.svg') ?>" alt="" class="completion-icon">
                    <span class="completion-text">DONE</span>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($hasTagline): ?>
            <div class="tagline-quote">
                "<?= e($route['tagline']) ?>"
            </div>
        <?php endif; ?>

        <div class="badges-row">
            <?php if ($isReference): ?>
                <div class="difficulty-badge badge-reference">
                    <img src="<?= asset('icons/book-marked.svg') ?>" alt="" class="badge-icon" style="filter: invert(35%) sepia(98%) saturate(2679%) hue-rotate(201deg) brightness(102%) contrast(101%);">
                    REF
                </div>
            <?php endif; ?>
            
            <?php if ($isActivity): ?>
                <div class="difficulty-badge badge-activity">
                    <img src="<?= asset('icons/activity.svg') ?>" alt="" class="badge-icon" style="filter: invert(48%) sepia(79%) saturate(2476%) hue-rotate(86deg) brightness(97%) contrast(97%);">
                    AKTYWNOŚĆ
                </div>
            <?php endif; ?>
            
            <div class="difficulty-badge <?= $difficultyClass ?>">
                <?= $difficultyLabel ?>
            </div>

            <?php if (!empty($route['route_type'])): ?>
                <?php
                $typeIcons = [
                    'road' => 'bike.svg',
                    'gravel' => 'compass.svg',
                    'mtb' => 'mountain.svg',
                    'mixed' => 'shuffle.svg'
                ];
                $typeLabels = [
                    'road' => 'SZOSA',
                    'gravel' => 'GRAVEL',
                    'mtb' => 'MTB',
                    'mixed' => 'MIESZANA'
                ];
                $typeIcon = $typeIcons[$route['route_type']] ?? 'map.svg';
                $typeLabel = $typeLabels[$route['route_type']] ?? strtoupper($route['route_type']);
                ?>
                <div class="difficulty-badge badge-route-type">
                    <img src="<?= asset('icons/' . $typeIcon) ?>" alt="" class="badge-icon" style="filter: invert(35%) sepia(98%) saturate(2679%) hue-rotate(231deg) brightness(102%) contrast(101%);">
                    <?= $typeLabel ?>
                </div>
            <?php endif; ?>

            <?php if ($route['in_challenge']): ?>
                <div class="difficulty-badge badge-challenge">
                    <img src="<?= asset('icons/target.svg') ?>" alt="" class="badge-icon" style="filter: invert(42%) sepia(98%) saturate(2679%) hue-rotate(251deg) brightness(102%) contrast(101%);">
                    WYZWANIE
                </div>
            <?php endif; ?>

            <?php if (($route['rating'] ?? 0) >= 4.5): ?>
                <div class="top-route-badge">
                    <img src="<?= asset('icons/star.svg') ?>" alt="" style="width: 11px; height: 11px; fill: currentColor;">
                </div>
            <?php endif; ?>
        </div>

        <div class="accent-line" style="background: <?= 'var(--gradient-' . ($route['difficulty_level'] ?? 'medium') . ')' ?>;"></div>
    </div>

    <!-- STATS GRID -->
    <div class="section-padding">
        <div class="stats-grid <?= $hasEstimatedTime ? 'has-time' : '' ?>">
            <div class="stat-card">
                <img src="<?= asset('icons/navigation.svg') ?>" alt="" class="stat-card-icon">
                <div class="stat-card-value"><?= $route['distance_km'] ?></div>
                <div class="stat-card-label">KM</div>
            </div>

            <div class="stat-card">
                <img src="<?= asset('icons/trending-up.svg') ?>" alt="" class="stat-card-icon">
                <div class="stat-card-value"><?= $route['ascent_m'] ?></div>
                <div class="stat-card-label">PRZEWYŻSZENIA</div>
            </div>

            <?php if ($hasEstimatedTime): ?>
                <div class="stat-card">
                    <img src="<?= asset('icons/clock.svg') ?>" alt="" class="stat-card-icon">
                    <div class="stat-card-value">
                        <?= floor($route['estimated_time_hours']) ?>h<?= round(($route['estimated_time_hours'] - floor($route['estimated_time_hours'])) * 60) ?>
                    </div>
                    <div class="stat-card-label">CZAS</div>
                </div>
            <?php endif; ?>

            <div class="stat-card">
                <img src="<?= asset('icons/star.svg') ?>" alt="" class="stat-card-icon">
                <a href="#ratings" 
                   style="text-decoration: none; color: inherit;"
                   onclick="event.preventDefault(); document.getElementById('ratings-section').scrollIntoView({behavior: 'smooth'});">
                    <div class="stat-card-value" style="cursor: pointer; transition: all 0.2s;">
                        <?= number_format($route['rating'] ?? 0, 1) ?>
                    </div>
                </a>
                <div class="stat-card-label">OCENA</div>
            </div>
        </div>

        <?php if ($hasRoadTypes): ?>
            <div class="road-types-card">
                <div class="road-types-row">
                    <div class="road-types-label">Nawierzchnia:</div>

                    <div class="road-types-list">
                        <?php if (($roadTypes['asphalt_pct'] ?? 0) > 0): ?>
                            <div class="road-type-item">
                                <img src="<?= asset('icons/minus.svg') ?>" alt="" class="road-type-icon">
                                <span class="road-type-name">Asfalt</span>
                                <span class="road-type-value"><?= $roadTypes['asphalt_pct'] ?>%</span>
                            </div>
                        <?php endif; ?>

                        <?php if (($roadTypes['gravel_pct'] ?? 0) > 0): ?>
                            <div class="road-type-item">
                                <img src="<?= asset('icons/circle.svg') ?>" alt="" class="road-type-icon">
                                <span class="road-type-name">Gravel</span>
                                <span class="road-type-value"><?= $roadTypes['gravel_pct'] ?>%</span>
                            </div>
                        <?php endif; ?>

                        <?php if (($roadTypes['trail_pct'] ?? 0) > 0): ?>
                            <div class="road-type-item">
                                <img src="<?= asset('icons/triangle.svg') ?>" alt="" class="road-type-icon">
                                <span class="road-type-name">Ścieżka</span>
                                <span class="road-type-value"><?= $roadTypes['trail_pct'] ?>%</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- DESCRIPTION -->
    <?php if (!empty($route['description'])): ?>
        <div class="section-padding">
            <div class="description-card">
                <div class="description-text">
                    <?= nl2br(e($route['description'])) ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- PHOTOS -->
    <?php if ($hasPhotos): ?>
        <div class="section-padding">
            <h3 class="section-title">
                <img src="<?= asset('icons/image.svg') ?>" alt="">
                Galeria zdjęć (<?= count($route['photos']) ?>)
            </h3>
            <div class="photo-grid">
                <?php foreach ($route['photos'] as $photo): ?>
                    <?php
                    $photoData = [
                        'url' => get_image_url($photo['file_path'], 'original'),
                        'caption' => $photo['caption_override'] ?? $photo['caption'] ?? '',
                        'camera' => $photo['camera'] ?? '',
                        'exif' => [
                            'focal_length' => $photo['exif']['focal_length'] ?? '',
                            'aperture' => $photo['exif']['aperture'] ?? '',
                            'iso' => $photo['exif']['iso'] ?? '',
                            'exposure_time' => $photo['exif']['exposure_time'] ?? ''
                        ]
                    ];
                    ?>
                    <div class="photo-card <?= $photo['is_primary'] ? 'primary' : '' ?>"
                         onclick='window.openPhoto(<?= json_encode($photoData, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        <img src="<?= get_image_url($photo['file_path'], 200) ?>" 
                             alt="<?= e($photo['caption'] ?? '') ?>">

                        <?php if ($photo['is_primary']): ?>
                            <div class="photo-badge primary">
                                <img src="<?= asset('icons/star.svg') ?>" alt="">
                                GŁÓWNE
                            </div>
                        <?php endif; ?>

                        <?php if ($photo['has_gps']): ?>
                            <div class="photo-badge gps">
                                <img src="<?= asset('icons/map-pin.svg') ?>" alt="" style="filter: brightness(0) invert(1);">
                                GPS
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($photo['caption_override']) || !empty($photo['caption'])): ?>
                            <div class="photo-caption">
                                <?= e($photo['caption_override'] ?? $photo['caption']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- LAYERS -->
    <?php if ($hasLayers): ?>
        <div class="section-padding">
            <details class="layers-details">
                <summary class="layers-summary">
                    <img src="<?= asset('icons/layers.svg') ?>" alt="">
                    Warstwy mapy (<?= count($route['layers']) ?>)
                    <img src="<?= asset('icons/chevron-down.svg') ?>" alt="" class="chevron">
                </summary>
                <div class="layers-list">
                    <?php foreach ($route['layers'] as $layer): ?>
                        <div class="layer-item">
                            <img src="<?= asset('icons/layers.svg') ?>" alt="">
                            <?= e($layer['name']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
    <?php endif; ?>

    <!-- CHALLENGES -->
    <div class="section-padding">
        <div class="challenge-section">
            <div class="ratings-header">
                <h3 class="section-title">Wyzwania</h3>
            </div>

            <div id="challenge-verify-container" 
                 data-route-id="<?= $route['route_id'] ?>"
                 data-user-local-id="<?= $user['user_local_id'] ?? '' ?>"
                 style="min-height: 60px;">
                <div class="challenge-loading">
                    <img src="<?= asset('icons/loader.svg') ?>" alt="" class="animate-spin">
                    <div>Sprawdzanie wyzwań...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- RATINGS -->
    <div id="ratings-section" class="section-padding" style="scroll-margin-top: 20px;">
        <div class="ratings-section">
            <div class="ratings-header">
                <h3 class="section-title">Oceny użytkowników</h3>

                <?php if (($route['rating_count'] ?? 0) > 0): ?>
                    <div class="rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <img src="<?= asset('icons/star.svg') ?>" 
                                 alt="" 
                                 class="<?= $i <= floor($route['rating'] ?? 0) ? 'star-filled' : 'star-empty' ?>"
                                 style="width: 11px; height: 11px;">
                        <?php endfor; ?>
                        <span class="rating-count">(<?= $route['rating_count'] ?>)</span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($route['recent_ratings'])): ?>
                <?php foreach (array_slice($route['recent_ratings'], 0, 3) as $review): ?>
                    <div class="review-card">
                        <div class="review-header">
                            <div class="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <img src="<?= asset('icons/star.svg') ?>" 
                                         alt="" 
                                         class="<?= $i <= ($review['rating'] ?? 0) ? 'star-filled' : 'star-empty' ?>"
                                         style="width: 11px; height: 11px;">
                                <?php endfor; ?>
                            </div>
                            <span class="review-date">
                                <?= date('d.m.Y', strtotime($review['created_at'])) ?>
                            </span>
                        </div>
                        <?php if (!empty($review['comment'])): ?>
                            <p class="review-comment">
                                <?= e($review['comment']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-reviews">
                    Brak ocen. Bądź pierwszy!
                </div>
            <?php endif; ?>

            <button onclick="openRatingModal(<?= $route['route_id'] ?>)" 
                    class="btn btn-primary btn-block"
                    style="margin-top: 10px;">
                <img src="<?= asset('icons/star.svg') ?>" alt="" class="svg-white" style="width: 16px; height: 16px;">
                Dodaj ocenę
            </button>
        </div>
    </div>

    <!-- ACTIONS -->
    <div class="section-padding">
        <div class="actions-grid">
            <?php if ($route['gpx_file_path']): ?>
                <a href="<?= url('api/routes/gpx_export.php?route_id='.$route['route_id']) ?>" download class="btn btn-primary" style="text-decoration: none;">
                    <img src="<?= asset('icons/download.svg') ?>" alt="Pobierz GPX" class="svg-white" style="width: 16px; height: 16px;">
                    POBIERZ GPX
                </a>
            <?php endif; ?>

            <button onclick="copyRouteLink(<?= $route['route_id'] ?>)" class="btn btn-secondary">
                <img src="<?= asset('icons/link.svg') ?>" alt="" style="width: 16px; height: 16px;">
                KOPIUJ LINK
            </button>
        </div>
    </div>

    <!-- AUTHOR ACTIONS -->
    <?php if ($canEdit): ?>
        <div class="section-padding">
            <div class="author-section">
                <h4 class="author-section-title">Akcje autora</h4>

                <div class="author-actions">
                    <button onclick="openEditModal(<?= $route['route_id'] ?>)"
                            class="icon-btn btn-secondary"
                            title="Edytuj trasę">
                        <img src="<?= asset('icons/pencil.svg') ?>" alt="">
                    </button>

                    <button onclick="deleteRoute(<?= $route['route_id'] ?>)"
                            class="icon-btn btn-outline danger"
                            title="Usuń trasę">
                        <img src="<?= asset('icons/trash-2.svg') ?>" alt="">
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- META INFO -->
    <div class="meta-info">
        <div>
            <img src="<?= asset('icons/eye.svg') ?>" alt="">
            <?= number_format($route['views_count']) ?> wyświetleń
            <?php if ($route['segment_count'] > 0): ?>
                •
                <img src="<?= asset('icons/map-pin.svg') ?>" alt="">
                <?= $route['segment_count'] ?> segmentów
            <?php endif; ?>
        </div>
        <div style="margin-top: 4px;">
            Utworzono: <?= date('d.m.Y', strtotime($route['created_at'])) ?>
        </div>
    </div>

</div>

<script>
    function openRatingModal(routeId) {
        const app = document.querySelector('[x-data="app"]').__x.$data;
        window.RoutesModule.openRatingModal(app, routeId);
    }

    function copyRouteLink(routeId) {
        window.RoutesModule.copyRouteLink(routeId);
    }

    function deleteRoute(routeId) {
        const app = document.querySelector('[x-data="app"]').__x.$data;
        window.RoutesModule.deleteRoute(app, routeId);
    }

    function openEditModal(routeId) {
        const app = document.querySelector('[x-data="app"]').__x.$data;
        window.RoutesModule.editRoute(app, routeId);
    }

    // Smooth scroll for details/summary
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('details').forEach(el => {
            el.addEventListener('toggle', () => {
                if (el.open) {
                    const chevron = el.querySelector('summary img.chevron');
                    if (chevron) chevron.style.transform = 'rotate(180deg)';
                } else {
                    const chevron = el.querySelector('summary img.chevron');
                    if (chevron) chevron.style.transform = 'rotate(0deg)';
                }
            });
        });
    });
</script>