<?php
// views/web/partials/app-nav.php
// DOLNY PASEK NAWIGACJI APLIKACJI MOBILNEJ (Etap 1, tasks/active/apka-mobilna.md).
//
// Renderowany WYŁĄCZNIE gdy APP_IS_APP — w przeglądarce nawigacją zostaje
// nagłówek. Dzięki temu każdy przycisk tutaj może zakładać, że most natywny
// jest dostępny (przycisk skanowania nie ma sensownego odpowiednika na www).
//
// JEDEN partial dla całego serwisu, nie kopia per strona: pasek jest częścią
// layoutu, a nie treści, więc nowa podstrona dostaje go bez żadnej zmiany.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$currentUser = Core\Auth::user();

// Podświetlenie aktywnej zakładki liczone tak samo jak w header.php — po
// ścieżce bez base_path, bo dev siedzi pod /ridemore, a prod w korzeniu.
$basePath    = rtrim(APP_CONFIG['base_path'] ?? '', '/');
$currentPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$is = static fn(string $path): bool => $currentPath === $basePath . $path
    || str_starts_with($currentPath, $basePath . $path . '/');

// Profil rowerzysty dla zalogowanego, logowanie dla gościa — ta sama zasada,
// co przy „Mój profil rowerzysty" w menu konta.
//
// PUSTY SLUG MUSI MIEĆ WŁASNE WYJŚCIE (naprawione 2026-08-29 po zgłoszeniu
// usera: „kliknąłem na profil i mam komunikat »Brak połączenia«").
// `User::publicSlug` jest NULLOWALNE — nadaje się przy potwierdzeniu
// rejestracji i przy zmianie nazwy, więc konto, które tamtędy nie przeszło
// (m.in. logowanie społecznościowe, konta sprzed backfillu migr. 038), go
// nie ma. Bez tego warunku adres sklejał się do `/rowerzysta/`, czyli 404 —
// a w apce WebView zamienia błąd wczytania strony na ekran „Brak połączenia"
// (`errorPath` w capacitor.config.ts), więc user dostawał komunikat
// o zerwanej sieci przy działającym internecie.
//
// `header.php` (web) ma ten warunek od zawsze — po prostu ukrywa pozycję.
// Tutaj ukryć się nie da: to jeden z pięciu stałych slotów paska, a od
// przeniesienia menu konta na ekran profilu (2026-08-29) prowadzi TAKŻE do
// ustawień i wylogowania. Slug-less user bez tego wyjścia nie miałby jak
// się wylogować. Stąd `/admin/moje-konto` — ta sama sekcja `web`, więc
// dostaje skorupę apki razem z paskiem.
$profileUrl = '/logowanie';
if ($currentUser) {
    $profileUrl = $currentUser->publicSlug
        ? '/rowerzysta/' . $currentUser->publicSlug
        : '/admin/moje-konto';
}
$profileLabel = $currentUser ? __('Profil') : __('Zaloguj');
?>
<nav class="app-nav" aria-label="<?= htmlspecialchars(__('Nawigacja aplikacji')) ?>">
    <a href="<?= Utils\View::url('/wydarzenia') ?>" class="app-nav__i<?= $is('/wydarzenia') ? ' is-on' : '' ?>">
        <span class="app-nav__ico" aria-hidden="true"><?= Utils\Icon::render('calendar') ?></span>
        <span><?= __('Wyjazdy') ?></span>
    </a>
    <a href="<?= Utils\View::url('/skarby/zglos') ?>" class="app-nav__i<?= $is('/skarby') ? ' is-on' : '' ?>">
        <span class="app-nav__ico" aria-hidden="true"><?= Utils\Icon::render('pin-add') ?></span>
        <span><?= __('Zgłoś') ?></span>
    </a>

    <!-- MAPA JEST ŚRODKIEM APKI (Etap 1 przebudowy, 2026-08-28: „mapa powinna
         być centralnym przyciskiem tym na środku") — dostaje wyniesiony okrąg,
         który wcześniej miało skanowanie. To wciąż zwykły link (/odkrycia w
         trybie apki renderuje pełnoekranową discovery-app.php), nie akcja.

         2026-08-29: slot jest teraz ZWYKŁYM `.app-nav__i` z podpisem jak
         wszystkie pozostałe, a wyniesiony okrąg zszedł do wewnętrznego
         `.app-nav__ring`. Do tej pory jako jedyny nie miał podpisu (miał
         `aria-label`, czyli tekst dla czytnika, ale nie dla oka) — stąd
         „podpisy też są różne" w zgłoszeniu. Okrąg zostaje, bo to on robi
         „centralny przycisk". -->
    <?php // JUŻ NA MAPIE = NIE PRZEŁADOWUJ JEJ (2026-08-30, zgłoszenie usera:
          // „jak już jestem na mapie i kliknę raz jeszcze, to przeładowuje
          // stronę"). Apka chodzi na `server.url`, więc link do adresu,
          // na którym już jesteś, to pełna podróż do serwera i budowa mapy
          // od zera — łącznie z ponownym czekaniem na kadr. A skoro slot i tak
          // jest wtedy podświetlony jako aktywny, to dotknięcie go nie znaczy
          // „zabierz mnie na mapę" (jestem na niej), tylko „pokaż mi MNIE".
          // Stąd na tej jednej stronie ten sam slot jest przyciskiem
          // „wyśrodkuj na mojej pozycji" — obsługa w discovery-app.php.
          // Wzorzec jak przy skanowaniu niżej: akcja to `<button>`, nie link. ?>
    <?php if ($is('/odkrycia')): ?>
    <button type="button" class="app-nav__i app-nav__i--btn app-nav__hero is-on" data-rm-map-here
            aria-label="<?= htmlspecialchars(__('Wyśrodkuj mapę na mojej pozycji')) ?>">
        <span class="app-nav__ring" aria-hidden="true"><?= Utils\Icon::render('map') ?></span>
        <span><?= __('Mapa') ?></span>
    </button>
    <?php else: ?>
    <a href="<?= Utils\View::url('/odkrycia') ?>" class="app-nav__i app-nav__hero">
        <span class="app-nav__ring" aria-hidden="true"><?= Utils\Icon::render('map') ?></span>
        <span><?= __('Mapa') ?></span>
    </a>
    <?php endif; ?>

    <!-- SKANOWANIE JEST AKCJĄ, NIE MIEJSCEM, ale zwykłym slotem paska — środek
         należy teraz do Mapy. Obsługę podpina assets/js/native.js. -->
    <button type="button" class="app-nav__i app-nav__i--btn" data-rm-scan aria-label="<?= htmlspecialchars(__('Skanuj kod skarbu')) ?>">
        <span class="app-nav__ico" aria-hidden="true"><?= Utils\Icon::render('scan') ?></span>
        <span><?= __('Skanuj') ?></span>
    </button>

    <a href="<?= Utils\View::url($profileUrl) ?>" class="app-nav__i<?= $is('/rowerzysta') || $is('/logowanie') ? ' is-on' : '' ?>">
        <span class="app-nav__ico" aria-hidden="true"><?= Utils\Icon::render('user') ?></span>
        <span><?= $profileLabel ?></span>
    </a>
</nav>
