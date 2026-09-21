<?php
// views/web/pages/tiles-admin.php
// KAFLE MAP — stan cache'u i reset (panel admina).
//
// Ekran celowo NIE straszy. Kasowanie kafli wygląda groźnie („skasuj wszystko"),
// a jest najbezpieczniejszą operacją w tym panelu: kafel to obrazek wyliczony
// z geometrii, która zostaje w bazie. Dlatego zamiast czerwonych ostrzeżeń jest
// jedno zdanie mówiące, co się naprawdę stanie — wolniejsze pierwsze wejścia.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';

// Domyślne wartości, bo widok bywa renderowany także z gałęzi bez tych danych.
$brakGeometrii = (int) ($brakGeometrii ?? 0);
$wszystkieSlady = (int) ($wszystkieSlady ?? 0);
$brakKolorow = (int) ($brakKolorow ?? 0);
$brakGeometriiSolo = (int) ($brakGeometriiSolo ?? 0);
$wynik = $wynik ?? null;
$wynikNaprawy = $wynikNaprawy ?? null;

$num = static fn($n) => number_format((int) $n, 0, ',', ' ');
$mb  = static fn($b) => number_format($b / 1048576, 1, ',', ' ') . ' MB';
$zajete = $stats['limit'] > 0 ? round($stats['tiles'] / $stats['limit'] * 100) : 0;
?>

<div class="op-head">
    <div class="op-head__c">
        <h1>Kafle map</h1>
        <p class="op-head__sub">Mapy rysują się z gotowych obrazków liczonych na żądanie.
            Kasowanie ich niczego nie niszczy — geometria śladów zostaje w bazie,
            a kafle odbudują się same przy pierwszym wejściu na mapę.</p>
    </div>
</div>

<?php if ($info): ?>
<div class="box" style="margin-top:14px;border-color:var(--accent);"><b><?= htmlspecialchars($info) ?></b></div>
<?php endif; ?>

<div class="trust-bar" style="margin-top:18px;">
    <div class="trust-item">
        <div class="trust-item-label">Kafli na dysku</div>
        <div class="trust-item-value"><?= $num($stats['tiles']) ?><small>z <?= $num($stats['limit']) ?> limitu</small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Zajęte miejsce</div>
        <div class="trust-item-value"><?= $mb($stats['bytes']) ?></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Zestawów</div>
        <div class="trust-item-value"><?= $num($stats['keys']) ?><small>map z własnym cache'em</small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Wypełnienie</div>
        <div class="trust-item-value"><?= $zajete ?>%</div>
    </div>
    <?php // Ten kafel to STAN GOTOWOŚCI, nie statystyka: ślad bez policzonej
          // geometrii nie narysuje się na żadnym kaflu, dopóki ktoś nie wejdzie
          // na mapę i nie zapłaci za parsowanie w trakcie żądania o obrazek. ?>
    <div class="trust-item">
        <div class="trust-item-label">Śladów bez geometrii</div>
        <div class="trust-item-value"><?= $num($brakGeometrii) ?><small>z <?= $num($wszystkieSlady) ?> w bazie</small></div>
    </div>
    <?php // Osobny licznik od tego wyżej (migr. 076, 2026-08-28) — solo może
          // mieć PEŁNĄ geometrię policzoną (np. wszedł gdzie indziej) i wciąż
          // czekać na PRZYCIĘTĄ wersję pod heatmapę społeczności; jeden
          // licznik nie objąłby drugiego stanu. ?>
    <div class="trust-item">
        <div class="trust-item-label">Solo bez przyciętej geometrii</div>
        <div class="trust-item-value"><?= $num($brakGeometriiSolo) ?><small>pod heatmapę „Ślady"</small></div>
    </div>
</div>

