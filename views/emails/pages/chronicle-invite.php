<?php
// views/emails/pages/chronicle-invite.php
// Oczekuje: $inviterName (string), $eventTitle (string), $chronicleLink (string),
// $registerLink (string).
//
// Zaproszenie z REALNYM ładunkiem: nie „zajrzyj na fajny serwis", tylko
// „byłeś na tym wyjeździe, tu jest jego kronika". Dlatego wysłać je może
// wyłącznie ktoś, kto sam był na tym samym turnusie.
//
// Mail NIE twierdzi, że ktoś już został dopisany do składu — bo nie został.
// Obecność zaznacza sobie sam zaproszony po założeniu konta; inaczej liczby
// w całym serwisie przestałyby cokolwiek znaczyć.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting(null) ?></p>
<p style="font-size:15px;line-height:1.6;"><b><?= htmlspecialchars($inviterName) ?></b> <?= __('oznaczył Cię jako uczestnika wyjazdu') ?> <b><?= htmlspecialchars($eventTitle) ?></b> <?= __('na ridemore.bike.') ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Powstała z niego kronika: kto pojechał, którędy prowadziła trasa i co uczestnicy po sobie zostawili.') ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($chronicleLink, __('Zobacz kronikę wyjazdu →')) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0 0 20px;"><?= __('Jeśli faktycznie tam byłeś, załóż konto i potwierdź swój udział — trafisz do składu tego wyjazdu, a osoby, z którymi jechałeś, pojawią się w Twoim peletonie.') ?></p>
<p style="margin:0 0 6px;"><?= MailTemplate::button($registerLink, __('Załóż konto →'), false) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0;"><?= __('Nie kojarzysz tego wyjazdu? Zignoruj tę wiadomość — nic nie zostało zapisane na Twoje nazwisko.') ?></p>
