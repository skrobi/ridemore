<?php
// views/web/pages/discovery.php
// RIDEMORE DISCOVERY — /odkrycia. Jeden ekran, dwie zakładki mapy.
//
// STYL KAFLI STATYSTYK — „GRYWALIZACJA" (2026-09-05, zatwierdzone po podglądzie
// obok produkcji). Inspiracja: render przesłany przez usera, gdzie hex jest
// elementem przewodnim kafli statystyk, a każda liczba dostaje własną ikonę
// w heksagonalnej plakietce (`partials/stat-tiles-hex.php` /
// `renderHexStatTiles()` — WYŁĄCZNIE ta strona; profil rowerzysty, strona
// trasy i kronika zostają przy płaskim `stat-tiles.php`/`renderStatTiles()`,
// bo tego wariantu nikt tam jeszcze nie widział ani nie zatwierdził). Poziomy,
// XP i odznaki ŚWIADOMIE pominięte — user wskazał, że tego jeszcze nie ma
// w serwisie. Kolory plakietek to ISTNIEJĄCE tokeny systemu, zero nowych barw.
//
// Zbudowany wg projektu grafika (szablony/odkrywanie.html + makieta PNG).
// Scala dwa wcześniejsze widoki (discovery-mine.php i discovery-community.php)
// — projekt stawia mapę osobistą i wspólną obok siebie jako przełącznik nad tą
// samą mapą, bo to jedno pytanie zadane z dwóch stron.
//
// CZEGO TU NIE MA, MIMO ŻE JEST W PROJEKCIE — i dlaczego:
//   - „% Polski" pod liczbą pól: region w tym serwisie to pozycja słownika,
//     nie geometria, a mianownik z wszystkich pól dałby ułamek promila. Liczba
//     bez pokrycia w danych jest gorsza niż jej brak.
//   - „Następna nagroda: Explorer I": poziomy i XP są wprost wyłączone z tego
//     etapu (§15 briefu).
//   - „Odkrycia w pobliżu" (POI) i „Twoje cele" (misje): j.w.
//   - „Aktywność znajomych": osobny feed, blisko Pulsu — nie jest częścią
//     punktacji, a Puls już odpowiada na to pytanie.
// Układ zostawia na nie miejsce: dołożenie każdego to wstawienie kafla albo
// kolumny, nie przebudowa strony.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Models\DiscoveryScoring;
use Models\PointLedger;
use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
require_once __DIR__ . '/../partials/stat-tiles-hex.php';
require_once __DIR__ . '/../partials/trail-card.php';

$tab = $tab ?? 'all';
$isLoggedIn = $isLoggedIn ?? false;
$summary = $summary ?? null;
$regions = $regions ?? null;
$routes = $routes ?? [];
$rides = $rides ?? [];
$community = $community ?? [];
$discoverers = $discoverers ?? [];
$pointsWeek = $pointsWeek ?? 0;
$regionsWorld = $regionsWorld ?? null;
$regionPotentials = $regionPotentials ?? [];

// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);
$num = static fn($n) => number_format((int) $n, 0, ',', ' ');
$initials = static function (array $u): string {
    $raw = trim((string) ($u['name'] ?? '')) ?: (string) strstr((string) ($u['email'] ?? ''), '@', true);
    return mb_strtoupper(mb_substr($raw !== '' ? $raw : '?', 0, 2));
};

$trailsDone = 0;
foreach ($routes as $r) { if (!empty($r['isComplete'])) { $trailsDone++; } }

// TWOJE REGIONY — „rozpoczęte" (2026-09-05): kafel „Regiony" prowadzi
// tu kotwicą (`#regiony`), user chce zobaczyć zaawansowanie per województwo,
// nie tylko licznik „4 z 16". Region jest „rozpoczęty" wg TEJ SAMEJ definicji
// co sam kafel (`Discovery::regionsForUser` — masz w nim ≥1 odkryty heks),
// więc dwie liczby na stronie nie mogą się rozjechać. W obrębie kraju
// najbardziej zaawansowany pierwszy — pozytywne wzmocnienie, nie alfabet ani
// rozmiar regionu.
//
// GRUPOWANIE PO KRAJU (zgłoszenie usera 2026-09-05: „stwórz region poza
// Polską, zobacz czy pokaże się w statystykach" — pokazywał się, ale
// WMIESZANY w województwa, dolewając heksy do „Polska odkryta %". Test na
// żywo w dev opisany w pamięci sesji; naprawa siedzi w
// `Discovery::regionProgress()` — ten widok tylko GRUPUJE już poprawne dane).
// `$regionsWorld['countries']` przychodzi z modelu POSORTOWANE: kraj domowy
// (Polska) zawsze pierwszy, dalej malejąco wg pokrycia — kolejność krajów tu
// to ta sama kolejność, żeby dwa miejsca w kodzie nie mogły się rozjechać.
// Grupowanie przeniesione do partials/region-emblems.php (2026-09-13) —
// ten sam komponent stoi na profilu rowerzysty.
require_once __DIR__ . '/../partials/region-emblems.php';
$startedByCountry = ($isLoggedIn && $tab === 'me') ? regionEmblemGroups($regionsWorld ?? []) : [];

