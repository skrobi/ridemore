<?php
// views/emails/pages/custom.php
// UNIWERSALNA TREŚĆ MAILA Z PANELU (2026-09-11, migr. 084).
// Oczekuje: $bodyHtml (string) — gotowy, JUŻ OCZYSZCZONY HTML.
//
// Ten szablon celowo nie ma żadnej logiki i żadnej treści własnej. Cały
// materiał składa `Models\NotificationTexts::render()`: podstawia znaczniki
// (escape'owane) i bloki (gotowy HTML z kodu), a `oczyscHtml()` przepuszcza
// tylko znaczniki formatujące. Gdyby ten plik cokolwiek dokładał albo
// warunkował, ta sama decyzja istniałaby w dwóch miejscach — dokładnie ten
// błąd naprawiliśmy w `treasure-nearby.php`, przenosząc wybór wariantu
// ujawnienia z widoku do kodu.
//
// `$bodyHtml` NIE przechodzi przez htmlspecialchars i to jest zamierzone:
// jest HTML-em z definicji. Bezpieczeństwo stoi na białej liście przy zapisie
// i przy renderowaniu, nie tutaj.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div style="font-size:15px;line-height:1.6;">
<?= $bodyHtml ?>
</div>
