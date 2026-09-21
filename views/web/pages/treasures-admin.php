<?php
// views/web/pages/treasures-admin.php
// ZARZĄDZANIE SKARBAMI — mapa po lewej, formularz po prawej (Etap 8D).
//
// UKŁAD Z 2026-08-20 (zgłoszenie usera: „nie mam możliwości przesunięcia
// zakotwiczenia, brak możliwości usunięcia; sądziłem, że będę mógł kliknąć
// w punkt na mapie, wtedy po prawej otworzy mi się formularz edycji i wtedy
// aktywuje się możliwość przesuwania lokalizacji"). Wcześniej:
//   * formularz siedział w MODALU (`<dialog>`), który zasłaniał mapę — czyli
//     dokładnie to, na co trzeba patrzeć, poprawiając położenie punktu,
//   * klik w mapę zawsze znaczył „nowy skarb", a edycja szła wyłącznie linkiem
//     z listy; kliknięcie w istniejącą pinezkę nie robiło nic,
//   * lokalizacji istniejącego skarbu nie dało się ruszyć inaczej niż przez
//     postawienie go od nowa,
//   * kasowania nie było wcale,
//   * lista wypisywała WSZYSTKIE punkty i wsypywała je wszystkie do HTML-a
//     jako JSON — przy tysiącu punktów (pytanie usera) to ściana i kilkaset
//     kilobajtów na każde wejście.
//
// Teraz: mapa i formularz stoją OBOK SIEBIE, klik w pinezkę wybiera skarb do
// edycji, wybrana pinezka jest PRZECIĄGALNA, a pod spodem stoi lista z tym
// samym zestawem narzędzi co panel znanych tras (liczniki, zakładki filtrów,
// szukanie, sortowanie, strony).
//
// Trasy referencyjne rysują się pod spodem, żeby było widać, czy miejsce leży
// przy szlaku — skarb w szczerym polu, do którego nic nie prowadzi, znajdzie
// tylko ten, kto specjalnie po niego pojedzie.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';

$lista = $lista ?? ['items' => [], 'total' => 0, 'strona' => 1, 'stron' => 1, 'szukaj' => '', 'filtr' => '', 'sort' => ''];
$liczniki = $liczniki ?? ['wszystkie' => 0, 'aktywne' => 0, 'zgloszone' => 0, 'wycofane' => 0, 'nieznalezione' => 0];
$items = $lista['items'];
$szukaj = $lista['szukaj'];
$filtr = $lista['filtr'];
$sort = $lista['sort'];

$editing = $editing ?? null;
$categories = $categories ?? [];
$trails = $trails ?? [];
$defaults = $defaults ?? ['points' => 50, 'radius' => 150];

$rarities = ['COMMON' => 'Zwykły', 'RARE' => 'Rzadki', 'EPIC' => 'Epicki', 'LEGENDARY' => 'Legendarny'];
$origins  = ['OFFICIAL' => 'Oficjalny', 'ORGANIZER' => 'Wydarzenia', 'PARTNER' => 'Partnera', 'COMMUNITY' => 'Społeczności'];
$statuses = ['ACTIVE' => 'Aktywny', 'PROPOSED' => 'Zgłoszony', 'RETIRED' => 'Wycofany'];

// Stan listy przenoszony w linkach; `null` w $zmiany kasuje parametr. Pierwsza
// strona nie trafia do adresu — `?strona=1` to ten sam widok co goły adres.
// Ta sama mechanika co w panelu znanych tras, żeby oba ekrany zachowywały się
// tak samo pod ręką.
$stan = ['szukaj' => $szukaj, 'filtr' => $filtr, 'sort' => $sort, 'strona' => $lista['strona']];
$query = static function (array $zmiany = []) use ($stan): string {
    $p = array_filter(
        array_merge($stan, $zmiany),
        static fn($v, string $k): bool => $v !== null && $v !== '' && !($k === 'strona' && (int) $v <= 1),
        ARRAY_FILTER_USE_BOTH
    );
    return $p ? '?' . http_build_query($p) : '';
};
$link = static fn(array $zmiany = []): string => View::url('/admin/skarby') . $query($zmiany);

// Te same parametry jako pola ukryte — akcje POST (zapis, kasowanie, status)
// wracają dokładnie na tę stronę wyników. Bez tego poprawianie punktów seriami
// znaczyłoby odtwarzanie filtra po każdym zapisie.
$powrot = static function () use ($stan): string {
    $out = '';
    foreach (array_filter($stan, static fn($v): bool => $v !== null && $v !== '') as $k => $v) {
        $out .= '<input type="hidden" name="wroc_' . $k . '" value="' . htmlspecialchars((string) $v) . '">';
    }
    return $out;
};

$zakladki = [
    ''              => 'Wszystkie',
    'aktywne'       => 'Aktywne',
    'zgloszone'     => 'Zgłoszone',
    'wycofane'      => 'Wycofane',
    'nieznalezione' => 'Nieznalezione',
];
$sortowania = [
    ''            => 'nazwa',
    'najnowsze'   => 'najnowsze',
    'punkty'      => 'punkty',
    'znalezienia' => 'znalezienia',
    'region'      => 'region',
];
?>

<div class="op-head">
    <div class="op-head__c">
        <div class="tags"><span class="tag tag--plain">Etap 8D</span></div>
        <h1>Skarby</h1>
        <p class="op-head__sub">Kliknij w mapę, żeby postawić nowy skarb. Kliknij w pinezkę, żeby
            ją edytować — wtedy da się ją przeciągnąć w inne miejsce.</p>
    </div>
</div>

<?php if (!empty($info)): ?>
<div class="box" style="margin-top:14px;border-color:var(--accent);"><b><?= htmlspecialchars($info) ?></b></div>
<?php endif; ?>

<?php
    $editingPhotos = $editingPhotos ?? [];
    $photoLimit    = $photoLimit ?? 3;
?>
<?php // KASOWANIE ZDJECIA — po formularzu na zdjecie, wszystkie POZA formularzem
      // edycji skarbu (HTML nie pozwala zagniezdzac formularzy). Wiaze je
      // atrybut `form` na krzyzyku przy miniaturze.
      //
      // Osobne formularze, a nie jeden z polem ukrytym przelaczanym JS-em:
      // bez JS krzyzyk ma dzialac tak samo, a przy pieciu zdjeciach to i tak
      // piec linijek HTML-a. ?>
