<?php
// views/web/pages/rider-profile.php
// Publiczny profil rowerzysty — /rowerzysta/{slug} (Etap 3).
//
// PRZEBUDOWA 2026-09-13 (cztery rundy uwag usera w jednym dniu). Jedna zasada:
// PROFIL POKAZUJE, USTAWIENIA USTAWIAJĄ. Kolejność czytania:
//   nagłówek   — kim jest (imię, zdanie z faktów, 3 liczby) + dokąd jedzie;
//                właściciel: skróty do wgrywania i ustawień (same linki)
//   do dokończenia (tylko właściciel) — relacja, obecność, ślad, emblemat, trop
//   01 ludzie i wyjazdy — peleton + wyjazdy ze zdjęciami i kroniką
//   02 gablota   — znane trasy przejechane w całości, emblemat w rogu karty
//   03 skarby    — jedna sekcja: od najrzadszych, ze zdjęciami, z zagadką
//   04 mapa      — mgła, ślady, regiony
//   05 dziennik  — co ta osoba napisała i wgrała
// Czego tu NIE ma celowo: rankingów, publicznych punktów, liczby znalazców,
// kart ze stanem ustawień, formularzy. Nazwy komponentów: `.rp-*` w style.css.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';
require __DIR__ . '/../partials/rider-avatar.php';
require_once __DIR__ . '/../partials/region-emblems.php';
require_once __DIR__ . '/../partials/region-link.php';
require_once __DIR__ . '/../partials/trail-card.php';
require_once __DIR__ . '/../partials/step-pager.php';

