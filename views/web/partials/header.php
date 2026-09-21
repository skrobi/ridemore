<?php
// views/web/partials/header.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$currentUser = Core\Auth::user();
// Licznik obok „Moje wydarzenia" to ZAPOWIEDŹ, nie archiwum — pokazuje wyłącznie
// turnusy, które są jeszcze przede mną. Liczba zakończonych jedzie osobno, pod
// filtr „Zakończone" na liście wydarzeń.
$myEventCounts = $currentUser
    ? Models\EventRsvp::confirmedEditionCountsForUser($currentUser->id)
    : ['upcoming' => 0, 'past' => 0];
$myEventsCount = $myEventCounts['upcoming'];
// Rola organizatora = ISTNIENIE profilu organizatora, nie flaga na koncie
// (patrz Models\Organizer::hasProfile). Decyduje o tym, czy menu konta pokazuje
// blok „Organizuję".
$isOrganizer = $currentUser ? Models\Organizer::hasProfile($currentUser->id) : false;
// Badge = nieprzeczytane 1:1 + kanały grupowe wydarzeń (obie skrzynki są w
// jednym widoku /wiadomosci, patrz MessageController::mergedInbox).
$unreadMessagesCount = $currentUser
    ? Models\Message::unreadCountForUser($currentUser->id) + Models\EventGroupConversation::unreadCountForUser($currentUser->id)
    : 0;

