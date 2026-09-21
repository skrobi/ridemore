<?php
// views/web/pages/organizer-profile.php
// Przebudowa wg szablony/organizator-v3.html (2026-08-01).
//
// Zasada nadrzędna tej przebudowy: KAŻDY, kto kiedykolwiek doda jakiekolwiek
// wydarzenie (nawet samo "Pokręcę z kimś"), dostaje automatycznie utworzony
// organizer_profiles (patrz Organizer::ensureProfile(), wołane z
// EventController::finishCreate()) — zawsze typu 'peer', ze WSZYSTKIMI
// pozostałymi polami pustymi (bio/miasto/rok założenia/języki/social/flagi
// bezpieczeństwa). Taki "nieuzupełniony" profil jest z definicji
// społecznościowy i NIGDY nie pokazuje zmyślonych/pustych wartości — zamiast
// tego ciągniemy z konta i z tego, co organizator już zrobił (memberSinceYear
// z organizer_profiles.created_at, specializationChips z jego REALNYCH
// wydarzeń, itd. — patrz sekcje niżej). Ten sam duch co reszta serwisu
// (usunięte attendance_confirmed_rate, niefabrykowane statystyki).
//
// Oczekuje: $organizer (?Models\Organizer), $stats, $specialization,
// $coverPhotos, $totalCoverPhotos, $heroCover (?array url/eventTitle/
// eventDate), $ridingProfile, $upcomingEvents, $historyEvents/$historyTotal/
// $historyExpanded, $reviewStats, $reviews, $isAdminViewer, $isOwnerViewer,
// $missingFields (string[], tylko dla ownera), $claimed.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Auth;
use Core\Csrf;
use Models\Message;
use Utils\Format;
use Utils\Icon;
use Utils\View;

if (!$organizer) {
    echo '<h1>' . __('Nie znaleziono organizatora') . '</h1>';
    return;
}

$initials = mb_substr($organizer->name, 0, 2);
$isOperator = $organizer->organizerType === 'professional_operator';
$location = trim(implode(', ', array_filter([$organizer->city, $organizer->regionName])));

$FORMAT_META = [
    'ustawka'                => ['label' => __('Zorganizowane wydarzenie'), 'color' => 'var(--s-red)'],
    'wycieczka_wielodniowa'  => ['label' => __('Wielodniówka'), 'color' => 'var(--s-blue)'],
    'pokrec_z_kims'          => ['label' => __('Pokręcę z kimś'), 'color' => 'var(--s-green)'],
    'wyscig'                 => ['label' => __('Wyścig'), 'color' => 'var(--s-purple)'],
];

$safetyItems = [
    ['flag' => $organizer->safetySweepRider, 'label' => __('Zamykający grupę'), 'desc' => __('Ktoś jedzie na końcu i pilnuje, żeby nikt nie został sam.')],
    ['flag' => $organizer->safetyRouteKnown, 'label' => __('Prowadzący zna trasę'), 'desc' => __('Wyjazd prowadzi ktoś, kto jechał ją wcześniej.')],
    ['flag' => $organizer->safetyFirstAidKit, 'label' => __('Apteczka w grupie'), 'desc' => null],
    ['flag' => $organizer->safetySupportVehicle, 'label' => __('Samochód wsparcia'), 'desc' => __('Jedzie za grupą, zabiera bagaże i rowery po awarii.')],
    ['flag' => $organizer->safetyFirstAidCertified, 'label' => __('Kurs pierwszej pomocy'), 'desc' => __('Prowadzący z aktualnym zaświadczeniem.')],
    ['flag' => $organizer->safetyLiabilityInsurance, 'label' => __('Ubezpieczenie OC organizatora'), 'desc' => null],
];
$declaredSafety = array_values(array_filter($safetyItems, fn($s) => $s['flag']));
$undeclaredSafety = array_values(array_filter($safetyItems, fn($s) => !$s['flag']));

// "Na rynku" — self-declared rok założenia ma sens głównie dla biur; peer bez
// niego dostaje ten sam slot statystyki liczony z konta (kiedy DOŁĄCZYŁ do
// ridemore.bike), nigdy pusty ani zmyślony (patrz zasada nadrzędna wyżej).
$marketYear = $organizer->foundedYear ?: $stats['memberSinceYear'];
$marketYearsOn = max(1, (int) date('Y') - (int) $marketYear);
$marketYearsLabel = __n($marketYearsOn, 'rok', 'lata', 'lat');
$marketSourceLabel = $organizer->foundedYear ? __('od {rok}', ['rok' => $organizer->foundedYear]) : __('na ridemore.bike od {rok}', ['rok' => $stats['memberSinceYear']]);

