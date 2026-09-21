<?php
// views/web/pages/dashboard.php
// Panel "Moje wydarzenia" (organizator) / "Wszystkie wydarzenia" (admin).
// Oczekuje: $user (Models\User), $events (Models\Event::forDashboard()).
//
// PRZEBUDOWA 2026-08-09 (wariant B uzgodniony z userem). Poprzednia wersja
// była płaskim spisem 5 kolumn z akcjami jako linkami TEKSTOWYMI (do 4 na
// wiersz — przy 30 wydarzeniach 120 linków konkurujących o uwagę) i dwoma
// martwymi licznikami u góry. Zmiany:
//   1) pasek TRIAGE — klikalne kafelki ustawiające filtr, zamiast statystyk
//      bez akcji; odpowiada na "czy coś wymaga mojej uwagi?", nie "co mam";
//   2) wiersz dwustrefowy — kropka statusu + tytuł + meta (termin, "za N dni",
//      pasek zapełnienia, sygnał płatności) po lewej, akcje po prawej;
//   3) akcje: IKONY dla powtarzalnych (podgląd/edycja/uczestnicy), menu "⋯"
//      dla rzadkich i DESTRUKCYJNYCH (odwołaj/usuń/odrzuć/link przejęcia) —
//      goła ikona kosza obok ikony oka przy 30 wierszach to gotowy przepis
//      na przypadkowe kliknięcie (zastrzeżenie UI, przyjęte przez usera);
//   4) sortowanie (termin/status/zapisy).
// ŚWIADOMIE NIE zrobione (wariant C): paginacja serwerowa i osobne widoki
// admin/organizator — przy dzisiejszej skali (kilkadziesiąt wydarzeń) to
// przedwczesna optymalizacja; filtrowanie/sortowanie zostaje po stronie
// przeglądarki na już wyrenderowanych wierszach.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\Icon;
use Utils\View;
use Core\Csrf;

$statusLabels = [
    'draft'                => __('Szkic'),
    'published'            => __('Opublikowane'),
    'full'                 => __('Komplet'),
    'cancelled'            => __('Odwołane'),
    'completed'            => __('Zakończone'),
    'oczekuje_weryfikacji' => __('Oczekuje weryfikacji'),
];
// Kolor kropki statusu — ten sam kod barw co reszta serwisu (--s-* / --danger).
$statusColors = [
    'draft'                => 'var(--ink-mute)',
    'published'            => 'var(--s-green)',
    'full'                 => 'var(--s-blue)',
    'cancelled'            => 'var(--danger)',
    'completed'            => 'var(--ink-mute)',
    'oczekuje_weryfikacji' => 'var(--blaze-dark)',
];
$claimLink = $_GET['claim_link'] ?? null;
$today = new DateTimeImmutable('today');
// Licznik przy linku "Dyskusje" — ile pytań czeka na odpowiedź (patrz
// Controllers\Admin\DiscussionController). Domyślka, gdyby widok wyrenderowano
// bez tej zmiennej.
$unansweredCount = $unansweredCount ?? 0;

// Liczniki paska triage. Liczone raz, tu — widok nie robi zapytań, wszystko
// pochodzi z jednego Event::forDashboard() (patrz pending_payments_count tam).
$counts = ['payments' => 0, 'soon' => 0, 'draft' => 0, 'verify' => 0, 'unclaimed' => 0];
$rows = [];
foreach ($events as $ev) {
    $status = $ev['status_code'];
    $isActive = in_array($status, ['published', 'full'], true);
    $pending = (int) ($ev['pending_payments_count'] ?? 0);

    // "za N dni" — organizator myśli w kategoriach "ile mam czasu", nie w
    // kalendarzu; null dla wydarzeń, które już się odbyły/nie są aktywne.
    $daysUntil = null;
    if ($ev['start_date']) {
        $diff = $today->diff(new DateTimeImmutable($ev['start_date']));
        $daysUntil = (int) $diff->format('%r%a');
    }
    $isSoon = $isActive && $daysUntil !== null && $daysUntil >= 0 && $daysUntil <= 7;

    if ($pending > 0)                      $counts['payments'] += $pending;
    if ($isSoon)                           $counts['soon']++;
    if ($status === 'draft')               $counts['draft']++;
    if ($status === 'oczekuje_weryfikacji') $counts['verify']++;
    if (!empty($ev['organizer_unclaimed'])) $counts['unclaimed']++;

    $rows[] = $ev + ['_daysUntil' => $daysUntil, '_isSoon' => $isSoon, '_pending' => $pending, '_isActive' => $isActive];
}

