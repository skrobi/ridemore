<?php
// views/web/pages/event-participants.php
// Oczekuje: $event (Models\Event), $rows (EventRsvp::forEvent()), $isPaidEvent (bool),
// $isCompleted (bool), $attendanceMap (rsvp_id => ['attended'=>bool,'byOrganizer'=>bool]).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

// Obecność ("Byłem") — tylko po zakończeniu wyjazdu i tylko dla osób, które
// faktycznie miały potwierdzony zapis. Brak wpisu w mapie = nikt jeszcze nie
// odpowiedział (co NIE znaczy "nie było go" — patrz Models\EventAttendance).
$isCompleted = $isCompleted ?? false;
$attendanceMap = $attendanceMap ?? [];
$showAttendance = $isCompleted;

$statusLabels = [
    'potwierdzony'       => __('Potwierdzone'),
    'oczekuje_platnosci' => __('Oczekuje płatności'),
    'oczekuje_doplaty'   => __('Oczekuje dopłaty'),
    'oczekuje_zwrotu'    => __('Oczekuje zwrotu'),
    'lista_rezerwowa'    => __('Lista rezerwowa'),
    'anulowany'          => __('Anulowany'),
    'zainteresowany'     => __('Zainteresowany'),
];
$badgeClass = fn(string $code) => match ($code) {
    'potwierdzony' => 'badge-paid',
    'oczekuje_platnosci', 'oczekuje_doplaty', 'oczekuje_zwrotu' => 'badge-pending-payment',
    'zainteresowany' => 'badge-watching',
    default => 'badge-free',
};
$hasDeposit = $isPaidEvent && $event->pricing->depositAmount !== null && $event->pricing->depositAmount > 0;
$currencySymbol = $isPaidEvent ? Format::currencySymbol($event->pricing->currencyCode) : '';

// Podpowiedź kwoty w polu formularza — pierwsza wpłata sugeruje zaliczkę
// (jeśli skonfigurowana), każda kolejna sugeruje dokładnie to, co zostało
// do zapłaty. Organizator może to nadpisać dowolną faktycznie otrzymaną kwotą.
$suggestedAmount = function (array $row) use ($event, $hasDeposit) {
    $remaining = $event->pricing->amount - (float) $row['amount_paid'];
    if ($hasDeposit && (float) $row['amount_paid'] === 0.0) {
        return min($event->pricing->depositAmount, $remaining);
    }
    return max(0, $remaining);
};
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display" style="font-size:28px;"><?= __('Uczestnicy') ?></h1>
<p class="subline"><?= htmlspecialchars($event->title) ?></p>

<?php if (empty($rows)): ?>
<p class="desc" style="margin-top:16px;"><?= __('Nikt się jeszcze nie zapisał.') ?></p>
<?php else: ?>
<details class="bulk-message-box">
    <summary class="link-button"><?= __('Napisz do grupy (wszyscy uczestnicy) →') ?></summary>
    <form method="post" action="<?= View::url('/wydarzenia/' . $event->slug . '/uczestnicy/wyslij-wiadomosc') ?>">
        <?= Core\Csrf::field() ?>
        <textarea name="body" required placeholder="<?= htmlspecialchars(__('Treść trafi do dyskusji grupy — jedno miejsce, widoczne dla wszystkich zapisanych (i mailem)…')) ?>" rows="3"></textarea>
        <button type="submit" class="btn btn-secondary"><?= __('Wyślij do grupy') ?></button>
    </form>
