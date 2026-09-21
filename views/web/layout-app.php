<?php
// views/web/layout-app.php
// SKORUPA APLIKACJI MOBILNEJ (Capacitor). Wybierana przez `Utils\View::render()`
// na podstawie APP_IS_APP — kontrolery, trasy i wywołania View::render()
// o jej istnieniu nie wiedzą i nie muszą.
// Kontrakt: tasks/done/apka-mobilna-skorupa.md.
//
// RÓŻNICE WOBEC `layout.php` (web) — wszystkie w UKŁADZIE, nie w danych:
//   1. `partials/app-header.php` zamiast `partials/header.php` — belka bez
//      nawigacji i bez menu konta, bo obie rzeczy ma dolny pasek i ekran
//      „Profil". Oszczędza dwa zapytania na każde żądanie.
//   2. `partials/app-nav.php` BEZ WARUNKU — w tej skorupie pasek jest zawsze.
//   3. STOPKI NIE MA W OGÓLE. Do 2026-08-29 renderowała się do HTML-a
//      (45 linii mapy serwisu) i była ukrywana regułą `body.is-app footer`.
//      Teraz po prostu nie wchodzi — mniej bajtów i o jedną łatkę CSS mniej.
//   4. Klasa `is-app` na <body> jest tu BEZWARUNKOWA, a nie doklejana `if`-em.
//
// CZEGO TU NIE MA CELOWO: własnych meta tagów, SEO i skryptów — te siedzą we
// WSPÓLNYM `partials/head.php`. Apka NIE dostaje własnego frontu; dostaje
// własną skorupę. To jest cała różnica i nie wolno jej rozszerzać po cichu.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

require __DIR__ . '/partials/head.php';

// Klasa nadawana PO head.php, bo to on ustala domyślne `$bodyClass` (np. pusty
// ciąg albo `map-page` z kontrolera). `trim(... . ' is-app')` dokłada, a nie
// nadpisuje — inaczej pełnoekranowa mapa straciłaby swój `map-page`.
$bodyClass = trim($bodyClass . ' is-app');
?>
<body class="<?= htmlspecialchars($bodyClass) ?>">
<div class="wrap">
<?php require __DIR__ . '/partials/app-header.php'; ?>
<?php // WSKAŹNIK BRAKU SIECI — zamiast dawnego ekranu-blokady (`errorPath`).
      // W skorupie, nie na podstronie: brak zasięgu dotyczy każdego ekranu,
      // a nie tylko mapy. Patrz tasks/active/apka-offline.md. ?>
<?php require __DIR__ . '/partials/app-offline.php'; ?>
<?= $content ?>
<?php require __DIR__ . '/partials/app-nav.php'; ?>
<?php if (APP_ENV === 'prod'): ?>
<!-- Google tag (gtag.js) — tylko prod. Apka jedzie TĄ SAMĄ miarką co web:
     to ten sam serwis pod tym samym adresem, więc rozdzielenie statystyk
     byłoby zmianą pomiaru przy okazji refaktoru, a nie decyzją. -->
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