<?php foreach ($editingPhotos as $zdj): ?>
<form method="post" id="trPhotoDel<?= (int) $zdj['id'] ?>" style="display:none;"
      action="<?= View::url('/admin/skarby/zdjecie/' . (int) $zdj['id'] . '/usun') ?>">
    <?= Core\Csrf::field() ?><?= $powrot() ?>
</form>
<?php endforeach; ?>

<?php // KASOWANIE STOI W OSOBNYM FORMULARZU, poza formularzem edycji — HTML nie
      // pozwala zagnieżdżać formularzy, a przycisk „Skasuj" musi wysyłać coś
      // zupełnie innego niż „Zapisz". Wiąże je atrybut `form` na przycisku. ?>
<form method="post" id="trDelForm" style="display:none;"
      action="<?= View::url('/admin/skarby') ?>" data-base="<?= View::url('/admin/skarby') ?>">
    <?= Core\Csrf::field() ?><?= $powrot() ?>
</form>

<section class="sec">
    <div class="tr-work">
        <div class="tr-work__map">
            <div id="treasureMap"></div>
            <?php
            // WARSTWY — ta sama kontrolka co na /odkrycia, profilu i stronie
            // trasy (Etap 4 warstwy-mapy.md). Zawężona w kontrolerze do warstw
            // KONTEKSTOWYCH: skarby rysuje ten panel sam, przeciąganymi
            // pinezkami, więc węzeł „Skarby" postawiłby obok nich drugi komplet
            // znaczników tych samych punktów.
            $mlId     = 'treasureLayers';
            $mlLayers = $mapLayers ?? [];
            require __DIR__ . '/../partials/map-layers.php';
            ?>
            <div class="tr-bar">
                <?php // WYSZUKIWARKA MIEJSC — ten sam mechanizm co „miejsce startu"
                      // w formularzu wydarzenia (Nominatim). Bez niej postawienie
                      // skarbu w Bieszczadach znaczyło przeciąganie mapy przez pół
                      // kraju. Przesuwa KADR, nie stawia pinezki. ?>
                <div class="tr-search">
                    <input class="search-input" id="trFind" type="search" autocomplete="off"
                           placeholder="Szukaj miejsca (np. Sokolica, Przełęcz Wyżna)…">
                    <ul class="tr-found" id="trFound" hidden></ul>
                </div>
                <button type="button" class="btn btn-secondary" id="trHere">Moja lokalizacja</button>
                <span class="tr-bar__hint" id="trHint">Kliknij w mapę, żeby postawić skarb.</span>
            </div>
        </div>

        <aside class="tr-work__side">
            <?php // enctype MUSI tu byc od momentu, gdy w formularzu jest <input
                  // type="file"> — bez niego przegladarka wysyla same nazwy plikow,
                  // $_FILES jest puste i zapis „udaje sie" nie wgrywajac nic. ?>
            <form method="post" action="<?= View::url('/admin/skarby/zapisz') ?>" class="tr-form" id="trForm" enctype="multipart/form-data">
                <?= Core\Csrf::field() ?><?= $powrot() ?>
                <input type="hidden" name="id" id="trId" value="<?= (int) ($editing['id'] ?? 0) ?>">
                <input type="hidden" name="lat" id="trLat" value="<?= htmlspecialchars((string) ($editing['lat'] ?? '')) ?>">
                <input type="hidden" name="lon" id="trLon" value="<?= htmlspecialchars((string) ($editing['lon'] ?? '')) ?>">

                <div class="tr-form__hd">
                    <h2 id="trTitle"><?= $editing ? 'Edycja: ' . htmlspecialchars($editing['name']) : 'Nowy skarb' ?></h2>
                    <?php // Znaczniki stanu obok nazwy — rzadkość, kod naklejki
                          // i liczba znalezień. Do tej daty nie było ich na tym
                          // ekranie wcale, a to one rozstrzygają, czy skarb wolno
                          // jeszcze skasować i czy w ogóle ktoś go używa. ?>
                    <span class="tr-badges" id="trBadges"<?= $editing ? '' : ' hidden' ?>>
                        <?php if ($editing): ?>
                        <span class="rarity-chip is-rarity-<?= strtolower((string) $editing['rarity']) ?>" id="trBadgeRarity"><?= htmlspecialchars((string) Models\Treasure::rarityLabel($editing['rarity'])) ?></span>
                        <span class="tr-badges__m" id="trBadgeMeta">
                            <?= htmlspecialchars((string) ($editing['code'] ?? '')) ?>
                            · <?= (int) ($editing['claims'] ?? 0) ?> <?= Utils\Format::plural((int) ($editing['claims'] ?? 0), 'znalezienie', 'znalezienia', 'znalezień') ?>
                        </span>
                        <?php endif; ?>
                    </span>
                    <span style="flex:1;"></span>
                    <button type="button" class="btn btn--sm btn-secondary" id="trNew">+ Nowy</button>
                </div>
                <p class="tr-coords" id="trCoords"></p>

                <?php // PUSTY STAN — panel bez wybranego punktu ma powiedzieć, co
                      // zrobić, a nie stać jako formularz bez treści. ?>
                <p class="desc" id="trEmpty"<?= $editing ? ' hidden' : '' ?>>Nie wybrano punktu.
                    Kliknij w mapę, żeby postawić nowy, albo w istniejącą pinezkę, żeby ją poprawić.</p>

                <div id="trFields"<?= $editing ? '' : ' hidden' ?>>

                    <?php // SIATKA DWUKOLUMNOWA (2026-09-12). Do tej daty panel miał
                          // 360 px szerokości, a formularz w środku 1299 px — 2,2 ekranu
                          // przewijania WEWNĄTRZ ramki, z „Zapisz skarb" poza zasięgiem.
                          // Teraz panel jest szeroki (patrz `.tr-work` w style.css),
                          // więc pola mieszczą się bez ani jednego wewnętrznego paska. ?>
                    <div class="tr-grid">
                        <div class="tr-grid__full">
                            <label class="form-field-label" for="trName">Nazwa miejsca</label>
                            <input class="tr-fld" id="trName" name="name" maxlength="160"
                                   value="<?= htmlspecialchars((string) ($editing['name'] ?? '')) ?>">
                        </div>

                        <div class="tr-grid__full">
                            <label class="form-field-label" for="trDesc">Opis</label>
                            <textarea class="tr-fld" id="trDesc" name="description" rows="2"
                                      placeholder="Co tu jest ciekawego?"><?= htmlspecialchars((string) ($editing['description'] ?? '')) ?></textarea>
                        </div>

                        <div>
                            <label class="form-field-label" for="trCat">Kategoria</label>
                            <select class="tr-fld" id="trCat" name="category_item_id">
                                <option value="">—</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"<?= (int) ($editing['category_item_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>>
                                    <?php // Bez ikony — <option> nie renderuje SVG. ?>
                                    <?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-field-label" for="trRarity">Rzadkość</label>
                            <select class="tr-fld" id="trRarity" name="rarity">
                                <?php foreach ($rarities as $k => $v): ?>
                                <option value="<?= $k ?>"<?= ($editing['rarity'] ?? 'COMMON') === $k ? ' selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="hint">Nie zmienia punktów — maluje chip na mapie i w Pulsie.</p>
                        </div>

                        <div>
                            <label class="form-field-label" for="trPoints">Punkty</label>
                            <input class="tr-fld tr-fld--num" type="number" id="trPoints" name="points" min="0" max="10000"
                                   value="<?= (int) ($editing['points'] ?? $defaults['points']) ?>">
                        </div>

                        <div>
                            <label class="form-field-label" for="trRadius">Promień zaliczenia</label>
                            <span class="tr-unit">
                                <input class="tr-fld tr-fld--num" type="number" id="trRadius" name="claim_radius_m" min="10" max="2000"
                                       value="<?= (int) ($editing['claim_radius_m'] ?? $defaults['radius']) ?>">
                                <span>m</span>
                            </span>
                        </div>
                    </div>

                    <?php // POZIOM UJAWNIENIA — TRZY KARTY, NIE <select> (2026-09-12).
                          // To jedyna rzecz, która odróżnia skarb od zwykłej pinezki,
                          // a stała jako jeden z sześciu identycznych rozwijanych pól
                          // w kolumnie. Karty pokazują różnicę bez czytania podpowiedzi
                          // pod spodem.
                          //
                          // Nazwa pola i wartości BEZ ZMIAN (`reveal_level`, 2/1/0) —
                          // serwer dostaje dokładnie to, co dostawał. JS też się nie
                          // zmienił w miejscach wywołania: `pola.reveal.value` czyta
                          // i pisze zaznaczony radio przez getter/setter (patrz niżej). ?>
                    <div class="tr-block">
                        <span class="form-field-label">Ujawnienie na mapie</span>
                        <div class="tr-reveal" id="trReveal">
                            <?php
                            $krPoziomy = [
                                2 => ['Jawny', 'Pinezka i zdjęcie widoczne od razu.'],
                                1 => ['Trop', 'Po odkryciu pola pokazuje się wskazówka.'],
                                0 => ['Ukryty', 'W polu tylko znak zapytania.'],
                            ];
                            $krWybrany = (int) ($editing['reveal_level'] ?? 2);
                            ?>
                            <?php foreach ($krPoziomy as $krP => [$krNazwa, $krOpis]): ?>
                            <label class="tr-reveal__o<?= $krWybrany === $krP ? ' is-on' : '' ?>">
                                <input type="radio" name="reveal_level" value="<?= $krP ?>"<?= $krWybrany === $krP ? ' checked' : '' ?>>
                                <span>
                                    <b><?= $krNazwa ?></b>
                                    <span class="hint"><?= $krOpis ?></span>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php // TROP POKAZUJE SIĘ TYLKO WTEDY, GDY COŚ ROBI. Przy „Jawnym"
                          // to pole nie ma odbiorcy — a stało w formularzu zawsze. ?>
                    <div class="tr-block tr-block--sub" id="trHintBox"<?= $krWybrany === 2 ? ' hidden' : '' ?>>
                        <label class="form-field-label" for="trHintTxt">Trop (wskazówka)</label>
                        <input class="tr-fld" id="trHintTxt" name="hint" maxlength="300"
                               placeholder="Szukaj miejsca, z którego zobaczysz trzy jeziora."
                               value="<?= htmlspecialchars((string) ($editing['hint'] ?? '')) ?>">
                        <p class="hint">Widoczna dopiero temu, kto odkryje to pole siatki.</p>
                    </div>

                    <?php // ZDJĘCIA OBOK SIEBIE — dwa pola tego samego rodzaju, a stały
                          // jedno pod drugim, każde ze swoim akapitem wyjaśnienia.
                          //
                          // Kolumna `photo_url` i cała reguła ujawniania istniały od
                          // początku modułu — brakowało WYŁĄCZNIE tego pola, przez co
                          // żaden ze 102 skarbów nie miał zdjęcia (SKA/14). ?>
                    <div class="tr-grid tr-grid--photos">
                        <div>
                            <label class="form-field-label" for="trPhoto">Zdjęcie główne</label>
                            <div class="tr-photo">
                                <?php if (!empty($editing['photo_url'])): ?>
                                <img src="<?= htmlspecialchars(Utils\Image::src($editing['photo_url'], 'thumb')) ?>" alt="" width="112" height="80">
                                <?php endif; ?>
                                <div class="tr-photo__b">
                                    <input class="tr-fld" type="file" id="trPhoto" name="photo" accept="image/jpeg,image/png,image/webp">
                                    <?php if (!empty($editing['photo_url'])): ?>
                                    <label class="tr-check"><input type="checkbox" name="photo_remove" value="1"> usuń zdjęcie</label>
                                    <?php endif; ?>
                                    <p class="hint">Jawny pokazuje je każdemu. Trop i Ukryty — dopiero znalazcy.</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="form-field-label" for="trGallery">Galeria znalazców</label>
                            <?php if (!empty($editingPhotos)): ?>
                            <div class="tr-gal">
                                <?php foreach ($editingPhotos as $zdj): ?>
                                <div class="tr-gal__i">
                                    <img src="<?= htmlspecialchars(Utils\Image::src($zdj['url'], 'thumb')) ?>" alt="" loading="lazy">
                                    <?php // Kasowanie idzie WLASNYM formularzem, nie przyciskiem
                                          // w formularzu skarbu — inaczej „usun zdjecie" zapisywaloby
                                          // przy okazji wszystkie niezapisane zmiany w polach obok. ?>
                                    <button type="submit" form="trPhotoDel<?= (int) $zdj['id'] ?>" class="tr-gal__x"
                                            aria-label="Usuń zdjęcie">×</button>
                                    <?php if ($zdj['authorName']): ?>
                                    <span class="tr-gal__by" title="Dodał: <?= htmlspecialchars($zdj['authorName']) ?>"><?= htmlspecialchars($zdj['authorName']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <input class="tr-fld" type="file" id="trGallery" name="gallery[]" multiple
                                   accept="image/jpeg,image/png,image/webp">
                            <p class="hint">Znalazcy dorzucają własne (<?= (int) $photoLimit ?> na osobę) — kasujesz je krzyżykiem.</p>
                        </div>
                    </div>

                    <?php // ZAAWANSOWANE ZŁOŻONE (2026-09-12). Status, pochodzenie
                          // i widoczność mają dobre wartości domyślne i przy stawianiu
                          // partii naklejek nie zmienia się ich ani razu — a zajmowały
                          // trzy z dwunastu pól na wierzchu.
                          //
                          // `<details>` bez własnego JS-a: otwieranie, klawiatura
                          // i stan `open` są za darmo, tak samo jak przy `.adm-akcje`
                          // na liście użytkowników. ?>
                    <details class="tr-more">
                        <summary>Ustawienia zaawansowane · status, pochodzenie, widoczność</summary>
                        <div class="tr-grid tr-grid--more">
                            <div>
                                <label class="form-field-label" for="trStatusTop">Status</label>
                                <select class="tr-fld" id="trStatusTop" name="status">
                                    <?php foreach ($statuses as $k => $v): ?>
                                    <option value="<?= $k ?>"<?= ($editing['status'] ?? 'ACTIVE') === $k ? ' selected' : '' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <?php // REGION WYPADŁ Z FORMULARZA (uwaga usera 2026-08-14:
                                      // „po co region w menu, jak wiadomo po lokalizacji").
                                      // Serwer wyprowadza go z NAJBLIŻSZEJ znanej trasy
                                      // (Models\Treasure::guessRegion). ?>
                                <label class="form-field-label" for="trOrigin">Pochodzenie</label>
                                <select class="tr-fld" id="trOrigin" name="origin">
                                    <?php foreach ($origins as $k => $v): ?>
                                    <option value="<?= $k ?>"<?= ($editing['origin'] ?? 'OFFICIAL') === $k ? ' selected' : '' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <span class="form-field-label">Widoczność</span>
                                <label class="tr-check"><input type="checkbox" name="is_active" value="1" id="trVisible"
                                    <?= (int) ($editing['is_active'] ?? 1) === 1 ? ' checked' : '' ?>> widoczny na mapie</label>
                            </div>
                        </div>
                    </details>

                    <div class="tr-actions">
                        <button class="btn" type="submit">Zapisz skarb</button>
                        <span style="flex:1;"></span>
                        <p class="hint" id="trDelNote" hidden></p>
                        <?php // KASOWANIE TYLKO NIEZNALEZIONEGO — reguła siedzi
                              // w Models\Treasure::deleteIfUnfound i tutaj jest
                              // wyłącznie przycisk. Znaleziony skarb schodzi ze
                              // sceny statusem „Wycofany": punkty, które zapłacił,
                              // leżą w niezmiennym rejestrze i muszą mieć na co
                              // wskazywać. ?>
                        <button class="btn btn--sm btn-secondary btn--danger" type="submit" form="trDelForm" id="trDelete"
                                data-znalezienia="0" hidden>Skasuj</button>
                    </div>
                </div>
            </form>
        </aside>
    </div>
</section>

<?php if ($pending): ?>
<?php // POCZEKALNIA (SKA/3) — punkty zgłoszone, jeszcze nieaktywne.
      //
      // Stoi NAD listą postawionych, bo to jedyna sekcja na tym ekranie, która
      // czeka na decyzję. Reszta jest archiwum.
      //
      // Admin ma tu DWA przyciski, nie jeden: „Aktywuj" pomija głosowanie
      // (bo czasem po prostu wie, że punkt jest dobry), „Odrzuć" kończy
      // sprawę. Sam licznik potwierdzeń robi swoje bez niego — te przyciski
      // są dla przypadków, w których czekanie na trzech rowerzystów nie ma
      // sensu. ?>
<section class="sec">
    <div class="sec-head">
        <h2>Czekają na potwierdzenie</h2>
        <span><?= count($pending) ?></span>
    </div>
    <div class="box" style="padding:0;overflow-x:auto;">
        <table class="adm-table">
            <thead><tr><th>Punkt</th><th>Zgłosił</th><th>Głosy</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pending as $t): ?>
            <tr>
                <td>
                    <b><?= htmlspecialchars($t['name']) ?></b>
                    <?php if ($t['category_label'] || $t['region_label']): ?>
                    <div class="desc"><?= htmlspecialchars(implode(' · ', array_filter([$t['region_label'], $t['category_label']]))) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($t['author_name'] ?: '—') ?></td>
                <td><b><?= (int) $t['confirmations'] ?></b> z <?= (int) $t['needed'] ?></td>
                <td style="white-space:nowrap;">
                    <form method="post" action="<?= View::url('/admin/skarby/' . (int) $t['id'] . '/status') ?>" style="display:inline;">
                        <?= Core\Csrf::field() ?><?= $powrot() ?>
                        <input type="hidden" name="status" value="ACTIVE">
                        <button type="submit" class="btn btn--sm">Aktywuj</button>
                    </form>
                    <form method="post" action="<?= View::url('/admin/skarby/' . (int) $t['id'] . '/status') ?>" style="display:inline;">
                        <?= Core\Csrf::field() ?><?= $powrot() ?>
                        <input type="hidden" name="status" value="RETIRED">
                        <button type="submit" class="btn btn--sm btn-secondary">Odrzuć</button>
                    </form>
                    <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($link(['skarb' => (int) $t['id']])) ?>">Edytuj</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php // LICZNIKI + LISTA — ten sam zestaw narzędzi co w panelu znanych tras
      // (zgłoszenie usera 2026-08-20: „szukanie po liście jest kłopotliwe, co
      // w przypadku 1000 punktów?"). Lista bez wyszukiwarki nie jest listą,
      // tylko ścianą — przy skarbach dochodzi do tego, że jedyne, co je
      // odróżnia z daleka, to nazwa i region. ?>