// ---------------------------------------------------------------
// „NASTĘPNY CEL" — jedna konkretna rzecz do zrobienia, wybierana drabinką
// (2026-08-25, Faza C). Ekran dotąd odpowiadał tylko na „co już zrobiłem";
// motywacja wymaga odpowiedzi na „co mam zrobić dalej". Kolejność drabinki:
//
//   1. TRASA najbliżej następnego progu — ale tylko gdy jest naprawdę
//      „prawie" (brakuje ≤ jednego progu). „Prawie skończyłem" działa
//      mocniej niż „mam 5%". Progi (ile ich jest i co ile %) są od
//      2026-09-03 ustawieniem admina (DiscoveryScoring::trailThresholds()),
//      nie stałą 25/50/75/100 wpisaną tutaj na sztywno.
//   2. KONKRETNY SKARB czekający najdłużej (lista z serwera, bez współrzędnych).
//   3. REGION z największym potencjałem (Discovery::regionPotentials — pola ×
//      stawka + punkty skarbów; score porządkuje ranking, a widok cytuje
//      SKŁADNIKI, bo sumy nie wolno obiecywać jak gwarancji).
//   4. Start nowej trasy / zaproszenie na wyjazd — dla początkujących.
//
// Cel nie powtarza liczb z kafli: kafle mówią „ile masz", karta mówi „co
// dalej" — ta sama zasada rozdziału, która zdjmowała duplikaty z tej strony
// przy okazjach 2026-08-14/20/24.
// ---------------------------------------------------------------
$goalThresholds = DiscoveryScoring::trailThresholds();
// Rozstaw między progami = ile p.p. znaczy „jeden krok". Progi są równo
// rozstawione (patrz trailThresholds()), więc wystarczy pierwszy z listy —
// przy 4 progach to 25, ale liczba progów jest ustawieniem admina, nie stałą.
$goalStepGap = $goalThresholds[0] ?? 100;
$goal = null;
if ($isLoggedIn && $tab === 'me') {
    $bestTrail = null;
    foreach ($routes as $route) {
        $pct = (int) ($route['pct'] ?? 0);
        if ($pct <= 0 || $pct >= 100) { continue; }
        $nextTh = 100;
        foreach ($goalThresholds as $th) {
            if ($pct < $th) { $nextTh = $th; break; }
        }
        $leftPct = $nextTh - $pct;
        if ($leftPct > $goalStepGap) { continue; } // za daleko od celu — to nie jest „prawie"
        if ($bestTrail === null || $leftPct < $bestTrail['leftPct']) {
            $bestTrail = ['route' => $route, 'nextTh' => $nextTh, 'leftPct' => $leftPct];
        }
    }

    if ($bestTrail !== null) {
        $r = $bestTrail['route'];
        $metaParts = [__('do progu {prog}% — brakuje {ile}%', ['prog' => $bestTrail['nextTh'], 'ile' => $bestTrail['leftPct']])];
        if ((float) ($r['distance_km'] ?? 0) > 0) {
            $metaParts[] = __('{km} trasy', ['km' => Format::distance((float) $r['distance_km'])]);
        }
        $goal = [
            'type' => 'trail',
            'title' => $r['name'],
            'small' => __('{n}% progu', ['n' => $bestTrail['nextTh']]),
            'bar' => min(100, (int) $r['pct']),
            'meta' => implode(' · ', $metaParts),
            'cta' => View::url('/trasy/' . $r['slug']),
            'ctaLabel' => __('Odkrywaj'),
            'photo' => $r['cover_photo_url'] ?? null,
        ];
    } elseif (!empty($treasuresWaiting)) {
        // waitingList() sortuje po czasie czekania — bierzemy pierwszego.
        $tw = $treasuresWaiting[0];
        $goal = [
            'type' => 'treasure',
            // ID, a nie współrzędne — przycisk karty korzysta z tej samej
            // bramki ujawnienia co wiersze panelu skarbów (`/api/treasures/{id}`
            // pyta o pozycję dopiero po kliknięciu). Bez tego „Następny cel"
            // umiał tylko przewinąć do mapy i zostawić szukanie userowi
            // (zgłoszenie 2026-09-03).
            'treasureId' => (int) $tw['id'],
            'title' => $tw['name'],
            'small' => __('skarb czeka'),
            'bar' => null,
            'meta' => ($tw['days'] === 0 ? __('dodany dziś')
                    : __n((int) $tw['days'], 'czeka {n} dzień', 'czeka {n} dni', 'czeka {n} dni'))
                . (($tw['region'] ?? '') !== '' ? ' · ' . $tw['region'] : ''),
            'cta' => '#mapa',
            'ctaLabel' => __('Pokaż skarb na mapie'),
            'photo' => $tw['photo'] ?? null,
        ];
    } elseif (!empty($regionPotentials)) {
        $rp = $regionPotentials[0];
        $metaParts = [__n((int) $rp['cellsLeft'], '{x} nieodkryte pole', '{x} nieodkryte pola', '{x} nieodkrytych pól', ['x' => $num($rp['cellsLeft'])])];
        if ($rp['treasures'] > 0) {
            $metaParts[] = __n((int) $rp['treasures'], '{x} skarb ({p} pkt)', '{x} skarby ({p} pkt)', '{x} skarbów ({p} pkt)', ['x' => $num($rp['treasures']), 'p' => $num($rp['treasurePoints'])]);
        }
        if ($rp['routes'] > 0) {
            $metaParts[] = __n((int) $rp['routes'], '{x} znana trasa', '{x} znane trasy', '{x} znanych tras', ['x' => $num($rp['routes'])]);
        }
        $goal = [
            'type' => 'region',
            'title' => $rp['name'],
            'small' => __('teren do odkrycia'),
            'bar' => $rp['total'] > 0 ? round($rp['mine'] / $rp['total'] * 100, 1) : null,
            'meta' => implode(' · ', $metaParts),
            'cta' => $rp['routes'] > 0 ? View::url('/trasy') : '#mapa',
            'ctaLabel' => $rp['routes'] > 0 ? __('Zobacz trasy') : __('Znajdź na mapie'),
        ];
    } elseif (!empty($routes)) {
        $goal = [
            'type' => 'start',
            'title' => $routes[0]['name'],
            'small' => __('zacznij trasę'),
            'bar' => null,
            'meta' => __('Jeszcze jej nie masz — pola i punkty przyjdą same z przejazdu.'),
            'cta' => View::url('/trasy/' . $routes[0]['slug']),
            'ctaLabel' => __('Odkrywaj'),
            'photo' => $routes[0]['cover_photo_url'] ?? null,
        ];
    } else {
        $goal = [
            'type' => 'event',
            'title' => __('Dołącz do wyjazdu'),
            'small' => 'pierwszy krok',
            'bar' => null,
            'meta' => __('Odkrycia liczą się ze śladu z odbytego wyjazdu — najpierw trzeba pojechać.'),
            'cta' => View::url('/wydarzenia'),
            'ctaLabel' => __('Znajdź wydarzenie'),
        ];
    }
}

// SORTOWANIE TRAS POD CELE (Faza C): w toku wg malejącego postępu na początku,
// potem ukończone i nietknięte. Na mapie społeczności kolejność oryginalna —
// tam katalog ma być neutralny, a nie „Twoje cele".
$sortedRoutes = $routes;
if ($isLoggedIn && $tab === 'me') {
    $rankOf = static function (array $r): int {
        $pct = (int) ($r['pct'] ?? 0);
        if ($pct >= 100) { return 1; }
        if ($pct > 0) { return 0; }
        return 2;
    };
    usort($sortedRoutes, static function (array $a, array $b) use ($rankOf): int {
        $ra = $rankOf($a); $rb = $rankOf($b);
        if ($ra !== $rb) { return $ra <=> $rb; }
        if ($ra === 0) { return ((int) ($b['pct'] ?? 0)) <=> ((int) ($a['pct'] ?? 0)); }
        return 0;
    });
}

// Procent Polski z PRAWDZIWEGO mianownika (migr. 070/071). Dotąd zakazany,
// bo mianownik nie istniał — teraz istnieje, więc liczba wraca jako uczciwa.
// Format z przecinkiem, bez zbędnych zer.
$polandPct = number_format((float) ($regionsWorld['grand']['pctCommunity'] ?? 0), 1, ',', '');
$polandPct = rtrim(rtrim($polandPct, '0'), ',');
if ($polandPct === '') { $polandPct = '0'; }
?>

<?php // Bez karty społeczności hero zostaje z JEDNĄ kolumną. Bez tego
      // modyfikatora siatka trzymała drugą, pustą kolumnę (1.6fr .95fr)
      // i nagłówek zajmował ok. 62% szerokości, a obok ziała dziura. ?>
