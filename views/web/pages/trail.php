<?php
// views/web/pages/trail.php
// JEDNA ZNANA TRASA — /trasy/{slug}.
//
// Kolejność sekcji celowa i inna niż na stronie wydarzenia:
//   1. co to za trasa (nazwa, region, zdjęcie, pobranie GPX-a)
//   2. PASEK STATYSTYK  ← długość, przewyższenie, postęp, punkty, skarby
//   3. przebieg na mapie + profil wysokości z podjazdami
//   4. kto już przejechał
//
// UKŁAD Z 2026-08-20 (zgłoszenie usera). Wcześniej stały tu dwie osobne karty:
// „Twój postęp" nad mapą (jeden pasek) i „Punkty za tę trasę" pod nią
// (drabinka progów) — a to, po co się na stronę szlaku wchodzi (ile ma
// kilometrów, jak wygląda profil, co po drodze leży do znalezienia), albo
// ginęło w podtytule, albo nie było go wcale. Teraz jeden pasek `.stat-tiles`
// nad mapą, dokładnie ten sam co na /odkrycia i na profilu, a profil pod mapą.
//
// Zbudowana z komponentów, które już były: .op-head, .stat-tiles (wspólne kafle
// statystyk, partial), .profbox/.day-profile-big/.climbs (ze strony wydarzenia),
// .box/.sec, .roster/.avs (skład), .disc-cta.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
require __DIR__ . '/../partials/rider-avatar.php';
require_once __DIR__ . '/../partials/stat-tiles.php';
require_once __DIR__ . '/../partials/threshold-badge.php';
require_once __DIR__ . '/../partials/trail-card.php';

if (!$route) {
    ?>
    <div class="op-head">
        <div class="op-head__c">
            <h1><?= __('Nie znaleziono trasy') ?></h1>
            <p class="op-head__sub"><?= __('Ta trasa nie istnieje albo została wycofana z katalogu.') ?></p>
            <div class="op-head__act">
                <a class="btn" href="<?= View::url('/trasy') ?>"><?= __('Zobacz wszystkie trasy') ?></a>
            </div>
        </div>
    </div>
    <?php
    return;
}

$progress = $progress ?? null;
$finishers = $finishers ?? ['people' => [], 'total' => 0];
$thresholds = $thresholds ?? [];
$pointsEarned = $pointsEarned ?? null;
$treasures = $treasures ?? ['total' => 0, 'points' => 0, 'pointsLeft' => 0, 'found' => null];
$treasureList = $treasureList ?? ['items' => [], 'hidden' => 0];
$elevation = $elevation ?? null;
$nearby = $nearby ?? [];
$isLoggedIn = $isLoggedIn ?? false;

// Kolor tej trasy (migr. 064) — jedna wartość na całą stronę: linia na mapie,
// wykres profilu i pasy podjazdów mają być tym samym szlakiem.
$trailColor = Models\KnownRoute::colorOf($route['color_index'] ?? null);

// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);
$shortName = static function (array $u): string {
    $raw = trim((string) ($u['name'] ?? '')) ?: (string) strstr((string) ($u['email'] ?? ''), '@', true);
    $parts = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) { return __('Rowerzysta'); }
    return count($parts) > 1
        ? $parts[0] . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.'
        : $parts[0];
};
$initials = static function (array $u): string {
    $raw = trim((string) ($u['name'] ?? '')) ?: (string) strstr((string) ($u['email'] ?? ''), '@', true);
    return mb_strtoupper(mb_substr($raw !== '' ? $raw : '?', 0, 2));
};

// METRYCZKA TRASY (dystans/przewyższenie/pola) — budowana RAZ, wypisana w
// jednym z dwóch miejsc niżej (na zdjęciu w .hero-bottom / w nagłówku, gdy
// trasa nie ma zdjęcia) zależnie od cover_photo_url, bez powielania PHP-a
// (ten sam odruch co przy <h1> i przycisku GPX na tej stronie). Dystans w
// <b> dostaje znacznik --blaze na zdjęciu (.hero-sub b::after) — ta sama
// robota co dla daty na stronie wydarzenia (prośba usera 2026-09-10).
ob_start();
?>
<?php if ((float) $route['distance_km'] > 0): ?>
<b><?= htmlspecialchars(Format::distance((float) $route['distance_km'])) ?></b> ·
<?php endif; ?>
<?php // Przewyższenie (migr. 063) — ta sama liczba, którą pokazuje dymek
      // trasy na mapie. NULL to „nie wiem" (trasa sprzed migracji), a nie
      // „płasko", więc wtedy nic nie piszemy. ?>
