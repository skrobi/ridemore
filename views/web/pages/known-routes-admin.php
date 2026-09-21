<?php
// views/web/pages/known-routes-admin.php
// Znane trasy (Etap 8, §10) — LISTA w panelu admina.
// Oczekuje: $wynik (KnownRoute::search), $liczniki (KnownRoute::counters),
// $unordered (KnownRoute::unorderedCounts dla widocznej strony), $error, $info.
//
// Zbudowane z komponentów ekranu użytkowników (`users-admin.php`): `.op-head`,
// `.trust-bar`, `.disc-tabs`, `.adm-table`, prev/next na dole. To ten sam
// rodzaj ekranu — katalog danych referencyjnych, po którym trzeba UMIEĆ SIĘ
// PORUSZAĆ, a nie tylko go obejrzeć.
//
// Poprzednia wersja (do 2026-08-19) wypisywała WSZYSTKIE trasy jako karty,
// każdą z rozwijanym formularzem edycji. Przy dwustu trasach to dwieście
// formularzy z pełną listą regionów w jednym dokumencie i zero sposobów, żeby
// znaleźć konkretną. Formularze poszły na własne podstrony.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\Format;
use Utils\View;

$items = $wynik['items'];
$szukaj = $wynik['szukaj'];
$filtr = $wynik['filtr'];
$sort = $wynik['sort'];
$unordered = $unordered ?? [];

// Stan listy przenoszony w linkach; `null` w $zmiany kasuje parametr.
// Pierwsza strona nie trafia do adresu — `?strona=1` to ten sam widok co goły
// adres, a dwa adresy na jeden widok psują i historię, i cofanie.
$stan = ['q' => $szukaj, 'filtr' => $filtr, 'sort' => $sort, 'strona' => $wynik['strona']];
$query = static function (array $zmiany = []) use ($stan): string {
    $p = array_filter(
        array_merge($stan, $zmiany),
        static fn($v, string $k): bool => $v !== null && $v !== '' && !($k === 'strona' && (int) $v <= 1),
        ARRAY_FILTER_USE_BOTH
    );
    return $p ? '?' . http_build_query($p) : '';
};
$link = static fn(array $zmiany = []): string => View::url('/admin/znane-trasy') . $query($zmiany);
// Adres edycji NIESIE STAN LISTY, żeby „Zapisz" wróciło na tę samą stronę
// wyników — przy dwustu trasach powrót na początek katalogu znaczy szukanie
// od nowa.
$edytujUrl = static fn(int $id): string => View::url('/admin/znane-trasy/' . $id . '/edytuj') . $query();

// Te same parametry jako pola ukryte — akcje POST wracają dokładnie tu.
$powrot = static function () use ($stan): string {
    $out = '';
    foreach (array_filter($stan, static fn($v): bool => $v !== null && $v !== '') as $k => $v) {
        $out .= '<input type="hidden" name="wroc_' . $k . '" value="' . htmlspecialchars((string) $v) . '">';
    }
    return $out;
};

$zakladki = [
    ''                => 'Wszystkie',
    'aktywne'         => 'Aktywne',
    'wylaczone'       => 'Wyłączone',
    'do-przeliczenia' => 'Do przeliczenia',
    'bez-punktow'     => 'Bez punktów',
];

$sortowania = [
    ''          => 'nazwa A–Z',
    'najnowsze' => 'najnowsze',
    'dystans'   => 'najdłuższe',
    'pola'      => 'najwięcej pól',
    'region'    => 'region',
];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>

<div class="op-head">
    <div class="op-head__c">
        <h1 class="display">Znane trasy</h1>
        <p class="op-head__sub">Szlaki, które rowerzysta zalicza samym jeżdżeniem: Velo Czorsztyn,
            Green Velo, Szlak Orlich Gniazd, trasy zawodów, lokalne klasyki. Po wgraniu pliku GPX
            trasa zostaje podzielona na te same pola co przejazdy — postęp każdego uczestnika
            liczy się dalej sam, bez niczyjego udziału.</p>
    </div>
    <div class="op-head__act">
        <a class="btn" href="<?= View::url('/admin/znane-trasy/nowa') ?>">Dodaj trasę</a>
    </div>
</div>

<?php if ($error): ?>
<p class="form-error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
<?php if ($info): ?>
<p class="form-success"><?= htmlspecialchars($info) ?></p>
<?php endif; ?>

<div class="trust-bar">
    <div class="trust-item">
        <div class="trust-item-label">Tras</div>
        <div class="trust-item-value"><?= (int) $liczniki['wszystkie'] ?></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Aktywnych</div>
        <div class="trust-item-value"><?= (int) $liczniki['aktywne'] ?><small>widoczne na mapie</small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Wyłączonych</div>
        <div class="trust-item-value"><?= (int) $liczniki['wylaczone'] ?></div>
    </div>
    <?php // Ten kafel to USTERKA, nie statystyka: tyle tras nie rysuje się na
          // mapie, bo ich pola nie mają kolejności wzdłuż śladu (migr. 048). ?>
    <div class="trust-item">
        <div class="trust-item-label">Do przeliczenia</div>
        <div class="trust-item-value"><?= (int) $liczniki['do_przeliczenia'] ?><small>nie rysują się na mapie</small></div>
    </div>
