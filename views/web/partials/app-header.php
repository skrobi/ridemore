<?php
// views/web/partials/app-header.php
// GÓRNA BELKA APLIKACJI MOBILNEJ. Renderowana WYŁĄCZNIE z `layout-app.php`,
// czyli tylko gdy APP_IS_APP. Kontrakt: tasks/done/apka-mobilna-skorupa.md.
//
// CO TU JEST I DLACZEGO TYLE: logo (wejście na stronę główną) oraz ikona
// wiadomości z kropką nieprzeczytanych. Nic więcej.
//
// CZEGO TU NIE MA, choć jest w `header.php` (web), i dlaczego:
//   - nawigacja Wydarzenia/Puls/Odkrycia/Organizatorzy — dubluje dolny pasek
//     `app-nav.php`, który stoi na tym samym ekranie i jest pod kciukiem;
//   - hamburger — otwierał tę samą nawigację;
//   - rozwijane menu konta (~20 pozycji, w tym blok organizatora i admina) —
//     przeniesione na ekran „Profil" (piąty slot paska), gdzie ma miejsce
//     na czytelne grupy zamiast listy w kieszonce nad mapą;
//   - licznik „Moje wydarzenia" — jego zapytanie kosztowało tyle samo co
//     reszta menu razem, a liczba nie mieści się na wąskiej belce.
//
// KOSZT ZAPYTAŃ: `header.php` robi CZTERY zapytania na każde żądanie
// (EventRsvp::confirmedEditionCountsForUser, Organizer::hasProfile oraz dwa
// liczniki nieprzeczytanych). Tutaj zostają DWA — oba na tę jedną kropkę,
// bo skrzynki 1:1 i kanały grupowe wydarzeń są osobnymi tabelami, a badge
// ma pokazywać sumę (ta sama zasada co w `MessageController::mergedInbox`).
//
// ELEMENT <header> ŚWIADOMIE TEN SAM co na web — bierze z arkusza gotowe
// zachowanie (sticky, rozmycie tła, kreska u dołu, rozkład na boki), więc
// belka apki nie jest osobnym kawałkiem stylu do utrzymania. Różnicę robi
// klasa `app-header`: mniejsze odstępy i zapas na wycięcie ekranu.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$currentUser = Core\Auth::user();

// Badge = nieprzeczytane 1:1 + kanały grupowe wydarzeń (obie skrzynki są
// w jednym widoku /wiadomosci) — dokładnie jak w header.php.
$unreadMessagesCount = $currentUser
    ? Models\Message::unreadCountForUser($currentUser->id) + Models\EventGroupConversation::unreadCountForUser($currentUser->id)
    : 0;
?>
<header class="app-header">
    <a href="<?= Utils\View::url('/') ?>" class="logo"><span class="mark" aria-hidden="true"></span><img src="<?= Utils\View::asset('/assets/logo/android-chrome-192x192.png') ?>" alt="ridemore.bike" width="192" height="192"><span class="logo-text"><?= __('ride') ?><b><?= __('more') ?></b><?= __('.bike') ?></span></a>
    <?php require __DIR__ . '/lang-switch.php'; ?>
    <?php if ($currentUser): ?>
    <a href="<?= Utils\View::url('/wiadomosci') ?>" class="msg-icon" title="<?= htmlspecialchars(__('Wiadomości')) ?>" aria-label="<?= $unreadMessagesCount > 0 ? __('Wiadomości — {n} nieprzeczytane', ['n' => $unreadMessagesCount]) : __('Wiadomości') ?>">
        <?= Utils\Icon::render('mail') ?>
        <?php if ($unreadMessagesCount > 0): ?><span class="msg-icon-dot" aria-hidden="true"></span><?php endif; ?>
    </a>
    <?php endif; ?>
</header>
