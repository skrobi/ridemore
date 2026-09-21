<?php
// views/emails/pages/partial-payment-confirmed.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $amountNowLabel (string),
// $totalPaidLabel (string), $remainingLabel (string), $amountLabel (string, pełna cena),
// $eventLink (string), $discussionLink (string).
// Wysyłane gdy organizator potwierdza wpłatę, która NIE rozlicza sprawy w
// całości (patrz EventRsvp::confirmPayment(), $result['isFullySettled']===false)
// — może to być zaliczka, więcej niż zaliczka, albo kolejna z kilku rat.
// Świadomie NIE mówi "miejsce jest Twoje", bo reszta ceny wciąż nierozliczona.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Twoja wpłata za wydarzenie') ?> <b><?= htmlspecialchars($eventTitle) ?></b> (<b><?= htmlspecialchars($amountNowLabel) ?></b><?= __(') została potwierdzona przez organizatora.') ?></p>
<p style="font-size:14px;line-height:1.8;background:#F7F5EF;border-left:3px solid #D14E1E;padding:12px 16px;border-radius:0 8px 8px 0;">
    <?= __('Wpłacono łącznie:') ?> <b><?= htmlspecialchars($totalPaidLabel) ?></b> z <b><?= htmlspecialchars($amountLabel) ?></b><br>
    <?= __('Pozostało do wpłaty:') ?> <b><?= htmlspecialchars($remainingLabel) ?></b>
</p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0 0 10px;"><?= __('Pozostałą część ceny dopłacasz zgodnie z zasadami płatności ustalonymi przez organizatora tego wydarzenia. Miejsce liczy się jako w pełni potwierdzone dopiero po rozliczeniu całej kwoty.') ?></p>
<p style="margin:0 0 6px;"><?= MailTemplate::button($eventLink, __('Zobacz wydarzenie →'), false) ?></p>
