<?php
// views/web/pages/event-form-wizard.php
// Kreator dodawania wydarzenia wg szablony/dodaj-kreator.html — WYŁĄCZNIE
// dla /wydarzenia/nowe (Controllers\EventController::createForm()/create()).
// Edycja (editForm()/update()) zostaje przy dzisiejszym event-form.php, bez
// zmian — ten plik nigdy nie dostaje $isEdit=true.
//
// Oczekuje w scope: $initialState (Resources\EventFormResource), $billingComplete
// (bool), $dictOptions (jak w event-form.php), $error (?string).
//
// Architektura: JEDEN formularz <form>, JEDEN submit na końcu (krok
// "podsumowanie") — kroki to wyłącznie x-show w tym samym Alpine
// x-data='eventWizard(...)'. Ten plik to CIENKI orkiestrator: rama (x-data,
// pasek postępu, <form> + ukryte pola, nawigacja) + require kolejnych kroków
// z partials/wizard/*. Każdy krok = osobny plik, dzięki czemu dodanie/edycja
// typu dotyka jednego kroku, nie 1200-liniowego monolitu. Powtarzalny blok
// „nawierzchnia" (TRASA i PLAN) siedzi w jednym partials/wizard/gpx-surface.php.
// Wszystkie partiale dzielą ten sam scope zmiennych ($dictOptions, $initialState
// itd. — require wykonuje się w scope tego pliku) i ten sam komponent Alpine.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$isLoggedIn = Core\Auth::check();
$isAdmin = $isLoggedIn && Core\Auth::user()->isAdmin;

$regionLabelsByCode = [];
foreach ($dictOptions['regions'] as $group) {
    foreach ($group['items'] as $opt) {
        $regionLabelsByCode[$opt['code']] = $opt['name'];
    }
}
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<div class="k-page" x-data='eventWizard(<?= json_encode($initialState, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($billingComplete) ?>, <?= json_encode($isLoggedIn) ?>, <?= json_encode($isAdmin) ?>, <?= json_encode($regionLabelsByCode, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($error ?? null) ?>)' x-init="init()">

<?php require __DIR__ . '/../partials/wizard/type-picker.php'; ?>

<!-- ===== KREATOR ===== -->
<div x-show="wizardStarted" x-cloak>
    <div class="k-bar"><div class="k-bar__in">
        <p class="k-bar__t"><b x-text="__('Krok {a} z {b}', {a: stepIndex()+1, b: visibleSteps().length})"></b>
            <span x-text="isLastStep() ? __('— ostatni krok') : __('— zostało ok. {n} min', {n: estimatedMinutesLeft()})"></span>
            <span class="k-bar__ty"><i :style="'--bz:' + TYPE_META[type].color"></i><span x-text="TYPE_META[type].name"></span></span>
        </p>
        <div class="k-bar__p"><i :style="'width:' + progressPct() + '%'"></i></div>
    </div></div>

    <main class="k-wrap">
        <?php // id — pozwala przyciskowi "Opublikuj" w pasku nawigacji (nav.php,
              // renderowanym POZA tym <form>) wysłać ten formularz natywnie,
              // przez atrybut form="..." (HTML5 form owner). Bez tego przycisk
              // spoza formularza nic by nie wysłał. ?>
        <?php // @input/@change na formularzu, nie na pojedynczych polach: baner
              // błędu ma gasnąć przy KAŻDEJ poprawce, a pola, których dotyczy,
              // leżą w różnych krokach. Zdarzenia bąbelkują, więc jedno wiązanie
              // obsługuje cały kreator i nie trzeba pamiętać o nim przy każdym
              // nowym polu. ?>
        <form id="k-form" method="post" enctype="multipart/form-data" x-ref="form"
              @input="clearSubmitError()" @change="clearSubmitError()"
              action="<?= htmlspecialchars($formAction) ?>">
            <?= Core\Csrf::field() ?>
            <input type="hidden" name="existing_cover_photo_url" :value="coverPhotoUrl || ''">
            <input type="hidden" name="type" x-model="type">
            <input type="hidden" name="registration_type" x-model="registrationType">
            <input type="hidden" name="is_paid" :value="isPaid ? 1 : 0">
            <input type="hidden" name="limit_participants" :value="limitParticipants ? 1 : 0">
            <!-- Tempo/trudność wybiera się kafelkami (<button>, krok "Dla kogo"),
                 które ustawiają TYLKO stan Alpine — bez tych ukrytych pól nic nie
                 trafiało do POST-a i wartości nie zapisywały się przy dodawaniu
                 (zgłoszony bug). :value, bo zmieniają je przyciski, nie sam input.
                 Puste = null przy zapisie (Models\Dictionary::id ignoruje ''). -->
            <input type="hidden" name="pace" :value="pace">
            <input type="hidden" name="difficulty" :value="difficulty">

            <!-- Walidacja przed publikacją dzieje się po stronie klienta
                 (validateBeforeSubmit()) i przenosi do brakującego kroku BEZ
                 wysyłki — inaczej odrzucenie po stronie serwera przeładowuje
                 stronę i gubi wybrane zdjęcie okładki (plik <input> nie przetrwa
                 round-tripu, w odróżnieniu od GPX wgrywanego AJAX-em). Ten baner
                 pokazuje komunikat na kroku, do którego przeniesiono. -->
            <p class="form-error" x-show="submitError" x-cloak x-text="submitError" style="margin:0 0 18px;"></p>
            <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off">

            <?php require __DIR__ . '/../partials/wizard/step-kogo.php'; ?>
            <?php require __DIR__ . '/../partials/wizard/step-kiedy.php'; ?>
            <?php require __DIR__ . '/../partials/wizard/step-gdzie.php'; ?>
            <?php require __DIR__ . '/../partials/wizard/step-trasa.php'; ?>
            <?php require __DIR__ . '/../partials/wizard/step-plan.php'; ?>
            <?php require __DIR__ . '/../partials/wizard/step-oczym.php'; ?>
            <?php require __DIR__ . '/../partials/wizard/step-dlakogo.php'; ?>
            <?php require __DIR__ . '/../partials/wizard/step-pieniadze.php'; ?>
            <?php require __DIR__ . '/../partials/wizard/step-podsumowanie.php'; ?>
        </form>
    </main>

    <?php require __DIR__ . '/../partials/wizard/nav.php'; ?>
</div>

<?php require __DIR__ . '/../partials/wizard/map-modal.php'; ?>

</div>

<?php require __DIR__ . '/../partials/wizard/script.php'; ?>
