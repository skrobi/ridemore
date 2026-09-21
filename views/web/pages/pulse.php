<?php
// views/web/pages/pulse.php
// PULS — /puls (Etap 5).
//
// To NIE jest ściana postów. Jednostką każdego wpisu jest WYJAZD albo GRUPA,
// nigdy czyjeś kliknięcie: nie ma „Jan polubił", nie ma „Kasia dodała zdjęcie",
// nie ma liczników reakcji. Każdy wpis odpowiada na pytanie „co się wydarzyło
// na trasie albo wokół konkretnego wyjazdu".
//
// Jedyna dozwolona reakcja to „Jadę następnym razem" — i ona WYKONUJE PRACĘ,
// zamiast podbijać licznik: zapisuje realne zainteresowanie (ten sam status
// 'zainteresowany', co przycisk na stronie wydarzenia), więc od razu wraca do
// systemu jako część składu i sygnał dla organizatora.
//
// Rytm jest TYGODNIOWY, nie dzienny. Pusty dzień wygląda jak awaria, spokojny
// tydzień wygląda naturalnie — a przy realnym natężeniu wyjazdów rowerowych
// spokojne dni są normą, nie wyjątkiem.
//
// Zero nowych klas CSS: .op-head, .sec/.box, .op-card/.op-grid, .eyebrow/.blaze,
// .roster__lbl, .tag/.tags, .btn.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

$items = $items ?? [];
$pelotonPlans = $pelotonPlans ?? [];
$isLoggedIn = $isLoggedIn ?? false;

// Liczba mnoga przez Core\Lang (2026-09-16) — w innym języku formy idą
// ze słownika; lokalna kopia polskiej reguły tłumaczyć nie umiała.
$plural = static fn (int $n, string $one, string $few, string $many): string => __n($n, $one, $few, $many);

// Nagłówek grupy czasowej. Świadomie zgrubny — „dziś / w tym tygodniu /
// wcześniej" zamiast dokładnych dat, żeby rzadszy ruch nie wyglądał na dziury
// w kalendarzu.
$bucketFor = static function (?string $at): string {
    if (!$at) { return __('Wcześniej'); }
    $ts = strtotime($at);
    $days = (int) floor((time() - $ts) / 86400);
    if ($days <= 0) { return __('Dzisiaj'); }
    if ($days <= 7) { return __('W tym tygodniu'); }
    if ($days <= 14) { return __('W zeszłym tygodniu'); }
    return __('Wcześniej');
};

$eyebrowFor = static function (string $type): array {
    return match ($type) {
        'przejazd' => [__('Przejechali'), '--s-red'],
        'sklad'    => [__('Skład się zbiera'), '--s-blue'],
        'kronika'  => [__('Nowe w kronice'), '--s-green'],
        'wezwanie' => [__('Ktoś szuka towarzystwa'), '--s-purple'],
        // Dopisane przy okazji SKA/12: typ 'zapis' istniał od PULS/1, ale nigdy
        // nie trafił do tej mapy i spadał na `default`, więc każdy zapis miał
        // nagłówek „Puls" zamiast własnego.
        'zapis'    => [__('Ktoś dołączył'), '--s-blue'],
        // Dwa jedyne wpisy bez wyjazdu (SKA/12). Nowe skarby dostają kolor
        // „wezwania", bo są tym samym gatunkiem wpisu — zaproszeniem, nie
        // relacją. Pierwsze znalezienie dostaje kolor przejazdu, bo jak
        // przejazd mówi o czymś, co JUŻ się wydarzyło w terenie.
        'skarb-nowe'     => [__('Nowe skarby na mapie'), '--s-purple'],
        'skarb-pierwszy' => [__('Pierwszy raz znaleziony'), '--s-red'],
        // Wgrane ślady (2026-09-11) — kolor przejazdu, bo to ten sam gatunek
        // wpisu: relacja o czymś, co JUŻ się wydarzyło w terenie, a nie
        // zaproszenie. Różni się tylko tym, że mówi o jednej osobie.
        'slady-wgrane'   => [__('Nowe ślady na mapie'), '--s-red'],
        default    => [__('Puls'), '--accent'],
    };
};

