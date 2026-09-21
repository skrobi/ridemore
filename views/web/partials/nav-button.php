<?php
// views/web/partials/nav-button.php
// PRZYCISK „NAWIGUJ DO PUNKTU" — jeden dla całej aplikacji.
//
// Oczekuje w zasięgu:
//   $navLat, $navLon — współrzędne CELU (null/brak = partial nie renderuje nic),
//   $navLabel        — napis; MUSI mówić, dokąd wiezie („do miejsca zbiórki",
//                      „na start trasy"), nigdy samo „Nawiguj",
//   $navClass        — klasy przycisku (domyślnie `btn btn-secondary btn--sm`).
//
// DLACZEGO PARTIAL, A NIE CZWARTA KOPIA `<a href>`. Adres nawigacji ma trzy
// części, które łatwo pomylić i które zmienią się razem: tryb podróży
// (rowerowy, nie samochodowy), forma adresu (`dir/` URUCHAMIA prowadzenie,
// `?q=` stawia tylko pinezkę — strona wydarzenia miała do 2026-09-10 to drugie
// i nikt tego nie zauważył) oraz zachowanie w apce. Cztery kopie znaczyłyby, że
// następna poprawka trafi w trzy z nich.
//
// JAK TO DZIAŁA W APCE — BEZ ŻADNEJ WTYCZKI. WebView Capacitora oddaje adres
// spoza `server.url` systemowi (`Bridge.launchIntent` → `ACTION_VIEW`,
// sprawdzone w źródłach 8.5), więc telefon otwiera nawigację, którą ma
// zainstalowaną. `target="_blank"` tego nie psuje: Capacitor nie włącza
// `setSupportMultipleWindows`, więc link ładuje się w tym samym oknie i trafia
// w `shouldOverrideUrlLoading`, a tam już decyduje `launchIntent`.
//
// DLACZEGO ADRES GOOGLE, A NIE SCHEMAT `geo:`. `geo:` byłby uczciwszy wobec
// usera (system pyta, KTÓREJ nawigacji użyć), ale nie istnieje w przeglądarce —
// a ten sam przycisk stoi na www. Adres `https` działa wszędzie: na telefonie
// z Mapami Google otwiera aplikację, bez nich — przeglądarkę, a na desktopie
// po prostu mapę. Zmiana na `geo:` wyłącznie pod `APP_IS_APP` jest możliwa
// i opisana w tasks/active/apka-przeglad-2026-09.md, ale wymaga decyzji.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$navLat = $navLat ?? null;
$navLon = $navLon ?? null;
if ($navLat === null || $navLon === null || $navLat === '' || $navLon === '') {
    return;
}

$navLabel = $navLabel ?? __('Nawiguj do startu');
$navClass = $navClass ?? 'btn btn-secondary btn--sm';

// `travelmode=bicycling` — to jest rowerowy serwis i nie ma powodu, żeby
// domyślnie prowadzić kogoś samochodem po trasie, którą ma przejechać.
$navHref = 'https://www.google.com/maps/dir/?api=1&destination='
    . rawurlencode($navLat . ',' . $navLon)
    . '&travelmode=bicycling';
?>
<a class="<?= htmlspecialchars($navClass) ?>" href="<?= htmlspecialchars($navHref) ?>"
   target="_blank" rel="noopener"><?= htmlspecialchars($navLabel) ?> →</a>
<?php
// Sprzątamy po sobie — partial bywa dołączany kilka razy na stronie (etapy
// wielodniowego wydarzenia), a zostawiona zmienna z poprzedniego wywołania
// przekłamałaby następne.
unset($navLat, $navLon, $navLabel, $navClass, $navHref);