// Kafelki triage — różne dla organizatora i admina (te same dane, inne
// pytania: organizator pyta o pieniądze i terminy, admin o moderację).
$tiles = [
    ['key' => 'payments',  'label' => __('Czeka na wpłatę'),  'n' => $counts['payments'],  'icon' => 'coins',    'admin' => false],
    ['key' => 'soon',      'label' => __('Startuje w 7 dni'), 'n' => $counts['soon'],      'icon' => 'calendar', 'admin' => false],
    ['key' => 'draft',     'label' => __('Szkice'),           'n' => $counts['draft'],     'icon' => 'edit',     'admin' => false],
    ['key' => 'verify',    'label' => __('Do weryfikacji'),   'n' => $counts['verify'],    'icon' => 'warning',  'admin' => true],
    ['key' => 'unclaimed', 'label' => __('Nieprzejęte konta'),'n' => $counts['unclaimed'], 'icon' => 'users',    'admin' => true],
];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display" style="font-size:28px;"><?= $user->isAdmin ? __('Wszystkie wydarzenia') : __('Moje wydarzenia') ?></h1>

<div class="action-row">
    <a href="<?= View::url('/wydarzenia/nowe') ?>" class="btn"><?= __('+ Dodaj wydarzenie') ?></a>
    <?php // Bramka roli (2026-08-13): panel organizatora widzi wyłącznie ten,
          // kto ma profil organizatora (Models\Organizer::hasProfile). Zwykły
          // rowerzysta trafiał tu z menu i dostawał ekran o płatnościach
          // i profilu rozliczeniowym, których nigdy nie będzie potrzebował. ?>
    <?php if (Models\Organizer::hasProfile($user->id)): ?>
    <a href="<?= View::url('/admin/platnosci') ?>" class="btn btn-secondary"><?= __('Płatności') ?></a>
    <a href="<?= View::url('/admin/dyskusje') ?>" class="btn btn-secondary">Dyskusje<?= $unansweredCount > 0 ? ' (' . (int) $unansweredCount . ')' : '' ?></a>
    <a href="<?= View::url('/admin/profil-rozliczeniowy') ?>" class="btn btn-secondary"><?= __('Profil organizatora') ?></a>
    <?php endif; ?>
    <a href="<?= View::url('/admin/moje-konto') ?>" class="btn btn-secondary"><?= __('Moje konto') ?></a>
    <?php // Panel dotyczy wyłącznie roli ORGANIZATORA; własny profil rowerzysty
          // (peleton, przejechane wyjazdy, kroniki) nie miał stąd żadnego wejścia. ?>
    <a href="<?= View::url('/admin/moje-przejazdy') ?>" class="btn btn-secondary"><?= __('Moje przejazdy') ?></a>
    <?php if ($user->publicSlug): ?>
    <a href="<?= View::url('/rowerzysta/' . $user->publicSlug) ?>" class="btn btn-secondary"><?= __('Mój profil rowerzysty') ?></a>
    <?php endif; ?>
    <?php if ($user->isAdmin): ?>
    <a href="<?= View::url('/admin/organizatorzy') ?>" class="btn btn-secondary"><?= __('Organizatorzy') ?></a>
    <a href="<?= View::url('/admin/taksonomia') ?>" class="btn btn-secondary"><?= __('Taksonomia') ?></a>
    <a href="<?= View::url('/admin/znane-trasy') ?>" class="btn btn-secondary"><?= __('Znane trasy') ?></a>
    <a href="<?= View::url('/admin/regiony-mapa') ?>" class="btn btn-secondary"><?= __('Regiony na mapie') ?></a>
    <a href="<?= View::url('/admin/punkty') ?>" class="btn btn-secondary"><?= __('Punkty i bonusy') ?></a>
    <a href="<?= View::url('/admin/powiadomienia') ?>" class="btn btn-secondary"><?= __('Powiadomienia') ?></a>
    <a href="<?= View::url('/admin/importer') ?>" class="btn btn-secondary"><?= __('Importer wydarzeń') ?></a>
    <?php endif; ?>