if (!$profile) {
    // Ten sam ekran dla „nie ma takiego konta", „ukryło się" i „nie ma jeszcze
    // ani jednego przejazdu" — patrz RiderController::show.
    ?>
    <div class="op-head">
        <div class="op-head__c">
            <h1><?= __('Nie znaleziono rowerzysty') ?></h1>
            <p class="op-head__sub"><?= __('Ten profil nie istnieje albo nie jest publiczny.
                Profil rowerzysty pojawia się po pierwszym potwierdzonym wspólnym wyjeździe.') ?></p>
            <div class="op-head__act">
                <a class="btn" href="<?= View::url('/wydarzenia') ?>"><?= __('Zobacz nadchodzące wyjazdy') ?></a>
            </div>
        </div>
    </div>
    <?php
    return;
}

$p = $profile;
$isOwnProfile = $isOwnProfile ?? false;
// Discovery (Etap 8) — domyślne wartości, bo widok bywa renderowany także z
// gałęzi 404 (wyżej), która ich nie przekazuje.
$discovery = $discovery ?? ['cells' => 0, 'rides' => 0, 'pointsTotal' => 0, 'firstInCommunity' => 0];
$trails = $trails ?? [];
$trailsDone = $trailsDone ?? 0;
$trailsTotal = $trailsTotal ?? 0;
$riderSlug = $riderSlug ?? null;
$treasureStats = $treasureStats ?? ['found' => 0, 'total' => 0];
$collections = $collections ?? [];
$emblems = $emblems ?? [];
$ownerTodo = $ownerTodo ?? null;
// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);
$num = static fn($n) => number_format((int) $n, 0, ',', ' ');
$riderName = static function (array $r): string {
    $raw = trim((string) ($r['name'] ?? ''));
    if ($raw === '') { $raw = (string) strstr((string) ($r['email'] ?? ''), '@', true); }
    $parts = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) { return __('Rowerzysta'); }
    $out = $parts[0];
    if (count($parts) > 1) { $out .= ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.'; }
    return $out;
};
$riderInitials = static function (array $r): string {
    $raw = trim((string) ($r['name'] ?? ''));
    if ($raw === '') { $raw = (string) strstr((string) ($r['email'] ?? ''), '@', true); }
    return mb_strtoupper(mb_substr($raw !== '' ? $raw : '?', 0, 2));
};
?>

<?php
    // ====================================================================
    // HERO (2026-08-22) — JEDEN panel zamiast trzech prostokątów.
    //
    // Przedtem stały tu pod sobą: `.op-head` (białe), pudełko „Jedzie na"
    // (białe) i `.trust-bar` (czarny). Trzy bloki, z których żaden nie był
    // bohaterem ekranu — user: „nie ma polotu". Treść i kolejność czytania
    // ZOSTAJĄ te same (kim jest → dokąd jedzie → liczby); zmienia się to,
    // że są jednym obiektem, a nie trzema.
    //
    // Panel jest JASNY (decyzja usera). Pierwsza wersja miała ciemne tło.
    //
    // ZDANIE-SYGNATURA niżej składa się WYŁĄCZNIE z liczb, które i tak są
    // na tej stronie — nie dokłada ani jednego nowego zapytania. Nowe jest
    // to, że po raz pierwszy są zdaniem, a nie tabelą.
    $ulubionyRegion = null;
    $ileRazyTam = 0;
    foreach (($p['regions'] ?? []) as $label => $count) {
        if ((int) $count > $ileRazyTam) { $ulubionyRegion = $label; $ileRazyTam = (int) $count; }
    }
    // Notka pod „Regionów" — wypisuje, KTÓRE. Samo „3 z 5" nie mówiło nic.
    $listaRegionow = implode(' · ', array_slice(array_keys($p['regions'] ?? []), 0, 3));
    // Notka pod „Wraca w te same strony" — który region i ile razy.
    $notkaPowrotow = ($ulubionyRegion !== null && $ileRazyTam > 1)
        ? $ulubionyRegion . ', ' . $ileRazyTam . '×'
        : '';
?>

<?php
// Adres sekcji konta (`?sekcja=` + kotwica boksu, AccountController::form) —
// skróty właściciela i podpowiedzi prowadzą prosto do właściwego miejsca.
$kontoUrl = static fn(string $sekcja, string $kotwica = ''): string =>
    View::url('/admin/moje-konto') . '?sekcja=' . $sekcja . ($kotwica !== '' ? '#' . $kotwica : '');
?>
<section class="rp-hero">
    <?php // WARSTWICE. Jedyny ozdobnik na tej stronie i jedyny, który coś
          // znaczy: rysunek mapy, czyli tego samego, o czym jest cały profil.
          // Inline SVG, nie plik — sześć ścieżek waży mniej niż żądanie HTTP
          // i przefarbowuje się razem z paletą. ?>
    <div class="rp-hero__topo" aria-hidden="true">
        <svg viewBox="0 0 1200 420" preserveAspectRatio="none" fill="none">
            <g stroke="#15201A" stroke-opacity=".07" stroke-width="1.5">
                <path d="M-40 330C120 300 210 214 350 218s214 96 352 62 250-120 400-96 190 66 190 66"/>
                <path d="M-40 292C130 258 214 174 356 178s206 92 344 58 258-112 408-88 172 58 172 58"/>
                <path d="M-40 254C140 216 218 134 362 138s198 88 336 54 266-104 416-80 158 50 158 50"/>
                <path d="M-40 216C150 174 222 94 368 98s190 84 328 50 274-96 424-72 150 42 150 42"/>
                <path d="M-40 178C160 132 226 54 374 58s182 80 320 46 282-88 432-64 142 34 142 34"/>
                <path d="M-40 388C110 366 205 300 344 304s222 100 360 66 242-128 392-104 198 74 198 74"/>
            </g>
            <?php // Jedna warstwica w kolorze znaku szlaku — tyle żółtego, ile
                  // znosi strona, na której wszystko inne jest zielone i szare. ?>
            <g stroke="#F0B41E" stroke-opacity=".55" stroke-width="2.5">
                <path d="M-40 254C140 216 218 134 362 138s198 88 336 54 266-104 416-80 158 50 158 50"/>
            </g>
        </svg>
    </div>

    <div class="rp-hero__in">
        <div class="rp-id">
            <span class="av">
                <?php if (!empty($p['avatarUrl'])): ?>
                <img src="<?= htmlspecialchars(Utils\Image::src($p['avatarUrl'], 'av')) ?>" alt="<?= htmlspecialchars($p['name']) ?>" width="96" height="96">
                <?php else: ?>
                <?= htmlspecialchars($p['initials']) ?>
                <?php endif; ?>
                <span class="av__flag" aria-hidden="true"></span>
            </span>
            <div class="rp-id__c">
                <?php if ($p['signature']): ?>
                <div class="tags">
                    <?php foreach ($p['signature'] as $bit): ?>
                    <span class="tag tag--plain"><?= htmlspecialchars($bit) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <h1><?= htmlspecialchars($p['name']) ?></h1>

                <?php // ZDANIE Z FAKTÓW, BEZ LICZB (2026-09-13). Liczby stoją pod spodem
                      // w pasku — zdanie powtarzające je trzecią formą było jednym
                      // z powodów „chaosu". Zostaje to, czego liczby nie powiedzą:
                      // od kiedy ta osoba jeździ z ludźmi, dokąd wraca i dokąd
                      // pojechała ostatnio. Każdy człon może nie istnieć (świeże
                      // konto), więc zdania nie ma, gdy nie ma z czego go złożyć. ?>
                <?php
                    $miesiace = array_values(\Core\Lang::months('long'));
                    $czlony = [];
                    $pierwszy = $p['rides'] ? end($p['rides']) : null;
                    $ostatni = $p['rides'] ? reset($p['rides']) : null;
                    if ($pierwszy && ($dPierwszy = date_create((string) $pierwszy['start_date']))) {
                        $czlony[] = __('Jeździ z ludźmi od {kiedy}.', ['kiedy' => $miesiace[(int) $dPierwszy->format('n') - 1]
                            . ((int) $dPierwszy->format('Y') !== (int) date('Y') ? ' ' . $dPierwszy->format('Y') : '')]);
                    }
                    if ($ulubionyRegion !== null && $ileRazyTam > 1) {
                        $czlony[] = __('Najczęściej wraca w <b>{region}</b>.', ['region' => htmlspecialchars($ulubionyRegion)]);
                    }
                    if ($ostatni && count($p['rides']) > 1) {
                        $czlony[] = __('Ostatni wyjazd: <b>{tytul}</b>.', ['tytul' => htmlspecialchars($ostatni['title'])]);
                    }
                ?>
                <?php if ($czlony): ?>
                <p class="rp-hero__sig"><?= implode(' ', $czlony) ?></p>
                <?php endif; ?>

                <div class="rp-hero__act">
                    <?php // W APCE ten przycisk jest ZASTĄPIONY przypiętym dolnym paskiem
                          // (`.mbar` niżej, Faza 4 przebudowy UX apki, 2026-08-29) — profil
                          // bywa długi, a przycisk w treści znika po pierwszym przewinięciu.
                          // `bodyClass=has-mbar` (RiderController::show()) rysuje się w
                          // dokładnie tym samym warunku, więc dwa CTA nigdy nie stoją naraz. ?>
                    <?php if (!$isOwnProfile && !empty($viewerId) && !APP_IS_APP): ?>
                    <a class="btn btn-secondary" href="<?= View::url('/wiadomosci/z/' . (int) $p['id']) ?>"><?= __('Napisz wiadomość') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php // PRAWA KOLUMNA NAGŁÓWKA (2026-09-13): zaproszenie „Jedzie na"
              // dla każdego, a pod nim — wyłącznie na własnym profilu — SKRÓTY.
              // Skróty są PRZEKIEROWANIAMI, nie panelem (rozstrzygnięcie usera:
              // „to ma być tylko przekierowanie do ustawień, a nie miejsce, gdzie
              // będę to robił na profilu"). Żadnego stanu, przełącznika ani
              // formularza — każdy wiersz prowadzi na istniejący ekran. ?>
        <?php // Oba bloki naraz (własny profil + zaplanowany wyjazd) stoją obok
              // siebie, nie jeden pod drugim — w słupku rozciągały cały nagłówek. ?>
        <div class="rp-side<?= ($p['nextRide'] && $isOwnProfile) ? ' rp-side--both' : '' ?>">
        <?php if ($p['nextRide']): ?>
        <?php
            // JEDZIE NA — jedyny element sygnałowy na tym ekranie (znak szlaku
            // .blaze). Stoi PO PRAWEJ, na wysokości imienia: profil nie jest
            // nagrobkiem historii, tylko zaproszeniem — widzisz czyjś profil
            // i możesz dołączyć do jego następnego wyjazdu.
            $nr = $p['nextRide'];
            // „Za ile dni" liczone z daty startu, nie z terminu zapisów —
            // profil nie dostaje z kontrolera daty zamknięcia zapisów, a
            // zmyślanie jej byłoby obietnicą, której strona nie zna.
            $doStartu = null;
            $dStart = date_create((string) $nr['start_date']);
            if ($dStart) {
                $roznica = (int) (new DateTimeImmutable('today'))->diff($dStart)->format('%r%a');
                if ($roznica >= 0 && $roznica <= 60) { $doStartu = $roznica; }
            }
        ?>
        <aside class="rp-next">
            <p class="rp-next__lbl"><span class="blaze" aria-hidden="true"></span><?= __('Jedzie na') ?></p>
            <h2>
                <a href="<?= View::url('/events/' . $nr['slug']) ?>?termin=<?= (int) $nr['edition_id'] ?>"><?= htmlspecialchars($nr['title']) ?></a>
            </h2>
            <p class="rp-next__m"><?= htmlspecialchars(Format::dateShort($nr['start_date'])) ?><?= $nr['region_label'] ? ' · ' . renderRegionLinks($nr['region_label']) : '' ?></p>
            <a class="btn" href="<?= View::url('/events/' . $nr['slug']) ?>?termin=<?= (int) $nr['edition_id'] ?>"><?= __('Dołącz do tego wyjazdu') ?></a>
            <?php if ($doStartu !== null): ?>
            <p class="rp-next__in"><?= $doStartu === 0 ? __('Startuje dzisiaj')
                : ($doStartu === 1 ? __('Startuje jutro')
                : __n($doStartu, 'Za {n} dzień', 'Za {n} dni', 'Za {n} dni')) ?></p>
            <?php endif; ?>
        </aside>
        <?php endif; ?>
        <?php if ($isOwnProfile): ?>
        <nav class="rp-short" aria-label="<?= htmlspecialchars(__('Twoje skróty')) ?>">
            <div class="rp-short__hd"><b><?= __('Twoje skróty') ?></b><span class="rp-only"><?= Utils\Icon::render('lock') ?><?= __('tylko Ty') ?></span></div>
            <a class="btn rp-short__up" href="<?= View::url('/admin/moje-przejazdy') ?>#dodaj"><?= Utils\Icon::render('upload') ?><?= __('Wgraj przejazd') ?></a>
            <span class="rp-short__note"><?= __('plik GPX albo FIT, import z licznika') ?></span>
            <div class="rp-short__list">
                <a href="<?= View::url('/admin/moje-przejazdy') ?>"><?= __('Moje przejazdy i ślady') ?></a>
                <a href="<?= $kontoUrl('profil') ?>"><?= __('Ustawienia konta') ?></a>
                <a href="<?= $kontoUrl('preferencje', 'jak-jezdze') ?>"><?= __('Preferencje dopasowań') ?></a>
                <a href="<?= $kontoUrl('profil', 'powiadomienia-mail') ?>"><?= __('Powiadomienia') ?></a>
                <a href="<?= $kontoUrl('preferencje', 'prywatnosc') ?>"><?= __('Prywatność') ?></a>
            </div>
        </nav>
        <?php endif; ?>
        </div>
    </div>

    <?php
        // LICZBY NAGŁÓWKA — TRZY, NIE PIĘĆ (2026-09-13). Każda odpowiada na inne
        // pytanie: z kim (peleton), jak często razem (wyjazdy), jak daleko w świat
        // (teren). Dawne „Regionów", „Wraca w te same strony" i sześć kafli nad
        // mapą mówiły to samo innymi słowami — pamięć robocza nie utrzyma
        // jedenastu liczb, a zostaje z nich żadna. Regiony żyją teraz przy mapie.
        //
        // PUNKTY WIDZI WYŁĄCZNIE WŁAŚCICIEL. Na cudzym profilu liczba punktów
        // zamienia się w porównanie „mam mniej", a to uderza w dołączających
        // później — ta sama zasada co „progres to zasięg, nie wyczyn".
        $maPola = (int) ($discovery['cells'] ?? 0) > 0;
        $ileLiczb = 3 + ($isOwnProfile ? 1 : 0);
    ?>
    <div class="rp-nums" style="--rp-cols:<?= $ileLiczb ?>">
        <div class="rp-num">
            <div class="rp-num__l"><?= __('Peleton') ?></div>
            <div class="rp-num__v"><?= (int) $p['pelotonCount'] ?><small><?= $plural((int) $p['pelotonCount'], 'osoba', 'osoby', 'osób') ?></small></div>
            <div class="rp-num__n"><?= __('ze wspólnych wyjazdów') ?></div>
        </div>
        <div class="rp-num">
            <div class="rp-num__l"><?= __('Wspólne wyjazdy') ?></div>
            <div class="rp-num__v"><?= (int) $p['ridesCount'] ?></div>
            <div class="rp-num__n"><?= (float) $p['sharedKm'] > 0
                ? __('{km} z ludźmi', ['km' => htmlspecialchars(Format::distance((float) $p['sharedKm']))])
                : __('z potwierdzoną obecnością') ?></div>
        </div>
        <div class="rp-num">
            <div class="rp-num__l"><?= __('Odkryty teren') ?></div>
            <?php if ($maPola): ?>
            <div class="rp-num__v"><?= $num($discovery['cells']) ?><small><?= $plural((int) $discovery['cells'], 'pole', 'pola', 'pól') ?></small></div>
            <?php else: ?>
            <div class="rp-num__v"><?= (int) $p['regionsVisited'] ?><small><?= $plural((int) $p['regionsVisited'], 'region', 'regiony', 'regionów') ?></small></div>
            <?php endif; ?>
            <div class="rp-num__n"><?= $maPola
                ? __n((int) $p['regionsVisited'], 'w {n} regionie', 'w {n} regionach', 'w {n} regionach')
                : __('mgła zejdzie po pierwszym śladzie') ?></div>
        </div>
        <?php if ($isOwnProfile): ?>
        <div class="rp-num rp-num--own">
            <div class="rp-num__l"><?= __('Twoje punkty') ?> <span class="rp-only rp-only--xs"><?= Utils\Icon::render('lock') ?><?= __('tylko Ty') ?></span></div>
            <div class="rp-num__v"><?= $num($discovery['pointsTotal'] ?? 0) ?></div>
            <div class="rp-num__n"><?= __('inni ich nie widzą') ?></div>
        </div>
        <?php endif; ?>
    </div>
</section>


<?php // ============================================================================
      // DO DOKOŃCZENIA — WYŁĄCZNIE NA WŁASNYM PROFILU (2026-09-13).
      //
      // Zastępuje dawny „Twój panel" z ośmioma kartami stanu. Zasady:
      //   • JEDNO ZDANIE, JEDEN LINK. Rzecz robi się na swoim ekranie (relacja,
      //     obecność, Moje przejazdy) — tutaj tylko przypominamy, że czeka.
      //   • NAJWYŻEJ PIĘĆ NARAZ, KAŻDA INNEGO RODZAJU. Dłuższa lista przestaje
      //     być podpowiedzią, a zaczyna być zaległością; reszta ma link zbiorczy.
      //   • BEZ WYRZUTU. „Nic z tego nie jest obowiązkowe" stoi w nagłówku,
      //     a blok znika sam, gdy nie ma czego dokończyć.
      //   • KOLEJNOŚĆ Z KONTROLERA (RiderController::ownerTodo): najpierw to,
      //     co najłatwiej stracić — relacja blednie z każdym dniem.
      // ============================================================================ ?>
<?php
    $todoTop = $ownerTodo['top'] ?? [];
    $todoByEdition = $ownerTodo['byEdition'] ?? [];
    $todoMeta = [
        'recap'   => ['ikona' => 'edit',    'go' => __('Napisz relację')],
        'attend'  => ['ikona' => 'check',   'go' => __('Potwierdź')],
        'track'   => ['ikona' => 'route',   'go' => __('Dołóż ślad')],
        'emblem'  => ['ikona' => 'hex',     'go' => __('Zobacz trasę')],
        'trail'   => ['ikona' => 'hex',     'go' => __('Zobacz trasę')],
        'mystery' => ['ikona' => 'mystery', 'go' => __('Na mapę')],
    ];
?>
<?php if ($isOwnProfile && (!empty($profileHidden) || $todoTop)): ?>
<section class="rp-todo" aria-labelledby="rpTodoH">
    <div class="rp-todo__hd">
        <h2 id="rpTodoH"><?= __('Do dokończenia') ?></h2>
        <span class="rp-only"><?= Utils\Icon::render('lock') ?><?= __('tylko Ty') ?></span>
        <span class="rp-todo__sub"><?= __('nic z tego nie jest obowiązkowe') ?></span>
    </div>
    <?php if (!empty($profileHidden)): ?>
    <a class="rp-todo__i" href="<?= $kontoUrl('preferencje', 'prywatnosc') ?>">
        <span class="rp-todo__ic"><?= Utils\Icon::render('eye') ?></span>
        <span class="rp-todo__tx"><?= __('Twój profil jest') ?> <b><?= __('ukryty') ?></b> <?= __('— inni widzą „Nie znaleziono rowerzysty”. Tę stronę widzisz tylko Ty.') ?></span>
        <span class="rp-todo__go"><?= __('Zmień widoczność →') ?></span>
    </a>
    <?php endif; ?>
    <?php foreach (array_slice($todoTop, 0, 5) as $t): ?>
    <?php $tm = $todoMeta[$t['kind']] ?? ['ikona' => 'more', 'go' => __('Otwórz')]; ?>
    <a class="rp-todo__i rp-todo__i--<?= htmlspecialchars($t['kind']) ?>" href="<?= htmlspecialchars(View::url($t['url'])) ?>">
        <span class="rp-todo__ic"><?= Utils\Icon::render($tm['ikona']) ?></span>
        <span class="rp-todo__tx"><?= htmlspecialchars($t['text']) ?></span>
        <span class="rp-todo__go"><?= htmlspecialchars($tm['go']) ?> →</span>
    </a>
    <?php endforeach; ?>
    <?php if (count($todoTop) > 5): ?>
    <a class="rp-todo__more" href="<?= View::url('/admin/moje-przejazdy') ?>"><?= __('i {n} więcej w Moich przejazdach →', ['n' => count($todoTop) - 5]) ?></a>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php
    // ============================================================================
    // DANE ROZDZIAŁÓW — liczone raz, nad spisem, bo od nich zależy, które
    // pozycje spisu w ogóle się pokażą (spis prowadzący w pustkę jest gorszy
    // niż brak spisu).
    // ============================================================================
    $showcase = $showcase ?? [];
    $rarityTotals = $rarityTotals ?? [];
    $rideExtras = $rideExtras ?? [];
    // SKARBY — JEDNA SEKCJA (uwaga usera 2026-09-13: „jaka jest różnica między
    // »Zdobyte na zawsze« a »Znalezione w terenie«? Sekcja ze skarbami powinna
    // być jedna"). Legendarne i epickie nie mają osobnej gabloty — stoją na
    // początku tej samej listy, bo `showcaseForUser` sortuje od najrzadszych.
    $showcasePaged = $showcasePaged ?? ['page' => 1, 'pages' => 1, 'total' => count($showcase), 'per' => 12, 'rarity' => null, 'category' => null, 'counts' => ['ALL' => count($showcase)]];
    $ridesPaged = $ridesPaged ?? ['items' => $p['rides'], 'page' => 1, 'pages' => 1, 'total' => count($p['rides']), 'per' => 9];
    $profilUrl = View::url('/rowerzysta/' . $riderSlug);
    $mamLegendarny = (int) ($showcasePaged['counts']['LEGENDARY'] ?? 0) > 0;
    $slotLegendy = $isOwnProfile && !$mamLegendarny && (int) ($rarityTotals['LEGENDARY'] ?? 0) > 0;

    // GABLOTA = WYŁĄCZNIE PRZEJECHANE W CAŁOŚCI ZNANE TRASY (ta sama uwaga:
    // rozpoczęte trasy nie są „zdobyte"). Emblemat nie ma własnego rzędu —
    // siedzi w rogu karty trasy, a trasy z emblematem idą pierwsze. Trasa
    // z emblematem trafia do gabloty także wtedy, gdy admin wydłużył później
    // jej GPX i procent spadł poniżej 100 — emblematu nie da się stracić.
    // Emblemat za trasę WYDARZENIA (nie ma karty znanej trasy) dostaje taką
    // samą kartę, prowadzącą na wydarzenie.
    $emblematyTras = [];
    $emblematyWydarzen = [];
    foreach ($emblems as $em) {
        $zrodlo = (string) ($em['sourceUrl'] ?? '');
        if (str_starts_with($zrodlo, '/trasy/')) {
            $emblematyTras[$zrodlo] = $em;
        } else {
            $emblematyWydarzen[] = $em;
        }
    }
    $zEmblematem = [];
    $bezEmblematu = [];
    foreach ($trails as $route) {
        $em = $emblematyTras['/trasy/' . $route['slug']] ?? null;
        if ($em !== null) {
            $zEmblematem[] = [$route, $em];
        } elseif (!empty($route['isComplete'])) {
            $bezEmblematu[] = [$route, null];
        }
    }
    $maGablote = $zEmblematem || $emblematyWydarzen || $bezEmblematu;
    $maSkarby = (int) ($showcasePaged['counts']['ALL'] ?? 0) > 0 || !empty($collections) || $slotLegendy;
    $maLudzi = !empty($p['peloton']) || !empty($p['rides']);

    $kotwice = [];
    if ($maLudzi) { $kotwice['wyjazdy'] = __('Ludzie i wyjazdy'); }
    if ($maGablote) { $kotwice['gablota'] = __('Gablota'); }
    if ($maSkarby) { $kotwice['skarby'] = __('Skarby'); }
    $kotwice['mapa'] = __('Mapa');
    if (!empty($feed['items'])) { $kotwice['aktywnosc'] = __('Dziennik'); }
?>
<?php if (count($kotwice) > 2): ?>
<nav class="anchors">
    <div class="anchors__in">
        <?php foreach ($kotwice as $id => $label): ?>
        <a href="#<?= $id ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
        <?php if ($isOwnProfile): ?>
        <?php // Trzecie wejście do ustawień — w miejscu, które zostaje na ekranie
              // przy przewijaniu (spis jest przyklejony). ?>
        <a class="anchors__c anchors__c--link" href="<?= $kontoUrl('profil') ?>"><?= Utils\Icon::render('lock') ?>Ustawienia konta →</a>
        <?php else: ?>
        <span class="anchors__c"><?= __('Profil publiczny') ?></span>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>

<?php // ============================================================================
      // 01 · LUDZIE I WYJAZDY — pierwszy rozdział, pełna szerokość.
      // Kierunek „najpierw ludzie" (2026-08-11): peleton stał dotąd w wąskiej
      // szynie na ~3 500 px. Wyjazdy są tu razem z ludźmi, bo to na nich
      // peleton powstaje — i niosą ZDJĘCIA I KRONIKĘ, czyli wspomnienie,
      // a nie tylko datę. Kafel bez okładki bierze pierwsze zdjęcie z relacji.
      // ============================================================================ ?>
<?php if ($maLudzi): ?>
<section class="sec rp-ch" id="wyjazdy">
    <div class="rp-ch__hd">
        <span class="rp-ch__no"><?= __('01 · Ludzie') ?></span>
        <h2><?= $isOwnProfile ? __('Z kim jeździsz') : __('Z kim jeździ') ?></h2>
        <span class="rp-ch__meta"><?= __('tylko z potwierdzonej obecności') ?></span>
        <?php if ($isOwnProfile): ?>
        <a class="rp-ch__lnk" href="<?= $kontoUrl('preferencje', 'prywatnosc') ?>"><?= Utils\Icon::render('lock') ?>Kto mnie widzi →</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($p['peloton'])): ?>
    <div class="rp-crew">
        <?php foreach ($p['peloton'] as $mate): ?>
        <div class="rp-mate">
            <span class="avs"><?php renderRiderAvatar($riderInitials($mate), $mate['public_slug'] ?? null, $riderName($mate), $mate['avatar_url'] ?? null); ?></span>
            <span class="rp-mate__b">
                <b><?= renderRiderName($riderName($mate), $mate['public_slug'] ?? null) ?></b>
                <span><?= (int) $mate['rides_count'] ?> <?= $plural((int) $mate['rides_count'], __('wspólny wyjazd'), __('wspólne wyjazdy'), __('wspólnych wyjazdów')) ?><?= (float) $mate['shared_km'] > 0 ? ' · ' . htmlspecialchars(Format::distance((float) $mate['shared_km'])) : '' ?></span>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($p['rides'])): ?>
    <div class="rp-rides">
        <?php foreach ($ridesPaged['items'] as $ride): ?>
        <?php
            $ed = (int) $ride['edition_id'];
            $ex = $rideExtras[$ed] ?? ['photos' => [], 'recaps' => 0];
            $okladka = $ride['cover_photo_url'] ?: ($ex['photos'][0] ?? null);
            $eventUrl = View::url('/events/' . $ride['slug']) . '?termin=' . $ed;
            $kronikaUrl = View::url('/kronika/' . $ride['slug']) . '?termin=' . $ed;
            $miniatury = $ride['cover_photo_url'] ? $ex['photos'] : array_slice($ex['photos'], 1);
        ?>
        <article class="rp-ride">
            <a class="rp-ride__cover<?= $okladka ? '' : ' rp-ride__cover--none' ?>" href="<?= $eventUrl ?>"
               <?= $okladka ? 'style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($okladka, 'card')) . '\')"' : '' ?>
               aria-label="<?= htmlspecialchars($ride['title']) ?>">
                <?php if ((int) $ride['duration_days'] > 1): ?><span class="rp-ride__d"><?= __('{n} dni', ['n' => (int) $ride['duration_days']]) ?></span><?php endif; ?>
            </a>
            <div class="rp-ride__b">
                <h3><a href="<?= $eventUrl ?>"><?= htmlspecialchars($ride['title']) ?></a></h3>
                <p class="rp-ride__m"><?= htmlspecialchars(Format::dateShort($ride['start_date'])) ?><?= $ride['region_label'] ? ' · ' . renderRegionLinks($ride['region_label']) : '' ?><?= (float) $ride['distance_km'] > 0 ? ' · ' . htmlspecialchars(Format::distance((float) $ride['distance_km'])) : '' ?></p>
                <a class="rp-ride__kr" href="<?= $kronikaUrl ?>">
                    <?php foreach (array_slice($miniatury, 0, 3) as $ph): ?>
                    <span class="rp-ride__ph" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($ph, 'thumb')) ?>')"></span>
                    <?php endforeach; ?>
                    <span class="rp-ride__krt"><?= (int) $ex['recaps'] > 0
                        ? __n((int) $ex['recaps'], '{n} wpis w kronice →', '{n} wpisy w kronice →', '{n} wpisów w kronice →')
                        : __('Kronika wyjazdu →') ?></span>
                </a>
                <?php if ($ride['points'] !== null && (int) $ride['cells_new'] > 0): ?>
                <p class="rp-ride__ft"><b><?= (int) $ride['cells_new'] ?></b> <?= $plural((int) $ride['cells_new'], 'nowe pole', 'nowe pola', __('nowych pól')) ?><?php if ($isOwnProfile): ?> · <span class="rp-ride__pts">+<?= $num($ride['points']) ?> <?= __('pkt') ?></span><?php endif; ?></p>
                <?php endif; ?>
                <?php if ($isOwnProfile && !empty($todoByEdition[$ed])): ?>
                <div class="rp-ride__todo">
                    <?php foreach ($todoByEdition[$ed] as $chip): ?>
                    <a class="rp-chip rp-chip--<?= htmlspecialchars($chip['kind']) ?>" href="<?= htmlspecialchars(View::url($chip['url'])) ?>"><?= Utils\Icon::render($todoMeta[$chip['kind']]['ikona'] ?? 'more') ?><?= htmlspecialchars($chip['label']) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php renderStepPager(['base' => $profilUrl, 'param' => 'wyjazdy', 'anchor' => 'wyjazdy',
        'page' => $ridesPaged['page'], 'pages' => $ridesPaged['pages'], 'total' => $ridesPaged['total'],
        'from' => ($ridesPaged['page'] - 1) * $ridesPaged['per'] + 1,
        'to' => ($ridesPaged['page'] - 1) * $ridesPaged['per'] + count($ridesPaged['items']),
        'prev' => __('‹ Nowsze'), 'next' => __('Starsze ›')]); ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php // ============================================================================
      // 02 · GABLOTA — znane trasy przejechane w całości.
      // Te same karty co na /odkrycia i /trasy (`partials/trail-card.php`),
      // z emblematem w rogu. Bez osobnego tła i bez podziału „z emblematem /
      // bez" — różnicę robi sam znaczek i kolejność.
      // ============================================================================ ?>