</details>
<div class="dash-table" style="margin-top:20px;">
    <div class="dash-row dash-head">
        <div class="dash-cell"><?= __('Uczestnik') ?></div>
        <div class="dash-cell"><?= __('Termin') ?></div>
        <div class="dash-cell"><?= __('Status') ?></div>
        <?php if ($isPaidEvent): ?>
        <div class="dash-cell"><?= __('Wpłacono') ?></div>
        <div class="dash-cell"><?= __('Kwota wymagana') ?></div>
        <?php endif; ?>
        <div class="dash-cell"><?= __('Zapisano') ?></div>
        <?php if ($showAttendance): ?>
        <div class="dash-cell"><?= __('Obecność') ?></div>
        <?php endif; ?>
        <div class="dash-cell"><?= __('Akcje') ?></div>
    </div>
    <?php foreach ($rows as $row): ?>
    <div class="dash-row">
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Uczestnik')) ?>">
            <?= htmlspecialchars($row['name'] ?: $row['email']) ?>
            <div class="dash-sub"><?= htmlspecialchars($row['email']) ?></div>
        </div>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Termin')) ?>"><?= htmlspecialchars(Format::dateShort($row['edition_start_date'])) ?></div>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Status')) ?>">
            <span class="badge <?= $badgeClass($row['status_code']) ?>"><?= htmlspecialchars($statusLabels[$row['status_code']] ?? $row['status_code']) ?></span>
        </div>
        <?php if ($isPaidEvent): ?>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Wpłacono')) ?>">
            <?= htmlspecialchars(Format::price((float) $row['amount_paid'], $currencySymbol)) ?>
            <?php if ((float) $row['amount_paid'] !== 0.0): ?>
            <div class="dash-sub"><a href="<?= View::url('/wydarzenia/' . $event->slug . '/uczestnicy/' . $row['user_id'] . '/wplaty') . '?edition_id=' . $row['edition_id'] ?>"><?= __('Historia wpłat →') ?></a></div>
            <?php endif; ?>
        </div>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Kwota wymagana')) ?>"><?= htmlspecialchars(Format::price($event->pricing->amount, $currencySymbol)) ?></div>
        <?php endif; ?>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Zapisano')) ?>"><?= htmlspecialchars(Format::dateShort($row['joined_at'])) ?></div>
        <?php if ($showAttendance): ?>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Obecność')) ?>">
            <?php if ($row['status_code'] !== 'potwierdzony'): ?>
            —
            <?php else: ?>
            <?php $att = $attendanceMap[(int) $row['rsvp_id']] ?? null; ?>
            <?php if ($att === null): ?>
            <span class="badge badge-watching"><?= __('Brak odpowiedzi') ?></span>
            <?php elseif ($att['attended']): ?>
            <span class="badge badge-paid"><?= __('Był') ?></span>
            <?php else: ?>
            <span class="badge badge-free"><?= __('Nie dojechał') ?></span>
            <?php endif; ?>
            <div class="dash-sub">
                <?php
                    // Dwa przyciski zawsze widoczne (nie tylko brakujący stan) —
                    // organizator musi móc poprawić pomyłkę, także własną.
                    // Potwierdzenie przez organizatora jest mocniejsze niż
                    // deklaracja uczestnika, patrz EventAttendance::declare().
                    $attAction = View::url('/wydarzenia/' . $event->slug . '/uczestnicy/' . $row['user_id'] . '/obecnosc');
                ?>
                <?php if ($att === null || !$att['attended']): ?>
                <form method="post" action="<?= $attAction ?>">
                    <?= Core\Csrf::field() ?>
                    <input type="hidden" name="edition_id" value="<?= $row['edition_id'] ?>">
                    <input type="hidden" name="attended" value="1">
                    <button type="submit" class="link-button"><?= __('Był') ?></button>
                </form>
                <?php endif; ?>
                <?php if ($att === null || $att['attended']): ?>
                <form method="post" action="<?= $attAction ?>">
                    <?= Core\Csrf::field() ?>
                    <input type="hidden" name="edition_id" value="<?= $row['edition_id'] ?>">
                    <input type="hidden" name="attended" value="0">
                    <button type="submit" class="link-button"><?= __('Nie dojechał') ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="dash-cell dash-cell-actions" data-label="<?= htmlspecialchars(__('Akcje')) ?>">
            <?php if ($row['status_code'] !== 'anulowany'): ?>
            <a class="link-button" href="<?= View::url('/wiadomosci/z/' . $row['user_id']) ?>"><?= __('Napisz') ?></a>
            <?php endif; ?>
            <?php if ($isPaidEvent && in_array($row['status_code'], ['oczekuje_platnosci', 'oczekuje_doplaty'], true)): ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $event->slug . '/uczestnicy/' . $row['user_id'] . '/potwierdz-platnosc') ?>" class="inline-amount-form">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= $row['edition_id'] ?>">
                <input type="number" step="0.01" min="0.01" name="kwota" value="<?= htmlspecialchars((string) $suggestedAmount($row)) ?>" style="width:80px;">
                <button type="submit" class="link-button"><?= __('Potwierdź wpłatę') ?></button>
            </form>
            <?php endif; ?>
            <?php if ($isPaidEvent && $row['status_code'] === 'oczekuje_zwrotu'): ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $event->slug . '/uczestnicy/' . $row['user_id'] . '/potwierdz-zwrot') ?>">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= $row['edition_id'] ?>">
                <button type="submit" class="link-button"><?= __('Potwierdź zwrot') ?></button>
            </form>
            <?php endif; ?>
            <?php if (in_array($row['status_code'], ['potwierdzony', 'oczekuje_platnosci', 'oczekuje_doplaty', 'lista_rezerwowa'], true)): ?>
            <!-- Na wniosek uczestnika zgłoszony poza platformą (telefon, mail) — patrz
                 EventRsvp::requestCancellation(); dostępne też dla darmowych wydarzeń
                 (tam 'potwierdzony' anuluje się od razu, bez etapu zwrotu — nic nie wpłacone). -->
            <form method="post" action="<?= View::url('/wydarzenia/' . $event->slug . '/uczestnicy/' . $row['user_id'] . '/anuluj') ?>" onsubmit="return confirm(__('Anulować udział tego uczestnika na jego wniosek?'));">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= $row['edition_id'] ?>">
                <button type="submit" class="link-button"><?= __('Anuluj udział') ?></button>
            </form>
            <?php endif; ?>
            <?php if (in_array($row['status_code'], ['anulowany', 'zainteresowany'], true)): ?>
            —
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