<div class="trust-bar" style="margin-top:24px;">
    <div class="trust-item">
        <div class="trust-item-label">Wszystkie</div>
        <div class="trust-item-value"><?= (int) $liczniki['wszystkie'] ?></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Aktywne</div>
        <div class="trust-item-value"><?= (int) $liczniki['aktywne'] ?><small>płacą punktami</small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Zgłoszone</div>
        <div class="trust-item-value"><?= (int) $liczniki['zgloszone'] ?><small>czekają na decyzję</small></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Nieznalezione</div>
        <div class="trust-item-value"><?= (int) $liczniki['nieznalezione'] ?><small>nikt tam nie dotarł</small></div>
    </div>
</div>

<section class="sec">
    <div class="disc-tabs">
        <?php foreach ($zakladki as $klucz => $etykieta): ?>
        <a class="disc-tab<?= $filtr === $klucz ? ' is-on' : '' ?>"
           href="<?= htmlspecialchars($link(['filtr' => $klucz ?: null, 'strona' => null])) ?>"><?= $etykieta ?></a>
        <?php endforeach; ?>
    </div>

    <form class="box" method="get" action="<?= View::url('/admin/skarby') ?>"
          style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="filtr" value="<?= htmlspecialchars($filtr) ?>">
        <input class="search-input" type="search" name="szukaj" value="<?= htmlspecialchars($szukaj) ?>"
               placeholder="Nazwa, kod z naklejki, region albo kategoria" style="flex:1 1 240px;">
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
        <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($link(['szukaj' => null, 'strona' => null])) ?>">Wyczyść</a>
        <?php endif; ?>
    </form>
