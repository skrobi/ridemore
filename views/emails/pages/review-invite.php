<?php
// views/emails/pages/review-invite.php
// Oczekuje: $recipientName (?string), $eventTitle (string), $reviewLink (string),
// $recapLink (string), $attendanceLink (string).
//
// $attendanceLink prowadzi na STRONĘ wydarzenia (kotwica #bylem), a nie wprost
// do akcji potwierdzenia — potwierdzenie idzie wyłącznie POST-em. Klienty
// pocztowe i skanery antyspamowe klikają linki w tle, więc link GET zmieniający
// stan potwierdzałby obecność bez udziału człowieka.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Wydarzenie') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('dobiegło końca — mamy nadzieję, że było udane!') ?></p>
<?php if (!empty($attendanceLink)): ?>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($attendanceLink, __('Potwierdź, że byłeś →')) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0 0 20px;"><?= __('Jedno kliknięcie. Dzięki temu trafisz do składu tego wyjazdu — a my wiemy, kto z kim naprawdę jeździ, zamiast zgadywać po zapisach.') ?></p>
<?php endif; ?>
<p style="margin:0 0 6px;"><?= MailTemplate::button($reviewLink, __('Wystaw opinię →'), false) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0 0 20px;"><?= __('Oceń organizatora i podziel się, jak Ci poszło — pomożesz innym rowerzystom zdecydować.') ?></p>
<p style="margin:0 0 6px;"><?= MailTemplate::button($recapLink, __('Dodaj relację z wyjazdu →'), false) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0;"><?= __('Masz zdjęcia, tekst albo film z trasy? Dodaj relację — pojawi się na stronie wydarzenia razem z galerią zdjęć.') ?></p>