<?php if ($maGablote): ?>
<section class="sec rp-ch" id="gablota">
    <div class="rp-ch__hd">
        <span class="rp-ch__no"><?= __('02 · Gablota') ?></span>
        <h2><?= __('Przejechane w całości') ?></h2>
        <?php $ileTras = count($zEmblematem) + count($bezEmblematu); $ileEmb = count($zEmblematem) + count($emblematyWydarzen); ?>
        <span class="rp-ch__meta"><?= $ileTras ?> <?= $plural($ileTras, 'znana trasa', 'znane trasy', 'znanych tras') ?><?= $ileEmb > 0 ? ' · ' . $ileEmb . ' ' . $plural($ileEmb, 'emblemat', 'emblematy', 'emblematów') : '' ?></span>
    </div>
    <?php
        // JEDNA LISTA KART, potem strona. Kolejność: trasy z emblematem,
        // emblematy za trasy wydarzeń, reszta przejechanych — i ta kolejność
        // obowiązuje przez wszystkie strony, nie w obrębie jednej.
        $karty = [];
        foreach ($zEmblematem as [$route, $em]) { $karty[] = [$route, ['emblem' => $em]]; }
        foreach ($emblematyWydarzen as $em) {
            $karty[] = [[
                'slug' => '', 'name' => (string) ($em['sourceLabel'] ?? $em['name']), 'distance_km' => 0,
                'region_label' => null, 'cover_photo_url' => $em['imageUrl'] ?? null, 'color_index' => null,
            ], ['emblem' => $em, 'href' => $em['sourceUrl'] ?? '/', 'note' => __('Cała trasa wydarzenia')]];
        }
        foreach ($bezEmblematu as [$route, $em]) { $karty[] = [$route, []]; }
        $naGablote = Controllers\RiderController::PER_GABLOTA;
        $gablotaStron = max(1, (int) ceil(count($karty) / $naGablote));
        $gablotaStrona = min($gablotaStron, max(1, (int) ($gablotaPage ?? 1)));
        $kartyStrony = array_slice($karty, ($gablotaStrona - 1) * $naGablote, $naGablote);
    ?>
    <div class="disc-trails rp-trails">
        <?php foreach ($kartyStrony as [$route, $opcje]): ?>
        <?php renderTrailCard($route, true, $opcje); ?>
        <?php endforeach; ?>
    </div>
    <?php renderStepPager(['base' => $profilUrl, 'param' => 'gablota', 'anchor' => 'gablota',
        'page' => $gablotaStrona, 'pages' => $gablotaStron, 'total' => count($karty),
        'from' => ($gablotaStrona - 1) * $naGablote + 1, 'to' => ($gablotaStrona - 1) * $naGablote + count($kartyStrony)]); ?>