$galleryPhotos = array_slice($coverPhotos, 1);

$anchors = [
    ['id' => 'kim', 'label' => __('Kim jesteśmy')],
    ['id' => 'wyjazdy', 'label' => __('Wyjazdy ({n})', ['n' => count($upcomingEvents)])],
    // Pominięte w makiecie (pokazuje tylko nadchodzące wyjazdy), dopisane —
    // dzisiejsza strona ma to jako osobną, pełnoprawną sekcję z paginacją
    // (?historia=wszystko), nie wolno jej stracić przy przebudowie.
    ['id' => 'historia', 'label' => __('Historia (') . $historyTotal . ')'],
    ['id' => 'jak', 'label' => __('Jak wygląda wyjazd')],
    ['id' => 'gwarancje', 'label' => __('Zanim się zapiszesz')],
    ['id' => 'opinie', 'label' => __('Opinie (') . $reviewStats['count'] . ')'],
];
if (!empty($coverPhotos)) {
    $anchors[] = ['id' => 'zdjecia', 'label' => __('Zdjęcia')];
}
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>

<div class="op-head">
    <span class="av">
        <?php if (!empty($organizer->avatarUrl)): ?>
        <img src="<?= htmlspecialchars(Utils\Image::src($organizer->avatarUrl, 'av')) ?>" alt="<?= htmlspecialchars($organizer->name) ?>" width="84" height="84">
        <?php else: ?>
        <?= htmlspecialchars($initials) ?>
        <?php endif; ?>
        <?php if ($organizer->isVerified): ?>
        <span class="tick" title="<?= htmlspecialchars(__('Zweryfikowany')) ?>"><?= Icon::render('check') ?></span>
        <?php endif; ?>
    </span>
    <div class="op-head__c">
        <div class="tags">
            <?php if ($isOperator && $organizer->isVerified): ?>
            <span class="tag tag--ver"><?= __('✓ Zweryfikowany operator') ?></span>
            <?php elseif ($isOperator): ?>
            <span class="tag"><?= __('Operator turystyczny') ?></span>
            <?php else: ?>
            <span class="tag tag--peer"><?= __('Organizator społecznościowy') ?></span>
            <?php endif; ?>
            <?php if ($organizer->city): ?><span class="tag"><?= htmlspecialchars($organizer->city) ?></span><?php endif; ?>
            <?php if ($organizer->foundedYear): ?><span class="tag"><?= __('Od {rok}', ['rok' => (int) $organizer->foundedYear]) ?></span><?php endif; ?>
            <?php if ($organizer->languages): ?><span class="tag"><?= htmlspecialchars($organizer->languages) ?></span><?php endif; ?>
        </div>
        <h1><?= htmlspecialchars($organizer->name) ?></h1>
        <div class="op-head__act">
            <?php if (!empty($upcomingEvents)): ?>
            <a class="btn" href="#wyjazdy"><?= count($upcomingEvents) === 1 ? __('Zobacz 1 nadchodzący wyjazd') : __('Zobacz {n} nadchodzące wyjazdy', ['n' => count($upcomingEvents)]) ?></a>
            <?php endif; ?>
            <?php $orgViewer = Auth::user(); if ($orgViewer && $orgViewer->id !== $organizer->userId && Message::canMessage($orgViewer->id, $organizer->userId)): ?>
            <a class="btn btn-secondary" href="<?= View::url('/wiadomosci/z/' . $organizer->userId) ?>"><?= __('Napisz wiadomość') ?></a>
            <?php endif; ?>
            <?php // Profil nieprzejęty (konto założone automatem przy zgłoszeniu w czyimś
                  // imieniu) — subtelny, pomarańczowy przycisk obok pozostałych akcji;
                  // link aktywacyjny i tak leci tylko na e-mail przypisany do konta,
                  // więc przejąć może wyłącznie właściciel skrzynki. Patrz .sfoot niżej
                  // (przeniesione stąd — było nieostylowane na dole panelu). ?>
            <?php if ($organizer->isUnclaimed): ?>
            <?php if ($claimed ?? false): ?>
            <span class="op-claim-sent"><?= Icon::render('check') ?> <?= __('Link do przejęcia wysłany na e-mail profilu') ?></span>
            <?php else: ?>
            <form method="post" action="<?= View::url('/organizatorzy/' . $organizer->slug . '/przejmij') ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--claim"><?= __('To Twój profil? Przejmij →') ?></button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($heroCover): ?>