<?php $heroSolo = $isLoggedIn && $tab !== 'me'; ?>
<section class="disc-hero<?= $heroSolo ? ' disc-hero--solo' : '' ?>">
    <div class="disc-hero__main">
        <h1><?= __('Odkrywaj świat na rowerze') ?></h1>
        <p class="disc-hero__lead">
            <?= __('Każdy Twój przejazd odkrywa nowe miejsca na mapie. Zbieraj punkty, zaliczaj trasy
            i odkrywaj razem z innymi.') ?>

        </p>
        <?php // JEDEN PRZEŁĄCZNIK KONTEKSTU NA STRONĘ (uwaga usera 2026-08-14).
              //
              // Stało tu „Moje odkrycia" — przycisk prowadzący na /odkrycia,
              // czyli na stronę, na której się właśnie jest — a w ciemnej karcie
              // obok „Zobacz aktywność społeczności". Razem z zakładkami nad
              // mapą dawało to DWA przełączniki tego samego, ustawione w dwóch
              // miejscach i wyglądające na dwie różne funkcje.
              //
              // Zostaje jeden, niżej (.disc-tabs), i przełącza teraz CAŁY
              // kontekst strony — statystyki razem z mapą. Tutaj zostają
              // wyłącznie akcje, które NIE są przełączaniem widoku. ?>
        <div class="op-head__act">
            <?php if (!$isLoggedIn): ?>
            <a class="btn" href="<?= View::url('/rejestracja') ?>"><?= __('Dołącz i zacznij odkrywać') ?></a>
            <?php endif; ?>
            <a class="btn<?= $isLoggedIn ? '' : ' btn-secondary' ?>" href="<?= View::url('/wydarzenia') ?>"><?= __('Znajdź wyjazd') ?></a>
            <?php if ($isLoggedIn): ?>
            <?php // ZALICZENIE Z LOKALIZACJI (SKA/6) — droga dla skarbu, przy
                  // którym nie ma już wlepki albo nie da się jej zeskanować
                  // (zerwana, zamarznięty ekran, telefon bez aparatu).
                  //
                  // Przycisk NIE jest ukryty za wykryciem urządzenia mobilnego.
                  // Na desktopie po prostu nie zadziała, bo przeglądarka poda
                  // lokalizację z dokładnością do miasta i serwer odrzuci
                  // odległość — a to lepsze niż ukrywanie funkcji przed kimś,
                  // kto ma laptop z GPS-em albo telefon w trybie desktop. ?>
            <?php // Wejscie do zglaszania (SKA/4). Stoi obok „Jestem przy
                  // skarbie", bo to ta sama sytuacja z dwoma wyjsciami: stoje
                  // w terenie i albo cos tu juz jest, albo wlasnie znalazlem
                  // miejsce, ktorego jeszcze nie ma na mapie. ?>
            <a class="btn btn-secondary" href="<?= View::url('/skarby/zglos') ?>"><?= __('Zgłoś miejsce') ?></a>
            <button type="button" class="btn btn-secondary" id="skarbTutaj"
                    data-url="<?= htmlspecialchars(View::url('/api/treasures/claim')) ?>"
                    data-csrf="<?= htmlspecialchars(Core\Csrf::token()) ?>"><?= __('Jestem przy skarbie') ?></button>
            <?php endif; ?>
        </div>
    </div>

    <?php // RAZEM ODKRYLIŚMY — element mówiący o społeczności, zanim jeszcze
          // spojrzysz na mapę. Ciemna karta ze zdjęciem, bo ma być punktem
          // ciężkości, a nie kolejnym kaflem.
          //
          // NIE POKAZUJEMY GO NA ZAKŁADCE SPOŁECZNOŚCI: pasek statystyk niesie
          // wtedy dokładnie tę samą liczbę pól, a ta sama liczba dwa razy na
          // jednym ekranie czyta się jak dwie różne metryki. Na zakładce
          // osobistej karta jest kontrapunktem („tyle Ty, tyle my"), więc ma
          // sens; dla gościa jest jedynym miejscem, gdzie ta liczba pada
          // z odpowiednią wagą. ?>
    <?php // PRAWA KOLUMNA HERO — zależy od tego, KTO patrzy:
      // zalogowany na mapie osobistej dostaje kartę NASTĘPNEGO CELU (ciemna,
      // bo ma być punktem ciężkości — ten sam ciężar, który wcześniej niósł
      // „Razem odkryliśmy"), a gość kartę społeczną. Na zakładce wspólnej
      // żadnej karty nie ma: pasek statystyk niesie dokładnie te liczby i
      // ta sama liczba dwa razy czyta się jak dwie różne metryki. ?>
    <?php if ($isLoggedIn && $tab === 'me' && $goal !== null): ?>
    <?php // ZDJĘCIE CELU W „MGLE" — HEX NIEREGULARNY (2026-09-05, druga
          // iteracja tego samego dnia: pierwsza wersja robiła plakietkę 20%
          // szerokości karty — uwaga usera „w tak małym hex chcesz umieścić
          // zdjęcie?!"). Miniatura rośnie do ~40% i PRZELEWA SIĘ za krawędź
          // karty (ujemne marginesy = padding karty, przycięte przez
          // `.disc-social{overflow:hidden}`) zamiast stać w środku z marginesem
          // — to ten sam zabieg, którym duże, pełnowymiarowe zdjęcie wygrywa
          // z małą, grzeczną plakietką. Kształt to NIEREGULARNY siedmiokąt
          // hexo-podobny (`clip-path`, 7 wierzchołków zamiast 6 — user: „może
          // być hex nieregularny"), nie idealny sześciokąt z reszty serwisu:
          // ten jeden akcent ma wyglądać jak coś WYRWANE Z MGŁY, nie jak
          // kolejna plakietka.
          //
          // MGŁA JEST DOSŁOWNA: `.disc-goal__photo::after` kładzie radialny
          // gradient od przezroczystego środka do koloru karty na brzegach —
          // to ta sama metafora, którą mapa Discovery już ma („fog of war",
          // `md/features.md` → Discovery Grid): odkryty fragment wyłania się
          // z szarości zamiast być wyciętym prostokątem ze zdjęcia. Działa
          // TAK SAMO bez zdjęcia — `--empty` dostaje sam ten gradient plus
          // wyblakły `Icon::render('hex')` na środku (ten sam znak co
          // wszędzie indziej w serwisie dla „pola do odkrycia"), więc pusty
          // stan czyta się jako „tu jeszcze nic nie odkryto", nie jak błąd
          // ładowania obrazka. ?>
    <aside class="disc-social disc-goal">
        <div class="disc-goal__body">
            <span class="disc-social__lbl"><?= __('Następny cel') ?></span>
            <strong class="disc-social__num"><?= htmlspecialchars($goal['title']) ?>
                <small><?= htmlspecialchars($goal['small']) ?></small></strong>
            <?php if ($goal['bar'] !== null): ?>
            <div class="disc-goal__bar" role="presentation"><i style="width:<?= max(2, min(100, (float) $goal['bar'])) ?>%"></i></div>
            <?php endif; ?>
            <p class="disc-social__sub"><?= htmlspecialchars($goal['meta']) ?></p>
            <?php // `data-treasure` = ten sam uchwyt, co wiersze panelu skarbów:
                  // wspólna obsługa niżej dociąga pozycję i przesuwa kadr. `href`
                  // zostaje `#mapa`, żeby bez JS przycisk dalej robił to, co robił
                  // (przewinięcie do mapy). ?>
            <a class="btn" href="<?= htmlspecialchars($goal['cta']) ?>"<?= isset($goal['treasureId']) ? ' data-treasure="' . (int) $goal['treasureId'] . '"' : '' ?>><?= htmlspecialchars($goal['ctaLabel']) ?></a>
        </div>
        <?php if (!empty($goal['photo'])): ?>
        <span class="disc-goal__photo" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($goal['photo'], 'card')) ?>')" aria-hidden="true"></span>
        <?php else: ?>
        <span class="disc-goal__photo disc-goal__photo--empty" aria-hidden="true"><?= Utils\Icon::render('hex') ?></span>
        <?php endif; ?>
    </aside>
    <?php elseif (!$isLoggedIn || $tab === 'me'): ?>
    <aside class="disc-social">
        <span class="disc-social__lbl"><?= __('Razem odkryliśmy') ?></span>
        <strong class="disc-social__num"><span class="hexn"><?= Utils\Icon::render('hex') ?><?= $num($community['cells'] ?? 0) ?></span>
            <small><?= $plural((int) ($community['cells'] ?? 0), 'pole', 'pola', 'pól') ?></small></strong>
        <p class="disc-social__sub">
            <?php if (($community['cellsRecent'] ?? 0) > 0): ?>
            <?= __('W ostatnim miesiącu społeczność ridemore odkryła') ?>

            <b><?= $num($community['cellsRecent']) ?></b>
            <?= $plural((int) $community['cellsRecent'], 'nowe pole', 'nowe pola', __('nowych pól')) ?>.
            <?php else: ?>
            <?= __('Mapa czeka na pierwsze przejazdy. Każdy ślad zdejmuje z niej kawałek szarości.') ?>

            <?php endif; ?>
        </p>
        <?php if ($discoverers): ?>
        <span class="disc-social__faces">
            <?php foreach ($discoverers as $u): ?>
            <?php
                // Zgłoszenie usera 2026-09-10: te kółka nie linkowały nigdzie,
                // mimo że Discovery::recentDiscoverers() już zwraca
                // public_slug — brakowało tylko owinięcia w widoku. Bez sluga
                // (konto bez publicznego profilu) zostaje jak dziś: bez linku.
                $discovererUrl = !empty($u['public_slug'])
                    ? View::url('/rowerzysta/' . $u['public_slug'])
                    : null;
            ?>
            <?php if ($discovererUrl): ?><a href="<?= htmlspecialchars($discovererUrl) ?>" title="<?= htmlspecialchars($u['name'] ?? '') ?>" style="text-decoration:none;"><?php endif; ?>
            <?php if (!empty($u['avatar_url'])): ?>
            <i style="background-image:url('<?= htmlspecialchars(Utils\Image::src($u['avatar_url'], 'av')) ?>')"></i>
            <?php else: ?>
            <i><?= htmlspecialchars($initials($u)) ?></i>
            <?php endif; ?>
            <?php if ($discovererUrl): ?></a><?php endif; ?>
            <?php endforeach; ?>
        </span>
        <?php endif; ?>
    </aside>
    <?php endif; ?>
</section>

<?php // PRZEŁĄCZNIK KONTEKSTU — JEDEN NA STRONĘ, NAD STATYSTYKAMI.
      //
      // Stał niżej, tuż nad mapą, i przełączał wyłącznie mapę — a pasek
      // statystyk nad nim pokazywał zawsze liczby OSOBISTE, także wtedy, gdy
      // patrzyło się na mapę społeczności. Do tego drugi, konkurencyjny
      // przełącznik siedział w przycisku hero (uwaga usera 2026-08-14).
      //
      // Teraz jedna kontrolka rządzi CAŁYM ekranem, dlatego stoi wyżej: nad
      // tym, co przełącza. Zakładka poniżej statystyk sugerowałaby, że ich nie
      // dotyczy — i dokładnie tak było.
      //
      // Gość widzi tylko wspólną (swojej nie ma), więc przełącznik z jedną
      // nieczynną pozycją byłby obietnicą bez pokrycia. ?>