</section>
<?php endif; ?>

<?php // ============================================================================
      // 03 · SKARBY — jedna sekcja: od najrzadszych, ze zdjęciami, z zagadką.
      //
      // Tajemnica zostaje tajemnicą: skarb, który oglądający dopiero musi
      // znaleźć (Trop/Ukryty), przychodzi z modelu ZAMASKOWANY
      // (Treasure::showcaseForUser) i rysuje się jako ciemny kafel
      // „Tajemnica rozwiązana" — widać, że coś tam jest, ale nie co.
      //
      // Bez liczby znalazców i bez „byłem pierwszy"; „z ilu" w kategoriach
      // i puste miejsce na legendarny skarb widzi wyłącznie właściciel.
      // ============================================================================ ?>
<?php if ($maSkarby): ?>
<section class="sec rp-ch" id="skarby">
    <div class="rp-ch__hd">
        <span class="rp-ch__no"><?= __('03 · Skarby') ?></span>
        <h2><?= __('Znalezione w terenie') ?></h2>
        <?php if (($treasureStats['found'] ?? 0) > 0): ?>
        <span class="rp-ch__meta"><?= (int) $treasureStats['found'] ?> <?= $plural((int) $treasureStats['found'], 'skarb', 'skarby', 'skarbów') ?></span>
        <?php endif; ?>
        <a class="rp-ch__lnk" href="<?= View::url('/odkrycia') . ($isOwnProfile ? '' : '?mapa=spolecznosc') ?>"><?= $isOwnProfile ? __('Twoja mapa skarbów →') : __('Mapa skarbów →') ?></a>
    </div>

    <?php
        // FILTR RZADKOŚCI — liczniki z modelu, BEZ zamaskowanych zagadek
        // (Treasure::showcaseRarityCounts). Przycisk bez skarbów się nie rysuje.
        $sp = $showcasePaged;
        $rzadkosci = ['LEGENDARY' => __('Legendarne'), 'EPIC' => __('Epickie'), 'RARE' => __('Rzadkie'), 'COMMON' => __('Zwykłe')];
        $filtrAktywny = $sp['rarity'] !== null || $sp['category'] !== null;
        $nazwaKategorii = null;
        foreach ($collections as $col) { if ((int) $col['id'] === (int) $sp['category']) { $nazwaKategorii = $col['label']; } }
    ?>
    <?php if ((int) ($sp['counts']['ALL'] ?? 0) > $sp['per'] || $filtrAktywny): ?>
    <div class="rp-filters" role="group" aria-label="<?= htmlspecialchars(__('Filtr skarbów')) ?>">
        <a class="rp-filter<?= $sp['rarity'] === null ? ' is-on' : '' ?>" href="<?= htmlspecialchars(stepPagerHref($profilUrl, ['rzadkosc' => null, 'skarby' => null], 'skarby')) ?>"><?= __('Wszystkie') ?> <span><?= (int) $sp['counts']['ALL'] ?></span></a>
        <?php foreach ($rzadkosci as $kod => $etykieta): ?>
        <?php if ((int) ($sp['counts'][$kod] ?? 0) > 0): ?>
        <a class="rp-filter rp-filter--<?= strtolower($kod) ?><?= $sp['rarity'] === $kod ? ' is-on' : '' ?>" href="<?= htmlspecialchars(stepPagerHref($profilUrl, ['rzadkosc' => $kod, 'skarby' => null], 'skarby')) ?>"><?= $etykieta ?> <span><?= (int) $sp['counts'][$kod] ?></span></a>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($nazwaKategorii !== null): ?>
        <a class="rp-filter is-on" href="<?= htmlspecialchars(stepPagerHref($profilUrl, ['kategoria' => null, 'skarby' => null], 'skarby')) ?>"><?= htmlspecialchars($nazwaKategorii) ?> ×</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$showcase && $filtrAktywny): ?>
    <p class="rp-empty"><?= __('Brak skarbów w tym filtrze.') ?> <a href="<?= htmlspecialchars(stepPagerHref($profilUrl, ['rzadkosc' => null, 'kategoria' => null, 'skarby' => null], 'skarby')) ?>"><?= __('Pokaż wszystkie') ?></a></p>
    <?php endif; ?>

    <?php $slotLegendy = $slotLegendy && !$filtrAktywny && (int) $sp['page'] === 1; ?>
    <?php if ($showcase || $slotLegendy): ?>
    <div class="rp-tres">
        <?php foreach ($showcase as $s): ?>
        <?php if ($s['masked']): ?>
        <div class="rp-tre rp-tre--mystery">
            <span class="rp-tre__ph"><?= Utils\Icon::render('mystery') ?></span>
            <span class="rp-tre__b">
                <b><?= __('Tajemnica rozwiązana') ?></b>
                <span><?= $s['category'] ? htmlspecialchars($s['category']) . ' · ' : '' ?><?= __('znajdź, żeby zobaczyć, co to') ?></span>
            </span>
        </div>
        <?php else: ?>
        <?php $treTag = $isOwnProfile ? 'a' : 'div'; ?>
        <<?= $treTag ?> class="rp-tre rp-tre--<?= strtolower((string) $s['rarity']) ?>"<?= $isOwnProfile ? ' href="' . htmlspecialchars(View::url('/odkrycia?skarb=' . (int) $s['id'])) . '"' : '' ?>>
            <span class="rp-tre__ph"<?= $s['photo'] ? ' style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($s['photo'], 'thumb')) . '\')"' : '' ?>><?= $s['photo'] ? '' : Utils\Icon::maybe($s['icon']) ?></span>
            <span class="rp-tre__b">
                <span class="rarity-chip is-rarity-<?= strtolower((string) $s['rarity']) ?>"><?= htmlspecialchars(Models\Treasure::rarityLabel($s['rarity']) ?? '') ?></span>
                <b><?= htmlspecialchars((string) $s['name']) ?></b>
                <span><?= htmlspecialchars((string) ($s['category'] ?? '')) ?></span>
            </span>
        </<?= $treTag ?>>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($slotLegendy): ?>
        <a class="rp-tre rp-tre--slot" href="<?= View::url('/odkrycia') ?>?mapa=spolecznosc">
            <span class="rp-tre__ph"><?= Utils\Icon::render('mystery') ?></span>
            <span class="rp-tre__b">
                <span class="rarity-chip is-rarity-legendary"><?= __('legendarny') ?></span>
                <b><?= __('Miejsce na legendę') ?></b>
                <span><?= __n((int) $rarityTotals['LEGENDARY'], 'W całej Polsce czeka {n} — trzeba do nich naprawdę dojechać', 'W całej Polsce czekają {n} — trzeba do nich naprawdę dojechać', 'W całej Polsce czeka {n} — trzeba do nich naprawdę dojechać') ?></span>
            </span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php renderStepPager(['base' => $profilUrl, 'param' => 'skarby', 'anchor' => 'skarby',
        'page' => $sp['page'], 'pages' => $sp['pages'], 'total' => $sp['total'],
        'from' => ($sp['page'] - 1) * $sp['per'] + 1, 'to' => ($sp['page'] - 1) * $sp['per'] + count($showcase)]); ?>

    <?php if (!empty($collections)): ?>
    <div class="rp-colls">
        <?php foreach ($collections as $col): ?>
        <?php $pct = (int) $col['total'] > 0 ? min(100, (int) round(100 * (int) $col['found'] / (int) $col['total'])) : 0; ?>
        <?php // Kategoria jest FILTREM listy wyżej — przy 300 skarbach to jedyny
              // sensowny sposób, żeby dojść do „wszystkich zabytków". ?>
        <a class="rp-coll<?= (int) $sp['category'] === (int) $col['id'] ? ' is-on' : '' ?>" href="<?= htmlspecialchars(stepPagerHref($profilUrl, ['kategoria' => (int) $sp['category'] === (int) $col['id'] ? null : (int) $col['id'], 'skarby' => null], 'skarby')) ?>">
            <span class="coll__ic"><?= Utils\Icon::maybe($col['icon']) ?></span>
            <span class="rp-coll__b">
                <span class="rp-coll__hd"><b><?= htmlspecialchars($col['label']) ?></b>
                    <span><?= (int) $col['found'] ?><?php if ($isOwnProfile): ?> <small>z <?= (int) $col['total'] ?></small><?php endif; ?></span></span>
                <?php if ($isOwnProfile): ?><span class="coll__b"><i style="width:<?= $pct ?>%"></i></span><?php endif; ?>
                <?php if (!empty($col['rarityFound'])): ?>
                <span class="coll__rarities">
                    <?php foreach ($col['rarityFound'] as $poziom => $ile): ?>
                    <span class="rarity-chip is-rarity-<?= strtolower($poziom) ?>"><?= $ile ?>× <?= htmlspecialchars(Models\Treasure::rarityLabel($poziom)) ?></span>
                    <?php endforeach; ?>
                </span>
                <?php endif; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php
    // Zgłoszenie usera 2026-09-10: „rowerzysta się rejestruje, nigdzie nie
    // był, nigdzie jeszcze nie jechał, wchodzi na profil i co… dupa" — mapa
    // sekcji poniżej renderowała się WYŁĄCZNIE, gdy było choć jedno źródło
    // linii (ślad albo trasa zapowiadana). Do 2026-09-10 stał tu warunek
    // `!empty($p['tracks']) || !empty($p['plannedTracks'])`, usunięty
    // świadomie: świeże konto ma dostać PUSTĄ mapę (cała Polska pod mgłą,
    // ten sam silnik i ten sam pusty stan co na /odkrycia dla nowego konta),
    // a nie brak mapy w ogóle. `ridemoreDiscoveryMap()` niżej już bezpiecznie
    // obsługuje zerowe dane (`bounds: null` = domyślny widok całego kraju,
    // puste `sources`/`tracks` = warstwy bez linii) — to ten sam kod, który
    // /odkrycia pokazuje każdemu nowemu kontu.