</section>

<section class="sec">
    <div class="sec-head">
        <h2>Postawione skarby</h2>
        <span><?= (int) $lista['total'] ?></span>
    </div>

    <?php if (!$items): ?>
    <div class="box"><p class="desc" style="margin:0;">
        <?= $szukaj !== '' || $filtr !== ''
            ? 'Nic nie pasuje do tego zapytania.'
            : 'Jeszcze żadnego. Zacznij od pilotażu: 10–20 skarbów tam, gdzie realnie masz użytkowników.' ?>
    </p></div>
    <?php else: ?>
    <div class="box" style="padding:0;overflow-x:auto;">
        <table class="adm-table">
            <thead><tr><th></th><th>Nazwa</th><th>Kategoria</th><th>Rzadkość</th><th>Punkty</th>
                <th>Status</th><th>Znalezień</th><th>Kod</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($items as $t): ?>
                <tr>
                    <?php // MINIATURA (SKA/14) — pierwsza kolumna, bo „czy ten skarb
                          // ma juz zdjecie" jest pytaniem, ktore admin zadaje sobie
                          // przebiegajac liste wzrokiem, a nie po wejsciu w kazdy
                          // wpis. Pusty kwadrat mowi „brak" wyrazniej niz myslnik
                          // w kolumnie tekstowej. ?>
                    <td class="tr-thumb">
                        <?php if (!empty($t['photo_url'])): ?>
                        <span style="background-image:url('<?= htmlspecialchars(Utils\Image::src($t['photo_url'], 'thumb')) ?>')"></span>
                        <?php else: ?>
                        <span class="is-empty" title="Brak zdjęcia głównego"></span>
                        <?php endif; ?>
                        <?php if ((int) ($t['photos_count'] ?? 0) > 0): ?>
                        <small title="Zdjęcia w galerii">+<?= (int) $t['photos_count'] ?></small>
                        <?php endif; ?>
                    </td>
                    <td><b><?= htmlspecialchars($t['name']) ?></b><br>
                        <small><?= number_format((float) $t['lat'], 5, ',', '') ?>,
                               <?= number_format((float) $t['lon'], 5, ',', '') ?><?php
                            if (!empty($t['region_label'])) { echo ' · ' . htmlspecialchars($t['region_label']); }
                        ?></small></td>
                    <td><?= htmlspecialchars((string) ($t['category_label'] ?? '—')) ?></td>
                    <td><?= htmlspecialchars($rarities[$t['rarity']] ?? $t['rarity']) ?></td>
                    <td><?= (int) $t['points'] ?></td>
                    <td><?= htmlspecialchars($statuses[$t['status']] ?? $t['status']) ?><?= (int) $t['is_active'] === 0 ? ' (ukryty)' : '' ?></td>
                    <td><?= (int) ($t['claims'] ?? 0) ?></td>
                    <td><code><?= htmlspecialchars(substr((string) $t['code'], 0, 8)) ?>…</code></td>
                    <?php // „Edytuj" NIESIE STAN LISTY, żeby zapis wrócił na tę samą
                          // stronę wyników, i wskazuje punkt na mapie wyżej. ?>
                    <td><a href="<?= htmlspecialchars($link(['skarb' => (int) $t['id']])) ?>">Edytuj</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($lista['stron'] > 1): ?>
    <div class="op-head__act" style="margin-top:14px;align-items:center;">
        <?php if ($lista['strona'] > 1): ?>
        <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($link(['strona' => $lista['strona'] - 1])) ?>">← Poprzednia</a>
        <?php endif; ?>
        <span class="desc">Strona <?= (int) $lista['strona'] ?> z <?= (int) $lista['stron'] ?></span>
        <?php if ($lista['strona'] < $lista['stron']): ?>
        <a class="btn btn--sm btn-secondary" href="<?= htmlspecialchars($link(['strona' => $lista['strona'] + 1])) ?>">Następna →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</section>

