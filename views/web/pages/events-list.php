<?php
// views/web/pages/events-list.php
// Pełna, filtrowalna lista wydarzeń — przebudowa wg makiety
// szablony/wydarzenia.html (2026-07-31/08-01). Panel filtrów dostał NOWE
// klasy (.filters/.fg/.opt/...) świadomie różne od .filters-panel/
// .filter-group używanych na /organizatorzy (organizers-list.php) — tamta
// strona nie jest dziś przerabiana i nie powinna dziedziczyć tych zmian.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$events        = $events ?? [];
$total         = $total ?? 0;
$limit         = $limit ?? 24;
$sort          = $sort ?? 'date';
$filters       = $filters ?? [];
$filterOptions = $filterOptions ?? [];
$filterCounts  = $filterCounts ?? [];
$view          = $view ?? 'upcoming';
$mine          = $mine ?? false;
$breadcrumbs   = $breadcrumbs ?? [];

$isChecked = fn(string $group, string $code) => in_array($code, $filters[$group] ?? [], true) ? 'checked' : '';
$cnt       = fn(string $group, string $code) => (int) ($filterCounts[$group][$code] ?? 0);

// Format (typ wydarzenia) — jedyna grupa z kolorowym znacznikiem .bz, ten sam
// kod barw co karty i sekcja "Cztery formaty" na stronie głównej.
$eventTypeColor = fn(string $code) => match ($code) {
    'wycieczka_wielodniowa' => '--s-blue',
    'pokrec_z_kims'         => '--s-green',
    'wyscig'                => '--s-purple',
    default                 => '--s-red',
};
$renderEventTypeGroup = function () use ($filterOptions, $isChecked, $cnt, $eventTypeColor) {
    if (empty($filterOptions['eventTypes'])) return;
    ?>
    <div class="fg">
        <h3><?= __('Format') ?></h3>
        <?php foreach ($filterOptions['eventTypes'] as $opt): ?>
        <label class="opt" style="--bz:var(<?= $eventTypeColor($opt['code']) ?>);">
            <input type="checkbox" data-filter="eventTypes" value="<?= htmlspecialchars($opt['code']) ?>" <?= $isChecked('eventTypes', $opt['code']) ?>>
            <span class="bz"></span><?= htmlspecialchars($opt['name']) ?><span class="cnt"><?= $cnt('eventTypes', $opt['code']) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <?php
};
// Trzy pozostałe grupy checkboxowe (rower/trudność/tempo) mają identyczny,
// prostszy szkielet — bez kolorowego znacznika.
$renderFilterGroup = function (string $heading, string $groupKey) use ($filterOptions, $isChecked, $cnt) {
    if (empty($filterOptions[$groupKey])) return;
    ?>
    <div class="fg">
        <h3><?= htmlspecialchars($heading) ?></h3>
        <?php foreach ($filterOptions[$groupKey] as $opt): ?>
        <label class="opt"><input type="checkbox" data-filter="<?= htmlspecialchars($groupKey) ?>" value="<?= htmlspecialchars($opt['code']) ?>" <?= $isChecked($groupKey, $opt['code']) ?>><?= htmlspecialchars($opt['name']) ?><span class="cnt"><?= $cnt($groupKey, $opt['code']) ?></span></label>
        <?php endforeach; ?>
    </div>
    <?php
};
// Region ma inny kształt — Dictionary::groupedLeaves() zwraca grupy {label,
// items[]} pod nagłówkiem kraju (patrz migration_013_dictionary_hierarchy.sql).
$renderRegionFilterGroup = function () use ($filterOptions, $isChecked, $filterCounts) {
    if (empty($filterOptions['regions'])) return;
    ?>
    <div class="fg">
        <h3><?= __('Region') ?></h3>
        <?php foreach ($filterOptions['regions'] as $group): ?>
        <?php if ($group['label'] !== null): ?>
        <div class="filter-subheading"><?= htmlspecialchars($group['label']) ?></div>
        <?php endif; ?>
        <?php foreach ($group['items'] as $opt): ?>
        <label class="opt<?= $group['label'] !== null ? ' filter-opt-nested' : '' ?>"><input type="checkbox" data-filter="regions" value="<?= htmlspecialchars($opt['code']) ?>" <?= $isChecked('regions', $opt['code']) ?>><?= htmlspecialchars($opt['name']) ?><span class="cnt"><?= (int) ($filterCounts['regions'][$opt['code']] ?? 0) ?></span></label>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php
};

$activeChip = 'all';
if ($view === 'completed') $activeChip = 'completed';
elseif (($filters['paid'] ?? null) === 'free') $activeChip = 'free';
elseif (($filters['paid'] ?? null) === 'paid') $activeChip = 'paid';
elseif (($filters['when'] ?? null) === 'weekend') $activeChip = 'weekend';
elseif (($filters['when'] ?? null) === 'month') $activeChip = 'month';
elseif (in_array('pokrec_z_kims', $filters['eventTypes'] ?? [], true)) $activeChip = 'towarzystwo';

// Domyślnie „bez limitu" = górny kres suwaka, wyliczany niżej z danych.
$maxDistance    = $filters['maxDistanceKm'] ?? Models\Event::maxDistanceKm();
$maxPrice       = $filters['maxPriceAmount'] ?? 2000;
$radiusKm       = $filters['radiusKm'] ?? 300;
$durationChecked = fn(string $bucket) => in_array($bucket, $filters['durationBuckets'] ?? [], true) ? 'checked' : '';
?>
<?php // Okruszki pominięte w apce — ten sam odruch co na mapie i w
      // Wiadomościach (Fazy 1/2 przebudowy UX, tasks/done/apka-mobilna-ux.md):
      // mniej chromu na ciasnym ekranie, nawigacja główna to dolny pasek. ?>
