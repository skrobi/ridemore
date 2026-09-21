<?php
// views/emails/pages/match-suggestion.php
// Etap 2 (dopasowania), krok 5 — zadanie nocne konsolidujące (Models\MatchEngine::runNightlyConsolidation()).
// Oczekuje: $recipientName (?string), $reason (string), $alignOn (?string), $eventLink (string).
// Świadomie w formie sugestii, nigdy stwierdzenia faktu (docs/etap2) — ten
// sam słownik komunikatów co widget na stronie wydarzenia i podpowiedzi w
// formularzu, MatchEngine::reasonMessage() jest jedynym źródłem treści $reason.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\MailTemplate;
?>
<p style="font-size:16px;"><?= MailTemplate::greeting($recipientName) ?></p>
<p style="font-size:15px;line-height:1.6;"><?= htmlspecialchars($reason) ?></p>
<?php if ($alignOn): ?>
<p style="font-size:14px;line-height:1.5;color:#6B6A64;"><?= __('Jedyne, co warto by uzgodnić:') ?> <b><?= htmlspecialchars($alignOn) ?></b>.</p>
<?php endif; ?>
<p style="margin:24px 0 6px;"><?= MailTemplate::button($eventLink, __('Zobacz wydarzenie →')) ?></p>
<p style="font-size:13px;line-height:1.5;color:#6B6A64;margin:0;"><?= __('To tylko podpowiedź — jeśli to nie dla Ciebie, po prostu ją zignoruj.') ?></p>
