<?php
// views/web/partials/translated-note.php
// ETYKIETA TŁUMACZENIA TREŚCI (2026-09-16, tasks/active/wielojezycznosc.md).
// Wzorzec Airbnb/Reddita: treść domyślnie w języku strony, a obok uczciwa
// informacja, że to tłumaczenie, i droga do oryginału. Jeden partial dla
// eventu, organizatora, trasy i skarbu.
//
// Oczekuje: $tnMeta (Models\ContentTranslation::meta), opcjonalnie $tnEditUrl
// (link do korekty — tylko gdy widz MA do niej prawo; decyduje kontroler).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (empty($tnMeta) || empty($tnMeta['translated'])) {
    return;
}

$tnJezyki = [
    'pl' => __('Przetłumaczono automatycznie z polskiego'),
    'en' => __('Przetłumaczono automatycznie z angielskiego'),
    'de' => __('Przetłumaczono automatycznie z niemieckiego'),
    'cs' => __('Przetłumaczono automatycznie z czeskiego'),
    'sk' => __('Przetłumaczono automatycznie ze słowackiego'),
];
$tnOpis = ($tnMeta['origin'] ?? '') === 'human'
    ? __('Tłumaczenie poprawione przez autora')
    : ($tnJezyki[$tnMeta['from'] ?? ''] ?? __('Przetłumaczono automatycznie'));

$tnQuery = $_GET;
if (!empty($tnMeta['original'])) {
    unset($tnQuery['oryginal']);
} else {
    $tnQuery['oryginal'] = '1';
}
$tnPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$tnHref = $tnPath . ($tnQuery ? '?' . http_build_query($tnQuery) : '');
?>
<p class="translated-note">
    <?= Utils\Icon::maybe('globe') ?>
    <?php if (!empty($tnMeta['original'])): ?>
    <span><?= __('Oryginalna wersja tekstu') ?></span> · <a href="<?= htmlspecialchars($tnHref) ?>" rel="nofollow"><?= __('Pokaż tłumaczenie') ?></a>
    <?php else: ?>
    <span><?= htmlspecialchars($tnOpis) ?></span> · <a href="<?= htmlspecialchars($tnHref) ?>" rel="nofollow"><?= __('Pokaż oryginał') ?></a>
    <?php endif; ?>
    <?php if (!empty($tnEditUrl)): ?>
    · <a href="<?= htmlspecialchars($tnEditUrl) ?>"><?= __('Popraw tłumaczenie') ?></a>
    <?php endif; ?>
</p>