<?php if ($route['elevation_gain_m'] !== null): ?>
<?= __('{n} m przewyższenia', ['n' => (int) $route['elevation_gain_m']]) ?> ·
<?php endif; ?>
<?= __n((int) $route['cells_total'], '{n} pole do zaliczenia', '{n} pola do zaliczenia', '{n} pól do zaliczenia') ?>

<?php
// PUŁAPKA (dosłowny znacznik zamykający PHP w komentarzu jednoliniowym
// PRZEDWCZEŚNIE kończy blok, więc jest opisany słownie, nie wklejony): tag
// zamykający zjada JEDEN następujący po nim znak nowej linii. Linia wyżej
// musi kończyć się tekstem, nie samym tagiem zamykającym — inaczej „pól"
// i „do" zlewają się w „póldo" bez spacji (złapane żywym testem: w
// oryginalnym markupie ratowało to przypadkiem 12 spacji wcięcia PRZED
// „do zaliczenia", które zostawały jako tekst mimo zjedzonej nowej linii;
// tu, bez wcięcia, nie było niczego, co by to uratowało).
$metaLine = ob_get_clean();
?>

<div class="op-head">
    <div class="op-head__c">
        <div class="tags">
            <?php // ODZNAKA W KOLORZE (prośba usera 2026-09-10: „znana trasa"
                  // ginęła obok bezbarwnych, podrzędnych tagów wydarzenia — cena,
                  // region). Ten sam mechanizm, którym event koloruje swój
                  // PIERWSZOPLANOWY tag formatu (.tag+.blaze+--bz,
                  // event-page.php), kolorem --blaze-dark — „znak szlaku",
                  // nieużywany przez żaden format wydarzenia. ?>
            <span class="tag" style="--bz:var(--blaze-dark);"><span class="blaze"></span><?= __('Znana trasa') ?></span>
            <?php if (!empty($route['region_label'])): ?>
            <?php // Link do strony regionu (2026-09-14); przy kilku regionach — pierwszego. ?>
            <?php $regionPath = Models\Region::pathForCode($route['region_code'] ?? null); ?>
            <?php if ($regionPath): ?>
            <a class="tag tag--plain" href="<?= View::url($regionPath) ?>"><?= htmlspecialchars($route['region_label']) ?></a>
            <?php else: ?>
            <span class="tag tag--plain"><?= htmlspecialchars($route['region_label']) ?></span>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php // NAZWA + METRYCZKA TRASY — na zdjęciu w .op-cover niżej (prośba
              // usera 2026-09-10), nie tutaj: h1, potem .op-head__sub (ta sama
              // "robota" co na wydarzeniu — user o to dopytał, gdy zobaczył, że
              // zrobiłem to tylko na evencie). Bez zdjęcia nie ma gdzie ich
              // nałożyć, więc wtedy oba zostają tutaj, żeby strona nie została
              // bez nagłówka i bez faktów o trasie. ?>
        <?php if (empty($route['cover_photo_url'])): ?>
        <h1><?= htmlspecialchars($route['name']) ?></h1>
        <p class="op-head__sub"><?= $metaLine ?></p>
        <?php endif; ?>
        <div class="op-head__act">
            <?php if (!$isLoggedIn): ?>
            <a class="btn" href="<?= View::url('/rejestracja') ?>"><?= __('Załóż konto i śledź postęp') ?></a>
            <?php endif; ?>
            <?php // TRZY PRZYCISKI TRASY (GPX, nawigacja, wyjazdy w okolicy) STOJĄ
                  // RAZEM NA ZDJĘCIU niżej (2026-09-11, zgłoszenie usera: „nawiguj
                  // i wyjazdy w tych stronach powinny być zaraz obok Pobierz GPX").
                  // Do tej daty GPX był przeniesiony na okładkę (2026-09-10), a
                  // tamte dwa zostały tutaj — czyli jedna grupa akcji rozdzielona
                  // na dwa piętra strony.
                  //
                  // BEZ OKŁADKI nie ma na czym ich położyć, więc wtedy cała trójka
                  // zostaje tu — ta sama zasada, którą od 2026-09-10 rządzi się
                  // nazwa trasy i metryczka. Wspólny partial `trail-actions.php`,
                  // żeby dwa miejsca nie rozjechały się przy następnej zmianie. ?>
            <?php if (empty($route['cover_photo_url'])): ?>
            <?php require __DIR__ . '/../partials/trail-actions.php'; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php // ZDJĘCIE = .op-cover, TEN SAM KOMPONENT co profil organizatora
      // (organizer-profile.php) — zamiast dawnego prowizorycznego
      // .box+<img> bez zaokrągleń/gradientu (prośba usera 2026-09-10:
      // uspójnić wygląd ze stroną wydarzenia). Nazwa trasy i RZĄD AKCJI
      // (GPX, nawigacja, wyjazdy w okolicy) nakładają się na dół zdjęcia
      // (.hero-bottom/.hero-ttl/.hero-acts — patrz style.css), analogicznie
      // do paska organizatora w .cover na stronie wydarzenia. ?>
