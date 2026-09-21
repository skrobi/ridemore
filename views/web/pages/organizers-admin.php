<?php
// views/web/pages/organizers-admin.php
// Oczekuje: $organizers (Models\Organizer::allForAdmin()['items']), $total,
// $filters (q, status), $info (?string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\Format;
use Utils\View;

$organizers = $organizers ?? [];
$filters    = $filters ?? [];
$typeLabels = ['peer' => 'Społecznościowy', 'professional_operator' => 'Operator turystyczny'];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display" style="font-size:28px;">Organizatorzy</h1>
<p class="desc spaced-below">Edycja, weryfikacja i dezaktywacja profili organizatorów. Dezaktywowany profil znika z publicznej listy i wyszukiwania, ale jego dane i wydarzenia zostają nietknięte.</p>

<?php if (!empty($info)): ?>
<p class="form-success"><?= htmlspecialchars($info) ?></p>
<?php endif; ?>

<form method="get" action="<?= View::url('/admin/organizatorzy') ?>" class="search-row" style="margin-bottom:20px;">
    <input class="search-input" type="text" name="q" placeholder="Szukaj: nazwa, e-mail..." value="<?= htmlspecialchars($filters['q'] ?? '') ?>">
    <select class="search-input" name="status" onchange="this.form.submit()" style="max-width:200px;">
        <option value="">Wszyscy</option>
        <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Tylko aktywni</option>
        <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Tylko nieaktywni</option>
    </select>
    <button class="locate-btn" type="submit">Szukaj</button>
</form>

<?php if (empty($organizers)): ?>
<p class="desc">Brak organizatorów spełniających kryteria.</p>
<?php else: ?>
<div class="dash-table">
    <div class="dash-row dash-head">
        <div class="dash-cell dash-cell-title">Organizator</div>
        <div class="dash-cell">Typ</div>
        <div class="dash-cell">Status</div>
        <div class="dash-cell">Wydarzenia</div>
        <div class="dash-cell">Od</div>
        <div class="dash-cell">Akcje</div>
    </div>
    <?php foreach ($organizers as $o): ?>
    <div class="dash-row">
        <div class="dash-cell dash-cell-title" data-label="Organizator">
            <a href="<?= View::url('/organizatorzy/' . $o['slug']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($o['name']) ?></a>
            <div class="dash-sub"><?= htmlspecialchars($o['email']) ?><?= $o['isUnclaimed'] ? ' · konto nieprzejęte' : '' ?></div>
        </div>
        <div class="dash-cell" data-label="Typ"><?= htmlspecialchars($typeLabels[$o['organizerType']] ?? $o['organizerType']) ?></div>
        <div class="dash-cell" data-label="Status">
            <span class="badge <?= $o['isVerified'] ? 'badge-free' : 'badge-pending-payment' ?>"><?= $o['isVerified'] ? 'Zweryfikowany' : 'Niezweryfikowany' ?></span>
            <?php if (!$o['isActive']): ?><span class="badge badge-pending-payment">Nieaktywny</span><?php endif; ?>
        </div>
        <div class="dash-cell" data-label="Wydarzenia"><?= $o['eventsCount'] ?></div>
        <div class="dash-cell" data-label="Od"><?= htmlspecialchars(Format::dateShort($o['createdAt'])) ?></div>
        <div class="dash-cell" data-label="Akcje">
            <a href="<?= View::url('/admin/organizatorzy/' . $o['slug'] . '/edytuj') ?>" class="link-button">Edytuj</a>
            <form method="post" action="<?= View::url('/organizatorzy/' . $o['slug'] . '/weryfikacja') ?>" style="display:inline;">
                <?= Csrf::field() ?>
                <button type="submit" class="link-button"><?= $o['isVerified'] ? 'Cofnij weryfikację' : 'Zweryfikuj' ?></button>
            </form>
            <form method="post" action="<?= View::url('/admin/organizatorzy/' . $o['slug'] . '/aktywnosc') ?>" style="display:inline;" onsubmit="return <?= $o['isActive'] ? "confirm('Dezaktywować ten profil? Zniknie z publicznej listy i wyszukiwania.')" : 'true' ?>;">
                <?= Csrf::field() ?>
                <input type="hidden" name="redirect" value="<?= htmlspecialchars(View::url('/admin/organizatorzy')) ?>">
                <button type="submit" class="link-button"><?= $o['isActive'] ? 'Dezaktywuj' : 'Aktywuj' ?></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
