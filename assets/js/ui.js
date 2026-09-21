// assets/js/ui.js
// Drobne, ogólne helpery UI używane na wielu, niepowiązanych ze sobą stronach
// (zakładki sekcji, ocena gwiazdkowa w formularzu opinii) — były wcześniej
// wklejone jako identyczny <script> osobno w każdym widoku (event-page.php,
// organizer-profile.php, review-form.php), więc poprawka w jednym miejscu nie
// obejmowała pozostałych. Ładowane globalnie w layout.php, obok Alpine.js.

function showSection(name, btn) {
    document.querySelectorAll('#sectionTabs .section-tab').forEach(function (t) { t.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.section-panel').forEach(function (p) { p.classList.toggle('active', p.dataset.section === name); });
    // Strony z dodatkowym zachowaniem po przełączeniu zakładki (np. event-page.php
    // inicjuje mapy dopiero gdy zakładka "Mapa" staje się widoczna) nasłuchują
    // na to zdarzenie zamiast tej funkcji znającej ich szczegóły.
    document.dispatchEvent(new CustomEvent('ridemore:section-shown', { detail: { name: name } }));
}

// Linki "Wydarzenia"/"Moje wydarzenia" w nagłówku (header.php) są statycznymi
// <a href="/wydarzenia">, więc normalnie zawsze resetują filtry ustawione na
// liście wydarzeń. Strona /wydarzenia zapamiętuje ostatni filtrowany URL w
// sessionStorage (patrz events-list.php, fetchResults) — tu tylko podmieniamy
// href, jeśli coś zapamiętanego istnieje, żeby powrót do listy nie kasował
// wyszukiwania.
document.addEventListener('DOMContentLoaded', function () {
    var savedEvents = sessionStorage.getItem('ridemore_events_url');
    if (savedEvents) {
        document.querySelectorAll('a[data-nav="events"]').forEach(function (a) { a.href = savedEvents; });
    }

    var savedMine = sessionStorage.getItem('ridemore_mine_url');
    if (savedMine) {
        document.querySelectorAll('a[data-nav="mine"]').forEach(function (a) { a.href = savedMine; });
    }

    // Poza stroną główną PHP nie ma jak wiedzieć, czy wracamy do ogólnej listy
    // czy do "Moich wydarzeń" — dopiero tu, na podstawie ostatnio aktywnego
    // kontekstu zapamiętanego w events-list.php, podświetlamy właściwą pozycję menu
    // i kierujemy link powrotu do właściwego miejsca. Warunkowane obecnością
    // [data-nav-back] — ten atrybut istnieje TYLKO na event-page.php, więc bez
    // tego warunku podświetlenie "Wydarzenia"/"Moje wydarzenia" wyciekało też
    // na strony w ogóle niezwiązane z listą eventów (np. profil organizatora),
    // bo tam PHP też nie ustawia żadnego nav-active.
    var backLink = document.querySelector('[data-nav-back]');
    if (backLink) {
        var lastContext = sessionStorage.getItem('ridemore_list_context');
        var contextUrl = lastContext === 'mine' ? savedMine : savedEvents;

        if (lastContext && !document.querySelector('nav a.nav-active')) {
            var activeLink = document.querySelector('nav a[data-nav="' + lastContext + '"]');
            if (activeLink) activeLink.classList.add('nav-active');
        }

        if (lastContext && contextUrl) {
            backLink.href = contextUrl;
            backLink.textContent = lastContext === 'mine' ? __('← Wróć do moich wydarzeń') : __('← Wróć do listy wydarzeń');
        }
    }

    // Podgląd avatara/zdjęć w tle przed zapisem — patrz partials/organizer-profile-form.php,
    // które ładowane jest w dwóch miejscach (samoobsługowy profil organizatora
    // i edycja z panelu admina), stąd ten skrypt tu, a nie wklejony w widoku.
    var avatarInput = document.getElementById('avatarInput');
    if (avatarInput) {
        avatarInput.addEventListener('change', function () {
            var file = this.files[0];
            var wrap = document.getElementById('avatarPreviewWrap');
            var box = document.getElementById('avatarUploadBox');
            var label = document.getElementById('avatarUploadLabel');
            if (!file) {
                return;
            }
            wrap.innerHTML = '<img class="avatar-edit-preview" src="' + URL.createObjectURL(file) + '" alt="">';
            box.classList.add('filled');
            label.textContent = __('Wybrano: {plik}', { plik: file.name });
        });
    }

    var heroInput = document.getElementById('heroInput');
    if (heroInput) {
        heroInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(this.files);
            var preview = document.getElementById('heroNewPreview');
            var box = document.getElementById('heroUploadBox');
            var label = document.getElementById('heroUploadLabel');
            preview.innerHTML = '';
            if (!files.length) {
                box.classList.remove('filled');
                label.textContent = __('Dodaj zdjęcia w tle (do 5 naraz, JPEG/PNG/WebP)');
                return;
            }
            files.slice(0, 5).forEach(function (file) {
                var thumb = document.createElement('div');
                thumb.className = 'hero-photo-thumb';
                thumb.innerHTML = '<img src="' + URL.createObjectURL(file) + '" alt="">';
                preview.appendChild(thumb);
            });
            box.classList.add('filled');
            label.textContent = files.length + (files.length === 1 ? __(' zdjęcie wybrane') : __(' zdjęć wybranych')) + (files.length > 5 ? __(' (zapisanych zostanie max 5)') : '');
        });
    }
});

