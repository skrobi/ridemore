<?php
// views/web/pages/emblems-admin.php
// PANEL EMBLEMATÓW — /admin/emblematy (migr. 087, 2026-09-11).
//
// Lista i formularz na JEDNYM ekranie (inaczej niż znane trasy, gdzie
// formularze mają własne podstrony): emblematów będą dziesiątki, nie setki,
// a rozbicie na podstrony kosztowałoby kliknięcie bez żadnego zysku.
//
// Edycja dzieje się W WIERSZU — `<details>` zamiast własnego JS-a, tak samo jak
// akcje moderacyjne na liście użytkowników (`.adm-akcje`). Otwieranie,
// klawiatura i stan `open` są wtedy za darmo.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';

$emblems = $emblems ?? [];

// Komunikat „nadano: N" niesie liczbę w samym kodzie, żeby nie trzymać drugiego
// parametru w adresie tylko na tę jedną informację.
$komunikat = (string) ($komunikat ?? '');
$info = match (true) {
    $komunikat === 'dodane'     => 'Emblemat dodany.',
    $komunikat === 'zapisane'   => 'Zmiany zapisane.',
    $komunikat === 'skasowane'  => 'Emblemat skasowany razem ze zdobytymi egzemplarzami.',
    str_starts_with($komunikat, 'nadano:') => 'Przeliczono: nadano '
        . (int) substr($komunikat, 7) . ' '
        . Format::plural((int) substr($komunikat, 7), 'emblemat', 'emblematy', 'emblematów') . '.',
    default => null,
};
$blad = match ($blad ?? null) {
    'brak_nazwy'  => 'Emblemat musi mieć nazwę.',
    'zle_zdjecie' => 'Nie udało się wczytać grafiki — sprawdź format i rozmiar pliku.',
    'sesja'       => 'Sesja wygasła — odśwież stronę i spróbuj jeszcze raz.',
    default       => null,
};
?>
<h1 class="display">Emblematy</h1>
<p class="desc spaced-below">
    Wirtualna odznaka za <b>przejechanie całej trasy</b> — 100% jej pól siatki odkryć.
    Przypina się ją do znanej trasy (formularz trasy) albo do wydarzenia
    (<a href="<?= View::url('/admin/punkty') ?>">Punkty i trasy</a>). Zdobyte emblematy
    pokazują się na profilu rowerzysty i <b>nie da się ich stracić</b>: raz nadany zostaje,
    nawet jeśli przebieg trasy później się zmieni.
</p>

<?php if ($info): ?>
<p class="form-success"><?= htmlspecialchars($info) ?></p>
<?php endif; ?>
<?php if ($blad): ?>
<p class="form-error"><?= htmlspecialchars($blad) ?></p>
<?php endif; ?>

<section class="sec">
    <div class="sec-head">
        <h2>Nowy emblemat</h2>
        <span>grafika opcjonalna — bez niej widok rysuje pusty heks</span>
    </div>
    <div class="box">
        <form method="post" action="<?= View::url('/admin/emblematy/zapisz') ?>" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <div class="form-row">
                <label for="em_name">Nazwa</label>
                <input class="search-input" type="text" id="em_name" name="name" maxlength="120" required
                       placeholder="np. Korona Bieszczadów">
            </div>
            <div class="form-row">
                <label for="em_desc">Opis (opcjonalnie)</label>
                <input class="search-input" type="text" id="em_desc" name="description" maxlength="400"
                       placeholder="Za przejechanie całej trasy głównym grzbietem">
            </div>
            <div class="form-row">
                <label for="em_img">Grafika (opcjonalnie)</label>
                <input type="file" id="em_img" name="image" accept="image/*">
                <p class="hint">Kwadratowa wychodzi najlepiej — na profilu jest przycinana do heksa.</p>
            </div>
            <div class="form-row">
                <label class="filter-opt" for="em_active">
                    <input type="checkbox" id="em_active" name="is_active" value="1" checked>
                    Aktywny (nieaktywny przestaje być nadawany, ale zdobyte zostają)
                </label>
            </div>
            <button class="btn" type="submit">Dodaj emblemat</button>
        </form>
    </div>
</section>

