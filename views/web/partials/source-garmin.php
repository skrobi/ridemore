<?php
// views/web/partials/source-garmin.php
// ZAKŁADKA „GARMIN" — jedyny dostawca bez OAuth, więc jedyny z własnym panelem.
//
// Reszta liczników loguje się u siebie i wraca do nas z tokenem (jeden przycisk
// „Połącz konto", patrz `ride-sources.php`). Garmin nie ma API dla kont
// osobistych, więc tutaj stoi PRAWDZIWY FORMULARZ LOGOWANIA — a to znaczy, że
// przyjmujemy cudze hasło i musimy o tym powiedzieć wprost, w tym samym miejscu,
// w którym o nie prosimy.
//
// Oczekuje w zasięgu: $conn (połączenie albo null), $lista (aktywności z sesji),
// $garminNext (od którego miejsca szukać starszych; 0 = koniec historii), $plural. Trasy: /admin/moje-przejazdy/garmin/*.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\Format;
use Utils\View;
?>
<?php if (!$conn): ?>
<p class="desc"><?= __('Hasła nie zapisujemy: służy wyłącznie do jednego logowania, a zostaje
    po nim tylko token sesji (zaszyfrowany). Pole na kod 2FA jest widoczne od razu —
    kto ma dwuskładnikowe, wpisze kod za jednym zamachem, zamiast dostać odmowę i wracać.') ?></p>
<form method="post" action="<?= View::url('/admin/moje-przejazdy/garmin/polacz') ?>"
      class="track-upload" autocomplete="off">
    <?= Csrf::field() ?>
    <input class="search-input" type="email" name="garmin_email" required
           placeholder="<?= htmlspecialchars(__('E-mail konta Garmin Connect')) ?>" autocomplete="off">
    <input class="search-input" type="password" name="garmin_haslo" required
           placeholder="<?= htmlspecialchars(__('Hasło')) ?>" autocomplete="new-password" style="max-width:220px;">
    <input class="search-input" type="text" name="garmin_kod" inputmode="numeric"
           placeholder="<?= htmlspecialchars(__('Kod 2FA (jeśli masz)')) ?>" autocomplete="off" style="max-width:170px;">
    <button class="btn" type="submit"><?= __('Połącz konto') ?></button>
</form>

<?php else: ?>
<div class="track-list__row" style="border-bottom:none;padding-bottom:14px;">
    <span><?= __('Połączono jako') ?> <b><?= htmlspecialchars($conn['displayName'] ?: ($conn['label'] ?? __('Garmin'))) ?></b><?php
        if (!empty($conn['lastSyncAt'])): ?><br><span class="desc" style="font-size:13px;">ostatnie
        pobranie: <?= htmlspecialchars(Format::dateShort(substr($conn['lastSyncAt'], 0, 10)) ?? '') ?></span><?php
        endif; ?></span>
    <span class="action-row" style="margin:0;">
        <form method="post" action="<?= View::url('/admin/moje-przejazdy/garmin/odswiez') ?>" style="display:inline;">
            <?= Csrf::field() ?>
            <button class="btn btn--sm" type="submit"><?= __('Pobierz aktywności rowerowe') ?></button>
        </form>
        <?php // SZUKAJ STARSZYCH — jedno kliknięcie przegląda 300 ostatnich
              // aktywności (wszystkich dyscyplin), a historia bywa dłuższa.
              // Przycisk pojawia się dopiero, gdy poprzednie przeglądanie
              // dobiło do końca okna, czyli gdy NAPRAWDĘ może być coś dalej. ?>
        <?php if ($garminNext > 0): ?>
        <form method="post" action="<?= View::url('/admin/moje-przejazdy/garmin/odswiez') ?>" style="display:inline;">
            <?= Csrf::field() ?>
            <input type="hidden" name="start" value="<?= (int) $garminNext ?>">
            <button class="btn btn-secondary btn--sm" type="submit"><?= __('Szukaj starszych') ?></button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= View::url('/admin/moje-przejazdy/garmin/odlacz') ?>" style="display:inline;"
              onsubmit="return confirm(__('Odłączyć konto Garmin? Pobrane przejazdy zostaną.'));">
            <?= Csrf::field() ?>
            <button class="btn btn-secondary btn--sm" type="submit"><?= __('Odłącz') ?></button>
        </form>
    </span>
</div>

<?php if ($lista): ?>
<?php // Garmin jako JEDYNY ma pewną mapę dyscyplin (ai-engine/garmin.py), więc
      // lista jest tu już odsiana do samego roweru — bez trenażera i jazdy
      // wirtualnej, które nie mają śladu GPS. ?>
<form method="post" action="<?= View::url('/admin/moje-przejazdy/garmin/import') ?>" data-import-progress>
    <?= Csrf::field() ?>
    <div class="track-list" style="margin-top:6px;">
        <?php foreach ($lista as $gItem): ?>
        <label class="track-list__row" style="cursor:pointer;">
            <span style="display:flex;align-items:center;gap:10px;min-width:0;">
                <input type="checkbox" name="aktywnosc[]" value="<?= htmlspecialchars((string) $gItem['id']) ?>" checked>
                <span style="min-width:0;">
                    <b><?= htmlspecialchars($gItem['name'] !== '' ? $gItem['name'] : __('Przejazd')) ?></b>
                    <span class="dash-sub"><?= htmlspecialchars(substr((string) $gItem['startedAt'], 0, 16)) ?></span>
                </span>
            </span>
            <span><?= htmlspecialchars(Format::distance((float) $gItem['distanceKm'])) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <div class="action-row">
        <button class="btn" type="submit"><?= __('Pobierz zaznaczone') ?></button>
        <span class="desc" style="margin:0;"><?= __('Ściągamy porcjami po {n} — przy dłuższej liście to jedno kliknięcie po prostu zajmie chwilę dłużej.', ['n' => \Models\GarminImport::BATCH]) ?></span>
    </div>
</form>
<?php endif; ?>
<?php endif; ?>
