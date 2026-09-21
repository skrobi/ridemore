<?php
// views/web/layout.php
// SKORUPA WERSJI WEBOWEJ. Od 2026-08-29 apka mobilna ma własną — patrz
// `layout-app.php` i wybór w `Utils\View::render()`.
// Kontrakt: tasks/done/apka-mobilna-skorupa.md.
//
// Dlaczego DWIE skorupy, a nie warunki w jednej: nagłówek web ma logo, sześć
// pozycji nawigacji, hamburger, ikonę wiadomości i rozwijane menu konta
// z ~20 pozycjami — a apka miała to WSZYSTKO na tym samym ekranie co własny
// pięciosłotowy pasek dolny, plus stopkę renderowaną do HTML-a i ukrywaną
// CSS-em. To nie była różnica widoczności, tylko różnica układu, i kosztowała
// cztery zapytania do bazy na każde żądanie z apki.
//
// Meta tagi, SEO, favicony i wspólne skrypty siedzą we WSPÓLNYM
// `partials/head.php` — dwie kopie <head> rozjechałyby się po cichu.
// Ten plik odpowiada wyłącznie za to, co jest w <body>.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

require __DIR__ . '/partials/head.php';
?>
<body<?= $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass) . '"' : '' ?>>
<div class="wrap">
<?php require __DIR__ . '/partials/header.php'; ?>
<?= $content ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
<?php if (APP_ENV === 'prod'): ?>
<!-- Google tag (gtag.js) — tylko prod, żeby ruch z dev/testów lokalnych nie zaśmiecał statystyk. -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-8DS0V2V5J6"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-8DS0V2V5J6');
</script>
<?php endif; ?>
</div>
</body>
</html>