<div class="op-cover" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($heroCover['url'], 'wide')) ?>');" role="img" aria-label="<?= htmlspecialchars(__('Zdjęcie z wyjazdu')) ?>">
    <div class="op-cover__g"></div>
    <?php if ($heroCover['eventTitle']): ?>
    <div class="op-cover__b">
        <span><?= __('Wyjazd „{tytul}”', ['tytul' => htmlspecialchars($heroCover['eventTitle'])]) ?><?= $heroCover['eventDate'] ? ', ' . htmlspecialchars(Format::dateP($heroCover['eventDate'])) : '' ?></span>
        <?php if (!empty($galleryPhotos)): ?><a href="#zdjecia"><?= __('{n} zdjęcia', ['n' => count($galleryPhotos)]) ?> →</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<dl class="stats">
    <?php // SAM ROK, bez wyliczanego „N lat" (decyzja usera 2026-08-13).
          // „5 lat (od 2021)" podawało tę samą informację dwa razy, a licznik
          // lat sugerował ciągłość działania, której serwis nie zna — zna
          // wyłącznie deklarowany rok początku. ?>
    <div><dt><?= __('Na rynku') ?></dt><dd><?= htmlspecialchars($marketSourceLabel) ?></dd></div>
    <div><dt><?= __('Nadchodzące wyjazdy') ?></dt><dd><?= count($upcomingEvents) ?></dd></div>
    <div><dt><?= __('Zrealizowane') ?></dt><dd<?= $stats['completedCount'] === 0 ? ' class="soft"' : '' ?>><?= $stats['completedCount'] > 0 ? $stats['completedCount'] : __('Pierwszy sezon na ridemore.bike') ?></dd></div>
    <div><dt><?= __('Ocena') ?></dt><dd<?= $reviewStats['count'] === 0 ? ' class="soft"' : '' ?>><?= $reviewStats['count'] > 0 ? $reviewStats['avg'] . ' · ' . $reviewStats['count'] . ' opinii' : __('Brak opinii — wyjazdy jeszcze przed nami') ?></dd></div>
</dl>


<?php if ($isOwnerViewer): ?>
<div class="owner">
    <span class="mono"><?= __('Widok właściciela') ?></span>
    <?php if (empty($missingFields)): ?>
    <span><?= __('Profil publiczny i kompletny.') ?></span>
    <?php else: ?>
    <?php $lower = fn(string $s) => mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1); ?>
    <span><?= __('Profil publiczny. Uzupełnij') ?> <?php foreach ($missingFields as $i => $f): ?><?= $i > 0 ? ($i === count($missingFields) - 1 ? ' i ' : ', ') : '' ?><b><?= htmlspecialchars($lower($f)) ?></b><?php endforeach; ?><?= __(', żeby był kompletny.') ?></span>
    <?php endif; ?>
    <a class="btn btn-secondary btn--sm" href="<?= View::url('/admin/profil-rozliczeniowy') ?>"><?= __('Edytuj profil') ?></a>
</div>
<?php endif; ?>

<?php if ($isAdminViewer): ?>
<?php if (!$organizer->isActive): ?>
<div class="callout" style="margin-top:16px;">
    <b><?= __('Ten profil jest dezaktywowany — niewidoczny publicznie.') ?></b>
    <a href="<?= View::url('/admin/organizatorzy/' . $organizer->slug . '/edytuj') ?>"><?= __('Przywróć w panelu admina →') ?></a>
</div>
<?php endif; ?>
<form method="post" action="<?= View::url('/organizatorzy/' . $organizer->slug . '/weryfikacja') ?>" style="margin-top:16px;">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-secondary"><?= $organizer->isVerified ? __('Cofnij weryfikację') : __('Zweryfikuj organizatora') ?></button>
</form>
<?php endif; ?>

<nav class="anchors" aria-label="<?= htmlspecialchars(__('Sekcje profilu')) ?>"><div class="anchors__in">
    <?php foreach ($anchors as $i => $a): ?>
    <a href="#<?= $a['id'] ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>><?= htmlspecialchars($a['label']) ?></a>
    <?php endforeach; ?>
</div></nav>

