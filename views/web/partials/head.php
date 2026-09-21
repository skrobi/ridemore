<?php
// views/web/partials/head.php
// WSPÓLNA GŁOWA DOKUMENTU dla obu skorup serwisu: `layout.php` (web)
// i `layout-app.php` (apka mobilna). Kontrakt: tasks/done/apka-mobilna-skorupa.md.
//
// DLACZEGO OSOBNY PLIK, SKORO TO „TYLKO" <head>: rozdzielenie skorup ma sens
// wyłącznie wtedy, gdy meta tagi, SEO, OG, favicony i wspólne skrypty mają
// JEDNO źródło. Dwie kopie <head> rozjadą się przy pierwszej zmianie
// description albo przy dołożeniu skryptu — i rozjadą się CICHO, bo web
// wygląda wtedy dalej poprawnie, a apka traci coś, czego nikt nie ogląda
// w źródle. To jest jedyne realne ryzyko całej tej przebudowy.
//
// CO TEN PLIK USTAWIA POZA WYPISANIEM HTML-a: domyślne wartości zmiennych
// widoku ($description, $canonical, $ogImage, $ogType, $jsonLd, $pageTitle,
// $extraHead, $bodyClass). Skorupa, która go dołącza, MOŻE na nich polegać
// przy składaniu <body> — i obie to robią. Klasy `is-app` tu NIE MA
// świadomie: o niej decyduje wybór skorupy, nie zawartość głowy.
//
// WARUNKI APP_IS_APP, KTÓRE ZOSTAJĄ W TYM PLIKU, są o URZĄDZENIU, nie
// o układzie strony — dlatego nie przenoszą się do layout-app.php:
//   - `viewport-fit=cover` (wycięcie ekranu i pasek gestów),
//   - globalne RM_* + app-tracking.js (nagrywanie w tle działa na KAŻDYM
//     ekranie apki, więc mieszka w głowie, a nie na podstronie).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

// Strony, które tego potrzebują (np. formularz eventu — mapa Leaflet), mogą
// dorzucić własne <link>/<script> bez zaśmiecania reszty serwisu.
$extraHead = $extraHead ?? '';

// TŁUMACZENIE TREŚCI A INDEKSOWANIE (wielojęzyczność, 2026-09-16). Wersja
// „pokaż oryginał" to ta sama strona pod innym adresem, a strona, na której
// tłumaczenie było potrzebne i się nie udało, ma treść w złym języku — żadnej
// z nich wyszukiwarka nie powinna brać. Głowa renderuje się PO treści strony
// (View::render buforuje widok najpierw), więc flaga jest już ustawiona.
if (Models\ContentTranslation::showOriginal() || Models\ContentTranslation::failed()) {
    $noindex = true;
}