</div>

<?php if ($claimLink): ?>
<div class="claim-link-box">
    <div class="claim-link-label"><?= __('Link do przejęcia profilu — wyślij go organizatorowi (WhatsApp, mail, telefon...). Ważny bezterminowo, do pierwszego kliknięcia.') ?></div>
    <input type="text" readonly value="<?= htmlspecialchars($claimLink) ?>" onclick="this.select()">
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
<p class="desc"><?= __('Brak wydarzeń. Dodaj pierwsze powyżej.') ?></p>
<?php else: ?>

<?php // Pasek triage — kafelek z zerem jest wyszarzony i nieklikalny (nie ma
      // czego filtrować), żeby nie obiecywał akcji, której nie ma. ?>
<div class="triage">
    <?php foreach ($tiles as $t): ?>
    <?php if ($t['admin'] && !$user->isAdmin) continue; ?>
    <button type="button" class="triage__t<?= $t['n'] === 0 ? ' is-empty' : '' ?>"
            data-triage="<?= $t['key'] ?>" <?= $t['n'] === 0 ? 'disabled' : '' ?>
            aria-pressed="false">
        <span class="triage__i"><?= Icon::render($t['icon']) ?></span>
        <span class="triage__n"><?= (int) $t['n'] ?></span>
        <span class="triage__l"><?= htmlspecialchars($t['label']) ?></span>
    </button>
    <?php endforeach; ?>
</div>

<div class="dash-filters">
    <input type="search" id="dashSearch" placeholder="<?= htmlspecialchars(__('Szukaj po tytule…')) ?>" aria-label="<?= htmlspecialchars(__('Szukaj po tytule')) ?>">
    <?php // Wielokrotny wybór statusu (zgłoszenie usera 2026-08-09) — <details>
          // z checkboxami zamiast <select multiple>, który na telefonie jest
          // praktycznie nieobsługiwalny (wymaga przytrzymania Ctrl/⌘), a na
          // desktopie nie pokazuje, co jest zaznaczone, bez rozwijania. Ten sam
          // wzorzec co menu ⋯ w wierszu: natywne, dostępne z klawiatury, bez JS-a
          // do samego otwierania. Brak zaznaczeń = wszystkie statusy. ?>
    <details class="multisel" id="dashStatusBox">
        <summary class="multisel__s"><span id="dashStatusLabel"><?= __('Wszystkie statusy') ?></span></summary>
        <div class="multisel__p">
            <?php foreach ($statusLabels as $code => $label): ?>
            <label class="multisel__o">
                <input type="checkbox" class="dash-status-cb" value="<?= htmlspecialchars($code) ?>">
                <span class="dash-item__dot" style="--dot:<?= $statusColors[$code] ?? 'var(--ink-mute)' ?>;margin:0"></span>
                <?= htmlspecialchars($label) ?>
            </label>
            <?php endforeach; ?>
            <button type="button" class="multisel__clear" id="dashStatusClear"><?= __('Wyczyść wybór') ?></button>
        </div>
    </details>
    <select id="dashSort" aria-label="<?= htmlspecialchars(__('Sortowanie')) ?>">
        <option value="date"><?= __('Wg terminu') ?></option>
        <option value="signups"><?= __('Wg liczby zapisów') ?></option>
        <option value="payments"><?= __('Wg oczekujących wpłat') ?></option>
        <option value="status"><?= __('Wg statusu') ?></option>
        <option value="title"><?= __('Alfabetycznie') ?></option>
    </select>
    <span id="dashFilterCount" class="dash-filter-count"></span>
