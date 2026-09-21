<?php
// views/web/partials/ride-feed.php
// OSTATNIE PRZEJAZDY — panel przy mapie (2026-08-24).
//
// Zgłoszenie usera: „sekcja »Co dały ostatnie wyjazdy« powiela informacje
// z »Ostatniej aktywności«, a klikanie na aktywność nic nie wnosi (…) musi mieć
// paginację, ikonki, brakuje daty, są tylko punkty, a nie ma odkryć hexów".
//
// JEDNA LISTA ZAMIAST DWÓCH POŁÓWEK. Do tej daty to samo zdarzenie opisywały
// dwa miejsca: panel na mapie pokazywał SAME PUNKTY z rejestru naliczeń
// (bez daty, bez pól, bez nazwy wyjazdu), a sekcja pod mapą — karty z datą
// i polami, ale bez związku z mapą. Teraz jest jedno miejsce, w którym wiersz
// mówi wszystko i prowadzi TAM, GDZIE TO BYŁO.
//
// TEN SAM MODUŁ NA OBU MAPACH. `$feedShowRider` dokłada autora — na mapie
// społeczności „kto" jest najważniejszą kolumną, a na własnej byłoby
// powtarzaniem jednego nazwiska w kółko.
//
// LISTA PRZEWIJA SIĘ, NIE STRONICUJE (poprawka 2026-08-24 po uwadze usera:
// „tracimy na super funkcjonalność przez to, że są tylko 3 aktywności i trzeba
// przełączać paginacją; jeśli będzie dziennie 200 aktywności, to co mi z takiego
// okna"). Pierwsza wersja pokazywała trzy pozycje i chowała resztę za strzałkami
// — czyli okno mniejsze niż to, na co pozwalała mapa. Teraz panel bierze całą
// wolną wysokość kolumny (patrz `.disc-feed` w style.css), a wiersze, które się
// nie mieszczą, dojeżdżają przewijaniem. Wysokość dopasowuje flexbox, więc
// zwinięcie albo zgaszenie panelu skarbów od razu oddaje miejsce tej liście.
//
// Oczekuje w zasięgu: $feedRides, $feedShowRider (bool), $plural, $num.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\Icon;
use Utils\View;

