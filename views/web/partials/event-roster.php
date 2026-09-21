<?php
// views/web/partials/event-roster.php
// „KTO JEDZIE" — sklad turnusu (Etap 1).
//
// PRZENIESIONY DO PRAWEJ KOLUMNY, pod panel zapisu (uwaga usera 2026-08-12:
// „bedzie mialo to lepszy kontekst myslowy"). Wczesniej byl PIERWSZA sekcja
// w <main>, nad opisem wyjazdu. Racja jest po stronie usera: sklad to nie
// opis wydarzenia, tylko argument w decyzji „zapisac sie czy nie" — a ta
// decyzja zapada przy przycisku, nie w tresci. Obok CTA odpowiada na pytanie
// „z kim tam pojade", zamiast byc kolejna sekcja do przewiniecia.
//
// Zwykly require, nie funkcja: korzysta z domkniec zdefiniowanych u gory
// event-page.php ($rosterName/$rosterInitials/$rosterPlural/$rosterSentence/
// $rosterLink/$plural/$rosterShowNames) oraz z $roster i $pelotonHere. Ten sam
// wzorzec co interest-toggle.php i cancel-participation-form.php.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

// IMPORTY MUSZĄ BYĆ TUTAJ, nie w event-page.php, które ten plik dołącza.
// `use` działa PER PLIK i nie przechodzi przez require — bez tych dwóch linii
// `View::url()` niżej rozwiązywało się do nieistniejącej klasy globalnej
// `\View` i wywalało stronę wydarzenia błędem 500.
//
// Ujawniło się dopiero przy WYLOGOWANYM widzu: obie linijki z View::url()
// prowadzą do logowania, więc dla zalogowanego ta gałąź w ogóle się nie
// renderowała, a strona wyglądała na sprawną.
use Utils\Format;
use Utils\View;
?>
    <?php
        // Reużywa .avs (nachodzące awatary z panelu zapisu) i .box/.sec — własne
        // są tylko trzy klasy .roster* na rytm i listę imion, patrz style.css.
        // Imiona pokazujemy tylko zalogowanym ($rosterShowNames, u góry pliku).
        // Listy zawierają TYLKO osoby widoczne; *Total to wszyscy zapisani.
        // Wszędzie, gdzie pada liczba uczestników, używamy *Total — inaczej
        // ukrycie jednej osoby pomniejszałoby skład w oczach oglądającego.
        $rConfirmed = $roster['confirmed'];
        $rInterested = $roster['interested'];
        $rConfirmedTotal = $roster['confirmedTotal'];
        $rInterestedTotal = $roster['interestedTotal'];
        // $plural/$rosterPlural są zdefiniowane u góry pliku (dzieli je panel zapisu).
        // "Michał W., Ania K. i 5 innych" — trzy imiona wystarczą, żeby zobaczyć
        // ZNAJOMEGO; pełna lista byłaby ścianą tekstu i listą obecności, nie składem.
        // $total, nie count($people) — reszta liczona jest wobec WSZYSTKICH
        // zapisanych, więc osoba ukryta wpada do "i N innych" zamiast zniknąć.
        //
        // Zwraca HTML (imiona są LINKAMI do profili), więc wywołujący NIE może
        // tego przepuszczać przez htmlspecialchars — same imiona są escapowane
        // wewnątrz renderRiderName().
        $rosterSentence = static function (array $people, int $total, callable $name, callable $plural): string {
            $shown = [];
            foreach (array_slice($people, 0, 3) as $person) {
                $shown[] = renderRiderName($name($person), $person['public_slug'] ?? null);
            }
            $rest = $total - count($shown);
            $txt = implode(', ', $shown);
            if ($rest > 0) { $txt .= ' i ' . $rest . ' ' . ($rest === 1 ? 'inna osoba' : $plural($rest)); }
            return $txt;
        };
    ?>
    <?php // .roster-aside zdejmuje rytm sekcji artykułu (.sec ma 35 px marginesu)
          // i ścieśnia box do szerokości kolumny 348 px — reszta klas bez zmian. ?>
    <section class="sec roster-aside" id="kto-jedzie">
        <div class="box">
            <h2><?= __('Kto jedzie') ?></h2>

            <?php if ($rConfirmedTotal > 0): ?>
            <div class="roster">
                <?php if ($rConfirmed): ?>
                <?php $shownAvatars = min(count($rConfirmed), 8); ?>
                <span class="avs">
                    <?php foreach (array_slice($rConfirmed, 0, 8) as $p): ?>
                    <?php renderRiderAvatar($rosterInitials($p), $p['public_slug'] ?? null, $rosterName($p), $p['avatar_url'] ?? null); ?>
                    <?php endforeach; ?>
                    <?php if ($rConfirmedTotal > $shownAvatars): ?><span>+<?= $rConfirmedTotal - $shownAvatars ?></span><?php endif; ?>
                </span>
                <?php endif; ?>
                <span class="roster__names">
                    <?php if ($rosterShowNames && $rConfirmed): ?>
                    <b><?= $rosterSentence($rConfirmed, $rConfirmedTotal, $rosterName, $rosterPlural) ?></b>
                    <?php else: ?>
                    <b><?= $rConfirmedTotal ?> <?= $rosterPlural($rConfirmedTotal) ?></b>
                    <?php endif; ?>
                    <?= $rConfirmedTotal === 1 ? __('jedzie na ten termin') : __('jadą na ten termin') ?>
                </span>
            </div>
            <?php endif; ?>

            <?php if ($pelotonHere): ?>
            <?php
                // PELETON (Etap 2) — wyróżniony podzbiór składu: osoby, z którymi
                // widz JUŻ FAKTYCZNIE jechał (potwierdzona wspólna obecność, nie
                // sam zapis). Celowo BEZ koloru sygnałowego --blaze: na tej stronie
                // znak szlaku jest zarezerwowany dla „zostały wolne miejsca",
                // a dwa żółte akcenty obok siebie znoszą się nawzajem.
                //
                // Jedna liczba na osobę (wspólne wyjazdy), dystans tylko gdy w ogóle
                // jest — wydarzenia bez wpisanego dystansu dałyby „0 km razem",
                // co wygląda na błąd, a nie na fakt.
            ?>
            <div class="roster__grp">
                <p class="roster__lbl"><?= __('Z Twojego peletonu') ?></p>
                <div class="roster">
                    <span class="avs">
                        <?php foreach (array_slice($pelotonHere, 0, 8) as $p): ?>
                        <?php renderRiderAvatar($rosterInitials($p), $p['public_slug'] ?? null, $rosterName($p), $p['avatar_url'] ?? null); ?>
                        <?php endforeach; ?>
                    </span>
                    <span class="roster__names">
                        <?php foreach ($pelotonHere as $i => $p): ?>
                        <?php if ($i > 0): ?> · <?php endif; ?>
                        <b><?= $rosterLink($p, $rosterName($p)) ?></b>
                        <?php
                            $rc = (int) $p['rides_count'];
                            $km = (float) $p['shared_km'];
                        ?>
                        <span>(<?= $rc ?> <?= $plural($rc, __('wspólny wyjazd'), __('wspólne wyjazdy'), __('wspólnych wyjazdów')) ?><?= $km > 0 ? ', ' . htmlspecialchars(Format::distance($km)) . ' razem' : '' ?>)</span>
                        <?php endforeach; ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($rInterestedTotal > 0): ?>
            <div class="<?= $rConfirmedTotal > 0 ? 'roster__grp' : '' ?>">
                <p class="roster__lbl"><?= __('Rozważają udział') ?></p>
                <div class="roster">
                    <?php if ($rInterested): ?>
                    <?php $shownInterested = min(count($rInterested), 8); ?>
                    <span class="avs">
                        <?php foreach (array_slice($rInterested, 0, 8) as $p): ?>
                        <?php renderRiderAvatar($rosterInitials($p), $p['public_slug'] ?? null, $rosterName($p), $p['avatar_url'] ?? null); ?>
                        <?php endforeach; ?>
                        <?php if ($rInterestedTotal > $shownInterested): ?><span>+<?= $rInterestedTotal - $shownInterested ?></span><?php endif; ?>
                    </span>
                    <?php endif; ?>
                    <span class="roster__names">
                        <?php if ($rosterShowNames && $rInterested): ?>
                        <b><?= $rosterSentence($rInterested, $rInterestedTotal, $rosterName, $rosterPlural) ?></b>
                        <?php else: ?>
                        <b><?= $rInterestedTotal ?> <?= $rosterPlural($rInterestedTotal) ?></b>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($rConfirmedTotal === 0 && $rInterestedTotal === 0): ?>
            <?php
                // Pusty stan — nie "brak danych", tylko realna informacja:
                // wspólny wyjazd zwykle rusza od jednej osoby. Bez sztucznego
                // ponaglania i bez liczników, których nie ma czym wypełnić.
            ?>
            <p class="lead"><?= __('Nikt jeszcze nie zapisał się na ten termin. Wspólne wyjazdy
                zwykle zaczynają się od jednej osoby — kolejni chętniej dołączają,
                kiedy widzą, że ktoś już jedzie.') ?></p>
            <?php if (!$rosterShowNames): ?>
            <p class="book__micro" style="text-align:left;">
                <a href="<?= View::url('/logowanie') ?>"><?= __('Zaloguj się') ?></a><?= __(', żeby dołączyć i zobaczyć skład.') ?>

            </p>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (($rConfirmedTotal > 0 || $rInterestedTotal > 0) && !$rosterShowNames): ?>
            <p class="book__micro" style="text-align:left;">
                <a href="<?= View::url('/logowanie') ?>"><?= __('Zaloguj się') ?></a><?= __(', żeby zobaczyć, kto konkretnie jedzie.') ?>

            </p>
            <?php endif; ?>
        </div>
    </section>