<section class="sec">
    <div class="sec-head"><h2>Reset</h2></div>
    <div class="box">
        <p class="desc" style="margin-top:0;">Po skasowaniu pierwsze żądania będą wolniejsze
            (kafel liczy się ok. 30 ms, z cache'u ok. 1,5 ms). Nic nie trzeba wygrzewać —
            cache odbuduje się z ruchu.</p>

        <div class="op-head__act" style="margin-top:16px;flex-wrap:wrap;">
            <?php // TRZY PRZYCISKI, NIE JEDEN Z LISTĄ. Najczęstszy przypadek to
                  // „zmieniłem wygląd jednej warstwy" — wtedy kasowanie drugiej
                  // jest niepotrzebnym kosztem odbudowy. ?>
            <form method="post" action="<?= View::url('/admin/kafle/purge') ?>">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="warstwa" value="">
                <button type="submit" class="btn">Skasuj wszystkie kafle</button>
            </form>
            <form method="post" action="<?= View::url('/admin/kafle/purge') ?>">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="warstwa" value="<?= htmlspecialchars(Models\TileSource::LAYER_TRACKS) ?>">
                <button type="submit" class="btn btn-secondary">Tylko ślady</button>
            </form>
            <form method="post" action="<?= View::url('/admin/kafle/purge') ?>">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="warstwa" value="<?= htmlspecialchars(Models\TileSource::LAYER_HEX) ?>">
                <button type="submit" class="btn btn-secondary">Tylko odkrycia</button>
            </form>
            <form method="post" action="<?= View::url('/admin/kafle/prune') ?>">
                <?= Core\Csrf::field() ?>
                <button type="submit" class="btn btn-secondary">Przytnij do limitu</button>
            </form>
        </div>
    </div>
</section>

<section class="sec">
    <div class="sec-head"><h2>Geometria śladów</h2></div>
    <div class="box">
        <p class="desc" style="margin-top:0;">Kafel rysuje się z geometrii policzonej z pliku
            GPX. Bez niej ślad nie pojawi się na mapie, dopóki ktoś w nią nie wejdzie —
            i wtedy to jego żądanie o obrazek płaci za sparsowanie wszystkich brakujących
            plików. Ten przycisk robi to z góry, dla wszystkiego, co jest w bazie
            <b>na tę chwilę</b>: śladów z wyjazdów, tras zapowiadanych, wariantów,
            znanych tras i przejazdów. Liczy też PRZYCIĘTĄ geometrię solo pod
            heatmapę społeczności (migr. 076) — osobny krok w tym samym przebiegu.</p>

        <?php if ($brakGeometrii > 0): ?>
        <p class="form-error"><?= $num($brakGeometrii) ?>
            <?= $brakGeometrii === 1 ? 'ślad czeka' : 'śladów czeka' ?> na policzenie.</p>
        <?php else: ?>
        <p class="desc" style="margin:0;"><b>Wszystko policzone.</b> Ponowne uruchomienie
            niczego nie zepsuje — pliki, które już mają geometrię, są pomijane.</p>
        <?php endif; ?>

        <?php if ($brakGeometriiSolo > 0): ?>
        <p class="form-error"><?= $num($brakGeometriiSolo) ?>
            solo czeka na przycięcie pod heatmapę.</p>
        <?php endif; ?>

        <?php // Wynik ostatniego przebiegu, bo bez niego przycisk milczy i nie
              // wiadomo, czy zrobił cokolwiek. ?>
        <?php if ($wynik !== null): ?>
        <p class="desc">Ostatni przebieg: policzone <b><?= $num($wynik['policzone']) ?></b>,
            już były <b><?= $num($wynik['juz_byly']) ?></b><?php
            if ($wynik['brak_pliku'] > 0): ?>, <span style="color:var(--danger-dark);">brak pliku na
            dysku: <b><?= $num($wynik['brak_pliku']) ?></b></span><?php endif; ?>.</p>
        <?php endif; ?>

        <div class="op-head__act" style="margin-top:16px;">
            <form method="post" action="<?= View::url('/admin/kafle/geometria') ?>">
                <?= Core\Csrf::field() ?>
                <button type="submit" class="btn">Policz geometrię wszystkich śladów</button>
            </form>
        </div>

        <p class="desc" style="margin-top:14px;">To samo robi <code>php tiles.php backfill</code>
            z konsoli — ta sama funkcja, dwa wejścia.</p>
    </div>
</section>

<?php // KOLORY ŚLADÓW (migr. 073) — WŁASNA sekcja, nie przycisk doklejony do
      // geometrii. To dwie różne operacje: tamta LICZY pliki (parsowanie GPX,
      // sekundy na plik), ta MALUJE to, co już policzone (dwa zapytania na
      // ślad). Po wgraniu partii tras uruchamia się je jedna po drugiej, ale
      // pomylenie ich kosztowałoby czas przy pierwszym problemie. ?>
<?php // NAPRAWA INDEKSU KAFLI (2026-09-07) — osobna sekcja od geometrii wyżej,
      // bo to inny problem: tamta liczy geometrię PLIKOM, które jej nie mają;
      // ta naprawia ślady, które geometrię MAJĄ, ale ich indeks kafli jest
      // niekompletny (stare szkody po wyścigu zapisu, patrz GpxGeometry::
      // repairTileIndex). NIE czyta żadnego pliku GPX — działa też dla
      // przejazdów, których właściciela nie da się poprosić o ponowne
      // wgranie. ?>
<section class="sec">
    <div class="sec-head"><h2>Naprawa indeksu kafli</h2></div>
    <div class="box">
        <p class="desc" style="margin-top:0;">Rzadki, ale realny przypadek: ślad ma poprawnie
            policzoną geometrię, ale jej indeks kafli (<code>gpx_tiles</code>) jest
            <b>niekompletny</b> — objaw to trasa, która „urywa się" na części kafli, zależnie
            od zoomu, mimo że powinna być ciągła. Ten przycisk dopisuje wyłącznie brakujące
            wiersze, licząc je z geometrii już zapisanej w bazie — <b>bez czytania pliku GPX</b>,
            więc naprawia też przejazdy solo, których właściciela nie da się poprosić
            o ponowne wgranie. Bezpieczne do wielokrotnego uruchomienia: ślad z kompletnym
            indeksem kosztuje jedno zapytanie i nic nie zmienia.</p>

        <?php if ($wynikNaprawy !== null): ?>
        <p class="desc">Ostatni przebieg — pełna geometria: sprawdzone
            <b><?= $num($wynikNaprawy['sprawdzone']) ?></b>, naprawione
            <b><?= $num($wynikNaprawy['naprawione']) ?></b> (dopisanych wierszy
            <b><?= $num($wynikNaprawy['dopisane']) ?></b>); solo przycięte (heatmapa): sprawdzone
            <b><?= $num($wynikNaprawy['sprawdzoneTrim']) ?></b>, naprawione
            <b><?= $num($wynikNaprawy['naprawioneTrim']) ?></b> (dopisanych wierszy
            <b><?= $num($wynikNaprawy['dopisaneTrim']) ?></b>).</p>
        <?php if ($wynikNaprawy['naprawione'] > 0 || $wynikNaprawy['naprawioneTrim'] > 0): ?>
        <p class="desc" style="margin:0;">Kafle warstwy „Ślady" zostały skasowane, żeby
            naprawiony przebieg był widoczny — odbudują się z ruchu.</p>
        <?php endif; ?>
        <?php endif; ?>

        <div class="op-head__act" style="margin-top:16px;">
            <form method="post" action="<?= View::url('/admin/kafle/napraw-indeks') ?>">
                <?= Core\Csrf::field() ?>
                <button type="submit" class="btn">Sprawdź i napraw indeks kafli</button>
            </form>
        </div>

        <p class="desc" style="margin-top:14px;">To samo robi <code>php tiles.php repair</code>
            z konsoli.</p>
    </div>
</section>

<section class="sec">
    <div class="sec-head"><h2>Kolory śladów</h2></div>
    <div class="box">
        <p class="desc" style="margin-top:0;">Ślady leżące blisko siebie dostają
            <b>różne kolory</b>, żeby dało się je rozróżnić tam, gdzie się nakładają —
            inaczej kilkadziesiąt przejazdów wokół jednego miasta rysuje się jako jedna
            plama. Kolor wybiera się raz, przy pierwszym policzeniu geometrii; ten przycisk
            uzupełnia go dla wszystkiego, co powstało wcześniej.</p>

        <?php if ($brakKolorow > 0): ?>
        <p class="form-error"><?= $num($brakKolorow) ?>
            <?= $brakKolorow === 1 ? 'ślad nie ma' : 'śladów nie ma' ?> jeszcze koloru —
            rysują się kolorem domyślnym, czyli wpadają z powrotem do wspólnej plamy.</p>
        <?php else: ?>
        <p class="desc" style="margin:0;"><b>Wszystko pokolorowane.</b> Ponowne uruchomienie
            niczego nie przemaluje — ślady, które kolor już mają, są pomijane (a dzięki temu
            nie unieważnia kafli, których nikt nie kazał unieważniać).</p>
        <?php endif; ?>

        <div class="op-head__act" style="margin-top:16px;">
            <form method="post" action="<?= View::url('/admin/kafle/kolory') ?>">
                <?= Core\Csrf::field() ?>
                <button type="submit" class="btn">Przydziel kolory śladom</button>
            </form>
        </div>

        <?php // PALETA NA WIDOKU, nie w komentarzu w kodzie: to jest jedyne
              // miejsce w panelu, gdzie widać, czym serwis w ogóle maluje. ?>
        <p class="desc" style="margin-top:16px;margin-bottom:6px;">Paleta
            (<code>Utils\TrackPalette</code>):</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php foreach (Utils\TrackPalette::COLORS as $i => $hex): ?>
            <span style="display:inline-flex;align-items:center;gap:6px;font-family:var(--f-m);font-size:12px;">
                <i style="width:22px;height:4px;border-radius:2px;background:<?= htmlspecialchars($hex) ?>;
                          display:inline-block;"></i><?= htmlspecialchars($hex) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="desc" style="margin-top:10px;">Niebieski
            <i style="width:22px;height:4px;border-radius:2px;display:inline-block;vertical-align:middle;
                      background:<?= htmlspecialchars(Utils\TrackPalette::SELECTED) ?>;"></i>
            <code><?= htmlspecialchars(Utils\TrackPalette::SELECTED) ?></code> jest
            <b>zarezerwowany dla zaznaczonego śladu</b> i celowo nie ma go w palecie — inaczej
            zwykły przejazd wyglądałby jak kliknięty.</p>

        <p class="desc" style="margin-top:14px;">To samo robi <code>php tiles.php colors</code>
            z konsoli. Po przydzieleniu kafle warstwy „Ślady" kasują się same — bez tego
            zmiana siedziałaby w bazie, a mapa dalej pokazywałaby stare kolory.</p>
    </div>
</section>

<section class="sec">
    <div class="box">
        <p class="eyebrow" style="margin-top:0;">Czego ten ekran NIE robi</p>
        <p class="desc" style="margin:0;">Nie generuje samych kafli z góry i nie ma po co:
            pełna piramida z4–z14 dla Polski to ok. 269 000 plików, a realnie ogląda się
            z niej ułamek. Kafel powstaje przy pierwszym żądaniu w kilkadziesiąt
            milisekund — pod warunkiem, że geometria jest już policzona (sekcja wyżej).</p>
    </div>
</section>