?>
<?php
    // MAPA WSPOMNIEŃ — ślady wyjazdów, na których ta osoba faktycznie była.
    //
    // DWIE WARSTWY LINII, i to jest sedno spójności z mapą odkryć:
    //   linia ciągła   — ślad z ODBYTEGO wyjazdu (edition_tracks, migr. 042).
    //                    Dokładnie z niego naliczyły się pola, więc pod każdą
    //                    taką linią mgła jest zdjęta.
    //   linia przerywana — trasa ZAPOWIADANA wyjazdu bez śladu z realizacji.
    //                    Pól nie dała i mgła pod nią zostaje.
    //
    // Wcześniej mapa rysowała WYŁĄCZNIE trasy zapowiadane i podpisywała je
    // „ślady z przejechanych wyjazdów" — linie i mgła brały się wtedy z dwóch
    // różnych źródeł, więc na jednym obrazku pokazywały co innego. Rozdzielenie
    // ich stylem tłumaczy różnicę zamiast ją ukrywać: widać, których wyjazdów
    // nikt jeszcze nie potwierdził śladem.
    //
    // Od Etapu 8 ta sama mapa niesie DRUGĄ WARSTWĘ: mgłę nad terenem, którego
    // ta osoba nie przejechała. Jedna mapa, nie dwie — dwie mapy obok siebie
    // mówiłyby o tym samym („gdzie ta osoba jeździła") i zmuszały do
    // porównywania ich wzrokiem. Ślady odpowiadają „którymi trasami", mgła
    // „jak dużo świata jeszcze przed nią" — to są dwie odpowiedzi na jedno
    // pytanie i powinny leżeć na sobie.
    //
    // Kolejność warstw jest wymuszona własnym panelem 'discoveryHex'
    // (z-index 350, pod overlayPane) — inaczej zależałaby od tego, co wczyta
    // się pierwsze, a ślad GPX pod mgłą byłby po prostu niewidoczny.
    //
    // PRYWATNOŚĆ: dopóki ślady wgrywa się DO WYDARZENIA, ta warstwa nie zdradza
    // ani metra ponad to, co i tak jest na tej stronie — wyjazdy są wypisane
    // niżej. Zmieni się to przy przejazdach solo (8A/11), bo tam ślad zaczyna
    // się pod domem — i wtedy TA sekcja wymaga ponownej decyzji (8A/13).
    //
    // Kolor śladu = --accent (leśna zieleń), nie domyślna pomarańcz biblioteki,
    // żeby mapa należała do palety „Szlak".
    $hasDiscovery = ($discovery['cells'] ?? 0) > 0;
    $realCount = count($p['tracks']);
    $plannedCount = count($p['plannedTracks']);

    // BUDŻET LINII ZNIKŁ RAZEM Z ŁADOWANIEM GPX-ÓW (2026-08-14).
    //
    // Przez pół dnia stało tu ograniczenie do 25 najnowszych śladów, bo mapa
    // rysowała każdy z nich wektorowo i przy 600 przejazdach pobierała ok. 108 MB.
    // Kafle (migr. 051) usuwają POWÓD tego ograniczenia, a nie tylko jego objaw:
    // obrazek kafla kosztuje tyle samo przy 6 śladach co przy 6000, więc mapa
    // pokazuje znowu CAŁĄ historię i nie ma czego przycinać.
    //
    // Zostaje to, czego raster nie potrafi: wybrany z listy ślad rysuje się
    // wektorowo na wierzchu, żeby dało się go podświetlić i dociągnąć do niego
    // kadr — jeden plik na kliknięcie zamiast sześciuset na wejście.