$feedRides = $feedRides ?? [];
$feedShowRider = !empty($feedShowRider);
$feedTitle = $feedShowRider ? __('Ostatnia aktywność społeczności') : __('Ostatnia aktywność');
?>
<aside class="disc-panel disc-feed" id="discRideFeed" data-drawer="<?= htmlspecialchars($feedTitle) ?>">
    <h3><?= htmlspecialchars($feedTitle) ?></h3>

    <?php if (!$feedRides): ?>
    <p class="disc-panel__empty"><?= $feedShowRider
        ? __('Nikt jeszcze nie wgrał śladu. Pierwszy przejazd pojawi się tutaj.')
        : __('Tu pojawią się Twoje przejazdy — każdy ze swoimi polami i punktami. Wgraj ślad z licznika albo zapisz się na wyjazd.') ?></p>
    <?php else: ?>

    <div class="disc-feed__list">
        <?php foreach ($feedRides as $i => $ride): ?>
        <?php
            // DWA RODZAJE ZDARZEŃ NA JEDNEJ OSI CZASU (2026-08-24). Skarby
            // wróciły tu po zgłoszeniu usera: poprzedni panel czytał cały
            // rejestr naliczeń, więc pokazywał i przejazdy, i znaleziska,
            // a pierwsza wersja tej listy brała same przejazdy.
            $skarb = ($ride['kind'] ?? 'ride') === 'treasure';
            $solo = !$skarb && ($ride['source_code'] ?? '') === 'solo';
            $ile = (int) ($ride['count'] ?? 1);

            if ($skarb) {
                // Jedno znalezisko ma nazwę, kilka z jednego dnia — liczbę.
                // Siedem osobnych wierszy po jednym przejeździe wypychało
                // z listy same przejazdy (zmierzone na żywych danych).
                $tytul = $ile > 1
                    ? $ile . ' ' . $plural($ile, 'skarb', 'skarby', 'skarbów')
                    : (string) $ride['title'];
            } else {
                // Nazwa wyjazdu, a przy przejeździe bez wydarzenia — uczciwe
                // „solo". Do tej pory jedno i drugie nazywało się „Przejazd".
                $tytul = $solo || empty($ride['event_title'])
                    ? __('Przejazd solo')
                    : (string) $ride['event_title'];
            }
            $bounds = $ride['bounds'] ?? null;
        ?>
        <?php // Wiersz jest PRZYCISKIEM, nie linkiem: kliknięcie nie prowadzi na
              // inną stronę, tylko ustawia mapę na tym przejeździe. Klawiatura
              // dostaje to za darmo. Bez zapisanego zakresu (przejazd bez pól)
              // zostaje zwykły wiersz — przycisk, który nic nie robi, kłamie. ?>
        <<?= $bounds ? 'button type="button"' : 'div' ?>
            class="disc-feed__row<?= $bounds ? ' is-clickable' : '' ?>"
            <?= $bounds ? 'data-bounds=\'' . htmlspecialchars(json_encode($bounds), ENT_QUOTES) . '\'' : '' ?>
            <?php // data-ride — id przejazdu pod podświetlenie śladu wektorowego
                  // (discovery-map.js, opcja `tracks` ridemoreRideFeed). Skarby
                  // i zdarzenia bez przejazdu go nie dostają: nie mają pliku. ?>
            <?= !$skarb && isset($ride['id']) && $bounds ? 'data-ride="' . (int) $ride['id'] . '"' : '' ?>>
            <span class="disc-feed__ic<?= $skarb ? ' is-treasure' : '' ?>" aria-hidden="true"><?= Icon::render(
                $skarb ? 'star' : ($solo ? 'route' : 'users')
            ) ?></span>
            <span class="disc-feed__main">
                <span class="disc-feed__t"><?= htmlspecialchars($tytul) ?></span>
                <span class="disc-feed__m">
                    <?php // DATA BYŁA TYM, CZEGO BRAKOWAŁO NAJBARDZIEJ: bez niej
                          // lista mówiła „coś się wydarzyło", ale nie kiedy. ?>
                    <?= htmlspecialchars(Format::dateShort($ride['ride_date']) ?? '') ?><?php
                    if ($skarb): ?> · <?= $ile > 1 ? __('znalezione') : __('skarb znaleziony') ?><?php
                    else: ?><?php
                    if ((float) $ride['distance_km'] > 0): ?> · <?= htmlspecialchars(Format::distance((float) $ride['distance_km'])) ?><?php endif; ?><?php
                    if (!empty($ride['region_label'])): ?> · <?= htmlspecialchars($ride['region_label']) ?><?php endif; ?><?php
                    endif; ?>
                </span>
                <?php if ($feedShowRider): ?>
                <span class="disc-feed__who"><?= htmlspecialchars($ride['user_name'] ?: __('Rowerzysta')) ?></span>
                <?php endif; ?>
            </span>
            <span class="disc-feed__nums">
                <?php // POLA I PUNKTY OBOK SIEBIE. Panel pokazywał dotąd same
                      // punkty, a to odkryte pola są tym, po co ludzie tu wchodzą.
                      // Znalezisko pól nie odkrywa, więc pokazuje same punkty —
                      // zero z ikoną hexa kłamałoby, że coś tam było do wzięcia. ?>
                <?php if (!$skarb): ?>
                <span class="hexn"><?= Icon::render('hex') ?><b><?= (int) $ride['cells_new'] ?></b></span>
                <?php endif; ?>
                <span class="disc-feed__pts">+<?= $num($ride['points_total'] ?? 0) ?></span>
            </span>
        </<?= $bounds ? 'button' : 'div' ?>>
        <?php endforeach; ?>
    </div>

    <?php // Stopka mówi, JAK GŁĘBOKO sięga ta lista — bez niej „50 pozycji"
          // wygląda jak cała historia, a jest oknem na ostatnie zdarzenia. ?>
    <p class="disc-feed__foot"><?= count($feedRides) ?>
        <?= $plural(count($feedRides), 'ostatnie zdarzenie', 'ostatnie zdarzenia', __('ostatnich zdarzeń')) ?></p>
    <?php endif; ?>
</aside>