<script>
(function () {
    var el = document.getElementById('treasureMap');
    if (!el || typeof ridemoreCreateMap !== 'function') { return; }

    var map = ridemoreCreateMap(el);

    // WARSTWA ODKRYĆ POD SPODEM (uwaga usera 2026-08-14: „nie widzę, co odkryte
    // a co nie"). Panel bez niej kazał stawiać skarby na ślepo — a to, czy
    // miejsce leży w terenie już zjeżdżonym, czy na białej plamie, jest przy
    // wycenie skarbu informacją pierwszorzędną.
    //
    // scope 'all', bo pytanie brzmi „czy społeczność tam bywa", nie „czy ja tam
    // byłem": skarb stawia się dla wszystkich.
    if (typeof ridemoreDiscoveryMap === 'function') {
        var boxWarstw = document.getElementById('treasureLayers');
        ridemoreDiscoveryMap(el, {
            map: map,
            context: 'all',
            endpoint: <?= json_encode(View::url('/api/discovery/cells'), JSON_UNESCAPED_SLASHES) ?>,
            // Szablony kafli dla warstw kontekstowych (ślady, trasy) — bez nich
            // przełącznik zapalałby warstwy, które nie mają czego narysować.
            sources: <?= json_encode($mapSources ?? [], JSON_UNESCAPED_SLASHES) ?>,
            fitToCells: false,
            // Stan Z KONTROLKI, nie wpisany w szablonie — jedno źródło prawdy
            // dla mapy i dla checkboksów. Zapas na wypadek braku kontrolki
            // (np. gdyby ktoś usunął partial) zostawia dawne, sztywne ustawienie.
            layers: (boxWarstw && typeof ridemoreReadLayers === 'function')
                ? ridemoreReadLayers(boxWarstw)
                : { cells: true, heat: false, trails: false, treasures: false }
        });

        // Przełączanie warstw — ta sama jedna linijka co na /odkrycia
        // (`map.ridemoreSetLayer`). BEZ zapisu stanu w adresie: ten ekran ma
        // już w `$_GET` szukanie, filtr, sortowanie i stronę listy, a warstwy
        // mapy nie są tu widokiem do podesłania komuś linkiem.
        if (boxWarstw) {
            boxWarstw.addEventListener('change', function (e) {
                var cb = e.target.closest('[data-layer]');
                if (cb) { map.ridemoreSetLayer(cb.dataset.layer, cb.checked); }
            });
        }
    }

    var pola = {
        id: document.getElementById('trId'),
        lat: document.getElementById('trLat'),
        lon: document.getElementById('trLon'),
        name: document.getElementById('trName'),
        desc: document.getElementById('trDesc'),
        cat: document.getElementById('trCat'),
        status: document.getElementById('trStatusTop'),
        rarity: document.getElementById('trRarity'),
        origin: document.getElementById('trOrigin'),
        points: document.getElementById('trPoints'),
        radius: document.getElementById('trRadius'),
        // UJAWNIENIE TO TERAZ TRZY KARTY RADIO, NIE <select> (2026-09-12).
        // Reszta tego skryptu posługuje się nim tak samo — `pola.reveal.value`
        // czyta i pisze — więc zamiast przepisywać oba miejsca wywołania
        // (`nowy()` i `wybierz()`) podstawiamy obiekt z getterem i setterem.
        // Setter odświeża też zaznaczenie karty i widoczność pola „Trop".
        reveal: {
            get value() {
                var z = document.querySelector('#trReveal input[name="reveal_level"]:checked');
                return z ? z.value : '2';
            },
            set value(v) {
                var z = document.querySelector('#trReveal input[name="reveal_level"][value="' + v + '"]');
                if (z) { z.checked = true; }
                odswiezUjawnienie();
            }
        },
        hint: document.getElementById('trHintTxt'),
        visible: document.getElementById('trVisible')
    };
    var tytul = document.getElementById('trTitle');
    var podpis = document.getElementById('trCoords');
    var hint = document.getElementById('trHint');
    var puste = document.getElementById('trEmpty');
    var polaBox = document.getElementById('trFields');
    var odznaki = document.getElementById('trBadges');
    var odznakaRzadkosc = document.getElementById('trBadgeRarity');
    var odznakaMeta = document.getElementById('trBadgeMeta');
    var przyciskUsun = document.getElementById('trDelete');
    var notkaUsun = document.getElementById('trDelNote');
    var formUsun = document.getElementById('trDelForm');

    // TRASY REFERENCYJNE pod spodem — kontekst, nie treść, więc cienko
    // i wyblakle: mają podpowiadać „tędy ludzie jeżdżą", a nie konkurować
    // o uwagę ze skarbami.
    var granice = null;
    <?php foreach ($trails as $t): ?>
    ridemoreAddGpxTrack(map, <?= json_encode(View::url($t['gpx_url']), JSON_UNESCAPED_SLASHES) ?>, {
        color: '#7C8A80', weight: 2, opacity: 0.55, hideMarkers: true,
        onLoaded: function (e) {
            granice = granice ? granice.extend(e.target.getBounds()) : e.target.getBounds();
            <?php // Kadr dociągamy do tras TYLKO wtedy, gdy nie edytujemy
                  // konkretnego punktu — inaczej wejście z listy pokazywałoby
                  // wszystko naraz zamiast skarbu, po który się przyszło. ?>
            <?php if (!$editing): ?>
            map.fitBounds(granice, { padding: [24, 24] });
            <?php endif; ?>
        }
    });
    <?php endforeach; ?>

    // SKARBY Z KADRU, NIE WSZYSTKIE (2026-08-20). Wcześniej cała tabela szła do
    // HTML-a jako JSON; przy tysiącu punktów to kilkaset kilobajtów na wejście
    // i tysiąc znaczników rysowanych niezależnie od tego, na co admin patrzy.
    var warstwa = L.layerGroup().addTo(map);
    var znaczniki = {};      // id -> marker
    var dane = {};           // id -> wiersz z serwera
    var wybrany = null;      // id edytowanego punktu
    var przeciagana = null;  // pinezka wybranego punktu (jedyna przeciągalna)
    var pobieranie = null;
    var timer = null;

    var KOLORY = {
        ACTIVE:   { obrys: '#8B4FBF', wypelnienie: '#B47FE0' },
        PROPOSED: { obrys: '#C98A12', wypelnienie: '#FFD98A' },
        RETIRED:  { obrys: '#8B95A3', wypelnienie: '#C8CED6' }
    };

    function wczytaj() {
        var b = map.getBounds();
        if (map.getSize().x < 1) { return; }
        if (pobieranie) { pobieranie.abort(); }
        pobieranie = new AbortController();
        fetch(<?= json_encode(View::url('/admin/skarby/punkty'), JSON_UNESCAPED_SLASHES) ?>
              + '?north=' + b.getNorth() + '&south=' + b.getSouth()
              + '&east=' + b.getEast() + '&west=' + b.getWest(),
              { signal: pobieranie.signal })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (odp) { if (odp) { rysuj(odp.treasures || []); } });
    }

    function rysuj(lista) {
        warstwa.clearLayers();
        znaczniki = {};
        lista.forEach(function (t) {
            dane[t.id] = t;
            // Świeże dane dla punktu, który stoi w formularzu — liczba znalezień
            // decyduje o przycisku „Skasuj", a przy wejściu z listy znamy ją
            // dopiero po tej odpowiedzi.
            if (t.id === wybrany) { stanKasowania(t); }
            // Wybrany punkt rysuje PRZECIĄGALNA pinezka, reszta kółkami:
            // przeciąganie ma być możliwe dokładnie dla tego jednego, który
            // stoi w formularzu obok — inaczej łatwo przesunąć nie ten punkt.
            if (t.id === wybrany) { pinezkaWybranego(t.lat, t.lon); return; }
            var kolor = KOLORY[t.status] || KOLORY.ACTIVE;
            var m = L.circleMarker([t.lat, t.lon], {
                radius: 7, color: kolor.obrys, fillColor: kolor.wypelnienie,
                fillOpacity: t.isActive ? 0.9 : 0.35, weight: 2
            }).bindTooltip(t.name + (t.claims ? ' · znaleziony ' + t.claims + '×' : ''))
              .on('click', function (e) {
                  L.DomEvent.stopPropagation(e);   // żeby klik nie postawił nowego
                  wybierz(t.id);
              })
              .addTo(warstwa);
            znaczniki[t.id] = m;
        });
    }

    function pinezkaWybranego(lat, lon) {
        if (przeciagana) { map.removeLayer(przeciagana); }
        przeciagana = L.marker([lat, lon], {
            draggable: true,
            icon: L.divIcon({ className: 'tr-drag', html: '<i></i>', iconSize: [26, 26], iconAnchor: [13, 13] })
        }).addTo(map);
        przeciagana.on('dragend', function () {
            var p = przeciagana.getLatLng();
            ustawWspolrzedne(p.lat, p.lng, 'przesunięte');
        });
        przeciagana.bindTooltip('Przeciągnij, żeby poprawić miejsce', { direction: 'top' });
    }

    /**
     * Znaczniki obok nazwy: rzadkość, kod naklejki, liczba znalezień.
     * Nowy punkt jeszcze ich nie ma (kod nadaje serwer przy zapisie), więc
     * cały pasek się wtedy chowa zamiast pokazywać puste miejsca.
     */
    var RZADKOSC = { COMMON: 'zwykły', RARE: 'rzadki', EPIC: 'epicki', LEGENDARY: 'legendarny' };
    function odswiezOdznaki(t) {
        if (!odznaki) { return; }
        if (!t) { odznaki.hidden = true; return; }
        odznaki.hidden = false;
        if (odznakaRzadkosc) {
            odznakaRzadkosc.textContent = RZADKOSC[t.rarity] || '';
            odznakaRzadkosc.className = 'rarity-chip is-rarity-' + String(t.rarity || '').toLowerCase();
        }
        if (odznakaMeta) {
            var ile = t.claims || 0;
            var slowo = ile === 1 ? 'znalezienie' : (ile % 10 >= 2 && ile % 10 <= 4 && (ile % 100 < 12 || ile % 100 > 14) ? 'znalezienia' : 'znalezień');
            odznakaMeta.textContent = (t.code || '') + ' · ' + ile + ' ' + slowo;
        }
    }

    function ustawWspolrzedne(lat, lon, skad) {
        pola.lat.value = lat.toFixed(6);
        pola.lon.value = lon.toFixed(6);
        podpis.textContent = lat.toFixed(5) + ', ' + lon.toFixed(5) + ' · ' + skad;
    }

    /** Nowy punkt — formularz czyszczony do wartości domyślnych. */
    function nowy(lat, lon, skad) {
        wybrany = null;
        pola.id.value = '0';
        tytul.textContent = 'Nowy skarb';
        pola.name.value = '';
        pola.desc.value = '';
        pola.cat.value = '';
        pola.status.value = 'ACTIVE';
        pola.rarity.value = 'COMMON';
        pola.origin.value = 'OFFICIAL';
        pola.points.value = '<?= (int) $defaults['points'] ?>';
        pola.radius.value = '<?= (int) $defaults['radius'] ?>';
        pola.reveal.value = '2';
        pola.hint.value = '';
        pola.visible.checked = true;
        odswiezOdznaki(null);
        pokazFormularz(true);
        przyciskUsun.hidden = true;
        notkaUsun.hidden = true;
        if (lat !== undefined) {
            pinezkaWybranego(lat, lon);
            ustawWspolrzedne(lat, lon, skad || 'z mapy');
        }
        hint.textContent = 'Miejsce wskazane. Uzupełnij nazwę i zapisz.';
    }

    /** Edycja istniejącego punktu — dane z tej samej odpowiedzi, co pinezki. */
    function wybierz(id) {
        var t = dane[id];
        if (!t) { return; }
        wybrany = id;
        pola.id.value = String(id);
        tytul.textContent = 'Edycja: ' + t.name;
        pola.name.value = t.name;
        pola.desc.value = t.description || '';
        pola.cat.value = t.category ? String(t.category) : '';
        pola.status.value = t.status;
        pola.rarity.value = t.rarity;
        pola.origin.value = t.origin;
        pola.points.value = String(t.points);
        pola.radius.value = String(t.radius);
        pola.reveal.value = String(t.reveal);
        pola.hint.value = t.hint || '';
        pola.visible.checked = !!t.isActive;
        odswiezOdznaki(t);
        pokazFormularz(true);
        ustawWspolrzedne(t.lat, t.lon, 'zapisane' + (t.region ? ' · ' + t.region : ''));
        pinezkaWybranego(t.lat, t.lon);
        if (znaczniki[id]) { warstwa.removeLayer(znaczniki[id]); delete znaczniki[id]; }

        stanKasowania(t);
        hint.textContent = 'Przeciągnij pinezkę, żeby poprawić miejsce.';
    }

    /**
     * KASOWANIE TYLKO NIEZNALEZIONEGO — ten sam warunek co na serwerze
     * (Models\Treasure::deleteIfUnfound), pokazany ZANIM ktoś kliknie. Serwer
     * i tak odmówi, ale przycisk, który obiecuje operację niemożliwą, uczy
     * nieufności do całego panelu.
     */
    function stanKasowania(t) {
        formUsun.action = formUsun.dataset.base + '/' + t.id + '/usun';
        przyciskUsun.hidden = false;
        przyciskUsun.disabled = t.claims > 0;
        notkaUsun.hidden = !(t.claims > 0);
        if (t.claims > 0) {
            notkaUsun.textContent = 'Znaleziony ' + t.claims + '× — nie da się skasować, bo zapłacił punktami. '
                + 'Ustaw status „Wycofany", żeby zszedł z mapy.';
        }
    }

    function pokazFormularz(on) {
        polaBox.hidden = !on;
        puste.hidden = on;
        if (on) { pola.name.setAttribute('required', 'required'); }
    }

    /**
     * Zaznaczenie karty ujawnienia + widoczność pola „Trop".
     *
     * Trop przy poziomie „Jawny" nie ma odbiorcy (pinezka i tak jest widoczna),
     * więc pole się chowa zamiast stać pustym pytaniem. Wartość ZOSTAJE
     * w polu — przełączenie z powrotem na „Trop" oddaje to, co było wpisane.
     */
    function odswiezUjawnienie() {
        var karty = document.querySelectorAll('#trReveal .tr-reveal__o');
        karty.forEach(function (k) {
            var i = k.querySelector('input');
            k.classList.toggle('is-on', !!(i && i.checked));
        });
        var box = document.getElementById('trHintBox');
        if (box) { box.hidden = pola.reveal.value === '2'; }
    }
    document.querySelectorAll('#trReveal input[name="reveal_level"]').forEach(function (i) {
        i.addEventListener('change', odswiezUjawnienie);
    });

    map.on('click', function (e) { nowy(e.latlng.lat, e.latlng.lng, 'z mapy'); });
    map.on('moveend zoomend', function () {
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(wczytaj, 250);
    });

    document.getElementById('trNew').addEventListener('click', function () {
        nowy();
        podpis.textContent = '';
        hint.textContent = 'Kliknij w mapę, żeby wskazać miejsce.';
        pokazFormularz(true);
    });

    przyciskUsun.addEventListener('click', function (e) {
        if (!confirm('Skasować ten skarb? Tej operacji nie da się cofnąć.')) { e.preventDefault(); }
    });

    // LOKALIZACJA Z TELEFONU — druga droga wskazania miejsca. Bez wysokiej
    // dokładności GPS potrafi oddać pozycję z masztu, czyli z błędem liczonym
    // w kilometrach; przy stawianiu skarbu to przesądza o tym, czy ktokolwiek
    // go potem znajdzie.
    document.getElementById('trHere').addEventListener('click', function () {
        hint.textContent = 'Ustalam pozycję…';
        // Most (assets/js/native.js) — w aplikacji natywny GPS, poza nią
        // przeglądarka. Dokładność zostaje w komunikacie: przy stawianiu skarbu
        // to jedyna informacja, po której widać, czy można temu punktowi ufać.
        RM.native.position().then(function (poz) {
            map.setView([poz.lat, poz.lon], 17);
            nowy(poz.lat, poz.lon, 'z GPS ±' + Math.round(poz.accuracy) + ' m');
        }).catch(function () {
            hint.textContent = 'Nie udało się pobrać lokalizacji — wskaż miejsce klikając w mapę.';
        });
    });

    // SZUKANIE MIEJSCA — przesuwa kadr, NIE stawia pinezki. Wynik geokodera
    // wskazuje miejscowość albo szczyt z dokładnością do setek metrów, a skarb
    // ma stać dokładnie tam, gdzie wisi naklejka. Wyszukiwarka dowozi na miejsce,
    // pinezkę stawia człowiek.
    // NAZWY WŁASNE, nie `pole`/`timer` — `var` ma zasięg FUNKCJI, nie bloku,
    // więc druga deklaracja tej samej nazwy w tym samym IIFE nadpisała pierwszą.
    // Skutek był kiedyś cichy i mylący: skrypt wykonywał się do końca, a klik
    // w mapę przestawał robić cokolwiek.
    var poleSzukania = document.getElementById('trFind');
    var lista = document.getElementById('trFound');
    var token = 0;
    var timerSzukania = null;

    poleSzukania.addEventListener('input', function () {
        if (timerSzukania) { clearTimeout(timerSzukania); }
        var q = poleSzukania.value.trim();
        if (q.length < 3) { lista.hidden = true; return; }
        // Debounce, bo Nominatim ma limit zapytań i wypisanie w nim frazy
        // literka po literce potrafi skończyć się chwilową blokadą.
        timerSzukania = setTimeout(function () { szukaj(q); }, 400);
    });

    function szukaj(q) {
        var moj = ++token;
        fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=6&countrycodes=pl&q='
              + encodeURIComponent(q), { headers: { 'Accept-Language': 'pl' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .catch(function () { return []; })
            .then(function (data) {
                if (moj !== token) { return; }
                lista.innerHTML = '';
                if (!data.length) { lista.hidden = true; return; }
                data.forEach(function (r) {
                    var li = document.createElement('li');
                    li.textContent = r.display_name;
                    li.addEventListener('click', function () {
                        map.setView([parseFloat(r.lat), parseFloat(r.lon)], 15);
                        lista.hidden = true;
                        poleSzukania.value = '';
                        hint.textContent = 'Jesteś na miejscu — kliknij w mapę, żeby postawić skarb.';
                    });
                    lista.appendChild(li);
                });
                lista.hidden = false;
            });
    }

    // Wejście z ?skarb=ID (link „Edytuj" z listy) centruje mapę na punkcie
    // i otwiera go w panelu — po doczytaniu kadru, bo dane pinezek przychodzą
    // z serwera, a nie z HTML-a.
    <?php if ($editing): ?>
    var startId = <?= (int) $editing['id'] ?>;
    dane[startId] = <?= json_encode([
        'id' => (int) $editing['id'],
        'name' => (string) $editing['name'],
        'description' => (string) ($editing['description'] ?? ''),
        'hint' => (string) ($editing['hint'] ?? ''),
        'lat' => (float) $editing['lat'],
        'lon' => (float) $editing['lon'],
        'points' => (int) $editing['points'],
        'radius' => (int) $editing['claim_radius_m'],
        'rarity' => (string) $editing['rarity'],
        'origin' => (string) $editing['origin'],
        'status' => (string) $editing['status'],
        'reveal' => (int) $editing['reveal_level'],
        'category' => (int) ($editing['category_item_id'] ?? 0),
        'isActive' => (int) $editing['is_active'],
        'claims' => (int) ($editing['claims'] ?? 0),
        'region' => (string) ($editing['region_label'] ?? ''),
        // KOD NAKLEJKI (2026-09-12). Ten obiekt MA ODPOWIADAĆ wierszowi
        // z `/admin/skarby/punkty` — `wybierz()` czyta z niego to samo, co
        // z odpowiedzi mapy. Brakującego `code` nie było widać, dopóki panel
        // go nie pokazywał; od kiedy pokazuje, `wybierz(startId)` nadpisywał
        // poprawnie wyrenderowany przez serwer kod pustym stringiem.
        'code' => (string) ($editing['code'] ?? ''),
    ], JSON_UNESCAPED_UNICODE) ?>;
    map.setView([<?= (float) $editing['lat'] ?>, <?= (float) $editing['lon'] ?>], 16);
    wybierz(startId);
    <?php endif; ?>

    wczytaj();
})();
</script>
