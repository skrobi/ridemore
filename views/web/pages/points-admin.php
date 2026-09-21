<?php
// views/web/pages/points-admin.php
// PANEL PUNKTÓW I TRAS — /admin/punkty (Etap 8A/15).
//
// Ekran świadomie NIE edytuje globalnej konfiguracji punktacji: te wartości
// mieszkają w core/discovery.php, bo są regułami gry i mają przechodzić przez
// wdrożenie, a nie przez formularz. Tutaj są WIDOCZNE (z przykładami liczonymi
// tym samym kodem, który nalicza naprawdę), a edytowalne jest to, co jest
// DANYMI: bonusy per trasa i per wydarzenie.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Models\PointLedger;
use Utils\Format;
use Utils\View;

require __DIR__ . '/../partials/breadcrumbs.php';

$routes = $routes ?? [];
$hasAnyRoutes = $hasAnyRoutes ?? true;
$routeSearch = $routeSearch ?? ['q' => '', 'searching' => false, 'results' => []];
$events = $events ?? [];
// Lista emblematów do selectów przy wydarzeniach (migr. 087). Pusta, dopóki
// nikt żadnego nie założył — select pokazuje wtedy samo „— brak —".
$emblems = $emblems ?? [];
$eventSearch = $eventSearch ?? ['q' => '', 'searching' => false, 'results' => []];
$recent = $recent ?? [];
$totals = $totals ?? [];
$num = static fn($n) => number_format((int) $n, 0, ',', ' ');
?>
<h1 class="display">Punkty i trasy</h1>
<p class="desc spaced-below">
    Wartości globalne są w pliku <code>core/discovery.php</code> — zmiana reguł gry przechodzi
    przez wdrożenie, nie przez formularz. Tutaj widzisz, co z nich wynika, i ustawiasz to,
    co jest danymi: bonusy przy konkretnych trasach i wydarzeniach.
</p>

<?php if ($info): ?>
<p class="form-success"><?= htmlspecialchars($info) ?></p>
<?php endif; ?>

<div class="trust-bar">
    <div class="trust-item">
        <div class="trust-item-label">Naliczeń w rejestrze</div>
        <div class="trust-item-value"><?= $num($totals['transactions'] ?? 0) ?></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Punktów łącznie</div>
        <div class="trust-item-value"><?= $num($totals['points'] ?? 0) ?></div>
    </div>
    <div class="trust-item">
        <div class="trust-item-label">Rowerzystów z punktami</div>
        <div class="trust-item-value"><?= $num($totals['riders'] ?? 0) ?></div>
    </div>
</div>

<?php // STAWKI GLOBALNE — edytowalne od migr. 053.
      //
      // Do tej pory punktacja żyła wyłącznie w core/discovery.php, czyli dało
      // się ją zmienić tylko wdrożeniem. To było w porządku, dopóki wartości
      // ustalało się raz przy projektowaniu gry; przestało być, gdy trzeba je
      // kalibrować na żywym ruchu.
      //
      // Puste pole = powrót do wartości domyślnej z pliku. Dlatego przy każdej
      // stawce widać, ile wynosi domyślna — inaczej „wyczyść i zapisz" byłoby
      // skokiem w ciemno. ?>