function selectRating(container, value) {
    container.querySelector('input[name=rating]').value = value;
    container.querySelectorAll('.rating-btn').forEach(function (b) { b.classList.toggle('active', Number(b.textContent) <= value); });
}

function validateRating(form) {
    if (!form.querySelector('input[name=rating]').value) {
        alert(__('Wybierz ocenę od 1 do 5.'));
        return false;
    }
    return true;
}

// Podświetla pozycję kotwicznego paska nawigacji odpowiadającą sekcji aktualnie
// widocznej w oknie (profil organizatora — sekcje są zawsze w DOM, przewijane,
// nie chowane jak .section-tabs/showSection() powyżej).
function ridemoreInitScrollSpy(navSelector, sectionSelector) {
    var links = Array.prototype.slice.call(document.querySelectorAll(navSelector));
    if (!links.length) return;
    var sections = links.map(function (l) { return document.getElementById(l.dataset.t); });

    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            var id = entry.target.id;
            links.forEach(function (l) { l.classList.toggle('active', l.dataset.t === id); });
        });
    }, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });

    sections.forEach(function (s) { if (s) io.observe(s); });
}

// WERSJE JĘZYKOWE (2026-09-16, tasks/active/wielojezycznosc.md).
//
// 1. Przełącznik (partials/lang-switch.php) to zwykłe linki — tu tylko
//    ZAPAMIĘTUJEMY wybór, zanim przeglądarka pójdzie za linkiem: ciasteczko
//    od razu, a POST z `keepalive` dokończy się nawet po zmianie strony
//    (u zalogowanego zapisuje `users.lang`, od którego zależy język maili).
// 2. Baner „ta strona jest też w Twoim języku" — ZAMIAST przekierowania
//    (Google odradza przekierowania wg wykrytego języka). Liczony tutaj, nie
//    w PHP: HTML tej samej strony nie może zależeć od nagłówków przeglądarki.
//    Pokazuje się, gdy język strony różni się od jawnie wybranego (ciasteczko)
//    albo — u kogoś, kto nigdy nie wybierał — od języka przeglądarki.
(function () {
    function cookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : null;
    }

    function remember(lang, box) {
        document.cookie = 'rm_lang=' + encodeURIComponent(lang) + '; path=/; max-age=31536000; samesite=lax';
        var save = box && box.getAttribute('data-lang-save');
        if (!save || !window.fetch) return;
        var body = new URLSearchParams();
        body.set('lang', lang);
        try {
            fetch(save, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'X-CSRF-Token': box.getAttribute('data-csrf') || '' },
                body: body
            }).catch(function () { /* wybór i tak jest w ciasteczku */ });
        } catch (e) { /* stara przeglądarka bez keepalive */ }
    }

    document.addEventListener('click', function (ev) {
        var link = ev.target.closest ? ev.target.closest('a[data-lang-switch]') : null;
        if (!link) return;
        remember(link.getAttribute('data-lang-switch'), link.closest('.lang-switch'));
    });

    document.addEventListener('DOMContentLoaded', function () {
        var alts = window.RM_LANG_ALT || [];
        var pageLang = window.RM_LANG || document.documentElement.lang;
        if (alts.length < 2 || sessionStorage.getItem('rm_lang_banner_closed') === '1') return;

        var wanted = cookie('rm_lang');
        if (!wanted) {
            var browser = (navigator.languages || [navigator.language || '']).map(function (l) { return String(l).slice(0, 2).toLowerCase(); });
            for (var i = 0; i < browser.length && !wanted; i++) {
                if (alts.some(function (a) { return a.lang === browser[i]; })) wanted = browser[i];
            }
        }
        if (!wanted || wanted === pageLang) return;

        var target = alts.filter(function (a) { return a.lang === wanted; })[0];
        if (!target) return;

        var bar = document.createElement('div');
        bar.className = 'lang-banner';
        bar.setAttribute('lang', target.lang);
        var link = document.createElement('a');
        link.href = target.url;
        link.textContent = target.prompt;
        link.setAttribute('data-lang-switch', target.lang);
        link.setAttribute('hreflang', target.lang);
        var close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', '×');
        close.textContent = '×';
        close.addEventListener('click', function () {
            sessionStorage.setItem('rm_lang_banner_closed', '1');
            bar.remove();
        });
        link.addEventListener('click', function () {
            remember(target.lang, document.querySelector('.lang-switch'));
        });
        bar.appendChild(link);
        bar.appendChild(close);
        document.body.appendChild(bar);
    });
})();