</div>

<div class="dash-list" id="dashTable">
    <?php foreach ($rows as $ev): ?>
    <?php
    $status   = $ev['status_code'];
    $isPending = $status === 'oczekuje_weryfikacji';
    // Ślad pochodzenia z importera wydarzeń (Models\EventImport → custom_attributes).
    $prov = !empty($ev['custom_attributes']) ? json_decode((string) $ev['custom_attributes'], true) : null;
    $fromImport = is_array($prov) && ($prov['source'] ?? null) === 'ridemore-importer';
    $max      = $ev['max_participants'] !== null ? (int) $ev['max_participants'] : null;
    $confirmed = (int) $ev['confirmed_count'];
    $fillPct  = ($max && $max > 0) ? min(100, (int) round($confirmed / $max * 100)) : null;
    // "Usuń" (trwałe) — szkice zawsze, reszta tylko bez ani jednego zapisu
    // (patrz EventController::delete()). "Odwołaj" niezależnie dla aktywnych.
    $canDelete = $status === 'draft' || empty($ev['has_any_rsvp']);
    $canCancel = in_array($status, ['published', 'full'], true);
    ?>
    <article class="dash-item"
             data-status="<?= htmlspecialchars($status) ?>"
             data-title="<?= htmlspecialchars(mb_strtolower($ev['title'])) ?>"
             data-date="<?= htmlspecialchars((string) $ev['start_date']) ?>"
             data-signups="<?= $confirmed ?>"
             data-payments="<?= $ev['_pending'] ?>"
             data-awaiting="<?= (int) $ev['payments_awaiting_count'] ?>"
             data-soon="<?= $ev['_isSoon'] ? 1 : 0 ?>"
             data-draft="<?= $status === 'draft' ? 1 : 0 ?>"
             data-verify="<?= $isPending ? 1 : 0 ?>"
             data-unclaimed="<?= !empty($ev['organizer_unclaimed']) ? 1 : 0 ?>">

        <span class="dash-item__dot" style="--dot:<?= $statusColors[$status] ?? 'var(--ink-mute)' ?>"
              title="<?= htmlspecialchars($statusLabels[$status] ?? $status) ?>"></span>

        <div class="dash-item__main">
            <a class="dash-item__t" href="<?= View::url('/wydarzenia/' . $ev['slug'] . '/edytuj') ?>"><?= htmlspecialchars($ev['title']) ?></a>

            <div class="dash-item__meta">
                <span class="dash-item__st"><?= htmlspecialchars($statusLabels[$status] ?? $status) ?></span>
                <?php if ($fromImport): ?>
                <span class="dash-item__import" title="<?= htmlspecialchars(__('Kandydat dodany automatycznie przez importer — dane maszynowe, sprawdź przed publikacją')) ?>"><?= __('z importu') ?></span>
                <?php endif; ?>
                <?php if ($ev['start_date']): ?>
                <span><?= htmlspecialchars(Format::dateShort($ev['start_date'])) ?></span>
                <?php if ($ev['_isSoon']): ?>
                <span class="dash-item__soon"><?= $ev['_daysUntil'] === 0 ? __('dziś!') : __('za {n} dni', ['n' => $ev['_daysUntil']]) ?></span>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($fillPct !== null): ?>
                <span class="dash-item__fill" title="<?= htmlspecialchars(__('{a} z {b} miejsc', ['a' => $confirmed, 'b' => $max])) ?>">
                    <i style="width:<?= $fillPct ?>%"></i><b><?= $confirmed ?>/<?= $max ?></b>
                </span>
                <?php elseif ($confirmed > 0): ?>
                <span><?= __('{n} zapisanych', ['n' => $confirmed]) ?></span>
                <?php endif; ?>

            </div>

            <?php if ($user->isAdmin): ?>
            <div class="dash-item__sub"><?= htmlspecialchars($ev['name'] ?: $ev['email']) ?><?= !empty($ev['organizer_unclaimed']) ? ' · ' . __('konto nieprzejęte') : '' ?></div>
            <?php endif; ?>
            <?php if ($isPending && ($ev['submitter_name'] || $ev['submitter_email'])): ?>
            <div class="dash-item__sub"><?= __('Zgłosił(a):') ?> <?= htmlspecialchars($ev['submitter_name'] ?: '—') ?><?= $ev['submitter_email'] ? ' (' . htmlspecialchars($ev['submitter_email']) . ')' : '' ?></div>
            <?php endif; ?>
            <?php if ($isPending && $fromImport): ?>
            <div class="dash-item__sub dash-item__prov">
                <?php if (!empty($prov['source_url'])): ?>
                <a href="<?= htmlspecialchars($prov['source_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= __('źródło') ?></a>
                <?php endif; ?>
                <?php if (!empty($prov['confidence'])): ?>· <?= __('pewność: {p}', ['p' => htmlspecialchars($prov['confidence'])]) ?><?php endif; ?>
                <?php if (!empty($prov['duplicate_of'])): ?>
                · <a class="dash-item__dup" href="<?= View::url('/events/' . $prov['duplicate_of']) ?>" target="_blank" rel="noopener noreferrer"><?= __('⚠ możliwy duplikat') ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php // KOLUMNA "Płatności" (zgłoszenie usera 2026-08-09) — stała
              // szerokość i to samo miejsce w każdym wierszu, więc mimo układu
              // kartowego czyta się jak kolumna tabeli. Tylko dla wydarzeń
              // PŁATNYCH: przy darmowych "0 opłaconych" byłoby mylące (nie ma
              // czego opłacać), więc pokazujemy myślnik, żeby nie rozjechać
              // pionowego rytmu listy. ?>
        <div class="dash-item__pay">
            <?php if (empty($ev['is_paid_event'])): ?>
            <span class="dash-pay__free" title="<?= htmlspecialchars(__('Wydarzenie bezpłatne')) ?>">—</span>
            <?php else: ?>
            <span class="dash-pay__ok" title="<?= htmlspecialchars(__('Płatności potwierdzone w całości')) ?>">
                <?= Icon::render('check') ?><?= __('{n} opłacone', ['n' => (int) $ev['payments_confirmed_count']]) ?>

            </span>
            <?php $awaiting = (int) $ev['payments_awaiting_count']; ?>
            <?php if ($awaiting > 0): ?>
            <a class="dash-pay__wait" href="<?= View::url('/wydarzenia/' . $ev['slug'] . '/uczestnicy') ?>"
               title="<?= htmlspecialchars(__('Czeka na potwierdzenie wpłaty — kliknij, żeby przejść do uczestników')) ?>">
                <?= Icon::render('coins') ?><?= __('{n} czeka', ['n' => $awaiting]) ?>

            </a>
            <?php else: ?>
            <span class="dash-pay__none"><?= __('nic nie czeka') ?></span>
            <?php endif; ?>
            <?php if ((int) $ev['refunds_awaiting_count'] > 0): ?>
            <a class="dash-pay__wait" href="<?= View::url('/admin/platnosci') ?>" title="<?= htmlspecialchars(__('Oczekujące zwroty')) ?>">
                <?= Icon::render('warning') ?><?= __('{n} do zwrotu', ['n' => (int) $ev['refunds_awaiting_count']]) ?>

            </a>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="dash-item__act">
            <?php // Akcja GŁÓWNA, kontekstowa — tylko tam, gdzie coś realnie
                  // czeka na decyzję; w pozostałych wierszach same ikony. ?>
            <?php if ($isPending): ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $ev['slug'] . '/zatwierdz') ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--sm"><?= __('Zatwierdź') ?></button>
            </form>
            <?php endif; ?>

            <?php // DOKŁADNIE TRZY ikony w każdym wierszu (zgłoszenie usera
                  // 2026-08-09: "czasem masz 4 ikonki czasem 3"). Źródłem
                  // nierówności była ikona "Uczestnicy", której NIE MA przy
                  // szkicach — przeniesiona do menu ⋯, gdzie jej brak nie
                  // rozjeżdża rytmu kolumny. Zostają trzy zawsze dostępne:
                  // podgląd, edycja, menu. ?>
            <a class="iconbtn" href="<?= View::url('/events/' . $ev['slug']) ?>" target="_blank" rel="noopener"
               title="<?= htmlspecialchars(__('Podgląd strony wydarzenia')) ?>" aria-label="<?= htmlspecialchars(__('Podgląd strony wydarzenia')) ?>"><?= Icon::render('eye') ?></a>
            <a class="iconbtn" href="<?= View::url('/wydarzenia/' . $ev['slug'] . '/edytuj') ?>"
               title="<?= htmlspecialchars(__('Edytuj wydarzenie')) ?>" aria-label="<?= htmlspecialchars(__('Edytuj wydarzenie')) ?>"><?= Icon::render('edit') ?></a>

            <?php // Menu "⋯" — renderowane ZAWSZE (nawet dla szkicu, gdzie ma
                  // tylko "Usuń"), żeby trzecia pozycja nigdy nie znikała.
                  // <details> zamiast JS-a: natywne, dostępne z klawiatury. ?>
            <details class="rowmenu">
                <summary class="iconbtn" title="<?= htmlspecialchars(__('Więcej działań')) ?>" aria-label="<?= htmlspecialchars(__('Więcej działań')) ?>"><?= Icon::render('more') ?></summary>
                <div class="rowmenu__p">
                    <?php if ($status !== 'draft'): ?>
                    <a class="rowmenu__a" href="<?= View::url('/wydarzenia/' . $ev['slug'] . '/uczestnicy') ?>"><?= __('Uczestnicy i wpłaty') ?></a>
                    <?php endif; ?>
                    <?php // Moderacja dyskusji TEGO wydarzenia — prowadzi do
                          // panelu (/admin/dyskusje?event=slug), nie na publiczną
                          // stronę wydarzenia: moderujący ma zostać w narzędziu,
                          // w którym pracuje, i mieć od razu filtry pod ręką. ?>
                    <a class="rowmenu__a" href="<?= View::url('/admin/dyskusje?event=' . urlencode($ev['slug'])) ?>">
                        <?= __('Dyskusja i pytania') ?><?php if ((int) $ev['comments_unanswered'] > 0): ?>
                        <span class="rowmenu__badge"><?= (int) $ev['comments_unanswered'] ?> bez odp.</span>
                        <?php elseif ((int) $ev['comments_count'] > 0): ?>
                        <span class="rowmenu__count"><?= (int) $ev['comments_count'] ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if ($isPending): ?>
                    <form method="post" action="<?= View::url('/wydarzenia/' . $ev['slug'] . '/odrzuc') ?>" onsubmit="return confirm(__('Odrzucić to zgłoszenie? Zostanie trwale usunięte.'));">
                        <?= Csrf::field() ?>
                        <button type="submit" class="rowmenu__a is-danger"><?= __('Odrzuć zgłoszenie') ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($user->isAdmin && !empty($ev['organizer_unclaimed'])): ?>
                    <form method="post" action="<?= View::url('/wydarzenia/' . $ev['slug'] . '/link-przejecia') ?>">
                        <?= Csrf::field() ?>
                        <button type="submit" class="rowmenu__a"><?= __('Link do przejęcia profilu') ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canCancel): ?>
                    <form method="post" action="<?= View::url('/wydarzenia/' . $ev['slug'] . '/odwolaj') ?>" onsubmit="return confirm(__('Odwołać to wydarzenie? Zapisani uczestnicy zobaczą status odwołane.'));">
                        <?= Csrf::field() ?>
                        <button type="submit" class="rowmenu__a"><?= __('Odwołaj wydarzenie') ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                    <form method="post" action="<?= View::url('/wydarzenia/' . $ev['slug'] . '/usun') ?>" onsubmit="return confirm(__('Usunąć na stałe? Tej operacji nie można cofnąć.'));">
                        <?= Csrf::field() ?>
                        <button type="submit" class="rowmenu__a is-danger"><?= __('Usuń na stałe') ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<p id="dashNoResults" class="desc" hidden><?= __('Żadne wydarzenie nie pasuje do filtra.') ?></p>