<?php if ($isLoggedIn): ?>
<div class="disc-tabs disc-tabs--main">
    <a class="disc-tab<?= $tab === 'me' ? ' is-on' : '' ?>" href="<?= View::url('/odkrycia') ?>"><?= __('Mapa osobista') ?></a>
    <a class="disc-tab<?= $tab === 'all' ? ' is-on' : '' ?>" href="<?= View::url('/odkrycia') ?>?mapa=spolecznosc"><?= __('Mapa społeczności') ?></a>
</div>
<?php endif; ?>

<?php // PASEK STATYSTYK — `partials/stat-tiles-hex.php` / `renderHexStatTiles()`
      // (2026-09-05, wariant „grywalizacja" — patrz nagłówek pliku). Do
      // 2026-09-05 ta strona dzieliła jeden wspólny partial `stat-tiles.php`
      // z profilem rowerzysty, stroną trasy i kroniką (zgłoszenie usera
      // 2026-08-20: „na każdej stronie wygląda inaczej"); te trzy zostają przy
      // nim — tylko /odkrycia dostało nowy, hexowy wariant.
      //
      // TREŚĆ ZALEŻY OD ZAKŁADKI. Wcześniej pasek pokazywał liczby osobiste
      // niezależnie od tego, którą mapę się ogląda — na zakładce społeczności
      // „Twoje odkrycia" nad wspólną mapą podpowiadały, że mapa jest o Tobie. ?>
<?php if ($tab === 'all'): ?>
<?php // ZAKŁADKA WSPÓLNA — lead = procent Polski z PRAWDZIWEGO mianownika
      // (region_cell_counts). Ta liczba była tu zakazana, dopóki mianownik
      // nie istniał; teraz jest uczciwa i odpowiada na „ile świata przed nami".
      // Kolumny: lead bierze cały pierwszy rząd, pięć pozostałych idzie w
      // rzędzie 5-kolumnowym. ?>
<?php renderHexStatTiles([
    ['lbl' => __('Polska odkryta'), 'val' => $polandPct . '%', 'lead' => true, 'icon' => 'hex', 'tone' => 'pola',
     'sub' => __('{a} z {b} pól kraju', ['a' => $num($regionsWorld['grand']['community'] ?? 0), 'b' => $num($regionsWorld['grand']['total'] ?? 0)])],
    ['lbl' => __('Odkryte pola'), 'val' => $num($community['cells'] ?? 0), 'hex' => true, 'icon' => 'hex', 'tone' => 'pola',
     'sub' => __('na wspólnej mapie')],
    ['lbl' => __('W ostatnim miesiącu'), 'val' => $num($community['cellsRecent'] ?? 0), 'hex' => true, 'icon' => 'hex', 'tone' => 'teren',
     'sub' => __('nowych pól')],
    ['lbl' => __('Odkrywcy'), 'val' => $num($community['riders'] ?? 0), 'icon' => 'users', 'tone' => 'regiony',
     'sub' => __('osób z przejazdem ze śladem')],
    ['lbl' => __('Znane trasy'), 'val' => count($routes), 'icon' => 'route', 'tone' => 'trasy', 'sub' => __('w katalogu do zaliczenia')],
    // Skarby po stronie spolecznosci pokazuja CZEKAJACE, nie znalezione (patrz
    // Models\Treasure::statsCommunity) — liczba, ktora zaprasza zamiast
    // informowac, ze sie spoznil.
    ['lbl' => __('Skarby'), 'val' => $num($treasuresAll['waiting'] ?? 0), 'icon' => 'coins', 'tone' => 'skarby',
     'small' => __('z {n}', ['n' => $num($treasuresAll['total'] ?? 0)]),
     'sub' => __('czeka na pierwsze znalezienie')],
], ['cols' => 5]); ?>
<?php else: ?>
<?php // ZAKŁADKA OSOBISTA — poprawka po uwadze usera (2026-08-25, „uciąłeś
      // dużą część statystyki"): wraca PEŁNY zestaw odkrywcy — pola, nowy
      // teren, regiony, skarby, trasy — a lead z punktami przestaje być pustą
      // bandą, bo dostaje ROZBICIE „za co" prosto z rejestru
      // (PointLedger::breakdownForUser przez summaryForUser; nazwy z LABELS).
      // Układ: lead bierze cały pierwszy rząd, pięć kafli wypełnia drugi
      // (cols=5) — żadnej dziury w siatce.
      //
      // Regiony po migr. 070: „odkryty region" = masz w nim heks
      // (Models\Discovery::regionsForUser), nie region wydarzenia.
      // „Nowy teren" zostaje językiem WKŁADU (decyzja 2026-08-13). ?>
<?php
$pointsBreakdown = '';
if (!empty($summary['pointsBySource']) && $isLoggedIn) {
    $parts = [];
    foreach ($summary['pointsBySource'] as $source => $pointsTotal) {
        $parts[] = \Models\PointLedger::label((string) $source) . ' ' . $num($pointsTotal);
    }
    $pointsBreakdown = implode(' · ', $parts);
    if ($pointsWeek > 0) {
        $pointsBreakdown .= ' · ' . __('+{n} w tym tygodniu', ['n' => $num($pointsWeek)]);
    }
}
if ($pointsBreakdown === '' && $pointsWeek > 0) {
    $pointsBreakdown = __('+{n} w tym tygodniu', ['n' => $num($pointsWeek)]);
}
?>
<?php renderHexStatTiles([
    ['lbl' => __('Twoje punkty'), 'val' => $num($summary['pointsTotal'] ?? 0), 'lead' => true, 'icon' => 'star', 'tone' => 'skarby',
     'sub' => $pointsBreakdown !== ''
        ? __('za co: {co}', ['co' => $pointsBreakdown])
        : ($pointsWeek > 0
            ? __('+{n} w tym tygodniu', ['n' => $num($pointsWeek)])
            : __('za przejazdy, odkrycia i skarby'))],
    ['lbl' => __('Twoje pola'), 'val' => $num($summary['cells'] ?? 0), 'hex' => true, 'icon' => 'hex', 'tone' => 'pola',
     'small' => $plural((int) ($summary['cells'] ?? 0), 'pole', 'pola', 'pól'),
     'sub' => __('na Twojej mapie')],
    ['lbl' => __('Nowy teren'), 'val' => $num($summary['firstInCommunity'] ?? 0), 'hex' => true, 'icon' => 'tre-viewpoint', 'tone' => 'teren',
     'sub' => __('pól dołożonych do wspólnej mapy')],
    ['lbl' => __('Regiony'), 'val' => (int) ($regions['visited'] ?? 0), 'icon' => 'pin', 'tone' => 'regiony',
     'small' => __('z {n}', ['n' => (int) ($regions['total'] ?? 0)]),
     'sub' => __('województw z odkrytym heksem'),
     // KOTWICA DO EMBLEMATÓW — tylko gdy jest co pokazać niżej; bez tego
     // kafel obiecywałby sekcję, która akurat nie istnieje (0 rozpoczętych).
     'href' => $startedByCountry ? '#regiony' : null],
    ['lbl' => __('Skarby'), 'val' => $num($treasures['found'] ?? 0), 'icon' => 'coins', 'tone' => 'skarby',
     'small' => __('z {n}', ['n' => $num($treasures['total'] ?? 0)]),
     'sub' => $isLoggedIn ? __('znalezionych w terenie') : __('do znalezienia w terenie')],
    ['lbl' => __('Znane trasy'), 'val' => $trailsDone, 'icon' => 'route', 'tone' => 'trasy',
     'small' => __('z {n}', ['n' => count($routes)]),
     'done' => count($routes) > 0 && $trailsDone === count($routes),
     'sub' => __('ukończonych — lista pod mapą')],
], ['cols' => 5]); ?>
<?php endif; ?>

