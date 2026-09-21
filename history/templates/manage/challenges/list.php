<?php
/* ============================================================================
   PLIK: templates/manage/challenges/list.php
   Template - lista wyzwań
   ============================================================================ */
?>

<div class="manage-page-header">
    <div>
        <h1>Wyzwania</h1>
        <p class="manage-page-subtitle">Zarządzaj swoimi wyzwaniami rowerowymi</p>
    </div>
    
    <a href="<?= manage_url('challenges/form.php') ?>" class="btn btn-primary">
        <i class="fas fa-plus"></i> Utwórz wyzwanie
    </a>
</div>

<?php if (empty($challenges)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">
            <i class="fas fa-bullseye"></i>
        </div>
        <h3>Brak wyzwań</h3>
        <p>Utwórz pierwsze wyzwanie aby zaangażować społeczność</p>
        <a href="<?= manage_url('challenges/form.php') ?>" class="btn btn-primary">Utwórz pierwsze wyzwanie</a>
    </div>
<?php else: ?>
    <div class="manage-cards-grid">
        <?php foreach ($challenges as $challenge): ?>
            <div class="challenge-card">
                <div class="challenge-card-header">
                    <div class="challenge-card-icon" style="background: <?= e($challenge['color']) ?>;">
                        <?= e($challenge['icon']) ?>
                    </div>
                    <div class="challenge-card-info">
                        <h3><?= e($challenge['name']) ?></h3>
                        <?php if ($challenge['group_name']): ?>
                            <div class="challenge-card-group">
                                <?= e($challenge['group_icon']) ?> <?= e($challenge['group_name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="challenge-card-status">
                        <?php if ($challenge['active']): ?>
                            <span class="status-badge active">Aktywne</span>
                        <?php else: ?>
                            <span class="status-badge inactive">Nieaktywne</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="challenge-card-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $challenge['total_routes'] ?></div>
                        <div class="stat-label">Tras</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= number_format($challenge['total_distance_km'], 0) ?></div>
                        <div class="stat-label">km</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $challenge['participants_count'] ?></div>
                        <div class="stat-label">Uczestników</div>
                    </div>
                </div>
                
                <div class="challenge-card-meta">
                    <?php if ($challenge['start_date'] && $challenge['end_date']): ?>
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            <?= date('d.m.Y', strtotime($challenge['start_date'])) ?> - <?= date('d.m.Y', strtotime($challenge['end_date'])) ?>
                        </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <i class="fas fa-globe"></i>
                        <?= $challenge['is_public'] ? 'Publiczne' : 'Prywatne' ?>
                    </div>
                </div>
                
                <div class="challenge-card-actions">
                    <a href="<?= manage_url('challenges/form.php?id=' . $challenge['challenge_id']) ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-edit"></i> Edytuj
                    </a>
                    <a href="<?= manage_url('challenges/stats.php?id=' . $challenge['challenge_id']) ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-chart-bar"></i> Statystyki
                    </a>
                    <button onclick="deleteChallenge(<?= $challenge['challenge_id'] ?>)" class="btn btn-outline btn-sm">
                        <i class="fas fa-trash"></i> Usuń
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
const baseUrl = '<?= get_base_url() ?>';

function deleteChallenge(challengeId) {
    if (!confirm('Czy na pewno chcesz usunąć to wyzwanie?\n\nUsuną się również:\n- Przypisane trasy\n- Postępy użytkowników\n- Medale\n\nTej operacji nie można cofnąć.')) {
        return;
    }
    
    fetch(`${baseUrl}/api/manage/challenges.php?id=${challengeId}`, {
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
            alert('Błąd: ' + (data.error || 'Nie udało się usunąć wyzwania'));
        }
    })
    .catch(err => {
        alert('Błąd połączenia: ' + err.message);
    });
}
</script>