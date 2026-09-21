<?php
// views/web/pages/event-form.php
// Oczekuje w scope: $initialState (Resources\EventFormResource), $billingComplete
// (bool), $dictOptions (['difficulties','paces','bikeTypes','regions','surfaces',
// 'accommodationTypes','mealTypes','currencies','priceUnits'] => Dictionary::items()),
// $error (?string, patrz partials/form-error.php), $isEdit (bool).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$isEdit = $isEdit ?? false;
$isLoggedIn = Core\Auth::check();
// Admin publikuje/szkicuje od razu niezależnie od wybranego organizatora
// (patrz $isSelfOrAdmin w web/routes.php) — bez tego przycisk mylnie pokazywał
// "Zgłoś do weryfikacji" nawet gdy zgłoszenie faktycznie publikowało się
// natychmiast.
$isAdmin = $isLoggedIn && Core\Auth::user()->isAdmin;
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<div class="eyebrow"><?= $isEdit ? __('Edycja wydarzenia') : __('Nowe wydarzenie') ?></div>
<h1 class="display" style="font-size:32px;"><?= $isEdit ? __('Edytuj wydarzenie') : __('Dodaj wydarzenie') ?></h1>

<?php require __DIR__ . '/../partials/form-error.php'; ?>

<?php
// Kod -> nazwa regionu, do budowy propozycji tytułu ("Bieszczady, 9-10
// sierpnia") w JS bez dodatkowego zapytania do serwera — Dictionary::items()
// i tak już zwraca to samo w $dictOptions['regions'], tylko pogrupowane.
$regionLabelsByCode = [];
foreach ($dictOptions['regions'] as $group) {
    foreach ($group['items'] as $opt) {
        $regionLabelsByCode[$opt['code']] = $opt['name'];
    }
}
?>
<div x-data='eventForm(<?= json_encode($initialState, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($billingComplete) ?>, <?= json_encode($isLoggedIn) ?>, <?= json_encode($isAdmin) ?>, <?= json_encode($regionLabelsByCode, JSON_UNESCAPED_UNICODE) ?>)' x-init="init()">
<form method="post" enctype="multipart/form-data" x-ref="form" action="<?= htmlspecialchars($formAction) ?>">
    <?= Core\Csrf::field() ?>
    <input type="hidden" name="existing_cover_photo_url" :value="coverPhotoUrl || ''">
    <input type="hidden" name="type" x-model="type">
    <input type="hidden" name="registration_type" x-model="registrationType">
    <input type="hidden" name="is_paid" :value="isPaid ? 1 : 0">
    <input type="hidden" name="limit_participants" :value="limitParticipants ? 1 : 0">
    <!-- Pułapka na boty — pole ukryte CSS-em, niewidoczne dla człowieka; jeśli
         przyjdzie wypełnione, serwer po cichu odrzuca zgłoszenie. -->
    <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off">

    <?php if (!$isEdit): ?>
    <!-- 0. Kogo dotyczy wydarzenie — nie dotyczy pokrec_z_kims (zgłaszający
         JEST właścicielem swojego ogłoszenia, patrz EventController::create()).
         x-if (nie x-show) — usuwa pola z DOM-u, żeby ukryta sekcja nie
         dokładała organizer_mode/submitter_* do zapisu tego typu. -->
    <template x-if="type!=='pokrec_z_kims'">
    <div class="form-section">
        <h3><?= __('Kogo dotyczy to wydarzenie?') ?></h3>
        <input type="hidden" name="organizer_mode" x-model="organizerMode">
        <input type="hidden" name="organizer_id" :value="organizerId">
        <input type="hidden" name="new_organizer_name" :value="organizerSearchQuery">

        <?php if ($isLoggedIn): ?>
        <label class="checkbox-opt" style="margin-bottom:14px;">
            <input type="checkbox" :checked="organizerMode==='self'" @change="organizerMode = $event.target.checked ? 'self' : 'existing'">
            <?= __('To ja jestem organizatorem') ?>

        </label>
        <?php endif; ?>

        <div x-show="organizerMode!=='self'">
            <div class="form-row" x-show="!organizerConfirmed">
                <div class="form-field organizer-search" @click.outside="showOrganizerResults=false">
                    <label><?= __('Organizator') ?></label>
                    <input type="text" x-model="organizerSearchQuery" @input.debounce.300ms="searchOrganizers()" @focus="showOrganizerResults=true" placeholder="<?= htmlspecialchars(__('Zacznij pisać nazwę lub e-mail organizatora...')) ?>">
                    <div class="organizer-search-results" x-show="showOrganizerResults && organizerSearchQuery.trim().length >= 2">
                        <template x-for="r in organizerSearchResults" :key="r.id">
                            <div class="organizer-search-result" @click="selectOrganizer(r)" x-text="r.label"></div>
                        </template>
                        <div class="organizer-search-result organizer-search-new" @click="selectNewOrganizer()">
                            <?= __('+ Dodaj „') ?><span x-text="organizerSearchQuery"></span><?= __('” jako nowego organizatora') ?>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Jawne potwierdzenie wyboru zamiast zostawiania tego do odczytania
                 z treści pola — łatwo pomylić "wybrałem istniejącego" z "dodaję
                 nowego", skoro oba zostawiają podobny tekst w tym samym polu. -->
            <div class="organizer-selected" x-show="organizerConfirmed">
                <span x-show="organizerMode==='existing'"><?= __('Wybrany organizator:') ?> <b x-text="organizerSearchQuery"></b></span>
                <span x-show="organizerMode==='new'"><?= __('Nowy organizator (założymy dla niego profil):') ?> <b x-text="'„' + organizerSearchQuery + '”'"></b></span>
                <button type="button" class="link-button" @click="changeOrganizer()"><?= __('Zmień') ?></button>
            </div>

            <div x-show="organizerMode==='new' && organizerConfirmed" class="dependent-note">
                <div class="form-row">
                    <div class="form-field"><label><?= __('E-mail organizatora (do kontaktu w sprawie przejęcia profilu)') ?></label><input type="email" name="new_organizer_email" x-model="newOrganizerEmail" placeholder="<?= htmlspecialchars(__('kontakt@...')) ?>"></div>
                </div>
                <div class="hint"><?= __('Dostaną od Ciebie link, którym będą mogli przejąć profil.') ?></div>
            </div>

            <div class="dependent-note">
                <div class="hint" style="margin-bottom:10px;"><?= __('To zgłoszenie trafi do weryfikacji, zanim będzie publicznie widoczne.') ?></div>
                <div class="form-row" x-show="!isLoggedIn">
                    <div class="form-field"><label><?= __('Twoje imię (do kontaktu)') ?></label><input type="text" name="submitter_name" x-model="submitterName"></div>
                    <div class="form-field"><label><?= __('Twój e-mail') ?></label><input type="email" name="submitter_email" x-model="submitterEmail" :required="!isLoggedIn"></div>
                </div>
            </div>
        </div>
    </div>
    </template>

    <!-- Kontakt zgłaszającego dla pokrec_z_kims — bez organizatora/osoby
         trzeciej: zalogowany nie potrzebuje niczego (organizer_id = jego
         własne id, patrz EventController::create()), gość podaje tylko swój
         e-mail (do powiadomienia o publikacji po weryfikacji). -->
    <template x-if="type==='pokrec_z_kims' && !isLoggedIn">
    <div class="form-section">
        <h3><?= __('Twój kontakt') ?></h3>
        <div class="hint" style="margin-bottom:10px;"><?= __('Bez konta zgłoszenie trafi do krótkiej weryfikacji, zanim będzie publicznie widoczne — o publikacji poinformujemy Cię e-mailem.') ?></div>
        <div class="form-row">
            <div class="form-field"><label><?= __('Twoje imię (opcjonalnie)') ?></label><input type="text" name="submitter_name" x-model="submitterName"></div>
            <div class="form-field"><label><?= __('Twój e-mail') ?></label><input type="email" name="submitter_email" x-model="submitterEmail" required></div>
        </div>
    </div>
    </template>
    <?php endif; ?>

    <!-- 1. Podstawowe informacje -->
    <div class="form-section">
        <h3><?= __('Podstawowe informacje') ?></h3>
        <div class="type-toggle">
            <div class="type-option" :class="type==='ustawka' && 'active'" @click="type='ustawka'"><?= __('Ustawka jednodniowa') ?></div>
            <div class="type-option" :class="type==='wyscig' && 'active'" @click="type='wyscig'"><?= __('Wyścig') ?></div>
            <div class="type-option" :class="type==='wycieczka_wielodniowa' && 'active'" @click="type='wycieczka_wielodniowa'"><?= __('Wycieczka wielodniowa') ?></div>
            <div class="type-option" :class="type==='pokrec_z_kims' && 'active'" @click="type='pokrec_z_kims'"><?= __('Pokręcę z kimś') ?></div>
        </div>

        <!-- A. Kiedy masz czas (tylko pokrec_z_kims) — dwa tryby, oba oparte
             na zakresie dat od-do; różnica jest wyłącznie w interpretacji
             (długość wyjazdu vs okno dostępności), patrz dateIsFlexible. -->
        <template x-if="type==='pokrec_z_kims'">
        <div>
            <div class="type-toggle" style="margin-top:4px;">
                <div class="type-option" :class="!dateIsFlexible && 'active'" @click="dateIsFlexible=false"><?= __('Mam konkretny termin') ?></div>
                <div class="type-option" :class="dateIsFlexible && 'active'" @click="dateIsFlexible=true; startTime=''"><?= __('Jestem elastyczny') ?></div>
            </div>
            <input type="hidden" name="date_is_flexible" :value="dateIsFlexible ? 1 : 0">
            <div class="form-row">
                <div class="form-field"><label x-text="dateIsFlexible ? __('Od') : __('Data rozpoczęcia')"></label><input type="date" name="stages[0][date]" x-model="stages[0].date" required></div>
                <div class="form-field"><label x-text="dateIsFlexible ? __('Do') : __('Data zakończenia')"></label><input type="date" name="end_date" x-model="endDate" required></div>
                <div class="form-field" x-show="!dateIsFlexible"><label><?= __('Godzina rozpoczęcia (opcjonalnie)') ?></label><input type="time" name="start_time" x-model="startTime"></div>
            </div>
            <div class="hint" style="margin-bottom:10px;" x-text="dateIsFlexible ? __('Zakres to okno dostępności — dokładny dzień ustalicie później.') : __('Zakres to rzeczywista długość wyjazdu.')"></div>
        </div>
        </template>

        <div class="form-row">
            <div class="form-field"><label><?= __('Tytuł wydarzenia') ?></label><input type="text" name="title" x-model="title" @input="titleManuallyEdited=true" placeholder="<?= htmlspecialchars(__('np. Wielka Pętla Bieszczadzka')) ?>" required></div>
        </div>

        <template x-if="type!=='pokrec_z_kims'">
        <div>
            <div class="form-row">
                <div class="form-field"><label><?= __('Data wydarzenia') ?></label><input type="date" name="stages[0][date]" x-model="stages[0].date" required></div>
            </div>

            <!-- Turnusy — to samo wydarzenie odbywające się w kilku niezależnych
                 terminach (np. wyjazd wielodniowy organizowany co miesiąc). Każdy
                 dodatkowy termin dostaje własny limit miejsc i listę zapisanych
                 (patrz Models\EventEdition) — plan wyjazdu/trasa poniżej jest
                 wspólny dla wszystkich turnusów. -->
            <template x-for="(d, di) in additionalDates" :key="di">
                <div class="form-row edition-date-row">
                    <div class="form-field"><label x-text="'Dodatkowy termin ' + (di+1)"></label><input type="date" :name="'additional_edition_dates['+di+']'" x-model="additionalDates[di]"></div>
                    <button type="button" class="edition-date-remove" @click="removeEditionDate(di)" title="<?= htmlspecialchars(__('Usuń ten termin')) ?>"><?= Utils\Icon::render('close') ?></button>
                </div>
            </template>
            <button type="button" class="add-btn" style="margin-bottom:14px;" @click="addEditionDate()"><?= __('+ Dodaj kolejny termin (turnus)') ?></button>
        </div>
        </template>

        <div class="form-row">
            <div class="form-field"><label><?= __('Opis') ?></label><textarea name="description" x-model="description" :placeholder="type==='pokrec_z_kims' ? __('Napisz jak do znajomych — dokąd, w jakim tempie, czego szukasz...') : __('Dla kogo, jaka atmosfera, co warto wiedzieć...')" :required="type==='pokrec_z_kims'"></textarea></div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label><?= __('Zdjęcie okładki') ?></label>
                <label class="upload-box" :class="coverPhotoSelected && 'filled'">
                    <?= Utils\Icon::render('camera') ?>
                    <span x-text="coverPhotoSelected ? __('Zdjęcie wybrane') : __('Kliknij lub przeciągnij zdjęcie')"></span>
                    <input type="file" name="cover_photo" x-ref="coverPhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" @change="onCoverPhotoChange($event)">
                </label>
                <?php // Usunięcie okładki — patrz identyczny blok w kreatorze
                      // (partials/wizard/step-oczym.php) i clearCoverPhoto().
                      // W EDYCJI ma dodatkowe znaczenie: pozwala skasować JUŻ
                      // ZAPISANE zdjęcie, czego wcześniej nie dało się zrobić
                      // w ogóle. Rodzeństwo <label>, nie dziecko — inaczej klik
                      // otwierałby okno wyboru pliku. ?>
                <div class="hint" x-show="coverPhotoSelected" x-cloak>
                    <button type="button" class="link-button" @click="clearCoverPhoto()"><?= __('Usuń zdjęcie') ?></button>
                </div>
            </div>
        </div>
        <!-- Link zamiast pliku — patrz identyczny blok w kreatorze
             (partials/wizard/step-oczym.php) i komentarz przy
             Utils\Upload::saveCoverPhotoFromUrl(). Serwer pobiera zdjęcie sam
             przy zapisie; przeglądarka wysyła wyłącznie adres. -->
        <div class="form-row">
            <div class="form-field">
                <label><?= __('…albo link do zdjęcia') ?> <small><?= __('pobierzemy je za Ciebie') ?></small></label>
                <input type="url" name="cover_photo_source_url" x-model="coverPhotoSourceUrl"
                       placeholder="https://…/zdjecie.jpg" :disabled="coverPhotoSelected">
                <div class="hint" x-show="coverPhotoSelected" x-cloak><?= __('Wybrano plik z dysku — link nie jest potrzebny.') ?></div>
            </div>
        </div>
    </div>

    <!-- 2. Płatność i zapisy — nie dotyczy pokrec_z_kims (wymuszone internal
         + darmowe w Models\Event::save(), patrz Zadanie 2.6 specyfikacji). -->
    <template x-if="type!=='pokrec_z_kims'">
    <div class="form-section">
        <h3><?= __('Płatność i zapisy') ?></h3>

        <div class="subfield-label"><?= __('Cena') ?></div>
        <label class="switch-wrap">
            <input type="checkbox" class="switch" x-model="isPaid">
            <span class="switch-label" x-text="isPaid ? __('Płatne') : __('Darmowe')"></span>
        </label>

        <div class="subfield-label" style="margin-top:22px;"><?= __('Gdzie odbywają się zapisy') ?></div>
        <div class="type-toggle">
            <div class="type-option" :class="registrationType==='internal' && 'active'" @click="registrationType='internal'"><?= __('Na ridemore.bike') ?></div>
            <div class="type-option" :class="registrationType==='external' && 'active'" @click="registrationType='external'"><?= __('Link zewnętrzny') ?></div>
        </div>

        <div x-show="registrationType==='external'" class="dependent-note">
            <div class="form-row">
                <div class="form-field"><label><?= __('Link do zapisów / zakupu') ?></label><input type="text" name="external_url" x-model="externalUrl" placeholder="<?= htmlspecialchars(__('np. https://evenea.pl/... albo wydarzenie na Facebooku')) ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-field"><label><?= __('Telefon do zapisów') ?> <small><?= __('opcjonalnie') ?></small></label><input type="tel" name="external_phone" x-model="externalPhone" placeholder="<?= htmlspecialchars(__('np. 500 600 700')) ?>"></div>
                <div class="form-field"><label><?= __('E-mail do zapisów') ?> <small><?= __('opcjonalnie') ?></small></label><input type="email" name="external_email" x-model="externalEmail" placeholder="<?= htmlspecialchars(__('np. zapisy@klub.pl')) ?>"></div>
            </div>
            <div class="hint"><?= __('Uczestnicy zobaczą podane formy zapisu (link, telefon, e-mail) — możesz podać jedną lub kilka.') ?></div>
        </div>

        <div x-show="isPaid && registrationType==='internal' && !billingComplete" class="dependent-note">
            <div style="border:1px solid var(--accent);background:var(--accent-soft);border-radius:8px;padding:14px 16px;">
                <strong style="color:var(--accent-dark);"><?= __('Uzupełnij dane rozliczeniowe, zanim opublikujesz płatne wydarzenie.') ?></strong>
                <div class="hint" style="margin:6px 0 10px;"><?= __('Potrzebne do wystawienia faktur uczestnikom. Płatność idzie przez ridemore.bike, więc te dane są wymagane. Do tego czasu zapis zostanie szkicem.') ?></div>
                <a href="<?= Utils\View::url('/admin/profil-rozliczeniowy') ?>" class="btn btn-secondary" style="display:inline-block;text-decoration:none;"><?= __('Uzupełnij profil rozliczeniowy →') ?></a>
            </div>
        </div>
        <div x-show="isPaid && registrationType==='external'" class="dependent-note hint">
            <?= __('Płatność odbywa się na zewnętrznej stronie — dane rozliczeniowe w profilu nie są wymagane.') ?>

        </div>
    </div>
    </template>

    <!-- 3. Zbiórka -->
    <div class="form-section">
        <h3><?= __('Zbiórka') ?></h3>
        <div class="hint" style="margin-bottom:10px;"><?= __('Wskaż punkt na mapie — nie każde miejsce zbiórki ma adres.') ?></div>
        <div class="form-row">
            <div class="form-field">
                <label><?= __('Region') ?></label>
                <select name="region" x-model="region" :required="type==='pokrec_z_kims'">
                    <option value="">—</option>
                    <?php foreach ($dictOptions['regions'] as $group): ?>
                    <?php if ($group['label'] === null): ?>
                    <?php foreach ($group['items'] as $opt): ?>
                    <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <optgroup label="<?= htmlspecialchars($group['label']) ?>">
                        <?php foreach ($group['items'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field"><label><?= __('Opis miejsca (opcjonalnie)') ?></label><input type="text" name="meeting_address" x-model="meetingPoint.label" placeholder="<?= htmlspecialchars(__('np. Parking leśny, Cisna')) ?>"></div>
        </div>
        <input type="hidden" name="meeting_lat" :value="meetingPoint.lat">
        <input type="hidden" name="meeting_lng" :value="meetingPoint.lng">
        <button type="button" class="map-link-small" :class="meetingPoint.lat && 'set'" @click="openMap('meetingPoint')">
            <?= Utils\Icon::render('pin') ?>
            <span x-text="meetingPoint.lat ? meetingPoint.lat.toFixed(5) + ', ' + meetingPoint.lng.toFixed(5) + __(' — koryguj punkt zbiórki') : __('koryguj punkt zbiórki na mapie')"></span>
        </button>

        <!-- Etap 2 (dopasowania), krok 3: podpowiedzi na żywo — nigdy stwierdzenie
             faktu, zawsze sugestia z możliwością odrzucenia (docs/etap2). -->
        <div x-show="matchSuggestions.length" x-cloak style="margin-top:14px;">
            <template x-for="m in matchSuggestions" :key="m.url">
                <div style="border:1px solid var(--border-color,#e2ddd6);border-radius:10px;padding:12px 14px;margin-bottom:8px;display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                    <div>
                        <div style="font-weight:500;" x-text="m.reason"></div>
                        <div x-show="m.alignLabel" style="font-size:13px;opacity:.75;margin-top:2px;" x-text="m.alignLabel"></div>
                        <!-- Etap 3 §7 — ujawnienie warstwy wynikającej w miejscu użycia. -->
                        <div x-show="m.profileJustification" style="font-size:12px;opacity:.6;margin-top:2px;" x-text="m.profileJustification"></div>
                        <a :href="m.url" target="_blank" rel="noopener" style="font-size:13px;" x-text="m.title"></a>
                    </div>
                    <button type="button" @click="dismissMatch(m.eventId, m.url, m.title)" aria-label="<?= htmlspecialchars(__('Odrzuć podpowiedź')) ?>" style="background:none;border:none;cursor:pointer;opacity:.6;font-size:16px;line-height:1;">&times;</button>
                </div>
            </template>
            <div x-show="matchWideningLabel" style="font-size:12px;opacity:.6;" x-text="matchWideningLabel"></div>

            <!-- Etap 3 §6, stopień 2 (opcjonalny) — cztery przyciski, pomijalne,
                 bez formularza. Osobny blok (nie x-for), bo pozycja już zniknęła
                 z matchSuggestions po stopniu 1. -->
            <div x-show="matchReasonPrompt" x-cloak style="margin-top:8px;padding:8px 12px;border-radius:8px;background:var(--bg-subtle,#f5f1ec);font-size:12px;">
                <span style="opacity:.7;"><?= __('Powód odrzucenia „') ?><span x-text="matchReasonPrompt && matchReasonPrompt.title"></span><?= __('" (opcjonalnie):') ?></span>
                <button type="button" class="link-button" @click="submitDismissReason('zly_termin')"><?= __('zły termin') ?></button>
                <button type="button" class="link-button" @click="submitDismissReason('za_daleko')"><?= __('za daleko') ?></button>
                <button type="button" class="link-button" @click="submitDismissReason('nie_moje_tempo')"><?= __('nie moje tempo') ?></button>
                <button type="button" class="link-button" @click="submitDismissReason('po_prostu_nie')"><?= __('po prostu nie') ?></button>
                <button type="button" class="link-button" @click="skipDismissReason()"><?= __('pomiń') ?></button>
            </div>
        </div>
    </div>

    <!-- 4. Parametry — dla pokrec_z_kims zostaje tylko "Na czym jedziesz"
         (sekcja C specyfikacji, eksponowana od razu); trudność/tempo/limit
         uczestników przenoszą się do zwiniętej sekcji "Dodaj szczegóły" niżej. -->
    <div class="form-section">
        <h3 x-text="type==='pokrec_z_kims' ? __('Na czym jedziesz') : 'Parametry'"></h3>
        <template x-if="type!=='pokrec_z_kims'">
        <div>
            <div class="form-row">
                <div class="form-field">
                    <label><?= __('Poziom trudności') ?></label>
                    <select name="difficulty" x-model="difficulty">
                        <option value="">—</option>
                        <?php foreach ($dictOptions['difficulties'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label><?= __('Tempo grupy') ?></label>
                    <select name="pace" x-model="pace">
                        <option value="">—</option>
                        <?php foreach ($dictOptions['paces'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        </template>
        <div class="form-row">
            <div class="form-field">
                <label x-show="type!=='pokrec_z_kims'"><?= __('Dopuszczalne rowery') ?></label>
                <div class="checkbox-grid">
                    <?php foreach ($dictOptions['bikeTypes'] as $opt): ?>
                    <label class="checkbox-opt"><input type="checkbox" name="bike_types[]" value="<?= htmlspecialchars($opt['code']) ?>" x-model="bikeTypes"><?= htmlspecialchars($opt['name']) ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="hint" x-show="type==='pokrec_z_kims'"><?= __('Brak wyboru = brak ograniczeń.') ?></div>
            </div>
        </div>
        <template x-if="type!=='pokrec_z_kims'">
        <div>
            <label class="checkbox-opt" style="margin-bottom:10px;"><input type="checkbox" x-model="limitParticipants"> <?= __('Ogranicz liczbę uczestników') ?></label>
            <div class="form-row" x-show="limitParticipants">
                <div class="form-field"><label><?= __('Min. uczestników') ?></label><input type="number" name="min_participants" x-model="minParticipants" min="1" placeholder="<?= htmlspecialchars(__('np. 8')) ?>"></div>
                <div class="form-field"><label><?= __('Maks. uczestników') ?></label><input type="number" name="max_participants" x-model="maxParticipants" min="1" placeholder="<?= htmlspecialchars(__('np. 20')) ?>"></div>
            </div>
        </div>
        </template>
    </div>

    <!-- 5. Plan wyjazdu — nie dotyczy pokrec_z_kims (dystans/GPX orientacyjne
         dla niego żyją w zwiniętej sekcji "Dodaj szczegóły" niżej; jego
         jedyny wiersz event_stages powstaje z tamtych pól, patrz
         Models\Event::save()). -->
    <template x-if="type!=='pokrec_z_kims'">
    <div class="form-section">
        <h3><?= __('Plan wyjazdu') ?></h3>

        <!-- Warianty trasy (pętle) — tylko jednodniowe (ustawka/wyscig). Patrz
             Models\EventRouteVariant, event-form-wizard.php analogicznie. -->
        <template x-if="type==='ustawka' || type==='wyscig'">
        <label class="checkbox-opt" style="margin-bottom:14px;">
            <input type="checkbox" x-model="variantsEnabled" @change="toggleVariants()">
            <?= __('Kilka wariantów trasy (pętle) — każdy z własnym GPX i limitem') ?>

        </label>
        </template>

        <template x-if="(type==='ustawka' || type==='wyscig') && variantsEnabled">
        <div>
            <template x-for="(variant, i) in variants" :key="i">
                <div class="stage-block">
                    <button type="button" class="remove-btn" x-show="variants.length>1" @click="removeVariant(i)"><?= Utils\Icon::render('close') ?> <?= __('Usuń wariant') ?></button>
                    <div class="form-row">
                        <div class="form-field"><label><?= __('Nazwa wariantu') ?></label><input type="text" :name="'variants['+i+'][name]'" x-model="variant.name" placeholder="<?= htmlspecialchars(__('np. Pętla 300 km')) ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-field">
                            <label><?= __('Plik GPX') ?></label>
                            <input type="hidden" :name="'variants['+i+'][gpx_token]'" x-model="variant.gpxToken">
                            <input type="hidden" :name="'variants['+i+'][existing_gpx_url]'" :value="variant.gpxUrl || ''">
                            <label class="upload-box" :class="(variant.gpxToken || variant.gpxUrl) && 'filled'">
                                <?= Utils\Icon::render('upload') ?>
                                <span x-show="!variantGpxUploading[i]" x-text="(variant.gpxToken || variant.gpxUrl) ? __('trasa.gpx — wczytano') : __('Wgraj plik GPX')"></span>
                                <span x-show="variantGpxUploading[i]"><?= __('Analizuję trasę i nawierzchnię… (do 30 s)') ?></span>
                                <input type="file" accept=".gpx" style="display:none;" @change="uploadVariantGpx(i, $event)">
                            </label>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-field"><label><?= __('Dystans (km)') ?></label><input type="number" step="0.1" :name="'variants['+i+'][distance_km]'" x-model="variant.distanceKm" placeholder="<?= htmlspecialchars(__('np. 300')) ?>"></div>
                        <div class="form-field"><label><?= __('Przewyższenie (m)') ?></label><input type="number" :name="'variants['+i+'][elevation_m]'" x-model="variant.elevationM" placeholder="<?= htmlspecialchars(__('np. 4200')) ?>"></div>
                        <div class="form-field">
                            <label><?= __('Nawierzchnia') ?></label>
                            <input type="hidden" :name="'variants['+i+'][surface_asphalt_pct]'" :value="variant.surfaceAsphaltPct">
                            <input type="hidden" :name="'variants['+i+'][surface_gravel_pct]'" :value="variant.surfaceGravelPct">
                            <input type="hidden" :name="'variants['+i+'][surface_trail_pct]'" :value="variant.surfaceTrailPct">
                            <template x-if="variant.surfaceAsphaltPct !== null">
                                <div class="surface-breakdown">
                                    <div class="surface-bar">
                                        <span class="surface-seg surface-asphalt" :style="'width:' + variant.surfaceAsphaltPct + '%'"></span>
                                        <span class="surface-seg surface-gravel" :style="'width:' + variant.surfaceGravelPct + '%'"></span>
                                        <span class="surface-seg surface-trail" :style="'width:' + variant.surfaceTrailPct + '%'"></span>
                                    </div>
                                    <button type="button" class="link-button" @click="variant.surfaceAsphaltPct = null; variant.surfaceGravelPct = null; variant.surfaceTrailPct = null;"><?= __('Wpisz ręcznie zamiast wykrytego podziału') ?></button>
                                </div>
                            </template>
                            <select x-show="variant.surfaceAsphaltPct === null" :name="'variants['+i+'][surface]'" x-model="variant.surface">
                                <?php foreach ($dictOptions['surfaces'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-field"><label><?= __('Limit miejsc') ?> <small><?= __('opcjonalnie') ?></small></label><input type="number" :name="'variants['+i+'][max_participants]'" x-model="variant.maxParticipants" min="1" placeholder="<?= htmlspecialchars(__('np. 50')) ?>"></div>
                    </div>
                </div>
            </template>
            <button type="button" class="add-btn" @click="addVariant()"><?= __('+ Dodaj wariant') ?></button>
        </div>
        </template>

        <template x-if="!((type==='ustawka' || type==='wyscig') && variantsEnabled)">
        <template x-for="(stage, i) in stages" :key="i">
            <div class="stage-block">
                <button type="button" class="remove-btn" x-show="type==='wycieczka_wielodniowa' && stages.length>1" @click="removeStage(i)"><?= Utils\Icon::render('close') ?> <?= __('Usuń dzień') ?></button>
                <div class="stage-badge" x-show="type==='wycieczka_wielodniowa'" x-text="i+1"></div>

                <div class="form-row">
                    <div class="form-field">
                        <label><?= __('Start etapu') ?></label>
                        <input type="text" :name="'stages['+i+'][start_label]'" x-model="stage.startLabel" placeholder="<?= htmlspecialchars(__('np. Cisna (opcjonalnie — nazwa)')) ?>">
                        <input type="hidden" :name="'stages['+i+'][start_lat]'" :value="stage.startLat">
                        <input type="hidden" :name="'stages['+i+'][start_lng]'" :value="stage.startLng">
                        <button type="button" class="map-link-small" :class="stage.startLat && 'set'" @click="openMap('stage', i, 'start')">
                            <?= Utils\Icon::render('pin') ?>
                            <span x-text="stage.startLat ? stage.startLat.toFixed(5) + ', ' + stage.startLng.toFixed(5) + __(' — koryguj') : __('koryguj start na mapie')"></span>
                        </button>
                    </div>
                    <div class="form-field">
                        <label><?= __('Meta etapu') ?></label>
                        <input type="text" :name="'stages['+i+'][end_label]'" x-model="stage.endLabel" placeholder="<?= htmlspecialchars(__('np. Cisna (opcjonalnie — nazwa)')) ?>">
                        <input type="hidden" :name="'stages['+i+'][end_lat]'" :value="stage.endLat">
                        <input type="hidden" :name="'stages['+i+'][end_lng]'" :value="stage.endLng">
                        <button type="button" class="map-link-small" :class="stage.endLat && 'set'" @click="openMap('stage', i, 'end')">
                            <?= Utils\Icon::render('pin') ?>
                            <span x-text="stage.endLat ? stage.endLat.toFixed(5) + ', ' + stage.endLng.toFixed(5) + __(' — koryguj') : __('koryguj metę na mapie')"></span>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label><?= __('Plik GPX') ?></label>
                        <input type="hidden" :name="'stages['+i+'][gpx_token]'" x-model="stage.gpxToken">
                        <input type="hidden" :name="'stages['+i+'][existing_gpx_url]'" :value="stage.gpxUrl || ''">
                        <label class="upload-box" :class="(stage.gpxToken || stage.gpxUrl) && 'filled'">
                            <?= Utils\Icon::render('upload') ?>
                            <span x-show="!gpxUploading[i]" x-text="(stage.gpxToken || stage.gpxUrl) ? __('trasa.gpx — wczytano') : __('Wgraj plik GPX')"></span>
                            <span x-show="gpxUploading[i]"><?= __('Analizuję trasę i nawierzchnię… (do 30 s)') ?></span>
                            <input type="file" accept=".gpx" style="display:none;" @change="uploadGpx(i, $event)">
                        </label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field">
                        <label><?= __('Dystans (km)') ?></label>
                        <input type="number" step="0.1" :name="'stages['+i+'][distance_km]'" x-model="stage.distanceKm" placeholder="<?= htmlspecialchars(__('np. 45')) ?>">
                    </div>
                    <div class="form-field">
                        <label><?= __('Przewyższenie (m)') ?></label>
                        <input type="number" :name="'stages['+i+'][elevation_m]'" x-model="stage.elevationM" placeholder="<?= htmlspecialchars(__('np. 600')) ?>">
                    </div>
                    <div class="form-field">
                        <label><?= __('Nawierzchnia') ?></label>
                        <input type="hidden" :name="'stages['+i+'][surface_asphalt_pct]'" :value="stage.surfaceAsphaltPct">
                        <input type="hidden" :name="'stages['+i+'][surface_gravel_pct]'" :value="stage.surfaceGravelPct">
                        <input type="hidden" :name="'stages['+i+'][surface_trail_pct]'" :value="stage.surfaceTrailPct">
                        <!-- Wykryte automatycznie z GPX (patrz uploadGpx()) — pasek km/%
                             w stylu Komoot. Organizator może wrócić do ręcznego wyboru,
                             gdyby uznał detekcję za błędną (np. trasa słabo pokryta w OSM). -->
                        <template x-if="stage.surfaceAsphaltPct !== null">
                            <div class="surface-breakdown">
                                <div class="surface-bar">
                                    <span class="surface-seg surface-asphalt" :style="'width:' + stage.surfaceAsphaltPct + '%'"></span>
                                    <span class="surface-seg surface-gravel" :style="'width:' + stage.surfaceGravelPct + '%'"></span>
                                    <span class="surface-seg surface-trail" :style="'width:' + stage.surfaceTrailPct + '%'"></span>
                                </div>
                                <div class="surface-legend">
                                    <span class="surface-legend-item"><i class="surface-dot surface-asphalt"></i><?= __('Asfalt') ?> <b x-text="stage.surfaceAsphaltPct + '%'"></b> <span x-text="'(' + (stage.distanceKm * stage.surfaceAsphaltPct / 100).toFixed(1) + ' km)'"></span></span>
                                    <span class="surface-legend-item"><i class="surface-dot surface-gravel"></i><?= __('Gravel/szuter') ?> <b x-text="stage.surfaceGravelPct + '%'"></b> <span x-text="'(' + (stage.distanceKm * stage.surfaceGravelPct / 100).toFixed(1) + ' km)'"></span></span>
                                    <span class="surface-legend-item"><i class="surface-dot surface-trail"></i><?= __('Ścieżka') ?> <b x-text="stage.surfaceTrailPct + '%'"></b> <span x-text="'(' + (stage.distanceKm * stage.surfaceTrailPct / 100).toFixed(1) + ' km)'"></span></span>
                                </div>
                                <button type="button" class="link-button" @click="stage.surfaceAsphaltPct = null; stage.surfaceGravelPct = null; stage.surfaceTrailPct = null;"><?= __('Wpisz ręcznie zamiast wykrytego podziału') ?></button>
                            </div>
                        </template>
                        <select x-show="stage.surfaceAsphaltPct === null" :name="'stages['+i+'][surface]'" x-model="stage.surface">
                            <?php foreach ($dictOptions['surfaces'] as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row" x-show="type==='wycieczka_wielodniowa'">
                    <div class="form-field">
                        <label><?= __('Typ noclegu') ?></label>
                        <select :name="'stages['+i+'][accommodation_type]'" x-model="stage.accommodationType">
                            <option value=""><?= __('— brak —') ?></option>
                            <?php foreach ($dictOptions['accommodationTypes'] as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field"><label><?= __('Nazwa / adres noclegu') ?></label><input type="text" :name="'stages['+i+'][accommodation_name]'" x-model="stage.accommodationName" placeholder="<?= htmlspecialchars(__('np. Pensjonat w Ždiarze')) ?>"></div>
                </div>
                <div class="form-row" x-show="type==='wycieczka_wielodniowa'">
                    <div class="form-field">
                        <label><?= __('Posiłki w cenie') ?></label>
                        <div class="checkbox-grid">
                            <?php foreach ($dictOptions['mealTypes'] as $opt): ?>
                            <label class="checkbox-opt"><input type="checkbox" :name="'stages['+i+'][meals][]'" value="<?= htmlspecialchars($opt['code']) ?>" x-model="stage.meals"><?= htmlspecialchars($opt['name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field"><label><?= __('Notatki do etapu') ?></label><textarea :name="'stages['+i+'][notes]'" x-model="stage.notes" placeholder="<?= htmlspecialchars(__('np. trudny odcinek po deszczu, uwaga na bydło na drodze')) ?>"></textarea></div>
                </div>
            </div>
        </template>
        </template>
        <button type="button" class="add-btn" x-show="type==='wycieczka_wielodniowa' && !variantsEnabled" @click="addStage()"><?= __('+ Dodaj kolejny dzień') ?></button>
    </div>
    </template>

    <!-- 6. Sprzęt — dla pokrec_z_kims przenosi się do zwiniętej sekcji
         "Dodaj szczegóły" niżej (ta sama tablica equipment/pola equipment[i][...],
         tylko inne miejsce w DOM-ie — sekcje są wzajemnie wykluczające przez
         x-if, więc nazwy pól się nie duplikują). -->
    <template x-if="type!=='pokrec_z_kims'">
    <div class="form-section">
        <h3><?= __('Wymagany sprzęt') ?></h3>
        <template x-for="(item, i) in equipment" :key="i">
            <div class="price-item-row">
                <div class="price-item-category organizer-search" @click.outside="if(equipmentSearchFocusIndex===i) closeEquipmentResults()">
                    <input type="text" :name="'equipment['+i+'][name]'" x-model="item.name" @input.debounce.300ms="searchEquipment(i)" @focus="searchEquipment(i)" placeholder="<?= htmlspecialchars(__('np. kask, oświetlenie')) ?>">
                    <div class="organizer-search-results" x-show="equipmentSearchFocusIndex===i && equipmentSearchResults.length">
                        <template x-for="r in equipmentSearchResults" :key="r.code">
                            <div class="organizer-search-result" @click="selectEquipment(i, r.name)" x-text="r.name"></div>
                        </template>
                    </div>
                </div>
                <label class="checkbox-opt"><input type="checkbox" :name="'equipment['+i+'][mandatory]'" value="1" x-model="item.mandatory"><?= __('obowiązkowy') ?></label>
                <button type="button" class="remove-btn" style="position:static;" @click="equipment.splice(i,1)"><?= Utils\Icon::render('close') ?></button>
            </div>
        </template>
        <button type="button" class="add-btn" @click="equipment.push({name:'',mandatory:true})"><?= __('+ Dodaj pozycję') ?></button>
    </div>
    </template>

    <!-- 7. Cennik — nie dotyczy pokrec_z_kims. -->
    <template x-if="type!=='pokrec_z_kims'">
    <div class="form-section">
        <div x-show="!isPaid" class="hint"><?= __('To wydarzenie jest darmowe. Zmień w sekcji „Płatność i zapisy” powyżej, jeśli chcesz dodać cennik.') ?></div>

        <div x-show="isPaid">
            <template x-if="variantsEnabled">
            <div class="form-row">
                <div class="form-field">
                    <label><?= __('Cena każdego wariantu') ?></label>
                    <template x-for="(variant, i) in variants" :key="i">
                        <div class="price-item-row">
                            <span x-text="variant.name || __('Wariant {n}', {n: i+1})" style="flex:1;"></span>
                            <input type="number" step="0.01" style="max-width:120px;" :name="'variants['+i+'][price_amount]'" x-model="variant.priceAmount" placeholder="<?= htmlspecialchars(__('cena')) ?>">
                            <input type="number" step="0.01" style="max-width:120px;" :name="'variants['+i+'][deposit]'" x-model="variant.deposit" placeholder="<?= htmlspecialchars(__('zaliczka')) ?>">
                        </div>
                    </template>
                </div>
            </div>
            </template>
            <div class="form-row">
                <div class="form-field" x-show="!variantsEnabled"><label><?= __('Cena') ?></label><input type="number" step="0.01" name="price_amount" x-model="priceAmount" placeholder="890"></div>
                <div class="form-field">
                    <label><?= __('Waluta') ?></label>
                    <select name="currency" x-model="currency">
                        <?php foreach ($dictOptions['currencies'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label><?= __('Cena za') ?></label>
                    <select name="price_unit" x-model="priceUnit">
                        <?php foreach ($dictOptions['priceUnits'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field" x-show="!variantsEnabled"><label><?= __('Zaliczka') ?></label><input type="number" step="0.01" name="deposit" x-model="deposit" placeholder="200"></div>
                <div class="form-field"><label><?= __('Dopłata do (dni przed)') ?></label><input type="number" name="payment_deadline_days" x-model="paymentDeadlineDays" placeholder="30"></div>
                <div class="form-field"><label><?= __('Odwołanie możliwe do (dni przed)') ?></label><input type="number" name="cancellation_deadline_days" x-model="cancellationDeadlineDays" placeholder="<?= htmlspecialchars(__('np. 7 (puste = bez ograniczenia)')) ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-field"><label><?= __('Polityka anulowania') ?></label><textarea name="cancellation_policy" x-model="cancellationPolicy" placeholder="<?= htmlspecialchars(__('np. zwrot zaliczki do 14 dni przed wyjazdem')) ?>"></textarea></div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label><?= __('W cenie') ?></label>
                    <template x-for="(item, i) in priceItems" :key="i">
                        <template x-if="item.isIncluded">
                        <div class="price-item-row">
                            <span class="included-tag yes"><?= Utils\Icon::render('check') ?></span>
                            <div class="price-item-category organizer-search" @click.outside="if(categorySearchFocusIndex===i) closeCategoryResults()">
                                <input type="text" :name="'price_items['+i+'][category]'" x-model="item.category" @input.debounce.300ms="searchCategories(i)" @focus="searchCategories(i)" placeholder="<?= htmlspecialchars(__('Kategoria pozycji cennika (np. Nocleg)')) ?>">
                                <div class="organizer-search-results" x-show="categorySearchFocusIndex===i && categorySearchResults.length">
                                    <template x-for="r in categorySearchResults" :key="r.code">
                                        <div class="organizer-search-result" @click="selectCategory(i, r.name)" x-text="r.name"></div>
                                    </template>
                                </div>
                            </div>
                            <input type="hidden" :name="'price_items['+i+'][is_included]'" value="1">
                            <button type="button" class="remove-btn" style="position:static;" @click="priceItems.splice(i,1)"><?= Utils\Icon::render('close') ?></button>
                        </div>
                        </template>
                    </template>
                    <button type="button" class="add-btn" @click="priceItems.push({category:'',isIncluded:true})"><?= __('+ Dodaj pozycję') ?></button>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label><?= __('Nie w cenie') ?></label>
                    <template x-for="(item, i) in priceItems" :key="i">
                        <template x-if="!item.isIncluded">
                        <div class="price-item-row">
                            <span class="included-tag no">–</span>
                            <div class="price-item-category organizer-search" @click.outside="if(categorySearchFocusIndex===i) closeCategoryResults()">
                                <input type="text" :name="'price_items['+i+'][category]'" x-model="item.category" @input.debounce.300ms="searchCategories(i)" @focus="searchCategories(i)" placeholder="<?= htmlspecialchars(__('Kategoria pozycji cennika (np. Transport bagażu)')) ?>">
                                <div class="organizer-search-results" x-show="categorySearchFocusIndex===i && categorySearchResults.length">
                                    <template x-for="r in categorySearchResults" :key="r.code">
                                        <div class="organizer-search-result" @click="selectCategory(i, r.name)" x-text="r.name"></div>
                                    </template>
                                </div>
                            </div>
                            <button type="button" class="remove-btn" style="position:static;" @click="priceItems.splice(i,1)"><?= Utils\Icon::render('close') ?></button>
                        </div>
                        </template>
                    </template>
                    <button type="button" class="add-btn" @click="priceItems.push({category:'',isIncluded:false})"><?= __('+ Dodaj pozycję') ?></button>
                </div>
            </div>
        </div>
    </div>
    </template>

    <!-- Sekcja zwinięta "Dodaj szczegóły" — tylko pokrec_z_kims (Zadanie 3.4
         specyfikacji): tempo, trudność, orientacyjny dystans/GPX (jedyny
         wiersz event_stages tego typu, patrz Models\Event::save()), limit
         uczestników, wymagany sprzęt. x-show wewnątrz (nie x-if) — zwinięcie
         nie może kasować już wpisanych danych, muszą zostać w DOM-ie i
         przejść w submicie nawet gdy sekcja jest wizualnie zwinięta. -->
    <template x-if="type==='pokrec_z_kims'">
    <div class="form-section">
        <button type="button" class="details-toggle" @click="detailsOpen=!detailsOpen">
            <span x-text="detailsOpen ? __('− Zwiń szczegóły') : __('+ Dodaj szczegóły (opcjonalnie)')"></span>
        </button>
        <div x-show="detailsOpen" style="margin-top:16px;">
            <div class="form-row">
                <div class="form-field">
                    <label><?= __('Tempo grupy') ?></label>
                    <select name="pace" x-model="pace">
                        <option value="">—</option>
                        <?php foreach ($dictOptions['paces'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label><?= __('Poziom trudności') ?></label>
                    <select name="difficulty" x-model="difficulty">
                        <option value="">—</option>
                        <?php foreach ($dictOptions['difficulties'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label><?= __('Orientacyjny dystans (km)') ?></label>
                    <input type="number" step="0.1" name="stages[0][distance_km]" x-model="stages[0].distanceKm" placeholder="<?= htmlspecialchars(__('np. 60')) ?>">
                </div>
                <div class="form-field">
                    <label><?= __('Plik GPX') ?></label>
                    <input type="hidden" name="stages[0][gpx_token]" x-model="stages[0].gpxToken">
                    <input type="hidden" name="stages[0][existing_gpx_url]" :value="stages[0].gpxUrl || ''">
                    <label class="upload-box" :class="(stages[0].gpxToken || stages[0].gpxUrl) && 'filled'">
                        <?= Utils\Icon::render('upload') ?>
                        <span x-show="!gpxUploading[0]" x-text="(stages[0].gpxToken || stages[0].gpxUrl) ? __('trasa.gpx — wczytano') : __('Wgraj plik GPX')"></span>
                        <span x-show="gpxUploading[0]"><?= __('Analizuję trasę… (do 30 s)') ?></span>
                        <input type="file" accept=".gpx" style="display:none;" @change="uploadGpx(0, $event)">
                    </label>
                </div>
            </div>
            <label class="checkbox-opt" style="margin-bottom:10px;"><input type="checkbox" x-model="limitParticipants"> <?= __('Ogranicz liczbę uczestników') ?></label>
            <div class="form-row" x-show="limitParticipants">
                <div class="form-field"><label><?= __('Min. uczestników') ?></label><input type="number" name="min_participants" x-model="minParticipants" min="1" placeholder="<?= htmlspecialchars(__('np. 8')) ?>"></div>
                <div class="form-field"><label><?= __('Maks. uczestników') ?></label><input type="number" name="max_participants" x-model="maxParticipants" min="1" placeholder="<?= htmlspecialchars(__('np. 20')) ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label><?= __('Wymagany sprzęt') ?></label>
                    <template x-for="(item, i) in equipment" :key="i">
                        <div class="price-item-row">
                            <div class="price-item-category organizer-search" @click.outside="if(equipmentSearchFocusIndex===i) closeEquipmentResults()">
                                <input type="text" :name="'equipment['+i+'][name]'" x-model="item.name" @input.debounce.300ms="searchEquipment(i)" @focus="searchEquipment(i)" placeholder="<?= htmlspecialchars(__('np. kask, oświetlenie')) ?>">
                                <div class="organizer-search-results" x-show="equipmentSearchFocusIndex===i && equipmentSearchResults.length">
                                    <template x-for="r in equipmentSearchResults" :key="r.code">
                                        <div class="organizer-search-result" @click="selectEquipment(i, r.name)" x-text="r.name"></div>
                                    </template>
                                </div>
                            </div>
                            <label class="checkbox-opt"><input type="checkbox" :name="'equipment['+i+'][mandatory]'" value="1" x-model="item.mandatory"><?= __('obowiązkowy') ?></label>
                            <button type="button" class="remove-btn" style="position:static;" @click="equipment.splice(i,1)"><?= Utils\Icon::render('close') ?></button>
                        </div>
                    </template>
                    <button type="button" class="add-btn" @click="equipment.push({name:'',mandatory:true})"><?= __('+ Dodaj pozycję') ?></button>
                </div>
            </div>
        </div>
    </div>
    </template>

    <!-- Panel mapy -->
    <div class="modal-backdrop" x-show="mapOpen" x-cloak @click.self="mapOpen=false">
        <div class="modal-box" @click.outside="mapOpen=false">
            <div class="map-panel-head">
                <span><?= __('Kliknij na mapie, aby ustawić pin') ?></span>
                <button type="button" class="map-btn" @click="mapOpen=false"><?= __('Zamknij') ?></button>
            </div>
            <div class="map-search-row organizer-search" @click.outside="geocodeResults=[]">
                <input type="text" class="search-input" x-model="geocodeQuery" @input.debounce.500ms="searchGeocode()" placeholder="<?= htmlspecialchars(__('Szukaj miejsca po nazwie...')) ?>">
                <div class="organizer-search-results" x-show="geocodeResults.length">
                    <template x-for="r in geocodeResults" :key="r.placeId">
                        <div class="organizer-search-result" @click="selectGeocodeResult(r)" x-text="r.label"></div>
                    </template>
                </div>
            </div>
            <div id="picker-map"></div>
        </div>
    </div>

    <div class="submit-row" style="flex-direction:column;align-items:flex-end;">
        <div class="hint" x-show="isPaid && registrationType==='internal' && !billingComplete && organizerMode==='self'" style="color:var(--accent-dark);margin-bottom:8px;">
            <?= __('Bez uzupełnionego profilu rozliczeniowego wydarzenie zostanie zapisane jako szkic.') ?>

        </div>
        <div style="display:flex;gap:12px;">
            <button class="btn btn-secondary" type="submit" name="status" value="draft" x-show="type!=='pokrec_z_kims' && (organizerMode==='self' || isAdmin)"><?= __('Zapisz jako szkic') ?></button>
            <button class="btn" type="submit" name="status" value="published" x-text="type==='pokrec_z_kims' ? __('Wystaw inicjatywę') : ((organizerMode==='self' || isAdmin) ? __('Opublikuj wydarzenie →') : __('Zgłoś do weryfikacji →'))"></button>
        </div>
    </div>
</form>
</div>

<script>
function eventForm(initial, billingComplete, isLoggedIn, isAdmin, regionLabels) {
    // `initial` (dane odtworzone przez EventFormResource::fromPost() po
    // nieudanej walidacji) MUSI nadpisywać te twarde domyślne wartości, nie
    // odwrotnie — inaczej ponowne wyrenderowanie formularza po błędzie
    // zawsze czyściło wybór organizatora (patrz zgłoszenie użytkownika).
    return Object.assign({
        billingComplete: billingComplete,
        coverPhotoSelected: !!initial.coverPhotoUrl,
        gpxUploading: {},
        // Warianty trasy (pętle) — patrz event-form-wizard.php/script.php,
        // ta sama logika. variantsEnabled wynika z initial.variants (prefill
        // edycji przez EventFormResource::fromRawEvent).
        variantsEnabled: !!(initial.variants && initial.variants.length),
        variantGpxUploading: {},
        mapOpen: false,
        mapTarget: null,
        leafletMap: null,
        leafletMarker: null,
        geocodeQuery: '',
        geocodeResults: [],
        geocodeToken: 0,

        // pokrec_z_kims — propozycja tytułu (patrz event-form.php sekcja
        // "O czym to jest"). titleManuallyEdited=true po pierwszym ręcznym
        // wpisaniu (na 'input' realnego usera, patrz @input przy polu tytułu
        // — programistyczne this.title=... z watch() niżej NIE odpala tego
        // eventu) — od tej chwili propozycja przestaje nadpisywać treść pola.
        // Domyślnie true, gdy initial.title już coś niesie (odtworzenie po
        // nieudanej walidacji albo edycja) — inaczej watcher zaraz po
        // init() nadpisałby wpisany wcześniej tytuł.
        titleManuallyEdited: !!initial.title,
        detailsOpen: false,
        regionLabels: regionLabels || {},

        // "Kogo dotyczy" — patrz sekcja 0 wyżej. Jedno pole szukajki: pisanie
        // pokazuje pasujących organizatorów ORAZ zawsze opcję "dodaj jako nowego"
        // na dole listy — nie trzeba z góry wiedzieć/deklarować, czy organizator
        // już istnieje w bazie. isLoggedIn===false wymusza 'existing'/'new' (nie
        // ma "ja" bez zalogowania).
        // Etap 2 (dopasowania), krok 3 — podpowiedzi na żywo z Models\MatchEngine
        // podczas wypełniania formularza (patrz scheduleMatchFetch/init niżej).
        matchSuggestions: [],
        matchWideningLabel: null,
        matchFetchToken: 0,
        matchDebounceTimer: null,
        dismissedMatchUrls: [],
        scheduleMatchFetch() {
            clearTimeout(this.matchDebounceTimer);
            this.matchDebounceTimer = setTimeout(() => this.fetchMatchSuggestions(), 500);
        },
        async fetchMatchSuggestions() {
            const startDate = (this.stages[0] || {}).date;
            if (!startDate) {
                this.matchSuggestions = [];
                return;
            }
            const params = new URLSearchParams({ startDate });
            if (this.endDate) params.set('endDate', this.endDate);
            if (this.region) params.set('region', this.region);
            if (this.pace) params.set('pace', this.pace);
            if (this.meetingPoint.lat) params.set('lat', this.meetingPoint.lat);
            if (this.meetingPoint.lng) params.set('lng', this.meetingPoint.lng);
            if (this.existingId) params.set('excludeEventId', this.existingId);
            (this.bikeTypes || []).forEach(code => params.append('bikeTypes[]', code));

            // Ten sam wzorzec anty-wyścigowy co searchOrganizers()/searchCategories()
            // wyżej — odpowiedź na starsze zapytanie nie może nadpisać nowszej.
            const myToken = ++this.matchFetchToken;
            const res = await fetch(<?= json_encode(Utils\View::url('/api/matches/preview')) ?> + '?' + params.toString());
            if (myToken !== this.matchFetchToken || !res.ok) return;
            const data = await res.json();
            this.matchWideningLabel = data.wideningLabel;
            this.matchSuggestions = (data.matches || []).filter(m => !this.dismissedMatchUrls.includes(m.url));
        },
        // Etap 3 (preferencje) §6 — odrzucenie trzystopniowe. Stopień 1 (ten
        // klik) działa NATYCHMIAST, bez czekania na serwer — lokalne ukrycie
        // jest odczuwalne od razu, POST leci w tle. Bez konta zostaje tylko
        // ukrycie lokalne (serwerowy zapis wymaga usera, patrz
        // Models\RecommendationDismissal — FK NOT NULL na user_id).
        // {eventId, title} właśnie odrzuconej pozycji, dopóki user nie wybierze
        // powodu albo nie pominie — TRZYMANE OSOBNO od matchSuggestions (ta
        // pozycja już z niej zniknęła po stopniu 1, więc nie da się jej tam
        // znaleźć z powrotem pod x-for).
        matchReasonPrompt: null,
        async dismissMatch(eventId, url, title) {
            this.dismissedMatchUrls.push(url);
            this.matchSuggestions = this.matchSuggestions.filter(m => m.url !== url);
            if (!this.isLoggedIn) return;
            await this.postDismiss(eventId, null);
            this.matchReasonPrompt = { eventId, title };
        },
        async submitDismissReason(reason) {
            if (!this.matchReasonPrompt) return;
            await this.postDismiss(this.matchReasonPrompt.eventId, reason);
            this.matchReasonPrompt = null;
        },
        skipDismissReason() {
            this.matchReasonPrompt = null;
        },
        async postDismiss(eventId, reason) {
            const body = new URLSearchParams({ csrf_token: <?= json_encode(\Core\Csrf::token()) ?>, eventId: String(eventId) });
            if (reason) body.set('reason', reason);
            try {
                await fetch(<?= json_encode(Utils\View::url('/api/matches/dismiss')) ?>, { method: 'POST', body });
            } catch (e) {
                // Cicho pomijamy — odrzucenie lokalne już się stało, brak
                // zapisu serwerowego najwyżej pokaże tę samą sugestię ponownie.
            }
        },

        isLoggedIn: isLoggedIn,
        isAdmin: isAdmin,
        organizerMode: isLoggedIn ? 'self' : 'existing',
        organizerId: null,
        organizerConfirmed: false,
        organizerSearchQuery: '',
        organizerSearchResults: [],
        organizerSearchToken: 0,
        showOrganizerResults: false,
        newOrganizerEmail: '',
        submitterName: '',
        submitterEmail: '',

        async searchOrganizers() {
            this.organizerId = null;
            if (this.organizerMode !== 'new') this.organizerMode = 'existing';
            const query = this.organizerSearchQuery.trim();
            if (query.length < 2) {
                this.organizerSearchResults = [];
                return;
            }
            // Odpowiedzi z sieci mogą wrócić w innej kolejności niż wysłane
            // zapytania (np. wolniejsza odpowiedź na wcześniejszy, krótszy
            // fragment wpisanego tekstu) — bez tego znacznika starsza odpowiedź
            // mogła nadpisać wynik nowszego wyszukiwania i pokazać nieaktualną
            // listę w momencie kliknięcia.
            const myToken = ++this.organizerSearchToken;
            const res = await fetch(<?= json_encode(Utils\View::url('/api/organizers/search')) ?> + '?q=' + encodeURIComponent(query));
            if (myToken !== this.organizerSearchToken) return;
            this.organizerSearchResults = res.ok ? await res.json() : [];
        },
        selectOrganizer(r) {
            this.organizerId = r.id;
            this.organizerMode = 'existing';
            this.organizerSearchQuery = r.label;
            this.organizerSearchResults = [];
            this.showOrganizerResults = false;
            this.organizerConfirmed = true;
        },
        selectNewOrganizer() {
            this.organizerMode = 'new';
            this.organizerSearchResults = [];
            this.showOrganizerResults = false;
            this.organizerConfirmed = true;
        },
        changeOrganizer() {
            this.organizerConfirmed = false;
            this.organizerId = null;
            this.organizerMode = 'existing';
            this.organizerSearchQuery = '';
            this.newOrganizerEmail = '';
        },

        // Pozycja cennika = jedno pole "Kategoria pozycji cennika", dokładnie
        // ten sam wzorzec co szukajka organizatora wyżej: pisanie pokazuje
        // podpowiedzi ze słownika 'inclusion_category', kliknięcie podpowiedzi
        // WPROWADZA jej nazwę wprost w to samo pole (jak selectOrganizer()
        // ustawia organizerSearchQuery) — bez osobnego pola "opis", bez
        // dodatkowych etykietek. Jeśli wpisany tekst nie pasuje do niczego na
        // liście, backend (Dictionary::resolveOrCreate() w EventPricing) i tak
        // dołoży go jako nową pozycję słownika przy zapisie.
        categorySearchResults: [],
        categorySearchFocusIndex: null,
        categorySearchToken: 0,
        async searchCategories(i) {
            this.categorySearchFocusIndex = i;
            const query = (this.priceItems[i].category || '').trim();
            if (query.length < 2) {
                this.categorySearchResults = [];
                return;
            }
            const myToken = ++this.categorySearchToken;
            const res = await fetch(<?= json_encode(Utils\View::url('/api/dictionaries/inclusion_category/search')) ?> + '?q=' + encodeURIComponent(query));
            if (myToken !== this.categorySearchToken) return;
            this.categorySearchResults = res.ok ? await res.json() : [];
        },
        selectCategory(i, name) {
            this.priceItems[i].category = name;
            this.categorySearchResults = [];
            this.categorySearchFocusIndex = null;
        },
        closeCategoryResults() {
            this.categorySearchResults = [];
            this.categorySearchFocusIndex = null;
        },

        // Wymagany sprzęt — dokładnie ten sam wzorzec co kategoria pozycji
        // cennika wyżej, tylko nad słownikiem 'equipment_item'. Osobny zestaw
        // stanu (nie ten sam co categorySearch*) bo to inna lista (equipment,
        // nie priceItems) i oba pola mogą teoretycznie mieć fokus niezależnie.
        equipmentSearchResults: [],
        equipmentSearchFocusIndex: null,
        equipmentSearchToken: 0,
        async searchEquipment(i) {
            this.equipmentSearchFocusIndex = i;
            const query = (this.equipment[i].name || '').trim();
            if (query.length < 2) {
                this.equipmentSearchResults = [];
                return;
            }
            const myToken = ++this.equipmentSearchToken;
            const res = await fetch(<?= json_encode(Utils\View::url('/api/dictionaries/equipment_item/search')) ?> + '?q=' + encodeURIComponent(query));
            if (myToken !== this.equipmentSearchToken) return;
            this.equipmentSearchResults = res.ok ? await res.json() : [];
        },
        selectEquipment(i, name) {
            this.equipment[i].name = name;
            this.equipmentSearchResults = [];
            this.equipmentSearchFocusIndex = null;
        },
        closeEquipmentResults() {
            this.equipmentSearchResults = [];
            this.equipmentSearchFocusIndex = null;
        },

        init() {
            // Propozycja tytułu dla pokrec_z_kims — przelicza się na każdą
            // zmianę regionu/dat, dopóki user sam nie zacznie edytować pola
            // (titleManuallyEdited, patrz wyżej i @input przy polu tytułu).
            this.$watch('region', () => this.maybeSuggestTitle());
            this.$watch('stages[0].date', () => this.maybeSuggestTitle());
            this.$watch('endDate', () => this.maybeSuggestTitle());
            this.$watch('dateIsFlexible', () => this.maybeSuggestTitle());
            this.$watch('type', () => this.maybeSuggestTitle());

            // Etap 2 — te same pola napędzają podpowiedzi dopasowań (debounced,
            // patrz scheduleMatchFetch powyżej).
            this.$watch('region', () => this.scheduleMatchFetch());
            this.$watch('stages[0].date', () => this.scheduleMatchFetch());
            this.$watch('endDate', () => this.scheduleMatchFetch());
            this.$watch('meetingPoint.lat', () => this.scheduleMatchFetch());
            this.$watch('pace', () => this.scheduleMatchFetch());
            this.$watch('bikeTypes', () => this.scheduleMatchFetch());
            this.scheduleMatchFetch();
        },

        maybeSuggestTitle() {
            if (this.type !== 'pokrec_z_kims' || this.titleManuallyEdited) return;
            const regionName = this.regionLabels[this.region];
            const dateLabel = this.formatDateRangeShort(this.stages[0].date, this.endDate);
            if (!regionName && !dateLabel) return;
            this.title = [regionName, dateLabel].filter(Boolean).join(', ');
        },

        // Odpowiednik Utils\Format::dateRangeShort() po stronie klienta — sama
        // data (bez roku, bez godziny), format krótki, żeby zmieściła się w
        // polu tytułu. Puste/niepełne daty -> null (propozycja czeka, aż
        // user wypełni obie).
        formatDateRangeShort(start, end) {
            if (!start) return null;
            const months = __('sty,lut,mar,kwi,maj,cze,lip,sie,wrz,paź,lis,gru').split(',');
            const fmt = (d) => { const parts = d.split('-'); return parseInt(parts[2], 10) + ' ' + months[parseInt(parts[1], 10) - 1]; };
            if (!end || end === start) return fmt(start);
            const [sy, sm] = start.split('-');
            const [ey, em] = end.split('-');
            if (sy === ey && sm === em) {
                return parseInt(start.split('-')[2], 10) + '–' + parseInt(end.split('-')[2], 10) + ' ' + months[parseInt(em, 10) - 1];
            }
            return fmt(start) + ' – ' + fmt(end);
        },

        // Wyszukiwanie miejsca w pickerze mapy (Nominatim) — bez klucza API,
        // ale z jego polityką użycia: limit zapytań respektowany przez debounce
        // po stronie inputu (@input.debounce.500ms), nagłówek identyfikujący
        // aplikację (Nominatim wymaga albo tego, albo Referer — przeglądarka
        // i tak dokłada Referer, nagłówek tu jest dodatkowym, jawnym opisem).
        async searchGeocode() {
            const query = this.geocodeQuery.trim();
            if (query.length < 3) {
                this.geocodeResults = [];
                return;
            }
            const myToken = ++this.geocodeToken;
            try {
                const res = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&countrycodes=pl&q=' + encodeURIComponent(query), {
                    headers: { 'Accept-Language': 'pl' },
                });
                if (myToken !== this.geocodeToken) return;
                const data = res.ok ? await res.json() : [];
                this.geocodeResults = data.map((r) => ({ placeId: r.place_id, label: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon) }));
            } catch (e) {
                this.geocodeResults = [];
            }
        },
        selectGeocodeResult(r) {
            this.setPin({ lat: r.lat, lng: r.lng });
            if (this.mapTarget && this.mapTarget.kind === 'meetingPoint' && !this.meetingPoint.label) {
                this.meetingPoint.label = r.label;
            }
            this.geocodeQuery = '';
            this.geocodeResults = [];
            if (this.leafletMap) {
                this.leafletMap.setView([r.lat, r.lng], 13);
            }
        },

        addStage() {
            const prev = this.stages[this.stages.length - 1];
            this.stages.push({
                date: '', title: '',
                startLabel: prev ? prev.endLabel : '', startLat: prev ? prev.endLat : null, startLng: prev ? prev.endLng : null,
                endLabel: '', endLat: null, endLng: null,
                distanceKm: '', elevationM: '', surface: <?= json_encode($dictOptions['surfaces'][0]['code'] ?? '') ?>,
                surfaceAsphaltPct: null, surfaceGravelPct: null, surfaceTrailPct: null,
                gpxUrl: null, gpxToken: '',
                accommodationType: '', accommodationName: '', accommodationAddress: '',
                meals: [], notes: '',
            });
        },
        removeStage(i) {
            if (this.stages.length <= 1) return;
            this.stages.splice(i, 1);
        },

        addVariant() {
            this.variants.push({
                name: '', distanceKm: '', elevationM: '',
                surface: <?= json_encode($dictOptions['surfaces'][0]['code'] ?? '') ?>,
                surfaceAsphaltPct: null, surfaceGravelPct: null, surfaceTrailPct: null,
                gpxUrl: null, gpxToken: '',
                priceAmount: '', deposit: '', maxParticipants: '',
            });
        },
        removeVariant(i) {
            this.variants.splice(i, 1);
            if (this.variants.length === 0) this.variantsEnabled = false;
        },
        toggleVariants() {
            if (this.variantsEnabled && this.variants.length === 0) this.addVariant();
        },
        async uploadVariantGpx(i, event) {
            const file = event.target.files[0];
            if (!file) return;
            this.variantGpxUploading = Object.assign({}, this.variantGpxUploading, { [i]: true });
            const formData = new FormData();
            formData.append('gpx', file);
            formData.append('csrf_token', <?= json_encode(\Core\Csrf::token()) ?>);
            try {
                const res = await fetch(<?= json_encode(Utils\View::url('/api/gpx/parse')) ?>, { method: 'POST', body: formData });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || __('Nie udało się wczytać pliku'));
                this.variants[i].gpxToken = data.token;
                this.variants[i].distanceKm = data.distanceKm;
                this.variants[i].elevationM = data.elevationGainM;
                this.variants[i].surfaceAsphaltPct = data.surface ? data.surface.asphaltPct : null;
                this.variants[i].surfaceGravelPct  = data.surface ? data.surface.gravelPct  : null;
                this.variants[i].surfaceTrailPct   = data.surface ? data.surface.trailPct   : null;
            } catch (e) {
                alert(__('Nie udało się wczytać GPX: ') + e.message);
            } finally {
                this.variantGpxUploading = Object.assign({}, this.variantGpxUploading, { [i]: false });
            }
        },

        addEditionDate() {
            this.additionalDates.push('');
        },
        removeEditionDate(i) {
            this.additionalDates.splice(i, 1);
        },

        onCoverPhotoChange(event) {
            this.coverPhotoSelected = event.target.files.length > 0;
        },
        // Bliźniak clearCoverPhoto() z kreatora (partials/wizard/script.php) —
        // patrz tamten komentarz, dlaczego czyszczone są trzy rzeczy naraz.
        // Tu coverPhotoUrl=null ma dodatkowy skutek: kasuje JUŻ ZAPISANĄ
        // okładkę edytowanego wydarzenia (przez puste existing_cover_photo_url).
        clearCoverPhoto() {
            if (this.$refs.coverPhotoInput) this.$refs.coverPhotoInput.value = '';
            this.coverPhotoSelected = false;
            this.coverPhotoUrl = null;
        },

        async uploadGpx(i, event) {
            const file = event.target.files[0];
            if (!file) return;

            this.gpxUploading = Object.assign({}, this.gpxUploading, { [i]: true });
            const formData = new FormData();
            formData.append('gpx', file);
            formData.append('csrf_token', <?= json_encode(\Core\Csrf::token()) ?>);

            try {
                const res = await fetch(<?= json_encode(Utils\View::url('/api/gpx/parse')) ?>, { method: 'POST', body: formData });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || __('Nie udało się wczytać pliku'));
                this.stages[i].gpxToken = data.token;
                this.stages[i].distanceKm = data.distanceKm;
                this.stages[i].elevationM = data.elevationGainM;
                // data.surface bywa null (Overpass offline/rate limit/trasa bez
                // pokrycia OSM) — wtedy zostaje ręczny dropdown ze słownika,
                // patrz x-show w bloku "Nawierzchnia" niżej. Zerujemy jawnie na
                // wypadek gdyby POPRZEDNI plik dla tego etapu miał wykryty
                // podział, a nowy już nie.
                this.stages[i].surfaceAsphaltPct = data.surface ? data.surface.asphaltPct : null;
                this.stages[i].surfaceGravelPct  = data.surface ? data.surface.gravelPct  : null;
                this.stages[i].surfaceTrailPct   = data.surface ? data.surface.trailPct   : null;
            } catch (e) {
                alert(__('Nie udało się wczytać GPX: ') + e.message);
            } finally {
                this.gpxUploading = Object.assign({}, this.gpxUploading, { [i]: false });
            }
        },

        openMap(kind, stageIndex, which) {
            this.mapTarget = { kind, stageIndex, which };
            this.mapOpen = true;
            this.$nextTick(() => {
                if (!this.leafletMap) {
                    this.leafletMap = ridemoreCreateMap('picker-map');
                    this.leafletMap.on('click', (e) => this.setPin(e.latlng));
                } else {
                    this.leafletMap.invalidateSize();
                }
                // Edycja: pokaż już zapisaną pinezkę zamiast pustej mapy Polski —
                // inaczej nie widać, co aktualnie jest w bazie, dopóki się czegoś nie kliknie.
                if (this.leafletMarker) { this.leafletMap.removeLayer(this.leafletMarker); this.leafletMarker = null; }
                const existing = this.getTargetLatLng(this.mapTarget);
                if (existing) {
                    this.leafletMarker = L.marker(existing).addTo(this.leafletMap);
                    this.leafletMap.setView(existing, 13);
                } else {
                    this.leafletMap.setView([52.0, 19.3], 6);
                }
            });
        },
        getTargetLatLng(t) {
            if (t.kind === 'meetingPoint') {
                return (this.meetingPoint.lat && this.meetingPoint.lng) ? { lat: this.meetingPoint.lat, lng: this.meetingPoint.lng } : null;
            }
            const stage = this.stages[t.stageIndex];
            if (!stage) return null;
            if (t.which === 'start') {
                return (stage.startLat && stage.startLng) ? { lat: stage.startLat, lng: stage.startLng } : null;
            }
            return (stage.endLat && stage.endLng) ? { lat: stage.endLat, lng: stage.endLng } : null;
        },
        setPin(latlng) {
            if (this.leafletMarker) this.leafletMap.removeLayer(this.leafletMarker);
            this.leafletMarker = L.marker(latlng).addTo(this.leafletMap);

            const t = this.mapTarget;
            if (t.kind === 'meetingPoint') {
                this.meetingPoint.lat = latlng.lat;
                this.meetingPoint.lng = latlng.lng;
                if (this.stages[0] && !this.stages[0].startLat) {
                    this.stages[0].startLat = latlng.lat;
                    this.stages[0].startLng = latlng.lng;
                    if (!this.stages[0].startLabel) this.stages[0].startLabel = this.meetingPoint.label;
                }
            } else if (t.kind === 'stage') {
                if (t.which === 'start') {
                    this.stages[t.stageIndex].startLat = latlng.lat;
                    this.stages[t.stageIndex].startLng = latlng.lng;
                } else {
                    this.stages[t.stageIndex].endLat = latlng.lat;
                    this.stages[t.stageIndex].endLng = latlng.lng;
                    const next = this.stages[t.stageIndex + 1];
                    if (next && !next.startLat) {
                        next.startLat = latlng.lat;
                        next.startLng = latlng.lng;
                        if (!next.startLabel) next.startLabel = this.stages[t.stageIndex].endLabel;
                    }
                }
            }
        },
    }, initial);
}
</script>