<section class="sec" id="mapa">
    <?php // Kotwica #mapa — cel CTA karty celu dla skarbów i regionów bez
          // własnej trasy w okolicy: przycisk ma mieć DOKĄD zaprowadzić na
          // tej samej stronie, bez udawania nawigacji, której nie ma. ?>
    <?php // NAGŁÓWKA NAD MAPĄ NIE MA (2026-08-24, uwaga usera: „można usunąć
          // »Twoja mapa odkryć« i zwiększyć delikatnie taby, bo to ta sama nazwa,
          // więc taby powinny być wyjściowe do przełączania i informowania").
          // Zakładka wyżej mówi dokładnie to samo, a dwa razy to samo zdanie
          // zabierało pionowe miejsce ekranowi, na którym liczy się mapa. ?>

    <?php // Przełącznik zalogowanego stoi WYŻEJ, nad statystykami — rządzi
          // całym ekranem, nie samą mapą. Tutaj zostaje wyłącznie etykieta dla
          // gościa, który innej mapy nie ma i nie ma między czym wybierać. ?>
    <?php if (!$isLoggedIn): ?>
    <div class="disc-tabs">
        <span class="disc-tab is-on"><?= __('Mapa społeczności') ?></span>
    </div>
    <?php endif; ?>

    <div class="disc-mapwrap">
        <div class="disc-map">
            <div id="discoveryMap" class="disc-map__canvas"></div>

            <?php // PANELE STOJĄ W KOLUMNIE (2026-08-24). Do tej daty każdy był
                  // osobną nakładką przyklejoną do rogu mapy, więc dwa naraz
                  // leżałyby jeden na drugim — a mapa społeczności ma teraz
                  // i przejazdy, i skarby. Kolumna układa je pod sobą na
                  // desktopie i piętrzy jako paski szuflad na telefonie. ?>
            <div class="disc-panels">
            <?php // OSTATNIE PRZEJAZDY — ten sam moduł na OBU mapach
                  // (partials/ride-feed.php); na społeczności dokłada autora.
                  // Zastąpił listę samych naliczeń punktowych: ta nie miała ani
                  // daty, ani odkrytych pól, ani sposobu, żeby w nią kliknąć. ?>
            <?php
            $feedRides = $rides;
            $feedShowRider = $tab === 'all';
            require __DIR__ . '/../partials/ride-feed.php';
            ?>

            <?php // SKARBY — tylko na mapie społeczności: na własnej ten róg
                  // zajmują przejazdy, a dwa panele naraz na jednej mapie to
                  // dwie listy walczące o to samo miejsce. ?>
            <?php if ($tab === 'all' && ($treasuresWaiting || $treasureTop)): ?>
            <?php // data-drawer — podpis uchwytu szuflady na telefonie. Ten panel
                  // jako jedyny nie ma <h3> (zaczyna się od zakładek), więc bez
                  // tego uchwyt nie miałby czym się przedstawić. ?>
            <aside class="disc-panel" id="discTreasurePanel" data-drawer="Skarby w pobliżu"
                   data-layer-panel="treasures,treasuresFound">
                <?php // DWIE ZAKŁADKI (2026-08-20, zgłoszenie usera: „nie da się
                      // na nie kliknąć, więc trudno je zlokalizować; fajnie by
                      // było, jakby był tab i w drugim »W obszarze«").
                      //
                      // „Czekają" odpowiada na pytanie o CZAS („co stoi
                      // nietknięte najdłużej") i nie zależy od kadru — to lista
                      // z serwera. „W obszarze" odpowiada na pytanie o MIEJSCE
                      // („co jest tu, gdzie patrzę") i przeliczą się przy każdym
                      // przesunięciu mapy, z tej samej odpowiedzi, którą mapa
                      // i tak pobiera dla swoich pinezek — bez drugiego żądania.
                      //
                      // Wiersze są PRZYCISKAMI, nie linkami: kliknięcie nie
                      // prowadzi na inną stronę, tylko przesuwa kadr i otwiera
                      // dymek. Klawiatura dostaje to za darmo. ?>
                <div class="disc-panel__tabs" role="tablist">
                    <button type="button" class="is-on" data-pane="czekaja"><?= __('Czekają') ?></button>
                    <button type="button" data-pane="obszar"><?= __('W obszarze') ?></button>
                </div>

                <div data-pane-body="czekaja">
                    <?php if ($treasuresWaiting): ?>
                    <?php foreach ($treasuresWaiting as $tw): ?>
                    <button type="button" class="disc-panel__row" data-treasure="<?= (int) $tw['id'] ?>">
                        <b><?= Utils\Icon::maybe($tw['icon']) ?><?= htmlspecialchars($tw['name']) ?>
                            <span><?= $tw['days'] === 0 ? __('dziś') : __('{n} dni', ['n' => $tw['days']]) ?></span></b>
                        <span><?= htmlspecialchars(implode(' · ', array_filter([$tw['region'], $tw['category']])) ?: __('nikt jeszcze nie dojechał')) ?></span>
                    </button>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <p class="disc-panel__empty"><?= __('Wszystkie znalezione — kolejne pojawią się wkrótce.') ?></p>
                    <?php endif; ?>
                    <?php if ($treasureTop): ?>
                    <div class="disc-panel__sum"><?= __('Najczęściej znajdowany:') ?>

                        <?= Utils\Icon::maybe($treasureTop['icon']) ?><?= htmlspecialchars($treasureTop['name']) ?>
                        · <?= (int) $treasureTop['finders'] ?> <?= $plural((int) $treasureTop['finders'], 'osoba', 'osoby', 'osób') ?></div>
                    <?php endif; ?>
                </div>

                <div data-pane-body="obszar" hidden>
                    <div id="discAreaList"></div>
                    <p class="disc-panel__empty" id="discAreaEmpty"><?= __('Przesuń mapę — pokażę, co leży w tym kadrze.') ?></p>
                </div>
            </aside>
            <?php endif; ?>
            </div><?php // koniec .disc-panels ?>

            <?php // WARSTWY — ZWIJANE, w prawym DOLNYM rogu (poprawka 2026-08-14).
                  //
                  // Pierwsza wersja była stałym stosem checkboxów w prawym GÓRNYM
                  // rogu, czyli dokładnie tam, gdzie stoi „Ostatnia aktywność" —
                  // dwie nakładki na sobie. Przy okazji user zadał właściwe
                  // pytanie: „co jeśli dojdą nowe warstwy". Stały stos nie
                  // skaluje się w żadnym rogu — każda kolejna pozycja zjada
                  // kawałek mapy na stałe, także wtedy, gdy nikt jej nie używa.
                  //
                  // Zwinięta kontrolka kosztuje jeden przycisk niezależnie od
                  // liczby warstw; rozwinięta ma własne przewijanie. To ten sam
                  // wzorzec, który mają wszystkie mapy, z których ludzie
                  // korzystają na co dzień, więc nie trzeba go tłumaczyć.
                  //
                  // <details>, nie własny JS: otwieranie/zamykanie, obsługa
                  // klawiatury i stan `open` są w przeglądarce za darmo.
                  //
                  // MAPA DZIELI SIĘ NA DWIE KOLUMNY, nie na cztery rogi:
                  //   LEWA  — sterowanie: powiększanie (Leaflet), warstwy pod
                  //           nim, legenda na dole,
                  //   PRAWA — informacja: „Ostatnia aktywność" i co przyjdzie
                  //           po niej.
                  // Podział na rogi wyglądał porządnie, ale nie działał:
                  // „Ostatnia aktywność" zwisa z prawej góry na 385 px, a panel
                  // warstw rozwijał się z prawego dołu w górę — spotykały się
                  // w środku prawej kolumny (zmierzone: 236×121 px). Dwie
                  // nakładki w jednej kolumnie będą się bić zawsze.
                  // Nowa nakładka wybiera KOLUMNĘ wg tego, czy steruje, czy
                  // informuje.
                  //
                  // Sam blok od 2026-08-19 jest w partialu `map-layers.php` —
                  // JEDNA kontrolka warstw na całą aplikację. Drzewo, podpisy
                  // i stan domyślny liczy od Etapu 2 `Models\MapLayer` ze
                  // słownika (`DiscoveryController::index`); tej stronie
                  // zostaje wyłącznie PRZEKAZANIE gotowego `$mapLayers`. ?>
            <?php
            $mlId = 'discoveryLayers';
            $mlLayers = $mapLayers;
            $mlDefaultLayers = $mapLayersDefault;
            require __DIR__ . '/../partials/map-layers.php';
            ?>

            <?php // LEGENDA — nakładka w dolnym rogu mapy, nie pasek pod nią.
                  // Wypełnia ją discovery-map.js, bo tylko on wie, ile stopni
                  // skali ma sens przy tym, co widać w kadrze. ?>
            <div id="discoveryLegend" class="hex-legend hex-legend--onmap"></div>
        </div>

        <div class="disc-mapfoot">
            <span id="discoveryEmpty" hidden><?= __('Cała mapa jest jeszcze szara — pierwszy potwierdzony
                przejazd odsłoni na niej pierwszy skrawek terenu.') ?></span>
            <span><?= __('Szare pola czekają na odkrycie. Jedź, wgrywaj ślady i odkrywaj świat razem z innymi.') ?></span>
            <?php // Warunek na `$mapSources['slady']`, bo od Etapu 1b to właśnie
                  // ta warstwa rysuje własne ślady na zakładce „moja" (dawniej
                  // osobne `$tileTracksMe`, już nieistniejące). ?>
            <?php if ($tab === 'me' && !empty($mapSources['slady'])): ?>
            <span><?= __('Twoje ślady z wyjazdów rysują się zielonymi liniami — klik w przejazd na liście
                podświetla go na mapie.') ?></span>
            <?php endif; ?>
            <a href="<?= View::url('/jak-to-dziala') ?>"><?= __('Jak to działa →') ?></a>
        </div>
    </div>
</section>

<?php if ($routes): ?>
<section class="sec" id="znane-trasy">
    <div class="sec-head">
        <h2><?= $isLoggedIn && $tab === 'me' ? __('Twoje trasy') : __('Znane trasy') ?></h2>
        <span><?= $isLoggedIn ? ($tab === 'me'
                ? __('Najbliższe celu na początku — zaliczają się same ze śladu przejazdu')
                : __('Zaliczają się same, gdy Twoje przejazdy pokryją kolejne fragmenty'))
            : __('Załóż konto, żeby śledzić postęp') ?></span>
    </div>
    <div class="disc-trails">
        <?php foreach (array_slice($sortedRoutes, 0, 5) as $route): ?>
        <?php // Karta = WSPÓLNY PARTIAL (2026-09-10). Wcześniej stała tu kopia
              // tego samego bloku co w `trails.php`; mechanizm „prawie" miała
              // tylko ta kopia, choć nie ma powodu, żeby dotyczył jednego ekranu. ?>
        <?php renderTrailCard($route, (bool) $isLoggedIn, ['near' => true]); ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php // TWOJE REGIONY — emblematy, GRUPOWANE PO KRAJU (2026-09-05).
      // Cel kafla „Regiony" wyżej: user chciał zobaczyć NIE TYLKO „4 z 16",
      // ale zaawansowanie W KAŻDYM regionie z osobna — i żeby to wyglądało jak
      // coś do kolekcjonowania (inspiracja: plakietki „Ostatnie odznaki"
      // z rendera), nie jak kolejny wiersz tabeli. Hex jest tu WYPEŁNIANY
      // procentem odkrycia — ten sam motyw przewodni co w kaflach statystyk
      // wyżej, tylko jako miernik, nie tylko ikona.
      //
      // GRUPA NA KRAJ, bo płaski spis „16 województw + Słowacja bez etykiety"
      // czyta się jak błąd, nie jak dwa różne kraje — dokładnie to user
      // zobaczył przy własnym teście. `$startedByCountry` niesie już gotową
      // decyzję z PHP wyżej, KTÓRE grupy dostają osobny nagłówek kraju
      // (`showHeader`): kraj z realną podgrupą regionów (Polska) — tak; kraj
      // płaski, który jest sam swoim jedynym regionem (Słowacja dziś) — nie,
      // żeby ta sama nazwa i procent nie stały na ekranie dwa razy.
      //
      // Sekcja znika, gdy nie ma czego pokazać (0 rozpoczętych), zamiast
      // obiecywać kolekcję, w której nie ma ani jednej pozycji. ?>
<?php if ($startedByCountry): ?>
<section class="sec" id="regiony">
    <div class="sec-head">
        <h2><?= __('Twoje regiony') ?></h2>
        <span><?= __('Zaawansowanie w każdym regionie, w którym masz choć jeden odkryty heks') ?></span>
    </div>
    <?php renderRegionEmblems($startedByCountry); ?>
</section>
<?php endif; ?>

<?php // TWOJE ostatnie wyjazdy — treść na wskroś osobista, więc pokazywana
      // wyłącznie na zakładce osobistej. Pod mapą społeczności była tą samą
      // niespójnością co osobiste liczby nad nią: kontekst mówił „my", a sekcja
      // odpowiadała „ty". „Znane trasy" zostają w obu, bo katalog szlaków jest
      // wspólny — na zakładce osobistej dochodzi do niego tylko Twój postęp. ?>
<?php // SEKCJI „CO DAŁY OSTATNIE WYJAZDY" JUŻ TU NIE MA (2026-08-24, uwaga
      // usera: „to powinno być na mapie w »Ostatnia aktywność«, w tym momencie
      // mamy powielanie informacji"). Te same przejazdy opisywały dwa miejsca,
      // każde po połowie: panel miał punkty bez dat i pól, karty miały daty
      // i pola bez związku z mapą. Jedna lista w panelu ma wszystko i prowadzi
      // tam, gdzie ten przejazd był — patrz partials/ride-feed.php. ?>

<?php // RAZEM ODKRYWAMY POLSKĘ (Faza C) — obecność społeczności na zakładce
      // osobistej, gdzie po przeprowadzce karty celu nie ma jej już wcale:
      // „nie robię tego tylko dla siebie" potrzebuje jednej linijki dowodu,
      // nie drugiej mapy. Liczby z regionProgress(null) — ten sam mianownik co
      // procent na zakładce wspólnej, więc przełączenie zakładek NIE zmienia
      // wartości, tylko perspektywę. Na zakładce wspólnej paska nie ma — tam
      // te liczby stoi w kaflach. ?>
<?php if ($isLoggedIn && $tab === 'me' && (int) ($regionsWorld['grand']['total'] ?? 0) > 0): ?>
<section class="sec disc-world">
    <div class="disc-world__box">
        <span class="disc-world__lbl"><?= __('Razem odkrywamy Polskę') ?></span>
        <strong class="disc-world__num"><?= $polandPct ?>%</strong>
        <span class="disc-world__sub">
            <?= __('{a} z {b} pól kraju', ['a' => $num($regionsWorld['grand']['community']), 'b' => $num($regionsWorld['grand']['total'])]) ?>
            · <?= __n((int) ($community['riders'] ?? 0), '{x} rider ze śladem', '{x} riderów ze śladem', '{x} riderów ze śladem', ['x' => $num($community['riders'] ?? 0)]) ?>

        </span>
        <a class="btn btn-secondary btn--sm" href="<?= View::url('/odkrycia') ?>?mapa=spolecznosc#mapa"><?= __('Mapa społeczności') ?></a>
    </div>
</section>
<?php endif; ?>

<div class="disc-cta">
    <div>
        <h3><?= __('Odkrywaj razem z innymi') ?></h3>
        <p><?= __('Dołącz do wydarzenia i jedź z innymi rowerzystami. Razem odkrywamy więcej.') ?></p>
    </div>
    <a class="btn" href="<?= View::url('/wydarzenia') ?>"><?= __('Znajdź wydarzenie') ?></a>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('discoveryLayers');

    var map = ridemoreDiscoveryMap(document.getElementById('discoveryMap'), {
        context: <?= json_encode($tab === 'me' ? 'me' : 'all') ?>,
        treasuresEndpoint: <?= json_encode(View::url('/api/treasures')) ?>,
        <?php // Akcje w dymku skarbu (SKA/3, SKA/6). Przekazujemy je TYLKO
              // zalogowanym — anonim zobaczy dymek z opisem, ale bez przycisku,
              // bo i tak nie miałby czym się podpisać. ?>
        treasureActions: <?= $isLoggedIn ? 'true' : 'false' ?>,
        claimEndpoint: <?= json_encode(View::url('/api/treasures/claim')) ?>,
        confirmEndpoint: <?= json_encode(View::url('/api/treasures/confirm')) ?>,
        csrf: <?= json_encode(Core\Csrf::token()) ?>,
        endpoint: <?= json_encode(View::url('/api/discovery/cells')) ?>,
        <?php // Szlaki jadą KAFLAMI (klucz `kr`) — pełna geometria GPX zamiast
              // linii sklejonej ze środków pól siatki. Adres niesie epokę `?v=`,
              // więc zmiana trasy w panelu unieważnia kafle u wszystkich. ?>
        <?php // ŹRÓDŁA KAFLI — od Etapu 2 gotowe z kontrolera
              // (`Models\MapLayer::tileKeysFor` + `TileCache::urlTemplate`),
              // po jednym wpisie na warstwę `kind: 'tiles'` w drzewie. ?>
        sources: <?= json_encode($mapSources, JSON_UNESCAPED_SLASHES) ?>,
        <?php // Opis składania parametru `stan` z zapalonych dzieci „Skarbów"
              // (Etap 2) — patrz `ridemoreComposeFilter` w discovery-map.js. ?>
        filters: <?= json_encode($mapFilters, JSON_UNESCAPED_SLASHES) ?>,
        <?php // Klik w szlak pyta serwer, co jest pod tym punktem — kafel to
              // obrazek, więc nie ma w co kliknąć. Patrz nota w discovery-map.js. ?>
        trailsHitEndpoint: <?= json_encode(View::url('/api/discovery/trails/at')) ?>,
        <?php // KLIK WE WŁASNY ŚLAD (2026-08-27) — ta sama mechanika co przy
              // szlaku wyżej, ale TYLKO na zakładce osobistej i tylko dla
              // zalogowanego: przejazdy solo rysują się wyłącznie pod kluczem
              // `me` (§27, plik jest surowy i zaczyna się pod domem), więc na
              // wspólnej mapie nie ma w co kliknąć, a endpoint i tak oddałby
              // 404. Podanie go tam byłoby żądaniem po każdym kliknięciu w tło. ?>
        <?php if ($isLoggedIn && $tab === 'me'): ?>
        ridesHitEndpoint: <?= json_encode(View::url('/api/discovery/rides/at')) ?>,
        <?php endif; ?>
        emptyEl: document.getElementById('discoveryEmpty'),
        legendEl: document.getElementById('discoveryLegend'),
        <?php // Lista „W obszarze" w panelu obok mapy karmi się TĄ SAMĄ
              // odpowiedzią, którą mapa rysuje pinezki — zero dodatkowych
              // żądań przy każdym przesunięciu kadru. ?>
        onTreasures: function (dane) { if (window.discObszar) { window.discObszar(dane); } },
        <?php // KADR Z SERWERA — prostokąt faktycznych odkryć (własnych albo
              // wspólnych). Bez niego mapa startowała w środku Polski i pytała
              // o pola dla całego kraju, żeby dopiero z odpowiedzi wywnioskować,
              // gdzie właściwie jeździsz. `zoom` niżej zostaje jako zapas na
              // wypadek pustej mapy (nikt jeszcze nic nie odkrył). ?>
        bounds: <?= json_encode($mapBounds ?? null) ?>,
        zoom: <?= $tab === 'me' ? 8 : 6 ?>,
        <?php // Stan początkowy CZYTANY Z KONTROLKI — jednym wspólnym czytnikiem
              // (Etap 1). Oddaje wyłącznie przełączniki, które ta kontrolka
              // faktycznie ma, więc warstwa bez przełącznika zachowuje domyślną
              // modułu zamiast gasnąć. Tak samo znika ręczne pilnowanie
              // `treasuresFound` dla gościa: nie ma pola, nie ma klucza, więc
              // „Skarby" działają jako jedna warstwa (poprawka z 2026-08-23). ?>
        layers: ridemoreReadLayers(box),
    });

    // OSTATNIE PRZEJAZDY — stronicowanie i „pokaż na mapie". Moduł mieszka
    // w discovery-map.js, bo panel jest nakładką NA MAPIE i ma być do użycia
    // wszędzie tam, gdzie ta mapa stoi; tutaj zostaje jedno wywołanie.
    // `tracks` działa na OBU zakładkach (2026-08-25): klik w czyjąś aktywność
    // na mapie społeczności rysuje jego przejechaną trasę tak samo, jak klik
    // we własną na osobistej — wyjazdy przez ślad efektywny, solo przez
    // gpx_url przejazdu, ale TYLKO WŁAŚCICIELOWI (§27, 2026-08-26; plik solo
    // zaczyna się pod domem — patrz Support::trackUrlsForFeed). Cudze solo
    // nie ma tu wpisu, więc klik dociąga sam kadr z pól.
    if (typeof ridemoreRideFeed === 'function') {
        ridemoreRideFeed(map, document.getElementById('discRideFeed'), {
            tracks: <?= json_encode($feedTrackUrls ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        });
    }

    <?php // WŁASNE ŚLADY nie są tu już dokładane osobną, zawsze zapaloną
          // warstwą (Etap 1b, 2026-08-26). Rysuje je warstwa „Ślady", bo na
          // zakładce „moja" `sources.slady` niesie klucz `u-{slug}`/`me` —
          // ta sama zasada co na profilu rowerzysty, więc linie wyglądają
          // identycznie w obu miejscach, a przełącznik obok mapy gasi to,
          // co widać. ?>

    // Stan warstw ląduje w adresie (replaceState, nie push — przełącznik nie
    // jest krokiem nawigacji i nie ma zaśmiecać przycisku „wstecz"), żeby dało
    // się podesłać komuś dokładnie ten widok, który się ogląda.
    box.addEventListener('change', function (e) {
        var cb = e.target.closest('[data-layer]');
        if (!cb) return;
        map.ridemoreSetLayer(cb.dataset.layer, cb.checked);

        // Ten sam czytnik co przy starcie mapy (Etap 1) — stan warstw ma
        // JEDNO źródło, żeby adres nie mógł się rozjechać z mapą.
        //
        // NAZWY PARAMETRÓW = NAZWY WARSTW (Etap 2, 2026-08-26). Do tej daty
        // każdy klucz miał osobną polską nazwę w adresie (`trasy`, `skarby`,
        // `zdobyte") — zapisaną wprost tutaj, bo strona sama budowała
        // `$mlLayers`. Teraz warstwy przychodzą ze słownika przez
        // `Models\MapLayer`, więc trzymanie osobnego słownika nazw
        // parametrów byłoby DRUGĄ konfiguracją tego samego. `?trails=0`
        // zamiast `?trasy=0` — stare zakładki z dawną nazwą po prostu
        // wracają do stanu domyślnego, tak jak przy każdej nowej warstwie.
        //
        // `default` niesie stan SPRZED override'u z adresu (ten sam obiekt,
        // który discovery.php dostał z kontrolera PRZED `withQueryOverrides"
        // — patrz `data-default-layers` na kontrolce), żeby zapis w adresie
        // był tylko przy ODCHYLENIU od domyślnego, a nie przy każdym kluczu.
        var stan = ridemoreReadLayers(box);
        var domyslne = JSON.parse(box.dataset.defaultLayers || '{}');
        var url = new URL(location.href);
        Object.keys(domyslne).forEach(function (klucz) {
            if (!(klucz in stan)) { return; } // dziecko zgaszone razem z rodzicem — nie zapisujemy go osobno
            if (stan[klucz] === domyslne[klucz]) { url.searchParams.delete(klucz); }
            else { url.searchParams.set(klucz, stan[klucz] ? '1' : '0'); }
        });
        history.replaceState(null, '', url);
    });

    // SZUFLADA NA TELEFONIE I ZWIJANIE NA DESKTOPIE przeniesione 2026-08-24
    // do `assets/js/discovery-map.js` (ridemoreSetupPanels) — panel jest
    // nakładką NA MAPIE, więc należy do mapy, a nie do tej jednej strony.
    // Dzięki temu zwijanie mają wszystkie mapy w serwisie, także te, które
    // dopiero powstaną. Licznik w uchwycie ([data-drawer-count]) uzupełnia
    // dalej discObszar() niżej.

    // KLIK W KONKRETNY SKARB — pozycję pytamy OSOBNYM żądaniem, a nie
    // wstawiamy jej w HTML: `/api/treasures/{id}` przepuszcza punkt przez
    // bramkę ujawnienia, więc skarb ukryty wraca ze środkiem pola (albo
    // wcale, gdy pytający nie odkrył tego terenu). Współrzędne w atrybucie
    // zdradzałyby dokładne miejsce każdemu, kto otworzy źródło strony.
    //
    // Obsługa stoi POZA panelem skarbów (2026-09-03), bo ten sam uchwyt nosi
    // teraz przycisk karty „Następny cel" na zakładce osobistej — a panel
    // istnieje wyłącznie na mapie społeczności. Dotąd przycisk celu potrafił
    // tylko przewinąć do mapy i zostawić szukanie skarbu userowi.
    document.querySelectorAll('[data-treasure]').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = row.dataset.treasure;
            row.classList.add('is-loading');

            // Warstwa skarbów bywa zgaszona (na mapie osobistej domyślnie
            // rządzi nią kontrolka warstw) — przesunięcie kadru na punkt,
            // którego nie widać, wyglądałoby jak brak reakcji.
            var cbSkarby = box && box.querySelector('[data-layer="treasures"]');
            if (cbSkarby && !cbSkarby.checked) {
                cbSkarby.checked = true;
                map.ridemoreSetLayer('treasures', true);
            }

            fetch(<?= json_encode(View::url('/api/treasures/')) ?> + id)
                .then(function (r) { return r.ok ? r.json() : null; })
                .catch(function () { return null; })
                .then(function (dane) {
                    row.classList.remove('is-loading');
                    if (!dane || !dane.treasure) {
                        // 404 znaczy „nie masz prawa go widzieć" — ten sam
                        // komunikat co dla nieistniejącego, żeby odpowiedź
                        // nie zdradzała, że jednak tam coś jest.
                        row.classList.add('is-hidden-spot');
                        return;
                    }
                    map.ridemoreFocusTreasure(
                        parseInt(id, 10),
                        parseFloat(dane.treasure.lat),
                        parseFloat(dane.treasure.lon)
                    );
                });
        });
    });

    // PANEL SKARBÓW OBOK MAPY (2026-08-20) — dwie zakładki i klikalne wiersze.
    var panel = document.getElementById('discTreasurePanel');
    if (panel) {
        var listaObszar = document.getElementById('discAreaList');
        var pustyObszar = document.getElementById('discAreaEmpty');

        panel.querySelectorAll('.disc-panel__tabs button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                panel.querySelectorAll('.disc-panel__tabs button').forEach(function (b) {
                    b.classList.toggle('is-on', b === btn);
                });
                panel.querySelectorAll('[data-pane-body]').forEach(function (body) {
                    body.hidden = body.dataset.paneBody !== btn.dataset.pane;
                });
            });
        });

        // LISTA „W OBSZARZE" — przeliczana z każdej odpowiedzi warstwy skarbów,
        // po PUNKTACH malejąco: panel ma odpowiadać „co tu jest najbardziej
        // warte nadłożenia drogi", a nie powtarzać kolejność z bazy.
        window.discObszar = function (dane) {
            var punkty = (dane.treasures || []).slice().sort(function (a, b) {
                return (b.points || 0) - (a.points || 0);
            });
            listaObszar.innerHTML = '';
            punkty.slice(0, 12).forEach(function (t) {
                var row = document.createElement('button');
                row.type = 'button';
                row.className = 'disc-panel__row';
                var head = document.createElement('b');
                head.textContent = t.name;
                var pkt = document.createElement('span');
                pkt.textContent = '+' + (t.points || 0);
                head.appendChild(pkt);
                var meta = document.createElement('span');
                meta.textContent = (t.category_label ? t.category_label : __('skarb'))
                    + (Number(t.mine) ? ' · ' + __('masz') : '');
                row.appendChild(head);
                row.appendChild(meta);
                row.addEventListener('click', function () {
                    map.ridemoreFocusTreasure(t.id, parseFloat(t.lat), parseFloat(t.lon));
                });
                listaObszar.appendChild(row);
            });

            // Przy oddaleniu serwer oddaje PĘCZKI (same liczby, bez nazw), więc
            // lista nie ma z czego powstać — mówimy to wprost zamiast pokazywać
            // pustkę nad mapą pełną kółek.
            var wPeczkach = (dane.clusters || []).reduce(function (suma, c) { return suma + c.count; }, 0);

            // Liczba na uchwycie szuflady — jedyna rzecz, którą widać na
            // telefonie przy zwiniętym panelu, więc musi liczyć TO SAMO co
            // mapa: pojedyncze pinezki plus zawartość pęczków. Bez pęczków
            // uchwyt mówiłby „0 skarbów" nad mapą pełną kółek.
            var licznik = panel.querySelector('[data-drawer-count]');
            if (licznik) {
                var razem = punkty.length + wPeczkach;
                licznik.textContent = razem ? razem : '';
            }
            if (punkty.length) {
                pustyObszar.hidden = wPeczkach === 0;
                pustyObszar.textContent = wPeczkach
                    ? __('Dodatkowo {n} w pęczkach — przybliż, żeby je rozwinąć.', { n: wPeczkach })
                    : '';
            } else {
                pustyObszar.hidden = false;
                pustyObszar.textContent = wPeczkach
                    ? __('{n} skarbów w tym kadrze — przybliż, żeby zobaczyć listę.', { n: wPeczkach })
                    : __('W tym kadrze nie ma żadnego skarbu.');
            }
        };
    }
});
</script>

