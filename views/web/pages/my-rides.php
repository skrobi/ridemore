<?php
// views/web/pages/my-rides.php
// „MOJE PRZEJAZDY" — /admin/moje-przejazdy (2026-08-13).
//
// Pierwszy ekran PANELU należący do rowerzysty, nie do organizatora. Publiczny
// profil opowiada innym, gdzie ta osoba jeździła; ta tabela odpowiada JEJ na
// pytanie „co mam jeszcze do uzupełnienia".
//
// Komponent: .dash-table/.dash-row/.dash-cell — ta sama siatka co lista
// uczestników i płatności. Świadomie NIE .dash-list/.dash-item (karty
// z panelu „Moje wydarzenia"): tam wiersz to obiekt o wielu wymiarach, tu trzy
// krótkie, porównywalne wartości, które chce się przebiec wzrokiem w pionie.
// Na telefonie siatka sama rozkłada się na etykiety (data-label).
//
// Kolumny „Byłem" i „Ślad" są KLIKALNE, nie tylko informacyjne. Tabela, która
// pokazuje brak i każe iść go uzupełniać gdzie indziej, jest listą wyrzutów,
// a nie narzędziem.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\Format;
use Utils\View;

require_once __DIR__ . '/../partials/region-link.php';

require_once __DIR__ . '/../partials/stat-tiles.php';

$rides = $rides ?? [];
$noAnswer = $noAnswer ?? 0;
$noTrack = $noTrack ?? 0;
$viewerSlug = $viewerSlug ?? null;
$soloRides = $soloRides ?? [];
$deviceMeta = $deviceMeta ?? [];
$deviceConns = $deviceConns ?? [];
$devicePending = $devicePending ?? [];
$garminNext = $garminNext ?? 0;
$zrodlo = $zrodlo ?? 'upload';
$tab = ($tab ?? 'wyjazdy') === 'solo' ? 'solo' : 'wyjazdy';
// Szukanie/stronicowanie (2026-08-26) — kontroler liczy je TYLKO na zakładce
// solo; poza nią ten sam kształt z pustymi wynikami, żeby widok nie musiał
// znać różnicy.
$soloSearch = $soloSearch ?? ['items' => [], 'total' => 0, 'strona' => 1, 'stron' => 1, 'szukaj' => ''];

// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display"><?= __('Moje przejazdy') ?></h1>
<p class="desc spaced-below">
    <?= __('Wszystkie wyjazdy z Twoim potwierdzonym zapisem — także te, na których coś jeszcze
    zostało do zrobienia. Przejazd liczy się do odkryć i punktów dopiero wtedy, gdy
    potwierdzisz obecność') ?> <b>i</b> <?= __('istnieje ślad z realizacji.') ?>

</p>

<?php // SKĄD WZIĄĆ ŚLAD — zakładki źródeł (plik, Garmin, Polar, Wahoo, …)
      // mieszkają we WSPÓLNYM partialu, bo ten ekran ma zostać tabelą przejazdów,
      // a nie sześcioma formularzami integracji. Stoi NAD tabelą, bo to jedyna
      // rzecz na tej stronie, którą można dodać od zera; reszta opisuje to,
      // co już jest. ?>
<?php require __DIR__ . '/../partials/ride-sources.php'; ?>

<?php // DWIE ZAKŁADKI (2026-08-23) — zgłoszenie usera po imporcie z Garmina:
      // „działa, ale co z tego, jak nawet nie mogę zobaczyć ich, jak
      // zaczytałem". Wyjazdy i przejazdy solo to dwa RÓŻNE zestawienia (jedno
      // ma organizatora, obecność i punkty za wydarzenie, drugie samą jazdę),
      // więc jedna tabela musiałaby połowę kolumn zostawiać pustą. Zakładka
      // idzie adresem (`?tab=solo`), nie JS-em — link do konkretnej listy
      // musi dać się wysłać i odświeżyć. ?>
<?php
$powiazMsgs = [
    'sesja'                  => __('Sesja wygasła — spróbuj jeszcze raz.'),
    'powiaz-brak-przejazdu'  => __('Nie znaleziono tego przejazdu solo.'),
    'powiaz-brak-zapisu'     => __('Nie masz potwierdzonego zapisu na ten wyjazd — ślad można '
                              . 'powiązać tylko z wyjazdem, na który byłeś zapisany.'),
    'powiaz-nie-bylem'       => __('Przy tym wyjeździe odpowiedziałeś „nie dojechałem". Zmień '
                              . 'odpowiedź na „jednak byłem", a potem powiąż ślad — inaczej '
                              . 'przejazd nie miałby się z czego policzyć.'),
    'powiaz-brak-pliku'      => __('Plik GPX tego przejazdu zniknął z dysku — nie ma czego powiązać.'),
    // Kasowanie przejazdu solo (2026-08-26).
    'solo-brak'              => __('Nie znaleziono tego przejazdu solo — może już go usunąłeś w innej karcie.'),
];
$powiazError = $powiazMsgs[$_GET['blad'] ?? ''] ?? null;
$tabUrl = fn(string $t): string => View::url('/admin/moje-przejazdy') . ($t === 'solo' ? '?tab=solo' : '');

// Wyjazdy, z którymi WOLNO powiązać ślad: potwierdzony zapis (tabela i tak
// pokazuje tylko takie) i brak świadomego „nie dojechałem". Ta sama zasada,
// którą pilnuje serwer w RiderActivity::linkSoloToEdition — lista, która
// pokazuje pozycje kończące się odmową, jest gorsza niż krótsza lista.
$linkable = array_values(array_filter(
    $rides,
    static fn(array $r): bool => $r['attended'] === null || (int) $r['attended'] === 1
));
?>
<div class="disc-tabs">
    <a class="disc-tab<?= $tab === 'wyjazdy' ? ' is-on' : '' ?>" href="<?= htmlspecialchars($tabUrl('wyjazdy')) ?>">
        Wyjazdy<?= $rides ? ' (' . count($rides) . ')' : '' ?></a>
    <a class="disc-tab<?= $tab === 'solo' ? ' is-on' : '' ?>" href="<?= htmlspecialchars($tabUrl('solo')) ?>">
        Przejazdy solo<?= $soloRides ? ' (' . count($soloRides) . ')' : '' ?></a>
</div>

<?php if ($powiazError): ?>
<p class="form-error"><?= htmlspecialchars($powiazError) ?></p>
<?php elseif (($_GET['info'] ?? '') === 'powiazano'): ?>
<p class="form-success"><?= __('Przejazd powiązany z wyjazdem — jest teraz Twoim śladem z tego
    turnusu (i potwierdza obecność). Zniknął z listy solo, żeby te same kilometry
    nie policzyły się dwa razy.') ?></p>
<?php elseif (($_GET['info'] ?? '') === 'solo-usuniety'): ?>
<p class="form-success"><?= __('Przejazd usunięty — zniknął z Twojej mapy odkryć razem z polami
    i punktami, których był pierwszym źródłem.') ?></p>
<?php endif; ?>

<?php if ($tab === 'wyjazdy'): ?>
<?php if ($rides): ?>
<p class="desc spaced-below">
    <b><?= count($rides) ?></b> <?= $plural(count($rides), 'wyjazd', 'wyjazdy', 'wyjazdów') ?><?php
    if ($noAnswer > 0): ?> · <b><?= $noAnswer ?></b> <?= __('bez odpowiedzi „byłem?"') ?><?php endif; ?><?php
    if ($noTrack > 0): ?> · <b><?= $noTrack ?></b> <?= __('bez śladu') ?><?php endif; ?><?php
    if ($noAnswer === 0 && $noTrack === 0): ?> <?= __('· wszystko uzupełnione') ?><?php endif; ?>
</p>
<?php endif; ?>

<?php if (!$rides): ?>
<div class="card">
    <p class="desc" style="margin:0;"><?= __('Nie masz jeszcze żadnego potwierdzonego zapisu.
        Tabela wypełni się po pierwszym wyjeździe.') ?></p>
    <div class="action-row" style="margin-top:14px;">
        <a class="btn" href="<?= View::url('/wydarzenia') ?>"><?= __('Znajdź wyjazd') ?></a>
    </div>
</div>
<?php else: ?>
<div class="dash-table">
    <div class="dash-row dash-head">
        <div class="dash-cell"><?= __('Wyjazd') ?></div>
        <div class="dash-cell"><?= __('Termin') ?></div>
        <div class="dash-cell"><?= __('Byłem') ?></div>
        <div class="dash-cell"><?= __('Ślad') ?></div>
        <div class="dash-cell"><?= __('Odkrycia') ?></div>
        <div class="dash-cell"><?= __('Punkty') ?></div>
    </div>
    <?php foreach ($rides as $ride): ?>
    <?php
        // null = nikt jeszcze nie odpowiedział (NIE to samo co „nie było go" —
        // patrz nota nad EventAttendance). Rozróżnienie musi przetrwać do
        // widoku, bo to jedyna kolumna, która czegoś od człowieka wymaga.
        $attended = $ride['attended'] === null ? null : (int) $ride['attended'] === 1;
        $hasOwn = (int) $ride['own_tracks'] > 0;
        $hasEvent = (int) $ride['event_tracks'] > 0;
        $eventUrl = View::url('/events/' . $ride['slug']) . '?termin=' . (int) $ride['edition_id'];
    ?>
    <div class="dash-row">
        <div class="dash-cell dash-cell-title" data-label="<?= htmlspecialchars(__('Wyjazd')) ?>">
            <a href="<?= htmlspecialchars($eventUrl) ?>"><?= htmlspecialchars($ride['title']) ?></a>
            <?php if (!empty($ride['region_label']) || (float) $ride['planned_km'] > 0): ?>
            <div class="dash-sub">
                <?= !empty($ride['region_label']) ? renderRegionLinks($ride['region_label']) : '' ?><?php
                if (!empty($ride['region_label']) && (float) $ride['planned_km'] > 0): ?> · <?php endif; ?><?php
                if ((float) $ride['planned_km'] > 0): ?><?= htmlspecialchars(Format::distance((float) $ride['planned_km'])) ?><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Termin')) ?>">
            <?= htmlspecialchars(Format::dateShort($ride['start_date']) ?? '') ?>
            <?php if (!empty($ride['end_date']) && $ride['end_date'] !== $ride['start_date']): ?>
            <div class="dash-sub"><?= __('do {data}', ['data' => htmlspecialchars(Format::dateShort($ride['end_date']) ?? '')]) ?></div>
            <?php endif; ?>
        </div>

        <?php // BYŁEM — pytanie zadane wprost tam, gdzie widać, że nie padła
              // odpowiedź. Ten sam POST co przycisk na stronie wydarzenia
              // (/wydarzenia/{slug}/bylem), tylko z `powrot`, żeby nie wyrzucał
              // z listy. Potwierdzenie organizatora jest silniejsze od własnej
              // deklaracji i nie da się go zdjąć — dlatego wtedy bez przycisków. ?>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Byłem')) ?>">
            <?php if ($attended === null): ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $ride['slug'] . '/bylem') ?>" class="dash-cell-actions">
                <?= Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= (int) $ride['edition_id'] ?>">
                <input type="hidden" name="powrot" value="moje-przejazdy">
                <button class="btn btn--sm" type="submit" name="attended" value="1"><?= __('Byłem') ?></button>
                <button class="link-button" type="submit" name="attended" value="0"><?= __('Nie dojechałem') ?></button>
            </form>
            <?php elseif ($attended): ?>
            <b><?= __('Tak') ?></b>
            <?php if (!empty($ride['confirmed_by_organizer'])): ?>
            <div class="dash-sub"><?= __('potwierdzone przez organizatora') ?></div>
            <?php endif; ?>
            <?php else: ?>
            <?= __('Nie dojechałem') ?>

            <?php if (empty($ride['confirmed_by_organizer'])): ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $ride['slug'] . '/bylem') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= (int) $ride['edition_id'] ?>">
                <input type="hidden" name="powrot" value="moje-przejazdy">
                <input type="hidden" name="attended" value="1">
                <button class="link-button" type="submit"><?= __('jednak byłem') ?></button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php // ŚLAD — sam upload zostaje na stronie wyjazdu (potrzebuje pola
              // na plik i opisu; formularz w każdym wierszu rozsadziłby tabelę).
              // Tutaj stan i link prosto do właściwej sekcji. Własny ślad ma
              // pierwszeństwo przed wspólnym i tak też jest opisany. ?>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Ślad')) ?>">
            <?php if ($hasOwn): ?>
            <b><?= __('Twój ślad') ?><?= (int) $ride['own_tracks'] > 1 ? ' ×' . (int) $ride['own_tracks'] : '' ?></b>
            <div class="dash-sub"><a href="<?= htmlspecialchars($eventUrl) ?>#slad"><?= __('zmień') ?></a></div>
            <?php elseif ($hasEvent): ?>
            <?= __('Ślad wyjazdu') ?>

            <div class="dash-sub"><a href="<?= htmlspecialchars($eventUrl) ?>#slad"><?= __('wgraj własny') ?></a></div>
            <?php elseif ($attended === false): ?>
            —
            <?php else: ?>
            <a class="dash-item__warn" href="<?= htmlspecialchars($eventUrl) ?>#slad"><?= __('Brak — wgraj') ?></a>
            <?php endif; ?>
            <?php // ...ALBO WYBIERZ Z PRZEJAZDÓW SOLO (2026-08-23, prośba usera:
                  // „ślad jako wgraj powinien mieć wybór z listy solo"). Bardzo
                  // częsty przypadek po imporcie z Garmina: ślad z tego wyjazdu
                  // JUŻ jest w serwisie, tylko wpadł jako przejazd bez wydarzenia,
                  // więc kazanie go wgrywać drugi raz byłoby proszeniem o to,
                  // co już mamy. Pokazujemy tylko tam, gdzie własnego śladu brak. ?>
            <?php if (!$hasOwn && $attended !== false && $soloRides): ?>
            <div class="dash-sub"><button class="link-button js-powiaz-solo" type="button"
                    data-edycja="<?= (int) $ride['edition_id'] ?>"
                    data-data="<?= htmlspecialchars((string) $ride['start_date']) ?>"><?= __('wybierz z przejazdów solo') ?></button></div>
            <?php endif; ?>
            <?php // ŚLAD BEZ OBECNOŚCI — sytuacja, w której człowiek wgrał plik,
                  // a potem odpowiedział „nie dojechałem" (albo odwrotnie).
                  // Ślad wtedy leży i nie liczy się do niczego, a jedyne, co widać
                  // gdzie indziej, to zero punktów bez powodu. Tabela jest jedynym
                  // miejscem, gdzie da się zestawić obie kolumny obok siebie,
                  // więc to tutaj musi paść wprost. ?>
            <?php if ($attended === false && ($hasOwn || $hasEvent)): ?>
            <div class="dash-sub"><?= __('ślad jest, ale nie liczy się bez potwierdzonej obecności') ?></div>
            <?php endif; ?>
        </div>

        <?php // ODKRYCIA i PUNKTY — myślnik, gdy nie ma przejazdu. Zero
              // znaczyłoby „przejechałeś i nic nie odkryłeś", a to nieprawda:
              // przejazdu po prostu nie ma czym policzyć. ?>
        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Odkrycia')) ?>">
            <?php if ($ride['activity_id'] !== null): ?>
            <span class="hexn"><?= Utils\Icon::render('hex') ?><?= (int) $ride['cells_new'] ?></span>
            <?= $plural((int) $ride['cells_new'], 'pole', 'pola', 'pól') ?>
            <?php if ((float) $ride['ridden_km'] > 0): ?>
            <div class="dash-sub"><?= __('{km} ze śladu', ['km' => htmlspecialchars(Format::distance((float) $ride['ridden_km']))]) ?></div>
            <?php endif; ?>
            <?php else: ?>
            —
            <?php endif; ?>
        </div>

        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Punkty')) ?>">
            <?php if ($ride['activity_id'] !== null): ?>
            <b>+<?= number_format((int) $ride['points'], 0, ',', ' ') ?></b>
            <?php else: ?>
            —
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; /* koniec: pusto albo tabela wyjazdów */ ?>
<?php else: ?>
<?php // PRZEJAZDY SOLO — ślady bez wydarzenia (wgrane ręcznie albo pobrane
      // z Garmina). Kolumny są INNE niż przy wyjazdach, bo nie ma tu ani
      // obecności, ani organizatora: liczy się data, ile przejechane i co
      // z tego weszło na mapę. ?>
<?php if (!$soloRides): ?>
<div class="card">
    <p class="desc" style="margin:0;"><?= __('Nie masz jeszcze żadnego przejazdu solo.
        Wgraj plik GPX albo pobierz przejazdy z Garmina — kontrolki są wyżej.') ?></p>
</div>
<?php else: ?>
<p class="desc spaced-below">
    <b><?= count($soloRides) ?></b>
    <?= $plural(count($soloRides), 'przejazd', 'przejazdy', 'przejazdów') ?> <?= __('bez wydarzenia') ?> ·
    <?= __('razem') ?> <b><?= htmlspecialchars(Format::distance(array_sum(array_map(
        static fn(array $r): float => (float) $r['distance_km'], $soloRides
    )))) ?></b>
</p>

<?php
// SZUKANIE I STRONICOWANIE (2026-08-26, zgłoszenie usera: „jedyna akcja jaka
// może zostać podjęta to przypisanie trasy z wyjazdem, powinienem mieć więcej
// możliwości"). Ten sam wzorzec adresu co panel „Znane trasy"
// (KnownRouteController): stan listy (`q`, `strona`) w linkach, `strona=1`
// nigdy nie trafia do adresu.
$soloStan = ['tab' => 'solo', 'q' => $soloSearch['szukaj'], 'strona' => $soloSearch['strona']];
$soloLink = static function (array $zmiany = []) use ($soloStan): string {
    $p = array_filter(
        array_merge($soloStan, $zmiany),
        static fn($v, string $k): bool => $v !== null && $v !== '' && !($k === 'strona' && (int) $v <= 1),
        ARRAY_FILTER_USE_BOTH
    );
    return View::url('/admin/moje-przejazdy') . ($p ? '?' . http_build_query($p) : '');
};
?>
<form class="card spaced-below" method="get" action="<?= View::url('/admin/moje-przejazdy') ?>"
      style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="tab" value="solo">
    <input class="search-input" type="search" name="q" value="<?= htmlspecialchars($soloSearch['szukaj']) ?>"
           placeholder="<?= htmlspecialchars(__('Szukaj po nazwie (z licznika) albo dacie RRRR-MM-DD')) ?>" style="flex:1 1 240px;">
    <button type="submit" class="btn btn-secondary btn--sm"><?= __('Szukaj') ?></button>
    <?php if ($soloSearch['szukaj'] !== ''): ?>
    <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($soloLink(['q' => null, 'strona' => null])) ?>"><?= __('Wyczyść') ?></a>
    <?php endif; ?>
</form>

<?php if (!$soloSearch['items']): ?>
<div class="card">
    <p class="desc" style="margin:0;"><?= __('Nic nie pasuje do „{q}".', ['q' => htmlspecialchars($soloSearch['szukaj'])]) ?></p>
</div>
<?php else: ?>
<div class="dash-table dash-table--solo">
    <div class="dash-row dash-head">
        <div class="dash-cell"><?= __('Przejazd') ?></div>
        <div class="dash-cell"><?= __('Data') ?></div>
        <div class="dash-cell"><?= __('Dystans') ?></div>
        <div class="dash-cell"><?= __('Odkrycia') ?></div>
        <div class="dash-cell"><?= __('Punkty') ?></div>
        <div class="dash-cell"><?= __('Akcje') ?></div>
    </div>
    <?php foreach ($soloSearch['items'] as $solo): ?>
    <?php
        $soloId = (int) $solo['id'];
        // Własna nazwa (migr. 080), gdy ustawiona; inaczej nazwa z licznika,
        // gdy jest — to jedyne, co odróżnia od siebie wiersze z samych dat
        // i kilometrów. Wgrany plik bez licznika nie ma żadnej z dwóch,
        // dopóki właściciel jej nie nada.
        $soloNazwa = !empty($solo['name']) ? $solo['name'] : (!empty($solo['garmin_name']) ? $solo['garmin_name'] : __('Przejazd solo'));
    ?>
    <div class="dash-row">
        <div class="dash-cell dash-cell-title" data-label="<?= htmlspecialchars(__('Przejazd')) ?>"
             data-rename-url="<?= htmlspecialchars(View::url('/api/rides/' . $soloId . '/nazwa')) ?>">
            <?php // EDYCJA NAZWY WPROST W TABELI (2026-09-05, zgłoszenie usera:
                  // „dodajmy edycję nazwy też tutaj, tak samo jak na stronie
                  // przejazdu"). Ten sam endpoint (/api/rides/{id}/nazwa) i ten
                  // sam wzorzec fetch+FormData co na /przejazd/{id} — różnica
                  // jest wyłącznie w tym, że tu jest WIELE wierszy naraz, więc
                  // zamiast unikalnych id elementów (jak na stronie przejazdu)
                  // JS znajduje swoją trójkę (box/form/błąd) przez
                  // `closest('[data-rename-id]')`. ?>
            <span class="js-nazwa-box" style="display:inline-flex;align-items:center;gap:6px;">
                <span class="js-nazwa-tekst"><?= htmlspecialchars($soloNazwa) ?></span>
                <button type="button" class="iconbtn js-nazwa-edytuj"
                        title="<?= htmlspecialchars(__('Zmień nazwę przejazdu')) ?>" aria-label="<?= htmlspecialchars(__('Zmień nazwę przejazdu')) ?>"><?= Utils\Icon::render('edit') ?></button>
            </span>
            <form class="js-nazwa-form" style="display:none;gap:6px;align-items:center;margin:4px 0 0;">
                <?= Csrf::field() ?>
                <input type="text" class="js-nazwa-input" name="nazwa" maxlength="<?= (int) Models\RiderActivity::NAME_MAX_LENGTH ?>"
                       value="<?= htmlspecialchars($soloNazwa) ?>" style="flex:1;min-width:0;max-width:220px;">
                <button type="submit" class="btn btn--sm"><?= __('Zapisz') ?></button>
                <button type="button" class="btn btn-secondary btn--sm js-nazwa-anuluj"><?= __('Anuluj') ?></button>
            </form>
            <p class="form-error js-nazwa-blad" hidden style="margin:4px 0 0;"></p>
            <?php // REGION (2026-08-27, zgłoszenie usera: „brak widoczności z jakiego
                  // regionu są przejazdy") — ta sama konwencja co w zakładce Wyjazdy:
                  // dopisek pod tytułem, nie osobna kolumna (region bywa pusty, gdy
                  // ślad jeszcze nie ma policzonych pól). ?>
            <?php if (!empty($solo['region_label'])): ?>
            <div class="dash-sub"><?= renderRegionLinks($solo['region_label']) ?></div>
            <?php endif; ?>
            <?php // STRONA PRZEJAZDU (2026-09-03) — od niej zaczyna się wszystko,
                  // co o tym przejeździe wiadomo: mapa, profil, skarby, punkty.
                  // Stoi PRZED linkiem do pliku, bo plik jest surowcem, a strona
                  // odpowiedzią; podgląd w modalu niżej zostaje na „rzut oka
                  // bez opuszczania listy". ?>
            <div class="dash-sub">
                <a href="<?= View::url('/przejazd/' . $soloId) ?>"><?= __('strona przejazdu') ?></a>
                <?php if (!empty($solo['gpx_url'])): ?>
                · <a href="<?= htmlspecialchars(View::url($solo['gpx_url'])) ?>"><?= __('plik GPX') ?></a>
                <?php endif; ?>
                <?php if ((int) $solo['elevation_gain_m'] > 0): ?>
                · <?= __('{n} m w górę', ['n' => (int) $solo['elevation_gain_m']]) ?>

                <?php endif; ?>
            </div>
        </div>

        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Data')) ?>">
            <?= htmlspecialchars(Format::dateShort($solo['ride_date']) ?? '') ?>
            <?php if (!empty($solo['started_at'])): ?>
            <div class="dash-sub"><?= htmlspecialchars(substr((string) $solo['started_at'], 11, 5)) ?></div>
            <?php endif; ?>
        </div>

        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Dystans')) ?>">
            <?= htmlspecialchars(Format::distance((float) $solo['distance_km'])) ?>
        </div>

        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Odkrycia')) ?>">
            <span class="hexn"><?= Utils\Icon::render('hex') ?><?= (int) $solo['cells_new'] ?></span>
            <?= $plural((int) $solo['cells_new'], 'nowe pole', 'nowe pola', __('nowych pól')) ?>
            <?php if ((int) $solo['cells_touched'] > (int) $solo['cells_new']): ?>
            <div class="dash-sub">z <?= (int) $solo['cells_touched'] ?> przejechanych</div>
            <?php endif; ?>
        </div>

        <div class="dash-cell" data-label="<?= htmlspecialchars(__('Punkty')) ?>">
            <b>+<?= number_format((int) $solo['points_total'], 0, ',', ' ') ?></b>
        </div>

        <?php // AKCJE: podgląd, powiązanie z wyjazdem, usunięcie. IKONY z
              // title/aria-label zamiast napisów (zgłoszenie usera 2026-08-27:
              // trzy przyciski z pełnym tekstem rozpychały wiersz) — ten sam
              // komponent `.iconbtn`, którym panel organizatora oznacza
              // podgląd/edycję (patrz dashboard.php), więc tooltip to natywny
              // `title`, nie nowy komponent. POWIĄZ Z WYJAZDEM — modal z
              // wyjazdami, bo lista bywa długa, a wybór wymaga przeczytania
              // nazwy i daty. Data przejazdu jedzie atrybutem, żeby modal umiał
              // ustawić najbliższe terminy na górze (prośba usera: „można
              // nawet powiązać z datą"). ?>
        <div class="dash-cell dash-cell-actions" data-label="<?= htmlspecialchars(__('Akcje')) ?>">
            <?php if (!empty($solo['gpx_url'])): ?>
            <button class="iconbtn js-solo-podglad" type="button"
                    data-modal="soloPodglad<?= $soloId ?>"
                    title="<?= htmlspecialchars(__('Podgląd')) ?>" aria-label="<?= htmlspecialchars(__('Podgląd przejazdu')) ?>"><?= Utils\Icon::render('eye') ?></button>
            <?php endif; ?>
            <?php if ($linkable): ?>
            <button class="iconbtn js-powiaz-wyjazd" type="button"
                    data-przejazd="<?= $soloId ?>"
                    data-data="<?= htmlspecialchars((string) $solo['ride_date']) ?>"
                    title="<?= htmlspecialchars(__('Powiąż z wyjazdem')) ?>" aria-label="<?= htmlspecialchars(__('Powiąż z wyjazdem')) ?>"><?= Utils\Icon::render('link') ?></button>
            <?php else: ?>
            <span class="dash-sub dash-cell-actions__note"><?= __('brak wyjazdów, z którymi można powiązać') ?></span>
            <?php endif; ?>
            <form method="post" action="<?= View::url('/admin/moje-przejazdy/solo/' . $soloId . '/usun') ?>"
                  onsubmit="return confirm(__('Usunąć ten przejazd? Zniknie z Twojej mapy odkryć razem z polami i punktami, których był pierwszym źródłem. Tego nie da się cofnąć.'));">
                <?= Csrf::field() ?>
                <input type="hidden" name="wroc_q" value="<?= htmlspecialchars($soloSearch['szukaj']) ?>">
                <input type="hidden" name="wroc_strona" value="<?= (int) $soloSearch['strona'] ?>">
                <button class="iconbtn is-danger" type="submit"
                        title="<?= htmlspecialchars(__('Usuń')) ?>" aria-label="<?= htmlspecialchars(__('Usuń przejazd')) ?>"><?= Utils\Icon::render('close') ?></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($soloSearch['stron'] > 1): ?>
<div class="action-row" style="align-items:center;">
    <?php if ($soloSearch['strona'] > 1): ?>
    <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($soloLink(['strona' => $soloSearch['strona'] - 1])) ?>"><?= __('← Poprzednia') ?></a>
    <?php endif; ?>
    <span class="desc">Strona <?= (int) $soloSearch['strona'] ?> z <?= (int) $soloSearch['stron'] ?></span>
    <?php if ($soloSearch['strona'] < $soloSearch['stron']): ?>
    <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($soloLink(['strona' => $soloSearch['strona'] + 1])) ?>"><?= __('Następna →') ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // PODGLĄD (2026-08-26) — JEDEN dialog NA WIERSZ, żeby móc skorzystać
      // z gotowego `renderStatTiles()` (formatowanie i polska liczba mnoga
      // po stronie serwera, nie duplikowane w JS-ie). Mapa jest LEKKA, jak
      // w Kronice (`ridemoreCreateMap` + `ridemoreAddGpxTrack`, bez warstw
      // odkryć/skarbów/legendy) — świadomy wyjątek od „jedna standardowa
      // kontrolka mapy": to podgląd JEDNEGO pliku w małym oknie, ta sama
      // klasa problemu co per-wpisowa mapa w kronice, nie strona-temat jak
      // /trasy/{slug}. Mapa inicjuje się LENIWIE, przy PIERWSZYM otwarciu —
      // przy dwudziestu wierszach na stronie nie ma powodu stawiać dwudziestu
      // instancji Leafleta, których nikt nie zobaczy. ?>
    <?php foreach ($soloSearch['items'] as $solo): ?>
    <?php if (empty($solo['gpx_url'])) { continue; } ?>
    <dialog class="pick-modal" id="soloPodglad<?= (int) $solo['id'] ?>"
            data-gpx="<?= htmlspecialchars(View::url($solo['gpx_url'])) ?>">
        <div class="pick-modal__box">
            <h3 class="pick-modal__title">
                <?= htmlspecialchars(!empty($solo['name']) ? $solo['name'] : (!empty($solo['garmin_name']) ? $solo['garmin_name'] : __('Przejazd solo'))) ?></h3>
            <p class="desc pick-modal__hint"><?= htmlspecialchars(Format::dateShort($solo['ride_date']) ?? '') ?></p>
            <div class="chr-route__map" style="margin-bottom:14px;"></div>
            <?php renderStatTiles([
                ['lbl' => __('Dystans'), 'val' => Format::distance((float) $solo['distance_km'])],
                (int) $solo['elevation_gain_m'] > 0
                    ? ['lbl' => __('Przewyższenie'), 'val' => (int) $solo['elevation_gain_m'], 'small' => 'm']
                    : null,
                ['lbl' => __('Nowe pola'), 'val' => (int) $solo['cells_new'], 'hex' => true,
                 'sub' => 'z ' . (int) $solo['cells_touched'] . ' przejechanych'],
                ['lbl' => __('Punkty'), 'val' => '+' . number_format((int) $solo['points_total'], 0, ',', ' ')],
            ], ['compact' => true]); ?>
            <div class="action-row">
                <a class="btn btn-secondary btn--sm" href="<?= htmlspecialchars(View::url($solo['gpx_url'])) ?>"><?= __('Pobierz plik GPX') ?></a>
                <button class="btn btn-secondary btn--sm" type="button"
                        onclick="this.closest('dialog').close()"><?= __('Zamknij') ?></button>
            </div>
        </div>
    </dialog>
    <?php endforeach; ?>
<?php endif; /* koniec: nic nie pasuje do szukania albo tabela */ ?>
<?php endif; ?>
<?php endif; ?>

<div class="action-row">
    <a class="btn btn-secondary" href="<?= View::url('/odkrycia') ?>"><?= __('Moja mapa odkryć') ?></a>
    <?php if ($viewerSlug): ?>
    <a class="btn btn-secondary" href="<?= View::url('/rowerzysta/' . $viewerSlug) ?>"><?= __('Mój profil rowerzysty') ?></a>
    <?php endif; ?>
</div>

<?php // DWA MODALE, JEDNA CZYNNOŚĆ (2026-08-23). Powiązanie przejazdu solo
      // z wyjazdem opisane z dwóch stron: z listy solo wybiera się wyjazd,
      // z wiersza wyjazdu wybiera się przejazd. Oba formularze idą tą samą
      // trasą (`/admin/moje-przejazdy/powiaz`) z tą samą parą liczb — różnią
      // się wyłącznie tym, którą z nich człowiek klika.
      //
      // Natywny <dialog>, jak lightbox zdjęć (partials/photo-lightbox.php):
      // Escape, blokada tła i pułapka na fokus są za darmo, a bez JS-a strona
      // dalej działa (przyciski po prostu nic nie otwierają).
      //
      // Listy renderuje PHP RAZ, a JS je tylko porządkuje wg daty — składanie
      // wierszy w JS-ie znaczyłoby drugą kopię tego samego HTML-a. ?>
<?php if ($soloRides && $linkable): ?>
<dialog class="pick-modal" id="modalWyjazd">
    <form method="post" action="<?= View::url('/admin/moje-przejazdy/powiaz') ?>" class="pick-modal__box">
        <?= Csrf::field() ?>
        <input type="hidden" name="tab" value="solo">
        <input type="hidden" name="przejazd_id" value="">
        <h3 class="pick-modal__title"><?= __('Z którym wyjazdem powiązać ten przejazd?') ?></h3>
        <p class="desc pick-modal__hint"><?= __('Ślad stanie się Twoim śladem z tego turnusu i potwierdzi
            obecność. Z listy solo zniknie, żeby te same kilometry nie policzyły się dwa razy.') ?>

            <b><?= __('Ślad wyjazdu widać przy wyjeździe i nie jest przycinany w okolicy startu') ?></b> <?= __('—
            przejazd solo ma tam ucięte pola, wyjazd nie.') ?></p>
        <div class="pick-modal__list">
            <?php foreach ($linkable as $r): ?>
            <button class="pick-opt" type="submit" name="edition_id" value="<?= (int) $r['edition_id'] ?>"
                    data-data="<?= htmlspecialchars((string) $r['start_date']) ?>">
                <span class="pick-opt__main">
                    <b><?= htmlspecialchars($r['title']) ?></b>
                    <span class="dash-sub"><?= htmlspecialchars(Format::dateShort($r['start_date']) ?? '') ?><?php
                        if ((int) $r['own_tracks'] > 0): ?> <?= __('· masz już swój ślad') ?><?php endif; ?></span>
                </span>
                <span class="pick-opt__tag"></span>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="action-row">
            <button class="btn btn-secondary btn--sm" type="button" value="cancel"
                    onclick="this.closest('dialog').close()"><?= __('Anuluj') ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($soloRides && $tab === 'wyjazdy'): ?>
<dialog class="pick-modal" id="modalSolo">
    <form method="post" action="<?= View::url('/admin/moje-przejazdy/powiaz') ?>" class="pick-modal__box">
        <?= Csrf::field() ?>
        <input type="hidden" name="tab" value="wyjazdy">
        <input type="hidden" name="edition_id" value="">
        <h3 class="pick-modal__title"><?= __('Który przejazd solo to był ten wyjazd?') ?></h3>
        <p class="desc pick-modal__hint"><?= __('Wybrany ślad stanie się Twoim śladem z tego turnusu
            i potwierdzi obecność.') ?> <b><?= __('Ślad wyjazdu widać przy wyjeździe i nie jest przycinany
            w okolicy startu') ?></b> <?= __('— przejazd solo ma tam ucięte pola, wyjazd nie.') ?></p>
        <div class="pick-modal__list">
            <?php foreach ($soloRides as $solo): ?>
            <button class="pick-opt" type="submit" name="przejazd_id" value="<?= (int) $solo['id'] ?>"
                    data-data="<?= htmlspecialchars((string) $solo['ride_date']) ?>">
                <span class="pick-opt__main">
                    <b><?= htmlspecialchars(!empty($solo['name']) ? $solo['name'] : (!empty($solo['garmin_name']) ? $solo['garmin_name'] : __('Przejazd solo'))) ?></b>
                    <span class="dash-sub"><?= htmlspecialchars(Format::dateShort($solo['ride_date']) ?? '') ?>
                        · <?= htmlspecialchars(Format::distance((float) $solo['distance_km'])) ?></span>
                </span>
                <span class="pick-opt__tag"></span>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="action-row">
            <button class="btn btn-secondary btn--sm" type="button"
                    onclick="this.closest('dialog').close()"><?= __('Anuluj') ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($soloRides && ($linkable || $tab === 'wyjazdy')): ?>
<script>
(function () {
    // Bez wsparcia dla <dialog> nie podpinamy nic — przyciski zostają martwe,
    // ale nic się nie psuje (ta sama decyzja co w photo-lightbox.php).
    if (typeof HTMLDialogElement === 'undefined') { return; }

    // Kolejność wg ODLEGŁOŚCI OD DATY przejazdu — przy dwudziestu wyjazdach
    // szuka się tego z tego samego dnia, a nie tego, który jest wyżej
    // alfabetycznie. Ten sam dzień dostaje jeszcze etykietę, bo to jest
    // odpowiedź, po którą człowiek tu przyszedł.
    function ustawKolejnosc(modal, data) {
        var lista = modal.querySelector('.pick-modal__list');
        var opcje = Array.prototype.slice.call(lista.querySelectorAll('.pick-opt'));
        var cel = Date.parse(data);
        opcje.forEach(function (opt) {
            var roznica = Math.abs(Date.parse(opt.dataset.data) - cel) / 86400000;
            opt.dataset.roznica = isNaN(roznica) ? 99999 : roznica;
            var tag = opt.querySelector('.pick-opt__tag');
            tag.textContent = roznica === 0 ? __('ten sam dzień') : (roznica <= 2 ? __('{n} dni różnicy', { n: Math.round(roznica) }) : '');
            tag.className = 'pick-opt__tag' + (roznica === 0 ? ' is-match' : '');
        });
        opcje.sort(function (a, b) { return a.dataset.roznica - b.dataset.roznica; })
             .forEach(function (opt) { lista.appendChild(opt); });
    }

    function podepnij(selektorPrzycisku, idModala, poleUkryte, atrybut) {
        var modal = document.getElementById(idModala);
        if (!modal) { return; }
        document.querySelectorAll(selektorPrzycisku).forEach(function (btn) {
            btn.addEventListener('click', function () {
                modal.querySelector('input[name="' + poleUkryte + '"]').value = btn.dataset[atrybut];
                ustawKolejnosc(modal, btn.dataset.data);
                modal.showModal();
            });
        });
    }

    podepnij('.js-powiaz-wyjazd', 'modalWyjazd', 'przejazd_id', 'przejazd');
    podepnij('.js-powiaz-solo', 'modalSolo', 'edition_id', 'edycja');
})();
</script>
<?php endif; ?>

<?php if ($soloSearch['items']): ?>
<script>
(function () {
    // Bez <dialog> (stare przeglądarki) przyciski „Podgląd" zostają martwe —
    // ta sama decyzja co przy photo-lightbox.php i modalach powiązania wyżej.
    if (typeof HTMLDialogElement === 'undefined') { return; }

    document.querySelectorAll('.js-solo-podglad').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dialog = document.getElementById(btn.dataset.modal);
            if (!dialog) { return; }
            dialog.showModal();

            // MAPA LENIWA — TYLKO PRZY PIERWSZYM OTWARCIU tego dialogu, i
            // dopiero PO showModal(): kontener ma zerowy rozmiar, dopóki
            // <dialog> nie jest widoczny, a Leaflet mierzy go w chwili
            // tworzenia mapy. `getBoundingClientRect()` wymusza układ, żeby
            // to mierzenie zdążyło zobaczyć realny rozmiar, nie zero.
            if (dialog.dataset.mapInit || typeof ridemoreCreateMap !== 'function') { return; }
            dialog.dataset.mapInit = '1';

            var el = dialog.querySelector('.chr-route__map');
            el.getBoundingClientRect();
            var map = ridemoreCreateMap(el);
            map.invalidateSize();
            if (typeof ridemoreAddGpxTrack === 'function') {
                ridemoreAddGpxTrack(map, dialog.dataset.gpx, {
                    onLoaded: function (e) {
                        map.fitBounds(e.target.getBounds(), { padding: [18, 18] });
                        map.invalidateSize();
                    }
                });
            }
        });
    });

    // Klik w tło zamyka — ten sam wzorzec co photo-lightbox.php.
    document.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('pick-modal')
            && e.target.id.indexOf('soloPodglad') === 0) {
            e.target.close();
        }
    });
})();
</script>

