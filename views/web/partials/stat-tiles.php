<?php
// views/web/partials/stat-tiles.php
// KAFLE STATYSTYK — JEDEN komponent liczb dla całego serwisu (2026-08-20).
//
// Powstał na zgłoszenie usera: „na każdej stronie wygląda inaczej, a można
// z tego zrobić jeden partial". Przed tą zmianą te same trzy rzeczy — podpis,
// liczba, dopisek — miały w kodzie trzy różne postacie: `.disc-stats` na
// /odkrycia i na stronie trasy (pełna szerokość, pionowe kreski), `.rp-stats`
// na profilu rowerzysty (siatka 2×N z włosowymi przerwami) i ręcznie składane
// wiersze `.chr-route__disc` w kronice. Kopie zdążyły się rozjechać nie tylko
// wyglądem: część miała podpisy pod liczbą, część nie, a kronika nie miała
// nawet podpisów NAD liczbą.
//
// REFERENCJĄ JEST PROFIL ROWERZYSTY (wskazany przez usera): karta z obwódką,
// włosowe przerwy między kaflami, podpis wersalikami nad liczbą. Różnica
// między użyciami jest już tylko GĘSTOŚCIĄ — `compact` dla kolumny bocznej,
// gdzie kafel ma połowę szerokości ekranu, a nie piątą część.
//
// CO NALEŻY DO STRONY, A CO DO KOMPONENTU: strona decyduje, KTÓRE liczby
// pokazać i jak je nazwać (to jest treść i zależy od tego, o czym jest ekran);
// komponent decyduje, jak wyglądają. Dlatego nie ma tu ani jednej nazwy
// konkretnej statystyki.
//
// UŻYCIE:
//   require_once __DIR__ . '/../partials/stat-tiles.php';
//   renderStatTiles([
//       ['lbl' => 'Odkryte pola', 'val' => 1234, 'hex' => true],
//       ['lbl' => 'Znane trasy',  'val' => 3, 'small' => 'z 12', 'sub' => 'ukończonych'],
//       $czyPokazac ? ['lbl' => '...', 'val' => '...'] : null,   // null = kafla nie ma
//   ], ['cols' => 5]);
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('renderStatTiles')) {
    /**
     * @param array<int,array{lbl:string,val:string|int,small?:string,sub?:string,
     *                        hex?:bool,subHex?:bool,done?:bool,lead?:bool,
     *                        graphic?:string}|null> $tiles
     *        `graphic` — surowe SVG/HTML (2026-09-11), np. odznaka progów na
     *        trail.php. Staje OBOK liczby (wspólny `.stat-tile__vrow`) —
     *        strona buduje znacznik, komponent tylko daje mu `.stat-tile__badge`.
     *        `lead` — kafel wiodacy: bierze cala szerokosc rzedu i wieksza
     *        liczbe. Do JEDNEJ liczby na pasek, tej, po ktora sie tu przyszlo;
     *        drugi kafel z `lead` znosi sens pierwszego. Dodane 2026-08-22 na
     *        profilu rowerzysty, gdzie szesc identycznych kafli nie
     *        odpowiadalo na pytanie „ile tego jest".
     *        Pozycje `null`/puste są POMIJANE — dzięki temu warunek „pokaż ten
     *        kafel tylko, gdy jest o czym mówić" pisze się w miejscu, w którym
     *        i tak stoją dane, zamiast rozbijać wywołanie na kilka gałęzi.
     * @param array{cols?:int, compact?:bool, class?:string} $opts
     *        `cols`  — ile kafli w rzędzie na szerokim ekranie (domyślnie tyle,
     *                  ile kafli; węższe ekrany i tak zwija do 2 i do 1),
     *        `compact` — wariant do kolumny bocznej (mniejsza liczba, ciaśniej),
     *        `class` — dodatkowa klasa (np. margines w konkretnym miejscu).
     */
    function renderStatTiles(array $tiles, array $opts = []): void
    {
        $tiles = array_values(array_filter($tiles));
        if (!$tiles) {
            return;
        }

        // Domyślna liczba kolumn: tyle, ile kafli — a w wariancie bocznym dwie,
        // bo tam nie ma miejsca na więcej. Wartość idzie stylem inline, więc
        // MUSI być policzona dobrze: inline wygrywa z arkuszem, czyli
        // `--st-cols` z `.stat-tiles--compact` nigdy by go nie poprawiło.
        $cols = max(1, (int) ($opts['cols'] ?? (!empty($opts['compact']) ? 2 : count($tiles))));
        $class = 'stat-tiles'
            . (!empty($opts['compact']) ? ' stat-tiles--compact' : '')
            . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        ?>
        <div class="<?= htmlspecialchars($class) ?>" style="--st-cols:<?= $cols ?>">
            <?php foreach ($tiles as $tile): ?>
            <div class="stat-tile<?= !empty($tile['lead']) ? ' stat-tile--lead' : '' ?>">
                <span class="stat-tile__lbl"><?= htmlspecialchars((string) ($tile['lbl'] ?? '')) ?></span>
                <?php // ODZNAKA (`graphic`) STOI OBOK LICZBY, nie pod podpisem
                      // (2026-09-11, druga iteracja — pod podpisem wychodziła
                      // mała i blada). Wiersz powstaje WYŁĄCZNIE dla kafla
                      // z odznaką; pozostałe kafle mają markup jak dotąd. ?>
                <?php if (!empty($tile['graphic'])): ?><span class="stat-tile__vrow"><?php endif; ?>
                <span class="stat-tile__val<?= !empty($tile['done']) ? ' is-done' : '' ?>">
                    <?php // IKONA POLA SIEDZI W `.hexn` RAZEM Z LICZBĄ, nie obok:
                          // ta klasa trzyma je w jednej linii i skaluje ikonę do
                          // wysokości cyfry. Rozdzielone potrafiły się złamać
                          // w wąskim kaflu — ikona zostawała w rzędzie wyżej. ?>
                    <?php if (!empty($tile['hex'])): ?>
                    <span class="hexn"><?= Utils\Icon::render('hex') ?><?= htmlspecialchars((string) $tile['val']) ?></span>
                    <?php else: ?>
                    <?= htmlspecialchars((string) $tile['val']) ?>
                    <?php endif; ?>
                    <?php if (!empty($tile['small'])): ?>
                    <small><?= htmlspecialchars((string) $tile['small']) ?></small>
                    <?php endif; ?>
                </span>
                <?php // Surowe SVG/HTML od STRONY — komponent tylko daje mu
                      // miejsce (ten sam odruch co `hex`/`subHex`: treść należy
                      // do wywołującego, komponent ją tylko oprawia). ?>
                <?php if (!empty($tile['graphic'])): ?>
                <span class="stat-tile__badge"><?= $tile['graphic'] ?></span>
                </span>
                <?php endif; ?>
                <?php if (!empty($tile['sub'])): ?>
                <span class="stat-tile__sub">
                    <?php if (!empty($tile['subHex'])): ?>
                    <span class="hexn"><?= Utils\Icon::render('hex') ?></span>
                    <?php endif; ?>
                    <?= htmlspecialchars((string) $tile['sub']) ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