<?php // Okruszki: bramkę „nie w apce" trzyma teraz sam partial (2026-09-11) —
      // zasada obowiązywała tylko w czterech szablonach, a dotyczy wszystkich. ?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>

<div class="wrap list-top">
    <h1><?= __('Wyjazdy rowerowe w Polsce') ?></h1>
    <p><?= __('Zorganizowane wydarzenia na jeden dzień, wielodniowe wycieczki z noclegiem i ogłoszenia „Pokręcę z kimś”.
        Bezpłatne i płatne — filtruj po tym, co dla Ciebie ważne.') ?></p>

    <div class="search-pill">
        <div class="search-pill__f">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" class="icon"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input data-role="search" placeholder="<?= htmlspecialchars(__('Region, miasto albo nazwa wyjazdu')) ?>" value="<?= htmlspecialchars($filters['q'] ?? '') ?>">
        </div>
        <button class="locate-btn" type="button"><?= Utils\Icon::render('pin') ?> <span data-role="locate-label"><?= __('Blisko mnie') ?></span></button>
    </div>

    <div class="chip-row" data-role="chips" style="margin-top:14px;">
        <?php // Liczba przy „Zakończone" pokazuje się TYLKO na „Moich wydarzeniach"
              // — tam jest o czym mówić („w co się zapisałem i już się odbyło").
              // Na ogólnej liście byłaby licznikiem całego archiwum serwisu,
              // czyli czymś zupełnie innym pod tym samym podpisem. ?>
        <?php
        // Liczone TUTAJ, nie brane z header.php: widok strony renderuje się do
        // bufora ZANIM layout dołączy nagłówek, więc jego zmienne są tu jeszcze
        // nieznane.
        $myPast = 0;
        if (!empty($mine) && ($viewer = Core\Auth::user())) {
            $myPast = Models\EventRsvp::confirmedEditionCountsForUser($viewer->id)['past'];
        }
        ?>
        <div class="chip <?= $activeChip === 'all' ? 'active' : '' ?>" data-chip="all"><?= __('Wszystkie nadchodzące') ?></div>
        <div class="chip <?= $activeChip === 'completed' ? 'active' : '' ?>" data-chip="completed"><?= __('Zakończone') ?><?php
            if ($myPast > 0): ?> (<?= (int) $myPast ?>)<?php endif; ?></div>
        <div class="chip <?= $activeChip === 'free' ? 'active' : '' ?>" data-chip="free"><?= __('Darmowe') ?></div>
        <div class="chip <?= $activeChip === 'paid' ? 'active' : '' ?>" data-chip="paid"><?= __('Płatne') ?></div>
        <div class="chip <?= $activeChip === 'weekend' ? 'active' : '' ?>" data-chip="weekend"><?= __('Ten weekend') ?></div>
        <div class="chip <?= $activeChip === 'month' ? 'active' : '' ?>" data-chip="month"><?= __('Ten miesiąc') ?></div>
        <div class="chip <?= $activeChip === 'towarzystwo' ? 'active' : '' ?>" data-chip="towarzystwo"><?= __('Szukają towarzystwa') ?></div>
    </div>
</div>


<div class="wrap layout">
    <aside class="filters" id="filters" data-role="filters">
        <div class="filters__hd">
            <b><?= __('Filtry') ?></b>
            <div class="filters__hd-actions">
                <a href="<?= Utils\View::url('/wydarzenia') ?>"><?= __('Wyczyść') ?></a>
                <?php // Zamknięcie arkusza w apce — ukryty CSS-em poza APP_IS_APP
                      // (tam panel jest inline, zamyka go ten sam przycisk „Filtry"
                      // nad wynikami, patrz .filters__close w style.css). ?>
                <button type="button" class="filters__close" data-role="close-filters" aria-label="<?= htmlspecialchars(__('Zamknij filtry')) ?>">
                    <?= Utils\Icon::render('close') ?>
                </button>
            </div>
        </div>

        <?php $renderEventTypeGroup(); ?>

        <div class="fg">
            <h3><?= __('Termin') ?></h3>
            <div class="dates">
                <label class="sr" for="date-from"><?= __('Od') ?></label>
                <input id="date-from" type="date" data-filter-plain="dateFrom" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>">
                <label class="sr" for="date-to"><?= __('Do') ?></label>
                <input id="date-to" type="date" data-filter-plain="dateTo" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>">
            </div>
        </div>

        <div class="fg" id="radius-fg">
            <h3><?= __('Odległość od Ciebie') ?></h3>
            <input class="rng" type="range" min="10" max="300" step="10" value="<?= (int) $radiusKm ?>" data-filter-plain="radiusKm" id="radius-range" disabled>
            <div class="rng__lbl"><span><?= __('10 km') ?></span><span data-role="radius-label"><?= __('nieaktywne') ?></span><span><?= __('300 km') ?></span></div>
            <p class="fg-hint" data-role="radius-hint"><?= __('Kliknij „Blisko mnie” wyżej, żeby aktywować.') ?></p>
        </div>

        <div class="fg">
            <h3><?= __('Długość wyjazdu') ?></h3>
            <label class="opt"><input type="checkbox" data-filter="durationBuckets" value="1" <?= $durationChecked('1') ?>><?= __('1 dzień') ?><span class="cnt"><?= (int) ($filterCounts['durationBuckets']['1'] ?? 0) ?></span></label>
            <label class="opt"><input type="checkbox" data-filter="durationBuckets" value="2-3" <?= $durationChecked('2-3') ?>><?= __('2–3 dni') ?><span class="cnt"><?= (int) ($filterCounts['durationBuckets']['2-3'] ?? 0) ?></span></label>
            <label class="opt"><input type="checkbox" data-filter="durationBuckets" value="4plus" <?= $durationChecked('4plus') ?>><?= __('4 dni i więcej') ?><span class="cnt"><?= (int) ($filterCounts['durationBuckets']['4plus'] ?? 0) ?></span></label>
        </div>

        <div class="fg">
            <h3><?= __('Dystans trasy') ?></h3>
            <?php
            // GÓRNY KRES Z DANYCH, nie na sztywno (2026-08-13). Do tej pory było
            // max=300, gdzie 300 znaczyło „bez limitu" — ultramaratony (Wisła
            // 1200 to 1200 km) nie wypadały z wyników, ale wpadały do jednego
            // worka z każdą trasą powyżej 300 km i nie dało się ich odfiltrować.
            //
            // Krok rośnie wraz z wartością: 5 km do 200 km (gdzie mieści się
            // większość wyjazdów i różnica 5 km ma znaczenie), 25 km wyżej.
            // Liniowy krok 5 km przy kresie 1200 dałby 240 pozycji, z czego
            // 95% wydarzeń zajmowałoby pierwszą szóstą część suwaka.
            $distanceMax = Models\Event::maxDistanceKm();
            $distanceStep = $distanceMax > 400 ? 25 : 5;
            ?>
            <input type="range" class="rng" min="5" max="<?= $distanceMax ?>" step="<?= $distanceStep ?>"
                   value="<?= (int) $maxDistance ?>" data-filter-plain="maxDistanceKm" id="distance-range">
            <div class="rng__lbl"><span><?= __('5 km') ?></span><span data-role="distance-label"><?= (int) $maxDistance >= $distanceMax ? 'bez limitu' : (int) $maxDistance . ' km' ?></span><span><?= $distanceMax ?> km</span></div>
        </div>

        <?php
            $renderFilterGroup(__('Typ roweru'), 'bikeTypes');
            $renderFilterGroup(__('Poziom trudności'), 'difficulties');
            $renderFilterGroup(__('Tempo grupy'), 'paces');
            $renderRegionFilterGroup();
        ?>

        <div class="fg">
            <h3><?= __('Budżet') ?></h3>
            <input class="rng" type="range" min="0" max="2000" step="50" value="<?= (int) $maxPrice ?>" data-filter-plain="maxPriceAmount" id="budget-range">
            <div class="rng__lbl"><span><?= __('0 zł') ?></span><span data-role="budget-label"><?= (int) $maxPrice >= 2000 ? __('bez limitu') : (int) $maxPrice . ' zł' ?></span></div>
            <label class="sw"><span><?= __('Tylko bezpłatne') ?></span><input type="checkbox" data-filter-paid-free <?= ($filters['paid'] ?? null) === 'free' ? 'checked' : '' ?>></label>
        </div>

        <div class="fg">
            <h3><?= __('Organizator') ?></h3>
            <label class="opt"><input type="checkbox" data-filter-bool="verifiedOnly" <?= !empty($filters['verifiedOnly']) ? 'checked' : '' ?>><?= __('Tylko zweryfikowani') ?><span class="cnt"><?= (int) ($filterCounts['verifiedOnly'] ?? 0) ?></span></label>
            <label class="opt"><input type="checkbox" data-filter-bool="hasSpotsOnly" <?= !empty($filters['hasSpotsOnly']) ? 'checked' : '' ?>><?= __('Są wolne miejsca') ?><span class="cnt"><?= (int) ($filterCounts['hasSpotsOnly'] ?? 0) ?></span></label>
        </div>

        <?php // WIDOK — nie filtruje wyników, tylko decyduje, co jest na ekranie.
              // Dlatego NIE jedzie do serwera jak reszta panelu (żadnego
              // data-filter-*): przełącza sekcję na miejscu i zapamiętuje wybór
              // w localStorage. Parametr w adresie byłby mylący — link
              // podesłany komuś innemu niósłby MOJĄ preferencję widoku, a jego
              // dopasowania i tak są inne.
              //
              // Grupa pokazuje się tylko wtedy, gdy jest co przełączać:
              // przełącznik czegoś, czego dla mnie nie ma, to obietnica bez
              // pokrycia. ?>
        <?php if (!empty($matchCards)): ?>
        <div class="fg">
            <h3><?= __('Widok') ?></h3>
            <label class="opt"><input type="checkbox" data-role="toggle-matches" checked><?= __('Pokaż dopasowania') ?><span class="cnt"><?= count($matchCards) ?></span></label>
        </div>
        <?php endif; ?>
    </aside>

    <div class="results-column">
        <!-- Przełącznik żyje TU, poza <main> i poza #results-region — inaczej
             znikałby razem z kartami, gdy <main> jest ukrywane w widoku mapy,
             i nie dałoby się wrócić z powrotem na karty. -->
        <div class="view-toggle" data-role="view-toggle">
            <button type="button" class="view-btn active" data-view="cards"><?= Utils\Icon::render('grid') ?> Karty</button>
            <button type="button" class="view-btn" data-view="map"><?= Utils\Icon::render('pin') ?> <?= __('Mapa') ?></button>
        </div>

        <?php
            // DOPASOWANIA — pierwsza sekcja obszaru wyników (2026-08-14).
            // Wcześniej stały NAD całą wyszukiwarką, czyli przed pytaniem
            // „czego szukasz" — a to jest odpowiedź, nie wstęp.
            //
            // POZA <main>, tak samo jak przełącznik karty/mapa wyżej i z tego
            // samego powodu: filtrowanie robi `resultsMain.innerHTML = html`,
            // czyli podmienia CAŁĄ zawartość <main>. Wewnątrz sekcja znikała po
            // pierwszym kliknięciu w filtr — zgłoszone przez usera przy
            // „Blisko mnie" (poprawka mojego błędu z pierwszego podejścia:
            // wstawiłem ją do <main> zakładając, że AJAX podmienia sam
            // #results-region; podmienia więcej).
            //
            // Dopasowania i tak nie zależą od filtrów listy — liczy je
            // MatchEngine z profilu użytkownika, nie z zaznaczonych checkboxów.
            // Gdyby jechały z każdym odświeżeniem, mieliłyby silnik i zapisywały
            // RecommendationLog raz za razem, zaśmiecając statystyki trafności.
            $matchCards = $matchCards ?? [];
            require __DIR__ . '/../partials/match-grid.php';
        ?>

        <main>
            <?php require __DIR__ . '/../partials/event-results.php'; ?>
        </main>

        <!-- Poza #results-region (patrz komentarz w event-results.php) — trwa
             przez całe filtrowanie, nie jest tworzona/niszczona na nowo. -->
        <div class="map-view" id="map-view" style="display:none;">
            <div class="event-map" id="event-map"></div>
            <button type="button" class="search-area-btn" id="search-area-btn" style="display:none;"><?= __('Szukaj w tym obszarze') ?></button>
        </div>
    </div>
</div>

<?php if (!$mine && $view !== 'completed'): ?>
<section class="wrap list-seo">
    <h2><?= __('Wyjazdy rowerowe — jak znaleźć swój') ?></h2>
    <p><?= __('Tu spotykają się ludzie, którzy chcą jeździć razem w Polsce i na Świecie: jednodniowe wspólne jazdy
        prowadzone przez lokalne ekipy, wielodniowe wycieczki rowerowe zweryfikowanych operatorów turystycznych
        oraz ogłoszenia osób, które szukają towarzystwa na trasę. Każdy wyjazd prowadzi organizator wskazany
        na karcie wydarzenia — to on odpowiada za przebieg i u niego się zapisujesz.') ?></p>
    <p><?= __('Korzystanie z ridemore.bike jest bezpłatne, a od płatnych wycieczek nie pobieramy prowizji — cała kwota
        trafia do organizatora. Wielodniowe wyjazdy płatne mogą publikować wyłącznie podmioty z wpisem do
        rejestru organizatorów turystyki i aktualną gwarancją ubezpieczeniową.') ?></p>
    <?php if (!empty($filterOptions['regions'])): ?>
    <div class="chip-links">
        <?php foreach ($filterOptions['regions'] as $group): foreach (array_slice($group['items'], 0, 6) as $opt): ?>
        <?php // Strona regionu, nie filtr (2026-09-14) — to blok dla wyszukiwarki,
              // a filtr ma canonical na samo /wydarzenia. ?>
        <a class="chip" href="<?= Utils\View::url(Models\Region::pathForCode($opt['code']) ?? '/wydarzenia?regions[]=' . urlencode($opt['code'])) ?>"><?= htmlspecialchars($opt['name']) ?></a>
        <?php endforeach; endforeach; ?>
        <a class="chip" href="<?= Utils\View::url('/wydarzenia?bikeTypes[]=gravel') ?>"><?= __('Wyjazdy gravelowe') ?></a>
        <a class="chip" href="<?= Utils\View::url('/wydarzenia?bikeTypes[]=mtb') ?>"><?= __('Wyjazdy MTB') ?></a>
        <a class="chip" href="<?= Utils\View::url('/organizatorzy') ?>"><?= __('Organizatorzy') ?></a>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<script>
(function () {
    var chipsRow    = document.querySelector('[data-role="chips"]');
    var filtersPane = document.querySelector('[data-role="filters"]');
    var resultsMain = document.querySelector('.layout main');

    // POKAŻ/UKRYJ DOPASOWANIA. Czysto po stronie klienta: sekcja żyje poza
    // <main>, więc filtrowanie jej nie rusza, a serwer nie musi o tym wiedzieć.
    // Wybór trzymamy w localStorage, nie w adresie — to preferencja widoku
    // tej osoby, a nie filtr wyników, więc nie ma czego przenosić linkiem.
    (function () {
        var box = document.querySelector('[data-role="toggle-matches"]');
        var sec = document.querySelector('.match-grid-sec');
        if (!box || !sec) return;
        var KEY = 'ridemore_show_matches';
        var zapisane = localStorage.getItem(KEY);
        if (zapisane === '0') { box.checked = false; sec.hidden = true; }
        box.addEventListener('change', function () {
            sec.hidden = !box.checked;
            localStorage.setItem(KEY, box.checked ? '1' : '0');
        });
    })();
    var distanceLbl = document.querySelector('[data-role="distance-label"]');
    var budgetLbl   = document.querySelector('[data-role="budget-label"]');
    var radiusLbl   = document.querySelector('[data-role="radius-label"]');
    var radiusHint  = document.querySelector('[data-role="radius-hint"]');
    var radiusInput = document.getElementById('radius-range');
    var searchInput = document.querySelector('[data-role="search"]');
    var basePath    = <?= json_encode(Utils\View::url('/wydarzenia')) ?>;
    var mapApiPath  = <?= json_encode(Utils\View::url('/api/events/map')) ?>;
    var activeChip  = <?= json_encode($activeChip) ?>;
    var isMine      = <?= json_encode($mine) ?>;
    var fetchToken  = 0;
    var searchTimer = null;
    // Ustawione dopiero po udanej geolokalizacji ("Blisko mnie") — dopóki
    // null, suwak "Odległość od Ciebie" zostaje wygaszony (patrz radiusInput
    // niżej), bo bez współrzędnych ten filtr nic by nie znaczył.
    var userCoords  = null;

    // Zapisujemy bieżący kontekst od razu przy wejściu na stronę — nie tylko
    // przy zmianie filtrów (fetchResults niżej) — bo samo kliknięcie
    // "Wydarzenia"/"Moje wydarzenia" w nagłówku (bez dotykania filtrów) też
    // musi zaktualizować to, co ui.js pokaże jako aktywny kontekst gdziekolwiek
    // indziej w serwisie (patrz stronę wydarzenia).
    // PEŁNY ADRES, jak wszystko, co serwis wypuszcza od 2026-09-03 (patrz
    // `Utils\View::url`) — ta wartość ląduje potem w `href` linku w nagłówku
    // (ui.js), więc była JEDYNYM adresem na stronie bez schematu i hosta.
    sessionStorage.setItem(isMine ? 'ridemore_mine_url' : 'ridemore_events_url',
        location.origin + location.pathname + location.search);
    sessionStorage.setItem('ridemore_list_context', isMine ? 'mine' : 'events');

    // Widok karty/mapa — stan i kontener mapy żyją POZA #results-region (ten
    // region jest całkowicie podmieniany przy każdym filtrowaniu, patrz
    // fetchResults). Mapa nie jest paginowana jak karty — pobiera WSZYSTKIE
    // pasujące wydarzenia z osobnego, niepaginowanego endpointu (Event::mapPins()),
    // inaczej pokazywałaby tylko pierwsze 24 wyniki kart i większość by "znikała".
    var viewMode        = 'cards';
    var viewToggle       = document.querySelector('[data-role="view-toggle"]');
    var mapView         = document.getElementById('map-view');
    var mapEl           = document.getElementById('event-map');
    var searchAreaBtn   = document.getElementById('search-area-btn');
    var eventMap        = null;
    var mapMarkers      = [];
    var activeGpxLayer  = null;
    var suppressMoveEnd = false;

    function applyViewMode() {
        viewToggle.querySelectorAll('[data-view]').forEach(function (b) {
            b.classList.toggle('active', b.dataset.view === viewMode);
        });
        if (viewMode === 'map') {
            resultsMain.style.display = 'none';
            mapView.style.display = 'block';
            loadMapForCurrentFilters();
        } else {
            resultsMain.style.display = '';
            mapView.style.display = 'none';
        }
    }

    // Zabezpieczenie: gdyby 'moveend' z jakiegoś powodu nie odpalił się wcale
    // (np. karta w tle, rAF wstrzymany), flaga i tak wraca do false po chwili —
    // inaczej zostałaby "utknięta" na true i gasiła WSZYSTKIE późniejsze,
    // prawdziwe przesunięcia mapy przez usera.
    function suppressNextMoveEnd() {
        suppressMoveEnd = true;
        setTimeout(function () { suppressMoveEnd = false; }, 500);
    }

    function ensureMapInitialized() {
        if (eventMap) return;
        eventMap = ridemoreCreateMap(mapEl);
        // "Szukaj w tym obszarze" — styl Google/Airbnb Maps: przesunięcie/zoom
        // NIE doładowuje automatycznie (uniknięcie zalewu requestów przy
        // przeciąganiu), tylko pokazuje przycisk. Wyjątek: programistyczny
        // fitBounds() po świeżym fetchu — suppressMoveEnd gasi ten jeden event.
        eventMap.on('moveend', function () {
            if (suppressMoveEnd) { suppressMoveEnd = false; return; }
            if (viewMode === 'map') searchAreaBtn.style.display = 'block';
        });
    }

    function clearMarkers() {
        mapMarkers.forEach(function (m) { eventMap.removeLayer(m); });
        mapMarkers = [];
        if (activeGpxLayer) {
            eventMap.removeLayer(activeGpxLayer);
            activeGpxLayer = null;
        }
    }

    function renderMarkers(events, fitToResults) {
        clearMarkers();
        var bounds = [];
        events.forEach(function (ev) {
            var marker = L.marker([ev.lat, ev.lng]).addTo(eventMap);
            var popupHtml = '<b>' + escapeHtml(ev.title) + '</b><br>' + escapeHtml(ev.dateLabel || '') +
                ' · ' + escapeHtml(ev.distanceLabel || '') +
                '<br><a href="' + ev.url + __('">Zobacz szczegóły →</a>');
            marker.bindPopup(popupHtml);
            marker.on('click', function () { showGpxPreview(ev); });
            mapMarkers.push(marker);
            bounds.push([ev.lat, ev.lng]);
        });
        if (fitToResults && bounds.length) {
            // animate:false — bez tego "moveend" dopiero po zakończeniu animacji.
            suppressNextMoveEnd();
            eventMap.fitBounds(bounds, { padding: [30, 30], animate: false });
        }
    }

    function fetchMapPins(bounds) {
        var params = buildParams();
        if (bounds) {
            params.set('north', bounds.getNorth());
            params.set('south', bounds.getSouth());
            params.set('east', bounds.getEast());
            params.set('west', bounds.getWest());
        }
        return fetch(mapApiPath + '?' + params.toString()).then(function (res) { return res.json(); });
    }

    function loadMapForCurrentFilters() {
        ensureMapInitialized();
        searchAreaBtn.style.display = 'none';
        var myToken = ++fetchToken;
        fetchMapPins(null).then(function (events) {
            if (myToken !== fetchToken) return;
            renderMarkers(events, true);
            // Kontener bywał display:none w momencie tworzenia mapy — bez tego
            // Leaflet liczy rozmiar na 0x0 i kafelki tła się nie ładują.
            setTimeout(function () { eventMap.invalidateSize(); }, 60);
        });
    }

    if (searchAreaBtn) {
        searchAreaBtn.addEventListener('click', function () {
            searchAreaBtn.style.display = 'none';
            fetchMapPins(eventMap.getBounds()).then(function (events) {
                renderMarkers(events, false); // nie przesuwamy widoku — user go właśnie ustawił
            });
        });
    }

    function showGpxPreview(ev) {
        if (!ev.gpxUrls || !ev.gpxUrls.length) return;
        if (activeGpxLayer) {
            eventMap.removeLayer(activeGpxLayer);
            activeGpxLayer = null;
        }
        var group = L.layerGroup().addTo(eventMap);
        ev.gpxUrls.forEach(function (url) {
            ridemoreAddGpxTrack(group, url, {
                onLoaded: function (e) {
                    suppressNextMoveEnd();
                    eventMap.fitBounds(e.target.getBounds(), { padding: [30, 30], animate: false });
                }
            });
        });
        activeGpxLayer = group;
    }

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function buildParams(limit) {
        var params = new URLSearchParams();

        if (isMine) {
            params.set('mine', '1');
        }

        // Trzy rodzaje pól w panelu filtrów: (1) checkboxy wielowartościowe
        // (eventTypes[]/durationBuckets[]/...), (2) pojedyncze wartości liczbowe/
        // tekstowe bez '[]' (data-filter-plain — suwaki, daty), (3) bool-flagi
        // (data-filter-bool — "tylko zweryfikowani"/"są wolne miejsca").
        filtersPane.querySelectorAll('input[data-filter]:checked').forEach(function (el) {
            params.append(el.dataset.filter + '[]', el.value);
        });
        filtersPane.querySelectorAll('input[data-filter-bool]:checked').forEach(function (el) {
            params.set(el.dataset.filterBool, '1');
        });
        // "Tylko bezpłatne" mapuje się na istniejący filtr paid=free — osobny
        // atrybut, bo semantycznie to nie jest "kolejna" bool-flaga, tylko
        // wariant już istniejącego pola (patrz chipy "Darmowe"/"Płatne").
        var freeOnly = filtersPane.querySelector('[data-filter-paid-free]');
        if (freeOnly && freeOnly.checked) {
            params.set('paid', 'free');
        }

        if (searchInput && searchInput.value.trim() !== '') {
            params.set('q', searchInput.value.trim());
        }

        var distance = filtersPane.querySelector('[data-filter-plain="maxDistanceKm"]');
        if (distance && Number(distance.value) < 300) {
            params.set('distance', distance.value);
        }
        var budget = filtersPane.querySelector('[data-filter-plain="maxPriceAmount"]');
        if (budget && Number(budget.value) < 2000) {
            params.set('maxPrice', budget.value);
        }
        var dateFrom = filtersPane.querySelector('[data-filter-plain="dateFrom"]');
        if (dateFrom && dateFrom.value) {
            params.set('dateFrom', dateFrom.value);
        }
        var dateTo = filtersPane.querySelector('[data-filter-plain="dateTo"]');
        if (dateTo && dateTo.value) {
            params.set('dateTo', dateTo.value);
        }
        if (userCoords) {
            params.set('lat', userCoords.lat.toFixed(2));
            params.set('lng', userCoords.lng.toFixed(2));
            if (radiusInput && !radiusInput.disabled && Number(radiusInput.value) < 300) {
                params.set('radius', radiusInput.value);
            }
        }

        if (activeChip === 'completed') {
            params.set('view', 'completed');
        } else if (activeChip === 'free' || activeChip === 'paid') {
            params.set('paid', activeChip);
        } else if (activeChip === 'weekend' || activeChip === 'month') {
            params.set('when', activeChip);
        } else if (activeChip === 'towarzystwo' && !params.has('eventTypes[]')) {
            // Skrót do filtru typu — jeśli user ręcznie odznaczył/zaznaczył coś
            // w panelu bocznym, to jego wybór wygrywa (stąd sprawdzenie wyżej);
            // w przeciwnym razie chip zawęża wprost do pokrec_z_kims.
            params.append('eventTypes[]', 'pokrec_z_kims');
        }

        var sortSelect = resultsMain.querySelector('[data-role="sort"]');
        if (sortSelect && sortSelect.value !== 'date') {
            params.set('sort', sortSelect.value);
        }

        if (limit && limit !== 24) {
            params.set('limit', limit);
        }

        return params;
    }

    function fetchResults(limit, pushUrl) {
        var params  = buildParams(limit);
        var query   = params.toString();
        var url     = basePath + (query ? '?' + query : '');
        var myToken = ++fetchToken;

        fetch(url + (query ? '&ajax=1' : '?ajax=1'))
            .then(function (res) { return res.text(); })
            .then(function (html) {
                if (myToken !== fetchToken) return; // nowszy request już w drodze — ignorujemy przestarzałą odpowiedź
                resultsMain.innerHTML = html;
                applyViewMode();
                if (pushUrl) {
                    history.pushState(null, '', url || basePath);
                    // Zapamiętujemy ostatni filtrowany URL osobno dla każdego z dwóch
                    // kontekstów (ogólna lista / "Moje wydarzenia"), żeby link
                    // "Wydarzenia"/"Moje wydarzenia" w nagłówku (statyczny <a>, bez
                    // wiedzy o bieżących filtrach) mógł do niego wrócić zamiast zawsze
                    // resetować do "/". Zapamiętujemy też, KTÓRY z tych dwóch kontekstów
                    // był ostatnio aktywny — używane poza stroną główną (np. na stronie
                    // wydarzenia) do podświetlenia właściwej pozycji menu i wyznaczenia
                    // celu linku powrotu, patrz ui.js.
                    sessionStorage.setItem(isMine ? 'ridemore_mine_url' : 'ridemore_events_url',
                        new URL(url || basePath, location.origin).href);
                    sessionStorage.setItem('ridemore_list_context', isMine ? 'mine' : 'events');
                }
            });
    }

    filtersPane.addEventListener('change', function (e) {
        if (e.target.matches('input[data-filter]') || e.target.matches('input[data-filter-bool]')
            || e.target.matches('[data-filter-paid-free]') || e.target.matches('[data-filter-plain]')) {
            fetchResults(24, true);
        }
    });
    filtersPane.addEventListener('input', function (e) {
        if (e.target === document.getElementById('distance-range') && distanceLbl) {
            var v = Number(e.target.value);
            distanceLbl.textContent = v >= Number(e.target.max) ? 'bez limitu' : v + ' km';
        }
        if (e.target === document.getElementById('budget-range') && budgetLbl) {
            var v2 = Number(e.target.value);
            budgetLbl.textContent = v2 >= Number(e.target.max) ? __('bez limitu') : v2 + ' zł';
        }
        if (e.target === radiusInput && radiusLbl) {
            var v3 = Number(e.target.value);
            radiusLbl.textContent = v3 >= Number(e.target.max) ? 'bez limitu' : v3 + ' km';
        }
    });

    chipsRow.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-chip]');
        if (!chip) return;
        activeChip = chip.dataset.chip;
        chipsRow.querySelectorAll('.chip').forEach(function (c) { c.classList.remove('active'); });
        chip.classList.add('active');
        fetchResults(24, true);
    });

    // Deleguje na <main> (stały element) zamiast na podmienianą zawartość —
    // te przyciski/pola żyją WEWNĄTRZ #results-region (patrz event-results.php),
    // więc po każdym fetchResults() są nowymi węzłami DOM-u.
    resultsMain.addEventListener('change', function (e) {
        if (e.target.matches('[data-role="sort"]')) {
            fetchResults(24, true);
        }
    });

    resultsMain.addEventListener('click', function (e) {
        var loadMore = e.target.closest('[data-role="load-more"]');
        if (loadMore) {
            fetchResults(Number(loadMore.dataset.nextLimit), true);
            return;
        }
        var mobileToggle = e.target.closest('[data-role="mobile-filter-toggle"]');
        if (mobileToggle) {
            var isOpen = filtersPane.classList.toggle('is-open');
            mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            return;
        }
        var tagRemove = e.target.closest('[data-role="remove-filter"]');
        if (tagRemove) {
            removeFilterValue(tagRemove.dataset.filterGroup, tagRemove.dataset.filterValue);
        }
    });

    // ZAMKNIĘCIE ARKUSZA FILTRÓW W APCE (Faza 2 przebudowy UX, 2026-08-29) —
    // w apce arkusz zasłania cały ekran (patrz CSS `body.is-app .filters.is-open`),
    // więc przycisk „Filtry" w tle staje się niedostępny. `.filters` leży POZA
    // <main> (jest jego rodzeństwem w .layout), więc nasłuch na `resultsMain`
    // wyżej go nie złapie — stąd osobny, ale ten sam co do ducha, listener.
    // Na web-mobile (inline toggle, bez zasłaniania) ten przycisk jest ukryty
    // CSS-em — patrz `.filters__close`.
    filtersPane.addEventListener('click', function (e) {
        if (!e.target.closest('[data-role="close-filters"]')) { return; }
        filtersPane.classList.remove('is-open');
        var trigger = resultsMain.querySelector('[data-role="mobile-filter-toggle"]');
        if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
    });

    // Usuwa jeden wybór z panelu filtrów (odznacza checkbox / czyści pole) i
    // odświeża wyniki — obsługuje wszystkie trzy rodzaje pól z buildParams()
    // wyżej, żeby "×" na tagu działało tak samo jak ręczne odznaczenie w panelu.
    function removeFilterValue(group, value) {
        if (group === 'q') {
            searchInput.value = '';
        } else if (group === 'paid') {
            // 'paid=free' może pochodzić z przełącznika w panelu ALBO z chipa
            // "Darmowe" u góry (patrz buildParams) — tag nie wie, który to był,
            // więc czyścimy oba źródła naraz.
            var pf = filtersPane.querySelector('[data-filter-paid-free]');
            if (pf) pf.checked = false;
            if (activeChip === 'free' || activeChip === 'paid') {
                activeChip = 'all';
                chipsRow.querySelectorAll('.chip').forEach(function (c) { c.classList.remove('active'); });
                var allChip = chipsRow.querySelector('[data-chip="all"]');
                if (allChip) allChip.classList.add('active');
            }
        } else if (group === 'radiusKm') {
            if (radiusInput) {
                radiusInput.value = 300;
                if (radiusLbl) radiusLbl.textContent = radiusInput.disabled ? 'nieaktywne' : 'bez limitu';
            }
        } else if (group === 'verifiedOnly' || group === 'hasSpotsOnly') {
            var bf = filtersPane.querySelector('[data-filter-bool="' + group + '"]');
            if (bf) bf.checked = false;
        } else if (group === 'maxDistanceKm') {
            document.getElementById('distance-range').value = 300;
            if (distanceLbl) distanceLbl.textContent = 'bez limitu';
        } else if (group === 'maxPriceAmount') {
            document.getElementById('budget-range').value = 2000;
            if (budgetLbl) budgetLbl.textContent = 'bez limitu';
        } else if (group === 'dateFrom' || group === 'dateTo') {
            var df = filtersPane.querySelector('[data-filter-plain="' + group + '"]');
            if (df) df.value = '';
        } else {
            var input = filtersPane.querySelector('input[data-filter="' + group + '"][value="' + CSS.escape(value) + '"]');
            if (input) input.checked = false;
        }
        fetchResults(24, true);
    }

    viewToggle.addEventListener('click', function (e) {
        var viewBtn = e.target.closest('[data-view]');
        if (!viewBtn) return;
        viewMode = viewBtn.dataset.view;
        applyViewMode();
    });

    applyViewMode();

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { fetchResults(24, true); }, 400);
        });
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimer);
                fetchResults(24, true);
            }
        });
    }

    var locateBtn = document.querySelector('.locate-btn');
    if (locateBtn) {
        // Zmieniamy tylko tekst etykiety, nie całą zawartość przycisku —
        // inaczej .textContent skasowałby ikonę SVG obok niej.
        var locateLabel        = locateBtn.querySelector('[data-role="locate-label"]');
        var locateLabelDefault = locateLabel.textContent;

        locateBtn.addEventListener('click', function () {
            locateBtn.disabled = true;
            locateLabel.textContent = __('Lokalizuję…');

            // Most (assets/js/native.js). Bez wysokiej dokładności i z pozycją
            // sprzed 5 minut: i tak zaokrąglamy do ~1 km, więc dokładniejszy
            // pomiar niczego by nie zmienił, a kosztuje czekanie i baterię.
            RM.native.position({ timeout: 10000, highAccuracy: false, maximumAge: 5 * 60 * 1000 }).then(function (pos) {
                // Zaokrąglone do ~1 km — wystarcza do sortowania/filtrowania, a to
                // trafia też do adresu żądania i logów serwera (nie do historii
                // przeglądarki, patrz brak history.pushState niżej).
                userCoords = { lat: Number(pos.lat.toFixed(2)), lng: Number(pos.lon.toFixed(2)) };

                // Aktywujemy suwak "Odległość od Ciebie" dopiero teraz — bez
                // współrzędnych byłby to filtr, który nic nie znaczy (patrz
                // fg-hint w markupie i Event::buildFilterClauses() po stronie PHP).
                if (radiusInput) {
                    radiusInput.disabled = false;
                    if (radiusLbl) radiusLbl.textContent = Number(radiusInput.value) >= 300 ? 'bez limitu' : radiusInput.value + ' km';
                }
                if (radiusHint) radiusHint.style.display = 'none';

                var params = buildParams(24);
                params.set('sort', 'near');

                var myToken = ++fetchToken;
                fetch(basePath + '?' + params.toString() + '&ajax=1')
                    .then(function (res) { return res.text(); })
                    .then(function (html) {
                        if (myToken === fetchToken) { resultsMain.innerHTML = html; applyViewMode(); }
                    })
                    .finally(function () {
                        locateBtn.disabled = false;
                        locateLabel.textContent = locateLabelDefault;
                    });
                // Celowo bez history.pushState — współrzędne to dane wrażliwe,
                // nie zostają w adresie ani w historii przeglądarki.
            }).catch(function (err) {
                locateBtn.disabled = false;
                locateLabel.textContent = locateLabelDefault;
                // Most podaje gotowy komunikat po polsku — dawne `err.message`
                // z przeglądarki bywało po angielsku i mówiło „User denied
                // Geolocation" nawet komuś, kto zgody nigdy nie widział.
                alert(err.message);
            });
        });
    }

    window.addEventListener('popstate', function () {
        location.reload();
    });
})();
</script>