// SEO — meta framework. Każda trasa MOŻE nadpisać $description/$canonical/
// $ogImage/$ogType/$jsonLd/$noindex przez dane przekazane do View::render();
// bez nadpisania dostaje rozsądne wartości domyślne (serwis nadal ma pełne
// meta tagi, tylko mniej trafne niż per-strona), więc żadna strona nie
// zostaje bez description/canonical/OG jak dotąd.
$description = $description ?? __('Znajdź i dołącz do wyjazdu rowerowego w Polsce i na Świecie — wyprawy gravelowe, MTB i szosowe, organizowane przez lokalne grupy i operatorów turystycznych.');
$canonical   = $canonical ?? Utils\View::currentCanonicalUrl();
$ogImage     = $ogImage ?? Utils\View::absoluteUrl('/assets/logo/android-chrome-512x512.png');
$ogType      = $ogType ?? 'website';
$jsonLd      = $jsonLd ?? '';
$pageTitle   = $title ?? 'ridemore.bike';
// Opcjonalna klasa na <body> — dziś event-page.php (pasek mobilny .mbar
// potrzebuje miejsca na dole ekranu) i ekrany pełnoekranowej mapy w apce;
// bez tego KAŻDA strona dostałaby niepotrzebny margines na mobile.
$bodyClass   = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Core\Lang::current()) ?>">
<head>
<meta charset="UTF-8">
<!-- Serwis ma WYŁĄCZNIE jasny motyw — brak arkusza/zmiennych na ciemny.
     Bez tej deklaracji telefony z wymuszonym ciemnym motywem (Chrome/Android
     „Automatycznie przyciemniaj strony internetowe", Samsung Internet) same
     przemalowują stronę swoim algorytmem, co na gradientach/hexach/zdjęciach
     wygląda źle (zgłoszenie: „wersja ciemna strasznie kiepska" — myląca nazwa,
     to nie NASZ dark mode, bo takiego nie ma, tylko przeglądarka zgaduje kolory
     za nas). `color-scheme:light` w style.css robi to samo dla samego CSS
     (np. natywne kontrolki formularza), meta tag mówi to samo silnikowi
     przeglądarki zanim CSS się w ogóle wczyta. -->
<meta name="color-scheme" content="light">
<!-- viewport-fit=cover TYLKO w aplikacji: pozwala treści wejść pod wycięcie
     ekranu i pasek gestów, ale dopiero wtedy przeglądarka wystawia wartości
     env(safe-area-inset-*), którymi style odsuwają od nich dolny pasek.
     W zwykłej przeglądarce ten atrybut nic nie daje, a na iOS Safari zmienia
     zachowanie pasków — stąd warunek zamiast wpisania go wszystkim. -->
<meta name="viewport" content="width=device-width, initial-scale=1.0<?= APP_IS_APP ? ', viewport-fit=cover' : '' ?>">

<!-- Domeny trzecich stron ładowane synchronicznie niżej (Google Fonts, Alpine.js
     z jsdelivr) — preconnect skraca ścieżkę krytyczną o czas DNS+TLS handshake,
     zanim przeglądarka w ogóle dotrze do właściwych <link>/<script>. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net">

<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($description) ?>">
<link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
<?php if (!empty($noindex)): ?>
<meta name="robots" content="noindex,follow">
<?php endif; ?>
<meta property="og:site_name" content="ridemore.bike">
<meta property="og:type" content="<?= htmlspecialchars($ogType) ?>">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($description) ?>">
<meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
<meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
<?php
// WERSJE JĘZYKOWE (2026-09-16, tasks/active/wielojezycznosc.md). Wypisywane
// wyłącznie przy więcej niż jednym włączonym języku — z flagą ['pl'] głowa
// strony jest bajt w bajt taka jak przed wielojęzycznością.
//
// hreflang wg zasad Google: każda wersja wskazuje WSZYSTKIE, łącznie z sobą,
// plus x-default. Budowane z $canonical, a nie z bieżącego adresu, bo strony
// z filtrami nadpisują canonical na wersję bez parametrów — alternatywy
// muszą wskazywać te same adresy, inaczej Google uzna je za niespójne.
// Strony z noindex (konto, formularze) nie mają czego ogłaszać.
$rmLangAlternates = count(Core\Lang::supported()) > 1 ? Core\Lang::alternates($canonical) : [];
if ($rmLangAlternates && empty($noindex)):
    foreach ($rmLangAlternates as $rmAltLang => $rmAltUrl): ?>
<link rel="alternate" hreflang="<?= htmlspecialchars($rmAltLang) ?>" href="<?= htmlspecialchars($rmAltUrl) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($rmLangAlternates[Core\Lang::xDefault()]) ?>">
<?php endif;
if ($rmLangAlternates): ?>
<meta property="og:locale" content="<?= htmlspecialchars(Core\Lang::locale()) ?>">
<?php foreach (array_keys($rmLangAlternates) as $rmAltLang): if ($rmAltLang !== Core\Lang::current()): ?>
<meta property="og:locale:alternate" content="<?= htmlspecialchars(Core\Lang::locale($rmAltLang)) ?>">
<?php endif; endforeach; endif; ?>
<!-- Zwykłe ikony karty/zakładki — świadomie BEZ manifest.json/service workera,
     żeby nie wracać do stanu sprzed czyszczenia starej aplikacji One Page
     (patrz sw.js/service-worker.js w katalogu głównym). -->
<link rel="icon" type="image/png" sizes="32x32" href="<?= Utils\View::asset('/assets/logo/favicon-32x32.png') ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= Utils\View::asset('/assets/logo/favicon-16x16.png') ?>">
<link rel="apple-touch-icon" href="<?= Utils\View::asset('/assets/logo/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="<?= Utils\View::asset('/assets/css/style.css') ?>">
<?php if (APP_IS_APP): ?>
<!-- Style wyłącznie aplikacji mobilnej (Faza 3, tasks/done/apka-mobilna-skorupa.md).
     DODATKOWY arkusz, nie zamiennik: apka używa wspólnych komponentów ze
     style.css (.disc-panel, .mbar, .filters, .messenger), więc oba muszą być.
     KOLEJNOŚĆ JEST WYMOGIEM — app.css nadpisuje komponenty wspólne, więc musi
     stać PO style.css, nigdy przed.

     Wpięte tutaj, a nie w layout-app.php, z jednego powodu: `<head>` domyka
     ten plik, więc jest jedynym miejscem, z którego da się dołożyć <link>.
     To wyjątek od podziału „głowa wspólna, ciało osobne", nie jego złamanie. -->
<link rel="stylesheet" href="<?= Utils\View::asset('/assets/css/app.css') ?>">
<?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&family=Big+Shoulders+Display:wght@700;800;900&family=Instrument+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<!-- SŁOWNIK DLA JAVASCRIPTU — PRZED wszystkimi skryptami, bo skrypty wklejone
     w widokach wykonują się wcześniej niż te z `defer`. `__()` w JS działa jak
     w PHP: kluczem jest polski tekst, brak wpisu = polski tekst. Po polsku
     słownik jest pusty, ale `__` MUSI istnieć zawsze — także z flagą ['pl'],
     inaczej pierwszy tekst w JS rzuciłby ReferenceError. `RM_LANG_ALT` niesie adresy tej strony w innych
     językach dla przełącznika i banera — każdy z podpisem W SWOIM języku. -->
<script>
window.RM_LANG = <?= json_encode(Core\Lang::current()) ?>;
window.RM_I18N = <?= json_encode((object) Core\Lang::jsDictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
window.RM_LANG_ALT = <?= json_encode(array_map(fn($l, $u) => ['lang' => $l, 'url' => $u, 'name' => Core\Lang::name($l), 'prompt' => Core\Lang::with($l, fn() => __('Ta strona jest dostępna po polsku.'))], array_keys($rmLangAlternates), $rmLangAlternates), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
window.__ = function (s, p) {
    var t = (window.RM_I18N && Object.prototype.hasOwnProperty.call(window.RM_I18N, s)) ? window.RM_I18N[s] : s;
    if (p) { Object.keys(p).forEach(function (k) { t = t.split('{' + k + '}').join(String(p[k])); }); }
    return t;
};
</script>
<script src="<?= Utils\View::asset('/assets/js/ui.js') ?>" defer></script>
<!-- Most do funkcji natywnych (aparat, GPS, skaner QR). Ładowany ZAWSZE, nie
     tylko w apce: to on udaje te funkcje w zwykłej przeglądarce, więc kod stron
     woła jedno API i nie musi wiedzieć, gdzie się wykonuje. -->
<script src="<?= Utils\View::asset('/assets/js/native.js') ?>" defer></script>
<?php if (APP_IS_APP): ?>
<!-- Nagrywanie w tle + alerty o skarbach (Etap 5b, 2026-08-28) — działa na
     KAŻDYM ekranie apki, nie tylko na mapie, więc ładuje się tu, w głowie
     dokumentu, a nie na jednej podstronie. Adresy budowane View::url()
     (base_path dev vs prod, jak wszędzie); CSRF ten sam co w każdym <form post>. -->
<script>
window.RM_LOGGED_IN = <?= Core\Auth::user() ? 'true' : 'false' ?>;
window.RM_UPLOAD_URL = <?= json_encode(Utils\View::url('/admin/moje-przejazdy/solo')) ?>;
<?php // `RM_SUMMARY_URL` USUNIĘTE 2026-08-29. Trzymało adres ekranu wyniku dla
      // `goToSummary()` w app-tracking.js — a przycisk nagrywania od decyzji
      // usera („ma informować i przełączać, nic poza tym") nigdzie już nie
      // nawiguje. Podsumowanie otwiera się z powiadomienia, które składa
      // ścieżkę samo, przez most (`native.notify({url})`). Zmienna bez
      // odbiorcy to obietnica zachowania, którego nie ma. ?>
window.RM_NEARBY_URL = <?= json_encode(Utils\View::url('/api/discovery/nearby-treasures')) ?>;
window.RM_GAPS_URL = <?= json_encode(Utils\View::url('/api/discovery/nearby-gaps')) ?>;
window.RM_CLAIM_URL = <?= json_encode(Utils\View::url('/api/treasures/claim')) ?>;
window.RM_CSRF = <?= json_encode(Core\Csrf::token()) ?>;
</script>
<script src="<?= Utils\View::asset('/assets/js/app-tracking.js') ?>" defer></script>
<!-- Wysyłanie plików do innych aplikacji (2026-09-10) — dziś GPX trasy do
     nawigacji. Tu, w głowie, bo linki „Pobierz GPX" stoją na trzech różnych
     stronach, a delegacja obejmuje też te, które dopiero powstaną. Powód
     istnienia i dlaczego to NIE jest gałąź w szablonach: assets/js/app-share.js. -->
<script src="<?= Utils\View::asset('/assets/js/app-share.js') ?>" defer></script>
<!-- SERVICE WORKER TYLKO W APCE (tasks/active/apka-offline.md, Etap 2;
     decyzja usera 2026-08-29: „service worker tylko w apce").
     Rejestracja jest per-przeglądarka, a ta linia stoi ZA bramką APP_IS_APP,
     więc zwykła przeglądarka nigdy jej nie wykona i nigdy nie będzie
     kontrolowana. To jest cały mechanizm ograniczenia ryzyka, dla którego
     ten projekt świadomie nie miał service workera na www.
     PLIK MUSI LEŻEĆ W KORZENIU (nie w /assets/js/) — zasięg service workera
     to katalog, z którego go pobrano, a ma kontrolować /odkrycia i /assets. -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        // Worker NIE DOTYKA ŻADNEGO DOKUMENTU (patrz sw-app.js, reguła 5) —
        // cache'uje wyłącznie kafle, podkład mapy, arkusze i biblioteki.
        // Dzięki temu nie ma tu nic do czyszczenia przy wylogowaniu: żadna
        // strona z treścią użytkownika nigdy nie trafia na dysk.
        navigator.serviceWorker.register(<?= json_encode(Utils\View::url('/sw-app.js')) ?>)
            .catch(function () { /* brak SW nie może wywrócić apki */ });
    });
}
</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>
<?= $jsonLd ?>
<?= $extraHead ?>
</head>
