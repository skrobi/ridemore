<?php
// views/web/pages/users-admin.php
// UŻYTKOWNICY — lista i moderacja (panel admina).
//
// Tabela, nie kafle. Kafle są dla rzeczy, które się ogląda; tu się PORÓWNUJE
// wiersze i szuka odstającego (konto bez potwierdzonego e-maila, rejestracja
// z dziwnego adresu, zero aktywności) — a do tego jedynym dobrym układem jest
// tabela z równymi kolumnami.
//
// Akcje niszczące siedzą w <details>, nie w widocznym rzędzie przycisków.
// Powód nie jest estetyczny: „Skasuj" obok „Reset hasła" w jednym rzędzie to
// kwestia czasu, zanim ktoś kliknie nie to co trzeba na liście trzydziestu
// wierszy. Rozwinięcie kosztuje jedno kliknięcie i wymusza spojrzenie na to,
// co się zaraz stanie.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';

$items  = $wynik['items'];
$szukaj = $wynik['szukaj'];
$filtr  = $wynik['filtr'];

// Zakładki filtrów. „Wszyscy" celowo bez licznika w nawiasie — liczba kont
// ogółem jest w pasku wyżej i powtarzanie jej tutaj nic nie wnosi.
$zakladki = [
    ''                => ['Wszyscy', null],
    'nowi'            => ['Nowi (7 dni)', $liczniki['nowi']],
    'niepotwierdzeni' => ['Bez potwierdzenia', $liczniki['niepotwierdzeni']],
    'zablokowani'     => ['Zablokowani', $liczniki['zablokowani']],
    // Etap 9 przebudowy apki (2026-08-29) — kolejka samoobsługowych próśb
    // o usunięcie konta (Models\User::requestDeletion()). Osobna zakładka od
    // „Zablokowani": ten sam mechanizm blokady pod spodem, ale to inne
    // pytanie admina („kto chce odejść" vs „kogo zablokowałem za co").
    'do_usuniecia'    => ['Do usunięcia', $liczniki['do_usuniecia']],
    'organizatorzy'   => ['Organizatorzy', null],
];
$link = static function (array $zmiany) use ($szukaj, $filtr): string {
    $p = array_filter(array_merge(['q' => $szukaj, 'filtr' => $filtr], $zmiany),
        static fn($v): bool => $v !== null && $v !== '');
    return View::url('/admin/uzytkownicy') . ($p ? '?' . http_build_query($p) : '');
};
// Pola, dzięki którym po akcji wracamy w to samo miejsce listy.
$powrot = static function () use ($szukaj, $filtr, $wynik): string {
    return '<input type="hidden" name="wroc_q" value="' . htmlspecialchars($szukaj) . '">'
         . '<input type="hidden" name="wroc_filtr" value="' . htmlspecialchars($filtr) . '">'
         . '<input type="hidden" name="wroc_strona" value="' . (int) $wynik['strona'] . '">';
};
?>

<div class="op-head">
    <div class="op-head__c">
        <h1>Użytkownicy</h1>
        <p class="op-head__sub">Kto się zarejestrował i co z tym zrobić. Konta z historią
            w serwisie da się zablokować, ale nie skasować — kasowanie zabrałoby też
            cudze wyjazdy, w których brali udział.</p>
    </div>
</div>

<?php if ($info): ?>
<div class="box" style="margin-top:14px;border-color:var(--accent);"><b><?= htmlspecialchars($info) ?></b></div>
<?php endif; ?>

<div class="trust-bar" style="margin-top:18px;">
    <div class="trust-item">
        <div class="trust-item-label">Kont</div>
        <div class="trust-item-value"><?= (int) $liczniki['wszyscy'] ?></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Nowych</div>
        <div class="trust-item-value"><?= (int) $liczniki['nowi'] ?><small>ostatnie 7 dni</small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Bez potwierdzenia</div>
        <div class="trust-item-value"><?= (int) $liczniki['niepotwierdzeni'] ?><small>nie kliknęli w link</small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Zablokowanych</div>
        <div class="trust-item-value"><?= (int) $liczniki['zablokowani'] ?></div>
    </div>
</div>

<section class="sec">
    <div class="disc-tabs">
        <?php foreach ($zakladki as $klucz => [$etykieta, $licznik]): ?>
        <a class="disc-tab<?= $filtr === $klucz ? ' is-on' : '' ?>" href="<?= htmlspecialchars($link(['filtr' => $klucz, 'strona' => null])) ?>">
            <?= htmlspecialchars($etykieta) ?><?php if ($licznik !== null): ?> · <?= (int) $licznik ?><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <form class="box" method="get" action="<?= View::url('/admin/uzytkownicy') ?>" style="margin-top:10px;display:flex;gap:10px;align-items:center;">
        <input type="hidden" name="filtr" value="<?= htmlspecialchars($filtr) ?>">
        <input class="search-input" type="search" name="q" value="<?= htmlspecialchars($szukaj) ?>"
               placeholder="Imię albo e-mail" style="flex:1;">
        <button type="submit" class="btn btn-secondary">Szukaj</button>
        <?php if ($szukaj !== ''): ?>
        <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($link(['q' => null, 'strona' => null])) ?>">Wyczyść</a>
        <?php endif; ?>
    </form>
</section>

