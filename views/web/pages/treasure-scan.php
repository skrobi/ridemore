<?php
// views/web/pages/treasure-scan.php
// EKRAN SKARBU — to, co widzi telefon po zeskanowaniu naklejki (SKA/5).
//
// Jedyny ekran w serwisie oglądany na stojąco, w terenie, często w słońcu
// i jedną ręką. Stąd: duża liczba punktów jako bohater, jedna akcja, żadnych
// pobocznych linków przed odebraniem skarbu.
//
// KOLEJNOŚĆ TREŚCI JEST ODWROTNA NIŻ WSZĘDZIE INDZIEJ: najpierw nagroda,
// potem czym jest to miejsce. Gdzie indziej najpierw tłumaczymy, potem
// nagradzamy — tutaj człowiek już stoi przed naklejką i chce wiedzieć, czy się
// udało.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
require_once __DIR__ . '/../partials/region-link.php';

$treasure = $treasure ?? null;
$result = $result ?? null;
$collection = $collection ?? ['found' => 0, 'total' => 0, 'label' => null];
$isLoggedIn = $isLoggedIn ?? false;
$finders = $finders ?? 0;

$powody = [
    'juz_zaliczona' => [__('Ten skarb już masz'), __('Punkty naliczyły się przy pierwszym znalezieniu — drugi raz nie płacimy za to samo miejsce.')],
    'za_daleko'     => [__('Jesteś za daleko'), __('Skarb zalicza się na miejscu. Podejdź bliżej i spróbuj jeszcze raz.')],
    'nieznana'      => [__('Nie znamy tego kodu'), __('Naklejka mogła zostać wymieniona albo skarb wycofany.')],
    'nieaktywna'    => [__('Ten skarb jest nieaktywny'), __('Wrócił do skrzyni — może pojawić się znowu w sezonie.')],
    'sesja'         => [__('Sesja wygasła'), __('Spróbuj jeszcze raz.')],
    // Zapis się nie udał z powodu po naszej stronie. Osobny komunikat, bo
    // wcześniej taki przypadek wracał jako „ten skarb już masz" — czyli
    // odpowiedź, po której nikt nigdy nie zgłosiłby błędu.
    'blad_zapisu'   => [__('Nie udało się zapisać'), __('Spróbuj jeszcze raz za chwilę. Kod pozostaje ważny.')],
    'zaloguj'       => [__('Zaloguj się, żeby odebrać'), __('Skarb czeka — kod pozostaje ważny.')],
];
$ok = !empty($result['ok']);
// GALERIA (SKA/14) — domyslne wartosci, bo widok bywa renderowany takze
// z galezi „nieznany kod", ktora ich nie przekazuje.
$photos      = $photos ?? [];
$canAddPhoto = $canAddPhoto ?? false;
$photoLimit  = $photoLimit ?? 3;
$photoError  = $photoError ?? null;
$photoNotice = $photoNotice ?? null;

/**
 * GALERIA + FORMULARZ DODANIA — jeden blok dla obu galezi ekranu.
 *
 * Ten ekran ma dwie odslony (nagroda po zaliczeniu i widok przed) i zdjecia
 * maja sens w obu: po zaliczeniu sa nagroda, przed — dowodem, ze warto tam
 * pojechac. Domkniecie zamiast dwoch kopii, bo blok ma kilkanascie linijek
 * i rozjechalby sie przy pierwszej zmianie.
 *
 * KTO CO WIDZI rozstrzyga KONTROLER (TreasureScanController::render), nie ten
 * plik: przy Tropie i Ukrytym `$photos` przychodzi puste, dopoki ogladajacy
 * nie znajdzie skarbu. Widok nie zna reveal_level i nie ma prawa go znac.
 */