<section class="sec">
    <div class="sec-head">
        <h2>Wszystkie emblematy</h2>
        <?php // PRZELICZENIE RĘCZNE — pierwsze przypięcie emblematu do trasy
              // dotyczy z reguły ludzi, którzy przejechali ją dawno temu.
              // Ten sam kod chodzi w cronie, więc przycisk niczego nie dubluje,
              // tylko nie każe czekać do nocy. ?>
        <form method="post" action="<?= View::url('/admin/emblematy/przelicz') ?>">
            <?= Csrf::field() ?>
            <button class="btn btn--sm btn-secondary" type="submit">Przelicz zdobycia</button>
        </form>
    </div>

    <?php if (!$emblems): ?>
    <p class="desc">Nie ma jeszcze żadnego emblematu.</p>
    <?php else: ?>
    <div class="dash-table">
        <div class="dash-row dash-head">
            <div class="dash-cell">Emblemat</div>
            <div class="dash-cell">Przypięty do</div>
            <div class="dash-cell">Zdobyty przez</div>
            <div class="dash-cell">Akcje</div>
        </div>
        <?php foreach ($emblems as $em): ?>
        <div class="dash-row">
            <div class="dash-cell dash-cell-title" data-label="Emblemat">
                <span class="emblem-row">
                    <span class="emblem-hex emblem-hex--sm<?= empty($em['image_url']) ? ' emblem-hex--empty' : '' ?>"
                          <?= !empty($em['image_url'])
                            ? 'style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($em['image_url'], 'thumb')) . '\')"'
                            : '' ?>>
                        <?= empty($em['image_url']) ? Utils\Icon::render('hex') : '' ?>
                    </span>
                    <span>
                        <b><?= htmlspecialchars($em['name']) ?></b>
                        <?php if (!$em['is_active']): ?><span class="tag tag--plain">nieaktywny</span><?php endif; ?>
                        <?php if (!empty($em['description'])): ?>
                        <div class="dash-sub"><?= htmlspecialchars($em['description']) ?></div>
                        <?php endif; ?>
                    </span>
                </span>
            </div>
            <div class="dash-cell" data-label="Przypięty do">
                <?php $ileTras = (int) $em['routes']; $ileWyd = (int) $em['events']; ?>
                <?php if ($ileTras === 0 && $ileWyd === 0): ?>
                <span class="dash-sub">do niczego — nikt go nie zdobędzie</span>
                <?php else: ?>
                <?= $ileTras ?> <?= Format::plural($ileTras, 'trasa', 'trasy', 'tras') ?>
                · <?= $ileWyd ?> <?= Format::plural($ileWyd, 'wydarzenie', 'wydarzenia', 'wydarzeń') ?>
                <?php endif; ?>
            </div>
            <div class="dash-cell" data-label="Zdobyty przez">
                <?= (int) $em['awarded'] ?> <?= Format::plural((int) $em['awarded'], 'osobę', 'osoby', 'osób') ?>
            </div>
            <div class="dash-cell" data-label="Akcje">
                <details class="adm-akcje">
                    <summary class="btn btn--sm btn-secondary">Edytuj</summary>
                    <div class="box" style="margin-top:8px;display:flex;flex-direction:column;gap:10px;min-width:320px;">
                        <button type="button" class="adm-akcje__x"
                                onclick="this.closest('details').open = false;"
                                aria-label="Zamknij"><?= Utils\Icon::render('close') ?></button>
                        <form method="post" action="<?= View::url('/admin/emblematy/zapisz') ?>" enctype="multipart/form-data">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $em['id'] ?>">
                            <div class="form-row">
                                <label for="em_name_<?= (int) $em['id'] ?>">Nazwa</label>
                                <input class="search-input" type="text" id="em_name_<?= (int) $em['id'] ?>"
                                       name="name" maxlength="120" required value="<?= htmlspecialchars($em['name']) ?>">
                            </div>
                            <div class="form-row">
                                <label for="em_desc_<?= (int) $em['id'] ?>">Opis</label>
                                <input class="search-input" type="text" id="em_desc_<?= (int) $em['id'] ?>"
                                       name="description" maxlength="400" value="<?= htmlspecialchars((string) $em['description']) ?>">
                            </div>
                            <div class="form-row">
                                <label for="em_img_<?= (int) $em['id'] ?>">Grafika<?= !empty($em['image_url']) ? ' (zostaw puste, żeby nie zmieniać)' : '' ?></label>
                                <input type="file" id="em_img_<?= (int) $em['id'] ?>" name="image" accept="image/*">
                            </div>
                            <div class="form-row">
                                <label class="filter-opt" for="em_active_<?= (int) $em['id'] ?>">
                                    <input type="checkbox" id="em_active_<?= (int) $em['id'] ?>" name="is_active" value="1"
                                           <?= $em['is_active'] ? 'checked' : '' ?>>
                                    Aktywny
                                </label>
                            </div>
                            <button class="btn btn--sm" type="submit">Zapisz</button>
                        </form>
                        <?php // KASOWANIE ZABIERA TEŻ ZDOBYTE EGZEMPLARZE (kaskada,
                              // migr. 087) — dlatego ostrzeżenie stoi przy przycisku,
                              // a wycofanie z obiegu robi się odznaczeniem „Aktywny". ?>
                        <form method="post" action="<?= View::url('/admin/emblematy/' . (int) $em['id'] . '/usun') ?>"
                              onsubmit="return confirm('Skasować emblemat? Zniknie też <?= (int) $em['awarded'] ?> zdobytych egzemplarzy — tego nie da się cofnąć.');">
                            <?= Csrf::field() ?>
                            <button class="btn btn--sm btn-danger" type="submit">Skasuj</button>
                        </form>
                    </div>
                </details>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