<section class="sec">
    <div class="sec-head">
        <h2>Lista</h2>
        <span><?= (int) $wynik['total'] ?></span>
    </div>

    <?php if (!$items): ?>
    <div class="box"><p class="desc" style="margin:0;">Nic tu nie ma dla tych kryteriów.</p></div>
    <?php else: ?>
    <div class="box" style="padding:0;overflow-x:auto;">
        <table class="adm-table">
            <thead>
                <tr><th>Konto</th><th>Rejestracja</th><th>Aktywność</th><th>Stan</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $u): ?>
            <?php
                $id = (int) $u['id'];
                $zablokowany = $u['blocked_at'] !== null;
                $pusty = $tresc[$id]['puste'] ?? false;
            ?>
            <tr<?= $zablokowany ? ' style="opacity:.62;"' : '' ?>>
                <td>
                    <b><?= htmlspecialchars($u['name'] ?: '— bez imienia —') ?></b>
                    <?php if ($u['is_admin']): ?><span class="tag tag--plain">admin</span><?php endif; ?>
                    <div class="desc"><?= htmlspecialchars($u['email']) ?></div>
                    <?php if ($u['public_slug']): ?>
                    <div class="desc"><a href="<?= View::url('/rowerzysta/' . $u['public_slug']) ?>">zobacz profil →</a></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars(Format::dateShort($u['created_at'])) ?>
                    <?php // Skąd konto: e-mail czy logowanie społecznościowe. Przy
                          // fejkach to pierwsza rzecz, na którą się patrzy. ?>
                    <div class="desc"><?= $u['przez_oauth'] ? 'Google / Strava' : ($u['ma_haslo'] ? 'e-mail i hasło' : 'bez hasła') ?></div>
                </td>
                <td>
                    <?php if ($pusty): ?>
                    <span class="desc">nic jeszcze nie zrobił</span>
                    <?php else: ?>
                    <?php foreach ($tresc[$id]['pozycje'] as $etykieta => $ile): ?>
                    <div class="desc"><?= (int) $ile ?> · <?= htmlspecialchars($etykieta) ?></div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($zablokowany): ?>
                    <b><?= $u['deletion_requested_at'] !== null ? 'Prośba o usunięcie' : 'Zablokowane' ?></b>
                    <div class="desc"><?= htmlspecialchars($u['blocked_reason'] ?? '') ?></div>
                    <div class="desc"><?= htmlspecialchars(Format::dateShort($u['blocked_at'])) ?><?php
                        if ($u['blokujacy']): ?> · <?= htmlspecialchars($u['blokujacy']) ?><?php endif; ?></div>
                    <?php elseif ($u['email_verified_at'] === null): ?>
                    <b>Bez potwierdzenia</b>
                    <div class="desc">nie kliknął w link z maila</div>
                    <?php else: ?>
                    Czynne
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($u['is_admin']): ?>
                    <span class="desc">—</span>
                    <?php elseif ($zablokowany): ?>
                    <form method="post" action="<?= View::url('/admin/uzytkownicy/' . $id . '/odblokuj') ?>">
                        <?= Core\Csrf::field() ?><?= $powrot() ?>
                        <button type="submit" class="btn btn--sm">Odblokuj</button>
                    </form>
                    <?php else: ?>
                    <details class="adm-akcje">
                        <summary class="btn btn--sm btn-secondary">Działania</summary>
                        <div class="box" style="margin-top:8px;display:flex;flex-direction:column;gap:10px;min-width:280px;">
                            <?php // ZAMKNIĘCIE PANELU. <details> zamyka się też
                                  // ponownym kliknięciem w „Działania", ale ten
                                  // przycisk stoi już pod kursorem — po otwarciu
                                  // panelu summary jest wyżej i poza wzrokiem.
                                  // `open = false` zamiast toggle: kliknięcie w X
                                  // ma zamykać, nigdy nie otwierać z powrotem. ?>
                            <button type="button" class="adm-akcje__x"
                                    onclick="this.closest('details').open = false;"
                                    aria-label="Zamknij"><?= Utils\Icon::render('close') ?></button>
                            <form method="post" action="<?= View::url('/admin/uzytkownicy/' . $id . '/reset-hasla') ?>">
                                <?= Core\Csrf::field() ?><?= $powrot() ?>
                                <button type="submit" class="btn btn--sm btn-secondary">Wyślij link do nowego hasła</button>
                                <?php // Mówimy wprost, że hasła nie zobaczy ani nie ustawi —
                                      // inaczej admin będzie tego szukał. ?>
                                <div class="desc">Hasło ustawia sam właściciel konta, z linku w mailu.</div>
                            </form>

                            <form method="post" action="<?= View::url('/admin/uzytkownicy/' . $id . '/blokuj') ?>">
                                <?= Core\Csrf::field() ?><?= $powrot() ?>
                                <input class="search-input" type="text" name="powod" maxlength="255" required
                                       placeholder="Powód blokady (obowiązkowy)">
                                <button type="submit" class="btn btn--sm" style="margin-top:6px;">Zablokuj</button>
                            </form>

                            <?php if ($pusty): ?>
                            <form method="post" action="<?= View::url('/admin/uzytkownicy/' . $id . '/usun') ?>"
                                  onsubmit="return confirm('Skasować konto <?= htmlspecialchars(addslashes($u['email'])) ?>? Tego się nie cofnie.');">
                                <?= Core\Csrf::field() ?><?= $powrot() ?>
                                <button type="submit" class="btn btn--sm btn-secondary">Skasuj konto</button>
                                <div class="desc">To konto nie ma żadnej historii, więc nic po nim nie zostanie.</div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($wynik['stron'] > 1): ?>
    <div class="op-head__act" style="margin-top:14px;align-items:center;">
        <?php if ($wynik['strona'] > 1): ?>
        <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($link(['strona' => $wynik['strona'] - 1])) ?>">← Poprzednia</a>
        <?php endif; ?>
        <span class="desc">Strona <?= (int) $wynik['strona'] ?> z <?= (int) $wynik['stron'] ?></span>
        <?php if ($wynik['strona'] < $wynik['stron']): ?>
        <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($link(['strona' => $wynik['strona'] + 1])) ?>">Następna →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</section>