?>
<section class="sec rp-ch" id="mapa">
    <div class="rp-ch__hd">
        <span class="rp-ch__no"><?= __('04 · Miejsca') ?></span>
        <h2><?= $isOwnProfile ? __('Gdzie jeździsz') : __('Gdzie jeździ') ?></h2>
        <span class="rp-ch__meta"><?= $hasDiscovery ? __('szare pola to teren jeszcze pod mgłą') : __('ślady wspólnych wyjazdów') ?></span>
    </div>
    <div class="box">

        <?php // MAPA — TA SAMA STRUKTURA I TEN SAM PANEL AKTYWNOŚCI CO NA
              // /odkrycia (2026-08-25, uwaga usera: „na mapie zastosować
              // Aktywności z /odkrycia — masz wtedy mniej kodu i kombinowania").
              // Własna lista obok mapy czytała wyłącznie ślady wyjazdów
              // (EditionTrack::effectiveForUser) i WYCINAŁA przejazdy solo,
              // które u wielu osób są większością — stąd rozjazd z /odkrycia.
              // Panel ride-feed pokazuje przejazdy WSZYSTKIE (solo z plikiem
              // GPX włącznie) i znalezione skarby, a podświetlanie wybranego
              // śladu robi ten sam kod co tam (ridemoreRideFeed) — zero
              // podtrzymywania drugiej implementacji listy. ?>
        <div class="disc-map">
            <div id="riderMap" class="disc-map__canvas"></div>

            <?php // TA SAMA KONTROLKA CO WSZĘDZIE (partial `map-layers.php`).
                  // Drzewo, podpisy i stan domyślny liczy od Etapu 2
                  // `Models\MapLayer` ze słownika (`RiderController::show`);
                  // tej stronie zostaje wyłącznie PRZEKAZANIE `$mapLayers`. ?>
            <?php
            $mlId = 'riderLayers';
            $mlLayers = $mapLayers;
            require __DIR__ . '/../partials/map-layers.php';
            ?>

            <?php // PANEL „OSTATNIA AKTYWNOŚĆ" — ten sam partial co na
                  // /odkrycia. $feedShowRider = false: na czyimś profilu
                  // autor powtarzałby jedno nazwisko w kółko. ?>
            <div class="disc-panels">
                <?php $feedShowRider = false; require __DIR__ . '/../partials/ride-feed.php'; ?>
            </div>

            <div id="riderLegend" class="hex-legend hex-legend--onmap"></div>
        </div>
        <?php // Legenda mapy — jedno zdanie na warstwę, w kolejności, w jakiej
              // rzucają się w oczy. Bez niej przerywana linia bez zdjętej mgły
              // wygląda na błąd, a jest informacją. ?>
        <p class="roster__lbl" style="margin-top:12px;">
            <?php if ($realCount > 0): ?>
            <b><?= $realCount ?> <?= $plural($realCount, 'ślad', 'ślady', 'śladów') ?></b>
            <?= __('z odbytych wyjazdów') ?><?php if ($hasDiscovery): ?> · <?= $num($discovery['cells']) ?>
            <?= $plural((int) $discovery['cells'], 'odkryte pole', 'odkryte pola', __('odkrytych pól')) ?>
            <?php // UWAGA przy edycji: od zmiany na mgłę (2026-08-12) to SZARE
                  // znaczy „nieodkryte", nie odwrotnie. Poprzedni podpis mówił
                  // „zacieniony teren" o terenie ODKRYTYM i po odwróceniu
                  // warstwy był po prostu nieprawdą. ?>
            <?= __('— reszta mapy pozostaje zamglona') ?><?php endif; ?>
            <?php endif; ?>
            <?php if ($plannedCount > 0): ?>
            <?= $realCount > 0 ? '<br>' : '' ?>
            <?= __n($plannedCount, '<b>{n}</b> trasa zapowiadana (linia przerywana) — z tego wyjazdu nikt nie wgrał śladu, więc nie odsłonił pól.', '<b>{n}</b> trasy zapowiadane (linia przerywana) — z tych wyjazdów nikt nie wgrał śladu, więc nie odsłoniły pól.', '<b>{n}</b> tras zapowiadanych (linia przerywana) — z tych wyjazdów nikt nie wgrał śladu, więc nie odsłoniły pól.') ?>

            <?php endif; ?>
            <?php if ($realCount > 1): ?>
            <br><?= __('Kliknij przejazd w panelu aktywności na mapie, żeby podświetlić go na mapie.') ?>

            <?php endif; ?>
        </p>
    </div>
    <?php // REGIONY — TEN SAM KOMPONENT CO „Twoje regiony" NA /odkrycia
          // (partials/region-emblems.php, prośba usera 2026-09-13). Zastąpił
          // chipy „region · 2×": heks wypełniony procentem mówi więcej niż
          // licznik wyjazdów i wygląda jak coś do zebrania. ?>
    <?php $grupyRegionow = regionEmblemGroups($regionsWorld ?? []); ?>
    <?php if ($grupyRegionow): ?>
    <div class="rp-regions">
        <h3 class="rp-sub"><?= $isOwnProfile ? __('Twoje regiony') : __('Regiony') ?> <span><?= __('zaawansowanie w każdym regionie z choć jednym odkrytym polem') ?></span></h3>
        <?php renderRegionEmblems($grupyRegionow); ?>
    </div>
    <?php endif; ?>
