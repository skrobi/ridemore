<?php
/* ============================================================================
   PLIK: templates/manage/groups/form.php
   Template - formularz grupy (create/edit)
   ============================================================================ */

$isEdit = isset($group);
$title = $isEdit ? 'Edytuj Grupę' : 'Dodaj Grupę';
$submitText = $isEdit ? 'Zapisz zmiany' : 'Utwórz grupę';
?>

<div class="manage-page-header">
    <div>
        <h1><?= $title ?></h1>
        <p class="manage-page-subtitle">Grupy pomagają organizować wyzwania tematycznie</p>
    </div>
    <a href="/manage/groups/" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Powrót
    </a>
</div>

<div class="manage-form-container">
    <form method="POST" class="manage-form">
        
        <?php if (!empty($errors['general'])): ?>
            <div class="form-error-banner">
                <i class="fas fa-exclamation-circle"></i>
                <?= e($errors['general']) ?>
            </div>
        <?php endif; ?>
        
        <!-- Nazwa -->
        <div class="form-group">
            <label class="form-label required">Nazwa grupy</label>
            <input 
                type="text" 
                name="name" 
                class="form-input <?= isset($errors['name']) ? 'error' : '' ?>" 
                value="<?= e($formData['name']) ?>"
                placeholder="np. Korona Gór Polski"
                required>
            <?php if (isset($errors['name'])): ?>
                <div class="form-error"><?= e($errors['name']) ?></div>
            <?php endif; ?>
            <div class="form-help">Nazwa wyświetlana w aplikacji</div>
        </div>
        
        <!-- Slug -->
        <div class="form-group">
            <label class="form-label required">Slug</label>
            <input 
                type="text" 
                name="slug" 
                class="form-input <?= isset($errors['slug']) ? 'error' : '' ?>" 
                value="<?= e($formData['slug']) ?>"
                placeholder="korona-gor-polski"
                pattern="[a-z0-9-]+"
                required>
            <?php if (isset($errors['slug'])): ?>
                <div class="form-error"><?= e($errors['slug']) ?></div>
            <?php endif; ?>
            <div class="form-help">Tylko małe litery, cyfry i myślniki (używane w URL)</div>
        </div>
        
        <!-- Opis -->
        <div class="form-group">
            <label class="form-label">Opis</label>
            <textarea 
                name="description" 
                class="form-input" 
                rows="4"
                placeholder="Opcjonalny opis grupy..."><?= e($formData['description']) ?></textarea>
            <div class="form-help">Krótki opis grupy (opcjonalnie)</div>
        </div>
        
        <!-- Ikona i Kolor -->
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Ikona (emoji)</label>
                <input 
                    type="text" 
                    name="icon" 
                    class="form-input" 
                    value="<?= e($formData['icon']) ?>"
                    placeholder="🎯"
                    maxlength="10">
                <div class="form-help">Emoji lub ikona (1-2 znaki)</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Kolor</label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input 
                        type="color" 
                        name="color" 
                        value="<?= e($formData['color']) ?>"
                        style="width: 60px; height: 40px; border: 1px solid var(--color-border); border-radius: var(--radius-md); cursor: pointer;">
                    <input 
                        type="text" 
                        value="<?= e($formData['color']) ?>"
                        readonly
                        class="form-input"
                        style="flex: 1;">
                </div>
                <div class="form-help">Kolor przewodni grupy</div>
            </div>
        </div>
        
        <!-- Kolejność -->
        <div class="form-group">
            <label class="form-label">Kolejność sortowania</label>
            <input 
                type="number" 
                name="sort_order" 
                class="form-input" 
                value="<?= e($formData['sort_order']) ?>"
                min="0"
                step="1">
            <div class="form-help">Niższe wartości będą wyświetlane jako pierwsze</div>
        </div>
        
        <!-- Aktywna -->
        <div class="form-group">
            <label class="form-checkbox">
                <input 
                    type="checkbox" 
                    name="active" 
                    <?= $formData['active'] ? 'checked' : '' ?>>
                <span>Grupa aktywna</span>
            </label>
            <div class="form-help">Nieaktywne grupy nie są widoczne dla użytkowników</div>
        </div>
        
        <!-- Przyciski -->
        <div class="form-actions">
            <a href="/manage/groups/" class="btn btn-secondary">Anuluj</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $submitText ?>
            </button>
        </div>
        
    </form>
</div>

<script>
// Auto-generuj slug z nazwy
document.querySelector('input[name="name"]').addEventListener('input', function(e) {
    const slugInput = document.querySelector('input[name="slug"]');
    if (!slugInput.value || slugInput.dataset.autoGenerated) {
        const slug = e.target.value
            .toLowerCase()
            .replace(/ą/g, 'a')
            .replace(/ć/g, 'c')
            .replace(/ę/g, 'e')
            .replace(/ł/g, 'l')
            .replace(/ń/g, 'n')
            .replace(/ó/g, 'o')
            .replace(/ś/g, 's')
            .replace(/ź|ż/g, 'z')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        slugInput.value = slug;
        slugInput.dataset.autoGenerated = 'true';
    }
});

document.querySelector('input[name="slug"]').addEventListener('input', function() {
    delete this.dataset.autoGenerated;
});

// Update color text input
document.querySelector('input[type="color"]').addEventListener('input', function(e) {
    document.querySelector('input[type="text"][readonly]').value = e.target.value;
});
</script>