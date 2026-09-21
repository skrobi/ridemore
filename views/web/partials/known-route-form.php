<?php
// views/web/partials/known-route-form.php
// Formularz znanej trasy — JEDEN dla dodawania i dla edycji.
//
// Oczekuje w zasięgu:
//   $krRoute — wiersz known_routes (edycja) albo null (dodawanie),
//   $krWroc  — stan listy, na którą wrócić po zapisie (opcjonalny),
//   $krStare — pola z ODRZUCONEGO POST-a (opcjonalny; patrz niżej).
//
// Region NIE jest polem tego formularza od migr. 074 — wyprowadza się
// automatycznie z GPX (patrz blok „Region" niżej, tylko przy edycji, bo nowa
// trasa jeszcze nie ma przebiegu do policzenia).
//
// UKŁAD PRZEBUDOWANY 2026-09-12 (zgłoszenie usera: „na dzień dzisiejszy nie da
// się tego używać"). Zmieniły się trzy rzeczy, wszystkie o jednym:
//
//   1. POLA MAJĄ SZEROKOŚĆ NA MIARĘ TREŚCI. Wcześniej każde siedziało
//      w `.form-row` (flex) z klasą `.search-input` (`flex:1`), więc pole na
//      30-znakową nazwę trasy rozciągało się na 1210 px — i tak samo pole na
//      czterocyfrową liczbę punktów. Teraz klasy `.kr-field` z jawną szerokością.
//   2. POLA SĄ POGRUPOWANE (Podstawy / Przebieg / Wyróżnienie) zamiast stać
//      jednym ciągiem ośmiu wierszy przedzielonych akapitami podpowiedzi.
//   3. PODPOWIEDZI SĄ KRÓTKIE. Długie akapity między polami rozbijały rytm
//      formularza tak, że nie dało się go przebiec wzrokiem; to, co naprawdę
//      trzeba wiedzieć o skutkach podmiany przebiegu, stoi teraz jako
//      ostrzeżenie PRZY TYM POLU, a nie jako zdanie w środku listy.
//
// Dlaczego jeden partial, a nie dwa formularze: pola są te same, a dwa
// odrębne szablony rozjeżdżają się przy pierwszej dołożonej kolumnie —
// dokładnie tak, jak rozjechało się dodawanie trasy (miało region i opis)
// z jej późniejszą obsługą (nie miała czym ich zmienić).
//
// Identyfikatory pól niosą sufiks, choć na stronie stoi tylko JEDEN taki
// formularz — sufiks zostaje, bo nic nie kosztuje, a chroni przed powtórzeniem
// błędu, gdyby formularz kiedyś wrócił na wspólny ekran.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Models\DiscoveryScoring;
use Models\Emblem;
use Utils\Format;
use Utils\View;

$krRoute = $krRoute ?? null;
$krWroc = $krWroc ?? [];
$krStare = $krStare ?? null;
$krIsEdit = $krRoute !== null;

// WARTOŚĆ POLA: najpierw to, co człowiek przed chwilą wpisał, dopiero potem
// wiersz z bazy. Bez tego jeden błąd walidacji kazał wpisywać CAŁY formularz od
// nowa — przy dodawaniu trasy nie było nawet czego pokazać, bo $krRoute jest
// wtedy nullem i pola renderowały się puste.
//
// Klucze $krStare są celowo takie same jak kolumny `known_routes`, więc jest tu
// JEDNA ścieżka odczytu, a nie dwie („skąd wziąć nazwę" nie zależy od tego, czy
// to dodawanie, edycja, czy powrót po błędzie).
$krVal = static function (string $key, $default = '') use ($krStare, $krRoute) {
    if ($krStare !== null && array_key_exists($key, $krStare)) {
        return $krStare[$key];
    }
    return is_array($krRoute) ? ($krRoute[$key] ?? $default) : $default;
};

// Plik GPX czekający w gpx/tmp po odrzuconym zapisie. Przeglądarka nie wypełni
// inputu plikowego (i dobrze — to byłaby dziura), więc plik wraca TOKENEM.
$krGpxToken = (string) ($krStare['gpx_token'] ?? '');
$krId = $krIsEdit ? (int) $krRoute['id'] : 0;
$krSuffix = $krIsEdit ? (string) $krId : 'new';
$krAction = $krIsEdit
    ? View::url('/admin/znane-trasy/' . $krId . '/edytuj')
    : View::url('/admin/znane-trasy/dodaj');

