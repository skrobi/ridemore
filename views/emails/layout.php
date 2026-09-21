<?php
// views/emails/layout.php
// Oczekuje: $content (string, HTML wygenerowany przez views/emails/pages/*.php).
//
// $unsubscribeUrl (opcjonalny, Etap 1c programu zachęt 2026-09-11) — dokłada
// go `Models\Notifier` KAŻDEMU mailowi, który idzie przez most powiadomień,
// bo tylko most wie, która flaga zgody rządzi danym typem. Maile wysyłane
// wprost przez `Core\Mailer` (reset hasła, potwierdzenie wpłaty, zgłoszenie
// wydarzenia) go NIE mają i mieć nie powinny: nie ma tam z czego się wypisać,
// a przycisk sugerowałby, że jest — czyli obiecywałby funkcję, której nie ma.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#1A1A18;">
<?= $content ?>
    <p style="font-size:12px;color:#9C9A92;margin-top:32px;border-top:1px solid #E4E2DA;padding-top:16px;"><?= __('ridemore.bike — wydarzenia rowerowe, nie tylko trasy') ?></p>
<?php if (!empty($unsubscribeUrl)): ?>
    <p style="font-size:12px;color:#9C9A92;margin-top:8px;"><?= __('Nie chcesz takich wiadomości?') ?>

        <a href="<?= htmlspecialchars($unsubscribeUrl) ?>" style="color:#9C9A92;"><?= __('Wyłącz je jednym kliknięciem') ?></a> <?= __('—
        pozostałe powiadomienia zostaną bez zmian.') ?></p>
<?php endif; ?>
</div>
