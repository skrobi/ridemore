<?php
// views/web/partials/app-offline.php
// WSKAŹNIK BRAKU SIECI W APCE. Renderowany wyłącznie z `layout-app.php`.
// Kontrakt: tasks/active/apka-offline.md, Etap 1.
//
// POWSTAŁ ZAMIAST BLOKADY. Do 2026-08-29 brak sieci (a właściwie KAŻDY nieudany
// load, łącznie ze zwykłym 404) podmieniał całą stronę na `app/www/index.html`
// przez `server.errorPath` — user zgłosił to wprost: „aplikacja nie może
// blokować mapy w trybie offline (…) może być jakaś ikonka offline, a nie
// blokada". Ekran-blokada miał dwie wady nie do naprawienia na miejscu: nie
// miał dostępu do wtyczek Capacitora (nie pokazałby ani cache'u, ani pozycji)
// i kłamał o przyczynie, bo odpalał się też na błędach HTTP.
//
// TO JEST TYLKO ZNACZNIK STANU, NIE OSTRZEŻENIE. Bez przycisków, bez „spróbuj
// ponownie", bez zasłaniania czegokolwiek — jazda bez zasięgu jest NORMALNYM
// trybem pracy tej apki, a nie awarią: ślad buforuje się lokalnie
// (`app-tracking.js`), skany wpadają do kolejki (`native.js`), alerty o skarbach
// liczą się z pobranej wcześniej listy. Nic z tego nie wymaga od użytkownika
// reakcji, więc pasek nie ma prawa o nią prosić.
//
// Domyślnie UKRYTY i pokazywany dopiero JS-em — inaczej mignąłby przy każdym
// wejściu na stronę, zanim most zdąży odpowiedzieć na pytanie o stan sieci.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div class="app-offline" id="rmOffline" role="status" aria-live="polite" hidden>
    <span class="app-offline__dot" aria-hidden="true"></span>
    <span><?= __('Brak zasięgu — jedziesz dalej, wszystko zapisze się po powrocie') ?></span>
</div>
<script>
(function () {
    // PO DOMContentLoaded, nie od razu. `native.js` (most `RM`) ładuje się
    // z atrybutem `defer`, czyli wykonuje się PRZED tym zdarzeniem, ale PO
    // sparsowaniu treści — a ten skrypt stoi w treści. Sprawdzenie `window.RM`
    // w trakcie parsowania zawsze wypadłoby fałszywie i wskaźnik nigdy by się
    // nie odezwał. Ta sama pułapka co przy automacie GPS na zgłoszeniu skarbu.
    function start() {
        var pasek = document.getElementById('rmOffline');
        if (!pasek || !window.RM || !RM.native) { return; }

        function pokaz(online) {
            pasek.hidden = !!online;
            // `aria-live` wisi na elemencie na stałe, więc sama zmiana
            // `hidden` wystarczy, żeby czytnik ogłosił zmianę stanu.
            document.body.classList.toggle('is-offline', !online);
        }

        RM.native.isOnline().then(pokaz);
        RM.native.onNetworkChange(pokaz);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>
