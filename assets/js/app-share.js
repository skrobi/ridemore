// assets/js/app-share.js
// WYSYŁANIE PLIKÓW Z APKI DO INNYCH APLIKACJI — dziś: GPX do nawigacji.
// Ładowany WYŁĄCZNIE pod APP_IS_APP (partials/head.php).
//
// ============================================================================
// PROBLEM, KTÓRY TO NAPRAWIA
// ============================================================================
// Serwis ma cztery przyciski „Pobierz GPX" — na stronie znanej trasy (dwa:
// w nagłówku i na okładce), na stronie wydarzenia (po jednym na etap)
// i na stronie przejazdu. Wszystkie to `<a href="…gpx" download>`.
//
// W APCE ŻADEN Z NICH NIE DZIAŁA i nie działał od początku istnienia apki.
// Sprawdzone w źródłach Capacitora 8.5, nie z pamięci o WebView:
// `BridgeWebViewClient.shouldOverrideUrlLoading` oddaje adres do
// `Bridge.launchIntent`, ten dla adresu z TEGO SAMEGO hosta co `server.url`
// zwraca `false` (czyli „ładuj u siebie"), a WebView bez ustawionego
// `DownloadListener` nie potrafi pobrać pliku. `DownloadListener` nie ustawia
// ani Capacitor (nie ma go w całym pakiecie `@capacitor/android`), ani nasze
// `MainActivity`, które jest puste.
//
// ============================================================================
// DLACZEGO JEDNO MIEJSCE, A NIE CZTERY POPRAWKI W WIDOKACH
// ============================================================================
// Widoki są wspólne dla www i apki, a różnica dotyczy WYŁĄCZNIE apki i dotyczy
// KAŻDEGO takiego linku — także tych, które dopiero powstaną. Delegacja na
// `document` obejmuje je wszystkie bez pamiętania o niczym; cztery gałęzie
// `if (APP_IS_APP)` w szablonach oznaczałyby, że piąty link o tym nie wie.
//
// ============================================================================
// NAPIS MUSI ZGADZAĆ SIĘ Z TYM, CO SIĘ STANIE
// ============================================================================
// Gdy most ma obie wtyczki (Filesystem + Share), dotknięcie otwiera systemowy
// arkusz „wyślij do…" i plik ląduje w OsmAndzie/Locusie/Komoocie — więc napis
// zmienia się na „Wyślij do nawigacji". Gdy wtyczek nie ma (starszy APK, bo
// `server.url` znaczy, że TEN plik dojedzie do telefonu wcześniej niż nowa
// binarka), nie ruszamy niczego: zostaje „Pobierz GPX" i systemowe pobieranie
// z `DownloadListener` w `MainActivity`. Przycisk nigdy nie obiecuje drugiej
// drogi, gdy umie tylko pierwszą — w tym projekcie kilka razy okazało się,
// że tekst UI obiecywał funkcję, której kod nie realizował.
(function () {
    'use strict';

    function linki() {
        return Array.prototype.slice.call(
            document.querySelectorAll('a[download][href$=".gpx"], a[download][href*=".gpx?"]')
        );
    }

    function nazwaPliku(a) {
        // Atrybut `download` niesie nazwę czytelną dla człowieka („beskidy.gpx"),
        // a adres — hash. Bierzemy pierwsze, gdy jest.
        var z = (a.getAttribute('download') || '').trim();
        if (z) { return z.slice(-4) === '.gpx' ? z : z + '.gpx'; }
        return 'trasa-' + Date.now() + '.gpx';
    }

    function przemianuj() {
        linki().forEach(function (a) {
            if (a.dataset.rmShare) { return; }
            a.dataset.rmShare = '1';
            // Zostawiamy strzałkę w dół tylko przy pobieraniu; przy wysyłce
            // kierunek jest inny, więc znika razem z dawnym napisem.
            a.textContent = __('Wyślij do nawigacji');
        });
    }

    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[data-rm-share]');
        if (!a) { return; }
        e.preventDefault();

        var stary = a.textContent;
        a.textContent = __('Przygotowuję…');
        a.classList.add('is-busy');

        window.RM.native.shareFile(a.href, nazwaPliku(a), document.title)
            .catch(function (err) {
                // Arkusz zamknięty palcem to NIE jest błąd — wtyczka zgłasza
                // to odrzuconą obietnicą z „canceled"/„Share canceled",
                // a komunikat o niepowodzeniu byłby wtedy kłamstwem.
                if (/cancel/i.test((err && err.message) || '')) { return; }
                alert((err && err.message) || __('Nie udało się wysłać pliku.'));
            })
            .then(function () {
                a.textContent = stary;
                a.classList.remove('is-busy');
            });
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (!window.RM || !RM.native || typeof RM.native.canShareFile !== 'function') { return; }
        if (!RM.native.canShareFile()) { return; }
        przemianuj();
    });
})();