<section class="sec">
    <div class="sec-head">
        <h2>Stawki punktowe</h2>
        <span><?php if (!empty($lastChange)): ?>ostatnia zmiana:
            <?= htmlspecialchars(substr((string) $lastChange['updated_at'], 0, 16)) ?>
            <?= $lastChange['who'] ? ' · ' . htmlspecialchars($lastChange['who']) : '' ?>
        <?php else: ?>wszystko na wartościach domyślnych<?php endif; ?></span>
    </div>
    <div class="box">
        <p class="desc" style="margin-top:0;">Zmiana działa <b>na przyszłość</b>. Rejestr punktów
            jest niezmienny — to, co już naliczone, zostaje takie, jakie było w chwili przejazdu.</p>
        <form method="post" action="<?= View::url('/admin/punkty/stawki') ?>">
            <?= Core\Csrf::field() ?>
            <div class="rate-grid">
                <?php foreach (($rates ?? []) as $r): ?>
                <div class="rate<?= $r['overridden'] ? ' is-set' : '' ?>">
                    <label for="<?= htmlspecialchars($r['field']) ?>">
                        <?= htmlspecialchars($r['label']) ?>
                        <?php if ($r['overridden']): ?><i>zmienione</i><?php endif; ?>
                    </label>
                    <div class="rate__in">
                        <input type="text" inputmode="decimal" id="<?= htmlspecialchars($r['field']) ?>"
                               name="<?= htmlspecialchars($r['field']) ?>"
                               value="<?= htmlspecialchars((string) $r['value']) ?>"
                               placeholder="<?= htmlspecialchars((string) $r['default']) ?>">
                        <span><?= htmlspecialchars($r['unit']) ?></span>
                    </div>
                    <p class="rate__hint">
                        <?php if ($r['hint']): ?><?= htmlspecialchars($r['hint']) ?><br><?php endif; ?>
                        Domyślnie: <b><?= htmlspecialchars((string) $r['default']) ?></b> — wyczyść pole, żeby wrócić.
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="op-head__act"><button class="btn" type="submit">Zapisz stawki</button></div>
        </form>
    </div>
</section>

