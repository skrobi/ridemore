<?php
// views/web/pages/taxonomy-admin.php
// Oczekuje: $dictionaries (Dictionary::dictionaries()), $selectedCode (string),
// $tree (Dictionary::tree($selectedCode)), $error (?string), $info (?string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\View;

// Ta sama płaska lista z wcięciami służy do dwóch rzeczy: renderowania
// drzewa (rekurencyjnie) i budowy opcji <select name="parent_id"> — stąd
// osobna funkcja spłaszczająca $tree z zachowaniem głębokości.
$flatten = function (array $nodes, int $depth = 0) use (&$flatten): array {
    $out = [];
    foreach ($nodes as $node) {
        $out[] = ['depth' => $depth, 'node' => $node];
        foreach ($flatten($node['children'], $depth + 1) as $child) {
            $out[] = $child;
        }
    }
    return $out;
};
$flatItems = $flatten($tree);
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display">Taksonomia</h1>
<p class="desc spaced-below">Zarządzanie słownikami aplikacji (region, trudność, typ roweru...) — pozycje mogą mieć elementy nadrzędne, np. kraj z regionami w jego obrębie.</p>

<?php if ($error): ?>
<p class="form-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
<?php if ($info): ?>
<p class="form-success"><?= htmlspecialchars($info) ?></p>
<?php endif; ?>

<form method="get" action="<?= View::url('/admin/taksonomia') ?>" class="action-row">
    <select class="search-input" name="dict" onchange="this.form.submit()" style="max-width:280px;">
        <?php foreach ($dictionaries as $d): ?>
        <option value="<?= htmlspecialchars($d['code']) ?>" <?= $d['code'] === $selectedCode ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="taxonomy-tree spaced-below">
    <?php if (empty($flatItems)): ?>
    <p class="desc">Ten słownik nie ma jeszcze żadnych pozycji.</p>
    <?php endif; ?>
    <?php foreach ($flatItems as $entry): $node = $entry['node']; $depth = $entry['depth']; ?>
    <div class="card taxonomy-node" style="margin-left: <?= $depth * 24 ?>px;">
        <div class="taxonomy-node-row">
            <span class="taxonomy-node-name<?= $node['isActive'] ? '' : ' taxonomy-inactive' ?>">
                <?= htmlspecialchars($node['name']) ?> <code><?= htmlspecialchars($node['code']) ?></code>
            </span>
            <span class="taxonomy-node-actions">
                <details style="display:inline-block;">
                    <summary class="btn btn-secondary" style="display:inline-block;">Edytuj</summary>
                    <form method="post" action="<?= View::url('/admin/taksonomia/' . $node['id'] . '/edytuj') ?>" class="taxonomy-edit-form">
                        <?= Csrf::field() ?>
                        <input class="search-input" type="text" name="code" maxlength="64" required value="<?= htmlspecialchars($node['code']) ?>">
                        <input class="search-input" type="text" name="name" maxlength="128" required value="<?= htmlspecialchars($node['name']) ?>">
                        <input class="search-input" type="number" name="sort_order" value="<?= $node['sortOrder'] ?>">
                        <button class="btn" type="submit">Zapisz</button>
                    </form>
                </details>
                <form method="post" action="<?= View::url('/admin/taksonomia/' . $node['id'] . '/przelacz') ?>" style="display:inline;">
                    <?= Csrf::field() ?>
                    <button class="btn btn-secondary" type="submit"><?= $node['isActive'] ? 'Dezaktywuj' : 'Aktywuj' ?></button>
                </form>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="auth-page auth-page-wider">
    <p class="subline">Dodaj nową pozycję</p>
    <form class="auth-form" method="post" action="<?= View::url('/admin/taksonomia/dodaj') ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="dictionary_code" value="<?= htmlspecialchars($selectedCode) ?>">
        <input class="search-input" type="text" name="code" placeholder="Kod (np. bawaria, tylko a-z0-9_)" maxlength="64" required>
        <input class="search-input" type="text" name="name" placeholder="Nazwa (np. Bawaria)" maxlength="128" required>
        <input class="search-input" type="number" name="sort_order" placeholder="Kolejność (opcjonalnie)" value="0">
        <select class="search-input" name="parent_id">
            <option value="">— (brak, poziom najwyższy) —</option>
            <?php foreach ($flatItems as $entry): ?>
            <option value="<?= $entry['node']['id'] ?>"><?= str_repeat('— ', $entry['depth']) ?><?= htmlspecialchars($entry['node']['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Dodaj</button>
    </form>
</div>
