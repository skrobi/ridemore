<?php
// views/web/pages/chronicle.php
// KRONIKA WYJAZDU — /kronika/{slug}[?termin=ID] (Etap 4).
//
// To jest ZAPIS, nie rozmowa i nie ściana postów. Dyskusja należy do kanału
// grupy, pytania do sekcji na stronie wydarzenia — tutaj jest tylko to, co
// się wydarzyło: kto był, którędy, i co ci ludzie po sobie zostawili.
//
// Kolejność sekcji celowa:
//   1. co i kiedy (fakty)
//   2. POJECHALI  ← ludzie PRZED zdjęciami; Instagram zaczyna od obrazka,
//                   kronika zaczyna od składu
//   3. trasa
//   4. dziennik (wpisy chronologicznie, po godzinie — opowieść o dniu)
//   5. ile znajomości powstało tego dnia
//   6. następny krok
//
// Zero nowych klas CSS — .box/.sec, .avs (skład), .tag/.tags, .op-head,
// .review/.review-* przez renderActivityCard() (ten sam komponent, co opinie
// i komentarze na stronie wydarzenia), .btn/.eyebrow/.blaze.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
require __DIR__ . '/../partials/activity-card.php';
require __DIR__ . '/../partials/rider-avatar.php';
require_once __DIR__ . '/../partials/stat-tiles.php';
require __DIR__ . '/../partials/track-upload.php';
require_once __DIR__ . '/../partials/region-link.php';

