<script>
// Wysokość prawdziwego <header> serwisu jako zmienna CSS — .k-bar (sticky
// pasek postępu kreatora) musi zaczynać się dokładnie pod nim, a header bywa
// wyższy na mobile (zawija się do dwóch wierszy poniżej 800px), więc stały
// px w CSS by się rozjechał. Przeliczane też przy resize/zmianie orientacji.
(function () {
    var header = document.querySelector('header');
    if (!header) return;
    function sync() { document.documentElement.style.setProperty('--k-header-h', header.offsetHeight + 'px'); }
    sync();
    window.addEventListener('resize', sync);
})();

// Autozapis — świadomie tylko wizualny sygnał "nic nie wysyłamy" (patrz plan:
// prawdziwy autozapis to osobna funkcja backendu, poza zakresem tej zmiany;
// tekst musi zostać uczciwy, nie sugerować zapisu, którego nie ma).
(function () {
    var el = document.getElementById('kSaveTxt');
    if (!el) return;
    var base = el.textContent;
    var timer;
    document.addEventListener('input', function () {
        el.textContent = __('Wypełniasz…');
        clearTimeout(timer);
        timer = setTimeout(function () { el.textContent = base; }, 900);
    });
})();

function eventWizard(initial, billingComplete, isLoggedIn, isAdmin, regionLabels, initialError) {
    return Object.assign({
        billingComplete: billingComplete,
        coverPhotoSelected: !!initial.coverPhotoUrl,
        gpxUploading: {},
        mapOpen: false,
        mapTarget: null,
        leafletMap: null,
        leafletMarker: null,
        geocodeQuery: '',
        geocodeResults: [],
        geocodeToken: 0,
        titleManuallyEdited: !!initial.title,
        regionLabels: regionLabels || {},
        turnusOpen: false,
        whoMoreOpen: false,
        payMoreOpen: false,
        regBoxOpen: !!(initial.externalUrl || initial.externalPhone || initial.externalEmail),
        // BUG naprawiony 2026-08-09: initialError (komunikat z Event::save(),
        // przekazany serwerowo po przeładowaniu strony przy błędzie, który
        // nie złapał się na walidacji klienckiej) był używany TYLKO do
        // ustalenia wizardStarted/currentStep niżej — sam tekst nigdy nie
        // trafiał do submitError, więc baner (<p x-show="submitError">
        // w event-form-wizard.php) zostawał pusty mimo realnego, dobrego
        // komunikatu z serwera. Efekt identyczny jak zgłoszony brak komunikatu
        // przy required na ukrytym kroku — użytkownik nie wiedział, czego brakuje.
        submitError: initialError || '',
        // Warianty trasy (pętle) — variantsEnabled przełącza krok TRASA na listę
        // wariantów zamiast pojedynczej trasy.
        //
        // BUG naprawiony 2026-08-13: stan brał się z `initial.variants.length`,
        // czyli był ZGADYWANY z danych zamiast czytany z decyzji użytkownika.
        // Wystarczyło, że w tablicy wylądował jeden wiersz (choćby pusty, dopięty
        // przez toggleVariants()), żeby po nieudanym zapisie kreator sam zaznaczył
        // „kilka wariantów" przy jednej trasie — czyli twierdził o wyjeździe coś,
        // czego użytkownik nie wybrał. Teraz przełącznik jedzie w POST polem
        // `variants_enabled` (step-trasa.php) i wraca jako `variantsEnabled`.
        variantsEnabled: !!initial.variantsEnabled,
        variantGpxUploading: {},

        // --- Kreator: stan/nawigacja kroku (nowe, reszta obiektu = 1:1
        // dzisiejszy eventForm() z event-form.php) ---
        wizardStarted: !!initialError || !!initial.title,
        currentStep: initialError ? 'podsumowanie' : 'kogo',
        TYPE_META: {
            pokrec_z_kims: { name: __('Pokręcę z kimś'), color: 'var(--s-green)' },
            ustawka: { name: __('Zorganizowane wydarzenie'), color: 'var(--s-red)' },
            wycieczka_wielodniowa: { name: 'Wycieczka wielodniowa', color: 'var(--s-blue)' },
            wyscig: { name: __('Wyścig'), color: 'var(--s-purple)' },
        },
        DAY_COLORS: ['var(--s-red)', 'var(--s-green)', 'var(--s-blue)', '#B07A2B', '#6A4E8C'],
        // 'wyscig' liczone tak samo jak 'ustawka' — formularz jednodniowy identyczny.
        STEP_MIN: { pokrec_z_kims: 1, ustawka: 3, wycieczka_wielodniowa: 8, wyscig: 3 },

        startWizard(t) {
            this.type = t;
            this.wizardStarted = true;
            this.currentStep = this.visibleSteps()[0];
        },
        // Kroki zależą od typu i (dla "kogo") od stanu zalogowania — ten sam
        // zestaw warunków co dawniejsze x-if w event-form.php.
        visibleSteps() {
            const steps = [];
            const kogoNeeded = !(this.type === 'pokrec_z_kims' && this.isLoggedIn);
            if (kogoNeeded) steps.push('kogo');
            steps.push('kiedy', 'gdzie');
            // 'wyscig' dzieli krok "Trasa" z 'ustawka' — jednodniowy, tam żyją
            // warianty dystansu (patrz komentarz przy TYPE_META wyżej).
            if (this.type === 'ustawka' || this.type === 'wyscig') steps.push('trasa');
            if (this.type === 'wycieczka_wielodniowa') steps.push('plan');
            steps.push('oczym', 'dlakogo');
            if (this.type !== 'pokrec_z_kims') steps.push('pieniadze');
            steps.push('podsumowanie');
            return steps;
        },
        stepIndex() {
            return Math.max(0, this.visibleSteps().indexOf(this.currentStep));
        },
        isLastStep() {
            const steps = this.visibleSteps();
            return this.currentStep === steps[steps.length - 1];
        },
        progressPct() {
            const steps = this.visibleSteps();
            return Math.round((this.stepIndex() + 1) / steps.length * 100);
        },
        estimatedMinutesLeft() {
            const steps = this.visibleSteps();
            const left = Math.max(1, Math.round(this.STEP_MIN[this.type] * (1 - this.stepIndex() / steps.length)));
            return left;
        },
        // Komunikat błędu znika, gdy user ZACZYNA COŚ Z NIM ROBIĆ — czyli przy
        // pierwszej zmianie czegokolwiek w formularzu, a nie dopiero przy
        // kolejnej wysyłce (zgłoszenie usera 2026-08-13: „nie usuwa erroru
        // pomimo tego że wróciłem i poprawiłem"). Samo goToStep() nie
        // wystarczało: pola z błędnymi datami leżą na TYM SAMYM kroku, na
        // którym wisi baner, więc poprawka nie wywoływała żadnej nawigacji
        // i człowiek poprawiał dane, patrząc na komunikat, że są złe.
        clearSubmitError() {
            if (this.submitError) this.submitError = '';
        },
        goToStep(key) {
            this.submitError = '';
            this.currentStep = key;
            if (key === 'podsumowanie') this.fetchMatchSuggestions();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        // Walidacja przed publikacją — MIRROR warunków z EventController::create()
        // /finishCreate() (te same komunikaty), ale po stronie klienta, żeby
        // brak wymaganego pola NIE wywoływał przeładowania serwerowego, które
        // gubi wybraną okładkę (plik <input> nie przetrwa round-tripu). Przy
        // braku — przenosi do właściwego kroku i pokazuje baner (submitError),
        // bez wysyłki. Zwraca true dopiero, gdy komplet — wtedy natywny submit
        // idzie dalej (przyciski są type=submit, więc name=status też poleci).
        validateBeforeSubmit() {
            const emailOk = (v) => /.+@.+\..+/.test((v || '').trim());
            const jumpTo = (step, msg) => {
                this.currentStep = step;
                this.submitError = msg;
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return false;
            };
            if (this.type !== 'pokrec_z_kims') {
                if (this.organizerMode === 'self' && this.isLoggedIn) {
                    // "To ja" — bez dodatkowych pól kontaktu/organizatora.
                } else if (this.organizerMode === 'new') {
                    if (!this.isLoggedIn && !emailOk(this.submitterEmail)) return jumpTo('kogo', __('Podaj poprawny e-mail kontaktowy — potrzebny do weryfikacji zgłoszenia.'));
                    if (!(this.organizerSearchQuery || '').trim() || !emailOk(this.newOrganizerEmail)) return jumpTo('kogo', __('Podaj nazwę i poprawny e-mail nowego organizatora.'));
                } else { // 'existing'
                    if (!this.isLoggedIn && !emailOk(this.submitterEmail)) return jumpTo('kogo', __('Podaj poprawny e-mail kontaktowy — potrzebny do weryfikacji zgłoszenia.'));
                    if (!this.organizerId || this.organizerId <= 0) return jumpTo('kogo', __('Wybierz organizatora z listy podpowiedzi.'));
                }
            } else if (!this.isLoggedIn && !emailOk(this.submitterEmail)) {
                return jumpTo('kogo', __('Podaj poprawny e-mail — potrzebny do powiadomienia o publikacji.'));
            }
            // Data/region/opis miały kiedyś natywny "required" wprost na polu —
            // usunięte 2026-08-09 (zgłoszenie usera): pole żyje na kroku, który
            // bywa niewidoczny (x-show) w chwili kliknięcia "Opublikuj" (user
            // stoi na innym kroku); przeglądarka wtedy w ogóle nie potrafi
            // pokazać dymka walidacji na ukrytym polu i po prostu MILCZY —
            // klik nic nie robi, bez żadnego komunikatu. Stąd jawne sprawdzenia
            // tutaj, z tym samym jumpTo() co reszta walidacji w tej funkcji.
            if (!(this.stages[0].date || '').trim()) {
                return jumpTo('kiedy', this.type === 'pokrec_z_kims' ? __('Podaj termin (od kiedy).') : __('Podaj datę wydarzenia.'));
            }
            if (this.type === 'pokrec_z_kims' && !(this.endDate || '').trim()) {
                return jumpTo('kiedy', __('Podaj termin (do kiedy).'));
            }
            // Data końca przed początkiem — sprawdzane TUTAJ, bo dotąd łapał to
            // dopiero Event::save() i człowiek dowiadywał się o tym po pełnym
            // przeładowaniu strony. Obie daty leżą w tym samym kroku, dwa pola
            // obok siebie: komunikat po round-tripie serwerowym o czymś, co
            // widać gołym okiem, i tak był spóźniony.
            if ((this.endDate || '').trim() && (this.stages[0].date || '').trim()
                && this.endDate < this.stages[0].date) {
                return jumpTo('kiedy', __('Data zakończenia nie może być wcześniejsza niż data rozpoczęcia.'));
            }
            if (this.type === 'pokrec_z_kims' && !(this.region || '').trim()) {
                return jumpTo('gdzie', 'Wybierz region.');
            }
            if (!(this.title || '').trim()) return jumpTo('oczym', __('Podaj tytuł wydarzenia.'));
            if (this.type === 'pokrec_z_kims' && !(this.description || '').trim()) {
                return jumpTo('oczym', __('Napisz krótki opis — dokąd, w jakim tempie, czego szukasz.'));
            }
            // Warianty włączone, ale żaden nie ma nazwy — inaczej zapisze się
            // event bez trasy (puste warianty są pomijane przy zapisie).
            if (this.variantsEnabled && !this.variants.some(v => (v.name || '').trim())) {
                return jumpTo('trasa', __('Dodaj przynajmniej jeden wariant z nazwą albo wyłącz „Kilka wariantów trasy”.'));
            }
            this.submitError = '';
            return true;
        },
        next() {
            const steps = this.visibleSteps();
            const i = this.stepIndex();
            if (i < steps.length - 1) this.goToStep(steps[i + 1]);
        },
        back() {
            const steps = this.visibleSteps();
            const i = this.stepIndex();
            if (i > 0) this.goToStep(steps[i - 1]);
        },
        previewMetaLine() {
            const parts = [];
            if (this.stages[0] && this.stages[0].date) parts.push(this.stages[0].date);
            if ((this.type === 'ustawka' || this.type === 'wyscig') && this.stages[0] && this.stages[0].distanceKm) parts.push(this.stages[0].distanceKm + ' km');
            if (this.region && this.regionLabels[this.region]) parts.push(this.regionLabels[this.region]);
            return parts.length ? parts.join(' · ') : 'termin · dystans';
        },

        // Etap 2 (dopasowania) — dziś live-debounced na każde pole w kroku
        // "Zbiórka", tu odpalane JEDNORAZOWO przy wejściu na podsumowanie
        // (patrz goToStep() wyżej) — świadome przyjęcie umiejscowienia z
        // makiety: sprawdź dopasowania tuż przed publikacją.
        matchSuggestions: [],
        matchWideningLabel: null,
        matchFetchToken: 0,
        dismissedMatchUrls: [],
        async fetchMatchSuggestions() {
            const startDate = (this.stages[0] || {}).date;
            if (!startDate) { this.matchSuggestions = []; return; }
            const params = new URLSearchParams({ startDate });
            if (this.endDate) params.set('endDate', this.endDate);
            if (this.region) params.set('region', this.region);
            if (this.pace) params.set('pace', this.pace);
            if (this.meetingPoint.lat) params.set('lat', this.meetingPoint.lat);
            if (this.meetingPoint.lng) params.set('lng', this.meetingPoint.lng);
            if (this.existingId) params.set('excludeEventId', this.existingId);
            (this.bikeTypes || []).forEach(code => params.append('bikeTypes[]', code));
            const myToken = ++this.matchFetchToken;
            const res = await fetch(<?= json_encode(Utils\View::url('/api/matches/preview')) ?> + '?' + params.toString());
            if (myToken !== this.matchFetchToken || !res.ok) return;
            const data = await res.json();
            this.matchWideningLabel = data.wideningLabel;
            this.matchSuggestions = (data.matches || []).filter(m => !this.dismissedMatchUrls.includes(m.url));
        },
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
        skipDismissReason() { this.matchReasonPrompt = null; },
        async postDismiss(eventId, reason) {
            const body = new URLSearchParams({ csrf_token: <?= json_encode(\Core\Csrf::token()) ?>, eventId: String(eventId) });
            if (reason) body.set('reason', reason);
            try {
                await fetch(<?= json_encode(Utils\View::url('/api/matches/dismiss')) ?>, { method: 'POST', body });
            } catch (e) { /* odrzucenie lokalne już się stało */ }
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
            if (query.length < 2) { this.organizerSearchResults = []; return; }
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

        categorySearchResults: [],
        categorySearchFocusIndex: null,
        categorySearchToken: 0,
        async searchCategories(i) {
            this.categorySearchFocusIndex = i;
            const query = (this.priceItems[i].category || '').trim();
            if (query.length < 2) { this.categorySearchResults = []; return; }
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
        closeCategoryResults() { this.categorySearchResults = []; this.categorySearchFocusIndex = null; },

        equipmentSearchResults: [],
        equipmentSearchFocusIndex: null,
        equipmentSearchToken: 0,
        async searchEquipment(i) {
            this.equipmentSearchFocusIndex = i;
            const query = (this.equipment[i].name || '').trim();
            if (query.length < 2) { this.equipmentSearchResults = []; return; }
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
        closeEquipmentResults() { this.equipmentSearchResults = []; this.equipmentSearchFocusIndex = null; },

        init() {
            this.$watch('region', () => this.maybeSuggestTitle());
            this.$watch('stages[0].date', () => this.maybeSuggestTitle());
            this.$watch('endDate', () => this.maybeSuggestTitle());
            this.$watch('dateIsFlexible', () => this.maybeSuggestTitle());
            this.$watch('type', () => this.maybeSuggestTitle());
            if (this.currentStep === 'podsumowanie') this.fetchMatchSuggestions();
        },
        maybeSuggestTitle() {
            if (this.type !== 'pokrec_z_kims' || this.titleManuallyEdited) return;
            const regionName = this.regionLabels[this.region];
            const dateLabel = this.formatDateRangeShort(this.stages[0].date, this.endDate);
            if (!regionName && !dateLabel) return;
            this.title = [regionName, dateLabel].filter(Boolean).join(', ');
        },
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

        async searchGeocode() {
            const query = this.geocodeQuery.trim();
            if (query.length < 3) { this.geocodeResults = []; return; }
            const myToken = ++this.geocodeToken;
            try {
                const res = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&countrycodes=pl&q=' + encodeURIComponent(query), {
                    headers: { 'Accept-Language': 'pl' },
                });
                if (myToken !== this.geocodeToken) return;
                const data = res.ok ? await res.json() : [];
                this.geocodeResults = data.map((r) => ({ placeId: r.place_id, label: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon) }));
            } catch (e) { this.geocodeResults = []; }
        },
        selectGeocodeResult(r) {
            this.setPin({ lat: r.lat, lng: r.lng });
            if (this.mapTarget && this.mapTarget.kind === 'meetingPoint' && !this.meetingPoint.label) {
                this.meetingPoint.label = r.label;
            }
            this.geocodeQuery = '';
            this.geocodeResults = [];
            if (this.leafletMap) this.leafletMap.setView([r.lat, r.lng], 13);
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
        // Wywoływane z @change przełącznika w kroku TRASA — po włączeniu opcji od
        // razu dodaje pierwszy pusty wariant, żeby lista nie była pusta.
        toggleVariants() {
            if (this.variantsEnabled && this.variants.length === 0) this.addVariant();
        },
        addEditionDate() { this.additionalDates.push(''); },
        removeEditionDate(i) { this.additionalDates.splice(i, 1); },
        onCoverPhotoChange(event) { this.coverPhotoSelected = event.target.files.length > 0; },
        // Cofnięcie wyboru okładki. Czyści TRZY rzeczy, bo zdjęcie może
        // pochodzić z trzech źródeł i każde trzeba wyzerować osobno:
        //   1) .value inputu pliku — bez tego przeglądarka i tak wyśle plik,
        //      niezależnie od stanu Alpine (stan nie steruje <input type=file>);
        //   2) coverPhotoSelected — odblokowuje z powrotem pole "wklej link"
        //      (jest :disabled, gdy plik wybrany) i przywraca napis "Dodaj zdjęcie";
        //   3) coverPhotoUrl — JUŻ ZAPISANA okładka (dotyczy edycji): zasila
        //      ukryte existing_cover_photo_url, więc bez wyzerowania serwer
        //      przywróciłby stare zdjęcie mimo "usunięcia" (patrz
        //      Resources\EventFormInput::fromRequest(), ostatni fallback).
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
                this.stages[i].surfaceAsphaltPct = data.surface ? data.surface.asphaltPct : null;
                this.stages[i].surfaceGravelPct  = data.surface ? data.surface.gravelPct  : null;
                this.stages[i].surfaceTrailPct   = data.surface ? data.surface.trailPct   : null;
            } catch (e) {
                alert(__('Nie udało się wczytać GPX: ') + e.message);
            } finally {
                this.gpxUploading = Object.assign({}, this.gpxUploading, { [i]: false });
            }
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