$galeriaSkarbu = static function (array $treasure) use ($photos, $canAddPhoto, $photoLimit, $photoError, $photoNotice) {
    if (!$photos && !$canAddPhoto && !$photoError && !$photoNotice) {
        return;
    }
    ?>
    <div class="ts__gal">
        <?php if ($photoNotice): ?>
        <p class="ts__galnote is-ok"><?= htmlspecialchars($photoNotice) ?></p>
        <?php endif; ?>
        <?php if ($photoError): ?>
        <p class="ts__galnote is-err"><?= htmlspecialchars($photoError) ?></p>
        <?php endif; ?>

        <?php if ($photos): ?>
        <p class="roster__lbl"><?= count($photos) === 1 ? __('1 zdjęcie tego miejsca') : __('{n} zdjęcia tego miejsca', ['n' => count($photos)]) ?></p>
        <div class="ts__galgrid">
            <?php foreach ($photos as $zdj): ?>
            <?php // `.ph-link` = wspolny lightbox serwisu (partials/photo-lightbox.php).
                  // href prowadzi do ORYGINALU, kafelek pokazuje wariant `thumb`. ?>
            <a class="ph-link" href="<?= htmlspecialchars(Utils\View::url($zdj['url'])) ?>"
               target="_blank" rel="noopener" aria-label="<?= htmlspecialchars(__('Powiększ zdjęcie')) ?>"
               style="background-image:url('<?= htmlspecialchars(Utils\Image::src($zdj['url'], 'thumb')) ?>')"></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($canAddPhoto): ?>
        <?php // Formularz widzi WYLACZNIE znalazca (guard w kontrolerze). Zdjecie
              // skarbu ukrytego jest najmocniejszym spoilerem w tym module, wiec
              // dorzucic je moze tylko ktos, kto to miejsce i tak juz widzial. ?>
        <form method="post" enctype="multipart/form-data" class="ts__galform"
              action="<?= Utils\View::url('/skarb/' . $treasure['code'] . '/zdjecie') ?>">
            <?= \Core\Csrf::field() ?>
            <label class="ts__galadd">
                <input type="file" name="photos[]" id="tsGalPhotos" multiple accept="image/jpeg,image/png,image/webp">
                <span><?= __('Dorzuć swoje zdjęcie') ?></span>
            </label>
            <?php // APARAT WPROST (Etap 6, 2026-08-28) — tylko w apce; w
                  // przeglądarce zwykły <input type=file> na telefonie i tak
                  // proponuje aparat jako źródło, więc drugi przycisk byłby
                  // powtórzeniem. `native.js` dokłada zdjęcie do #tsGalPhotos
                  // (data-rm-camera-for) — formularz zostaje zwykłym POST-em. ?>
            <?php if (APP_IS_APP): ?>
            <button type="button" class="btn btn-secondary btn--sm" data-rm-camera-for="tsGalPhotos"><?= Utils\Icon::render('camera') ?> <?= __('Zrób zdjęcie') ?></button>
            <?php endif; ?>
            <button class="btn btn--sm" type="submit"><?= __('Wyślij') ?></button>
        </form>
        <p class="ts__hint"><?= __('Widziałeś to miejsce — pokaż je innym. Do {n} zdjęć na osobę.', ['n' => (int) $photoLimit]) ?></p>
        <?php endif; ?>
    </div>
    <?php
};
?>

<?php if ($ok): ?>
<?php // ZNALEZIONE — ekran nagrody. ?>
<section class="ts ts--win">
    <p class="ts__eyebrow"><?= __('Skarb odkryty') ?></p>
    <p class="ts__points">+<?= number_format((int) $result['points'], 0, ',', ' ') ?></p>
    <p class="ts__unit"><?= __('punktów Ridemore') ?></p>

    <h1 class="ts__name"><?= htmlspecialchars($treasure['name']) ?></h1>
    <p class="ts__meta">
        <?= htmlspecialchars((string) ($treasure['category_label'] ?? __('Skarb'))) ?>
        <?php if (!empty($treasure['region_label'])): ?> · <?= renderRegionLinks($treasure['region_label']) ?><?php endif; ?>
    </p>

    <?php if (!empty($treasure['description'])): ?>
    <p class="ts__desc"><?= nl2br(htmlspecialchars($treasure['description'])) ?></p>
    <?php
        $tnMeta = $treasure['translation'] ?? null;
        $tnEditUrl = (Core\Auth::user()?->isAdmin ?? false) ? Utils\View::url('/tlumaczenie/treasure/' . (int) $treasure['id']) : null;
        require __DIR__ . '/../partials/translated-note.php';
    ?>
    <?php endif; ?>

    <?php // KOLEKCJA — to jest to, co zamienia pojedyncze znalezienie w grę.
          // Pokazujemy dopiero PO zdobyciu, bo przed zdobyciem byłaby to
          // informacja o katalogu, a nie o człowieku. ?>
    <?php if ($collection['total'] > 1 && $collection['label']): ?>
    <?php // Ikona z Utils\Icon, nie emoji — patrz migracja 057. ?>
    <p class="ts__coll"><?= Utils\Icon::render('tre-curiosity') ?> <b><?= (int) $collection['found'] ?> / <?= (int) $collection['total'] ?></b>
        <?= __('skarbów w regionie {region}', ['region' => htmlspecialchars($collection['label'])]) ?></p>
    <?php endif; ?>

    <?php if ($finders > 1): ?>
    <p class="ts__social"><?= __('Znaleziony przez') ?> <b><?= (int) $finders ?></b> <?= __('riderów') ?></p>
    <?php endif; ?>

    <?php $galeriaSkarbu($treasure); ?>

    <div class="ts__act">
        <a class="btn" href="<?= View::url('/odkrycia') ?>"><?= __('Moja mapa') ?></a>
        <a class="btn btn-secondary" href="<?= View::url('/wydarzenia') ?>"><?= __('Znajdź wyjazd') ?></a>
    </div>
