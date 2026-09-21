<?php
// views/emails/pages/treasure-nearby.php
// Etap 1c programu zachęt (2026-09-11) — mailowa połowa powiadomienia
// „nowy skarb w Twojej okolicy" (Models\PushNotifier::runTreasuresNearby).
// Oczekuje: $recipientName (?string), $lead (string), $mapLink (string).
//
// $lead PRZYCHODZI GOTOWY z `Models\NotificationTexts` (migr. 084) — szablon
// nie składa go z kawałków i NIE WIE o poziomie ujawnienia skarbu. To jest
// celowe: wybór wariantu („jawny"/„ukryty") należy do kodu, który zna skarb,
// a nie do widoku. Gdyby warunek stał tutaj, ta sama reguła istniałaby
// w dwóch miejscach — a wystarczy, że rozjadą się raz.
//
// Tekst jest escape'owany jak każdy inny: treści z panelu są zwykłym tekstem,
// nigdy HTML-em (patrz nota w NotificationTexts).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= htmlspecialchars($lead) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= __('Dokładne miejsce odsłoni się na mapie — tak samo jak przy pozostałych skarbach.') ?></p>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($mapLink, __('Zobacz na mapie →')) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0;"><?= __('Piszemy o tym raz. Jeśli akurat nie po drodze — nie wrócimy z tym samym skarbem.') ?></p>