<script>
(function () {
    var search = document.getElementById('dashSearch');
    var statusBoxes = Array.prototype.slice.call(document.querySelectorAll('.dash-status-cb'));
    var statusLabelEl = document.getElementById('dashStatusLabel');
    var statusClear = document.getElementById('dashStatusClear');
    var sortSel = document.getElementById('dashSort');
    var list = document.getElementById('dashTable');
    var items = Array.prototype.slice.call(list.querySelectorAll('.dash-item'));
    var countEl = document.getElementById('dashFilterCount');
    var noResultsEl = document.getElementById('dashNoResults');
    var tiles = Array.prototype.slice.call(document.querySelectorAll('[data-triage]'));
    var activeTriage = null;

    // Zaznaczone statusy; pusta lista = BRAK zawężenia (wszystkie).
    function selectedStatuses() {
        return statusBoxes.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    }

    function apply() {
        var q = (search.value || '').trim().toLowerCase();
        var statuses = selectedStatuses();
        var visible = 0;
        items.forEach(function (el) {
            var okQ = !q || (el.dataset.title || '').indexOf(q) !== -1;
            var okStatus = statuses.length === 0 || statuses.indexOf(el.dataset.status) !== -1;
            // Kafelek triage zawęża do wierszy z niezerowym sygnałem danego typu.
            var okTriage = !activeTriage || (parseInt(el.dataset[activeTriage] || '0', 10) > 0);
            var show = okQ && okStatus && okTriage;
            el.hidden = !show;
            if (show) visible++;
        });

        // Etykieta zwijanej listy musi mówić, co jest wybrane, BEZ rozwijania —
        // to główna przewaga nad <select multiple> (patrz komentarz w HTML).
        statusLabelEl.textContent = statuses.length === 0
            ? __('Wszystkie statusy')
            : (statuses.length === 1
                ? statusBoxes.filter(function (c) { return c.checked; })[0].parentElement.textContent.trim()
                : 'Statusy: ' + statuses.length);

        countEl.textContent = (q || statuses.length || activeTriage) ? (visible + ' z ' + items.length) : '';
        noResultsEl.hidden = visible > 0;
    }

    function sortList() {
        var mode = sortSel.value;
        var sorted = items.slice().sort(function (a, b) {
            if (mode === 'signups') return (+b.dataset.signups) - (+a.dataset.signups);
            if (mode === 'payments') return (+b.dataset.awaiting) - (+a.dataset.awaiting);
            if (mode === 'title') return (a.dataset.title || '').localeCompare(b.dataset.title || '', 'pl');
            if (mode === 'status') return (a.dataset.status || '').localeCompare(b.dataset.status || '');
            return (a.dataset.date || '').localeCompare(b.dataset.date || ''); // termin rosnąco
        });
        sorted.forEach(function (el) { list.appendChild(el); });
    }

    search.addEventListener('input', apply);
    statusBoxes.forEach(function (c) { c.addEventListener('change', apply); });
    statusClear.addEventListener('click', function () {
        statusBoxes.forEach(function (c) { c.checked = false; });
        apply();
    });
    sortSel.addEventListener('change', sortList);
    tiles.forEach(function (t) {
        t.addEventListener('click', function () {
            var key = t.dataset.triage;
            activeTriage = (activeTriage === key) ? null : key; // ponowny klik = wyłącz
            tiles.forEach(function (o) { o.setAttribute('aria-pressed', String(o.dataset.triage === activeTriage)); });
            apply();
        });
    });

    // Tylko jedno menu "⋯" otwarte naraz — inaczej przy 30 wierszach łatwo
    // zostawić kilka otwartych i kliknąć nie w to, co się myśli.
    list.addEventListener('toggle', function (e) {
        var d = e.target;
        if (d.tagName === 'DETAILS' && d.open) {
            list.querySelectorAll('details.rowmenu[open]').forEach(function (o) { if (o !== d) o.open = false; });
        }
    }, true);
})();
</script>
<?php endif; ?>