</div>

<section class="sec">
    <div class="disc-tabs">
        <?php foreach ($zakladki as $klucz => $etykieta): ?>
        <a class="disc-tab<?= $filtr === $klucz ? ' is-on' : '' ?>"
           href="<?= htmlspecialchars($link(['filtr' => $klucz ?: null, 'strona' => null])) ?>"><?= $etykieta ?></a>
        <?php endforeach; ?>
    </div>

    <form class="box" method="get" action="<?= View::url('/admin/znane-trasy') ?>"
          style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="filtr" value="<?= htmlspecialchars($filtr) ?>">
        <input class="search-input" type="search" name="q" value="<?= htmlspecialchars($szukaj) ?>"
               placeholder="Nazwa, adres trasy albo region" style="flex:1 1 240px;">
        <?php // Sortowanie w tym samym formularzu co szukanie — jeden „Szukaj"
              // zamiast dwóch kontrolek wysyłających się nawzajem. ?>
        <select class="search-input" name="sort" style="flex:0 0 auto;width:auto;"
                onchange="this.form.submit()">
            <?php foreach ($sortowania as $klucz => $etykieta): ?>
            <option value="<?= $klucz ?>"<?= $sort === $klucz ? ' selected' : '' ?>>Sortuj: <?= $etykieta ?></option>
            <?php endforeach; ?>
        </select>
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
    <div class="box"><p class="desc" style="margin:0;">
        <?= $szukaj !== '' || $filtr !== ''
            ? 'Nic tu nie ma dla tych kryteriów.'
            : 'Nie ma jeszcze żadnej znanej trasy. Dopóki jej nie ma, sekcja „Znane trasy" nie pokazuje się na stronie odkryć.' ?>
    </p></div>
    <?php else: ?>
    <div class="box" style="padding:0;overflow-x:auto;">
        <table class="adm-table">
            <thead>
                <tr><th>Trasa</th><th>Przebieg</th><th>Region</th><th>Stan</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $route): ?>
            <?php
                $id = (int) $route['id'];
                $edytuj = $edytujUrl($id);
                $missingOrder = (int) ($unordered[$id] ?? 0);
            ?>
            <tr>
                <td>
                    <?php if (!empty($route['cover_photo_url'])): ?>
                    <img src="<?= htmlspecialchars(Utils\Image::src($route['cover_photo_url'], 'thumb')) ?>" alt=""
                         width="54" height="36"
                         style="float:left;margin-right:10px;border-radius:6px;object-fit:cover;">
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($edytuj) ?>"><b><?= htmlspecialchars($route['name']) ?></b></a>
                    <div class="desc"><a href="<?= View::url('/trasy/' . $route['slug']) ?>">/trasy/<?= htmlspecialchars($route['slug']) ?></a></div>
                </td>
                <td>
                    <?= (int) $route['cells_total'] ?> pól
                    <?php if ((float) $route['distance_km'] > 0): ?>
                    <div class="desc"><?= htmlspecialchars(Format::distance((float) $route['distance_km'])) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?= !empty($route['region_label']) ? htmlspecialchars($route['region_label']) : '<span class="desc">—</span>' ?>
                </td>
                <td>
                    <?php if (!(bool) $route['is_active']): ?>
                    <span class="badge">wyłączona</span>
                    <?php endif; ?>
                    <?php if (!(bool) $route['bonus_enabled']): ?>
                    <span class="badge">bez punktów</span>
                    <?php endif; ?>
                    <?php if ($missingOrder > 0): ?>
                    <div class="desc" style="color:var(--danger-dark);">nie rysuje się na mapie<br>
                        <?= $missingOrder ?> pól bez kolejności</div>
                    <?php elseif ((bool) $route['is_active'] && (bool) $route['bonus_enabled']): ?>
                    <span class="desc">w porządku</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="action-row">
                        <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($edytuj) ?>">Edytuj</a>
                        <?php // Włącz/wyłącz zostaje przy wierszu: to jedno
                              // kliknięcie i najczęstsza czynność na liście.
                              // Reszta działań mieszka na stronie trasy. ?>
                        <form method="post" action="<?= View::url('/admin/znane-trasy/' . $id . '/przelacz') ?>">
                            <?= Csrf::field() ?><?= $powrot() ?>
                            <button class="btn btn--sm btn-secondary" type="submit">
                                <?= (bool) $route['is_active'] ? 'Wyłącz' : 'Włącz' ?>
                            </button>
                        </form>
                    </div>
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