<div class="op-layout">
<main>

    <!-- KIM JESTEŚMY -->
    <section class="sec" id="kim">
        <p class="eyebrow"><span class="blaze" style="--bz:var(--s-blue);"></span><?= __('Kim jesteśmy') ?></p>
        <div class="box">
            <?php // Nota o rodzaju organizatora przeniesiona do sekcji #typ wyżej. ?>

            <?php
            $facts = [];
            $facts[] = [__('Rodzaj organizatora'), $isOperator ? __('Operator turystyczny') : __('Organizator społecznościowy')];
            if ($isOperator && $organizer->tourismRegisterNumber) $facts[] = [__('Nr w rejestrze'), htmlspecialchars($organizer->tourismRegisterNumber), true];
            if ($organizer->city) $facts[] = [__('Miasto'), htmlspecialchars($organizer->city)];
            // Strona regionu zamiast filtra /wydarzenia (2026-09-14) — filtr
            // ma canonical na samo /wydarzenia, więc link nic nie znaczył dla
            // wyszukiwarki. Kod spoza aktywnych regionów zostaje przy filtrze.
            if ($organizer->regionName) $facts[] = [__('Region'), '<a href="' . View::url(Models\Region::pathForCode($organizer->regionCode) ?? '/wydarzenia?regions[]=' . urlencode((string) $organizer->regionCode)) . '">' . htmlspecialchars($organizer->regionName) . '</a>'];
            if ($organizer->foundedYear) $facts[] = [__('Działa od'), (int) $organizer->foundedYear];
            if ($organizer->languages) $facts[] = [__('Języki'), htmlspecialchars($organizer->languages)];
            ?>
            <?php if (count($facts) > 1): ?>
            <dl class="facts">
                <?php foreach ($facts as $f): ?>
                <div class="fact"><dt><?= htmlspecialchars($f[0]) ?></dt><dd<?= !empty($f[2]) ? ' class="mono"' : '' ?>><?= $f[1] ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <?php endif; ?>

            <?php if (!empty($organizer->bio)): ?>
            <div class="said">
                <p>„<?= nl2br(htmlspecialchars($organizer->bio)) ?>”</p>
                <?php
                    $tnMeta = $bioTranslation ?? null;
                    $tnEditUrl = !empty($isOwnerViewer) || (Core\Auth::user()?->isAdmin ?? false) ? View::url('/tlumaczenie/organizer/' . (int) $organizer->userId) : null;
                    require __DIR__ . '/../partials/translated-note.php';
                ?>
                <cite><i><?= htmlspecialchars($initials) ?></i><?= __('Tak opisują się sami') ?></cite>
            </div>
            <?php endif; ?>

            <?php if (!empty($specialization)): ?>
            <div class="chips" style="margin-top:16px;">
                <?php foreach ($specialization as $chip): ?>
                <?php // Chipy to typy rowerów I regiony z wyjazdów organizatora
                      // (Organizer::specializationChips — same nazwy). Region
                      // prowadzi na swoją stronę (zgłoszenie usera 2026-09-14). ?>
                <?php $chipRegionPath = Models\Region::pathForName($chip); ?>
                <?php if ($chipRegionPath !== null): ?>
                <a class="chip" href="<?= View::url($chipRegionPath) ?>"><?= htmlspecialchars($chip) ?></a>
                <?php else: ?>
                <span class="chip"><?= htmlspecialchars($chip) ?></span>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- WYJAZDY -->
    <section class="sec" id="wyjazdy">
        <p class="eyebrow"><span class="blaze" style="--bz:var(--s-red);"></span><?= __('Co możesz z nimi pojechać') ?></p>
        <h2><?= __('Nadchodzące wyjazdy') ?></h2>
        <?php if (empty($upcomingEvents)): ?>
        <p class="desc"><?= __('Brak nadchodzących wyjazdów.') ?></p>
        <?php else: ?>
        <div class="op-grid">
            <?php foreach ($upcomingEvents as $ev): ?>
            <?php $fmt = $FORMAT_META[$ev['eventType']] ?? $FORMAT_META['ustawka']; ?>
            <a class="op-card" href="<?= View::url('/events/' . $ev['slug']) ?>">
                <div class="op-card__v"<?= $ev['coverPhotoUrl'] ? ' style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($ev['coverPhotoUrl'], 'card')) . '\');"' : '' ?>>
                    <span class="op-card__f" style="--bz:<?= $fmt['color'] ?>;"><i></i><?= htmlspecialchars($fmt['label']) ?></span>
                    <?php if ($ev['durationDays'] > 1): ?><span class="op-card__d"><?= __('{n} dni', ['n' => $ev['durationDays']]) ?></span><?php endif; ?>
                </div>
                <div class="op-card__b">
                    <h3 class="op-card__t"><?= htmlspecialchars($ev['title']) ?></h3>
                    <p class="op-card__m">
                        <?= htmlspecialchars(Format::dateShort($ev['startDate'])) ?>
                        <?php if ($ev['distanceKm'] > 0): ?> · <?= htmlspecialchars(Format::distance($ev['distanceKm'])) ?><?php endif; ?>
                        <?php if ($ev['difficultyLabel']): ?> · <?= htmlspecialchars($ev['difficultyLabel']) ?><?php endif; ?>
                    </p>
                    <div class="op-card__ft">
                        <span<?= $ev['confirmedCount'] === 0 ? ' class="low"' : '' ?>><?= $ev['confirmedCount'] > 0 ? $ev['confirmedCount'] . ' zapisanych' : __('Nowy termin') ?><?= $ev['spotsLeft'] !== null ? ' · ' . $ev['spotsLeft'] . ' miejsc' : '' ?></span>
                        <span<?= !$ev['isPaid'] ? ' class="free"' : '' ?>><?= $ev['isPaid'] ? htmlspecialchars(Format::price($ev['priceAmount'], Format::currencySymbol($ev['currency']))) : __('Bez wpisowego') ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- HISTORIA (pominięte w makiecie — istniejąca funkcjonalność, zachowana) -->
    <section class="sec" id="historia">
        <p class="eyebrow"><span class="blaze" style="--bz:var(--ink-mute);"></span><?= __('Co już się odbyło') ?></p>
        <h2><?= __('Historia') ?></h2>
        <?php if (empty($historyEvents)): ?>
        <p class="desc"><?= __('Brak jeszcze zrealizowanych wyjazdów.') ?></p>
        <?php else: ?>
        <div class="op-grid">
            <?php foreach ($historyEvents as $ev): ?>
            <?php $fmt = $FORMAT_META[$ev['eventType']] ?? $FORMAT_META['ustawka']; ?>
            <a class="op-card" href="<?= View::url('/events/' . $ev['slug']) ?>">
                <div class="op-card__v"<?= $ev['coverPhotoUrl'] ? ' style="background-image:url(\'' . htmlspecialchars(Utils\Image::src($ev['coverPhotoUrl'], 'card')) . '\');"' : '' ?>>
                    <span class="op-card__f" style="--bz:<?= $fmt['color'] ?>;"><i></i><?= htmlspecialchars($fmt['label']) ?></span>
                </div>
                <div class="op-card__b">
                    <h3 class="op-card__t"><?= htmlspecialchars($ev['title']) ?></h3>
                    <p class="op-card__m"><?= htmlspecialchars(Format::dateShort($ev['startDate'])) ?><?= $ev['distanceKm'] > 0 ? ' · ' . htmlspecialchars(Format::distance($ev['distanceKm'])) : '' ?></p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php $historyRemaining = $historyTotal - count($historyEvents); ?>
        <?php if (!$historyExpanded && $historyRemaining > 0): ?>
        <p style="margin-top:14px;"><a class="btn btn-secondary btn--sm" href="?historia=wszystko#historia"><?= __('Zobacz pozostałe {n}', ['n' => $historyRemaining]) ?></a></p>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- JAK WYGLĄDA WYJAZD -->
    <section class="sec" id="jak">
        <p class="eyebrow"><span class="blaze" style="--bz:var(--s-green);"></span><?= __('Czego się spodziewać') ?></p>
        <h2><?= __('Jak wygląda wyjazd z nimi') ?></h2>
        <?php
        $expItems = [];
        if ($ridingProfile['avgGroupSize'] !== null) $expItems[] = [__('Grupa'), __('Zwykle {n} osób', ['n' => $ridingProfile['avgGroupSize']]), null];
        if ($ridingProfile['distanceRangeLabel'] !== null) $expItems[] = [__('Dzień'), $ridingProfile['distanceRangeLabel'], null];
        if ($ridingProfile['difficultyLabel']) $expItems[] = [__('Trudność'), $ridingProfile['difficultyLabel'], null];
        if ($ridingProfile['paceLabel']) $expItems[] = [__('Tempo'), $ridingProfile['paceLabel'], null];
        if ($ridingProfile['surfaceLabel']) $expItems[] = [__('Nawierzchnia'), $ridingProfile['surfaceLabel'], null];
        if ($organizer->languages) $expItems[] = [__('Języki'), $organizer->languages, null];
        ?>
        <div class="box">
            <?php if (empty($expItems)): ?>
            <p class="desc"><?= __('Brak jeszcze zakończonych wyjazdów, z których dałoby się to policzyć.') ?></p>
            <?php else: ?>
            <?php if ($stats['completedCount'] > 0): ?>
            <p class="desc" style="margin-bottom:11px;font-size:14px;"><?= __('na podstawie ostatnich {n} wyjazdów', ['n' => min(12, $stats['completedCount'])]) ?></p>
            <?php endif; ?>
            <ul class="exp">
                <?php foreach ($expItems as $item): ?>
                <li><span class="n"><?= htmlspecialchars($item[0]) ?></span><div><b><?= htmlspecialchars($item[1]) ?></b></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </section>

    <!-- ZANIM SIĘ ZAPISZESZ -->
    <section class="sec" id="gwarancje">
        <p class="eyebrow"><span class="blaze" style="--bz:var(--blaze);"></span><?= __('Twoje zabezpieczenie') ?></p>
        <h2><?= __('Zanim się zapiszesz') ?></h2>

        <?php if ($isOperator): ?>
        <div class="box">
            <h3><?= __('Co gwarantuje prawo') ?></h3>
            <ul class="safe" style="margin-top:11px;">
                <li><span class="ic"><?= Icon::render('check') ?></span><div><b><?= __('Wpis do rejestru organizatorów turystyki') ?></b>
                    <?php if ($organizer->tourismRegisterNumber): ?><small><?= __('Nr') ?> <span class="mono"><?= htmlspecialchars($organizer->tourismRegisterNumber) ?></span> <?= __('— możesz go sprawdzić w Centralnej Ewidencji Organizatorów Turystyki.') ?></small><?php endif; ?></div></li>
                <li><span class="ic"><?= Icon::render('check') ?></span><div><b><?= __('Obowiązkowe zabezpieczenie finansowe') ?></b>
                    <small><?= __('Jeśli wyjazd nie dojdzie do skutku z winy organizatora, wpłacone pieniądze są chronione.') ?></small></div></li>
                <li><span class="ic"><?= Icon::render('check') ?></span><div><b><?= __('Umowa zawierana bezpośrednio z organizatorem') ?></b>
                    <small><?= __('Przysługują Ci prawa konsumenta wobec organizatora imprezy turystycznej.') ?></small></div></li>
            </ul>
        </div>
        <?php else: ?>
        <div class="box">
            <h3><?= __('Co to znaczy w praktyce') ?></h3>
            <p class="desc" style="font-size:15px;"><?= __('To nie jest impreza turystyczna w rozumieniu ustawy. Nie ma rejestru,
                gwarancji ani biura, które odpowiada za przebieg wyjazdu.') ?></p>
            <div class="callout">
                <b><?= __('Jedziecie na własną odpowiedzialność.') ?></b>
                <?= __('Organizator dzieli się trasą i terminem — nie sprzedaje usługi turystycznej. Zadbaj o własne ubezpieczenie
                i oceń trasę pod swoje możliwości.') ?>

            </div>
            <p class="desc" style="font-size:14px;margin-top:13px;"><?= __('To normalny i najczęstszy sposób organizowania
                wspólnych jazd w Polsce. Wymaga tylko innego rodzaju uwagi niż wyjazd komercyjny.') ?></p>
        </div>
        <?php endif; ?>

        <div class="box">
            <h3><?= __('Co deklaruje organizator') ?></h3>
            <p class="desc" style="font-size:14px;margin-bottom:13px;"><?= __('To deklaracja organizatora, nie nasza weryfikacja.') ?></p>
            <?php if (empty($declaredSafety)): ?>
            <p class="desc"><?= __('Ten organizator nie zadeklarował jeszcze żadnych z poniższych.') ?></p>
            <?php else: ?>
            <ul class="safe">
                <?php foreach ($declaredSafety as $s): ?>
                <li><span class="ic"><?= Icon::render('check') ?></span><div><b><?= htmlspecialchars($s['label']) ?></b><?php if ($s['desc']): ?><small><?= htmlspecialchars($s['desc']) ?></small><?php endif; ?></div></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if (!empty($undeclaredSafety)): ?>
            <p class="nodecl"><?= __('Nie zadeklarowano:') ?> <?php foreach ($undeclaredSafety as $i => $s): ?><?= $i > 0 ? ', ' : '' ?><b><?= htmlspecialchars(mb_strtolower($s['label'])) ?></b><?php endforeach; ?><?= __('.
                Brak deklaracji nie znaczy, że tego nie ma — zapytaj przed zapisem.') ?></p>
            <?php endif; ?>
        </div>
    </section>

    <!-- OPINIE -->
    <section class="sec" id="opinie">
        <p class="eyebrow"><span class="blaze" style="--bz:var(--s-red);"></span><?= __('Co mówią inni') ?></p>
        <h2><?= __('Opinie uczestników') ?></h2>
        <?php if ($reviewStats['count'] === 0): ?>
        <div class="empty">
            <b><?= __('Jeszcze nikt nie napisał opinii') ?></b>
            <p><?= __('Opinię może wystawić tylko osoba, która była na zakończonym wyjeździe zapisanym przez ridemore.bike.') ?>

                <?= $stats['completedCount'] === 0 ? __('Ten organizator zaczyna u nas sezon, więc opinii jeszcze nie ma.') : __('Jeśli chcesz sprawdzić wcześniejsze wyprawy, napisz do niego albo zajrzyj na jego stronę i profile.') ?></p>
            <p style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($orgViewer && $orgViewer->id !== $organizer->userId && Message::canMessage($orgViewer->id, $organizer->userId)): ?>
                <a class="btn btn-secondary btn--sm" href="<?= View::url('/wiadomosci/z/' . $organizer->userId) ?>"><?= __('Zapytaj o referencje') ?></a>
                <?php endif; ?>
                <?php if ($organizer->websiteUrl): ?>
                <a class="btn btn-secondary btn--sm" href="<?= htmlspecialchars($organizer->websiteUrl) ?>" target="_blank" rel="noopener"><?= __('Strona organizatora →') ?></a>
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div class="box">
            <?php foreach ($reviews as $review): ?>
            <div class="review">
                <?php // Zdjęcie autora opinii, inicjały jako zapas (2026-08-22).
                      // Ta karta ma własny markup (link do wydarzenia w nagłówku),
                      // więc nie idzie przez `renderActivityCard` — ale kółko
                      // zachowuje się identycznie. ?>
                <?php $rAv = !empty($review['reviewerAvatarUrl']) ? Utils\Image::src($review['reviewerAvatarUrl'], 'av') : ''; ?>
                <div class="r-avatar"><?php if ($rAv !== ''): ?><img src="<?= htmlspecialchars($rAv) ?>" alt="<?= htmlspecialchars(mb_substr($review['reviewerName'], 0, 2)) ?>" width="34" height="34" loading="lazy"><?php else: ?><?= htmlspecialchars(mb_substr($review['reviewerName'], 0, 2)) ?><?php endif; ?></div>
                <div class="review-body">
                    <div class="review-head">
                        <span class="review-name"><?= htmlspecialchars($review['reviewerName']) ?></span>
                        <span class="review-stars"><?= str_repeat(Icon::render('star'), $review['rating']) ?></span>
                        <span class="review-event"><?= htmlspecialchars($review['eventTitle']) ?></span>
                        <span class="review-time"><?= htmlspecialchars(Format::dateShort($review['createdAt'])) ?></span>
                    </div>
                    <?php if (!empty($review['comment'])): ?>
                    <div class="review-text"><?= htmlspecialchars($review['comment']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- ZDJĘCIA -->
    <?php if (!empty($coverPhotos)): ?>
    <section class="sec" id="zdjecia">
        <p class="eyebrow"><span class="blaze" style="--bz:var(--s-green);"></span><?= __('Z trasy') ?></p>
        <h2><?= __('Zdjęcia') ?></h2>
        <div class="strip">
            <?php foreach ($coverPhotos as $photoUrl): ?>
            <?php // href = PEŁNY plik, tło = miniatura `thumb` (2026-08-22).
                  // Wcześniej oba wskazywały na miniaturę, więc kliknięcie
                  // otwierało nową kartę z obrazkiem 320 px zamiast powiększyć. ?>
            <a class="ph-link" href="<?= htmlspecialchars(Utils\View::url($photoUrl)) ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars(__('Powiększ zdjęcie')) ?>" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($photoUrl, 'thumb')) ?>');"></a>
            <?php endforeach; ?>
        </div>
        <p class="desc" style="font-size:13px;margin-top:11px;color:var(--ink-mute);"><?= __('Zdjęcia dodane przez organizatora.
            Relacje z konkretnych wyjazdów znajdziesz na stronach wydarzeń.') ?></p>
    </section>
    <?php endif; ?>
</main>

<!-- PANEL BOCZNY -->
<aside>
    <?php if (!empty($upcomingEvents)): ?>
    <div class="book">
        <h3><?= __('Najbliższy wyjazd') ?></h3>
        <?php $next = $upcomingEvents[0]; ?>
        <a class="next" href="<?= View::url('/events/' . $next['slug']) ?>">
            <p class="next__t"><?= htmlspecialchars($next['title']) ?></p>
            <p class="next__m"><?= htmlspecialchars(Format::dateShort($next['startDate'])) ?><?= $next['distanceKm'] > 0 ? ' · ' . htmlspecialchars(Format::distance($next['distanceKm'])) : '' ?><?= $next['regionName'] ? ' · ' . htmlspecialchars($next['regionName']) : '' ?></p>
            <p class="next__p">
                <span><?= $next['isPaid'] ? htmlspecialchars(Format::price($next['priceAmount'], Format::currencySymbol($next['currency']))) . ' / os.' : __('Bez wpisowego') ?></span>
                <span style="color:var(--ink-soft);"><?= $next['confirmedCount'] > 0 ? $next['confirmedCount'] . ' zapisanych' : __('Nowy termin') ?></span>
            </p>
        </a>
        <a class="btn btn--full" href="<?= View::url('/events/' . $next['slug']) ?>"><?= __('Zobacz wyjazd →') ?></a>
        <?php if ($orgViewer && $orgViewer->id !== $organizer->userId && Message::canMessage($orgViewer->id, $organizer->userId)): ?>
        <a class="btn btn-secondary btn--full" href="<?= View::url('/wiadomosci/z/' . $organizer->userId) ?>"><?= __('Napisz do organizatora') ?></a>
        <?php endif; ?>

        <div class="book__list">
            <?php if ($isOperator): ?>
            <div><?= Icon::render('check') ?><span><b><?= __('Zweryfikowany operator') ?></b> <?= __('— rejestr sprawdzony') ?></span></div>
            <?php else: ?>
            <div><?= Icon::render('check') ?><span><b><?= __('Organizator społecznościowy') ?></b> <?= __('— jazda na własną odpowiedzialność') ?></span></div>
            <?php endif; ?>
            <div><?= Icon::render('check') ?><span><?= __('Zapisy i płatność') ?> <b><?= __('bezpośrednio u organizatora') ?></b></span></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($organizer->phone || $organizer->contactEmail || $organizer->websiteUrl || $organizer->facebookUrl || $organizer->instagramUrl || $organizer->stravaUrl): ?>
    <div class="book">
        <h3><?= __('Kontakt') ?></h3>
        <div class="social">
            <?php if ($organizer->phone): ?>
            <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $organizer->phone)) ?>"><?= Icon::render('phone') ?><?= htmlspecialchars($organizer->phone) ?></a>
            <?php endif; ?>
            <?php if ($organizer->contactEmail): ?>
            <a href="mailto:<?= htmlspecialchars($organizer->contactEmail) ?>"><?= Icon::render('mail') ?><?= htmlspecialchars($organizer->contactEmail) ?></a>
            <?php endif; ?>
            <?php if ($organizer->websiteUrl): ?>
            <a href="<?= htmlspecialchars($organizer->websiteUrl) ?>" target="_blank" rel="noopener"><?= Icon::render('link') ?><?= htmlspecialchars(preg_replace('#^https?://(www\.)?#', '', $organizer->websiteUrl)) ?></a>
            <?php endif; ?>
            <?php if ($organizer->facebookUrl): ?>
            <a href="<?= htmlspecialchars($organizer->facebookUrl) ?>" target="_blank" rel="noopener"><?= Icon::render('facebook') ?>Facebook</a>
            <?php endif; ?>
            <?php if ($organizer->instagramUrl): ?>
            <a href="<?= htmlspecialchars($organizer->instagramUrl) ?>" target="_blank" rel="noopener"><?= Icon::render('instagram') ?>Instagram</a>
            <?php endif; ?>
            <?php if ($organizer->stravaUrl): ?>
            <a href="<?= htmlspecialchars($organizer->stravaUrl) ?>" target="_blank" rel="noopener"><?= Icon::render('strava') ?>Klub Strava</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // Przejęcie profilu przeniesione do nagłówka (.op-head__act) — tu zostaje
          // tylko zgłaszanie nieprawidłowości. ?>
    <p class="sfoot">
        <a href="mailto:<?= htmlspecialchars(APP_CONFIG['mail']['from_email'] ?? 'kontakt@ridemore.bike') ?>?subject=<?= rawurlencode('Zgłoszenie: profil ' . $organizer->slug) ?>"><?= __('Zgłoś nieprawidłowość') ?></a>
    </p>
</aside>
</div>

<?php // Podgląd zdjęcia — wspólny lightbox (2026-08-22). Do tej pory kliknięcie
      // w kafelek galerii otwierało nową kartę z wariantem `thumb`, czyli
      // obrazkiem 320 px — „powiększenie" pokazywało mniej niż strona.
      // Kafelki oznaczone `.ph-link`; bez JS link dalej działa, tylko od teraz
      // prowadzi do oryginału. ?>
<?php require __DIR__ . '/../partials/photo-lightbox.php'; ?>
