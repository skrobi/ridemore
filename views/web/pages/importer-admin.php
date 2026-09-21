<?php
// views/web/pages/importer-admin.php
// PANEL IMPORTERA WYDARZEŃ — /admin/importer (Etap 4 importera). Zarządzanie
// z poziomu serwisu zamiast CLI: pojedynczy adres, zbieranie linków z
// kalendarza, stała lista źródeł, dziennik i kandydaci do weryfikacji.
// Kontroler: Controllers\Admin\ImporterController. Komponenty pożyczone z reszty
// panelu (.box/.btn/.hint/.desc/.dash-table) — bez nowego CSS.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\View;

$statusMeta = [
    'created'            => ['✅', __('utworzono kandydata')],
    'possible_duplicate' => ['⚠', __('kandydat — możliwy duplikat')],
    'skipped'           => ['—', __('pominięto')],
    'js_rendered'       => ['🧩', __('strona na JS (SPA) — użyj rozszerzenia Chrome')],
    'unsupported_source' => ['🚫', __('Facebook/Instagram — użyj rozszerzenia Chrome')],
    'error'             => ['⛔', __('błąd')],
];
?>
<h1 class="display"><?= __('Importer wydarzeń') ?></h1>
<p class="desc spaced-below">
    <?= __('Wciąga wydarzenia z internetu i wstawia je jako kandydatów w kolejce weryfikacji — nic nie publikuje się samo. Zatwierdzasz je w') ?>
    <a href="<?= View::url('/admin') ?>"><?= __('panelu wydarzeń') ?></a>.
</p>

<?php if ($flash): ?><p class="form-success"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
<?php if ($error): ?><p class="form-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<?php if (!$engineReady): ?>
<div class="box">
    <p class="hint" style="margin:0;">
        ⚠ <b><?= __('Silnik AI nie jest tu aktywny') ?></b> —
        <?= __('ekstrakcja działa tylko w środowisku dev z mostem PHP→Python. „Zbierz linki" i lista źródeł działają, ale sam import zwróci błąd.') ?>
    </p>
</div>
<?php endif; ?>

