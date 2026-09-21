<?php
// views/emails/pages/review-posted.php
// Do organizatora, gdy uczestnik wystawi opinię. Oczekuje: $recipientName
// (?string), $reviewerName (string), $eventTitle (string), $rating (int 1-5),
// $comment (?string), $link (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><b><?= htmlspecialchars($reviewerName) ?></b> <?= __('wystawił(a) Ci opinię (') ?><b><?= (int) $rating ?>/5</b><?= __(') za wydarzenie') ?> <b><?= htmlspecialchars($eventTitle) ?></b>.</p>
<?php if ($comment): ?>
<p style="font-size:14px;line-height:1.6;background:#F7F5EF;border-left:3px solid #D14E1E;padding:10px 14px;border-radius:0 8px 8px 0;"><?= nl2br(htmlspecialchars($comment)) ?></p>
<?php endif; ?>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($link, __('Zobacz opinię →')) ?></p>