require __DIR__ . '/../partials/breadcrumbs.php';
require __DIR__ . '/../partials/rider-avatar.php';
require_once __DIR__ . '/../partials/region-link.php';
?>

<div class="op-head">
    <div class="op-head__c">
        <h1><?= __('Puls') ?></h1>
        <p class="op-head__sub"><?= __('Kto przejechał trasę, na jakie wyjazdy zbierają się grupy
            i kto szuka towarzystwa. Bez lajków i bez postów — tylko to, co dzieje się
            na trasach.') ?></p>
    </div>
</div>

<?php if ($pelotonPlans): ?>
<?php
    // Nagłówek tej sekcji był dwuznaczny („Co teraz robimy" / „Twoi ludzie") —
    // zalogowany user nie wiedział, kogo dotyczy (zgłoszenie 2026-08-12).
    // Teraz mówi wprost: to są osoby z JEGO peletonu, czyli ci, z którymi
    // faktycznie już jechał.
?>
<section class="sec" id="twoi-ludzie">
    <div class="box">
        <p class="eyebrow" style="margin-top:0;"><span class="blaze" style="--bz:var(--blaze-dark);"></span><?= __('Twój peleton') ?></p>
        <h2><?= __('Ludzie z Twojego peletonu jadą') ?></h2>
        <p class="op-head__sub" style="margin-top:6px;"><?= __('Osoby, z którymi już przejechałeś trasę,
            zapisały się na te wyjazdy.') ?></p>
        <?php
            // NIE .op-card: cała karta byłaby jednym linkiem do wydarzenia, a
            // wtedy imion nie da się podlinkować do profili (zagnieżdżone <a>
            // to nieprawidłowy HTML). Ten blok jest o LUDZIACH, więc każdy
            // człowiek musi być klikalny — stąd zwykły wiersz z osobnymi linkami.
        ?>
        <?php foreach ($pelotonPlans as $plan): ?>
        <div class="roster__grp">
            <div class="roster">
                <span class="avs">
                    <?php foreach (array_slice($plan['mates'], 0, 6) as $mate): ?>
                    <?php renderRiderAvatar($mate['initials'], $mate['slug'], $mate['name'], $mate['avatarUrl'] ?? null); ?>
                    <?php endforeach; ?>
                </span>
                <span class="roster__names">
                    <?php foreach ($plan['mates'] as $i => $mate): ?>
                    <?php if ($i > 0): ?>, <?php endif; ?>
                    <b><?= renderRiderName($mate['name'], $mate['slug']) ?></b>
                    <?php endforeach; ?>
                    <?= count($plan['mates']) === 1 ? __('jedzie na') : __('jadą na') ?>
                </span>
            </div>
            <h3 class="op-card__t" style="font-size:18px;margin-top:10px;">
                <a href="<?= View::url('/events/' . $plan['eventSlug']) ?>?termin=<?= (int) $plan['editionId'] ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($plan['title']) ?></a>
            </h3>
            <p class="op-card__m">
                <?= htmlspecialchars(Format::dateShort($plan['startDate']) ?? '') ?>
                <?= $plan['regionLabel'] ? ' · ' . renderRegionLinks($plan['regionLabel']) : '' ?>
            </p>
            <div class="op-head__act" style="margin-top:10px;">
                <a class="btn btn--sm" href="<?= View::url('/events/' . $plan['eventSlug']) ?>?termin=<?= (int) $plan['editionId'] ?>"><?= __('Dołącz do nich') ?></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!$items): ?>
<section class="sec">
    <div class="box">
        <p class="lead"><?= __('Na razie cicho. Puls zapełnia się sam, gdy grupy wyjeżdżają na trasy,
            zbierają się składy i powstają kroniki — nic tu nie trzeba pisać ręcznie.') ?></p>
        <div class="op-head__act">
            <a class="btn" href="<?= View::url('/wydarzenia') ?>"><?= __('Zobacz nadchodzące wyjazdy') ?></a>
        </div>
    </div>
</section>
<?php else: ?>
<div class="pulse-feed">
<?php $lastBucket = null; ?>
<?php foreach ($items as $item): ?>
<?php
    $bucket = $bucketFor($item['at']);
    [$eyebrowLabel, $colorVar] = $eyebrowFor($item['type']);
?>
<?php if ($bucket !== $lastBucket): ?>
<?php $lastBucket = $bucket; ?>
<h2 class="section-title"><?= htmlspecialchars($bucket) ?></h2>
<?php endif; ?>

<?php
    // WPIS PULSU — kompaktowy wiersz POZIOMY, nie karta pionowa.
    //
    // Pierwsza wersja reużywała .hcard ze strony głównej i każdy wpis miał
    // ~356 px wysokości — jeden przejazd zajmował cały ekran (zgłoszenie usera).
    // Feed rządzi się inną logiką niż strona wyjazdu: ma pozwolić PRZEBIEC
    // wzrokiem kilka zdarzeń naraz, więc treść idzie w lewo, a miniatura
    // (mała, kwadratowa) w prawo. Cel: 3-4 wpisy na ekran zamiast jednego.
    $c = $item['card'];
?>
<section class="sec">
    <div class="box pulse-item">
        <div class="pulse-item__grid">
        <div class="pulse-item__main">
        <p class="eyebrow" style="margin-top:0;background:var(--tint);color:var(--ink-soft);">
            <span class="blaze" style="--bz:var(<?= $colorVar ?>);"></span><?= htmlspecialchars($eyebrowLabel) ?>
        </p>

        <?php // Wpisy o skarbach nie mają wyjazdu, więc i tytuł nie prowadzi do
              // strony wydarzenia — bez tego warunku leciałby link
              // /events/?termin=0, czyli 404 na każdym takim wpisie.
              //
              // ADRES SKARBU KADRUJE MAPĘ NA NIM (2026-09-11, zgłoszenie usera:
              // „skarby, które są pierwszy raz znalezione, linkują nie do
              // skarbu, tylko do mapy społecznościowej"). Tytuł wpisu jest
              // NAZWĄ konkretnego skarbu, a prowadził na mapę całego kraju —
              // czyli obiecywał rzecz, a dawał katalog.
              //
              // `?skarb={id}` to ten sam parametr, którym posługuje się
              // powiadomienie „nowy skarb w Twojej okolicy"
              // (DiscoveryController::kadrNaSkarbie). Model oddaje `treasureId`
              // WYŁĄCZNIE dla skarbu jawnego i tylko dla wpisu opisującego
              // JEDEN skarb — przy zbiorczym „8 nowych skarbów" mapa okolicy
              // jest prawidłową odpowiedzią i adres zostaje bez parametru. ?>
        <?php $adresSkarbu = View::url('/odkrycia') . '?mapa=spolecznosc'
            . (!empty($item['treasureId']) ? '&skarb=' . (int) $item['treasureId'] : ''); ?>
        <h3 class="pulse-item__title">
            <?php if ($item['eventSlug'] !== null): ?>
            <a href="<?= View::url('/events/' . $item['eventSlug']) ?>?termin=<?= (int) $item['editionId'] ?>"><?= htmlspecialchars($item['title']) ?></a>
            <?php elseif ($item['type'] === 'slady-wgrane'): ?>
            <?php // Tytułem jest IMIĘ, więc link prowadzi do profilu tej osoby —
                  // jedyny wpis w Pulsie, który wskazuje na człowieka, a nie na
                  // wyjazd ani na mapę (decyzja usera 2026-09-11, uzasadnienie
                  // i bramka widoczności w Models\Pulse::trackUploads). ?>
            <a href="<?= View::url('/rowerzysta/' . $item['riderSlug']) ?>"><?= htmlspecialchars($item['title']) ?></a>
            <?php else: ?>
            <a href="<?= htmlspecialchars($adresSkarbu) ?>"><?= Utils\Icon::maybe($item['icon'] ?? null) ?><?= htmlspecialchars($item['title']) ?></a>
            <?php endif; ?>
        </h3>

        <?php if ($item['type'] === 'skarb-nowe' || $item['type'] === 'skarb-pierwszy'): ?>
        <?php
            // Fakty dla skarbu — te same, co przy wyjeździe, tyle że jest ich
            // mniej: region i kategoria. Reszta paska faktów wymaga karty
            // turnusu, której te wpisy nie mają.
            // Już jako HTML: region jest linkiem do swojej strony (2026-09-14).
            $faktySkarbu = array_filter([
                renderRegionLinks($item['regionLabel'] ?? null),
                htmlspecialchars((string) ($item['categoryLabel'] ?? '')),
            ]);
            // RZADKOŚĆ — chip, nie kolejny człon tekstu w faktach: „epicki"
            // wpisane słowem ginęło obok reszty (zgłoszenie usera). Dostępna
            // tylko przy pierwszym znalezieniu — `skarb-nowe` jest zbiorczym
            // wpisem o WIELU skarbach naraz, więc jedna rzadkość by kłamała.
            $rzadkoscSkarbu = $item['type'] === 'skarb-pierwszy'
                ? Models\Treasure::rarityLabel($item['rarity'] ?? null)
                : null;
        ?>
        <?php if ($faktySkarbu || $rzadkoscSkarbu): ?>
        <p class="pulse-item__facts">
            <?= implode(' · ', $faktySkarbu) ?>
            <?php if ($rzadkoscSkarbu): ?>
            <span class="rarity-chip is-rarity-<?= strtolower($item['rarity']) ?>"><?= htmlspecialchars($rzadkoscSkarbu) ?></span>
            <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($c): ?>
        <?php
            // FAKTY jedną linią, mono — dokładnie te, których user szukał
            // w feedzie: kiedy, ile km, jak trudno, gdzie, ile kosztuje.
            // Fakty budowane jako GOTOWY HTML, nie jako tekst do sklejenia:
            // region jest linkiem do listy wyjazdów z tych stron. Powód jest
            // podwójny — nawigacyjny (kliknięcie w „Bieszczady" ma pokazać
            // Bieszczady) i SEO: region działa wtedy jak tag i buduje wewnętrzne
            // linkowanie, którego ten serwis prawie nie miał.
            $esc = static fn($v) => htmlspecialchars((string) $v);
            $facts = [];
            $facts[] = $esc(Format::dateShort($c['startDate']));
            if ($c['durationDays'] > 1) { $facts[] = $esc(__('{n} dni', ['n' => $c['durationDays']])); }
            if ($c['distanceKm'] > 0)   { $facts[] = $esc(Format::distance((float) $c['distanceKm'])); }
            if ($c['difficultyLabel'])  { $facts[] = $esc($c['difficultyLabel']); }
            if ($c['regionName']) {
                // Strona regionu (2026-09-14) zamiast filtra listy — jak wszędzie.
                $facts[] = renderRegionLinks($c['regionName']);
            }
            $facts[] = $esc($c['isPaid']
                ? Format::price((float) $c['priceAmount'], Format::currencySymbol($c['currency']))
                : __('Bezpłatnie'));
        ?>
        <p class="pulse-item__facts"><?= implode(' · ', array_filter($facts)) ?></p>
        <?php endif; ?>

        <?php
            // CO SIĘ WYDARZYŁO — sedno wpisu. Pod faktami, bo fakty odpowiadają
            // „co to za wyjazd", a to zdanie „co się z nim właśnie stało".
        ?>
        <p class="pulse-item__what">
            <?php if ($item['type'] === 'przejazd'): ?>
            <?= __n((int) $item['peopleCount'], '<b>{n} osoba</b> przejechała tę trasę razem.', '<b>{n} osoby</b> przejechały tę trasę razem.', '<b>{n} osób</b> przejechało tę trasę razem.') ?>

            <?php if ($item['firstTimers'] > 0 && $item['regionLabel']): ?>
            <?= __n((int) $item['firstTimers'], 'Dla {n} osoby to był pierwszy raz w regionie {region}.', 'Dla {n} osób to był pierwszy raz w regionie {region}.', 'Dla {n} osób to był pierwszy raz w regionie {region}.', ['region' => renderRegionLinks($item['regionLabel'])]) ?>

            <?php endif; ?>
            <?php if ($item['newPairs'] > 0): ?>
            <?= __n((int) $item['newPairs'], 'Zawiązała się {n} nowa znajomość.', 'Zawiązały się {n} nowe znajomości.', 'Zawiązało się {n} nowych znajomości.') ?>

            <?php endif; ?>
            <?php // Discovery (Etap 8) jako kolejne zdanie TEGO SAMEGO wpisu, nie
                  // jako osobny typ: odkrycie nie jest zdarzeniem obok przejazdu,
                  // tylko jego skutkiem. Dopisek prowadzi na wspólną mapę — to
                  // jedyne wyjście z Pulsu w stronę Discovery, jakiego potrzeba. ?>
            <?php if (!empty($item['newCells'])): ?>
            <?= __n((int) $item['newCells'], 'Przy okazji odkryli {hex} <b>nowe pole</b> na {mapa}.', 'Przy okazji odkryli {hex} <b>nowe pola</b> na {mapa}.', 'Przy okazji odkryli {hex} <b>nowych pól</b> na {mapa}.', [
                'hex'  => '<b class="hexn">' . Utils\Icon::render('hex') . (int) $item['newCells'] . '</b>',
                'mapa' => '<a href="' . View::url('/odkrycia/spolecznosc') . '">' . __('wspólnej mapie') . '</a>',
            ]) ?>

            <?php endif; ?>

            <?php elseif ($item['type'] === 'sklad'): ?>
            <?= __n((int) $item['peopleCount'], 'Na ten wyjazd zapisała się już <b>{n} osoba</b>.', 'Na ten wyjazd zapisały się już <b>{n} osoby</b>.', 'Na ten wyjazd zapisało się już <b>{n} osób</b>.') ?>

            <?php if ($item['spotsLeft'] !== null): ?>
            <?php // Bez wykrzykników i bez „już tylko" — sztuczna pilność przy
                  // rytmie tygodniowym wypala się po trzech wejściach. Liczba
                  // miejsc mówi sama za siebie. ?>
            <?= __n((int) $item['spotsLeft'], 'Jest jeszcze {n} wolne miejsce.', 'Są jeszcze {n} wolne miejsca.', 'Jest jeszcze {n} wolnych miejsc.') ?>

            <?php endif; ?>

            <?php elseif ($item['type'] === 'kronika'): ?>
            <?php // Podmiotem są LUDZIE, nie kronika: „W kronice pojawiły się wpisy"
                  // opisuje zmianę w bazie, „Uczestnicy dorzucili zdjęcia" opisuje
                  // to, co zrobili ludzie. Ta sama informacja, druga jest zdaniem,
                  // które ktoś mógłby powiedzieć na głos. ?>
            <?= __n((int) $item['entriesCount'], '<b>{n} uczestnik</b> dorzucił swoje wspomnienia z trasy.', '<b>{n} uczestników</b> dorzuciło swoje wspomnienia z trasy.', '<b>{n} uczestników</b> dorzuciło swoje wspomnienia z trasy.') ?>


            <?php elseif ($item['type'] === 'wezwanie'): ?>
            <?php // Region CELOWO nie wchodzi do tego zdania, choć kusi: nazwy
                  // regionów są w słowniku w mianowniku („Mazury", „Beskidy"),
                  // a zdanie potrzebuje miejscownika („na Mazurach",
                  // „w Beskidach"). Polskiej odmiany nie da się wyprowadzić
                  // regułą, a „na wyjazd w Mazury" czyta się gorzej niż brak
                  // regionu. I tak stoi w linijce faktów wyżej — jako link. ?>
            <?= __('Ktoś szuka towarzystwa — ma trasę i wolny termin, nie chce jechać sam.') ?>


            <?php elseif ($item['type'] === 'zapis'): ?>
            <?php // Bez imienia — wpis mówi ILE osób i na co, nigdy kto.
                  // Zwijany do jednego wiersza na wyjazd i dobę (patrz
                  // Models\Pulse::signups), więc jeden popularny wyjazd nie
                  // zasypuje feedu dwudziestoma identycznymi wierszami. ?>
            <?= __('Ktoś się właśnie zapisał — skład zaczyna się zbierać.') ?>

            <?php elseif ($item['type'] === 'skarb-nowe'): ?>
            <?php // Zdanie mówi „są do wzięcia", nie „ktoś je postawił" —
                  // sprawcą tego wpisu jest teren, nie admin. ?>
            <?= (int) $item['count'] === 1 ? __('Nowy skarb czeka na pierwszą osobę, która tam dojedzie.') : __('Czekają w terenie na pierwszą osobę, która tam dojedzie.') ?>

            <?php elseif ($item['type'] === 'slady-wgrane'): ?>
            <?php // BEZ CZASOWNIKA W FORMIE OSOBOWEJ — „wgrał"/„wgrała" wymusza
                  // rodzaj, którego to konto nie deklaruje, a „wgrał(a)" czyta
                  // się jak formularz urzędowy. Podmiotem zdania są więc ŚLADY,
                  // nie człowiek: informacja ta sama, a zdanie działa dla
                  // każdego. Ta sama zasada, dla której reszta Pulsu mówi
                  // „zapisało się 5 osób", a nie „Kasia zapisała się". ?>
            <?= __n((int) $item['count'], '<b>{n} nowy ślad</b> trafił na mapę', '<b>{n} nowe ślady</b> trafiły na mapę', '<b>{n} nowych śladów</b> trafiło na mapę') ?><?php
                if ($item['km'] > 0): ?> <?= __('— razem') ?> <b><?= htmlspecialchars(Format::distance((float) $item['km'])) ?></b><?php
                endif; ?>.
            <?php if (!empty($item['cellsNew'])): ?>
            <?php // Ten sam dopisek i ten sam adres co przy przejeździe grupowym:
                  // odkryte pola są SKUTKIEM wgrania śladu, więc zdaniem dalszym
                  // tego samego wpisu, a nie osobnym typem. ?>
            <?= __n((int) $item['cellsNew'], 'Przy okazji odkryło się {hex} <b>nowe pole</b> na {mapa}.', 'Przy okazji odkryły się {hex} <b>nowe pola</b> na {mapa}.', 'Przy okazji odkryło się {hex} <b>nowych pól</b> na {mapa}.', [
                'hex'  => '<b class="hexn">' . Utils\Icon::render('hex') . (int) $item['cellsNew'] . '</b>',
                'mapa' => '<a href="' . View::url('/odkrycia/spolecznosc') . '">' . __('wspólnej mapie') . '</a>',
            ]) ?>

            <?php endif; ?>

            <?php elseif ($item['type'] === 'skarb-pierwszy'): ?>
            <?php // Bez imienia — tak samo jak przy zapisach. Wpis mówi, że
                  // skarb przestał czekać; kto go zdjął z mapy, jest sprawą
                  // jego profilu. ?>
            <?= __('Ktoś dojechał tam pierwszy — ten skarb zniknął z listy czekających.') ?>

            <?php endif; ?>
        </p>

        <div class="pulse-item__act">
            <?php if ($item['type'] === 'przejazd' || $item['type'] === 'kronika'): ?>
            <a class="btn btn-secondary" href="<?= View::url('/kronika/' . $item['eventSlug']) ?>?termin=<?= (int) $item['editionId'] ?>"><?= __('Zobacz kronikę') ?></a>
            <?php endif; ?>

            <?php if ($item['type'] === 'slady-wgrane'): ?>
            <a class="btn btn-secondary" href="<?= View::url('/rowerzysta/' . $item['riderSlug']) ?>"><?= __('Zobacz profil') ?></a>
            <?php endif; ?>

            <?php if ($item['type'] === 'skarb-nowe' || $item['type'] === 'skarb-pierwszy'): ?>
            <?php // Ten sam adres co tytuł wyżej — z kadrem na skarbie, gdy
                  // wpis opisuje jeden i widać go na mapie. ?>
            <a class="btn btn-secondary" href="<?= htmlspecialchars($adresSkarbu) ?>"><?= __('Zobacz na mapie') ?></a>
            <?php endif; ?>

            <?php if ($item['isUpcoming'] && $isLoggedIn): ?>
            <?php
                // JEDYNA reakcja w całym Pulsie — i nie jest lajkiem. Zapisuje
                // realne zainteresowanie przez ISTNIEJĄCĄ akcję
                // RsvpController::markInterested (status 'zainteresowany'), więc
                // od razu widać ją w składzie na stronie wydarzenia i w Pulsie
                // innych osób. Reakcja, która wykonuje pracę zamiast liczyć kliknięcia.
            ?>
            <form method="post" action="<?= View::url('/wydarzenia/' . $item['eventSlug'] . '/zainteresowany') ?>">
                <?= Core\Csrf::field() ?>
                <input type="hidden" name="edition_id" value="<?= (int) $item['editionId'] ?>">
                <button type="submit" class="btn"><?= __('Jadę następnym razem') ?></button>
            </form>
            <?php elseif ($item['isUpcoming']): ?>
            <a class="btn btn--sm" href="<?= View::url('/events/' . $item['eventSlug']) ?>?termin=<?= (int) $item['editionId'] ?>"><?= __('Zobacz wyjazd') ?></a>
            <?php endif; ?>
        </div>
        </div><?php // .pulse-item__main ?>

        <?php if ($item['type'] === 'slady-wgrane' && (int) ($item['mapReady'] ?? 0) > 0): ?>
        <?php
            // MINIATURĄ WPISU O ŚLADACH JEST SAM ŚLAD. Ten wpis nie ma wyjazdu,
            // więc nie ma ani zdjęcia z kroniki, ani okładki organizatora —
            // do 2026-09-12 zostawało po prawej puste miejsce. Kwadratowy
            // wariant tego samego obrazka, który na stronie głównej jest tłem
            // karty (TileController::trackGroupMap).
            //
            // LINK JAK NA KARCIE: jeden ślad prowadzi do TEGO przejazdu, wiele
            // — na profil. Tytuł wpisu wyżej zostaje przy profilu niezależnie
            // od liczby, bo tytułem jest IMIĘ, a imię prowadzi do człowieka.
            //
            // `mapReady > 0` jest warunkiem koniecznym — patrz ta sama nota
            // przy karcie w home.php.
            $sladTarget = ($item['rideId'] ?? null) !== null
                ? View::url('/przejazd/' . (int) $item['rideId'])
                : View::url('/rowerzysta/' . $item['riderSlug']);
        ?>
        <a class="pulse-item__ph" href="<?= htmlspecialchars($sladTarget) ?>" aria-hidden="true" tabindex="-1">
            <img src="<?= htmlspecialchars(View::url('/assets/tiles/slad/' . $item['mapKey'] . '/kwadrat/' . $item['mapStamp'] . '.png')) ?>"
                 alt="" width="264" height="264" loading="lazy">
        </a>
        <?php elseif ($item['photoUrl']): ?>
        <?php
            // MINIATURA po prawej — kwadrat, nie pas na całą szerokość.
            // Źródło: najpierw zdjęcie z kroniki (to, co uczestnicy przywieźli
            // z trasy), dopiero potem okładka organizatora — patrz Models\Pulse.
            // width/height ustawione, żeby nie było przeskoku układu przy
            // doczytywaniu obrazka.
            $photoTarget = ($item['type'] === 'przejazd' || $item['type'] === 'kronika')
                ? View::url('/kronika/' . $item['eventSlug']) . '?termin=' . (int) $item['editionId']
                : View::url('/events/' . $item['eventSlug']) . '?termin=' . (int) $item['editionId'];
        ?>
        <a class="pulse-item__ph" href="<?= htmlspecialchars($photoTarget) ?>" aria-hidden="true" tabindex="-1">
            <img src="<?= htmlspecialchars(View::url($item['photoUrl'])) ?>" alt="" width="150" height="150" loading="lazy">
        </a>
        <?php endif; ?>
        </div><?php // .pulse-item__grid ?>
    </div>
</section>
<?php endforeach; ?>
</div><?php // .pulse-feed ?>

<?php // STRONICOWANIE — zwykły link, nie doczytywanie w tle. Feed ma rytm
      // tygodniowy, nie strumieniowy: nieskończone przewijanie sugerowałoby,
      // że zawsze jest coś nowego, a tu spokojny tydzień jest normalnym stanem.
      // Link zamiast przycisku, bo to nawigacja — działa też bez JS i da się
      // otworzyć w nowej karcie. ?>
<?php if (!empty($nextCursor)): ?>
<div class="pulse-more">
    <a class="btn btn-secondary" href="<?= View::url('/puls') ?>?przed=<?= urlencode($nextCursor) ?>"><?= __('Pokaż starsze') ?></a>
</div>
<?php elseif (!empty($isPaged)): ?>
<div class="pulse-more">
    <p class="desc" style="margin:0 0 12px;"><?= __('To już koniec — Puls sięga trzech tygodni wstecz.') ?></p>
    <a class="btn btn-secondary" href="<?= View::url('/puls') ?>"><?= __('Wróć na początek') ?></a>
</div>
<?php endif; ?>
<?php endif; ?>