<?php /* ── WYNIK IMPORTU ─────────────────────────────────────────────── */ ?>
<?php if (is_array($results)): ?>
<div class="box">
    <h3><?= __('Wynik importu') ?></h3>
    <table class="dash-table">
        <thead><tr><th></th><th><?= __('Adres') ?></th><th><?= __('Wynik') ?></th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): [$ikona, $etykieta] = $statusMeta[$r['status']] ?? ['?', $r['status']]; ?>
            <tr>
                <td><?= $ikona ?></td>
                <td><a href="<?= htmlspecialchars($r['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= htmlspecialchars($r['url']) ?></a></td>
                <td>
                    <?= htmlspecialchars($etykieta) ?>
                    <?php if (!empty($r['slug'])): ?>
                        — <a href="<?= View::url('/events/' . $r['slug']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($r['slug']) ?></a>
                    <?php endif; ?>
                    <?php if (!empty($r['duplicateOf'])): ?>
                        (<?= __('wobec') ?> <a href="<?= View::url('/events/' . $r['duplicateOf']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($r['duplicateOf']) ?></a>)
                    <?php endif; ?>
                    <?php if (!empty($r['reason']) && in_array($r['status'], ['skipped','error','js_rendered','unsupported_source'], true)): ?>
                        <span class="hint"><?= htmlspecialchars($r['reason']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint"><?= __('Kandydaci czekają na zatwierdzenie w') ?> <a href="<?= View::url('/admin') ?>"><?= __('panelu wydarzeń') ?></a>.</p>
</div>
<?php endif; ?>

<?php /* ── ZEBRANE LINKI (harvest) ───────────────────────────────────── */ ?>
<?php if (is_array($harvest)): ?>
<div class="box">
    <h3><?= __('Zebrane linki wydarzeń') ?></h3>
    <form method="post" action="<?= View::url('/admin/importer/import') ?>">
        <?= Csrf::field() ?>
        <?php $any = false; foreach ($harvest as $h): ?>
            <p class="hint" style="margin-top:12px;">
                <b><?= htmlspecialchars($h['listUrl']) ?></b> —
                <?php if ($h['error']): ?><span class="form-error"><?= htmlspecialchars($h['error']) ?></span>
                <?php elseif ($h['jsRendered']): ?>🧩 <?= __('strona renderowana JavaScriptem (SPA) — serwer jej nie odczyta; użyj rozszerzenia Chrome („🔗 Zbierz linki")') ?>
                <?php else: ?><?= __('znaleziono {n} linków', ['n' => count($h['links'])]) ?><?php endif; ?>
            </p>
            <?php foreach ($h['links'] as $l): $any = true; ?>
            <label class="check-line" style="display:flex;gap:8px;align-items:center;padding:3px 0;">
                <input type="checkbox" name="urls[]" value="<?= htmlspecialchars($l['url']) ?>" <?= empty($l['alreadyImported']) ? 'checked' : '' ?>>
                <a href="<?= htmlspecialchars($l['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" style="flex:1;"><?= htmlspecialchars($l['text'] !== '' ? $l['text'] : $l['url']) ?></a>
                <?php if (!empty($l['alreadyImported'])): ?><span class="hint"><?= __('już zaimportowane') ?></span><?php endif; ?>
            </label>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if ($any): ?>
        <p style="margin-top:12px;"><button type="submit" class="btn btn-primary"><?= __('Importuj zaznaczone') ?></button></p>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<?php /* ── DODAJ POJEDYNCZY ADRES ────────────────────────────────────── */ ?>
<div class="box">
    <h3><?= __('Dodaj pojedynczy event (link)') ?></h3>
    <p class="hint"><?= __('Adres strony pojedynczego wydarzenia. Zostanie przeanalizowany i dodany jako kandydat do weryfikacji.') ?></p>
    <form method="post" action="<?= View::url('/admin/importer/import') ?>">
        <?= Csrf::field() ?>
        <div class="fieldrow" style="display:flex;gap:8px;">
            <input type="url" name="url" placeholder="https://…/wydarzenie/…" required style="flex:1;">
            <button type="submit" class="btn btn-primary"><?= __('Importuj') ?></button>
        </div>
    </form>
</div>

<?php /* ── ZBIERZ Z KALENDARZA ───────────────────────────────────────── */ ?>
<div class="box">
    <h3><?= __('Zbierz linki z kalendarza (strony-listy)') ?></h3>
    <p class="hint"><?= __('Adres strony z listą wydarzeń. Wyłuskamy linki pojedynczych wydarzeń — zaznaczysz, które zaimportować. Działa dla stron renderowanych po stronie serwera (nie SPA).') ?></p>
    <form method="post" action="<?= View::url('/admin/importer/zbierz') ?>">
        <?= Csrf::field() ?>
        <div class="fieldrow" style="display:flex;gap:8px;">
            <input type="url" name="list_url" placeholder="https://…/kalendarz" required style="flex:1;">
            <button type="submit" class="btn btn-secondary"><?= __('Zbierz linki') ?></button>
        </div>
    </form>
</div>

<?php /* ── ŹRÓDŁA (allowlista) ───────────────────────────────────────── */ ?>
<div class="box">
    <h3><?= __('Źródła (stałe kalendarze)') ?></h3>
    <p class="hint"><?= __('Lista, po której chodzi „Zbierz ze wszystkich" oraz worker CLI') ?> <code>php import_events.php --sources</code>.</p>

    <?php if ($sources): ?>
    <table class="dash-table">
        <tbody>
        <?php foreach ($sources as $s): ?>
            <tr>
                <td><a href="<?= htmlspecialchars($s['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= htmlspecialchars($s['url']) ?></a></td>
                <td style="text-align:right;">
                    <form method="post" action="<?= View::url('/admin/importer/zrodla/usun') ?>" style="display:inline;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="source_url" value="<?= htmlspecialchars($s['url']) ?>">
                        <button type="submit" class="btn btn-ghost btn-sm"><?= __('Usuń') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" action="<?= View::url('/admin/importer/zbierz') ?>" style="margin-top:10px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="all" value="1">
        <button type="submit" class="btn btn-secondary"><?= __('Zbierz ze wszystkich źródeł') ?></button>
    </form>
    <?php else: ?>
    <p class="desc"><?= __('Brak źródeł. Dodaj pierwsze poniżej.') ?></p>
    <?php endif; ?>

    <form method="post" action="<?= View::url('/admin/importer/zrodla/dodaj') ?>" style="margin-top:12px;">
        <?= Csrf::field() ?>
        <div class="fieldrow" style="display:flex;gap:8px;">
            <input type="url" name="source_url" placeholder="https://…/kalendarz" required style="flex:1;">
            <button type="submit" class="btn btn-ghost"><?= __('Dodaj źródło') ?></button>
        </div>
    </form>
</div>

<?php /* ── KANDYDACI DO WERYFIKACJI ──────────────────────────────────── */ ?>
<?php if ($pending): ?>
<div class="box">
    <h3><?= __('Kandydaci z importu do weryfikacji') ?> (<?= count($pending) ?>)</h3>
    <table class="dash-table">
        <tbody>
        <?php foreach ($pending as $p): ?>
            <tr>
                <td><a href="<?= View::url('/wydarzenia/' . $p['slug'] . '/edytuj') ?>"><?= htmlspecialchars($p['title']) ?></a></td>
                <td class="hint"><?= htmlspecialchars((string) $p['start_date']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint"><?= __('Zatwierdź/odrzuć w') ?> <a href="<?= View::url('/admin') ?>"><?= __('panelu wydarzeń') ?></a>.</p>
</div>
<?php endif; ?>

<?php /* ── DZIENNIK EKSTRAKCJI ───────────────────────────────────────── */ ?>
<?php if ($recentLog): ?>
<div class="box">
    <h3><?= __('Ostatnie analizy') ?></h3>
    <table class="dash-table">
        <thead><tr><th><?= __('Kiedy') ?></th><th><?= __('Domena') ?></th><th><?= __('Pewność') ?></th><th><?= __('Adres') ?></th></tr></thead>
        <tbody>
        <?php foreach ($recentLog as $row): ?>
            <tr>
                <td class="hint"><?= htmlspecialchars((string) $row['created_at']) ?></td>
                <td><?= htmlspecialchars((string) ($row['source_domain'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string) ($row['confidence'] ?? '—')) ?></td>
                <td><a href="<?= htmlspecialchars((string) $row['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= htmlspecialchars(mb_strimwidth((string) $row['url'], 0, 70, '…')) ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