<section class="sec">
    <div class="sec-head">
        <h2>Znana trasa — punktacja</h2>
        <span><a href="<?= View::url('/admin/znane-trasy') ?>">Zarządzaj trasami →</a></span>
    </div>
    <p class="desc" style="margin-top:0;">Trasa jest warta JEDNĄ liczbę punktów łącznie — progi (niżej: na ile
        się dzieli) rozdzielają ją między siebie <b>równo</b>. Wartość rośnie z długością <b>automatycznie</b>;
        ręcznie ustawiasz ją tylko dla trasy, która ma być wyjątkiem od tej reguły.</p>

    <div class="box" style="margin-bottom:16px;">
        <h3 style="margin:0 0 14px;font-family:var(--f-m);font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute);">
            Ustawienia ogólne</h3>
        <form method="post" action="<?= View::url('/admin/punkty/stawki') ?>">
            <?= Csrf::field() ?>
            <div class="rate-grid">
                <?php foreach (($trailRates ?? []) as $r): ?>
                <div class="rate<?= $r['overridden'] ? ' is-set' : '' ?>">
                    <label for="<?= htmlspecialchars($r['field']) ?>">
                        <?= htmlspecialchars($r['label']) ?>
                        <?php if ($r['overridden']): ?><i>zmienione</i><?php endif; ?>
                    </label>
                    <div class="rate__in">
                        <input type="text" inputmode="decimal" id="<?= htmlspecialchars($r['field']) ?>"
                               name="<?= htmlspecialchars($r['field']) ?>"
                               value="<?= htmlspecialchars((string) $r['value']) ?>"
                               placeholder="<?= htmlspecialchars((string) $r['default']) ?>">
                        <span><?= htmlspecialchars($r['unit']) ?></span>
                    </div>
                    <p class="rate__hint">
                        <?php if ($r['hint']): ?><?= htmlspecialchars($r['hint']) ?><br><?php endif; ?>
                        Domyślnie: <b><?= htmlspecialchars((string) $r['default']) ?></b> — wyczyść pole, żeby wrócić.
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="op-head__act"><button class="btn" type="submit">Zapisz stawki</button></div>
        </form>
    </div>

    <?php // DODAWANIE PRZEZ WYSZUKIWARKĘ (2026-09-03, druga uwaga usera):
          // reszta ekranu ma ukazywać WYJĄTKI, więc dodanie trasy do listy
          // ręcznej to osobny, świadomy krok — nie odznaczenie promptu przy
          // każdej trasie z osobna. ?>
    <div class="op-head__act" style="margin:0 0 16px;">
        <button type="button" id="routeSearchToggle" class="btn btn-secondary">Ustaw wartości ręcznie</button>
    </div>

    <div id="routeSearchPanel" <?= $routeSearch['searching'] ? '' : 'hidden' ?> style="margin-bottom:16px;">
        <form class="box" method="get" action="<?= View::url('/admin/punkty') ?>"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input class="search-input" type="search" name="q" value="<?= htmlspecialchars($routeSearch['q']) ?>"
                   placeholder="Nazwa, adres albo region trasy" style="flex:1 1 240px;" autofocus>
            <button type="submit" class="btn btn-secondary">Szukaj</button>
        </form>

        <?php if ($routeSearch['searching']): ?>
        <div class="box" style="margin-top:10px;">
            <?php if ($routeSearch['q'] === ''): ?>
            <p class="desc" style="margin:0;">Wpisz nazwę, adres albo region trasy.</p>
            <?php elseif (!$routeSearch['results']): ?>
            <p class="desc" style="margin:0;">Nic nie pasuje — albo trasa jest już na liście niżej.</p>
            <?php else: ?>
            <div class="dash-table">
                <?php foreach ($routeSearch['results'] as $r): ?>
                <div class="dash-row">
                    <div class="dash-cell dash-cell-title" data-label="Trasa">
                        <?= htmlspecialchars($r['name']) ?>
                        <div class="dash-sub"><?= (int) $r['cells_total'] ?> pól<?php
                            if ((float) $r['distance_km'] > 0): ?> · <?= htmlspecialchars(Format::distance((float) $r['distance_km'])) ?><?php endif; ?>
                            · automatycznie <?= $num($r['suggestedTotal']) ?> pkt</div>
                    </div>
                    <div class="dash-cell" data-label="Dodaj">
                        <form method="post" action="<?= View::url('/admin/punkty/trasa/' . (int) $r['id']) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="value_mode" value="manual">
                            <?php // Startuje od dzisiejszej wartości automatycznej — „dodaj" nie
                                  // ma prawa po cichu zmienić, ile trasa jest warta, tylko
                                  // przenieść ją na listę, gdzie da się to zmienić świadomie. ?>
                            <input type="hidden" name="bonus_points" value="<?= (int) $r['suggestedTotal'] ?>">
                            <?php if (!empty($r['bonus_enabled'])): ?>
                            <input type="hidden" name="bonus_enabled" value="1">
                            <?php endif; ?>
                            <button class="btn btn--sm" type="submit">Dodaj</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$routes): ?>
    <p class="desc">
        <?= $hasAnyRoutes
            ? 'Wszystkie trasy mają wartość automatyczną — nic tu nie trzeba ustawiać ręcznie.'
            : 'Nie ma jeszcze żadnej znanej trasy.' ?>
    </p>
    <?php else: ?>
    <div style="display:grid;gap:14px;">
        <?php foreach ($routes as $r): ?>
        <div class="box">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div>
                    <b style="font-size:16px;"><?= htmlspecialchars($r['name']) ?></b>
                    <div class="dash-sub"><?= (int) $r['cells_total'] ?> pól<?php
                        if ((float) $r['distance_km'] > 0): ?> · <?= htmlspecialchars(Format::distance((float) $r['distance_km'])) ?><?php endif; ?>
                        · automatycznie byłoby <?= $num($r['suggestedTotal']) ?> pkt</div>
                </div>
                <form method="post" action="<?= View::url('/admin/punkty/trasa/' . (int) $r['id']) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="value_mode" value="auto">
                    <button class="btn btn--sm btn-secondary" type="submit">Wróć do automatycznej</button>
                </form>
            </div>

            <form method="post" action="<?= View::url('/admin/punkty/trasa/' . (int) $r['id']) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="value_mode" value="manual">
                <div class="form-row" style="align-items:flex-end;">
                    <div class="form-field" style="max-width:220px;">
                        <label for="route<?= (int) $r['id'] ?>-bp">Wartość trasy łącznie</label>
                        <input id="route<?= (int) $r['id'] ?>-bp" class="search-input" type="number" min="0"
                               name="bonus_points" value="<?= (int) $r['total'] ?>">
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;white-space:nowrap;padding-bottom:11px;">
                        <input type="checkbox" name="bonus_enabled" value="1" <?= !empty($r['bonus_enabled']) ? 'checked' : '' ?>>
                        Bonus włączony
                    </label>
                    <button class="btn btn--sm" type="submit">Zapisz</button>
                </div>
                <p class="hint">Progi (patrz „Co to daje" niżej) dzielą tę wartość między siebie równo.</p>
            </form>

            <?php // Podgląd liczony DOKŁADNIE tą samą metodą, która nalicza
                  // naprawdę (DiscoveryScoring::trailAwards) — więc to, co
                  // widać tutaj, jest tym, co dostanie rowerzysta, nie
                  // przybliżeniem. ?>
            <p class="desc" style="margin:14px 0 0;">
                <b>Co to daje: </b>
                <?php $i = 0; foreach ($r['breakdown'] as $pct => $pts): ?>
                <?= $i++ > 0 ? ' · ' : '' ?><?= (int) $pct ?>%<?= $pct >= 100 ? ' (ukończenie)' : '' ?> → +<?= $num($pts) ?>
                <?php endforeach; ?>
                <?php if ($r['breakdown']): ?> · <b>razem <?= $num($r['total']) ?> pkt</b><?php endif; ?>
                <?php if (!$r['breakdown']): ?>Bonus wyłączony — trasa nie płaci nic.<?php endif; ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<script>
(function () {
    // Wspólna dla wyszukiwarki tras i wydarzeń — ten sam wzorzec „przycisk
    // odsłania panel", więc jedna funkcja zamiast dwóch prawie identycznych.
    function wireSearchToggle(toggleId, panelId) {
        var toggle = document.getElementById(toggleId);
        var panel = document.getElementById(panelId);
        if (!toggle || !panel) { return; }
        toggle.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            if (!panel.hidden) {
                var input = panel.querySelector('input[type="search"]');
                if (input) { input.focus(); }
            }
        });
    }
    wireSearchToggle('routeSearchToggle', 'routeSearchPanel');
    wireSearchToggle('eventSearchToggle', 'eventSearchPanel');
})();
</script>

