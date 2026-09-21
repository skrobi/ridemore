<?php
// views/web/pages/event-page.php
// Przebudowa wg szablony/wydarzenie.html (2026-08-01) — zakładki zastąpione
// długą, przewijaną stroną ze sticky nawigacją-kotwicami (scrollspy), patrz
// script na dole. Cały silnik tras/GPX/RSVP/płatności/opinii zostaje 1:1,
// zmienia się tylko markup/CSS wokół niego.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\DayColor;
use Utils\ClimbCategory;
use Utils\Icon;
use Utils\View;
use Utils\Gpx;

if (!$eventData) {
    echo '<h1>' . __('Nie znaleziono wydarzenia') . '</h1>';
    return;
}
$e = $eventData;
$breadcrumbs = $breadcrumbs ?? [];
$criticalMassProgress = $criticalMassProgress ?? null;
$confirmedParticipants = $confirmedParticipants ?? [];
// Skład turnusu pod sekcję "Kto jedzie" (patrz EventRsvp::rosterForEdition()).
// $confirmedParticipants wyżej to to samo co $roster['confirmed'] — zostaje jako
// osobna zmienna, bo panel zapisu (.book__who) używał jej, zanim ta sekcja powstała.
$roster = $roster ?? ['confirmed' => [], 'confirmedTotal' => 0, 'interested' => [], 'interestedTotal' => 0];
// Suma zapisanych — może być WIĘKSZA niż liczba pozycji na liście, bo osoby,
// które wyłączyły się z list (migr. 037), liczą się do składu, ale nie
// pokazujemy ich twarzy ani imienia.
$confirmedTotal = $confirmedTotal ?? count($confirmedParticipants);
// Stan "Byłem" zalogowanego uczestnika na wybranym turnusie (null = nie dotyczy:
// wydarzenie niezakończone, gość albo brak potwierdzonego zapisu).
$attendance = $attendance ?? null;
// Czy ten turnus ma już kronikę (ktokolwiek potwierdził obecność) — decyduje
// o sekcji "po wyjeździe" ORAZ o tym, czy blok "Relacje" pokazuje wpisy, czy
// odsyła do kroniki. Ustawiane przez EventController::show().
$hasChronicle = $hasChronicle ?? false;
// Peleton na TYM turnusie — osoby, z którymi widz już faktycznie jechał
// (Models\RiderConnection). Pusta tablica dla gościa i dla kogoś, kto jeszcze
// z nikim stąd nie jechał.
$pelotonHere = $pelotonHere ?? [];

// PRYWATNOŚĆ: awatary z inicjałami widzi każdy (tak jest w .book__who od zawsze),
// ale IMIONA tylko zalogowani. Zapis na wyjazd to nie publikacja profilu —
// niezalogowany gość (i robot) dostaje liczbę i twarze, nie listę nazwisk.
// I tak skracamy do "Michał W.", nigdy pełne nazwisko.
$rosterShowNames = \Core\Auth::check();
$rosterName = static function (array $p): string {
    $raw = trim((string) ($p['name'] ?? ''));
    if ($raw === '') { $raw = (string) strstr((string) ($p['email'] ?? ''), '@', true); }
    $parts = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) { return __('Rowerzysta'); }
    $out = $parts[0];
    if (count($parts) > 1) { $out .= ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.'; }
    return $out;
};
// Polska odmiana przez liczbę: 1 → forma pojedyncza, 2-4 → "few", reszta →
// "many", z wyjątkiem 12-14 (te idą do "many"). Domknięcie w widoku, nie nowy
// helper w Utils: Format nie ma dziś żadnej obsługi liczby mnogiej, a dokładanie
// jej tam to osobna decyzja. Definiowane U GÓRY pliku, bo używa tego zarówno
// sekcja "Kto jedzie", jak i panel zapisu (.book__who) — a te renderują się
// w rozłącznych gałęziach statusu wydarzenia.
// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);
$rosterPlural = static fn(int $n): string => $plural($n, 'osoba', 'osoby', 'osób');

$rosterInitials = static function (array $p): string {
    $raw = trim((string) ($p['name'] ?? ''));
    if ($raw === '') { $raw = (string) strstr((string) ($p['email'] ?? ''), '@', true); }
    return mb_strtoupper(mb_substr($raw !== '' ? $raw : '?', 0, 2));
};
// Imię jako link do publicznego profilu rowerzysty (Etap 3) — ale TYLKO gdy
// konto ma już slug (konta sprzed migr. 038, których nie objął
// backfill_rider_slugs.php, po prostu zostają zwykłym tekstem zamiast
// prowadzić donikąd). Sam profil i tak sprawdza własne warunki widoczności.
require __DIR__ . '/../partials/rider-avatar.php';
$rosterLink = static fn(array $p, string $label): string => renderRiderName($label, $p['public_slug'] ?? null);
$canGroupChat = $canGroupChat ?? false;
// Moderacja dyskusji i dodawanie par Q&A (organizator/współpracownik/admin) —
// patrz EventController::show(). Domyślnie false, żeby widok nie wywalił się,
// gdyby ktoś kiedyś wyrenderował go bez tej zmiennej.
$canModerateDiscussion = $canModerateDiscussion ?? false;

// Turnusy (patrz Models\EventEdition) — $selectedEdition to wpis z $e['editions']
// odpowiadający $e['selectedEditionId'] (ustalonym przez EventController::show(),
// patrz Support::resolveEdition()). Fallback na null tylko teoretyczny (każdy
// event ma przynajmniej jeden turnus).
$selectedEdition = null;
foreach ($e['editions'] as $ed) {
    if ($ed['id'] === $e['selectedEditionId']) { $selectedEdition = $ed; break; }
}
$hasMultipleEditions = count($e['editions']) > 1;

// Warianty trasy (pętle) — gdy event je ma, WYBRANY wariant (ustalony przez
// EventController::show wg ?wariant=ID) niesie własną trasę, cenę i limit.
// Podmieniamy $e['stages'] na JEDEN syntetyczny etap zbudowany z wariantu, żeby
// istniejąca sekcja "Mapa i profil trasy" (Leaflet+GPX, niżej) renderowała jego
// ślad bez żadnej przebudowy — tak samo totals, surfaceBreakdown i cena w panelu.
$selectedVariant = null;
if (!empty($e['variants'])) {
    foreach ($e['variants'] as $v) {
        if ($v['id'] === ($e['selectedVariantId'] ?? null)) { $selectedVariant = $v; break; }
    }
    if ($selectedVariant === null) { $selectedVariant = $e['variants'][0]; }

    $vProfile = $selectedVariant['elevationProfile'];
    $vBreakdown = $selectedVariant['surfaceAsphaltPct'] !== null ? [
        'asphaltPct' => $selectedVariant['surfaceAsphaltPct'],
        'gravelPct'  => $selectedVariant['surfaceGravelPct'],
        'trailPct'   => $selectedVariant['surfaceTrailPct'],
        'asphaltKm'  => round($selectedVariant['distanceKm'] * $selectedVariant['surfaceAsphaltPct'] / 100, 1),
        'gravelKm'   => round($selectedVariant['distanceKm'] * $selectedVariant['surfaceGravelPct'] / 100, 1),
        'trailKm'    => round($selectedVariant['distanceKm'] * $selectedVariant['surfaceTrailPct'] / 100, 1),
    ] : null;
    $e['stages'] = [[
        'dayNumber'            => 1,
        'date'                 => $selectedEdition['startDate'] ?? null,
        'title'                => $selectedVariant['name'],
        'startPoint'           => null,
        'endPoint'             => null,
        'distanceKm'           => $selectedVariant['distanceKm'],
        'elevationGainM'       => $selectedVariant['elevationM'],
        'gpxUrl'               => $selectedVariant['gpxUrl'],
        'notes'                => null,
        'surfaceLabel'         => $selectedVariant['surfaceLabel'],
        'accommodationLabel'   => null,
        'accommodationAddress' => null,
        'includedMealLabels'   => [],
        'surfaceBreakdown'     => $vBreakdown,
        'elevationProfile'     => $vProfile,
        'startElevationM'      => !empty($vProfile) ? $vProfile[0]['e'] : null,
        'peaks'                => !empty($vProfile) ? Gpx::detectPeaks($vProfile) : [],
    ]];
    $e['totals']['distanceKm'] = $selectedVariant['distanceKm'];
    $e['totals']['elevationM'] = $selectedVariant['elevationM'];
    $e['surfaceBreakdown']     = $vBreakdown;
    if ($e['isPaid'] && $e['pricing']) {
        $e['pricing']['amount']        = $selectedVariant['priceAmount'] ?? $e['pricing']['amount'];
        $e['pricing']['depositAmount'] = $selectedVariant['depositAmount'];
    }
}

$gpxStages = array_values(array_filter($e['stages'], fn($s) => !empty($s['gpxUrl'])));

// „CO MI TO DA" — szacunek nowych pól i punktów per trasa (2026-08-14, prośba
// usera: „może się okazać, że podobną trasę już odkrywałem i niewiele to
// wniesie do mojej jazdy"). Liczone TYLKO dla zalogowanego: bez konta nie ma
// czyich odkryć odejmować, a „100% nowego" pokazane gościowi byłoby obietnicą
// dla konta, którego jeszcze nie ma.
//
// Pola trasy idą z cache'u per plik (Models\RoutePreview, migr. 050), więc
// wejście na stronę nie parsuje GPX-ów od nowa — zmierzone: 14–31 ms pierwsze
// liczenie, 1 ms z cache'u.
$routePreview = [];
if (!empty($viewerId)) {
    foreach ($gpxStages as $s) {
        $cells = Models\RoutePreview::cellsForGpx(CORE_PATH . '/..' . $s['gpxUrl']);
        if (!$cells) { continue; }
        $routePreview[$s['dayNumber']] = Models\RoutePreview::forUser(
            (int) $viewerId,
            $cells,
            (float) $s['distanceKm'],
            (int) $s['elevationGainM']
        );
    }
}