<?php if (!empty($route['cover_photo_url'])): ?>
<div class="op-cover" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($route['cover_photo_url'], 'wide')) ?>');" role="img" aria-label="<?= htmlspecialchars($route['name']) ?>">
    <div class="op-cover__g"></div>
    <?php // PIECZĄTKA EMBLEMATU (prośba usera 2026-09-13: „wbity na hero jak
          // pieczątka"). Skrót do sekcji #emblemat niżej, która dalej tłumaczy,
          // za co się go dostaje. ?>
    <?php if (!empty($emblem)): ?>
    <a class="emblem-stamp<?= $emblem['mine'] ? ' emblem-stamp--mine' : '' ?>" href="#emblemat"
       title="<?= htmlspecialchars('Emblemat: ' . $emblem['name']) ?>">
        <span class="emblem-stamp__ring">
            <span class="emblem-hex<?= empty($emblem['imageUrl']) ? ' emblem-hex--empty' : '' ?>"<?= !empty($emblem['imageUrl'])
                ? ' style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($emblem['imageUrl'], 'thumb')) . '\')"'
                : '' ?>>
                <?= empty($emblem['imageUrl']) ? Utils\Icon::render('hex') : '' ?>
            </span>
        </span>
        <span class="emblem-stamp__lbl"><?= $emblem['mine'] ? __('Zdobyty') : __('Emblemat') ?></span>
    </a>
    <?php endif; ?>
    <div class="hero-bottom">
        <h1 class="hero-ttl"><?= htmlspecialchars($route['name']) ?></h1>
        <p class="op-head__sub hero-sub"><?= $metaLine ?></p>
        <div class="hero-acts">
            <?php require __DIR__ . '/../partials/trail-actions.php'; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// PASEK STATYSTYK TRASY — jedna konsolidacja zamiast dwóch kart (zgłoszenie
// usera 2026-08-20: „brakuje statystyk: jaka długość, jaki profil, jakie
// skarby, ile punktów; dwie karty nad i pod mapą niewiele wnoszą"). Do tej daty
// stały tu: karta „Twój postęp" nad mapą i karta „Punkty za tę trasę" (drabinka
// progów) pod nią, a rzeczy, po które przychodzi się na stronę szlaku — długość,
// przewyższenie, skarby po drodze — albo ginęły w podtytule, albo nie było ich
// wcale.
//
// KAFLE IDĄ WSPÓLNYM PARTIALEM `stat-tiles.php` (druga uwaga usera tego samego
// dnia: „na każdej stronie wygląda inaczej, zrób z tego jeden partial;
// referencyjny niech będzie profil rowerzysty"). Pierwsza wersja tego paska
// miała tu własny markup `.disc-stats`.
//
// PIĘĆ KAFLI, kolejność od faktów o TRASIE (długość, przewyższenie) do faktów
// o WIDZU (postęp, punkty, skarby).
//
// GOŚĆ NIE DOSTAJE ZER. „0%" i „0 znalezionych" dla kogoś bez konta nie są
// informacją o nim, tylko wyrzutem — dostaje więc liczby o samej trasie
// i zaproszenie w podpisie (ta sama zasada co w dymku szlaku na mapie).
$zostaloPol = $progress !== null ? (int) ($progress['total'] - $progress['matched']) : 0;

// ODZNAKA PROGÓW PUNKTOWYCH — wspólny partial (patrz jego nagłówek: po co
// powstała i dlaczego wygląda tak, a nie inaczej). Tutaj zostaje wyłącznie
// PRZEKAZANIE danych: progi tej trasy i postęp widza (null dla gościa).
$badge = renderThresholdBadge($thresholds, $progress !== null ? (float) $progress['pct'] : null);
$pointsBadge = $badge['svg'];
$pointsDone  = $badge['done'];
$pointsSteps = $badge['steps'];