<section class="sec">
    <div class="sec-head">
        <h2>Bonus za udział w wydarzeniu</h2>
        <span>puste pole = usuwa bonus</span>
    </div>
    <p class="desc">Model ma <b>umożliwiać</b> nagrodę za udział w konkretnym wyjeździe, a nie
        dawać ją każdemu — dlatego domyślnie nie ma jej nigdzie.</p>

    <?php // TA SAMA ZASADA CO PRZY TRASACH: lista pokazuje TYLKO wydarzenia
          // z już ustawionym bonusem, dodanie kolejnego jest świadomym krokiem
          // przez wyszukiwarkę, nie skrolowaniem całego kalendarza. ?>
    <div class="op-head__act" style="margin:0 0 16px;">
        <button type="button" id="eventSearchToggle" class="btn btn-secondary">Dodaj bonus punktów wydarzenia</button>
    </div>

    <div id="eventSearchPanel" <?= $eventSearch['searching'] ? '' : 'hidden' ?> style="margin-bottom:16px;">
        <form class="box" method="get" action="<?= View::url('/admin/punkty') ?>"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input class="search-input" type="search" name="eq" value="<?= htmlspecialchars($eventSearch['q']) ?>"
                   placeholder="Nazwa wydarzenia" style="flex:1 1 240px;" autofocus>
            <button type="submit" class="btn btn-secondary">Szukaj</button>
        </form>

        <?php if ($eventSearch['searching']): ?>
        <div class="box" style="margin-top:10px;">
            <?php if ($eventSearch['q'] === ''): ?>
            <p class="desc" style="margin:0;">Wpisz nazwę wydarzenia.</p>
            <?php elseif (!$eventSearch['results']): ?>
            <p class="desc" style="margin:0;">Nic nie pasuje — albo wydarzenie jest już na liście niżej.</p>
            <?php else: ?>
            <div class="dash-table">
                <?php foreach ($eventSearch['results'] as $e): ?>
                <div class="dash-row">
                    <div class="dash-cell dash-cell-title" data-label="Wydarzenie">
                        <?= htmlspecialchars($e['title']) ?>
                        <?php if ($e['start_date']): ?>
                        <div class="dash-sub"><?= htmlspecialchars(Format::dateShort($e['start_date']) ?? '') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="dash-cell" data-label="Dodaj">
                        <form method="post" action="<?= View::url('/admin/punkty/wydarzenie/' . (int) $e['id']) ?>">
                            <?= Csrf::field() ?>
                            <?php // Bez automatycznej sugestii (nie ma z czego jej wyprowadzić,
                                  // inaczej niż przy trasach) — dodanie startuje od zera, żeby
                                  // wydarzenie znalazło się na liście niżej i dało się od razu
                                  // wpisać prawdziwą wartość. ?>
                            <input type="hidden" name="point_bonus" value="0">
                            <button class="btn btn--sm" type="submit">Dodaj</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$events): ?>
    <p class="desc">Żadne wydarzenie nie ma jeszcze bonusu.</p>
    <?php else: ?>
    <div class="dash-table">
        <div class="dash-row dash-head">
            <div class="dash-cell">Wydarzenie</div>
            <div class="dash-cell">Bonus</div>
            <div class="dash-cell">Emblemat</div>
            <div class="dash-cell">Zapisz</div>
        </div>
        <?php foreach ($events as $e): ?>
        <form method="post" action="<?= View::url('/admin/punkty/wydarzenie/' . (int) $e['id']) ?>" class="dash-row">
            <?= Csrf::field() ?>
            <div class="dash-cell dash-cell-title" data-label="Wydarzenie">
                <a href="<?= View::url('/events/' . $e['slug']) ?>"><?= htmlspecialchars($e['title']) ?></a>
                <?php if ($e['start_date']): ?>
                <div class="dash-sub"><?= htmlspecialchars(Format::dateShort($e['start_date']) ?? '') ?></div>
                <?php endif; ?>
            </div>
            <div class="dash-cell" data-label="Bonus">
                <input class="search-input" type="number" min="0" name="point_bonus"
                       value="<?= $e['point_bonus'] !== null ? (int) $e['point_bonus'] : '' ?>"
                       placeholder="brak">
            </div>
            <?php // EMBLEMAT ZA CAŁĄ TRASĘ WYDARZENIA (migr. 087, 2026-09-11).
                  // Warunek jest TEN SAM co przy znanej trasie: 100% pól siatki
                  // odkryć. Dla wielodniówki liczy się suma wszystkich etapów,
                  // dla wyścigu z wariantami wystarczy JEDEN wariant w całości
                  // (patrz Models\Emblem::eventRouteCandidates) — inaczej
                  // emblemat dostawałby tylko ktoś, kto przejechał wszystkie
                  // dystanse naraz.
                  //
                  // Wydarzenie BEZ pliku GPX nie ma z czego policzyć pokrycia,
                  // więc emblemat nigdy się na nim nie naliczy. Select zostaje
                  // aktywny (organizator może dograć ślad później), ale warto
                  // o tym wiedzieć, patrząc na pustą kolumnę „zdobyty przez"
                  // w panelu emblematów. ?>
            <div class="dash-cell" data-label="Emblemat">
                <select class="search-input" name="emblem_id">
                    <option value="">— brak —</option>
                    <?php foreach ($emblems as $em): ?>
                    <option value="<?= (int) $em['id'] ?>"
                        <?= (int) ($e['emblem_id'] ?? 0) === (int) $em['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($em['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="dash-cell" data-label="Zapisz">
                <button class="btn btn--sm" type="submit">Zapisz</button>
            </div>
        </form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="sec">
    <div class="sec-head"><h2>Ostatnie naliczenia</h2><span>podgląd kontrolny z rejestru</span></div>
    <?php if (!$recent): ?>
    <p class="desc">Rejestr jest jeszcze pusty.</p>
    <?php else: ?>
    <div class="dash-table">
        <div class="dash-row dash-head">
            <div class="dash-cell">Rowerzysta</div>
            <div class="dash-cell">Za co</div>
            <div class="dash-cell">Punkty</div>
            <div class="dash-cell">Data przejazdu</div>
        </div>
        <?php foreach ($recent as $t): ?>
        <div class="dash-row">
            <div class="dash-cell dash-cell-title" data-label="Rowerzysta"><?= htmlspecialchars($t['user_name'] ?: $t['user_email']) ?></div>
            <div class="dash-cell" data-label="Za co">
                <?= htmlspecialchars(PointLedger::label($t['source'])) ?>
                <div class="dash-sub"><?= htmlspecialchars((string) $t['description']) ?></div>
            </div>
            <div class="dash-cell" data-label="Punkty"><b>+<?= $num($t['points']) ?></b></div>
            <div class="dash-cell" data-label="Data przejazdu"><?= htmlspecialchars($t['ride_date'] ? (Format::dateShort($t['ride_date']) ?? '') : '—') ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