// Jedno zdanie pod trasą. Wydzielone, bo ta sama statystyka pokazuje się przy
// każdym dniu wielodniówki, a przy jednodniowym wyjeździe raz.
$renderRoutePreview = function (?array $p): string {
    if ($p === null) { return ''; }
    $num = fn($n) => number_format((int) $n, 0, ',', ' ');
    ob_start();
    ?>
    <p class="rgain<?= $p['newCells'] === 0 ? ' rgain--known' : '' ?>">
        <?php if ($p['newCells'] === 0): ?>
        <?php // NAJWAŻNIEJSZY PRZYPADEK — to po niego user o to poprosił.
              // Mówimy wprost, że odkryć nie będzie, i od razu co zostaje:
              // punkty za sam przejazd naliczają się zawsze, także po znanym. ?>
        <b><?= __('Tę trasę masz już całą odkrytą.') ?></b>
        <?= __('Nowych pól nie przybędzie — zostaje') ?> <b>+<?= $num($p['ridePoints']) ?></b> <?= __('pkt za sam przejazd.') ?>

        <?php else: ?>
        <b class="hexn"><?= Utils\Icon::render('hex') ?><?= $num($p['newCells']) ?></b>
        <?= __n((int) $p['newCells'], 'nowe pole z {z} na tej trasie', 'nowe pola z {z} na tej trasie', 'nowych pól z {z} na tej trasie', ['z' => $num($p['cells'])]) ?><?php
        if ($p['knownCells'] > 0): ?> <?= __('(resztę już masz)') ?><?php endif; ?> <?= __('·
        szacunkowo') ?> <b>+<?= $num($p['points']) ?></b> <?= __('pkt') ?>

        <small>(<?= __('{a} za przejazd + {b} za odkrycia', ['a' => $num($p['ridePoints']), 'b' => $num($p['discoveryPoints'])]) ?><?php
            if ($p['explorationPoints'] > 0): ?> + <?= __('{n} za nowy teren', ['n' => $num($p['explorationPoints'])]) ?><?php endif; ?>)</small>
        <?php endif; ?>
        <?php // SZACUNEK, i to musi być powiedziane. Liczymy z trasy
              // ZAPOWIADANEJ, a pola naliczają się wyłącznie ze śladu z odbytego
              // wyjazdu (migr. 042): kto skróci trasę, dostanie mniej; kto
              // dojedzie własnym dojazdem, więcej. ?>
        <small class="rgain__note"><?= __('Szacunek z zapowiedzianej trasy — liczy się ślad z tego, co faktycznie przejedziesz.') ?></small>
    </p>
    <?php
    return ob_get_clean();
};
$hasGpx = !empty($gpxStages);
$showDayTabs = count($gpxStages) > 1;
$isCompleted = $e['statusCode'] === 'completed';
$isLooseRide = $e['type'] === 'pokrec_z_kims';
$isBookable = in_array($e['statusCode'], ['published', 'full'], true);
// Prawy sidebar (panel zapisu) renderuje treść dla tych statusów; przy
// 'completed' żadna gałąź nie trafia i <aside> byłby PUSTY — wtedy chowamy go
// i rozciągamy główną kolumnę na całą szerokość (inaczej layout zostawia
// martwe 348px po prawej, patrz .event-layout--single w style.css).
$hasBookingPanel = in_array($e['statusCode'], ['draft', 'cancelled', 'oczekuje_weryfikacji', 'published', 'full'], true);

// Zewnętrzne formy zapisu (link / telefon / e-mail) — organizator prowadzi
// zapisy poza platformą, może podać jedną lub kilka. Wykaz zasila sekcję
// "O wyjeździe" i CTA w panelu zapisu (pierwszy element = akcja główna panelu).
$externalMethods = [];
if ($e['registrationType'] === 'external') {
    if (!empty($e['externalUrl'])) {
        $extHost = preg_replace('#^www\.#', '', (string) parse_url($e['externalUrl'], PHP_URL_HOST)) ?: 'strona organizatora';
        $externalMethods[] = ['href' => $e['externalUrl'], 'label' => $extHost . ' ↗', 'cta' => __('Zapisz się →'), 'blank' => true];
    }
    if (!empty($e['externalPhone'])) {
        $externalMethods[] = ['href' => 'tel:' . preg_replace('/[^0-9+]/', '', $e['externalPhone']), 'label' => $e['externalPhone'], 'cta' => __('Zadzwoń: {tel}', ['tel' => $e['externalPhone']]), 'blank' => false];
    }
    if (!empty($e['externalEmail'])) {
        $externalMethods[] = ['href' => 'mailto:' . $e['externalEmail'], 'label' => $e['externalEmail'], 'cta' => 'Napisz: ' . $e['externalEmail'], 'blank' => false];
    }
}
// Hero: "Dołącz do nas" zamiast "Napisz do organizatora" dopóki widz nie ma
// żadnego realnego zapisu — zainteresowany/anulowany/brak zapisu liczy się
// tu jak "jeszcze nie dołączył" (patrz też interest-toggle.php, ten sam
// zestaw statusów). Prowadzi do panelu zapisu (#zapis) w bocznym sidebarze/
// na dole strony, zamiast duplikować całą maszynę stanów CTA w nagłówku.
$notJoinedYet = $isBookable && !in_array($myStatus, ['potwierdzony', 'oczekuje_platnosci', 'oczekuje_doplaty', 'oczekuje_zwrotu'], true);
// Trasa bez treści dla luźnego wyjazdu bez wgranego GPX/dystansu — sekcja
// "O wyjeździe" nie dostaje wtedy pustej siatki faktów pod opisem.
$hasTrasaContent = !empty($e['difficultyLabel']) || !empty($e['paceGroupLabel']) || $e['surfaceBreakdown'] !== null
    || !empty(array_filter(array_column($e['stages'], 'surfaceLabel'))) || $hasGpx;

// Etapy mają opisowe szczegóły (notatka / nocleg / posiłki), które warto
// pokazać nawet bez GPX i także dla jednodniówki. Wcześniej notatka
// jednodniowego wyjazdu nie wyświetlała się w ogóle: blok "Plan wyjazdu"
// był tylko dla isMultiday (=liczba etapów > 1), a cała sekcja przebiegu
// tylko przy GPX (patrz zgłoszenie użytkownika). $hasPrzebieg otwiera sekcję,
// gdy jest CO pokazać — mapa ALBO opisowe szczegóły etapu.
$hasStageDetails = !empty(array_filter($e['stages'], fn($s) =>
    !empty($s['notes']) || !empty($s['accommodationLabel']) || !empty($s['includedMealLabels'])));
$hasPrzebieg = $hasGpx || $hasStageDetails;

require __DIR__ . '/../partials/activity-card.php';
require __DIR__ . '/../partials/track-upload.php';

// Pasek segmentowy + legenda km/% — reużywany dla agregatu całego eventu
// (facts w "O wyjeździe") i osobno dla każdego dnia z wykrytym podziałem
// (moduł "Mapa i profil trasy"). $breakdown to StageResource/EventResource
// 'surfaceBreakdown' (już nie null w miejscu wywołania).
// MARKUP WYPROWADZONY DO WSPÓLNEGO PARTIALA (2026-09-11) — od migracji 086 ten
// sam pasek rysuje też strona znanej trasy, a domknięta funkcja w tym pliku była
// dla niej niedostępna. Domknięcie zostaje jako opakowanie, bo dwa miejsca niżej
// wołają je W ŚRODKU znacznika echa i potrzebują STRINGA, a nie wypisania.
$renderSurfaceBreakdown = function (array $breakdown): string {
    ob_start();
    $sbBreakdown = $breakdown;
    require __DIR__ . '/../partials/surface-breakdown.php';
    return ob_get_clean();
};

// Format (typ wydarzenia) — ten sam kod barw co /wydarzenia i strona główna.
// 'wyscig' sprawdzane po kodzie typu (jak pokrec_z_kims), NIE po isMultiday —
// wyścig jest z definicji jednodniowy (ten sam wizard co ustawka), więc
// wpadałby w gałąź "Zorganizowane wydarzenie" poniżej, gdyby nie ten warunek.
$isRace = $e['type'] === 'wyscig';
$formatLabel = $isLooseRide ? __('Pokręcę z kimś') : ($isRace ? __('Wyścig') : ($e['isMultiday'] ? __('Wycieczka wielodniowa') : __('Zorganizowane wydarzenie')));
$formatColorVar = $isLooseRide ? '--s-green' : ($isRace ? '--s-purple' : ($e['isMultiday'] ? '--s-blue' : '--s-red'));

// Lista kotwic sticky-nav — warunkowo dokładnie jak dawniej budowane były
// zakładki ($hasGpx/$e['isMultiday']/$isCompleted): sekcja bez treści po
// prostu nie dostaje kotwicy.
// "Kto jedzie" jest PIERWSZĄ sekcją strony (przed opisem) — o wspólnym wyjeździe
// decyduje się patrząc na ludzi, nie na opis trasy. Tylko dla wydarzeń, na które
// da się jeszcze zapisać: przy zakończonym/odwołanym czas przyszły byłby fałszem
// (skład wyjazdu, który już się odbył, pokaże kronika — Etap 4).
$anchors = [];
// Sekcja u góry istnieje WYŁĄCZNIE wtedy, gdy jest o co zapytać. Po odpowiedzi
// nie ma tam nic: kronika i stan obecności żyją w bloku "Relacje z tego wyjazdu"
// na dole (sekcja #opinie, która ma własną kotwicę) — inaczej kronika byłaby na
// stronie dwa razy. Link z maila po wydarzeniu celuje w #bylem, więc ta kotwica
// musi pojawiać się dokładnie razem z sekcją.
if ($attendance !== null && $attendance['attended'] === null) {
    $anchors[] = ['id' => 'bylem', 'label' => __('Byłeś?')];
}
// „Kto jedzie" WYPADŁO z kotwic (2026-08-12), bo skład przeniósł się do prawej
// kolumny, pod panel zapisu. Kotwice opisują sekcje ARTYKUŁU — czytelnik
// przeskakuje nimi po treści wyjazdu. Na desktopie skład jest widoczny cały
// czas i skakanie do niego nie ma sensu; na wąskim ekranie kotwica ciągnęłaby
// na sam dół strony, za całą treść. Sam identyfikator #kto-jedzie zostaje,
// bo linkują w niego maile i Puls.
$anchors[] = ['id' => 'o-wyjezdzie', 'label' => __('O wyjeździe')];
if ($hasPrzebieg) {
    $anchors[] = ['id' => 'przebieg', 'label' => $hasGpx ? __('Mapa i profil trasy') : __('Trasa')];
}
if ($e['isPaid']) {
    $anchors[] = ['id' => 'cena', 'label' => __('Cena i warunki')];
}
if (!empty($e['equipment'])) {
    $anchors[] = ['id' => 'sprzet', 'label' => __('Sprzęt')];
}
$anchors[] = ['id' => 'zbiorka', 'label' => __('Zbiórka')];
$anchors[] = ['id' => 'organizator', 'label' => __('Organizator')];
$anchors[] = $isCompleted
    ? ['id' => 'opinie', 'label' => __('Opinie i relacje (') . (count($reviews) + count($recaps)) . ')']
    : ['id' => 'pytania', 'label' => __('Pytania (') . count($comments) . ')'];
?>
<?php // Bez okruszków w apce — ten sam odruch co na każdym innym ekranie
      // przebudowanym w tej sesji (Fazy 1-4, tasks/done/apka-mobilna-ux.md). ?>
<?php // Okruszki: bramkę „nie w apce" trzyma teraz sam partial (2026-09-11) —
      // zasada obowiązywała tylko w czterech szablonach, a dotyczy wszystkich. ?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>

<div class="head">
    <div class="tags">
        <span class="tag" style="--bz:var(<?= $formatColorVar ?>);"><span class="blaze"></span><?= htmlspecialchars($formatLabel) ?></span>
        <?php if ($e['isPaid']): ?>
        <span class="tag tag--plain tag--paid"><?= __('Płatna') ?> · <?= htmlspecialchars(Format::price($e['pricing']['amount'], Format::currencySymbol($e['pricing']['currency']))) ?></span>
        <?php else: ?>
        <span class="tag tag--plain"><?= __('Bezpłatna') ?></span>
        <?php endif; ?>
        <?php if (!empty($e['bikeTypes'])): ?>
        <span class="tag tag--plain"><?= htmlspecialchars(implode(' / ', $e['bikeTypes'])) ?></span>
        <?php endif; ?>
        <?php if (!empty($e['regionLabel'])): ?>
        <?php // Osobny, klikalny tag na każdy region (partials/region-link.php). ?>
        <?php require_once __DIR__ . '/../partials/region-link.php'; ?>
        <?= renderRegionLinks($e['regionLabel'], 'tag tag--plain', "\n        ") ?>
        <?php endif; ?>
    </div>
    <?php // NAZWA WYDARZENIA — NA ZDJĘCIU (prośba usera 2026-09-10), nie tutaj.
          // .event-title-row (h1 + ikonka „obserwujesz") przenosi się do wnętrza
          // .cover, w .hero-bottom NAD paskiem organizatora — ten pasek zostaje
          // dokładnie tam, gdzie jest (patrz .cover__org niżej, treść 1:1).
          // `.head__sub` (data/zbiórka) dołącza pod nazwą (kolejna prośba usera,
          // ten sam dzień) — modyfikator `.hero-sub` zmienia tylko kolor
          // (biały zamiast ink-soft, potrzebny kontrast na zdjęciu), reszta
          // (flex/gap/font-size) zostaje. Data dostaje `--blaze`-marker —
          // TEN SAM pasek za tekstem co wyróżnione słowo w <em> na hero
          // strony głównej (.hero h1 em::after, style.css), nie nowy wzór. ?>
    <?php if (!empty($e['coverPhotoUrl'])): ?>
    <div class="cover" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($e['coverPhotoUrl'], 'wide')) ?>');" role="img" aria-label="<?= htmlspecialchars($e['title']) ?>">
    <?php else: ?>
    <div class="cover">
    <?php endif; ?>
        <div class="cover__grad"></div>
        <div class="hero-bottom">
            <div class="event-title-row">
                <h1 class="hero-ttl"><?= htmlspecialchars($e['title']) ?></h1>
                <?php if ($myStatus === 'zainteresowany'): ?>
                <span class="watching-indicator" title="<?= htmlspecialchars(__('Obserwujesz to wydarzenie')) ?>"><?= Icon::render('eye') ?></span>
                <?php endif; ?>
            </div>
            <p class="head__sub hero-sub">
                <?php if ($selectedEdition): ?>
                <b>
                <?php if ($isLooseRide): ?>
                    <?= $selectedEdition['dateIsFlexible'] ? __('termin do uzgodnienia:') . ' ' : '' ?><?= htmlspecialchars(Format::dateRangeP($selectedEdition['startDate'], $selectedEdition['endDate'])) ?><?= (!$selectedEdition['dateIsFlexible'] && $selectedEdition['startTime']) ? ', ' . __('godz.') . ' ' . htmlspecialchars(substr($selectedEdition['startTime'], 0, 5)) : '' ?>
                <?php else: ?>
                    <?= Format::dateP($selectedEdition['startDate']) ?>
                <?php endif; ?>
                </b> ·
                <?php endif; ?>
                <?= __('zbiórka:') ?> <?= htmlspecialchars($e['meetingPointAddress'] ?? '—') ?>
                <?php if ($e['isMultiday']): ?>· <span class="mono"><?= __('{n} dni', ['n' => $e['totals']['durationDays']]) ?></span><?php endif; ?>
            </p>
            <div class="cover__org">
                <?php // Avatar linkuje do TEGO SAMEGO adresu co imię obok (L376) —
                      // do dziś było odwrotnie: klikalne było tylko imię. ?>
                <a href="<?= View::url('/organizatorzy/' . $e['organizer']['slug']) ?>">
                <?php if (!empty($e['organizer']['avatarUrl'])): ?>
                <img class="avatar" src="<?= htmlspecialchars(Utils\Image::src($e['organizer']['avatarUrl'], 'av')) ?>" alt="<?= htmlspecialchars($e['organizer']['name']) ?>" width="38" height="38">
                <?php else: ?>
                <span class="avatar"><?= htmlspecialchars(mb_substr($e['organizer']['name'], 0, 2)) ?></span>
                <?php endif; ?>
                </a>
                <span class="who">
                    <b><a href="<?= View::url('/organizatorzy/' . $e['organizer']['slug']) ?>" style="color:inherit;"><?= htmlspecialchars($e['organizer']['name']) ?></a></b>
                    <?php if ($e['organizer']['isVerified']): ?><span class="badge-ver"><?= __('✓ Zweryfikowany') ?></span><?php endif; ?>
                    <small><?= $e['organizer']['eventsOrganizedCount'] ?> zorganizowanych · <?= $e['organizer']['ratingAvg'] ?> <?= Icon::render('star') ?></small>
                </span>
                <?php $orgViewer = Core\Auth::user(); ?>
                <?php if ($notJoinedYet): ?>
                <a class="btn btn-secondary btn--sm cover__cta" href="#zapis"><?= __('Dołącz do nas') ?></a>
                <?php elseif ($orgViewer && $orgViewer->id !== $e['organizerId'] && Models\Message::canMessage($orgViewer->id, $e['organizerId'])): ?>
                <a class="btn btn-secondary btn--sm cover__cta" href="<?= View::url('/wiadomosci/z/' . $e['organizerId']) ?>"><?= __('Napisz do organizatora') ?></a>
                <?php endif; ?>
                <?php if ($canGroupChat): ?>
                <a class="btn btn-secondary btn--sm cover__cta" href="<?= View::url('/wiadomosci/grupa/' . $e['selectedEditionId']) ?>"><?= __('Dyskusja grupy') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <dl class="stats">
        <?php if (!$isLooseRide || $e['totals']['distanceKm'] > 0): ?>
        <div><dt><?= __('Dystans') ?></dt><dd><?= Format::distance($e['totals']['distanceKm']) ?></dd></div>
        <?php endif; ?>
        <?php if (!$isLooseRide || $e['totals']['elevationM'] > 0): ?>
        <div><dt><?= __('Przewyższenie') ?></dt><dd><?= $e['totals']['elevationM'] ?> m</dd></div>
        <?php endif; ?>
        <?php if ($e['isMultiday']): ?>
        <div><dt><?= __('Czas trwania') ?></dt><dd><?= __('{n} dni', ['n' => $e['totals']['durationDays']]) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($e['difficultyLabel'])): ?>
        <div><dt><?= __('Trudność') ?></dt><dd><?= htmlspecialchars($e['difficultyLabel']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($e['paceGroupLabel'])): ?>
        <div><dt><?= __('Tempo') ?></dt><dd><?= htmlspecialchars($e['paceGroupLabel']) ?></dd></div>
        <?php endif; ?>
        <?php if ($isLooseRide && !empty($e['regionLabel'])): ?>
        <?php require_once __DIR__ . '/../partials/region-link.php'; ?>
        <div><dt><?= __('Region') ?></dt><dd><?= renderRegionLinks($e['regionLabel']) ?></dd></div>
        <?php endif; ?>
    </dl>