renderStatTiles([
    [
        'lbl' => __('Długość'),
        'val' => (float) $route['distance_km'] > 0 ? Format::distance((float) $route['distance_km']) : '—',
        'sub' => __n((int) $route['cells_total'], '{n} pole do zaliczenia', '{n} pola do zaliczenia', '{n} pól do zaliczenia'),
    ],
    [
        // NULL to „nie wiem", nie „płasko" — trasa sprzed migr. 063 albo plik
        // bez wysokości. Zero jest poprawną wartością dla płaskiej pętli, więc
        // nie może udawać braku danych.
        'lbl'   => __('Przewyższenie'),
        'val'   => $route['elevation_gain_m'] !== null
            ? number_format((int) $route['elevation_gain_m'], 0, ',', ' ') : '—',
        'small' => $route['elevation_gain_m'] !== null ? 'm' : null,
        'sub'   => $route['elevation_gain_m'] !== null ? __('suma podjazdów') : __('plik bez danych o wysokości'),
    ],
    $progress !== null
        ? [
            'lbl'    => __('Twój postęp'),
            'val'    => $progress['isComplete'] ? __('CAŁA') : (int) $progress['pct'] . '%',
            'done'   => (bool) $progress['isComplete'],
            'subHex' => true,
            'sub'    => $progress['isComplete']
                ? __('{a} z {b} pól — masz ją całą', ['a' => (int) $progress['matched'], 'b' => (int) $progress['total']])
                : __('{a} z {b} pól · zostało {c}', ['a' => (int) $progress['matched'], 'b' => (int) $progress['total'], 'c' => $zostaloPol]),
        ]
        : ['lbl' => __('Postęp'), 'val' => '—', 'sub' => __('załóż konto, a pola zaliczą się same')],
    // Trasa z wyłączonym bonusem NIE WCHODZI do gry punktowej (`syncProgress`
    // filtruje po `bonus_enabled`), więc drabinka progów na jej stronie była
    // obietnicą bez pokrycia.
    !$thresholds
        ? ['lbl' => __('Punkty'), 'val' => '—', 'sub' => __('ta trasa nie daje punktów')]
        : [
            'lbl'     => __('Punkty'),
            'val'     => number_format((int) ($pointsEarned ?? array_sum($thresholds)), 0, ',', ' '),
            'small'   => $pointsEarned !== null ? __('z {n}', ['n' => number_format(array_sum($thresholds), 0, ',', ' ')]) : null,
            // „za progi 20/40/60/80/100%" ZDJĘTE stąd (prośba usera 2026-09-11:
            // „niezrozumiałe") — obraz niesie odznaka, a podpis mówi to samo
            // po ludzku: ILE progów, a nie przy ilu procentach one leżą.
            'sub'     => $pointsEarned !== null
                ? __n($pointsSteps, '{a} z {n} progu', '{a} z {n} progów', '{a} z {n} progów', ['a' => $pointsDone])
                : __n($pointsSteps, '{n} próg do zdobycia', '{n} progi do zdobycia', '{n} progów do zdobycia'),
            'graphic' => $pointsBadge,
        ],
    [
        // „Po drodze" znaczy: na POLACH tej trasy — ta sama reguła, którą
        // stosuje zaliczanie ze śladu (Treasure::claimAlongTrack). Nie
        // wypisujemy nazw: są na mapie niżej jako pinezki i to tam mają być
        // znajdowane. Punkty liczone z tego, czego widz JESZCZE nie ma —
        // „+150 pkt" obok „1 z 3 znalezionych" czytałoby się jako obietnica.
        'lbl'   => __('Skarby po drodze'),
        'val'   => $treasures['found'] !== null ? (int) $treasures['found'] : (int) $treasures['total'],
        'small' => $treasures['found'] !== null ? __('z {n}', ['n' => (int) $treasures['total']]) : null,
        'sub'   => $treasures['total'] === 0
            ? __('na tej trasie żadnego nie ma')
            : ($treasures['found'] !== null
                ? __('znalezionych · zostało +{n} pkt', ['n' => number_format((int) $treasures['pointsLeft'], 0, ',', ' ')])
                : __('do znalezienia · +{n} pkt', ['n' => number_format((int) $treasures['pointsLeft'], 0, ',', ' ')])),
    ],
]);
?>

<?php if (!empty($route['gpx_url'])): ?>
<?php // MAPA = TA SAMA KONTROLKA CO WSZĘDZIE INDZIEJ (2026-08-19, prośba usera:
      // „nie chcę, żeby w aplikacji używano czegoś innego niż standardowej
      // kontrolki mapy"). Wcześniej stała tu goła mapa z samym śladem: bez
      // warstw, bez legendy, bez skarbów — jedyny ekran z mapą, który nie
      // zachowywał się jak reszta serwisu.
      //
      // Zero nowego komponentu: `discovery-map.js` zwraca zwykłą mapę Leafletu,
      // a przebieg TEJ trasy dokłada się kaflem klucza `kr-{id}` (2026-09-04)
      // — TYM SAMYM mechanizmem (TileSource::tracks), którym rysuje się
      // warstwa „Trasy" i każdy inny ślad w serwisie, więc obwódka, złączenia
      // i kolor są zawsze takie same wszędzie, bez osobnego kodu do
      // utrzymania. Dawniej rysował to `ridemoreAddGpxTrack` (leaflet-gpx,
      // wektor z pliku) — zdjęte: to był drugi, niezależny silnik rysowania
      // tej samej linii, który nigdy nie dostawał poprawek wyglądu robionych
      // w `TileRenderer` (obwódka 2026-08-27, złączenia 2026-08-29), więc
      // przebieg trasy na tej stronie wyglądał inaczej niż wszędzie indziej. ?>