// Sugestia wartości punktowej — ta sama funkcja, która nalicza naprawdę
// (DiscoveryScoring), a nie liczba przepisana do widoku. Bez niej pole „puste =
// automatycznie z długości" nie mówi, ILE to znaczy dla TEJ trasy.
$krSugestia = $krIsEdit
    ? DiscoveryScoring::trailValueFor((float) ($krRoute['distance_km'] ?? 0), null)['total'] ?? null
    : null;
?>
<form method="post" action="<?= $krAction ?>" enctype="multipart/form-data" class="kr-form">
    <?= Csrf::field() ?>
    <?php // Stan listy przenoszony przez zapis — inaczej po poprawieniu trasy
          // znalezionej na czwartej stronie wyników admin lądowałby na pierwszej
          // stronie nieprzefiltrowanego katalogu i musiał szukać jej od nowa. ?>
    <?php foreach ($krWroc as $krK => $krV): ?>
    <input type="hidden" name="wroc_<?= htmlspecialchars($krK) ?>" value="<?= htmlspecialchars((string) $krV) ?>">
    <?php endforeach; ?>

    <!-- ── PODSTAWY ────────────────────────────────────────────────── -->
    <div class="kr-group">
        <h3 class="kr-group__h"><?= __('Podstawy') ?></h3>

        <div class="kr-field kr-field--name">
            <label for="kr_name_<?= $krSuffix ?>"><?= __('Nazwa') ?></label>
            <input type="text" id="kr_name_<?= $krSuffix ?>" name="name" maxlength="200" required
                   placeholder="<?= htmlspecialchars(__('np. Velo Czorsztyn')) ?>"
                   value="<?= htmlspecialchars((string) $krVal('name')) ?>">
        </div>

        <?php if ($krIsEdit): ?>
        <?php // Region nie jest polem od migr. 074 — wyprowadza się z przebiegu
              // (known_route_cells ⋈ region_cells). Pokazujemy go jako fakt,
              // nie jako wyłączone pole formularza: wyszarzony input sugeruje
              // „kiedyś się odblokuje", a tu nie odblokuje się nigdy. ?>
        <div class="kr-field">
            <label><?= __('Region') ?></label>
            <p class="kr-readonly">
                <?php if (!empty($krRoute['region_label'])): ?>
                <span class="kr-pill"><?= htmlspecialchars((string) $krRoute['region_label']) ?></span>
                <?php else: ?>
                <span class="kr-pill kr-pill--empty"><?= __('brak') ?></span>
                <?php endif; ?>
                <span class="hint"><?= __('wyliczany z przebiegu — nie da się ustawić ręcznie') ?></span>
            </p>
        </div>
        <?php endif; ?>

        <div class="kr-field kr-field--wide">
            <label for="kr_desc_<?= $krSuffix ?>"><?= __('Opis') ?></label>
            <textarea id="kr_desc_<?= $krSuffix ?>" name="description" rows="3"
                      placeholder="<?= htmlspecialchars(__('Krótko: skąd dokąd, czym się wyróżnia.')) ?>"><?= htmlspecialchars((string) $krVal('description', '')) ?></textarea>
            <p class="hint"><?= __('Widoczny na stronie trasy. Opcjonalny.') ?></p>
        </div>
    </div>

    <!-- ── PRZEBIEG ────────────────────────────────────────────────── -->
    <div class="kr-group">
        <h3 class="kr-group__h"><?= __('Przebieg') ?></h3>

        <?php if ($krIsEdit && !empty($krRoute['gpx_url'])): ?>
        <?php // KTÓRY PLIK TRASA MA TERAZ. Dotąd nie było tego nigdzie na
              // ekranie — zostawał sam input „Podmień przebieg (opcjonalnie)",
              // czyli pytanie bez kontekstu. ?>
        <div class="kr-file">
            <span class="kr-file__ico"><?= Utils\Icon::render('route') ?></span>
            <span class="kr-file__b">
                <span class="kr-file__n"><?= htmlspecialchars(basename((string) $krRoute['gpx_url'])) ?></span>
                <span class="hint">
                    <?= (int) $krRoute['cells_total'] ?> <?= Format::plural((int) $krRoute['cells_total'], 'pole', 'pola', 'pól') ?>
                    <?php if ((float) $krRoute['distance_km'] > 0): ?>
                    · <?= htmlspecialchars(Format::distance((float) $krRoute['distance_km'])) ?>
                    <?php endif; ?>
                </span>
            </span>
            <a class="btn btn-secondary btn--sm" href="<?= htmlspecialchars(View::url((string) $krRoute['gpx_url'])) ?>"
               download="<?= htmlspecialchars((string) $krRoute['slug']) ?>.gpx"><?= __('Pobierz') ?></a>
        </div>
        <?php endif; ?>

        <div class="kr-field kr-field--wide">
            <label for="kr_gpx_<?= $krSuffix ?>"><?= $krIsEdit ? __('Podmień przebieg') : __('Plik GPX z przebiegiem trasy') ?></label>
            <?php // Token trzymanego pliku. `required` schodzi z inputu, kiedy plik
                  // już czeka na serwerze — inaczej przeglądarka blokowałaby wysyłkę
                  // formularza, w którym akurat wszystko jest w porządku. ?>
            <?php if ($krGpxToken !== ''): ?>
            <input type="hidden" name="gpx_token" value="<?= htmlspecialchars($krGpxToken) ?>">
            <?php endif; ?>
            <input type="file" id="kr_gpx_<?= $krSuffix ?>" name="gpx" accept=".gpx"<?= $krIsEdit || $krGpxToken !== '' ? '' : ' required' ?>>
            <?php if ($krGpxToken !== ''): ?>
            <p class="kr-note kr-note--ok">
                <?= Utils\Icon::render('check') ?>
                <?= __('Plik z poprzedniej próby czeka na serwerze — nie musisz wskazywać go drugi raz.') ?>

            </p>
            <?php endif; ?>
        </div>

        <?php if ($krIsEdit): ?>
        <?php // SKUTKI PODMIANY PRZY POLU, KTÓRE JE WYWOŁUJE. Wcześniej stały
              // jako akapit w środku listy pól i czytało się je jako kolejną
              // podpowiedź — a to jest jedyna akcja na tym ekranie, która
              // dotyka danych WSZYSTKICH uczestników. ?>
        <p class="kr-note kr-note--warn">
            <?= Utils\Icon::render('warning') ?>
            <span><?= __('Nowy plik przelicza pola, dystans, nawierzchnię i') ?> <b><?= __('postęp wszystkich uczestników') ?></b><?= __('.
                Adres trasy i zdobyte progi zostają — inaczej niż przy usunięciu i dodaniu od nowa.') ?></span>
        </p>
        <?php endif; ?>

        <div class="kr-field kr-field--wide">
            <label for="kr_photo_<?= $krSuffix ?>"><?= __('Zdjęcie okładkowe') ?></label>
            <?php if ($krIsEdit && !empty($krRoute['cover_photo_url'])): ?>
            <div class="kr-photo">
                <span class="kr-photo__t" style="background-image:url('<?= htmlspecialchars(Utils\Image::src((string) $krRoute['cover_photo_url'], 'thumb')) ?>')"></span>
                <span class="kr-photo__b">
                    <input type="file" id="kr_photo_<?= $krSuffix ?>" name="cover_photo" accept="image/*">
                    <span class="hint"><?= __('Zostawione puste — zdjęcie zostaje bez zmian.') ?></span>
                </span>
            </div>
            <?php else: ?>
            <input type="file" id="kr_photo_<?= $krSuffix ?>" name="cover_photo" accept="image/*">
            <p class="hint"><?= __('Opcjonalne. Widoczne na karcie trasy i w katalogu.') ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── WYRÓŻNIENIE ─────────────────────────────────────────────── -->
    <div class="kr-group">
        <h3 class="kr-group__h"><?= __('Wyróżnienie') ?></h3>

        <div class="kr-row">
            <?php // EMBLEMAT ZA CAŁOŚĆ (migr. 087) — dostępny TAKŻE PRZY
                  // DODAWANIU, w odróżnieniu od wartości punktowej obok. Tamta
                  // musi czekać na edycję, bo punkty za zaległości nalicza
                  // `awardBacklog()` wewnątrz `createFromGpx()`, czyli zanim
                  // wartość z formularza trafiłaby do bazy. Emblematy nadaje
                  // osobny przebieg (`Emblem::sync`), który leci PO zapisie. ?>
            <div class="kr-field kr-field--emblem">
                <label for="kr_emblem_<?= $krSuffix ?>"><?= __('Emblemat za całą trasę') ?></label>
                <select id="kr_emblem_<?= $krSuffix ?>" name="emblem_id">
                    <option value=""><?= __('— bez emblematu —') ?></option>
                    <?php foreach (Emblem::all(true) as $krEm): ?>
                    <option value="<?= (int) $krEm['id'] ?>"
                        <?= (int) $krVal('emblem_id', 0) === (int) $krEm['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($krEm['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint"><?= __('Dostaje go każdy, kto pokryje 100% pól — także wstecz.') ?>

                    <a href="<?= View::url('/admin/emblematy') ?>"><?= __('Nowy emblemat') ?></a></p>
            </div>

            <?php if ($krIsEdit): ?>
            <?php // JEDNO POLE, NIE DWA (2026-09-03, druga iteracja): trasa ma
                  // jedną wartość całkowitą, którą progi dzielą między siebie
                  // równo — patrz DiscoveryScoring::trailValueFor(). Stare trasy
                  // z osobno wypełnionymi `bonus_points`/`completion_bonus`
                  // pokazują tu ich SUMĘ (legacyRouteOverrideTotal), a kolejny
                  // zapis pisze już tylko do `bonus_points`. ?>
            <?php $krTotalOverride = ($krStare !== null && array_key_exists('bonus_points', $krStare))
                ? $krStare['bonus_points']
                : DiscoveryScoring::legacyRouteOverrideTotal(
                    is_array($krRoute) ? $krRoute['bonus_points'] : null,
                    is_array($krRoute) ? $krRoute['completion_bonus'] : null
                ); ?>
            <div class="kr-field kr-field--points">
                <label for="kr_bonus_pts_<?= $krSuffix ?>"><?= __('Wartość trasy') ?></label>
                <span class="kr-unit">
                    <input type="number" min="0" step="1" id="kr_bonus_pts_<?= $krSuffix ?>"
                           name="bonus_points" placeholder="<?= htmlspecialchars(__('auto')) ?>"
                           value="<?= $krTotalOverride === null ? '' : (int) $krTotalOverride ?>">
                    <span><?= __('pkt') ?></span>
                </span>
                <p class="hint">
                    <?php if ($krSugestia !== null): ?>
                    <?= __('Puste =') ?> <b><?= number_format((int) $krSugestia, 0, ',', ' ') ?> <?= __('pkt') ?></b> <?= __('z długości.') ?>

                    <?php else: ?>
                    <?= __('Puste = automatycznie z długości.') ?>

                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($krIsEdit): ?>
        <label class="kr-check" for="kr_bonus_on_<?= $krSuffix ?>">
            <input type="checkbox" id="kr_bonus_on_<?= $krSuffix ?>" name="bonus_enabled" value="1"
                   <?= (bool) $krVal('bonus_enabled', 0) ? 'checked' : '' ?>>
            <?= __('Trasa daje punkty za progi') ?>

        </label>
        <p class="hint" style="margin-top:6px;"><?= __('Wyłączona nie wchodzi do gry punktowej — jej strona
            przestaje obiecywać progi.') ?> <a href="<?= View::url('/admin/punkty') ?>"><?= __('Punkty i trasy') ?></a></p>
        <?php endif; ?>
    </div>

    <?php // PASEK ZAPISU — przyklejony do dołu kolumny, żeby nie trzeba było
          // przewijać po każdą zmianę. DWA PRZYCISKI, bo są dwa zamiary:
          // „Zapisz" zostaje na ekranie (można od razu zobaczyć skutek na
          // podglądzie obok i poprawiać dalej), „Zapisz i wróć" kończy pracę
          // nad tą trasą. Rozstrzyga o tym `name` klikniętego przycisku —
          // przeglądarka wysyła tylko ten jeden (patrz wrocGdzieTrzeba()). ?>
    <div class="kr-save">
        <button class="btn" type="submit"><?= $krIsEdit ? __('Zapisz') : __('Dodaj trasę') ?></button>
        <?php if ($krIsEdit): ?>
        <button class="btn btn-secondary" type="submit" name="zapisz_i_wroc" value="1"><?= __('Zapisz i wróć do listy') ?></button>
        <?php endif; ?>
    </div>
</form>