</div>

<nav class="anchors" aria-label="<?= htmlspecialchars(__('Sekcje strony')) ?>"><div class="anchors__in">
    <?php foreach ($anchors as $i => $a): ?>
    <a href="#<?= $a['id'] ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>><?= htmlspecialchars($a['label']) ?></a>
    <?php endforeach; ?>
</div></nav>

<div class="event-layout<?= $hasBookingPanel ? '' : ' event-layout--single' ?>">
<main>

    <?php
        // PO WYJEŹDZIE. Kronika ma na tej stronie DOKŁADNIE JEDNO wejście —
        // w bloku „Relacje z tego wyjazdu" na dole (sekcja #opinie). Górna
        // karta z linkiem do kroniki została usunięta jako duplikat
        // (zgłoszenie usera 2026-08-12: „mam kronikę w dwóch miejscach").
        //
        // Na górze zostaje WYŁĄCZNIE pytanie „Byłeś?" — i tylko dopóki jest
        // bez odpowiedzi. To nie jest informacja do powtórzenia, tylko jedyny
        // moment, w którym system może zdobyć fakt o obecności; zepchnięte na
        // dół długiej strony po prostu by nie zadziałało. Gdy user już
        // odpowiedział, stan schodzi na dół, do tego samego bloku co kronika.
        $needsAnswer = $attendance !== null && $attendance['attended'] === null;
    ?>
    <?php if ($needsAnswer): ?>
    <section class="sec" id="bylem">
        <div class="box">
            <p class="eyebrow" style="margin-top:0;"><?= __('Ten wyjazd się odbył') ?></p>
            <h2><?= __('Byłeś na tym wyjeździe?') ?></h2>
            <p class="lead"><?= __('Potwierdzenie zajmuje sekundę, a decyduje o tym, kto trafi
                do składu tego wyjazdu — i z kim faktycznie jeździsz.') ?></p>
            <div class="post-ride__act">
                <form method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/bylem') ?>">
                    <?= Core\Csrf::field() ?>
                    <input type="hidden" name="edition_id" value="<?= (int) $e['selectedEditionId'] ?>">
                    <input type="hidden" name="attended" value="1">
                    <button type="submit" class="btn"><?= __('Tak, byłem') ?></button>
                </form>
                <form method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/bylem') ?>">
                    <?= Core\Csrf::field() ?>
                    <input type="hidden" name="edition_id" value="<?= (int) $e['selectedEditionId'] ?>">
                    <input type="hidden" name="attended" value="0">
                    <button type="submit" class="btn btn-secondary"><?= __('Nie dojechałem') ?></button>
                </form>
            </div>
        </div>
    </section>
    <?php endif; ?>


    <!-- O WYJEŹDZIE -->
    <section class="sec" id="o-wyjezdzie">
        <div class="box">
            <h2><?= __('O wyjeździe') ?></h2>
            <p class="lead"><?= nl2br(htmlspecialchars($e['description'] ?? '')) ?></p>
            <?php
                $tnMeta = $e['translation'] ?? null;
                $tnEditUrl = !empty($canManageEvent) ? View::url('/tlumaczenie/event/' . (int) ($e['id'] ?? 0)) : null;
                require __DIR__ . '/../partials/translated-note.php';
            ?>

            <?php if ($e['registrationType'] === 'external' && !empty($externalMethods)): ?>
            <?php
                // Zapisy zewnętrzne — informacyjny wykaz WSZYSTKICH podanych form
                // (link/telefon/e-mail) w treści, żeby dało się skontaktować bez
                // klikania CTA "Zapisz się" w panelu (które przy płatnym evencie
                // zewnętrznym tworzy już wstępną rezerwację). Link pokazuje sam
                // host, nie długi URL. $externalMethods budowane u góry pliku.
            ?>
            <p class="ext-link">
                <?= Icon::render('link') ?>
                <span><?= __('Zapisy prowadzi organizator poza platformą —') ?>

                    <?php foreach ($externalMethods as $i => $m): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= htmlspecialchars($m['href']) ?>"<?= $m['blank'] ? ' target="_blank" rel="noopener nofollow"' : '' ?>><?= htmlspecialchars($m['label']) ?></a><?php endforeach; ?><?= __('.
                    Kontakt z organizatorem nie oznacza jeszcze zapisu na wyjazd.') ?></span>
            </p>
            <?php endif; ?>

            <?php if ($hasTrasaContent): ?>
            <dl class="facts">
                <?php if (!empty($e['difficultyLabel'])): ?>
                <div class="fact"><dt><?= __('Poziom trudności') ?></dt><dd><?= htmlspecialchars($e['difficultyLabel']) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($e['bikeTypes'])): ?>
                <div class="fact"><dt><?= __('Rower') ?></dt><dd><?= htmlspecialchars(implode(' / ', $e['bikeTypes'])) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($e['paceGroupLabel'])): ?>
                <div class="fact"><dt><?= __('Tempo grupy') ?></dt><dd><?= htmlspecialchars($e['paceGroupLabel']) ?></dd></div>
                <?php endif; ?>
                <div class="fact"><dt><?= __('Zapisy') ?></dt><dd>
                    <?php if ($e['registrationType'] === 'external' && !empty($externalMethods)): ?>
                    <?php // Realne formy zapisu, klikalne (link jako domena, telefon/e-mail),
                          // zamiast osamotnionego "Link zewnętrzny organizatora" bez adresu. ?>
                    <?php foreach ($externalMethods as $i => $m): ?><?= $i > 0 ? '<br>' : '' ?><a class="fact-link" href="<?= htmlspecialchars($m['href']) ?>"<?= $m['blank'] ? ' target="_blank" rel="noopener nofollow"' : '' ?>><?= htmlspecialchars($m['label']) ?></a><?php endforeach; ?>
                    <?php elseif ($e['registrationType'] === 'external'): ?>
                    <?= __('Link zewnętrzny organizatora') ?>

                    <?php else: ?>
                    <?= __('Bezpośrednio u organizatora') ?>

                    <?php endif; ?>
                </dd></div>
            </dl>
            <?php if ($e['surfaceBreakdown'] !== null): ?>
            <div class="mini-stat-block" style="margin-top:14px;">
                <span><?= __('Nawierzchnia') ?></span>
                <?= $renderSurfaceBreakdown($e['surfaceBreakdown']) ?>
            </div>
            <?php else: ?>
            <?php $surfaces = array_unique(array_filter(array_column($e['stages'], 'surfaceLabel'))); ?>
            <?php if (!empty($surfaces)): ?>
            <p class="fg-hint" style="margin-top:10px;"><?= __('Nawierzchnia:') ?> <b><?= htmlspecialchars(implode(', ', $surfaces)) ?></b></p>
            <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($e['equipment']) && empty(array_filter($e['equipment'], fn($i) => !$i['isMandatory']))): ?>
            <!-- puste — sprzęt ma własną sekcję "#sprzet" niżej -->
            <?php endif; ?>
        </div>
    </section>

    <?php
    // STANDARDOWA KONTROLKA WARSTW DLA MAP PRZEBIEGU (2026-08-20, zgłoszenie
    // usera: „mapa wydarzenia nie jest spójna z pozostałymi mapami w aplikacji").
    // Drzewo, podpisy i stan domyślny liczy od Etapu 2 `Models\MapLayer`
    // ze słownika (`EventController::show`, łącznie z patchem „mgła to tu
    // dodatek" na kluczu `cells`) — tej stronie zostaje wyłącznie
    // PRZEKAZANIE gotowego `$mapLayers`.
    //
    // Każdy panel dnia ma WŁASNĄ mapę, więc i własną kontrolkę — stąd klucz
    // w id. JS czyta stan checkboxów z kontrolki o tym samym kluczu.
    $mapaWarstwy = static function (string $klucz) use ($mapLayers): void {
        $mlId = 'mapLayers-' . $klucz;
        $mlLayers = $mapLayers;
        require __DIR__ . '/../partials/map-layers.php';
    };
    ?>
    <?php if ($hasPrzebieg): ?>
    <!-- MAPA I PROFIL TRASY + PLAN WYJAZDU (układ modułu tras zachowany 1:1 — Leaflet+GPX,
         prawdziwy wykres profilu, kategoryzacja podjazdów; zmienia się tylko CSS wokół).
         Mapa/profil tylko przy GPX; blok opisowy (plan/notatka/nocleg) także bez GPX
         i dla jednodniówki (patrz $hasPrzebieg/$hasStageDetails). -->
    <section class="sec" id="przebieg">
        <?php if ($hasGpx): ?>
        <h2><?= __('Mapa i profil trasy') ?></h2>
        <div class="route">
            <?php if ($showDayTabs): ?>
            <div class="dtabs" id="dayTabs" role="tablist" aria-label="<?= htmlspecialchars(__('Dni wyjazdu')) ?>">
                <button type="button" class="dtab" role="tab" aria-selected="true" aria-controls="p-all" id="t-all" data-day="all" style="--dc:#8A948C;"><i></i><?= __('Cały wyjazd') ?></button>
                <?php foreach ($gpxStages as $stage): $color = DayColor::forDay($stage['dayNumber']); ?>
                <button type="button" class="dtab" role="tab" aria-selected="false" aria-controls="p-<?= $stage['dayNumber'] ?>" id="t-<?= $stage['dayNumber'] ?>" data-day="<?= $stage['dayNumber'] ?>" style="--dc:<?= $color['color'] ?>;"><i></i><?= __('Dzień {n}', ['n' => $stage['dayNumber']]) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="dpanel" id="p-all" data-panel="all" role="tabpanel" aria-labelledby="t-all">
                <div class="rhead"><span class="rhead__t"><b><?= htmlspecialchars(trim(($gpxStages[0]['startPoint'] ?? '') . ' → ' . implode(' → ', array_filter(array_map(fn($s) => $s['endPoint'], $gpxStages))), ' →')) ?></b></span>
                    <span class="rhead__s"><?= Format::distance($e['totals']['distanceKm']) ?> · <?= __('{n} m przewyższenia', ['n' => $e['totals']['elevationM']]) ?></span></div>
                <p class="legend">
                    <?php foreach ($gpxStages as $stage): $color = DayColor::forDay($stage['dayNumber']); ?>
                    <span style="--dc:<?= $color['color'] ?>;"><i></i><?= __('Dzień {n}', ['n' => $stage['dayNumber']]) ?> · <?= htmlspecialchars(trim($stage['startPoint'] . ' → ' . $stage['endPoint'], ' →')) ?></span>
                    <?php endforeach; ?>
                </p>
                <div class="mapbox"><div class="map-svg" id="map-all" data-gpx-map data-map-key="all"
                     data-gpx-urls='<?= htmlspecialchars(json_encode(array_map(fn($s) => View::url($s['gpxUrl']), $gpxStages)), ENT_QUOTES) ?>'
                     data-gpx-colors='<?= htmlspecialchars(json_encode(array_map(fn($s) => DayColor::forDay($s['dayNumber'])['color'], $gpxStages)), ENT_QUOTES) ?>'></div>
                    <?php $mapaWarstwy('all'); ?>
                    <div id="legend-all" class="hex-legend hex-legend--onmap"></div></div>
                <?php
                    // SUMA PO DNIACH dla zakładki „Cały wyjazd". Sumujemy gotowe
                    // szacunki, a NIE liczymy od nowa na scalonych polach —
                    // przy dniach, które się nakładają, suma byłaby zawyżona,
                    // ale różnica jest w praktyce znikoma, a drugi tor liczenia
                    // (i drugie miejsce do rozjechania się z pierwszym) nie.
                    // Dlatego przy nakładających się dniach mówimy „do".
                    $sumaPreview = null;
                    if ($routePreview) {
                        $sumaPreview = ['cells' => 0, 'newCells' => 0, 'knownCells' => 0,
                                        'ridePoints' => 0, 'discoveryPoints' => 0,
                                        'explorationPoints' => 0, 'points' => 0, 'freshPct' => 0];
                        foreach ($routePreview as $p) {
                            foreach (['cells','newCells','knownCells','ridePoints','discoveryPoints','explorationPoints','points'] as $k) {
                                $sumaPreview[$k] += $p[$k];
                            }
                        }
                        $sumaPreview['freshPct'] = $sumaPreview['cells'] > 0
                            ? (int) round(100 * $sumaPreview['newCells'] / $sumaPreview['cells'])
                            : 0;
                    }
                ?>
                <?= $renderRoutePreview($sumaPreview) ?>
            </div>
            <?php endif; ?>

            <?php foreach ($gpxStages as $stage): $color = DayColor::forDay($stage['dayNumber']); ?>
            <div class="dpanel" id="p-<?= $stage['dayNumber'] ?>" data-panel="<?= $stage['dayNumber'] ?>" role="tabpanel" aria-labelledby="t-<?= $stage['dayNumber'] ?>"<?= $showDayTabs ? ' hidden' : '' ?>>
                <div class="rhead"><span class="rhead__t"><b><?= htmlspecialchars(trim($stage['startPoint'] . ' → ' . $stage['endPoint'], ' →')) ?></b></span>
                    <span class="rhead__s"><?= Format::distance($stage['distanceKm']) ?> · <?= __('{n} m przewyższenia', ['n' => $stage['elevationGainM']]) ?></span></div>
                <?php if ($stage['startElevationM'] !== null): ?>
                <p class="rsub"><?= __('Start:') ?> <b><?= $stage['startElevationM'] ?> m n.p.m.</b><?= $stage['surfaceLabel'] ? ' · ' . htmlspecialchars($stage['surfaceLabel']) : '' ?><?= $stage['date'] ? ' · ' . htmlspecialchars(Format::dateShort($stage['date'])) : '' ?></p>
                <?php endif; ?>
                <div class="mapbox"><div class="day-map-big" id="map-day-<?= $stage['dayNumber'] ?>" data-gpx-map
                     data-map-key="<?= $stage['dayNumber'] ?>"
                     data-gpx-urls='<?= htmlspecialchars(json_encode([View::url($stage['gpxUrl'])]), ENT_QUOTES) ?>'
                     data-gpx-colors='<?= htmlspecialchars(json_encode([$color['color']]), ENT_QUOTES) ?>'></div>
                    <?php $mapaWarstwy((string) $stage['dayNumber']); ?>
                    <div id="legend-<?= $stage['dayNumber'] ?>" class="hex-legend hex-legend--onmap"></div></div>
                <?php // CO MI TO DA — pod mapą tego dnia, bo dotyczy DOKŁADNIE
                      // tej trasy, a nie całego wyjazdu. Przy wielodniówce każdy
                      // dzień ma własną odpowiedź: jeden może być w całości znany,
                      // drugi w całości nowy. ?>
                <?= $renderRoutePreview($routePreview[$stage['dayNumber']] ?? null) ?>
                <?php if (!empty($stage['elevationProfile'])): ?>
                <?php
                    $peaksForChart = array_map(function ($p) {
                        $p['categoryColor'] = $p['category'] !== null ? ClimbCategory::color($p['category']) : null;
                        return $p;
                    }, $stage['peaks']);
                ?>
                <div class="profbox"><div class="day-profile-big" style="width:100%;height:100%;"
                     data-elevation-profile='<?= htmlspecialchars(json_encode($stage['elevationProfile']), ENT_QUOTES) ?>'
                     data-elevation-peaks='<?= htmlspecialchars(json_encode($peaksForChart), ENT_QUOTES) ?>'
                     data-elevation-color="<?= $color['color'] ?>"></div></div>
                <?php endif; ?>
                <?php if (!empty($stage['peaks'])): ?>
                <div class="climbs">
                    <span><?= __('Podjazdy') ?></span>
                    <?php foreach ($stage['peaks'] as $peak): ?>
                    <button type="button" class="climb<?= $peak['category'] !== null ? ' climb--cat' : '' ?>" data-peak-d="<?= $peak['d'] ?>" style="--peak-color:<?= ClimbCategory::color($peak['category']) ?>;" title="<?= htmlspecialchars(__('{km} km podjazdu, śr. {pct}%', ['km' => $peak['climbLengthKm'], 'pct' => $peak['gradientPct']])) ?>"><?= $peak['e'] ?> m<?= $peak['category'] !== null ? ' · ' . ClimbCategory::label($peak['category']) : '' ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <a class="gpxlink" style="--dc:<?= $color['color'] ?>;" href="<?= htmlspecialchars(View::url($stage['gpxUrl'])) ?>" download><?= $e['isMultiday'] ? __('Pobierz GPX — dzień {n}', ['n' => $stage['dayNumber']]) : __('Pobierz GPX') ?> ↓</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; // $hasGpx (mapa i profil) ?>

        <?php if ($e['isMultiday']): ?>
        <h2 style="margin-top:26px;"><?= __('Plan wyjazdu') ?></h2>
        <?php foreach ($e['stages'] as $stage): $stageHasGpx = !empty($stage['gpxUrl']); $color = $stageHasGpx ? DayColor::forDay($stage['dayNumber']) : null; ?>
        <article class="day"<?= $color ? ' style="--dc:' . $color['color'] . ';"' : '' ?>>
            <div class="day__hd">
                <span class="day__n"><?= __('Dzień {n}', ['n' => $stage['dayNumber']]) ?></span>
                <span class="day__t"><?= htmlspecialchars($stage['title'] ?: trim($stage['startPoint'] . ' → ' . $stage['endPoint'], ' →')) ?></span>
                <span class="day__s"><?= $stage['date'] ? htmlspecialchars(Format::dateShort($stage['date'])) . ' · ' : '' ?><?= Format::distance($stage['distanceKm']) ?> · <?= $stage['elevationGainM'] ?> m</span>
            </div>
            <div class="day__body">
                <?php if ($stage['surfaceBreakdown'] !== null): ?>
                <div class="day-surface-breakdown" style="margin-bottom:12px;"><?= $renderSurfaceBreakdown($stage['surfaceBreakdown']) ?></div>
                <?php endif; ?>
                <?php if (!empty($stage['notes'])): ?>
                <p class="day__notes"><?= nl2br(htmlspecialchars($stage['notes'])) ?></p>
                <?php endif; ?>
                <div class="day__foot">
                    <?php if (!empty($stage['accommodationLabel'])): ?>
                    <span class="stay">Nocleg: <?= htmlspecialchars($stage['accommodationLabel']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($stage['includedMealLabels'])): ?>
                    <span class="stay"><?= __('{co} w cenie', ['co' => htmlspecialchars(implode(', ', $stage['includedMealLabels']))]) ?></span>
                    <?php endif; ?>
                    <?php if ($stageHasGpx): ?>
                    <button type="button" class="seeroute" style="--dc:<?= $color['color'] ?>;" data-goto="<?= $stage['dayNumber'] ?>"><?= __('zobacz trasę i profil ↑') ?></button>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php endforeach; ?>

        <?php elseif ($hasStageDetails): ?>
        <?php
            // Jednodniówka: BEZ numeracji dni — pokazujemy tylko to, co realnie
            // podano na jedynym etapie (notatka, nocleg, posiłki). Bez GPX
            // dokładamy podstawowe fakty trasy (start→meta gdy różne, dystans,
            // nawierzchnia), których nigdzie indziej by nie było — z GPX te dane
            // pokazuje już nagłówek mapy wyżej, więc tu tylko treść opisowa.
            $s0 = $e['stages'][0];
            $s0Route = (!empty($s0['startPoint']) && !empty($s0['endPoint']) && $s0['startPoint'] !== $s0['endPoint'])
                ? $s0['startPoint'] . ' → ' . $s0['endPoint'] : '';
            $s0Facts = array_filter([
                !empty($s0['distanceKm']) ? Format::distance($s0['distanceKm']) : null,
                !empty($s0['surfaceLabel']) ? $s0['surfaceLabel'] : null,
            ]);
        ?>
        <?php if (!$hasGpx): ?><h2><?= __('Trasa') ?></h2><?php endif; ?>
        <article class="day"<?= $hasGpx ? ' style="margin-top:20px;"' : '' ?>>
            <?php if (!$hasGpx && ($s0Route !== '' || $s0Facts)): ?>
            <div class="day__hd">
                <?php if ($s0Route !== ''): ?><span class="day__t"><?= htmlspecialchars($s0Route) ?></span><?php endif; ?>
                <?php if ($s0Facts): ?><span class="day__s"><?= htmlspecialchars(implode(' · ', $s0Facts)) ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="day__body">
                <?php if (!empty($s0['notes'])): ?>
                <p class="day__notes"><?= nl2br(htmlspecialchars($s0['notes'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($s0['accommodationLabel']) || !empty($s0['includedMealLabels'])): ?>
                <div class="day__foot">
                    <?php if (!empty($s0['accommodationLabel'])): ?>
                    <span class="stay">Nocleg: <?= htmlspecialchars($s0['accommodationLabel']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($s0['includedMealLabels'])): ?>
                    <span class="stay"><?= __('{co} w cenie', ['co' => htmlspecialchars(implode(', ', $s0['includedMealLabels']))]) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- CENA I WARUNKI -->
    <?php if ($e['isPaid']): ?>
    <section class="sec" id="cena">
        <div class="box">
            <h2><?= __('Cena i warunki') ?></h2>
            <div class="incl2">
                <div>
                    <h3><?= __('W cenie') ?></h3>
                    <ul>
                        <?php foreach ($e['pricing']['included'] as $item): ?>
                        <li><span class="ic ic--y">✓</span><?= htmlspecialchars($item['category']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <h3><?= __('Poza ceną') ?></h3>
                    <ul class="excl">
                        <?php foreach ($e['pricing']['excluded'] as $item): ?>
                        <li><span class="ic ic--n">×</span><?= htmlspecialchars($item['category']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="terms">
                <?php if ($e['pricing']['depositAmount']): ?>
                <p><?= __('<b>Zaliczka {kwota}</b> przy zapisie', ['kwota' => htmlspecialchars(Format::price($e['pricing']['depositAmount'], Format::currencySymbol($e['pricing']['currency'])))]) ?><?= $e['pricing']['paymentDeadlineDays'] ? __(', reszta do {n} dni przed startem', ['n' => $e['pricing']['paymentDeadlineDays']]) : '' ?>.</p>
                <?php elseif ($e['pricing']['paymentDeadlineDays']): ?>
                <p><b><?= __('Płatność do {n} dni przed wyjazdem.', ['n' => $e['pricing']['paymentDeadlineDays']]) ?></b></p>
                <?php endif; ?>
                <?php if ($e['pricing']['cancellationDeadlineDays']): ?>
                <p><?= __('<b>Bezpłatne anulowanie do {n} dni przed</b> wyjazdem — później zaliczka nie podlega zwrotowi.', ['n' => $e['pricing']['cancellationDeadlineDays']]) ?></p>
                <?php endif; ?>
                <?php if (!empty($e['pricing']['cancellationPolicy'])): ?>
                <p><?= nl2br(htmlspecialchars($e['pricing']['cancellationPolicy'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($e['minParticipants'])): ?>
                <p><b><?= __('Minimum {n} osób.', ['n' => $e['minParticipants']]) ?></b> <?= __('Jeśli grupa się nie zbierze, organizator odwołuje wyjazd i zwraca całość wpłat.') ?></p>
                <?php endif; ?>
                <p style="color:var(--ink-mute);font-size:13px;"><?= __('Płatność trafia bezpośrednio do organizatora. ridemore.bike nie pośredniczy w płatnościach i nie pobiera prowizji.') ?></p>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- SPRZĘT -->
    <?php if (!empty($e['equipment'])): ?>
    <section class="sec" id="sprzet">
        <div class="box">
            <h2><?= __('Co zabrać') ?></h2>
            <p style="color:var(--ink-soft);font-size:15px;margin-bottom:13px;"><?= __('Pogrubione pozycje są obowiązkowe — bez nich organizator może odmówić startu.') ?></p>
            <div class="kit">
                <?php foreach ($e['equipment'] as $item): ?>
                <span<?= $item['isMandatory'] ? ' class="req"' : '' ?>><?= $item['isMandatory'] ? '<b>' . htmlspecialchars($item['name']) . '</b>' : htmlspecialchars($item['name']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php // ZBIÓRKA i KTO ORGANIZUJE stoją OBOK SIEBIE (uwaga usera 2026-08-12).
          // Osobno zajmowały ok. 790 px pionu na dwa krótkie bloki kontekstu —
          // adres z pinezką i wizytówka organizatora. Ten sam wzorzec i ta sama
          // klasa co dwie akcje uczestnika w kronice (.chr-actions, grid 1fr 1fr,
          // schodzi pod siebie poniżej 780 px), żeby nie mnożyć siatek. ?>
    <div class="chr-actions">
    <!-- ZBIÓRKA -->
    <section class="sec" id="zbiorka">
        <div class="box">
            <h2><?= __('Zbiórka') ?></h2>
            <div class="meet">
                <div class="meet__info">
                    <p class="row"><?= Icon::render('pin') ?><span><b><?= htmlspecialchars($e['meetingPointAddress'] ?? __('Adres zostanie potwierdzony przez organizatora.')) ?></b></span></p>
                    <?php if ($selectedEdition && !$isLooseRide): ?>
                    <p class="row"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span><b><?= htmlspecialchars(Format::dateP($selectedEdition['startDate'])) ?><?= $selectedEdition['startTime'] ? ', ' . htmlspecialchars(substr($selectedEdition['startTime'], 0, 5)) : '' ?></b></span></p>
                    <?php endif; ?>
                    <?php if ($e['meetingPointLat'] !== null): ?>
                    <?php // NAZWA MÓWI, DOKĄD (2026-09-10, decyzja usera). Samo „Nawiguj"
                          // zajmowało słowo, które w apce ma znaczyć co innego: prowadzenie
                          // po ŚLADZIE trasy jak w liczniku Garmina (kontrakt
                          // tasks/nawigacja-w-apce-ODLOZONE.md). Ten przycisk wiezie do
                          // JEDNEGO PUNKTU — zbiórki — i tyle ma obiecywać.
                          // Adres i zachowanie w apce: partials/nav-button.php.
                          $navLat = $e['meetingPointLat'];
                          $navLon = $e['meetingPointLng'];
                          $navLabel = __('Nawiguj do miejsca zbiórki'); ?>
                    <p style="margin-top:2px;"><?php require __DIR__ . '/../partials/nav-button.php'; ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($e['meetingPointLat'] !== null && $e['meetingPointLng'] !== null): ?>
                <div class="mapbox" style="min-height:200px;border-radius:12px;">
                    <div class="day-map-big" id="meetingMap" style="width:100%;height:100%;" data-lat="<?= $e['meetingPointLat'] ?>" data-lng="<?= $e['meetingPointLng'] ?>"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ORGANIZATOR -->
    <section class="sec" id="organizator">
        <div class="box">
            <h2><?= __('Kto organizuje') ?></h2>
            <div class="orgbox">
                <a href="<?= View::url('/organizatorzy/' . $e['organizer']['slug']) ?>" style="text-decoration:none;">
                <?php if (!empty($e['organizer']['avatarUrl'])): ?>
                <img class="avatar" src="<?= htmlspecialchars(Utils\Image::src($e['organizer']['avatarUrl'], 'av')) ?>" alt="" width="52" height="52">
                <?php else: ?>
                <span class="avatar"><?= htmlspecialchars(mb_substr($e['organizer']['name'], 0, 2)) ?></span>
                <?php endif; ?>
                </a>
                <div style="flex:1;min-width:240px;">
                    <p style="font-family:var(--f-d);font-weight:700;font-size:18px;">
                        <a href="<?= View::url('/organizatorzy/' . $e['organizer']['slug']) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($e['organizer']['name']) ?></a>
                        <?php if ($e['organizer']['isVerified']): ?><span class="badge-ver"><?= __('✓ Zweryfikowany') ?></span><?php endif; ?>
                    </p>
                    <?php if (!empty($e['organizer']['bio'])): ?>
                    <p style="color:var(--ink-soft);font-size:15px;margin-top:6px;"><?= nl2br(htmlspecialchars($e['organizer']['bio'])) ?></p>
                    <?php endif; ?>
                    <div class="orgstats">
                        <span><b><?= $e['organizer']['eventsOrganizedCount'] ?></b><?= __('zorganizowane wyjazdy') ?></span>
                        <?php if ($e['organizer']['reviewCount'] > 0): ?>
                        <span><b><?= $e['organizer']['ratingAvg'] ?></b><?= __('średnia ocena ({n})', ['n' => $e['organizer']['reviewCount']]) ?></span>
                        <?php endif; ?>
                    </div>
                    <p style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn btn-secondary btn--sm" href="<?= View::url('/organizatorzy/' . $e['organizer']['slug']) ?>"><?= __('Profil organizatora') ?></a>
                        <?php if ($orgViewer && $orgViewer->id !== $e['organizerId'] && Models\Message::canMessage($orgViewer->id, $e['organizerId'])): ?>
                        <a class="btn btn-secondary btn--sm" href="<?= View::url('/wiadomosci/z/' . $e['organizerId']) ?>"><?= __('Napisz wiadomość') ?></a>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php if ($e['organizer']['type'] === 'professional_operator' && !empty($e['organizer']['tourismRegisterNumber'])): ?>
            <p class="legal"><b><?= __('Status prawny:') ?></b> <?= __('organizator turystyki wpisany do rejestru pod nr') ?> <?= htmlspecialchars($e['organizer']['tourismRegisterNumber']) ?><?= $e['organizer']['hasLiabilityInsurance'] ? __(', posiada ubezpieczenie OC organizatora') : '' ?>.
                <?= __('Umowa o udział w wyjeździe zawierana jest bezpośrednio z organizatorem. ridemore.bike udostępnia wyłącznie ogłoszenie.') ?></p>
            <?php else: ?>
            <p class="legal"><b><?= __('Organizator społecznościowy.') ?></b> <?= __('Wyjazd jest nieformalny — każdy jedzie na własną odpowiedzialność. To nie jest zarejestrowana impreza turystyczna.') ?></p>
            <?php endif; ?>
        </div>
    </section>
    </div><!-- /.chr-actions (zbiórka + organizator obok siebie) -->

    <?php
    // Etap 2/3 (dopasowania), krok 4 — pod informacją o organizatorze (nie od
    // razu na górze strony, żeby nie konkurować z informacją o samym
    // wydarzeniu). $matchCards już ukształtowane przez Resources\MatchCardResource
    // w Controllers\EventController::show(). Bez zmian PHP/JS względem reszty
    // serwisu — patrz partials/match-suggestions.php.
    require __DIR__ . '/../partials/match-suggestions.php';
    ?>

    <?php if ($isCompleted): ?>
    <!-- OPINIE I RELACJE -->
    <section class="sec" id="opinie">
        <div class="box">
            <h2><?= __('Opinie') ?></h2>
            <?php if ($canReview): ?>
            <form class="inline-review-form" method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/opinia') ?>" enctype="multipart/form-data">
                <?= Core\Csrf::field() ?>
                <div class="rating-picker" data-role="rating-picker">
                    <?php for ($n = 1; $n <= 5; $n++): ?>
                    <button type="button" class="rating-btn" onclick="selectRating(this.closest('[data-role=rating-picker]'), <?= $n ?>)"><?= $n ?></button>
                    <?php endfor; ?>
                    <input type="hidden" name="rating" value="">
                </div>
                <textarea class="search-input" name="comment" rows="3" maxlength="2000" placeholder="<?= htmlspecialchars(__('Jak było? (opcjonalnie)')) ?>"></textarea>
                <div class="inline-review-row">
                    <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp">
                    <button class="btn" type="submit" onclick="return validateRating(this.closest('form'))"><?= __('Wyślij opinię') ?></button>
                </div>
            </form>
            <?php endif; ?>
            <?php if (empty($reviews)): ?>
            <p class="desc"><?= __('Brak opinii.') ?></p>
            <?php else: ?>
            <?php foreach ($reviews as $review): ?>
            <?php
                ob_start();
                if (!empty($review['comment'])):
            ?>
            <div class="review-text"><?= nl2br(htmlspecialchars($review['comment'])) ?></div>
            <?php
                endif;
                renderActivityCard(
                    $review['reviewerName'],
                    Format::dateShort($review['createdAt']),
                    ob_get_clean(),
                    '<span class="review-stars">' . str_repeat(Icon::render('star'), $review['rating']) . '</span>',
                    '',
                    $review['reviewerSlug'] ?? null,
                    '',
                    $review['reviewerAvatarUrl'] ?? null
                );
            ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php
            // RELACJE. Gdy istnieje kronika tego turnusu, jest ona JEDYNYM
            // miejscem, gdzie relacje się czyta — tutaj zostaje sam odsyłacz
            // (uwaga usera 2026-08-12: „poniżej masz Relacje, a to to samo co
            // kronika"). Powielanie tych samych wpisów na dwóch stronach
            // rozmywało, która jest właściwa, i puchło stronę wydarzenia.
            //
            // Bez kroniki (nikt nie potwierdził obecności, więc nie ma składu)
            // relacje pokazujemy tu jak dotąd — inaczej zniknęłyby z serwisu.
            $chronicleUrl = View::url('/kronika/' . $e['slug']) . '?termin=' . (int) $e['selectedEditionId'];
        ?>
        <?php if ($hasChronicle): ?>
        <div class="box" style="margin-top:14px;">
            <h2><?= __('Relacje z tego wyjazdu') ?></h2>
            <p class="lead">
                <?= empty($recaps)
                    ? __('Zebrane są w kronice — razem ze składem i trasą.')
                    : (count($recaps) === 1 ? __('Jest tam 1 wpis uczestnika, razem ze składem i trasą.')
                        : __('Są tam {n} wpisy uczestników, razem ze składem i trasą.', ['n' => count($recaps)])) ?>
            </p>
            <div class="post-ride__act">
                <a class="btn btn-secondary" href="<?= htmlspecialchars($chronicleUrl) ?>"><?= __('Przejdź do kroniki') ?></a>
                <?php if ($canRecap): ?>
                <a class="link-button" href="<?= View::url('/wydarzenia/' . $e['slug'] . '/relacja') ?>?termin=<?= (int) $e['selectedEditionId'] ?>"><?= __('Dodaj wpis do kroniki') ?></a>
                <?php endif; ?>
            </div>

            <?php if ($attendance !== null && !$needsAnswer): ?>
            <?php // Stan MOJEJ obecności — jedna linia w stopce tego bloku.
                  // Zeszła tu z góry razem z kroniką, żeby wszystko „po wyjeździe"
                  // było w jednym miejscu. <div>, NIE <p>: w środku jest <form>,
                  // a formularza nie wolno zagnieżdżać w akapicie (przeglądarka
                  // zamyka <p> przed nim i wyrzuca przycisk poza blok). ?>
            <div class="post-ride__me">
                <?= $attendance['attended']
                    ? __('Potwierdzono — byłeś na tym wyjeździe.')
                    : __('Zaznaczono, że nie dojechałeś na ten termin.') ?>
                <?php if ($attendance['byOrganizer']): ?>
                <span class="roster__lbl" style="display:inline;margin:0 0 0 4px;"><?= __('potwierdzone przez organizatora') ?></span>
                <?php else: ?>
                <form method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/bylem') ?>" style="display:inline;">
                    <?= Core\Csrf::field() ?>
                    <input type="hidden" name="edition_id" value="<?= (int) $e['selectedEditionId'] ?>">
                    <input type="hidden" name="attended" value="<?= $attendance['attended'] ? '0' : '1' ?>">
                    <button type="submit" class="link-button"><?= __('Zmień') ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php // Co ten wyjazd dał (§38) — dokładnie tutaj, tuż pod
                  // potwierdzeniem obecności, bo to jedyny moment, w którym
                  // człowiek już wie, że pojechał, i jeszcze patrzy na tę
                  // stronę. Jedno zdanie, bez animacji i bez wyskakującego
                  // okna: §34 mówi wprost, że gamifikacja ma być subtelna. ?>
            <?php if ($attendance['attended'] && !empty($discoveryRide['cells_new'])): ?>
            <?php $discoveryPoints = (int) ($discoveryRide['points'] ?? 0); ?>
            <div class="post-ride__me">
                <?= __('Odkryłeś na nim') ?> <b class="hexn"><?= Utils\Icon::render('hex') ?><?= (int) $discoveryRide['cells_new'] ?></b>
                <b><?= $plural((int) $discoveryRide['cells_new'], 'nowe pole', 'nowe pola', __('nowych pól')) ?></b>
                — <?= number_format($discoveryPoints, 0, ',', ' ') ?> Discovery.
                <a href="<?= View::url('/odkrycia') ?>"><?= __('Twoja mapa') ?></a>
            </div>
            <?php endif; ?>
            <?php endif; ?>


            <?php if (!empty($galleryPhotos)): ?>
            <div class="section-title"><?= __('Galeria') ?></div>
            <div class="photo-gallery">
                <?php foreach ($galleryPhotos as $photoUrl): ?>
                <a class="ph-link" href="<?= htmlspecialchars(Utils\View::url($photoUrl)) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars(Utils\Image::src($photoUrl, 'thumb')) ?>" alt="<?= htmlspecialchars($e['title']) ?> — <?= htmlspecialchars(__('zdjęcie z wyjazdu')) ?>" loading="lazy"></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="box" style="margin-top:14px;">
            <h2><?= __('Relacje') ?></h2>
            <?php if ($canRecap): ?>
            <p class="spaced-below"><a href="<?= View::url('/wydarzenia/' . $e['slug'] . '/relacja') ?>" class="btn btn-secondary btn--sm"><?= __('Dodaj wpis do kroniki') ?></a></p>
            <?php endif; ?>
            <?php if (empty($recaps)): ?>
            <p class="desc"><?= __('Brak relacji.') ?></p>
            <?php else: ?>
            <?php foreach ($recaps as $recap): ?>
            <?php ob_start(); ?>
            <?php if (!empty($recap['body'])): ?>
            <div class="review-text"><?= nl2br(htmlspecialchars($recap['body'])) ?></div>
            <?php endif; ?>
            <?php if ($recap['youtubeId']): ?>
            <div class="recap-video"><iframe src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($recap['youtubeId']) ?>" title="<?= htmlspecialchars(__('Film z wyjazdu')) ?>" loading="lazy" allowfullscreen></iframe></div>
            <?php endif; ?>
            <?php if (!empty($recap['photos'])): ?>
            <div class="photo-gallery photo-gallery-inline">
                <?php foreach ($recap['photos'] as $photoUrl): ?>
                <a class="ph-link" href="<?= htmlspecialchars(Utils\View::url($photoUrl)) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars(Utils\Image::src($photoUrl, 'thumb')) ?>" alt="<?= htmlspecialchars($e['title']) ?> — <?= htmlspecialchars(__('zdjęcie z relacji')) ?>" loading="lazy"></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php renderActivityCard($recap['authorName'], Format::dateShort($recap['createdAt']), ob_get_clean(), '', '', $recap['authorSlug'] ?? null, '', $recap['authorAvatarUrl'] ?? null); ?>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($galleryPhotos)): ?>
            <div class="section-title"><?= __('Galeria') ?></div>
            <div class="photo-gallery">
                <?php foreach ($galleryPhotos as $photoUrl): ?>
                <a class="ph-link" href="<?= htmlspecialchars(Utils\View::url($photoUrl)) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars(Utils\Image::src($photoUrl, 'thumb')) ?>" alt="<?= htmlspecialchars($e['title']) ?> — <?= htmlspecialchars(__('zdjęcie z wyjazdu')) ?>" loading="lazy"></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; // $hasChronicle — gałąź bez kroniki ?>
    </section>

    <?php // ŚLAD Z ODBYTEGO WYJAZDU (migr. 042) — WŁASNA sekcja, celowo POZA
          // blokiem „Relacje z tego wyjazdu".
          //
          // Pierwsza wersja siedziała w jego stopce, czyli pod warunkiem
          // $hasChronicle — wyjazd bez kroniki nie miał tej kontrolki w ogóle,
          // a to właśnie tam jest najbardziej potrzebna (bez śladu nikt nie
          // dostaje ani jednego pola). Zgłoszenie usera 2026-08-12: „w ogóle
          // nie jest widoczne i niekoniecznie wiadomo, że można i trzeba to
          // zrobić".
          //
          // Ta sama kontrolka renderuje się w kronice — patrz partials/track-upload.php. ?>
    <?php if ($canUploadTrack): ?>
    <?php renderTrackUpload([
        'eventSlug'  => $e['slug'],
        'editionId'  => (int) $e['selectedEditionId'],
        'tracks'     => $editionTracks,
        'canManage'  => $canManageEvent,
        'isAttendee' => $isAttendeeHere,
        'viewerId'   => $viewerId,
    ]); ?>
    <?php endif; ?>
    <?php else: ?>
    <!-- PYTANIA (DYSKUSJA) -->
    <section class="sec qa" id="pytania">
        <div class="box">
            <h2><?= __('Pytania do organizatora') ?></h2>
            <p style="color:var(--ink-soft);font-size:15px;margin-bottom:13px;"><?= __('Odpowiedzi widzą wszyscy — jeśli coś jest niejasne, prawdopodobnie nie tylko dla Ciebie.') ?></p>
            <?php if (Core\Auth::check()): ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/komentarz') ?>">
                <?= Core\Csrf::field() ?>
                <label class="sr" for="qbody"><?= __('Twoje pytanie') ?></label>
                <textarea id="qbody" name="body" rows="3" maxlength="2000" placeholder="<?= htmlspecialchars(__('Zadaj pytanie organizatorowi...')) ?>" required></textarea>
                <div class="qa__row">
                    <span></span>
                    <button class="btn" type="submit"><?= __('Wyślij pytanie') ?></button>
                </div>
            </form>
            <?php else: ?>
            <p class="desc"><a href="<?= View::url('/logowanie') ?>"><?= __('Zaloguj się') ?></a><?= __(', żeby zadać pytanie.') ?></p>
            <?php endif; ?>

            <?php // Q&A wpisywane przez organizatora (2026-08-09) — do spisania
                  // pytań, które padają w kółko poza platformą (telefon,
                  // Messenger). Pytanie pokaże się ANONIMOWO, odpowiedź jako
                  // odpowiedź organizatora — patrz Models\EventComment::createFaqPair().
                  // Widoczne tylko dla osób zarządzających wydarzeniem. ?>
            <?php if ($canModerateDiscussion): ?>
            <details class="faq-add">
                <summary><?= __('+ Dodaj pytanie i odpowiedź (FAQ)') ?></summary>
                <form method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/komentarz/faq') ?>">
                    <?= Core\Csrf::field() ?>
                    <p class="desc" style="font-size:13px;margin:0 0 10px;"><?= __('Pytanie pojawi się anonimowo, tak jakby zadał je uczestnik — pod nim Twoja odpowiedź.') ?></p>
                    <label class="sr" for="faqq"><?= __('Pytanie') ?></label>
                    <input id="faqq" type="text" name="faq_question" maxlength="500" placeholder="<?= htmlspecialchars(__('np. Czy trasa jest przejezdna na oponach 32 mm?')) ?>" required>
                    <label class="sr" for="faqa"><?= __('Odpowiedź') ?></label>
                    <textarea id="faqa" name="faq_answer" rows="3" maxlength="2000" placeholder="<?= htmlspecialchars(__('Twoja odpowiedź...')) ?>" required></textarea>
                    <div class="qa__row"><span></span><button class="btn" type="submit"><?= __('Dodaj do FAQ') ?></button></div>
                </form>
            </details>
            <?php endif; ?>

            <?php if (empty($comments)): ?>
            <p class="qa__empty"><?= __('Nie ma jeszcze pytań. Twoje będzie pierwsze.') ?></p>
            <?php else: ?>
            <?php foreach ($comments as $comment): ?>
            <?php ob_start(); ?>
            <div class="review-text"><?= nl2br(htmlspecialchars($comment['body'])) ?></div>
            <?php if ($canModerateDiscussion): ?>
            <?php // Kasuje CAŁY wątek (pytanie + wszystkie odpowiedzi pod nim) —
                  // ON DELETE CASCADE na parent_comment_id, patrz schema.sql.
                  // Komunikat mówi o tym wprost, żeby nikt nie skasował wątku
                  // sądząc, że usuwa samo pytanie. ?>
            <form class="qa-mod" method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/komentarz/' . $comment['id'] . '/usun') ?>" onsubmit="return confirm(__('Usunąć to pytanie wraz ze wszystkimi odpowiedziami pod nim? Tej operacji nie można cofnąć.'));">
                <?= Core\Csrf::field() ?>
                <button type="submit" class="qa-mod__b"><?= __('Usuń wątek') ?></button>
            </form>
            <?php endif; ?>
            <?php if (Core\Auth::check()): ?>
            <button type="button" class="day-see" onclick="toggleReplyForm(<?= $comment['id'] ?>)"><?= __('Odpowiedz') ?></button>
            <div class="comment-reply-form" id="reply-form-<?= $comment['id'] ?>" style="display:none;">
                <form class="inline-review-form" method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/komentarz') ?>">
                    <?= Core\Csrf::field() ?>
                    <input type="hidden" name="parent_comment_id" value="<?= $comment['id'] ?>">
                    <textarea class="search-input" name="body" rows="2" maxlength="2000" placeholder="<?= htmlspecialchars(__('Odpowiedz...')) ?>" required></textarea>
                    <div class="inline-review-row" style="justify-content:flex-end;">
                        <button class="btn" type="submit"><?= __('Odpowiedz') ?></button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php foreach ($comment['replies'] as $reply): ?>
            <?php ob_start(); ?>
            <div class="review-text"><?= nl2br(htmlspecialchars($reply['body'])) ?></div>
            <?php if ($canModerateDiscussion): ?>
            <form class="qa-mod" method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/komentarz/' . $reply['id'] . '/usun') ?>" onsubmit="return confirm(__('Usunąć tę odpowiedź na stałe?'));">
                <?= Core\Csrf::field() ?>
                <button type="submit" class="qa-mod__b"><?= __('Usuń odpowiedź') ?></button>
            </form>
            <?php endif; ?>
            <?php
                renderActivityCard(
                    $reply['authorName'],
                    Format::dateShort($reply['createdAt']),
                    ob_get_clean(),
                    $reply['isOrganizerReply'] ? '<span class="comment-org-tag">' . __('Organizator') . '</span>' : '',
                    'comment-reply' . ($reply['isOrganizerReply'] ? ' review-organizer' : ''),
                    $reply['authorSlug'] ?? null,
                    '',
                    $reply['authorAvatarUrl'] ?? null
                );
            ?>
            <?php endforeach; ?>
            <?php
                renderActivityCard(
                    $comment['authorName'],
                    Format::dateShort($comment['createdAt']),
                    ob_get_clean(),
                    // Pytanie z FAQ dostaje własny znacznik zamiast plakietki
                    // "Organizator" — mimo że technicznie wpisał je organizator,
                    // prezentuje się jako pytanie uczestnika (patrz migration_034).
                    !empty($comment['isFaq'])
                        ? '<span class="qa-faq-tag">' . __('Częste pytanie') . '</span>'
                        : ($comment['isOrganizerReply'] ? '<span class="comment-org-tag">' . __('Organizator') . '</span>' : ''),
                    $comment['isOrganizerReply'] && empty($comment['isFaq']) ? 'review-organizer' : '',
                    $comment['authorSlug'] ?? null,
                    '',
                    $comment['authorAvatarUrl'] ?? null
                );
            ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<!-- ===== PANEL ZAPISU ===== -->
<?php if ($hasBookingPanel): ?>
<aside id="zapis">
<?php if ($e['statusCode'] === 'draft'): ?>
    <div class="book"><div class="cancelled-banner" style="margin:0;"><?= __('To wydarzenie jest szkicem — widoczne tylko dla Ciebie, dopóki go nie opublikujesz.') ?></div></div>
<?php elseif ($e['statusCode'] === 'cancelled'): ?>
    <div class="book"><div class="cancelled-banner" style="margin:0;"><?= __('To wydarzenie zostało odwołane przez organizatora.') ?></div></div>
<?php elseif ($e['statusCode'] === 'oczekuje_weryfikacji'): ?>
    <div class="book"><div class="cancelled-banner" style="margin:0;"><?= __('To zgłoszenie czeka na zatwierdzenie — nie jest jeszcze publicznie widoczne.') ?></div></div>
<?php elseif ($isBookable): ?>
    <?php // Panel zapisu i skład turnusu jako JEDEN sztywny słupek. Sticky
          // przeniesione z .book na .book-rail — gdyby zostało na panelu,
          // „Kto jedzie" przewijałoby się POD niego i chowało za jego tłem.
          // max-height + przewijanie wewnętrzne to bezpiecznik: przy dużym
          // składzie (kilkanaście osób + peleton + „rozważają udział") słupek
          // przerósłby ekran i sticky przestałoby działać, zabierając dostęp
          // do dolnej części panelu. ?>
    <div class="book-rail">
    <div class="book">
        <?php if ($e['isPaid']): ?>
        <p class="book__price"><b><?= htmlspecialchars(Format::price($e['pricing']['amount'], Format::currencySymbol($e['pricing']['currency']))) ?></b><span><?= __('/ osoba') ?></span></p>
        <?php if ($e['pricing']['depositAmount']): ?>
        <p class="book__dep"><?= __('Zaliczka {kwota} przy zapisie', ['kwota' => htmlspecialchars(Format::price($e['pricing']['depositAmount'], Format::currencySymbol($e['pricing']['currency'])))]) ?><?= $e['pricing']['paymentDeadlineDays'] ? __(', reszta do {n} dni przed', ['n' => $e['pricing']['paymentDeadlineDays']]) : '' ?></p>
        <?php endif; ?>
        <?php else: ?>
        <p class="book__price"><b><?= __('Dołącz do nas') ?></b></p>
        <?php endif; ?>

        <?php if ($hasMultipleEditions): ?>
        <div class="edition-picker">
            <div class="edition-picker-label"><?= __('Ten wyjazd ma kilka terminów — wybierz, na który chcesz się zapisać:') ?></div>
            <div class="edition-picker-chips">
                <?php foreach ($e['editions'] as $ed): ?>
                <a class="edition-chip<?= $ed['id'] === $e['selectedEditionId'] ? ' active' : '' ?><?= $ed['isCancelled'] ? ' cancelled' : '' ?>"
                   href="<?= View::url('/events/' . $e['slug']) . '?termin=' . $ed['id'] ?>">
                    <?= htmlspecialchars(Format::dateP($ed['startDate'])) ?>
                    <?php if ($ed['isCancelled']): ?><span class="edition-chip-note"><?= __('odwołany') ?></span>
                    <?php elseif ($ed['maxParticipants'] !== null): ?><span class="edition-chip-note"><?= max(0, $ed['maxParticipants'] - $ed['confirmedCount']) ?> miejsc</span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($e['hasVariants']): ?>
        <div class="edition-picker">
            <div class="edition-picker-label"><?= __('Wybierz wariant trasy:') ?></div>
            <div class="edition-picker-chips">
                <?php foreach ($e['variants'] as $v): ?>
                <a class="edition-chip<?= $v['id'] === $e['selectedVariantId'] ? ' active' : '' ?><?= (($v['spotsLeft'] ?? null) !== null && $v['spotsLeft'] <= 0) ? ' cancelled' : '' ?>"
                   href="<?= View::url('/events/' . $e['slug']) . '?termin=' . $e['selectedEditionId'] . '&wariant=' . $v['id'] ?>">
                    <?= htmlspecialchars($v['name']) ?>
                    <span class="edition-chip-note"><?= Format::distance($v['distanceKm']) ?><?php if ($e['isPaid'] && $v['priceAmount'] !== null): ?> · <?= htmlspecialchars(Format::price($v['priceAmount'], Format::currencySymbol($e['pricing']['currency']))) ?><?php endif; ?><?php if (($v['spotsLeft'] ?? null) !== null): ?> · <?= $v['spotsLeft'] > 0 ? __('{n} miejsc', ['n' => $v['spotsLeft']]) : __('brak miejsc') ?><?php endif; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($criticalMassProgress !== null && $criticalMassProgress['missing'] > 0): ?>
        <?php $pct = $criticalMassProgress['min'] > 0 ? min(100, round($criticalMassProgress['confirmed'] / $criticalMassProgress['min'] * 100)) : 0; ?>
        <div class="prog">
            <div class="prog__bar"><i style="width:<?= $pct ?>%;"></i></div>
            <p class="prog__lbl"><span><?= __('{a} z {b} zapisanych', ['a' => $criticalMassProgress['confirmed'], 'b' => $criticalMassProgress['min']]) ?></span><?php if ($selectedEdition['maxParticipants']): ?><span><?= __('limit {n} osób', ['n' => $selectedEdition['maxParticipants']]) ?></span><?php endif; ?></p>
            <p class="prog__note"><?= __n((int) $criticalMassProgress['missing'], 'Brakuje <b>{n} osoby</b>, żeby wyjazd był potwierdzony. Jeśli grupa się nie zbierze, dostajesz zwrot całości.', 'Brakuje <b>{n} osób</b>, żeby wyjazd był potwierdzony. Jeśli grupa się nie zbierze, dostajesz zwrot całości.', 'Brakuje <b>{n} osób</b>, żeby wyjazd był potwierdzony. Jeśli grupa się nie zbierze, dostajesz zwrot całości.') ?></p>
        </div>
        <?php endif; ?>

        <?php if (!$e['isPaid'] && $e['registrationType'] === 'external'): ?>
        <?php // Akcja główna = pierwsza podana forma (link > telefon > e-mail);
              // pozostałe pod spodem jako "albo". Patrz $externalMethods u góry. ?>
        <?php $extPrimary = $externalMethods[0] ?? null; ?>
        <?php if ($extPrimary): ?>
        <a href="<?= htmlspecialchars($extPrimary['href']) ?>"<?= $extPrimary['blank'] ? ' target="_blank" rel="noopener"' : '' ?> class="btn btn--full"><?= htmlspecialchars($extPrimary['cta']) ?></a>
        <?php if (count($externalMethods) > 1): ?>
        <div class="book__alt"><span><?= __('albo') ?></span>
            <?php foreach (array_slice($externalMethods, 1) as $m): ?>
            <a href="<?= htmlspecialchars($m['href']) ?>"<?= $m['blank'] ? ' target="_blank" rel="noopener"' : '' ?>><?= htmlspecialchars($m['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php require __DIR__ . '/../partials/interest-toggle.php'; ?>

        <?php elseif ($e['isPaid'] && $e['registrationType'] === 'external'): ?>
            <?php if ($myStatus === 'potwierdzony'): ?>
            <button class="btn joined btn--full" disabled><?= Icon::render('check') ?> Zapisano</button>
            <?php require __DIR__ . '/../partials/cancel-participation-form.php'; ?>
            <?php elseif ($myStatus === 'oczekuje_platnosci'): ?>
            <span class="btn pending btn--full"><?= __('Wstępnie zarezerwowano — czeka na płatność') ?></span>
            <form method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/potwierdz-oplate') ?>">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= $e['selectedEditionId'] ?>">
                <?php if ($e['selectedVariantId']): ?><input type="hidden" name="variant_id" value="<?= $e['selectedVariantId'] ?>"><?php endif; ?>
                <button class="btn btn--full" type="submit"><?= __('Potwierdzam, że opłaciłem/am') ?></button>
            </form>
            <?php require __DIR__ . '/../partials/cancel-participation-form.php'; ?>
            <?php elseif ($myStatus === 'oczekuje_zwrotu'): ?>
            <span class="btn pending btn--full"><?= __('Zwrot w trakcie realizacji') ?></span>
            <?php else: ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/zapisz-zewnetrzne') ?>">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= $e['selectedEditionId'] ?>">
                <?php if ($e['selectedVariantId']): ?><input type="hidden" name="variant_id" value="<?= $e['selectedVariantId'] ?>"><?php endif; ?>
                <button class="btn btn--full" type="submit"><?= __('Zapisz się →') ?></button>
            </form>
            <?php require __DIR__ . '/../partials/interest-toggle.php'; ?>
            <?php endif; ?>

        <?php elseif ($e['isPaid']): ?>
            <?php if ($myStatus === 'potwierdzony'): ?>
            <button class="btn joined btn--full" disabled><?= Icon::render('check') ?> Zapisano</button>
            <?php require __DIR__ . '/../partials/cancel-participation-form.php'; ?>
            <?php elseif ($myStatus === 'oczekuje_platnosci' || $myStatus === 'oczekuje_doplaty'): ?>
            <span class="btn pending btn--full"><?= $myStatus === 'oczekuje_doplaty' ? __('Zaliczka wpłacona — czekamy na dopłatę reszty') : __('Wstępnie zarezerwowano — czeka na płatność') ?></span>
            <?php require __DIR__ . '/../partials/cancel-participation-form.php'; ?>
            <?php if ($paymentInfo): ?>
            <div class="payment-info-box" style="margin-top:12px;">
                <?php if ($paymentInfo['depositAmountLabel']): ?>
                <div class="payment-info-row"><span><?= __('Zaliczka do wpłaty teraz') ?></span><b><?= htmlspecialchars($paymentInfo['depositAmountLabel']) ?></b></div>
                <div class="payment-info-row"><span><?= __('Cena całkowita') ?></span><b><?= htmlspecialchars($paymentInfo['amountLabel']) ?></b></div>
                <p class="desc" style="margin:8px 0 0;"><?= __('Pozostałą kwotę dopłacasz zgodnie z zasadami płatności ustalonymi przez organizatora (patrz sekcja „Cena i warunki”).') ?></p>
                <?php else: ?>
                <div class="payment-info-row"><span><?= __('Kwota') ?></span><b><?= htmlspecialchars($paymentInfo['amountLabel']) ?></b></div>
                <?php endif; ?>
                <?php if ($paymentInfo['deadlineDateLabel']): ?>
                <div class="payment-info-row"><span><?= __('Termin') ?></span><b><?= htmlspecialchars($paymentInfo['deadlineDateLabel']) ?></b></div>
                <?php endif; ?>
                <div class="payment-info-row"><span><?= __('Odbiorca') ?></span><b><?= htmlspecialchars($paymentInfo['bankOwnerName']) ?></b></div>
                <div class="payment-info-row"><span><?= __('Nr konta') ?></span><b><?= htmlspecialchars($paymentInfo['bankAccount']) ?></b></div>
            </div>
            <?php endif; ?>
            <?php elseif ($myStatus === 'oczekuje_zwrotu'): ?>
            <span class="btn pending btn--full"><?= __('Zwrot w trakcie realizacji') ?></span>
            <?php else: ?>
            <a href="<?= View::url('/wydarzenia/' . $e['slug'] . '/zapisz') . '?edition=' . $e['selectedEditionId'] . ($e['selectedVariantId'] ? '&wariant=' . $e['selectedVariantId'] : '') ?>" class="btn btn--full"><?= __('Zarezerwuj miejsce →') ?></a>
            <?php require __DIR__ . '/../partials/interest-toggle.php'; ?>
            <?php endif; ?>

        <?php else: ?>
        <div x-data='eventPage(<?= json_encode($e, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($myStatus === 'potwierdzony') ?>, <?= json_encode(Core\Csrf::token()) ?>)'>
            <div x-show="!joined">
                <button x-on:click="join()" class="btn btn--full"><?= $isLooseRide ? __('Dołączam') : __('Dołącz do wyjazdu →') ?></button>
                <?php require __DIR__ . '/../partials/interest-toggle.php'; ?>
            </div>
            <div x-show="joined" style="display:none;">
                <button class="btn joined btn--full" disabled><?= Icon::render('check') ?> Zapisano</button>
                <?php // Brakujący self-service "wypisz się" dla darmowych wydarzeń
                      // wewnętrznych — jedyna gałąź CTA, gdzie po dołączeniu nie było
                      // ŻADNEGO sposobu na rezygnację poza napisaniem do organizatora
                      // (płatne gałęzie mają cancel-participation-form.php). Osobny,
                      // dedykowany przycisk zamiast reużycia tamtego partiala — inne
                      // brzmienie ("Rozmyśliłem się", nie "Anuluj udział") i celowo
                      // pomarańczowy (.btn--claim, jak przejęcie profilu) żeby był
                      // widoczny, ale nie konkurował z zielonym CTA dołączenia. Ten
                      // sam endpoint co wszędzie indziej (anuluj-udzial) — dla eventu
                      // bez cennika EventPricing::isCancellationDeadlinePassed() nigdy
                      // nie blokuje, więc przycisk zawsze aktywny. ?>
                <form method="post" action="<?= View::url('/wydarzenia/' . $e['slug'] . '/anuluj-udzial') ?>" onsubmit="return confirm(__('Na pewno rezygnujesz z udziału w tym wyjeździe?'));" style="margin-top:8px;">
                    <?= Core\Csrf::field() ?>
                    <input type="hidden" name="edition_id" value="<?= $e['selectedEditionId'] ?>">
                    <button type="submit" class="btn btn--claim btn--full"><?= __('Rozmyśliłem się') ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <p class="book__micro"><?= __('Zapisujesz się u organizatora. Nie pobieramy prowizji.') ?></p>

        <?php
        // Wiersze zaufania pod przyciskiem — wyłącznie z realnych danych, bez
        // fabrykowanych twierdzeń w stylu "gwarancja ubezpieczeniowa" z makiety
        // (patrz decyzja projektowa nr 3 w planie tej przebudowy): termin
        // anulowania liczony z tych samych pól co sekcja "Cena i warunki"
        // wyżej, ubezpieczenie tylko gdy organizator faktycznie dopisał taką
        // pozycję do "W cenie", metoda płatności wprost z registrationType.
        $cancellationDeadlineDate = null;
        if ($e['isPaid'] && !empty($e['pricing']['cancellationDeadlineDays']) && $selectedEdition) {
            $cancellationDeadlineDate = (new \DateTime($selectedEdition['startDate']))
                ->modify('-' . $e['pricing']['cancellationDeadlineDays'] . ' days')
                ->format('Y-m-d');
        }
        $insuranceLabel = null;
        if ($e['isPaid']) {
            foreach ($e['pricing']['included'] as $item) {
                if (mb_stripos($item['category'], 'ubezpiecz') !== false) { $insuranceLabel = $item['category']; break; }
            }
        }
        $paymentMethodLabel = $e['isPaid']
            ? ($e['registrationType'] === 'internal' ? __('Płatność przelewem do organizatora') : __('Zapisy i płatność przez link zewnętrzny organizatora'))
            : null;
        ?>
        <?php if ($cancellationDeadlineDate || $insuranceLabel || $paymentMethodLabel): ?>
        <div class="book__list">
            <?php if ($cancellationDeadlineDate): ?>
            <div><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span><?= __('Bezpłatne anulowanie do') ?> <b><?= htmlspecialchars(Format::dateP($cancellationDeadlineDate)) ?></b></span></div>
            <?php endif; ?>
            <?php if ($insuranceLabel): ?>
            <div><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 4v5c0 5-3.4 8.3-8 9-4.6-.7-8-4-8-9V7l8-4Z"/></svg><span><?= __('{co} w cenie', ['co' => htmlspecialchars($insuranceLabel)]) ?></span></div>
            <?php endif; ?>
            <?php if ($paymentMethodLabel): ?>
            <div><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16v12H4z"/><path d="M4 10h16"/></svg><span><?= htmlspecialchars($paymentMethodLabel) ?></span></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php // .book__who (3 awatary + „N osób już jedzie") USUNIĘTE stąd —
              // po przeniesieniu „Kto jedzie" tuż pod panel mówiłoby dokładnie
              // to samo, dwa razy pod rząd, tylko uboższymi środkami. ?>
    </div>

    <?php // SKŁAD TURNUSU tuż pod przyciskiem zapisu — patrz partials/event-roster.php. ?>
    <?php require __DIR__ . '/../partials/event-roster.php'; ?>
    </div><!-- /.book-rail -->
<?php endif; ?>
</aside>
<?php endif; // $hasBookingPanel ?>
</div>

<?php if ($isBookable): ?>
<div class="mbar">
    <span class="mbar__p">
        <?php if ($e['isPaid']): ?>
        <b><?= htmlspecialchars(Format::price($e['pricing']['amount'], Format::currencySymbol($e['pricing']['currency']))) ?></b>
        <?php else: ?>
        <b><?= __('Bezpłatnie') ?></b>
        <?php endif; ?>
        <small><?= __('zapisy u organizatora') ?></small>
    </span>
    <a href="#zbiorka" class="btn"><?= __('Zobacz szczegóły') ?></a>
</div>
<?php endif; ?>

<script>
function eventPage(initialData, initialJoined, csrfToken) {
    return {
        event: initialData,
        joined: initialJoined,
        async join() {
            const res = await fetch(`<?= View::url('/api/events') ?>/${this.event.slug}/rsvp?edition_id=${this.event.selectedEditionId}`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken }
            });
            if (res.status === 401) {
                window.location.href = <?= json_encode(View::url('/logowanie')) ?>;
                return;
            }
            if (res.ok) this.joined = true;
        }
    };
}

// Scrollspy sekcji — zastępuje dawny showSection() (zakładki). ui.js
// (showSection() itd.) zostaje nietknięte — używają go inne strony
// (organizer-profile.php, review-form.php, "moje konto"), tylko ta strona
// przestała go potrzebować.
(function () {
    var links = Array.prototype.slice.call(document.querySelectorAll('.anchors a'));
    var secs = links.map(function (a) { return document.querySelector(a.getAttribute('href')); }).filter(Boolean);
    if (!('IntersectionObserver' in window) || !secs.length) return;
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            links.forEach(function (l) { l.removeAttribute('aria-current'); });
            var match = links.filter(function (l) { return l.getAttribute('href') === '#' + entry.target.id; })[0];
            if (match) match.setAttribute('aria-current', 'true');
        });
    }, { rootMargin: '-130px 0px -70% 0px' });
    secs.forEach(function (s) { io.observe(s); });
})();

// Karta "Zbiórka" — prawdziwa mapa Leaflet (bez śladu GPX), tworzona leniwie
// dopiero gdy faktycznie widoczna na stronie (IntersectionObserver — sekcja
// jest teraz zawsze w DOM, nie za zakładką, więc nie ma innego sygnału "user
// tu dotarł").
(function () {
    var mapEl = document.getElementById('meetingMap');
    if (!mapEl) return;
    var created = false;
    function createMeetingMap() {
        if (created) return;
        created = true;
        var lat = parseFloat(mapEl.dataset.lat);
        var lng = parseFloat(mapEl.dataset.lng);
        var map = ridemoreCreateMap(mapEl);
        map.setView([lat, lng], 13);
        L.marker([lat, lng]).addTo(map);
        setTimeout(function () { map.invalidateSize(); }, 60);
    }
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) { createMeetingMap(); io.disconnect(); }
        });
        io.observe(mapEl);
    } else {
        createMeetingMap();
    }
})();

