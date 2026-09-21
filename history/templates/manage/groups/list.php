<?php
/* ============================================================================
   PLIK: templates/manage/groups/list.php
   Template - lista grup wyzwań
   ============================================================================ */
?>

<div class="manage-page-header">
    <div>
        <h1>Grupy Wyzwań</h1>
        <p class="manage-page-subtitle">Organizuj wyzwania w tematyczne grupy</p>
    </div>
    
    <?php if (isAdmin()): ?>
        <a href="<?= manage_url('/groups/create.php'); ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Dodaj grupę
        </a>
    <?php endif; ?>
</div>

<?php if (empty($groups)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">
            <i class="fas fa-folder-open"></i>
        </div>
        <h3>Brak grup</h3>
        <p>
            <?php if (isAdmin()): ?>
                Utwórz pierwszą grupę aby organizować wyzwania
            <?php else: ?>
                Nie masz jeszcze żadnych wyzwań przypisanych do grup
            <?php endif; ?>
        </p>
        <?php if (isAdmin()): ?>
            <a href="<?= manage_url('groups/create.php'); ?>" class="btn btn-primary">Dodaj pierwszą grupę</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="manage-table-container">
        <table class="manage-table">
            <thead>
                <tr>
                    <th width="60">Ikona</th>
                    <th>Nazwa</th>
                    <th>Slug</th>
                    <th width="100">Kolor</th>
                    <th width="100">Wyzwań</th>
                    <th width="80">Kolejność</th>
                    <th width="100">Status</th>
                    <?php if (isAdmin()): ?>
                        <th width="120">Akcje</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr>
                        <td class="text-center">
                            <span style="font-size: 24px;"><?= e($group['icon']) ?></span>
                        </td>
                        <td>
                            <strong><?= e($group['name']) ?></strong>
                            <?php if ($group['description']): ?>
                                <br><small class="text-muted"><?= e(substr($group['description'], 0, 60)) ?><?= strlen($group['description']) > 60 ? '...' : '' ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code><?= e($group['slug']) ?></code>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 24px; height: 24px; background: <?= e($group['color']) ?>; border-radius: 4px; border: 1px solid var(--color-border);"></div>
                                <code><?= e($group['color']) ?></code>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge"><?= $group['challenges_count'] ?></span>
                        </td>
                        <td class="text-center">
                            <?= $group['sort_order'] ?>
                        </td>
                        <td>
                            <?php if ($group['active']): ?>
                                <span class="status-badge active">Aktywna</span>
                            <?php else: ?>
                                <span class="status-badge inactive">Nieaktywna</span>
                            <?php endif; ?>
                        </td>
                        <?php if (isAdmin()): ?>
                            <td>
                                <div class="action-buttons">
                                    <a href="<?= manage_url('groups/edit.php?id='. $group['group_id']); ?>" class="btn-icon" title="Edytuj">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($group['challenges_count'] == 0): ?>
                                        <button onclick="deleteGroup(<?= $group['group_id'] ?>)" class="btn-icon btn-danger" title="Usuń">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-icon" disabled title="Nie można usunąć - grupa ma wyzwania">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
function deleteGroup(groupId) {
    if (!confirm('Czy na pewno chcesz usunąć tę grupę?\n\nTej operacji nie można cofnąć.')) {
        return;
    }
    
    fetch(`/api/manage/groups.php?id=${groupId}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Błąd: ' + (data.error || 'Nie udało się usunąć grupy'));
        }
    })
    .catch(err => {
        alert('Błąd połączenia: ' + err.message);
    });
}
</script>