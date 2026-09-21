<?php
// views/web/pages/messages.php
// Layout w stylu Messengera: lista konwersacji po lewej (zawsze widoczna na
// desktopie), aktywny wątek po prawej. Jeden widok obsługuje DWA rodzaje
// kanałów — 1:1 (Models\Message) i grupowe per-turnus (Models\EventGroupConversation),
// scalone w kontrolerze (MessageController::mergedInbox) do jednego,
// znormalizowanego kształtu wiersza skrzynki.
//
// Oczekuje:
//  $conversations — znormalizowane wiersze: key, is_group, href, avatar, title,
//     subtitle(?), time, preview, unread.
//  $currentUserId, $activeKey (?string — 'c{id}'/'g{editionId}', podświetlenie),
//  $activeThread (?array): 1:1 => ['isGroup'=>false,'title','messages'];
//     grupa => ['isGroup'=>true,'title','subtitle','eventLink','messages']
//     (wiadomości grupy niosą sender_id, sender_name, is_organizer).
//  $sendAction (string, tylko gdy $activeThread !== null).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

$activeKey = $activeKey ?? null;
$startChannels = $startChannels ?? [];
$startOrganizers = $startOrganizers ?? [];
$isGroupThread = $activeThread !== null && !empty($activeThread['isGroup']);
// Ikona grupy (ta sama co w wierszach skrzynki) — reużywana w panelu „Nowa rozmowa".
$groupIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 6.2a3.2 3.2 0 0 1 0 6M17.5 19a5.5 5.5 0 0 0-3-4.9"/></svg>';
?>
<?php // Okruszki są chromem stron web (ekran > sekcja > ...) — w apce, gdzie
      // powrót do listy daje już `.messenger-back`, tylko zajmowałyby miejsce
      // nad i tak ciasnym ekranem telefonu. Ten sam odruch co pominięcie hero
      // na `discovery-app.php` w Etapie 1: mniej chromu, więcej treści. ?>
<?php // Okruszki: bramkę „nie w apce" trzyma teraz sam partial (2026-09-11) —
      // zasada obowiązywała tylko w czterech szablonach, a dotyczy wszystkich. ?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>