function toggleReplyForm(id) {
    const el = document.getElementById('reply-form-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// Link z maila powiadomienia o odpowiedzi w dyskusji wskazuje na #dyskusja —
// dawny hash sprzed przebudowy; #pytania to nowy, prawdziwy anchor tej sekcji.
if (window.location.hash === '#dyskusja' || window.location.hash === '#pytania') {
    document.getElementById('pytania')?.scrollIntoView({ block: 'start' });
}

function showDayTab(key, btn) {
    document.querySelectorAll('#dayTabs .dtab').forEach(t => t.setAttribute('aria-selected', 'false'));
    btn.setAttribute('aria-selected', 'true');
    document.querySelectorAll('.dpanel').forEach(p => { p.hidden = p.dataset.panel !== String(key); });
    initVisibleMaps();
}
document.querySelectorAll('#dayTabs .dtab').forEach(function (btn) {
    btn.addEventListener('click', function () { showDayTab(btn.dataset.day, btn); });
});

function jumpToDay(key) {
    if (!key) return;
    const dayBtn = document.querySelector('#dayTabs .dtab[data-day="' + key + '"]');
    if (dayBtn) showDayTab(key, dayBtn);
    document.querySelector('.route')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
document.querySelectorAll('.seeroute').forEach(function (b) {
    b.addEventListener('click', function () { jumpToDay(b.dataset.goto); });
});

const initedMaps = {};
function initVisibleMaps() {
    document.querySelectorAll('.dpanel:not([hidden]) [data-gpx-map]').forEach(el => {
        const id = el.id;
        if (initedMaps[id]) { initedMaps[id].invalidateSize(); return; }
        const map = ridemoreCreateMap(id);

        // TA SAMA KONTROLKA CO WSZĘDZIE INDZIEJ (2026-08-20). `ridemoreDiscoveryMap`
        // dostaje gotową mapę (`options.map`), więc dokłada tylko warstwy: pół
        // odkryć, znanych tras (kafle) i skarbów. Ślad wyjazdu ląduje na wierzchu
        // przez `ridemoreAddGpxTrack` — tak samo jak na stronie trasy i na
        // profilu rowerzysty, bo plik jest dokładniejszy niż środki pól.
        //
        // `fitToCells: false`, bo kadr należy do ŚLADU: `fitBounds` po wczytaniu
        // GPX-a jest tu jedynym właścicielem kadru i nie może się z niczym bić.
        const kluczMapy = el.dataset.mapKey;
        const boxWarstw = document.getElementById('mapLayers-' + kluczMapy);
        if (typeof ridemoreDiscoveryMap === 'function' && boxWarstw) {
            ridemoreDiscoveryMap(el, {
                map: map,
                // Zalogowany widzi SWOJE odkrycia (od razu widać, ile z tej trasy
                // ma zaliczone), gość — wspólną mapę społeczności.
                context: <?= json_encode(!empty($viewerId) ? 'me' : 'all') ?>,
                endpoint: <?= json_encode($mapEndpoints['cells'] ?? '') ?>,
                <?php // ŹRÓDŁA KAFLI I OPIS FILTRA SKARBÓW — gotowe z kontrolera
                      // (Etap 2). Bez klucza `slady`: panel dnia nie pokazuje tej
                      // warstwy (`only` w `EventController::show`). ?>
                sources: <?= json_encode($mapSources, JSON_UNESCAPED_SLASHES) ?>,
                filters: <?= json_encode($mapFilters, JSON_UNESCAPED_SLASHES) ?>,
                trailsHitEndpoint: <?= json_encode($mapEndpoints['trailsAt'] ?? '') ?>,
                treasuresEndpoint: <?= json_encode($mapEndpoints['treasures'] ?? '') ?>,
                treasureActions: <?= !empty($viewerId) ? 'true' : 'false' ?>,
                claimEndpoint: <?= json_encode($mapEndpoints['claim'] ?? '') ?>,
                confirmEndpoint: <?= json_encode($mapEndpoints['confirm'] ?? '') ?>,
                csrf: <?= json_encode(Core\Csrf::token()) ?>,
                legendEl: document.getElementById('legend-' + kluczMapy),
                fitToCells: false,
                <?php // Wspólny czytnik kontrolki (Etap 1). Panel dnia ma własną
                      // kontrolkę per dzień (`mapLayers-{klucz}`), więc czytnik
                      // dostaje TĘ, a nie pierwszą z brzegu. ?>
                layers: ridemoreReadLayers(boxWarstw)
            });
            boxWarstw.addEventListener('change', function (e) {
                const cb = e.target.closest('[data-layer]');
                if (cb) { map.ridemoreSetLayer(cb.dataset.layer, cb.checked); }
            });
        }

        const urls = JSON.parse(el.dataset.gpxUrls);
        const colors = JSON.parse(el.dataset.gpxColors);
        let bounds = null;
        urls.forEach((url, i) => {
            ridemoreAddGpxTrack(map, url, {
                color: colors[i],
                onLoaded: function (e) {
                    bounds = bounds ? bounds.extend(e.target.getBounds()) : e.target.getBounds();
                    map.fitBounds(bounds);
                }
            });
        });
        initedMaps[id] = map;

        const panel = el.closest('.dpanel');
        const profileEl = panel ? panel.querySelector('.day-profile-big') : null;
        if (profileEl && profileEl.dataset.elevationProfile) {
            ridemoreRenderElevationChart(
                profileEl,
                map,
                JSON.parse(profileEl.dataset.elevationProfile),
                JSON.parse(profileEl.dataset.elevationPeaks || '[]'),
                profileEl.dataset.elevationColor
            );
        }
    });
}

// Mapa(y) modułu tras są teraz zawsze w DOM (nie za zakładką) — ładujemy
// widoczny panel od razu po wejściu na stronę, tak jak dawniej robił to
// pierwszy "ridemore:section-shown" przy otwarciu zakładki "Mapa".
if (document.querySelector('.dpanel [data-gpx-map]')) {
    initVisibleMaps();
}
</script>

<?php // Podgląd zdjęcia — wspólny lightbox (2026-08-22). Do tej pory kliknięcie
      // w kafelek galerii otwierało nową kartę z wariantem `thumb`, czyli
      // obrazkiem 320 px — „powiększenie" pokazywało mniej niż strona.
      // Kafelki oznaczone `.ph-link`; bez JS link dalej działa, tylko od teraz
      // prowadzi do oryginału. ?>
<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