</section>
<script>
(function () {
    var el = document.getElementById('riderMap');
    if (!el || typeof ridemoreDiscoveryMap !== 'function') return;

    // TA SAMA KONTROLKA CO NA /odkrycia — scope 'rider' (decyzja usera
    // 2026-08-15). Wcześniej stała tu okrojona mapa zbudowana z gołych kafli;
    // różniła się od tamtej wszystkim poza wyglądem podkładu i wymagała własnej
    // obsługi warstw, której nie miała.
    //
    // Warstwa śladów dalej idzie kaflami (migr. 051) i to się nie zmienia:
    // przy 600 przejazdach wersja wektorowa pobierała ok. 108 MB i ok. 25 s do
    // pierwszego obrazu, a to jest strona PUBLICZNA. Wektorowy zostaje wyłącznie
    // ślad WYBRANY — jego trzeba przestylować i dociągnąć kadrem.
    var przelacznik = document.getElementById('riderLayers');

    var map = ridemoreDiscoveryMap(el, {
        context: 'rider',
        slug: <?= json_encode($riderSlug) ?>,
        endpoint: <?= json_encode($mapEndpoints['cells']) ?>,
        <?php // ŹRÓDŁA KAFLI — od Etapu 2 gotowe z kontrolera
              // (`Models\MapLayer::tileKeysFor`), kafle śladów TEJ osoby
              // (`u-{slug}`), nie warstwy społeczności. ?>
        sources: <?= json_encode($mapSources, JSON_UNESCAPED_SLASHES) ?>,
        <?php // Rodzina „Skarby" nie ma tu dzieci (profil nie rozbija na
              // odkryte/nieodkryte — pokazuje KOLEKCJĘ tej osoby), więc
              // `filters` niesie pustą listę dzieci; `ridemoreComposeFilter`
              // czyta to jako „rodzic zapalony = brak filtra `stan`",
              // a zawężenie do tej osoby robi samo `slug` wyżej. ?>
        filters: <?= json_encode($mapFilters, JSON_UNESCAPED_SLASHES) ?>,
        trailsHitEndpoint: <?= json_encode($mapEndpoints['trailsAt']) ?>,
        <?php // KLIK W ŚLAD — dymek z danymi przejazdu i linkiem, ten sam
              // mechanizm co przy szlaku wyżej (patrz nota w discovery-map.js).
              // Adres jest ten sam dla właściciela i obcego (2026-09-10) — to
              // moduł JS dokleja `&rider=` z opcji `slug` DWA WIERSZE WYŻEJ,
              // ta sama sztuczka co przy `treasuresEndpoint`. ?>
        ridesHitEndpoint: <?= json_encode($mapEndpoints['ridesAt'] ?? null) ?>,
        treasuresEndpoint: <?= json_encode($mapEndpoints['treasures']) ?>,
        treasureActions: <?= !empty($mapActions) ? 'true' : 'false' ?>,
        claimEndpoint: <?= json_encode($mapEndpoints['claim']) ?>,
        confirmEndpoint: <?= json_encode($mapEndpoints['confirm']) ?>,
        csrf: <?= json_encode(Core\Csrf::token()) ?>,
        legendEl: document.getElementById('riderLegend'),
        <?php // KADR Z SERWERA, nie z wczytanych plików: gotowy prostokąt
              // policzony z kolumn min/max w bazie, więc mapa zna swoje granice
              // bez pobierania historii przejazdów — i zna je ZANIM zapyta
              // o pola (od 2026-08-19 idzie opcją `bounds`, wcześniej był to
              // fitBounds po utworzeniu mapy, czyli pierwsze żądanie leciało
              // dla środka Polski, a obraz przeskakiwał). ?>
        bounds: <?= json_encode($tileBounds) ?>,
        zoom: 8,
        <?php // Stan początkowy z kontrolki, wspólnym czytnikiem (Etap 1).
              // Profil nie daje przełącznika „Skarby zdobyte", więc klucza
              // stąd nie ma i obie warstwy skarbów gasną razem — dokładnie
              // to, czego wymagała poprawka z 2026-08-23. ?>
        layers: ridemoreReadLayers(przelacznik)
    });

    if (przelacznik) {
        przelacznik.addEventListener('change', function (e) {
            var cb = e.target.closest('[data-layer]');
            if (cb) { map.ridemoreSetLayer(cb.dataset.layer, cb.checked); }
        });
    }

    <?php // Ślady tej osoby NIE SĄ już dokładane tutaj osobno (Etap 1b,
          // 2026-08-26). Rysuje je warstwa „Ślady" modułu, bo `sources.slady`
          // niesie klucz TEJ OSOBY — dzięki temu przełącznik obok mapy gasi
          // dokładnie to, co widać, zamiast gasić warstwę społeczności, której
          // na profilu i tak nie ma po co pokazywać. ?>

    // PANEL AKTYWNOŚCI NA MAPIE — ten sam moduł co na /odkrycia (2026-08-25).
    // `tracks` = „id przejazdu → GPX": klik w wiersz rysuje ślad wektorowo
    // na niebiesko WYBORU i dociąga kadr; wyjazdy wchodzą tu przez ślad
    // efektywny, a solo przez własny gpx_url przejazdu — ale wyłącznie na
    // WŁASNYM profilu (§27, 2026-08-26: plik solo zaczyna się pod domem).
    // Na cudzym profilu wiersz solo zostaje klikalny i dociąga sam kadr
    // z zapisanych pól (Support::trackUrlsForFeed).
    if (typeof ridemoreRideFeed === 'function') {
        ridemoreRideFeed(map, document.getElementById('discRideFeed'), {
            tracks: <?= json_encode($feedTrackUrls ?: new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        });
    }

    // Kadr ustawia sama mapa z opcji `bounds` wyżej — patrz nota przy niej.

    // --- AKTYWNOŚĆ POD MAPĄ STERUJE MAPĄ (decyzja usera 2026-08-15) ---------
    //
    // Wpis „wgrany ślad" i „znaleziony skarb" dotyczą TEJ mapy, więc kliknięcie
    // przewija do niej i zaznacza to, o czym mówi wpis — zamiast wyprowadzać
    // na kronikę albo, co gorsza, na mapę społeczności, czyli na cudze dane.
    //
    // Ślad rysuje ridemoreFocusTrack — TEN SAM helper, którego używa panel
    // aktywności na mapie (kolor WYBORU, kadr z wczytanego GPX). Jedna droga
    // rysowania zamiast dwóch, które mogłyby się rozjechać.
    function doMapy() {
        document.getElementById('mapa').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    var trackUrlsById = <?= json_encode($trackUrlsById ?: new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var rfWektor = null;

    function zaznaczSlad(id) {
        var url = trackUrlsById[id];
        if (!url) { return false; }
        if (rfWektor) { map.removeLayer(rfWektor); rfWektor = null; }
        rfWektor = window.ridemoreFocusTrack(map, url);
        return true;
    }

    // Pinezka skarbu wskazana z feedu. Pozycji NIE MA w HTML-u strony — idzie
    // przez API, bo tylko ono wie, ile z danego skarbu wolno pokazać temu, kto
    // patrzy (skarb ukryty oddaje środek pola, nie prawdziwe miejsce).
    var wskaznik = null;
    function pokazSkarb(id) {
        fetch(<?= json_encode($mapEndpoints['one']) ?> + '/' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (o) {
                if (!o || !o.treasure) { return; }
                var t = o.treasure;
                map.setView([t.lat, t.lon], Math.max(map.getZoom(), 13));
                if (wskaznik) { map.removeLayer(wskaznik); }
                // Okrąg, nie kolejna pinezka: pinezka skarbu już tam stoi
                // (warstwa skarbów), a druga w tym samym punkcie zasłoniłaby
                // pierwszą. Obwódka wskazuje, nie dubluje.
                wskaznik = L.circleMarker([t.lat, t.lon], {
                    radius: 18, color: '#8B4FBF', weight: 3, fill: false, dashArray: '4 3'
                }).addTo(map);
                setTimeout(function () {
                    if (wskaznik) { map.removeLayer(wskaznik); wskaznik = null; }
                }, 4000);
            })
            .catch(function () { /* brak sieci — wpis zachowa się jak zwykły link */ });
    }

    // DELEGACJA NA DOKUMENCIE, nie pętla po `.rf-item--mapa`.
    //
    // Ten skrypt siedzi w sekcji mapy, a lista aktywności jest NIŻEJ w HTML —
    // w chwili jego wykonania wpisów feedu nie ma jeszcze w DOM, więc pętla
    // podpinała zdarzenia do zera elementów i kliknięcie po prostu otwierało
    // link (zmierzone: klik we wpis o śladzie wychodził na kronikę).
    // Delegacja jest odporna także na doładowanie kolejnej strony aktywności.
    document.addEventListener('click', function (e) {
        var wpis = e.target.closest && e.target.closest('.rf-item--mapa');
        if (!wpis) { return; }

        var track = wpis.dataset.track;
        var skarb = wpis.dataset.treasure;

        if (track && zaznaczSlad(Number(track))) {
            e.preventDefault();
            doMapy();
            return;
        }
        if (skarb) {
            e.preventDefault();
            doMapy();
            pokazSkarb(skarb);
        }
        // Bez trafienia zostaje domyślne działanie linku — wpis nadal
        // gdzieś prowadzi, zamiast nie robić nic.
    });
})();
</script>

<?php // AKTYWNOŚĆ — co ta osoba napisała i wgrała, od najnowszego.
      //
      // Stoi ZARAZ POD MAPĄ, bo mapa odpowiada „gdzie jeździ", a to jest
      // naturalne następne pytanie: „i co z tego wynikło". Dalej idą trasy,
      // peleton i wyjazdy — czyli rzeczy coraz bardziej podsumowujące.
      //
      // POKAZUJEMY TREŚĆ, NIE ZDARZENIA: wpisy w kronice, komentarze, zdjęcia
      // i wgrane ślady. Nie ma tu „zapisał się", „wypisał się" ani „wszedł na
      // stronę" — to jest ta sama granica, którą trzyma Puls od Etapu 5,
      // przeniesiona z pytania „co się dzieje" na „kim ta osoba jest".
      // Szczegóły i uzasadnienie doboru typów: Models\RiderFeed. ?>
<?php $feed = $feed ?? ['items' => [], 'hasMore' => false]; ?>
<?php if (!empty($feed['items'])): ?>
<?php
    // Etykieta i ikona per typ wpisu — jedna tablica zamiast switcha
    // w szablonie. Czasownik w formie dokonanej, bo każdy wpis to rzecz
    // ZROBIONA, a nie stan.
    // `ton` dokłada kolor kafelka ikony (2026-08-22). Wszystkie szare znaczyło,
    // że lista osiemnastu pozycji czytała się jak jeden blok tekstu — a to są
    // rzeczy różne: napisany tekst, wgrane zdjęcia, przejechany teren.
    // Trzy tony, nie siedem: kolor ma grupować, a nie tworzyć drugi alfabet.
    $feedKinds = [
        'kronika'   => ['ikona' => 'edit',   'label' => __('Wpis w kronice'),          'ton' => ''],
        'komentarz' => ['ikona' => 'users',  'label' => __('Komentarz'),               'ton' => 'talk'],
        'odpowiedz' => ['ikona' => 'users',  'label' => __('Odpowiedź organizatora'),  'ton' => 'talk'],
        'faq'       => ['ikona' => 'users',  'label' => __('Wpis w FAQ'),              'ton' => 'talk'],
        'zdjecia'   => ['ikona' => 'camera', 'label' => __('Zdjęcia'),                 'ton' => 'pic'],
        'slad'      => ['ikona' => 'route',  'label' => __('Wgrany ślad'),             'ton' => 'map'],
        'solo'      => ['ikona' => 'route',  'label' => __('Przejazd solo'),           'ton' => 'map'],
        'skarb'     => ['ikona' => 'pin',    'label' => __('Znaleziony skarb'),        'ton' => 'map'],
    ];
?>
<section class="sec rp-ch" id="aktywnosc">
    <div class="rp-ch__hd">
        <span class="rp-ch__no"><?= __('05 · Dziennik') ?></span>
        <h2><?= __('Ostatnio') ?></h2>
        <?php if (!empty($feedTotal)): ?>
        <span class="rp-ch__meta"><?= (int) $feedTotal ?> <?= $plural((int) $feedTotal, 'wpis', 'wpisy', 'wpisów') ?></span>
        <?php endif; ?>
    </div>
    <div class="rf-list">
        <?php foreach ($feed['items'] as $item): ?>
        <?php $kind = $feedKinds[$item['type']] ?? ['ikona' => 'route', 'label' => __('Aktywność'), 'ton' => '']; ?>
        <?php
            // WPISY MAPOWE STERUJĄ MAPĄ, NIE WYPROWADZAJĄ ZE STRONY
            // (decyzja usera 2026-08-15). Wgrany ślad i znaleziony skarb
            // dotyczą TEJ mapy, wyżej — link do kroniki albo, co gorsza, na
            // mapę społeczności wyrzucał oglądającego z profilu, żeby pokazać
            // mu cudze dane.
            //
            // Zostaje <a> z prawdziwym href: bez JS wpis nadal prowadzi tam,
            // gdzie prowadził. JS przechwytuje kliknięcie dopiero, gdy mapa
            // faktycznie istnieje na stronie.
            $mapowy = ($item['type'] === 'slad' && !empty($item['trackId']))
                   || ($item['type'] === 'skarb' && !empty($item['treasureId']));
            // Kotwica NIE przechodzi przez View::url — ta doklejałaby bazę
            // aplikacji i „#mapa" zamieniało się w link na stronę główną
            // (zmierzone: /ridemore/#mapa).
            $adres = str_starts_with((string) $item['url'], '#')
                ? $item['url']
                : View::url($item['url']);
        ?>
        <a class="rf-item<?= $mapowy ? ' rf-item--mapa' : '' ?>" href="<?= htmlspecialchars($adres) ?>"
           <?php if (!empty($item['trackId'])): ?>data-track="<?= (int) $item['trackId'] ?>"<?php endif; ?>
           <?php if (!empty($item['treasureId'])): ?>data-treasure="<?= (int) $item['treasureId'] ?>"<?php endif; ?>>
            <span class="rf-item__ico<?= !empty($kind['ton']) ? ' rf-item__ico--' . $kind['ton'] : '' ?>"><?= Utils\Icon::render($kind['ikona']) ?></span>
            <span class="rf-item__b">
                <span class="rf-item__hd">
                    <b><?= htmlspecialchars($kind['label']) ?></b>
                    <?php // TYTUŁ TYLKO GDY WNOSI COŚ PONAD ETYKIETĘ. Przejazd
                          // solo bez nadanej nazwy nazywa się dokładnie tak, jak
                          // brzmi etykieta typu — a „Przejazd solo Przejazd solo"
                          // wygląda na błąd renderowania, nie na wpis. ?>
                    <?php if ($item['title'] !== $kind['label']): ?>
                    <span class="rf-item__ev"><?= htmlspecialchars($item['title']) ?></span>
                    <?php endif; ?>
                </span>
                <?php if (!empty($item['excerpt'])): ?>
                <span class="rf-item__tx"><?= htmlspecialchars($item['excerpt']) ?></span>
                <?php elseif ($item['type'] === 'zdjecia'): ?>
                <span class="rf-item__tx"><?= __n((int) $item['count'], '{n} zdjęcie z wyjazdu', '{n} zdjęcia z wyjazdu', '{n} zdjęć z wyjazdu') ?></span>
                <?php elseif ($item['type'] === 'skarb'): ?>
                <span class="rf-item__tx"><?= Utils\Icon::maybe($item['icon'] ?? null) ?><?= htmlspecialchars((string) ($item['label'] ?? __('Skarb'))) ?><?php
                    if (!empty($item['region'])): ?> · <?= htmlspecialchars($item['region']) ?><?php endif; ?>
                    · <b>+<?= (int) $item['points'] ?></b></span>
                <?php elseif ($item['type'] === 'slad'): ?>
                <span class="rf-item__tx"><?= htmlspecialchars(Format::distance((float) $item['km'])) ?><?php
                    if (!empty($item['label'])): ?> · <?= htmlspecialchars($item['label']) ?><?php endif; ?></span>
                <?php endif; ?>
            </span>
            <time class="rf-item__at"><?= htmlspecialchars(Format::dateShort(substr((string) $item['at'], 0, 10)) ?? '') ?></time>
        </a>
        <?php endforeach; ?>
    </div>
    <?php // STRONICOWANIE KROKOWE, tak samo jak przy przejazdach obok mapy
          // (uwaga usera 2026-08-14: „nie można pokazać 200 aktywności, bo to
          // zabije listę"). Różnica jest w tym, GDZIE się dzieje: tam cała lista
          // musi siedzieć w DOM-ie, bo kliknięcie steruje mapą, więc stronicuje
          // przeglądarka. Tutaj wiersze to zwykłe linki, więc strona przychodzi
          // z serwera i przy 200 wpisach do przeglądarki jedzie dziesięć, a nie
          // dwieście.
          //
          // Zwykłe linki, nie przyciski: adres z numerem strony da się podesłać,
          // a „wstecz" działa tak, jak user się spodziewa. Kotwica #aktywnosc
          // trzyma widok na sekcji zamiast wyrzucać na górę profilu. ?>
    <?php renderStepPager(['base' => $profilUrl, 'param' => 'aktywnosc', 'anchor' => 'aktywnosc',
        'page' => (int) $feed['page'] + 1, 'pages' => max(1, (int) ceil((int) $feedTotal / max(1, (int) ($feed['perPage'] ?? 10)))),
        'total' => (int) $feedTotal, 'from' => (int) $feed['from'], 'to' => (int) $feed['to'],
        'prev' => __('‹ Nowsze'), 'next' => __('Starsze ›')]); ?>
</section>
<?php endif; ?>

<?php // ============================================================================
      // KONTO W APCE (2026-08-29, tasks/done/apka-mobilna-skorupa.md).
      //
      // Te pozycje mieszkały w rozwijanym menu górnej belki. Belka apki straciła
      // to menu razem z nawigacją — a razem z nim zniknęłoby WYLOGOWANIE i cały
      // panel organizatora, gdyby nie wylądowały tutaj. „Profil" to piąty slot
      // dolnego paska, czyli jedno tapnięcie z każdego ekranu: bliżej niż
      // kieszonka rozwijana z górnego rogu.
      //
      // TYLKO NA WŁASNYM PROFILU i tylko w apce — na web menu w belce zostaje
      // bez zmian, a na cudzym profilu te linki nie mają sensu.
      //
      // ZAPYTANIA O ROLE ROBIMY TU, na jednym ekranie, zamiast na każdym
      // żądaniu w belce — to jest cała oszczędność tej przebudowy.
      // ============================================================================ ?>
<?php if ($isOwnProfile && APP_IS_APP):
    $konto = Core\Auth::user();
    $jestOrganizatorem = $konto ? Models\Organizer::hasProfile($konto->id) : false;
?>
<section class="rp-account">
    <h2><?= __('Konto') ?></h2>

    <?php // Przejazdy i ustawienia są w skrótach nagłówka — tu zostaje reszta menu konta. ?>
    <span class="rp-account__g"><?= __('Ja na rowerze') ?></span>
    <a href="<?= View::url('/odkrycia') ?>"><?= __('Moje odkrycia') ?></a>
    <a href="<?= View::url('/wydarzenia?mine=1') ?>"><?= __('Moje wyjazdy') ?></a>
    <a href="<?= View::url('/wiadomosci') ?>"><?= __('Wiadomości') ?></a>

    <?php // ORGANIZATOR I ADMIN POD ZWINIĘCIEM — większość kont to zwykli
          // rowerzyści, którym „Płatności" czy „Kafle map" nic nie mówią.
          // Ta sama zasada co w menu belki, gdzie blok organizatora pokazuje
          // się WYŁĄCZNIE organizatorom (Models\Organizer::hasProfile). ?>
    <?php if ($jestOrganizatorem): ?>
    <details class="rp-account__more">
        <summary><?= __('Organizuję') ?></summary>
        <a href="<?= View::url('/admin') ?>"><?= __('Panel organizatora') ?></a>
        <a href="<?= View::url('/wydarzenia/nowe') ?>"><?= __('Dodaj wydarzenie') ?></a>
        <a href="<?= View::url('/admin/platnosci') ?>"><?= __('Płatności') ?></a>
        <a href="<?= View::url('/admin/profil-rozliczeniowy') ?>"><?= __('Profil organizatora') ?></a>
    </details>
    <?php else: ?>
    <span class="rp-account__g"><?= __('Chcesz organizować?') ?></span>
    <a href="<?= View::url('/wydarzenia/nowe') ?>"><?= __('Wystaw pierwszy wyjazd') ?></a>
    <?php endif; ?>

    <?php if ($konto && $konto->isAdmin): ?>
    <details class="rp-account__more">
        <summary><?= __('Administracja') ?></summary>
        <a href="<?= View::url('/admin/uzytkownicy') ?>"><?= __('Użytkownicy') ?></a>
        <a href="<?= View::url('/admin/organizatorzy') ?>"><?= __('Organizatorzy') ?></a>
        <a href="<?= View::url('/admin/taksonomia') ?>"><?= __('Taksonomia') ?></a>
        <a href="<?= View::url('/admin/znane-trasy') ?>"><?= __('Znane trasy') ?></a>
        <a href="<?= View::url('/admin/skarby') ?>"><?= __('Skarby') ?></a>
        <a href="<?= View::url('/admin/punkty') ?>"><?= __('Punkty i bonusy') ?></a>
        <a href="<?= View::url('/admin/kafle') ?>"><?= __('Kafle map') ?></a>
    </details>
    <?php endif; ?>

    <?php // WYLOGOWANIE ZOSTAJE POST-em Z TOKENEM — przeniesione 1:1 z belki.
          // Jako zwykły link byłoby wykonalne cudzym obrazkiem na stronie
          // trzeciej, więc forma ma znaczenie, nie tylko wygląd. ?>
    <form method="post" action="<?= View::url('/wyloguj') ?>" class="rp-account__out">
        <?= Core\Csrf::field() ?>
        <button type="submit" class="link-button"><?= __('Wyloguj') ?></button>
    </form>
</section>
<?php endif; ?>

<?php // PRZYPIĘTA AKCJA „NAPISZ WIADOMOŚĆ" W APCE (Faza 4 przebudowy UX apki,
      // 2026-08-29) — ten sam komponent `.mbar` co przycisk zapisu na stronie
      // wydarzenia; TEN SAM warunek, który wyżej ukrył przycisk w treści.
      // `body.has-mbar` (RiderController::show()) rezerwuje mu miejsce. ?>
<?php if (!$isOwnProfile && !empty($viewerId) && APP_IS_APP): ?>
<div class="mbar">
    <span class="mbar__p">
        <b><?= htmlspecialchars($p['name']) ?></b>
        <small><?= __('profil rowerzysty') ?></small>
    </span>
    <a class="btn" href="<?= View::url('/wiadomosci/z/' . (int) $p['id']) ?>"><?= __('Napisz wiadomość') ?></a>
</div>
<?php endif; ?>

<?php // Wspólny lightbox — dymek skarbu na mapie wstawia kafelki `.ph-link`
      // z galerią (SKA/14). Bez tego kliknięcie w zdjęcie wyprowadzałoby
      // z mapy do nowej karty. ?>
<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