<div class="messenger <?= $activeThread !== null ? 'has-active' : '' ?>">
    <aside class="messenger-sidebar">
        <h1 class="messenger-title"><?= __('Wiadomości') ?></h1>

        <?php // Panel „Nowa rozmowa" — startowanie z poziomu messengera: grupy
              // wyjazdów (także jeszcze nierozpoczęte) + organizatorzy do 1:1. ?>
        <details class="msg-start">
            <summary><?= __('+ Nowa rozmowa') ?></summary>
            <div class="msg-start-panel">
                <?php if (empty($startChannels) && empty($startOrganizers)): ?>
                <p class="desc" style="padding:6px 4px;font-size:13px;"><?= __('Zapisz się na wyjazd, żeby dołączyć do dyskusji grupy i pisać do organizatora.') ?></p>
                <?php endif; ?>

                <?php if (!empty($startChannels)): ?>
                <p class="msg-start-h"><?= __('Dyskusje grup Twoich wyjazdów') ?></p>
                <?php foreach ($startChannels as $ch): ?>
                <a class="msg-start-row" href="<?= View::url('/wiadomosci/grupa/' . $ch['edition_id']) ?>">
                    <span class="msg-start-ic msg-start-ic--group"><?= $groupIconSvg ?></span>
                    <span class="msg-start-txt"><b><?= htmlspecialchars($ch['event_title']) ?></b><small><?= __('Grupa') ?> · <?= htmlspecialchars(Format::dateShort($ch['edition_date'])) ?><?= !$ch['has_channel'] ? ' · ' . __('zacznij dyskusję') : '' ?></small></span>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($startOrganizers)): ?>
                <p class="msg-start-h"><?= __('Napisz do organizatora') ?></p>
                <?php foreach ($startOrganizers as $org): $on = $org['name'] ?: $org['email']; ?>
                <a class="msg-start-row" href="<?= View::url('/wiadomosci/z/' . $org['user_id']) ?>">
                    <span class="msg-start-ic"><?= htmlspecialchars(mb_strtoupper(mb_substr($on, 0, 1))) ?></span>
                    <span class="msg-start-txt"><b><?= htmlspecialchars($on) ?></b><small><?= __('Organizator') ?> · <?= htmlspecialchars($org['sample_event_title']) ?></small></span>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>

        <?php if (empty($conversations)): ?>
        <p class="desc" style="padding:0 16px;"><?= __('Nie masz jeszcze żadnych konwersacji. Przycisk „Napisz” pojawia się na stronie wydarzenia lub organizatora, z którym łączy Cię wspólny wyjazd — a dyskusja całej grupy na stronie wydarzenia, na które jesteś zapisany/a.') ?></p>
        <?php else: ?>
        <div class="inbox-list">
            <?php foreach ($conversations as $c): ?>
            <a class="inbox-row <?= (int) $c['unread'] > 0 ? 'inbox-row-unread' : '' ?> <?= $c['key'] === $activeKey ? 'inbox-row-active' : '' ?>" href="<?= htmlspecialchars($c['href']) ?>">
                <?php if ($c['is_group']): ?>
                <div class="inbox-avatar inbox-avatar--group" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 6.2a3.2 3.2 0 0 1 0 6M17.5 19a5.5 5.5 0 0 0-3-4.9"/></svg>
                </div>
                <?php else: ?>
                <?php // Zdjęcie rozmówcy, litera jako zapas (2026-08-22) — ta sama
                      // zasada co w `partials/rider-avatar.php`; litera zostaje
                      // w `alt`, więc niewczytany plik nie daje pustego kółka. ?>
                <?php if (!empty($c['avatarUrl'])): ?>
                <div class="inbox-avatar inbox-avatar--photo"><img src="<?= htmlspecialchars(Utils\Image::src($c['avatarUrl'], 'av')) ?>" alt="<?= htmlspecialchars($c['avatar']) ?>" width="44" height="44" loading="lazy"></div>
                <?php else: ?>
                <div class="inbox-avatar"><?= htmlspecialchars($c['avatar']) ?></div>
                <?php endif; ?>
                <?php endif; ?>
                <div class="inbox-body">
                    <div class="inbox-row-top">
                        <span class="inbox-name"><?= htmlspecialchars($c['title']) ?></span>
                        <span class="inbox-time"><?= htmlspecialchars(Format::dateTime($c['time'])) ?></span>
                    </div>
                    <?php if (!empty($c['subtitle'])): ?>
                    <div class="inbox-sub"><?= htmlspecialchars($c['subtitle']) ?></div>
                    <?php endif; ?>
                    <div class="inbox-preview"><?= htmlspecialchars(mb_strimwidth((string) $c['preview'], 0, 64, '…')) ?></div>
                </div>
                <?php if ((int) $c['unread'] > 0): ?>
                <span class="inbox-badge"><?= (int) $c['unread'] ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </aside>

    <section class="messenger-thread">
        <?php if ($activeThread === null): ?>
        <div class="messenger-empty">
            <p class="desc"><?= __('Wybierz konwersację z listy po lewej.') ?></p>
        </div>
        <?php else: ?>
        <a class="messenger-back" href="<?= View::url('/wiadomosci') ?>"><?= __('← Wszystkie wiadomości') ?></a>
        <h2 class="messenger-thread-title">
            <?php if ($isGroupThread): ?><span class="thread-group-tag"><?= __('Grupa') ?></span> <?php endif; ?>
            <?= htmlspecialchars($activeThread['title']) ?>
        </h2>
        <?php if ($isGroupThread): ?>
        <p class="messenger-thread-sub"><?= htmlspecialchars($activeThread['subtitle'] ?? '') ?> · <a href="<?= htmlspecialchars($activeThread['eventLink']) ?>"><?= __('strona wyjazdu →') ?></a></p>
        <?php endif; ?>

        <div class="thread-box">
            <?php if (empty($activeThread['messages'])): ?>
            <p class="desc"><?= $isGroupThread ? __('Napisz pierwszą wiadomość do grupy — zobaczą ją wszyscy zapisani na ten termin.') : __('Napisz pierwszą wiadomość.') ?></p>
            <?php endif; ?>
            <?php foreach ($activeThread['messages'] as $m): ?>
            <?php $mine = (int) $m['sender_id'] === (int) $currentUserId; ?>
            <div class="thread-msg <?= $mine ? 'thread-msg-mine' : 'thread-msg-theirs' ?>">
                <?php if ($isGroupThread && !$mine): ?>
                <div class="thread-msg-sender">
                    <?= htmlspecialchars($m['sender_name'] ?: __('Uczestnik')) ?><?php if (!empty($m['is_organizer'])): ?><span class="thread-badge-org"><?= __('Organizator') ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="thread-msg-bubble"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
                <div class="thread-msg-time"><?= htmlspecialchars(Format::dateTime($m['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="post" action="<?= View::url($sendAction) ?>" class="thread-reply-form">
            <?= Core\Csrf::field() ?>
            <textarea name="body" required placeholder="<?= $isGroupThread ? __('Napisz do grupy…') : __('Napisz wiadomość…') ?>" rows="2"></textarea>
            <button type="submit" class="btn"><?= __('Wyślij') ?></button>
        </form>
        <?php endif; ?>
    </section>
</div>