<?php if ($isLoggedIn): ?>
<script>
// ZALICZENIE Z LOKALIZACJI (SKA/6).
//
// Cala logika decyzyjna jest PO STRONIE SERWERA — tutaj tylko pobieramy pozycje
// i pokazujemy odpowiedz. Przegladarka nie wie, gdzie stoja skarby (do momentu,
// az sie o nie zapyta w kadrze mapy), a nawet gdyby wiedziala, jej ocena
// odleglosci nie moglaby niczego przyznac: punkty przyznaje wylacznie serwer.
(function () {
    var przycisk = document.getElementById('skarbTutaj');
    if (!przycisk) { return; }

    var pierwotny = przycisk.textContent;
    var powiedz = function (tekst) {
        przycisk.textContent = tekst;
        // Wracamy do pierwotnego napisu, zeby przycisk dalo sie uzyc ponownie
        // po przejechaniu kawalka dalej — na przelęczy stoja czesto dwa skarby
        // w odstepie kilkuset metrow.
        setTimeout(function () { przycisk.textContent = pierwotny; przycisk.disabled = false; }, 6000);
    };

    przycisk.addEventListener('click', function () {
        przycisk.disabled = true;
        przycisk.textContent = __('Sprawdzam gdzie jesteś…');

        // Pozycja przez most (assets/js/native.js) — natywny GPS w aplikacji,
        // `navigator.geolocation` w przeglądarce, jedno API dla obu.
        RM.native.position().then(function (poz) {
            var dane = new FormData();
            dane.append('csrf_token', przycisk.dataset.csrf);
            dane.append('lat', poz.lat);
            dane.append('lon', poz.lon);

            return fetch(przycisk.dataset.url, { method: 'POST', body: dane, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (o) {
                    if (o.error) { powiedz(o.error); return; }
                    if (o.claimed && o.claimed.length) {
                        powiedz(o.claimed.length === 1
                            ? __('Masz: {nazwa} (+{p})', { nazwa: o.claimed[0].name, p: o.points })
                            : __('{n} skarby zaliczone (+{p})', { n: o.claimed.length, p: o.points }));
                        // Warstwa skarbow musi od razu pokazac zmiane stanu,
                        // inaczej ikonka dalej wyglada na nieznaleziona.
                        if (typeof window.ridemoreRefreshTreasures === 'function') {
                            window.ridemoreRefreshTreasures();
                        }
                        return;
                    }
                    powiedz(o.already > 0
                        ? __('Te skarby już masz.')
                        : __('Nie ma tu skarbu w zasięgu.'));
                })
                .catch(function () { powiedz(__('Nie udało się połączyć.')); });
        }).catch(function (err) {
            // Most rozróżnia odmowę zgody od braku sygnału — dawny komunikat
            // mówił o zgodzie także wtedy, gdy zgoda była, a GPS nie złapał.
            powiedz(err.message);
        });
    });
})();
</script>
<?php endif; ?>

<?php // Wspólny lightbox — dymek skarbu na mapie wstawia kafelki `.ph-link`
      // z galerią (SKA/14). Bez tego kliknięcie w zdjęcie wyprowadzałoby
      // z mapy do nowej karty. ?>
<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