</section>

<?php elseif ($treasure === null): ?>
<section class="ts">
    <h1 class="ts__name"><?= __('Nie znamy tego kodu') ?></h1>
    <p class="ts__desc"><?= __('Naklejka mogła zostać wymieniona albo skarb wycofany.') ?></p>
    <div class="ts__act"><a class="btn" href="<?= View::url('/odkrycia') ?>"><?= __('Zobacz mapę skarbów') ?></a></div>
</section>

<?php else: ?>
<?php // PRZED ODEBRANIEM albo po nieudanej próbie. ?>
<section class="ts">
    <?php if ($result !== null && isset($powody[$result['reason']])): ?>
    <div class="ts__note">
        <b><?= htmlspecialchars($powody[$result['reason']][0]) ?></b>
        <span><?= htmlspecialchars($powody[$result['reason']][1]) ?></span>
    </div>
    <?php endif; ?>

    <p class="ts__eyebrow"><?= __('Skarb Ridemore') ?></p>
    <h1 class="ts__name"><?= htmlspecialchars($treasure['name']) ?></h1>
    <p class="ts__meta">
        <?= htmlspecialchars((string) ($treasure['category_label'] ?? __('Skarb'))) ?>
        <?php if (!empty($treasure['region_label'])): ?> · <?= renderRegionLinks($treasure['region_label']) ?><?php endif; ?>
        · <b><?= (int) $treasure['points'] ?> <?= __('pkt') ?></b>
    </p>

    <?php if ($isLoggedIn): ?>
    <form method="post" action="<?= View::url('/skarb/' . $treasure['code']) ?>" id="tsForm">
        <?= Core\Csrf::field() ?>
        <input type="hidden" name="lat" id="tsLat">
        <input type="hidden" name="lon" id="tsLon">
        <button class="btn ts__grab" type="submit" id="tsGrab"><?= __('Odbierz skarb') ?></button>
        <p class="ts__hint" id="tsHint"><?= __('Sprawdzimy, czy jesteś na miejscu.') ?></p>
    </form>
    <?php else: ?>
    <div class="ts__act">
        <a class="btn" href="<?= View::url('/logowanie') ?>?powrot=<?= urlencode('/skarb/' . $treasure['code']) ?>"><?= __('Zaloguj się i odbierz') ?></a>
        <a class="btn btn-secondary" href="<?= View::url('/rejestracja') ?>"><?= __('Załóż konto') ?></a>
    </div>
    <p class="ts__hint"><?= __('Kod pozostaje ważny — wróć tu po zalogowaniu.') ?></p>
    <?php endif; ?>

    <?php $galeriaSkarbu($treasure); ?>
</section>

<script>
(function () {
    var form = document.getElementById('tsForm');
    if (!form) { return; }
    var przycisk = document.getElementById('tsGrab');
    var hint = document.getElementById('tsHint');
    var pobrane = false;

    // POZYCJA POBIERANA DOPIERO PRZY KLIKNIĘCIU, nie przy wejściu na stronę.
    // Pytanie o lokalizację zanim człowiek cokolwiek zrobił wygląda jak
    // nagabywanie; po kliknięciu „Odbierz" jest oczywiste, po co.
    form.addEventListener('submit', function (e) {
        if (pobrane) { return; }
        e.preventDefault();
        przycisk.disabled = true;
        hint.textContent = __('Ustalam pozycję…');

        var wyslij = function () { pobrane = true; form.requestSubmit(); };
        // Krótszy limit niż domyślne 10 s: tu stoi człowiek przy naklejce,
        // z kodem w ręku, a lokalizacja jest tylko dodatkiem — czekanie na
        // GPS dłużej niż chwilę opóźnia coś, co i tak zaliczy się bez niej.
        RM.native.position({ timeout: 8000 }).then(function (poz) {
            document.getElementById('tsLat').value = poz.lat;
            document.getElementById('tsLon').value = poz.lon;
            wyslij();
        }).catch(function () {
            // Odmowa albo brak sygnału NIE blokuje — serwer i tak przyjmie
            // zgłoszenie bez współrzędnych (patrz Models\Treasure::claim).
            hint.textContent = __('Bez lokalizacji — wysyłam samo zgłoszenie.');
            wyslij();
        });
    });
})();
</script>
<?php endif; ?>

<?php // Wspólny lightbox — kafelki galerii są oznaczone `.ph-link`. ?>
<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