// Podświetlenie "Wydarzenia" vs "Moje wydarzenia" w nawigacji — oba wskazują
// na tę samą trasę '/wydarzenia', różni je tylko ?mine=1, więc bez tego nie
// dało się poznać po samym menu, w którym trybie się jest.
$basePath = rtrim(APP_CONFIG['base_path'] ?? '', '/');
$currentPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$isEventsListPage = $currentPath === $basePath . '/wydarzenia';
$isMineActive = $isEventsListPage && ($_GET['mine'] ?? '') === '1';
$isEventsActive = $isEventsListPage && !$isMineActive;
$isOrganizersActive = $currentPath === $basePath . '/organizatorzy' || str_starts_with($currentPath, $basePath . '/organizatorzy/');
?>
<header>
    <a href="<?= Utils\View::url('/') ?>" class="logo"><span class="mark" aria-hidden="true"></span><img src="<?= Utils\View::asset('/assets/logo/android-chrome-192x192.png') ?>" alt="ridemore.bike" width="192" height="192"><span class="logo-text"><?= __('ride') ?><b><?= __('more') ?></b><?= __('.bike') ?></span></a>
    <details class="mobile-nav-toggle">
        <summary class="hamburger-btn" aria-label="<?= htmlspecialchars(__('Menu')) ?>"><?= Utils\Icon::render('menu') ?><?= Utils\Icon::render('close') ?></summary>
    </details>
    <nav>
        <a href="<?= Utils\View::url('/wydarzenia') ?>" data-nav="events" class="<?= $isEventsActive ? 'nav-active' : '' ?>"><?= __('Wydarzenia') ?></a>
        <a href="<?= Utils\View::url('/puls') ?>" class="<?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/puls') ? 'nav-active' : '' ?>"><?= __('Puls') ?></a>
        <?php // Discovery (Etap 8) wchodzi do nawigacji przez WSPÓLNĄ mapę, nie
              // przez osobistą: wspólna działa bez logowania i jest jedynym
              // miejscem w serwisie, na które można wejść bez konkretnego
              // wyjazdu na oku. Do własnej mapy prowadzi stamtąd jedno
              // kliknięcie i pozycja w menu konta niżej. ?>
        <a href="<?= Utils\View::url('/odkrycia') ?>" class="<?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/odkrycia') ? 'nav-active' : '' ?>"><?= __('Odkrycia') ?></a>
        <a href="<?= Utils\View::url('/organizatorzy') ?>" class="<?= $isOrganizersActive ? 'nav-active' : '' ?>"><?= __('Organizatorzy') ?></a>
        <?php // "Jak to działa" przeniesione do stopki (sekcja Serwis) — to treść
              // czytana RAZ, przy pierwszym kontakcie, a zajmowała stałe miejsce
              // w nawigacji na każdej stronie. Zwolniony slot zostaje na moduł. ?>
        <?php if ($currentUser): ?>
        <a href="<?= Utils\View::url('/wydarzenia?mine=1') ?>" data-nav="mine" class="<?= $isMineActive ? 'nav-active' : '' ?>"><?= __('Moje wydarzenia ({n})', ['n' => $myEventsCount]) ?></a>
        <?php // Wiadomości jako osobna ikona obok dropdownu (dawniej pozycja w
              // menu konta). Czerwona kropka = są nieprzeczytane (1:1 + grupowe).
              // Na lewo od niej „Dodaj wydarzenie" — dla każdego zalogowanego;
              // /wydarzenia/nowe sam zakłada profil organizatora. ?>
        <a href="<?= Utils\View::url('/wydarzenia/nowe') ?>" class="msg-icon msg-icon--add" title="<?= htmlspecialchars(__('Dodaj wydarzenie')) ?>" aria-label="<?= htmlspecialchars(__('Dodaj wydarzenie')) ?>">
            <?= Utils\Icon::render('calendar-add') ?>
        </a>
        <a href="<?= Utils\View::url('/wiadomosci') ?>" class="msg-icon" title="<?= htmlspecialchars(__('Wiadomości')) ?>" aria-label="<?= $unreadMessagesCount > 0 ? __('Wiadomości — {n} nieprzeczytane', ['n' => $unreadMessagesCount]) : __('Wiadomości') ?>">
            <?= Utils\Icon::render('mail') ?>
            <?php if ($unreadMessagesCount > 0): ?><span class="msg-icon-dot" aria-hidden="true"></span><?php endif; ?>
        </a>
        <details class="account-menu">
            <summary><?= htmlspecialchars($currentUser->displayName()) ?></summary>
            <div class="account-menu-panel">
                <?php // KARTA „TO JA" NA GÓRZE MENU (2026-09-13, test usera: „kliknąłem
                      // swój nick, żeby przejść do profilu, a ustawienia są głęboko").
                      // Sam nick musi zostać przełącznikiem menu (<summary>), więc
                      // przejście do profilu jest PIERWSZĄ rzeczą pod nim — imię,
                      // e-mail i wyraźne „Mój profil →" w jednym dużym polu, a zaraz
                      // pod nim „Ustawienia i preferencje" (dawniej „Moje konto" na
                      // samym dole, pod blokiem organizatora i adminem).
                      // Bez sluga (konto sprzed migr. 038) zostaje sam e-mail. ?>
                <?php if ($currentUser->publicSlug): ?>
                <a class="account-menu-me" href="<?= Utils\View::url('/rowerzysta/' . $currentUser->publicSlug) ?>">
                    <b><?= htmlspecialchars($currentUser->displayName()) ?></b>
                    <small><?= htmlspecialchars($currentUser->email) ?></small>
                    <span><?= __('Mój profil rowerzysty →') ?></span>
                </a>
                <?php else: ?>
                <span class="account-menu-email"><?= htmlspecialchars($currentUser->email) ?></span>
                <?php endif; ?>
                <a href="<?= Utils\View::url('/admin/moje-konto') ?>"><?= __('Ustawienia i preferencje') ?></a>
                <div class="account-menu-sep"></div>
                <?php // MENU POGRUPOWANE WG ROLI (2026-08-13, uwaga usera „gruby
                      // bałagan"). Do tej pory dziewięć pozycji leżało jedną płaską
                      // listą w kolejności dopisywania, mieszając trzy różne role:
                      // rowerzystę, organizatora i ustawienia konta. Teraz trzy
                      // bloki oddzielone kreską, a blok organizatora POKAZUJE SIĘ
                      // TYLKO ORGANIZATOROM — większość kont to zwykli rowerzyści,
                      // którym „Płatności" i „Profil organizatora" nic nie mówią. ?>
                <span class="account-menu-group"><?= __('Ja na rowerze') ?></span>
                <a href="<?= Utils\View::url('/odkrycia') ?>"><?= __('Moje odkrycia') ?></a>
                <a href="<?= Utils\View::url('/admin/moje-przejazdy') ?>"><?= __('Moje przejazdy') ?></a>
                <a href="<?= Utils\View::url('/wydarzenia?mine=1') ?>"><?= __('Moje wyjazdy') ?></a>

                <div class="account-menu-sep"></div>
                <?php if ($isOrganizer): ?>
                <span class="account-menu-group"><?= __('Organizuję') ?></span>
                <a href="<?= Utils\View::url('/admin') ?>"><?= __('Panel organizatora') ?></a>
                <a href="<?= Utils\View::url('/wydarzenia/nowe') ?>"><?= __('+ Dodaj wydarzenie') ?></a>
                <a href="<?= Utils\View::url('/admin/platnosci') ?>"><?= __('Płatności') ?></a>
                <a href="<?= Utils\View::url('/admin/profil-rozliczeniowy') ?>"><?= __('Profil organizatora') ?></a>
                <?php else: ?>
                <?php // Zwykły rowerzysta nie dostaje panelu organizatora, tylko
                      // jedno wejście — i to ono zakłada profil (patrz
                      // Organizer::ensureProfile), a nie przypadkowe kliknięcie
                      // w „Dodaj wydarzenie". ?>
                <span class="account-menu-group"><?= __('Chcesz organizować?') ?></span>
                <a href="<?= Utils\View::url('/wydarzenia/nowe') ?>"><?= __('Wystaw pierwszy wyjazd') ?></a>
                <?php endif; ?>

                <?php if ($currentUser->isAdmin): ?>
                <div class="account-menu-sep"></div>
                <?php // Użytkownicy PIERWSI w tej grupie: to jedyna pozycja, którą
                      // otwiera się rutynowo („czy ktoś nowy się zarejestrował"),
                      // reszta to konfiguracja, do której wchodzi się rzadko. ?>
                <a href="<?= Utils\View::url('/admin/uzytkownicy') ?>"><?= __('Użytkownicy') ?></a>
                <a href="<?= Utils\View::url('/admin/organizatorzy') ?>"><?= __('Organizatorzy') ?></a>
                <a href="<?= Utils\View::url('/admin/taksonomia') ?>"><?= __('Taksonomia') ?></a>
                <a href="<?= Utils\View::url('/admin/znane-trasy') ?>"><?= __('Znane trasy') ?></a>
                <a href="<?= Utils\View::url('/admin/regiony-mapa') ?>"><?= __('Regiony na mapie') ?></a>
                <a href="<?= Utils\View::url('/admin/skarby') ?>"><?= __('Skarby') ?></a>
                <a href="<?= Utils\View::url('/admin/punkty') ?>"><?= __('Punkty i bonusy') ?></a>
                <a href="<?= Utils\View::url('/admin/powiadomienia') ?>"><?= __('Powiadomienia') ?></a>
                <a href="<?= Utils\View::url('/admin/kafle') ?>"><?= __('Kafle map') ?></a>
                <?php endif; ?>
                <form method="post" action="<?= Utils\View::url('/wyloguj') ?>">
                    <?= Core\Csrf::field() ?>
                    <button type="submit" class="link-button"><?= __('Wyloguj') ?></button>
                </form>
            </div>
        </details>
        <?php else: ?>
        <a href="<?= Utils\View::url('/wydarzenia/nowe') ?>"><?= __('Zgłoś wydarzenie') ?></a>
        <?php // Jedno wejście do konta zamiast dwóch. "Załóż konto" nie znika —
              // strona logowania ma je pierwszym zdaniem pod formularzem
              // ("Nie masz konta? Załóż konto", login.php), więc rejestracja jest
              // o jedno kliknięcie dalej, a nagłówek odzyskuje slot. ?>
        <a href="<?= Utils\View::url('/logowanie') ?>" class="<?= str_contains($currentPath, $basePath . '/logowanie') || str_contains($currentPath, $basePath . '/rejestracja') ? 'nav-active' : '' ?>"><?= __('Zaloguj się') ?></a>
        <?php endif; ?>
        <?php require __DIR__ . '/lang-switch.php'; ?>
    </nav>
</header>