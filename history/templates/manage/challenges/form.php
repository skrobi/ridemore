<?php
/* ============================================================================
   Template: Formularz wyzwania - WERSJA 2.0 - Z uploadem obrazków
   ============================================================================ */
?>

<div class="manage-page-header">
    <div>
        <h1><?= $isEditMode ? 'Edytuj Wyzwanie' : 'Utwórz Wyzwanie' ?></h1>
        <p class="manage-page-subtitle">Wypełnij wszystkie sekcje formularza</p>
    </div>
    <a href="<?= manage_url('challenges/') ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Powrót
    </a>
</div>

<?php if (!empty($errors['general'])): ?>
    <div class="form-error-banner">
        <i class="fas fa-exclamation-circle"></i>
        <?= e($errors['general']) ?>
    </div>
<?php endif; ?>

<!-- ERROR SUMMARY -->
<?php if (!empty($errors)): ?>
    <div class="form-errors-summary">
        <h4><i class="fas fa-exclamation-triangle"></i> Znaleziono błędy walidacji:</h4>
        <ul>
            <?php if (!empty($errors['basic'])): ?>
                <li>
                    <strong>Podstawy:</strong>
                    <ul>
                        <?php foreach ($errors['basic'] as $field => $msg): ?>
                            <li><?= e($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endif; ?>
            
            <?php if (!empty($errors['routes'])): ?>
                <li>
                    <strong>Trasy:</strong>
                    <ul>
                        <?php foreach ($errors['routes'] as $msg): ?>
                            <li><?= e($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endif; ?>
            
            <?php if (!empty($errors['medals']['items'])): ?>
                <li>
                    <strong>Medale:</strong>
                    <ul>
                        <?php foreach ($errors['medals']['items'] as $msg): ?>
                            <li><?= e($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" id="challenge-form" class="challenge-form-container">
    
    <!-- Nawigacja sekcji -->
    <div class="challenge-form-nav">
        <button type="button" class="form-nav-btn active <?= !empty($errors['basic']) ? 'has-error' : '' ?>" data-section="basic">
            <i class="fas fa-info-circle"></i> Podstawy
        </button>
        <button type="button" class="form-nav-btn <?= !empty($errors['routes']) ? 'has-error' : '' ?>" data-section="routes">
            <i class="fas fa-route"></i> Trasy
        </button>
        <button type="button" class="form-nav-btn <?= !empty($errors['medals']) ? 'has-error' : '' ?>" data-section="medals">
            <i class="fas fa-medal"></i> Medale
        </button>
        <button type="button" class="form-nav-btn" data-section="advanced">
            <i class="fas fa-cog"></i> Zaawansowane
        </button>
    </div>
    
    <!-- SEKCJA 1: PODSTAWY -->
    <div class="challenge-form-section active" data-section="basic">
        <h3>Podstawowe informacje</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label required">Nazwa wyzwania</label>
                <input 
                    type="text" 
                    name="name" 
                    id="challenge-name"
                    class="form-input <?= isset($errors['basic']['name']) ? 'error' : '' ?>" 
                    value="<?= e($formData['name']) ?>"
                    placeholder="np. Tour de Pologne 2025"
                    required>
                <?php if (isset($errors['basic']['name'])): ?>
                    <div class="form-error"><?= e($errors['basic']['name']) ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label class="form-label required">Slug</label>
                <input 
                    type="text" 
                    name="slug" 
                    id="challenge-slug"
                    class="form-input <?= isset($errors['basic']['slug']) ? 'error' : '' ?>" 
                    value="<?= e($formData['slug']) ?>"
                    placeholder="tour-de-pologne-2025"
                    pattern="[a-z0-9\-]+"
                    required>
                <?php if (isset($errors['basic']['slug'])): ?>
                    <div class="form-error"><?= e($errors['basic']['slug']) ?></div>
                <?php endif; ?>
                <div class="form-help">Używany w URL</div>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Grupa</label>
            <select name="group_id" class="form-input">
                <option value="">Bez grupy</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= $group['group_id'] ?>" <?= $formData['group_id'] == $group['group_id'] ? 'selected' : '' ?>>
                        <?= e($group['icon']) ?> <?= e($group['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Opis</label>
            <textarea 
                name="description" 
                class="form-input" 
                rows="5"
                placeholder="Opisz wyzwanie..."><?= e($formData['description']) ?></textarea>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label required">Ikona (emoji)</label>
                <input 
                    type="text" 
                    name="icon" 
                    id="challenge-icon"
                    class="form-input <?= isset($errors['basic']['icon']) ? 'error' : '' ?>" 
                    value="<?= e($formData['icon']) ?>"
                    placeholder="🎯"
                    maxlength="10"
                    required>
                <?php if (isset($errors['basic']['icon'])): ?>
                    <div class="form-error"><?= e($errors['basic']['icon']) ?></div>
                <?php endif; ?>
                <div class="form-help">Emoji lub tekst (max 10 znaków)</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Kolor</label>
                <input 
                    type="color" 
                    name="color" 
                    value="<?= e($formData['color']) ?>"
                    class="form-input"
                    style="height: 40px;">
            </div>
            
            <div class="form-group">
                <label class="form-label">Typ</label>
                <select name="type" class="form-input">
                    <option value="regional" <?= $formData['type'] === 'regional' ? 'selected' : '' ?>>Regionalne</option>
                    <option value="race" <?= $formData['type'] === 'race' ? 'selected' : '' ?>>Wyścig</option>
                    <option value="thematic" <?= $formData['type'] === 'thematic' ? 'selected' : '' ?>>Tematyczne</option>
                    <option value="distance" <?= $formData['type'] === 'distance' ? 'selected' : '' ?>>Dystansowe</option>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Logo wyzwania</label>
            <div class="upload-image-wrapper">
                <input 
                    type="text" 
                    name="image_url" 
                    id="challenge-image-url"
                    class="form-input" 
                    value="<?= e($formData['image_url']) ?>"
                    placeholder="uploads/challenges/..."
                    readonly>
                <button type="button" class="btn btn-secondary" id="upload-challenge-image-btn">
                    <i class="fas fa-upload"></i> Upload
                </button>
                <input 
                    type="file" 
                    id="challenge-image-file" 
                    accept="image/*" 
                    style="display: none;">
            </div>
            <?php if ($formData['image_url']): ?>
                <img src="<?= get_challenge_image($formData['image_url']) ?>" alt="Preview" class="image-preview" id="challenge-image-preview">
            <?php else: ?>
                <img src="" alt="Preview" class="image-preview" id="challenge-image-preview" style="display: none;">
            <?php endif; ?>
            <div class="form-help">Logo lub baner wyzwania (max 5MB, JPG/PNG/GIF/WEBP)</div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Data rozpoczęcia</label>
                <input 
                    type="date" 
                    name="start_date" 
                    class="form-input" 
                    value="<?= e($formData['start_date']) ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Data zakończenia</label>
                <input 
                    type="date" 
                    name="end_date" 
                    class="form-input <?= isset($errors['basic']['end_date']) ? 'error' : '' ?>" 
                    value="<?= e($formData['end_date']) ?>">
                <?php if (isset($errors['basic']['end_date'])): ?>
                    <div class="form-error"><?= e($errors['basic']['end_date']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-checkbox">
                    <input type="checkbox" name="is_public" <?= $formData['is_public'] ? 'checked' : '' ?>>
                    <span>Publiczne</span>
                </label>
            </div>
            
            <div class="form-group">
                <label class="form-checkbox">
                    <input type="checkbox" name="active" <?= $formData['active'] ? 'checked' : '' ?>>
                    <span>Aktywne</span>
                </label>
            </div>
        </div>
    </div>
    
    <!-- SEKCJA 2: TRASY -->
    <div class="challenge-form-section" data-section="routes">
        <h3>Trasy w wyzwaniu</h3>
        
        <?php if (!empty($errors['routes']['general'])): ?>
            <div class="form-error-banner">
                <i class="fas fa-exclamation-circle"></i>
                <?= e($errors['routes']['general']) ?>
            </div>
        <?php endif; ?>
        
        <!-- Upload GPX -->
        <div class="route-upload-section">
            <button type="button" class="btn btn-primary" id="upload-gpx-btn">
                <i class="fas fa-upload"></i> Dodaj trasę z GPX
            </button>
            <input type="file" id="gpx-file-input" accept=".gpx" style="display: none;">
            <div id="upload-progress" class="upload-progress" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <span class="progress-text">Uploading...</span>
            </div>
        </div>
        
        <div class="divider-text">
            <span>lub</span>
        </div>
        
        <!-- Wyszukiwarka tras -->
        <div class="route-search-box">
            <input 
                type="text" 
                id="route-search-input" 
                class="form-input" 
                placeholder="Wyszukaj istniejącą trasę..."
                autocomplete="off">
            <div id="route-search-results" class="route-search-results"></div>
        </div>
        
        <!-- Lista wybranych tras -->
        <div id="selected-routes-container" class="selected-routes-list">
            <?php foreach ($selectedRoutes as $route): ?>
                <div class="selected-route-item" data-route-id="<?= $route['route_id'] ?>">
                    <div class="route-item-info">
                        <div class="route-item-name"><?= e($route['name']) ?></div>
                        <div class="route-item-meta">
                            <?= number_format($route['distance_km'], 1) ?> km • 
                            <?= number_format($route['ascent_m']) ?> m
                        </div>
                    </div>
                    <div style="display: flex; gap: 4px;">
                        <button type="button" class="btn-icon" onclick="window.openEditRouteModal(<?= $route['route_id'] ?>)" title="Edytuj">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn-icon btn-danger remove-route-btn">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div id="routes-empty-state" class="empty-state-inline" <?= !empty($selectedRoutes) ? 'style="display:none"' : '' ?>>
            <i class="fas fa-route"></i>
            <p>Brak tras. Użyj wyszukiwarki powyżej.</p>
        </div>
        
        <input type="hidden" name="route_ids" id="route-ids-hidden" value='<?= htmlspecialchars(json_encode($initialRouteIds), ENT_QUOTES) ?>'>
    </div>
    
    <!-- SEKCJA 3: MEDALE -->
    <div class="challenge-form-section" data-section="medals">
        <h3>Medale do zdobycia</h3>
        
        <?php if (!empty($errors['medals']['items'])): ?>
            <div class="form-error-banner">
                <i class="fas fa-exclamation-circle"></i>
                <strong>Błędy w medalach:</strong>
                <ul style="margin: 8px 0 0 20px;">
                    <?php foreach ($errors['medals']['items'] as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <button type="button" class="btn btn-secondary" id="add-medal-btn">
            <i class="fas fa-plus"></i> Dodaj medal
        </button>
        
        <div id="medals-container" class="medals-grid">
            <?php foreach ($medals as $medal): ?>
                <div class="medal-row-item">
                    <input type="text" placeholder="Nazwa" class="form-input" value="<?= e($medal['name']) ?>" data-medal-field="name">
                    <input type="text" placeholder="Poziom" class="form-input" value="<?= e($medal['level']) ?>" data-medal-field="level">
                    <input type="number" placeholder="Min %" class="form-input" value="<?= $medal['required_percent_min'] ?>" data-medal-field="min" min="0" max="100" step="0.01">
                    <input type="number" placeholder="Max %" class="form-input" value="<?= $medal['required_percent_max'] ?>" data-medal-field="max" min="0" max="100" step="0.01">
                    <input type="text" placeholder="Ikona" class="form-input" value="<?= e($medal['badge_icon']) ?>" data-medal-field="icon" maxlength="10">
                    <input type="color" class="form-input" value="<?= e($medal['badge_color']) ?>" data-medal-field="color">
                    <div class="medal-image-upload">
                        <input type="text" placeholder="uploads/medals/..." class="form-input" value="<?= e($medal['badge_image_url'] ?? '') ?>" data-medal-field="badge_image_url" readonly>
                        <button type="button" class="btn-icon upload-medal-image-btn" data-field="badge_image_url" data-type="medal" title="Upload odznaki">
                            <i class="fas fa-upload"></i>
                        </button>
                    </div>
                    <label class="form-checkbox">
                        <input type="checkbox" data-medal-field="has_physical" <?= $medal['has_physical'] ? 'checked' : '' ?>>
                        <span>Fizyczny</span>
                    </label>
                    <input type="number" placeholder="Cena PLN" class="form-input" value="<?= $medal['physical_price_pln'] ?>" data-medal-field="price" min="0" step="0.01">
                    <div class="medal-image-upload">
                        <input type="text" placeholder="uploads/medals/..." class="form-input" value="<?= e($medal['physical_image_url'] ?? '') ?>" data-medal-field="physical_image_url" readonly>
                        <button type="button" class="btn-icon upload-medal-image-btn" data-field="physical_image_url" data-type="medal" title="Upload fizycznego">
                            <i class="fas fa-upload"></i>
                        </button>
                    </div>
                    <button type="button" class="btn-icon btn-danger remove-medal-btn">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div id="medals-empty-state" class="empty-state-inline" <?= !empty($medals) ? 'style="display:none"' : '' ?>>
            <i class="fas fa-medal"></i>
            <p>Brak medali. Kliknij "Dodaj medal".</p>
        </div>
        
        <input type="hidden" name="medals_data" id="medals-data-hidden" value='<?= htmlspecialchars(json_encode($initialMedalsData), ENT_QUOTES) ?>'>
    </div>
    
    <!-- SEKCJA 4: ZAAWANSOWANE -->
    <div class="challenge-form-section" data-section="advanced">
        <h3>Ustawienia zaawansowane</h3>
        
        <div class="form-group">
            <label class="form-label">Minimalny procent ukończenia tras (%)</label>
            <input 
                type="number" 
                name="min_completion_pct" 
                class="form-input <?= isset($errors['basic']['min_completion_pct']) ? 'error' : '' ?>" 
                value="<?= e($formData['min_completion_pct']) ?>"
                min="0" 
                max="100" 
                step="0.01"
                placeholder="100.00">
            <?php if (isset($errors['basic']['min_completion_pct'])): ?>
                <div class="form-error"><?= e($errors['basic']['min_completion_pct']) ?></div>
            <?php endif; ?>
            <div class="form-help">Ile procent tras musi być ukończonych (domyślnie 100%)</div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Minimalny czas (HH:MM)</label>
                <input 
                    type="time" 
                    name="min_completion_time" 
                    class="form-input" 
                    value="<?= secondsToTime($formData['min_completion_time_sec']) ?>">
                <div class="form-help">Opcjonalnie - minimalny czas ukończenia</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Maksymalny czas (HH:MM)</label>
                <input 
                    type="time" 
                    name="max_completion_time" 
                    class="form-input <?= isset($errors['basic']['time']) ? 'error' : '' ?>" 
                    value="<?= secondsToTime($formData['max_completion_time_sec']) ?>">
                <?php if (isset($errors['basic']['time'])): ?>
                    <div class="form-error"><?= e($errors['basic']['time']) ?></div>
                <?php endif; ?>
                <div class="form-help">Opcjonalnie - maksymalny czas ukończenia</div>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-checkbox">
                <input type="checkbox" name="requires_timestamps" <?= $formData['requires_timestamps'] ? 'checked' : '' ?>>
                <span>Wymagaj plików GPX z timestampami</span>
            </label>
            <div class="form-help">Jeśli zaznaczone, użytkownicy muszą uploadować GPX z danymi czasowymi</div>
        </div>
    </div>
    
    <!-- PRZYCISKI -->
    <div class="challenge-form-actions">
        <a href="<?= manage_url('challenges/') ?>" class="btn btn-secondary">Anuluj</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> <?= $isEditMode ? 'Zapisz zmiany' : 'Utwórz wyzwanie' ?>
        </button>
    </div>
    
</form>

<!-- Modal edycji trasy -->
<div id="edit-route-modal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="window.closeEditRouteModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edytuj trasę</h3>
            <button type="button" class="modal-close" onclick="window.closeEditRouteModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="edit-route-form">
                <input type="hidden" id="edit-route-id">
                
                <div class="form-group">
                    <label class="form-label">Nazwa trasy</label>
                    <input type="text" id="edit-route-name" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Opis</label>
                    <textarea id="edit-route-description" class="form-input" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Typ trasy</label>
                        <select id="edit-route-type" class="form-input">
                            <option value="">Brak</option>
                            <option value="road">Szosa</option>
                            <option value="mtb">MTB</option>
                            <option value="gravel">Gravel</option>
                            <option value="mixed">Mieszana</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Trudność</label>
                        <select id="edit-route-difficulty" class="form-input">
                            <option value="">Brak</option>
                            <option value="easy">Łatwa</option>
                            <option value="medium">Średnia</option>
                            <option value="hard">Trudna</option>
                            <option value="expert">Ekspercka</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="window.closeEditRouteModal()">Anuluj</button>
            <button type="button" class="btn btn-primary" onclick="window.saveRouteEdit()">Zapisz</button>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?= get_base_url() ?>/assets/css/manage-challenge-form.css">
<script src="<?= get_base_url() ?>/assets/js/manage-challenge-form.js"></script>
<script>
window.challengeFormData = {
    baseUrl: '<?= get_base_url() ?>',
    selectedRoutes: <?= json_encode(array_column($selectedRoutes, 'route_id')) ?>
};
</script>