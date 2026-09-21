<?php
// views/web/pages/discussions.php
// Zbiorcza moderacja dyskusji — patrz Controllers\Admin\DiscussionController.
// Oczekuje: $user, $comments (EventComment::forModeration()), $q,
// $onlyUnanswered, $unansweredCount.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\Format;
use Utils\Icon;
use Utils\View;

$comments        = $comments ?? [];
$q               = $q ?? '';
$onlyUnanswered  = $onlyUnanswered ?? false;
$unansweredCount = $unansweredCount ?? 0;
$eventSlug       = $eventSlug ?? '';
$eventTitle      = $eventTitle ?? '';
$hasFilter       = $q !== '' || $onlyUnanswered || $eventSlug !== '';
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display" style="font-size:28px;"><?= __('Dyskusje') ?></h1>
<?php if ($eventSlug !== ''): ?>
<?php // Widok zawężony do jednego wydarzenia (wejście z wiersza w panelu) —
      // stan filtra MUSI być widoczny, inaczej pusta lista czyta się jako
      // "nie ma nic w całym serwisie" zamiast "nie ma nic w tym wyjeździe". ?>
<p class="desc spaced-below">
    <?= __('Dyskusja wydarzenia:') ?>

    <b><?= htmlspecialchars($eventTitle !== '' ? $eventTitle : $eventSlug) ?></b>
    · <a href="<?= View::url('/admin/dyskusje') ?>"><?= __('pokaż wszystkie dyskusje') ?></a>
</p>
<?php else: ?>
<p class="desc spaced-below">
    <?= $user->isAdmin
        ? __('Wszystkie pytania i odpowiedzi w serwisie — najnowsze u góry. Stąd moderujesz treści bez wchodzenia na każde wydarzenie osobno.')
        : __('Pytania i odpowiedzi pod Twoimi wydarzeniami — najnowsze u góry.') ?>
</p>
<?php endif; ?>

<div class="action-row">
    <a href="<?= View::url('/admin') ?>" class="btn btn-secondary"><?= __('← Wydarzenia') ?></a>
    <?php if ($unansweredCount > 0): ?>
    <a href="<?= View::url('/admin/dyskusje?bez_odpowiedzi=1') ?>" class="btn">
        <?= Icon::render('warning') ?> Bez odpowiedzi: <?= (int) $unansweredCount ?>
    </a>
    <?php endif; ?>
</div>

<?php // Filtry — serwerowe (GET), w odróżnieniu od panelu wydarzeń: tu lista
      // może realnie urosnąć (wszystkie komentarze w serwisie), więc zawężanie
      // musi dziać się w zapytaniu, nie w przeglądarce. ?>
<form class="dash-filters" method="get" action="<?= View::url('/admin/dyskusje') ?>">
    <?php // Wybrane wydarzenie przenosimy przez formularz, żeby szukanie i
          // "bez odpowiedzi" zawężały W JEGO OBRĘBIE, a nie wyrzucały z powrotem
          // na całą listę. ?>
    <?php if ($eventSlug !== ''): ?>
    <input type="hidden" name="event" value="<?= htmlspecialchars($eventSlug) ?>">
    <?php endif; ?>
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars(__('Szukaj w treści lub tytule wydarzenia…')) ?>" aria-label="<?= htmlspecialchars(__('Szukaj')) ?>">
    <label class="checkrow" style="margin:0;">
        <input type="checkbox" name="bez_odpowiedzi" value="1" <?= $onlyUnanswered ? 'checked' : '' ?>>
        <?= __('Tylko bez odpowiedzi') ?>

    </label>
    <button class="btn btn-secondary btn--sm" type="submit"><?= __('Filtruj') ?></button>
    <?php if ($hasFilter): ?>
    <a class="link-button" href="<?= View::url('/admin/dyskusje') ?>"><?= __('Wyczyść') ?></a>
    <?php endif; ?>
</form>

<?php if (empty($comments)): ?>
<p class="desc"><?php
    if ($eventSlug !== '' && $q === '' && !$onlyUnanswered) {
        echo __('Pod tym wydarzeniem nie ma jeszcze żadnych pytań.');
    } elseif ($hasFilter) {
        echo __('Nic nie pasuje do filtra.');
    } else {
        echo __('Nie ma jeszcze żadnych pytań ani odpowiedzi.');
    }
?></p>
<?php else: ?>
<div class="dash-list">
    <?php foreach ($comments as $c): ?>
    <article class="dash-item">
        <span class="dash-item__dot" style="--dot:<?= $c['needsAnswer'] ? 'var(--blaze-dark)' : 'var(--hair-strong)' ?>"
              title="<?= $c['needsAnswer'] ? __('Czeka na odpowiedź') : '' ?>"></span>

        <div class="dash-item__main">
            <div class="dash-item__meta" style="margin:0 0 5px;">
                <a class="dash-item__t" style="font-size:13px;" href="<?= View::url('/events/' . $c['eventSlug']) ?>#pytania"><?= htmlspecialchars($c['eventTitle']) ?></a>
                <span><?= htmlspecialchars(Format::dateTime($c['createdAt'])) ?></span>
                <?php if ($c['isReply']): ?><span class="dash-item__st"><?= __('odpowiedź') ?></span><?php endif; ?>
                <?php if ($c['isOrganizerReply']): ?><span class="comment-org-tag"><?= __('Organizator') ?></span><?php endif; ?>
                <?php if ($c['isFaq']): ?><span class="qa-faq-tag"><?= __('Częste pytanie') ?></span><?php endif; ?>
                <?php if ($c['needsAnswer']): ?><span class="dash-item__soon"><?= __('czeka na odpowiedź') ?></span><?php endif; ?>
            </div>
            <div class="mod-body"><?= nl2br(htmlspecialchars($c['body'])) ?></div>
            <div class="dash-item__sub"><?= htmlspecialchars($c['authorName']) ?></div>
        </div>

        <div class="dash-item__act">
            <a class="iconbtn" href="<?= View::url('/events/' . $c['eventSlug']) ?>#pytania"
               title="<?= htmlspecialchars(__('Otwórz dyskusję na stronie wydarzenia')) ?>" aria-label="<?= htmlspecialchars(__('Otwórz dyskusję')) ?>"><?= Icon::render('eye') ?></a>
            <?php // Kasowanie idzie przez ISTNIEJĄCĄ trasę moderacji — cała
                  // kontrola uprawnień zostaje w jednym miejscu
                  // (CommentController::delete + requireEventEditPermission),
                  // zamiast drugiej, równoległej ścieżki do utrzymania. ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $c['eventSlug'] . '/komentarz/' . $c['id'] . '/usun') ?>"
                  onsubmit="return confirm(<?= htmlspecialchars(json_encode(($c['isReply'] ? __('Usunąć tę odpowiedź?') : __('Usunąć to pytanie wraz ze wszystkimi odpowiedziami pod nim?')) . ' ' . __('Tej operacji nie można cofnąć.'), JSON_UNESCAPED_UNICODE)) ?>);">
                <?= Csrf::field() ?>
                <input type="hidden" name="back" value="panel">
                <button type="submit" class="iconbtn is-danger" title="<?= htmlspecialchars(__('Usuń')) ?>" aria-label="<?= htmlspecialchars(__('Usuń')) ?>"><?= Icon::render('close') ?></button>
            </form>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