<section class="sec" id="przebieg">
    <div class="box">
        <h2><?= __('Przebieg') ?></h2>
        <?php // Te same klasy co na /odkrycia — kontrolka warstw i legenda są
              // nakładkami pozycjonowanymi względem `.disc-map`. ?>
        <div class="disc-map" style="margin-top:14px;">
            <div id="trailMap" class="disc-map__canvas"></div>
            <?php // Drzewo, podpisy i stan domyślny liczy od Etapu 2
                  // `Models\MapLayer` ze słownika (`TrailController::show`,
                  // łącznie z patchem „mgła to tu dodatek" na kluczu `cells").
                  // Tej stronie zostaje wyłącznie PRZEKAZANIE `$mapLayers`. ?>
            <?php
            $mlId = 'trailLayers';
            $mlLayers = $mapLayers;
            require __DIR__ . '/../partials/map-layers.php';
            ?>
            <div id="trailLegend" class="hex-legend hex-legend--onmap"></div>
        </div>
        <?php // PROFIL WYSOKOŚCI POD MAPĄ (migr. 065, zgłoszenie usera
              // 2026-08-20: „brakuje, jaki profil trasy"). Ani jednej nowej
              // linii JS-a i ani jednego nowego komponentu: te same klasy
              // (`.profbox`, `.day-profile-big`), te same atrybuty `data-` i ta
              // sama funkcja `ridemoreRenderElevationChart`, którą rysuje się
              // etap wydarzenia — razem z podświetlaniem punktu na mapie przy
              // najechaniu na wykres i z kategoriami podjazdów.
              //
              // Kolor wykresu = KOLOR TRASY (migr. 064), ten sam, którym idzie
              // jej linia na mapie wyżej. ?>
        <?php // PROFIL WYSOKOŚCI — WSPÓLNY PARTIAL (2026-09-03). Ten sam blok
              // rysuje strona przejazdu; wcześniej stał tu w całości razem
              // z nieoczywistym `.day-panel`, po którym wykres znajduje chipy. ?>
        <?php
        $epProfile = $elevation ? $elevation['profile'] : null;
        $epPeaks   = $elevation ? $elevation['peaks'] : [];
        $epColor   = $trailColor;
        require __DIR__ . '/../partials/elevation-profile.php';
        ?>

        <?php // NAWIERZCHNIA (migr. 086, prośba usera 2026-09-11: „na profilu
              // znanej trasy brakuje informacji o nawierzchni"). TEN SAM pasek
              // i ta sama legenda co na stronie wydarzenia — wspólny partial,
              // nie kopia (`partials/surface-breakdown.php`).
              //
              // POD PROFILEM WYSOKOŚCI, bo odpowiada na to samo pytanie co on:
              // „jak się to jedzie". Kafle wyżej mówią ILE (długość, pola,
              // punkty), a te dwa bloki — JAK.
              //
              // Brak detekcji to nie zero: trasa sprzed backfillu albo Overpass
              // niedostępny w chwili wgrania. Wtedy nie ma tu niczego, zamiast
              // paska z samych zer (TrailController::surfaceBreakdown → null). ?>
        <?php if (!empty($surface)): ?>
        <div class="trail-surface">
            <h3 class="trail-surface__h"><?= __('Nawierzchnia') ?></h3>
            <?php $sbBreakdown = $surface; require __DIR__ . '/../partials/surface-breakdown.php'; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('trailMap');
    if (!el || typeof ridemoreDiscoveryMap !== 'function') return;

    var przelacznik = document.getElementById('trailLayers');

    var map = ridemoreDiscoveryMap(el, {
        context: <?= json_encode($mapScope ?? 'all') ?>,
        endpoint: <?= json_encode($mapEndpoints['cells']) ?>,
        <?php // ŹRÓDŁA KAFLI — gotowe z kontrolera (Etap 2). Bez klucza `slady`:
              // `only` w `TrailController::show` zdjęła tę warstwę z drzewa,
              // więc kontroler nie ma dla niej czego zbudować. ?>
        sources: <?= json_encode($mapSources, JSON_UNESCAPED_SLASHES) ?>,
        filters: <?= json_encode($mapFilters, JSON_UNESCAPED_SLASHES) ?>,
        trailsHitEndpoint: <?= json_encode($mapEndpoints['trailsAt']) ?>,
        treasuresEndpoint: <?= json_encode($mapEndpoints['treasures']) ?>,
        <?php // Zaliczanie skarbu tylko dla zalogowanego — anonim zobaczy dymek
              // z opisem, ale nie miałby czym się podpisać. ?>
        treasureActions: <?= $isLoggedIn ? 'true' : 'false' ?>,
        claimEndpoint: <?= json_encode($mapEndpoints['claim']) ?>,
        confirmEndpoint: <?= json_encode($mapEndpoints['confirm']) ?>,
        csrf: <?= json_encode(Core\Csrf::token()) ?>,
        legendEl: document.getElementById('trailLegend'),
        <?php // KADR NALEŻY DO TRASY, nie do odkryć widza. Prostokąt liczy
              // serwer (`KnownRoute::boundsFor`) i mapa dostaje go ZANIM
              // o cokolwiek zapyta — inaczej pierwsze żądanie poszłoby dla
              // całej Polski, a obraz przeskoczyłby po odpowiedzi. ?>
        bounds: <?= json_encode($mapBounds) ?>,
        <?php // Stan początkowy CZYTANY Z KONTROLKI, a nie z domyślnych wartości
              // modułu — inaczej mapa startuje w innym stanie, niż pokazują
              // checkboxy (zmierzone: mgła narysowana mimo odznaczonej warstwy
              // „Odkrycia", bo moduł domyślnie ją zapala).
              //
              // Gość nie dostaje przełącznika „Skarby zdobyte", więc czytnik
              // nie odda tego klucza i obie warstwy skarbów gasną razem —
              // warunek `$isLoggedIn` przestał być tu potrzebny, bo wynika
              // już z tego, co kontrolka W OGÓLE wyrenderowała. ?>
        layers: ridemoreReadLayers(przelacznik),
    });

    if (przelacznik) {
        przelacznik.addEventListener('change', function (e) {
            var cb = e.target.closest('[data-layer]');
            if (cb) { map.ridemoreSetLayer(cb.dataset.layer, cb.checked); }
        });
    }

    // KLIK W KARTĘ ATRAKCJI USTAWIA MAPĘ NA TYM MIEJSCU (2026-08-23). Ten sam
    // mechanizm, którym panel „Skarby, które czekają" obok mapy na /odkrycia
    // wskazuje punkt — `ridemoreFocusTreasure(id, lat, lon)`. Bez tego lista
    // mówi „jest tam coś takiego" i zostawia człowieka z pytaniem „ale gdzie";
    // dokładnie to zgłoszenie zamknęło tamtą wersję panelu.
    //
    // Delegacja na dokumencie, nie słuchacz na każdej karcie: kart bywa
    // kilkadziesiąt, a to jest jedno kliknięcie na wizytę.
    document.addEventListener('click', function (e) {
        var karta = e.target.closest('.js-atrakcja');
        if (!karta) { return; }
        var lat = parseFloat(karta.dataset.lat);
        var lon = parseFloat(karta.dataset.lon);
        if (isNaN(lat) || isNaN(lon)) { return; }
        map.ridemoreFocusTreasure(parseInt(karta.dataset.id, 10), lat, lon);
        // Mapa stoi WYŻEJ na stronie niż lista — bez przewinięcia „nic się nie
        // dzieje" po kliknięciu, bo cała akcja odbywa się poza ekranem.
        document.getElementById('trailMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    // Karta jest przyciskiem (role=button), więc musi reagować na klawiaturę.
    document.addEventListener('keydown', function (e) {
        if ((e.key !== 'Enter' && e.key !== ' ') || !e.target.classList.contains('js-atrakcja')) { return; }
        e.preventDefault();
        e.target.click();
    });

    <?php // PRZEBIEG TEJ TRASY IDZIE WARSTWĄ „ZNANE TRASY", NIE OSOBNYM KAFLEM
          // (2026-09-10). Do tej daty stało tu drugie, zawsze zapalone
          // `ridemoreAddTileLayer(..., 'kr-{id}')` — „bohater" — obok warstwy
          // ciągnącej CAŁY katalog (`kr`). Odznaczenie „Znanych tras" nie gasiło
          // wtedy linii tej trasy, bo rysował ją tamten drugi kafel, a mapa
          // strony o jednym szlaku pokazywała wszystkie sąsiednie.
          //
          // Teraz `mapSources.trails` NIESIE klucz `kr-{id}` (TrailController::
          // show), więc mapa rysuje tę jedną trasę tym samym kodem co zawsze,
          // a checkbox nią steruje. Sąsiedzi są w sekcji „W okolicy tej trasy". ?>
    // Wykres profilu podpina wspólny helper (`assets/js/gpx-map.js`): to on zna
    // bramkę na zerowy kontener i odczyt atrybutów `data-`, a stały tu one
    // skopiowane razem z markupem.
    ridemoreSetupElevationProfile(map);
});
</script>
<?php endif; ?>

<?php // OPIS TRASY — POD PROFILEM WYSOKOŚCI, nie na górze strony (prośba usera
      // 2026-09-10: „opis nie jest potrzebny od razu na górze"). Ten sam wzorzec
      // sekcji co „O wyjeździe" na stronie wydarzenia (.sec/.box/<h2>) zamiast
      // akapitu w nagłówku. `.op-head__sub--full` (modyfikator „na całą
      // szerokość", 2026-08-23) jedzie razem z opisem bez zmian. ?>
<?php if (!empty($route['description'])): ?>
<section class="sec" id="o-trasie">
    <div class="box">
        <h2><?= __('O trasie') ?></h2>
        <p class="op-head__sub op-head__sub--full"><?= nl2br(htmlspecialchars($route['description'])) ?></p>
        <?php
            $tnMeta = $route['translation'] ?? null;
            $tnEditUrl = (Core\Auth::user()?->isAdmin ?? false) ? Utils\View::url('/tlumaczenie/route/' . (int) $route['id']) : null;
            require __DIR__ . '/../partials/translated-note.php';
        ?>
    </div>
</section>
<?php endif; ?>

<?php // EMBLEMAT ZA PRZEJECHANIE CAŁOŚCI (migr. 087, 2026-09-11).
      //
      // Stoi ZARAZ POD opisem trasy, a nad skarbami: skarby są tym, co można
      // zebrać PO DRODZE, emblemat jest tym, co się dostaje ZA CAŁOŚĆ — więc
      // czyta się jako cel, do którego ta strona namawia, a nie jako kolejna
      // atrakcja na liście.
      //
      // Trasa bez przypisanego emblematu nie renderuje tu nic — brak nagrody
      // nie jest informacją, którą trzeba ogłaszać. ?>
<?php if (!empty($emblem)): ?>
<section class="sec" id="emblemat">
    <div class="emblem-promise<?= $emblem['mine'] ? ' emblem-promise--mine' : '' ?>">
        <?php
        // Adres grafiki składany w bloku, nie w atrybucie: `url('...')` wewnątrz
        // łańcucha w apostrofach wymaga escape'owania, które w jednolinijkowym
        // ternarnym w środku HTML-a jest nie do przeczytania (i raz już wywaliło
        // parser). Ta sama forma co przy karcie skarbu w `treasure-list.php`.
        $emStyl = !empty($emblem['imageUrl'])
            ? ' style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($emblem['imageUrl'], 'thumb')) . '\')"'
            : '';
        ?>
        <span class="emblem-hex<?= empty($emblem['imageUrl']) ? ' emblem-hex--empty' : '' ?>"<?= $emStyl ?>>
            <?= empty($emblem['imageUrl']) ? Utils\Icon::render('hex') : '' ?>
        </span>
        <div class="emblem-promise__b">
            <?php if ($emblem['mine']): ?>
            <span class="emblem-promise__got"><?= __('MASZ TEN EMBLEMAT') ?></span>
            <?php endif; ?>
            <h3><?= htmlspecialchars($emblem['name']) ?></h3>
            <p>
                <?php if (!empty($emblem['description'])): ?>
                <?= htmlspecialchars($emblem['description']) ?><br>
                <?php endif; ?>
                <?= $emblem['mine']
                    ? __('Zdobyty za przejechanie całej trasy — widać go na Twoim profilu.')
                    : __('Za przejechanie całej trasy, czyli pokrycie 100% jej pól. Raz zdobyty zostaje na zawsze.') ?>
            </p>
        </div>
    </div>
</section>
<?php endif; ?>

<?php // CO ZOBACZYSZ PO DRODZE (2026-08-23, prośba usera: „chciałbym zobaczyć,
      // jakie skarby — w domyśle atrakcje — zobaczę na trasie").
      //
      // Skarb w tym serwisie jest DWIEMA rzeczami naraz: punktem do zdobycia
      // i miejscem, które warto zobaczyć. Kafel statystyk mówił dotąd tylko
      // o pierwszym („12 skarbów, +600 pkt"), a człowiek planujący wyjazd pyta
      // o drugie. Ta sekcja odpowiada na to pytanie i dlatego stoi NAD listą
      // „Mają ją całą": najpierw „co tam jest", potem „kto już tam był".
      //
      // TE SAME KARTY CO KATALOG TRAS (`.disc-trails--all` + `.disc-trail`) —
      // zero nowego komponentu. Karta z miniaturą, nazwą, metryczką i stopką
      // to dokładnie ten kształt; miejsce na zdjęcie było w niej od początku.
      //
      // UJAWNIENIE ROBI MODEL, nie ten widok (Models\Treasure::listOnRoute →
      // reveal()). Skarb ukryty w polu, którego widz nie odkrył, w ogóle tu
      // nie dojedzie — dostajemy tylko ich LICZBĘ, żeby lista zgadzała się
      // z kaflem „Skarby" wyżej. ?>
<?php if (!empty($treasureList['items']) || (int) ($treasureList['hidden'] ?? 0) > 0): ?>
<section class="sec" id="atrakcje">
    <div class="box">
        <h2><?= __('Co zobaczysz po drodze') ?></h2>
        <p class="desc" style="margin-top:6px;">
            <?php // Kolejność jest ODPOWIEDZIĄ, nie ozdobą: karty stoją wzdłuż
                  // śladu, więc lista czyta się jak plan wyprawy. Mówimy o tym
                  // wprost, bo inaczej wygląda na przypadkową. ?>
            <?= __('Miejsca leżące na tej trasie, w kolejności, w jakiej je miniesz.
            Za każde są punkty — zaliczają się same, gdy przejedziesz obok') ?>

            <?php if (!$isLoggedIn): ?><?= __('(potrzebne konto)') ?><?php endif; ?>.
        </p>

        <?php // KARTY SKARBÓW — WSPÓLNY PARTIAL (2026-09-03), ten sam, którego
              // używa strona przejazdu. Zdanie o ukrytych miejscach jedzie
              // razem z nimi, bo bez niego lista wygląda na niepełną. ?>
        <?php
        $tlList = $treasureList;
        require __DIR__ . '/../partials/treasure-list.php';
        ?>
    </div>
</section>
<?php endif; ?>

<?php // KTO JUŻ PRZEJECHAŁ — „nie jesteś tu pierwszy". Liczba obejmuje
      // wszystkich, lista tylko widocznych: ukrycie dotyczy tożsamości, nie
      // faktu (ta sama zasada co przy składzie wyjazdu). ?>
<?php if ($finishers['total'] > 0): ?>
<section class="sec" id="kto">
    <div class="box">
        <h2><?= __('Mają ją całą') ?></h2>
        <div class="roster" style="margin-top:12px;">
            <span class="avs">
                <?php foreach ($finishers['people'] as $u): ?>
                <?php renderRiderAvatar($initials($u), $u['public_slug'] ?? null, $shortName($u), $u['avatar_url'] ?? null); ?>
                <?php endforeach; ?>
            </span>
            <span class="roster__names">
                <?= __n((int) $finishers['total'], '<b>{n} osoba</b> przejechała tę trasę w całości', '<b>{n} osoby</b> przejechały tę trasę w całości', '<b>{n} osób</b> przejechało tę trasę w całości') ?>

            </span>
        </div>
    </div>
</section>
<?php endif; ?>

<?php // W OKOLICY TEJ TRASY (2026-09-10, prośba usera). To, co zeszło z mapy
      // razem z zawężeniem warstwy „Znane trasy" do tej jednej: szlaki, które
      // się z nią krzyżują albo biegną w pobliżu. Sąsiedztwo liczy model tą
      // samą definicją, którą serwis dobiera trasom kolory (`KnownRoute::
      // nearby()`), a karta jest tą samą kartą co w katalogu /trasy
      // (`partials/trail-card.php`).
      //
      // Zamiast paska postępu — PODPIS O RELACJI DO TEJ TRASY („krzyżuje się"
      // / „biegnie w pobliżu"). Procent na cudzej trasie odpowiadałby tu na
      // pytanie, którego nikt na tej stronie nie zadał; człowiek wchodzi po
      // „co jeszcze mogę tu przejechać", nie po własną statystykę. ?>
<?php if (!empty($nearby)): ?>
<section class="sec" id="w-okolicy">
    <div class="sec-head">
        <h2><?= __('W okolicy tej trasy') ?></h2>
        <span><?= __('Szlaki, które ją przecinają albo biegną obok — mapa wyżej pokazuje wyłącznie tę jedną') ?></span>
    </div>
    <div class="disc-trails">
        <?php foreach ($nearby as $near): ?>
        <?php renderTrailCard($near, (bool) $isLoggedIn, [
            'note' => !empty($near['crosses']) ? __('krzyżuje się z tą trasą') : __('biegnie w pobliżu'),
        ]); ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="disc-cta">
    <div>
        <h3><?= __('Trasy zalicza się jeżdżąc') ?></h3>
        <p><?= __('Wgraj ślad z przejechanego wyjazdu — pola zaliczą się same, także wstecz.') ?></p>
    </div>
    <a class="btn" href="<?= View::url('/odkrycia') ?>"><?= __('Moja mapa odkryć') ?></a>
</div>

<?php // Wspólny lightbox — dymek skarbu na mapie wstawia kafelki `.ph-link`
      // z galerią (SKA/14). Bez tego kliknięcie w zdjęcie wyprowadzałoby
      // z mapy do nowej karty. ?>
<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
