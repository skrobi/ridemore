<?php
// views/emails/pages/payment-reminder.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $eventDateLabel (?string),
// $amountLabel (string), $depositAmountLabel (?string, gdy event ma zaliczkę),
// $deadlineDateLabel (string), $bankAccount (string), $bankOwnerName (string),
// $eventLink (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
$hasDeposit = !empty($depositAmountLabel);
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __($hasDeposit
    ? 'Twoje miejsce na wydarzeniu {tytul}{data} zostało {wstepnie}. Żeby je potwierdzić, opłać zaliczkę do {termin}.'
    : 'Twoje miejsce na wydarzeniu {tytul}{data} zostało {wstepnie}. Żeby je potwierdzić, opłać rezerwację do {termin}.', [
    'tytul'    => '<b>' . htmlspecialchars($eventTitle) . '</b>',
    'data'     => $eventDateLabel ? ' (' . htmlspecialchars($eventDateLabel) . ')' : '',
    'wstepnie' => '<b>' . __('wstępnie zarezerwowane') . '</b>',
    'termin'   => '<b>' . htmlspecialchars($deadlineDateLabel) . '</b>',
]) ?></p>
<p style="font-size:14px;line-height:1.8;background:#F7F5EF;border-left:3px solid #D14E1E;padding:12px 16px;border-radius:0 8px 8px 0;">
    <?= __('Odbiorca:') ?> <b><?= htmlspecialchars($bankOwnerName) ?></b><br>
    <?= __('Nr konta:') ?> <b><?= htmlspecialchars($bankAccount) ?></b><br>
    <?php if ($hasDeposit): ?>
    <?= __('Zaliczka do wpłaty teraz:') ?> <b><?= htmlspecialchars($depositAmountLabel) ?></b><br>
    <?= __('Cena całkowita:') ?> <b><?= htmlspecialchars($amountLabel) ?></b>
    <?php else: ?>
    <?= __('Kwota:') ?> <b><?= htmlspecialchars($amountLabel) ?></b>
    <?php endif; ?>
</p>
<?php if ($hasDeposit): ?>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0 0 10px;"><?= __('Pozostałą część ceny dopłacasz zgodnie z zasadami płatności ustalonymi przez organizatora tego wydarzenia.') ?></p>
<?php endif; ?>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0 0 20px;"><?= __('Miejsce liczy się jako zajęte dopiero po ręcznym potwierdzeniu wpłaty przez organizatora.') ?></p>
<p style="margin:0 0 6px;"><?= MailTemplate::button($eventLink, __('Zobacz wydarzenie →'), false) ?></p>