<script>
(function () {
    // EDYCJA NAZWY WPROST W TABELI (2026-09-05) — ten sam endpoint i wzorzec
    // fetch+FormData co pencil na /przejazd/{id} (RideController::show).
    // Różnica: tu jest WIELE wierszy naraz, więc zamiast unikalnych id
    // elementów każdy wiersz trzyma swoją trójkę (box/form/błąd) pod jedną
    // komórką z `data-rename-url` — JS znajduje ją przez `closest()`,
    // zamiast składać adres z osobnego identyfikatora.
    document.querySelectorAll('.js-nazwa-edytuj').forEach(function (btn) {
        var cell = btn.closest('[data-rename-url]');
        if (!cell) { return; }
        var box = cell.querySelector('.js-nazwa-box');
        var tekst = cell.querySelector('.js-nazwa-tekst');
        var form = cell.querySelector('.js-nazwa-form');
        var input = cell.querySelector('.js-nazwa-input');
        var anuluj = cell.querySelector('.js-nazwa-anuluj');
        var blad = cell.querySelector('.js-nazwa-blad');

        function pokazForm() {
            box.style.display = 'none';
            form.style.display = 'flex';
            blad.hidden = true;
            input.value = tekst.textContent;
            input.focus();
            input.select();
        }
        function pokazBox(nowyTekst) {
            if (nowyTekst !== undefined) { tekst.textContent = nowyTekst; }
            form.style.display = 'none';
            box.style.display = 'inline-flex';
        }

        btn.addEventListener('click', pokazForm);
        anuluj.addEventListener('click', function () { pokazBox(); });
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { pokazBox(); }
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var przycisk = form.querySelector('button[type="submit"]');
            przycisk.disabled = true;
            blad.hidden = true;

            var dane = new FormData(form);
            fetch(cell.dataset.renameUrl, { method: 'POST', body: dane, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (o) {
                    przycisk.disabled = false;
                    if (o.error) {
                        blad.textContent = o.error;
                        blad.hidden = false;
                        return;
                    }
                    pokazBox(o.displayName);
                })
                .catch(function () {
                    przycisk.disabled = false;
                    blad.textContent = __('Nie udało się zapisać — spróbuj ponownie.');
                    blad.hidden = false;
                });
        });
    });
})();
</script>
<?php endif; ?>