if (!$chronicle) {
    ?>
    <div class="op-head">
        <div class="op-head__c">
            <h1><?= __('Nie znaleziono kroniki') ?></h1>
            <p class="op-head__sub"><?= __('Kronika powstaje dla wyjazdu, który już się odbył i na którym
                ktoś potwierdził obecność. Jeśli właśnie wróciłeś z trasy — potwierdź udział na
                stronie wydarzenia, a kronika pojawi się sama.') ?></p>
            <div class="op-head__act">
                <a class="btn" href="<?= View::url('/relacje') ?>"><?= __('Zobacz inne kroniki') ?></a>
            </div>
        </div>
    </div>
    <?php
    return;
}

$k = $chronicle;
$viewerAttended = $viewerAttended ?? false;
$viewerRecap = $viewerRecap ?? null;
// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);
?>

<div class="op-head">
    <div class="op-head__c">
        <div class="tags">
            <span class="tag tag--plain"><?= __('Kronika wyjazdu') ?></span>
            <?php if ($k['regionLabel']): ?>
            <?= renderRegionLinks($k['regionLabel'], 'tag tag--plain', "\n            ") ?>
            <?php endif; ?>
        </div>
        <h1><?= htmlspecialchars($k['title']) ?></h1>
        <p class="op-head__sub">
            <?= htmlspecialchars($k['dateLabel']) ?>
            <?= $k['distanceKm'] > 0 ? ' · ' . htmlspecialchars(Format::distance((float) $k['distanceKm'])) : '' ?>
            <?= $k['elevationM'] > 0 ? ' · ' . __('{n} m przewyższenia', ['n' => (int) $k['elevationM']]) : '' ?>
        </p>
        <div class="op-head__act">
            <a class="btn btn-secondary" href="<?= View::url('/events/' . $k['eventSlug']) ?>?termin=<?= (int) $k['editionId'] ?>"><?= __('Strona wydarzenia') ?></a>
            <?php if ($k['organizerSlug']): ?>
            <a class="btn btn-secondary" href="<?= View::url('/organizatorzy/' . $k['organizerSlug']) ?>"><?= htmlspecialchars((string) $k['organizerName']) ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
    // POJECHALI — skład przed zdjęciami. Kronika jest o ludziach, nie o obrazkach.
?>
<section class="sec" id="pojechali">
    <div class="box">
        <h2><?= __('Pojechali') ?></h2>
        <div class="roster">
            <span class="avs">
                <?php foreach (array_slice($k['people'], 0, 12) as $person): ?>
                <?php renderRiderAvatar($person['initials'], $person['slug'], $person['name'], $person['avatarUrl'] ?? null); ?>
                <?php endforeach; ?>
                <?php $shown = min(count($k['people']), 12); ?>
                <?php if ($k['peopleCount'] > $shown): ?><span>+<?= $k['peopleCount'] - $shown ?></span><?php endif; ?>
            </span>
            <span class="roster__names">
                <?= __n((int) $k['peopleCount'], '<b>{n} osoba</b> przejechała tę trasę', '<b>{n} osoby</b> przejechały tę trasę', '<b>{n} osób</b> przejechało tę trasę') ?>

            </span>
        </div>
        <?php if ($k['people']): ?>
        <p class="roster__names" style="margin-top:12px;">
            <?php foreach ($k['people'] as $i => $person): ?>
            <?php if ($i > 0): ?> · <?php endif; ?>
            <?php if ($person['slug']): ?>
            <a href="<?= htmlspecialchars(View::url('/rowerzysta/' . $person['slug'])) ?>" style="color:inherit;"><?= htmlspecialchars($person['name']) ?></a>
            <?php else: ?>
            <?= htmlspecialchars($person['name']) ?>
            <?php endif; ?>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>
        <?php
            // Dwa fakty O TYM SKŁADZIE — debiuty w regionie i nowe znajomości —
            // wracają TUTAJ, do składu, zamiast mieć własne pełnoszerokościowe
            // sekcje (uwaga usera 2026-08-12: „czemu to takie duże"). To zdania
            // o ludziach z tej listy, więc ich miejsce jest przy tej liście;
            // osobna karta na jedno zdanie robiła ze strony ciąg slajdów.
            $facts = [];
            if ($k['firstTimers'] > 0 && $k['regionLabel']) {
                $facts[] = __n((int) $k['firstTimers'], 'dla {n} osoby to był pierwszy raz w regionie {region}', 'dla {n} osób to był pierwszy raz w regionie {region}', 'dla {n} osób to był pierwszy raz w regionie {region}', ['region' => renderRegionLinks($k['regionLabel'])]);
            }
            if ($k['newPairs'] > 0) {
                $facts[] = __n((int) $k['newPairs'], 'zawiązała się <b>{n} nowa znajomość</b>', 'zawiązały się <b>{n} nowe znajomości</b>', 'zawiązało się <b>{n} nowych znajomości</b>');
            }
            // Discovery (Etap 8) dopisuje się do TEJ SAMEJ linijki, a nie do
            // własnej sekcji — to trzeci fakt o tym samym składzie i tym samym
            // dniu. Kronika ma 5 sekcji po świadomym cięciu z 7; szósta za
            // jedno zdanie byłaby cofnięciem tamtej decyzji.
            if (!empty($k['newCells'])) {
                $facts[] = __n((int) $k['newCells'], 'odkryli {hex} <b>nowe pole</b>', 'odkryli {hex} <b>nowe pola</b>', 'odkryli {hex} <b>nowych pól</b>', ['hex' => '<b class="hexn">' . Utils\Icon::render('hex') . (int) $k['newCells'] . '</b>']);
            }
        ?>
        <?php if ($facts): ?>
        <p class="chr-facts">
            <?= ucfirst(implode(' · ', $facts)) ?>
            <?php if ($k['newPairs'] > 0): ?>
            <span class="chr-facts__note"><?= __('Te osoby mają się od teraz nawzajem w peletonie.') ?></span>
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <?php
            // „KTOŚ JESZCZE TAM BYŁ?" — dopisek do TEJ listy, nie osobna sekcja
            // na dole strony (uwaga usera 2026-08-13). Zaproszenie to pytanie
            // o skład, więc jego miejsce jest przy składzie: patrzysz, kogo
            // brakuje na liście, i od razu masz gdzie wpisać adres.
            //
            // Kotwica #oznacz MUSI zostać — ChronicleController::invite wraca
            // tutaj z komunikatem (&zaproszono=1#oznacz albo &blad=...#oznacz).
            $inviteErrors = [
                'sesja'         => __('Sesja wygasła — spróbuj jeszcze raz.'),
                'email'         => __('To nie wygląda na prawidłowy adres e-mail.'),
                'samemu'        => __('To Twój własny adres.'),
                'limit'         => __('Na dziś wystarczy zaproszeń z tego wyjazdu.'),
                'nieuprawniony' => __('Zaprosić może tylko ktoś, kto był na tym wyjeździe.'),
            ];
        ?>
        <?php if ($viewerAttended): ?>
        <div class="chr-more" id="oznacz">
            <b><?= __('Ktoś jeszcze tam był?') ?></b>
            <p><?= __('Dostanie link do tej kroniki. Nikogo nie dopisujemy do składu za niego —
                udział potwierdza sam.') ?></p>
            <?php if (!empty($inviteSent)): ?>
            <p class="chr-more__ok"><?= __('Zaproszenie wysłane.') ?></p>
            <?php elseif (!empty($inviteError)): ?>
            <p class="form-error"><?= htmlspecialchars($inviteErrors[$inviteError] ?? __('Nie udało się wysłać zaproszenia.')) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= View::url('/kronika/' . $k['eventSlug'] . '/oznacz') ?>" class="chr-invite">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= (int) $k['editionId'] ?>">
                <input class="search-input" type="email" name="email" required placeholder="<?= htmlspecialchars(__('adres e-mail')) ?>">
                <button type="submit" class="btn btn-secondary"><?= __('Wyślij') ?></button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($k['tracks']): ?>
<?php
    // TRASA — dowód, że ta droga istniała i została przejechana. Ten sam
    // mechanizm co mapa na stronie wydarzenia (Utils gpx-map.js), tylko bez
    // profilu wysokości: kronika jest o dniu, nie o analizie podjazdów.
    //
    // WSZYSTKIE ślady naraz, nie pierwszy z brzegu (2026-08-12) — wielodniówka
    // ma osobny plik na każdy dzień, więc rysowanie jednego pokazywało jeden
    // dzień z trzech. Kolory z DayColor::forDay(), ten sam zestaw co plan
    // wyjazdu na stronie wydarzenia, żeby „Dzień 2" znaczył wszędzie ten sam
    // kolor. Granice sumowane w onLoaded — ślady wczytują się asynchronicznie,
    // więc kadr dopasowany do ostatniego byłby losowy.
    $trackColors = [];
    foreach ($k['tracks'] as $i => $t) {
        $trackColors[$i] = count($k['tracks']) > 1
            ? \Utils\DayColor::forDay($i + 1)['color']
            : '#2C6B4F';
    }
    $rideStats = $discoveryRide ?? null;
    // Suma z REJESTRU (Etap 8A/7) — obejmuje też punkty za jazdę i za
    // wydarzenie, których stare kolumny points_* nie miały gdzie pomieścić.
    $ridePoints = (int) ($rideStats['points'] ?? 0);
?>
<section class="sec" id="trasa">
    <div class="box">
        <h2><?= __('Trasa') ?></h2>
        <p class="chr-actions__sub" style="margin-bottom:0;">
            <?= $k['tracksAreActual']
                ? __('Ślad z tego, co faktycznie przejechaliście.')
                : __('Trasa zapowiedziana przy wydarzeniu — nikt nie wgrał jeszcze śladu z samego wyjazdu.') ?>
        </p>
        <div class="chr-route">
            <div id="chronicleMap" class="chr-route__map"></div>
            <div class="chr-route__side">
                <?php // Legenda POLA — najpierw, bo to o nich jest ta mapa;
                      // ślady są pod spodem tylko po to, żeby było widać którędy.
                      // „Odkryte wcześniej" nie jest porażką i nie może tak
                      // wyglądać: przez własny teren jeździ się stale (dojazd
                      // z domu, ulubiona pętla), więc dostaje neutralną szarość,
                      // a nie kolor ostrzegawczy. ?>
                <?php if (!empty($rideCells['cells'])): ?>
                <?php
                    $cellsNew = 0;
                    foreach ($rideCells['cells'] as $c) { $cellsNew += $c['n']; }
                    $cellsOld = count($rideCells['cells']) - $cellsNew;
                ?>
                <div class="chr-route__row">
                    <i style="background:#2C6B4F"></i>
                    <span><b><?= $cellsNew ?>
                        <?= $plural($cellsNew, 'nowe pole', 'nowe pola', __('nowych pól')) ?></b>
                        <small><?= __('odkryte tym przejazdem') ?></small></span>
                </div>
                <?php if ($cellsOld > 0): ?>
                <div class="chr-route__row">
                    <i style="background:#8B95A3"></i>
                    <span><b><?= $cellsOld ?>
                        <?= $plural($cellsOld, 'pole', 'pola', 'pól') ?></b>
                        <small><?= $viewerAttended ? __('miałeś już wcześniej') : __('skład miał już wcześniej') ?></small></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php // Legenda śladów: przy wielodniówce mówi, który kolor to
                      // który dzień; przy jednym śladzie to po prostu jego opis. ?>
                <?php foreach ($k['tracks'] as $i => $t): ?>
                <div class="chr-route__row">
                    <i style="background:<?= htmlspecialchars($trackColors[$i]) ?>"></i>
                    <span>
                        <b><?= htmlspecialchars($t['label'] ?: ($t['isOwn'] ? __('Twój ślad') : __('Trasa'))) ?></b>
                        <?php if ($t['distanceKm'] > 0): ?>
                        <small><?= htmlspecialchars(Format::distance((float) $t['distanceKm'])) ?></small>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>

                <?php // CO TO WNIOSŁO W POLACH — od 2026-08-20 tymi samymi
                      // kaflami, którymi mówi profil rowerzysty i strona trasy
                      // (`stat-tiles.php`, wariant `compact` do kolumny bocznej).
                      // Wcześniej te liczby były tu składane ręcznie i jako
                      // jedyne w serwisie nie miały nawet podpisu NAD liczbą —
                      // trzeba było przeczytać zdanie pod spodem, żeby wiedzieć,
                      // co się właśnie widzi.
                      //
                      // Dystans i przewyższenie SĄ TU MIMO że stoją też
                      // w nagłówku: nagłówek jest o wyjeździe („kiedy, gdzie,
                      // z kim"), a ta kolumna o ŚLADZIE, który obok leży na
                      // mapie. Tak samo działa strona trasy.
                      //
                      // Dla uczestnika liczby są jego własne (z tego przejazdu),
                      // dla każdego innego — dorobek całego składu. ?>
                <div class="chr-route__disc">
                    <p class="roster__lbl"><?= __('Co to dało w Discovery') ?></p>
                    <?php renderStatTiles([
                        $k['distanceKm'] > 0
                            ? ['lbl' => __('Dystans'), 'val' => Format::distance((float) $k['distanceKm']),
                               'sub' => $k['tracksAreActual'] ? __('ślad z wyjazdu') : __('trasa zapowiedziana')]
                            : null,
                        $k['elevationM'] > 0
                            ? ['lbl' => __('Przewyższenie'), 'val' => number_format((int) $k['elevationM'], 0, ',', ' '),
                               'small' => 'm', 'sub' => __('suma podjazdów')]
                            : null,
                        $rideStats
                            ? ['lbl' => __('Nowe pola'), 'val' => number_format((int) $rideStats['cells_new'], 0, ',', ' '), 'hex' => true,
                               'sub' => __('z {n} pól na trasie', ['n' => number_format((int) $rideStats['cells_touched'], 0, ',', ' ')])]
                            : (!empty($discoveryCells)
                                ? ['lbl' => __('Nowe pola'), 'val' => number_format((int) $discoveryCells, 0, ',', ' '), 'hex' => true,
                                   'sub' => __('odkrytych przez ten skład')]
                                : null),
                        $rideStats
                            ? ['lbl' => __('Punkty'), 'val' => '+' . number_format($ridePoints, 0, ',', ' '),
                               'sub' => __('Discovery za ten przejazd')]
                            : null,
                    ], ['compact' => true]); ?>
                    <?php if ($rideStats): ?>
                    <a class="btn btn-secondary btn--sm" href="<?= View::url('/odkrycia') ?>"><?= __('Twoja mapa odkryć') ?></a>
                    <?php elseif (!empty($discoveryCells)): ?>
                    <a class="btn btn-secondary btn--sm" href="<?= View::url('/odkrycia/spolecznosc') ?>"><?= __('Wspólna mapa') ?></a>
                    <?php elseif ($k['tracksAreActual']): ?>
                    <?php // Bez ani jednego pola nie ma czego kafelkować — zostaje
                          // zdanie, które tłumaczy, dlaczego liczb nie ma. ?>
                    <div class="chr-route__meta"><?= __('Pola naliczają się osobom, które potwierdziły
                        obecność na tym turnusie.') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    var el = document.getElementById('chronicleMap');
    if (!el || typeof ridemoreCreateMap !== 'function') return;
    var map = ridemoreCreateMap(el);

    <?php // POLA DISCOVERY pod śladami — własny panel o niższym z-index, żeby
          // linia trasy została czytelna na wierzchu. Rysowane jako DWA
          // wielokąty złożone (nowe / już posiadane), nie setka osobnych
          // warstw — ta sama decyzja co na mapie odkryć. ?>
    <?php if (!empty($rideCells['cells'])): ?>
    if (typeof ridemoreHexRing === 'function') {
        var cells = <?= json_encode($rideCells['cells']) ?>;
        var hexSize = <?= json_encode($rideCells['sizeM']) ?>;
        if (!map.getPane('rideHex')) { map.createPane('rideHex').style.zIndex = 350; }
        var groups = { nowe: [], stare: [] };
        cells.forEach(function (c) {
            groups[c.n ? 'nowe' : 'stare'].push([ridemoreHexRing(c.a, c.o, hexSize)]);
        });
        <?php // OBRYS POLA TYLKO OD ZOOMU 12 (2026-08-14).
              //
              // Ten sam biały włos co na mapie odkryć (discovery-map.js,
              // hexPolygon) i z tego samego powodu — ale TYLKO w przybliżeniu.
              //
              // Tamta mapa dobiera poziom agregacji do oddalenia, więc pole ma
              // na ekranie stale ok. 35 px i 0,6 px obrysu to najwyżej 1,7%
              // jego szerokości. Tutaj poziom jest stały (RES_CELL, ok. 500 m),
              // a kadr dopasowany do CAŁEGO przejazdu — przy trasie 100 km pole
              // ma 3,5 px, więc obrys zająłby 17% jego powierzchni i zamiast
              // rozdzielić pola, wyblakłby cały korytarz na biało.
              //
              // Próg 12 nie jest przypadkowy: to ten sam zoom, od którego
              // api/routes.php podaje pojedyncze pola zamiast plam (res 4). ?>
        var HEX_OUTLINE_ZOOM = 12;
        var hexShapes = [];
        [['stare', '#8B95A3', 0.3], ['nowe', '#2C6B4F', 0.36]].forEach(function (g) {
            if (!groups[g[0]].length) return;
            hexShapes.push(L.polygon(groups[g[0]], {
                pane: 'rideHex', color: '#FFFFFF', fillColor: g[1],
                weight: 0.6, opacity: 0, fillOpacity: g[2], interactive: false
            }).addTo(map));
        });
        var syncHexOutline = function () {
            var on = map.getZoom() >= HEX_OUTLINE_ZOOM;
            hexShapes.forEach(function (s) { s.setStyle({ opacity: on ? 0.35 : 0 }); });
        };
        map.on('zoomend', syncHexOutline);
        syncHexOutline();
    }
    <?php endif; ?>

    var tracks = <?= json_encode(array_map(
        fn($i, $t) => ['url' => View::url($t['url']), 'color' => $trackColors[$i]],
        array_keys($k['tracks']),
        $k['tracks']
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    // Wspólne granice WSZYSTKICH śladów — kadr ma objąć cały wyjazd, a nie
    // ten dzień, który akurat wczytał się ostatni.
    var bounds = null;
    tracks.forEach(function (t) {
        ridemoreAddGpxTrack(map, t.url, {
            color: t.color,
            onLoaded: function (e) {
                var b = e.target.getBounds();
                bounds = bounds ? bounds.extend(b) : b;
                map.fitBounds(bounds, { padding: [18, 18] });
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php
    // DZIENNIK — wpisy uczestników.
    //
    // Dodawanie i edycja mieszkają TUTAJ, przy dzienniku, a nie w osobnym
    // boksie na dole strony (uwaga usera 2026-08-13: „mogę mieć 50 wpisów").
    // Przy pięćdziesięciu wpisach przycisk pod nimi jest poza zasięgiem wzroku
    // i wymaga przewinięcia całej cudzej treści, żeby dodać własną.
    //   - „Dodaj wpis do kroniki" — w nagłówku sekcji, zawsze na wierzchu.
    //     Tylko dla kogoś, kto jeszcze wpisu nie ma: UNIQUE(edition_id, author)
    //     dopuszcza jeden na osobę, więc drugi przycisk prowadziłby do edycji
    //     pod nazwą „dodaj".
    //   - ołówek — przy WŁASNYM wpisie, czyli tam, gdzie się na niego patrzy.
    //
    // Sekcja renderuje się także PUSTA, gdy widz był na wyjeździe — inaczej
    // pierwszy uczestnik nie miałby skąd dodać pierwszego wpisu.
    $recapUrl = View::url('/wydarzenia/' . $k['eventSlug'] . '/relacja')
        . '?termin=' . (int) $k['editionId'];
?>
<?php if ($k['entries'] || $viewerAttended): ?>
<section class="sec" id="dziennik">
    <div class="sec-head">
        <h2><?= __('Dziennik wyjazdu') ?></h2>
        <?php // Przycisk zostaje ZAWSZE dla uczestnika (migr. 052) — kronika
              // przyjmuje kolejne wpisy, więc „już pisałem" nie jest powodem,
              // żeby go chować. Wcześniej znikał po pierwszym wpisie, bo wpis
              // mógł być tylko jeden. ?>
        <?php if ($viewerAttended): ?>
        <a class="btn" href="<?= htmlspecialchars($recapUrl) ?>"><?= $viewerRecap ? __('Dopisz do kroniki') : __('Dodaj wpis do kroniki') ?></a>
        <?php endif; ?>
    </div>
    <?php // ZDJĘCIA, KTÓRE NIE WESZŁY — komunikat z przekierowania po zapisie
          // relacji (RecapController::submit). Wpis jest już zapisany, więc nie
          // ma do czego wrócić formularzem; jedyne, co można zrobić, to
          // powiedzieć autorowi, CO odpadło i dlaczego. Milczenie było gorsze:
          // widział swój wpis bez zdjęć i nie wiedział, czy to jego wina, czy
          // usterka serwisu. ?>
    <?php if (!empty($_GET['zdjecia'])): ?>
    <div class="box chr-photo-warn">
        <b><?= __('Nie wszystkie zdjęcia się zapisały.') ?></b>
        <span><?= htmlspecialchars((string) $_GET['zdjecia']) ?></span>
        <span><?= __('Resztę wpisu zapisaliśmy — możesz dodać brakujące zdjęcia, edytując wpis.') ?></span>
    </div>
    <?php endif; ?>
    <div class="box">
        <?php if (!$k['entries']): ?>
        <p class="desc" style="margin:0;"><?= __('Nikt jeszcze nic nie dopisał. Jedno zdjęcie albo dwa
            zdania wystarczą — kronika ma już skład, trasę i datę.') ?></p>
        <?php endif; ?>
        <?php foreach ($k['entries'] as $entry): ?>
        <?php
            // Ten sam komponent, którym renderują się opinie i komentarze na
            // stronie wydarzenia (partials/activity-card.php) — kronika nie
            // wprowadza własnego wyglądu wpisu.
            ob_start();
            if (!empty($entry['body'])) {
                echo '<p class="lead" style="font-size:15px;">', nl2br(htmlspecialchars($entry['body'])), '</p>';
            }
            if (!empty($entry['youtubeId'])) {
                echo '<p style="margin-top:10px;"><a href="https://www.youtube.com/watch?v=',
                     htmlspecialchars($entry['youtubeId']), '" target="_blank" rel="noopener">', __('Film z wyjazdu →'), '</a></p>';
            }
            // ZDJĘCIA JAKO MINIATURY, NIE W PEŁNEJ SZEROKOŚCI (uwaga usera
            // 2026-08-14). Każde zdjęcie zajmowało wcześniej całą szerokość
            // wpisu i własny akapit, więc wpis z czterema zdjęciami rozpychał
            // kronikę na kilka ekranów, a tekst — czyli to, po co się do
            // kroniki wchodzi — lądował gdzieś między nimi.
            //
            // Kwadratowe kafle w siatce + podgląd w modalu. Pełny plik ładuje
            // się DOPIERO po kliknięciu (miniatura to ten sam plik przycięty
            // przez CSS, ale przeglądarka pobiera go raz — a pełny podgląd
            // i tak korzysta z cache'u).
            if ($entry['photos']) {
                echo '<div class="ph-grid">';
                foreach ($entry['photos'] as $photo) {
                    // MINIATURA to wariant `thumb` (320 px), nie oryginal przeskalowany CSS-em.
                    // Zmierzone na tym katalogu: 1200x900 / 229 kB -> 320x240 / 18 kB,
                    // czyli 92% mniej do pobrania na kafelek majacy 96 px. Pelny plik
                    // leci dopiero do `data-full`, czyli po kliknieciu.
                    $adres = htmlspecialchars(Utils\Image::src($photo, 'thumb'));
                    $pelny = htmlspecialchars(View::url($photo));
                    echo '<button type="button" class="ph-thumb" data-full="', $pelny,
                         '" style="background-image:url(\'', $adres, '\')" ',
                         'aria-label="', htmlspecialchars(__('Powiększ zdjęcie z wyjazdu')), '"></button>';
                }
                echo '</div>';
            }
            $bodyHtml = ob_get_clean();

            // Ołówek TYLKO przy własnym wpisie — i od migr. 052 prowadzi do
            // TEGO wpisu (`&wpis=ID`), a nie do „mojej relacji". Jedna osoba może
            // mieć ich w turnusie kilka, więc bez id nie dałoby się powiedzieć,
            // który ma zostać nadpisany.
            //
            // Rozpoznajemy po id autora wpisu, nie po nazwisku — imiona się
            // powtarzają.
            $isOwnEntry = !empty($viewerId) && (int) ($entry['authorId'] ?? 0) === (int) $viewerId;
            $actions = $isOwnEntry
                ? '<a class="iconbtn" href="' . htmlspecialchars($recapUrl . '&wpis=' . (int) $entry['id']) . '"'
                    . ' title="' . htmlspecialchars(__('Edytuj ten wpis')) . '" aria-label="' . htmlspecialchars(__('Edytuj ten wpis')) . '">'
                    . Utils\Icon::render('edit') . '</a>'
                : '';

            // Awatar i podpis autora prowadzą do jego profilu (jeśli ma slug).
            renderActivityCard(
                $entry['authorName'],
                Format::dateShort($entry['createdAt']) ?? '',
                $bodyHtml,
                '',
                '',
                $entry['authorSlug'] ?? null,
                $actions,
                $entry['authorAvatarUrl'] ?? null
            );
        ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php // ŚLAD Z ODBYTEGO WYJAZDU — ta sama kontrolka co na stronie wydarzenia
      // (partials/track-upload.php). Kronika jest miejscem, w którym ludzie
      // lądują PO powrocie — z maila, z Pulsu, z listy relacji — więc jeśli
      // gdziekolwiek ma się rzucać w oczy „wgraj ślad", to właśnie tu.
      // NAD blokiem „Dorzuć swoje": ślad odblokowuje odkrycia całemu składowi,
      // wpis w dzienniku jest miły, ale niczego nie uruchamia. ?>
<?php if (!empty($canUploadTrack)): ?>
<?php renderTrackUpload([
    'eventSlug'  => $k['eventSlug'],
    'editionId'  => (int) $k['editionId'],
    'tracks'     => $editionTracks ?? [],
    'canManage'  => !empty($canManageEvent),
    'isAttendee' => $viewerAttended,
    'viewerId'   => $viewerId ?? null,
    'backTo'     => 'kronika',
]); ?>
<?php endif; ?>

<?php // Sekcja „Dorzuć swoje" / „Ktoś jeszcze tam był?" (dawne .chr-actions
      // na dole strony) już nie istnieje: dodawanie wpisu przeniosło się do
      // nagłówka dziennika, edycja na ołówek przy własnym wpisie, a zaproszenie
      // do sekcji „Pojechali". Każda z tych akcji stoi teraz przy rzeczy,
      // której dotyczy, zamiast czekać za pięćdziesięcioma cudzymi wpisami. ?>

<section class="sec" id="dalej">
    <div class="box">
        <p class="eyebrow" style="margin-top:0;"><?= __('Co dalej') ?></p>
        <h2><?= __('Zobacz, dokąd jadą teraz') ?></h2>
        <div class="op-head__act">
            <a class="btn" href="<?= View::url('/wydarzenia') ?>"><?= __('Nadchodzące wyjazdy') ?></a>
            <?php if ($k['organizerSlug']): ?>
            <a class="btn btn-secondary" href="<?= View::url('/organizatorzy/' . $k['organizerSlug']) ?>"><?= __('Wyjazdy tego organizatora') ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php // Podgląd zdjęcia — wspólny partial (2026-08-22). Kod stąd pochodzi
      // i tu działał; przeniesiony, bo strona wyjazdu i profil organizatora
      // otwierały zdjęcia bez modala i do MINIATURY zamiast do oryginału.
      // Kopiowanie tych czterdziestu linijek na trzy strony byłoby trzecią
      // implementacją tego samego. ?>
<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
