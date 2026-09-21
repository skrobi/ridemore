<?php
// views/web/pages/planner.php
// ROUTE PLANNER, ETAP 1 — /planer. Szkielet statyczny; czysty model trasy
// dostarcza assets/js/planner/route-model.js, a assets/js/planner.js spina go
// z DOM-em, mapą i API.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<h1 style="margin:0 0 4px;"><?= __('Planer tras') ?></h1>
<p style="margin:0 0 4px;color:var(--ink-soft);font-size:14px;">
    <?= __('Kliknij na mapie, żeby ustawić START, kolejne kliknięcia dodają punkty. Ridemore pokaże znane trasy i skarby po drodze.') ?>
</p>

<div class="planner-layout" id="plannerLayout">

    <div class="planner-map" id="plannerMap">
        <div class="planner-map__canvas" id="plannerMapCanvas"></div>

        <div class="planner-onboarding" id="plannerOnboarding">
            <div class="planner-onboarding-card">
                <strong><?= __('Kliknij na mapie, aby ustawić START') ?></strong>
                <p><?= __('Kolejne kliknięcia dodają punkty pośrednie i cel.') ?></p>
            </div>
        </div>
    </div>

    <div class="planner-sidebar">

        <?php /* ŹRÓDŁA TRASY (2026-09-18): jedno miejsce wyboru — zaznaczenie źródła
                 pokazuje je na mapie I oddaje planerowi jako preferowane odcinki.
                 Konkretna trasa albo GPX to NADPISANIE bazy: status u góry mówi,
                 z czego planer korzysta, lista pod nim — co widać na mapie. */ ?>
        <section class="planner-cfg planner-sources" id="plannerSources" aria-labelledby="plannerSourcesTitle">
            <div class="planner-sources__head" id="plannerSourcesTitle">
                <?= Utils\Icon::render('layers') ?>
                <?= __('Źródła trasy') ?>
            </div>
            <div class="planner-cfg-body">

                <div class="planner-base" id="plannerBase" data-mode="sources" aria-live="polite">
                    <div class="planner-base__label"><?= __('Aktualna baza') ?></div>
                    <div class="planner-base__value">
                        <span class="planner-base__swatch" id="plannerBaseSwatch" hidden></span>
                        <span class="planner-base__file" id="plannerBaseFile" hidden><?= Utils\Icon::render('route') ?></span>
                        <span class="planner-base__texts">
                            <span class="planner-base__name" id="plannerBaseName"></span>
                            <span class="planner-base__meta" id="plannerBaseMeta" hidden></span>
                        </span>
                        <span class="planner-base__count" id="plannerBaseCount" hidden></span>
                        <button type="button" class="planner-base__clear" id="plannerBaseClear" aria-label="<?= htmlspecialchars(__('Usuń bazę')) ?>" hidden><?= Utils\Icon::render('close') ?></button>
                    </div>
                    <div class="planner-base__sub" id="plannerBaseSub"></div>
                    <div class="planner-base__actions">
                        <button type="button" class="planner-base__btn" id="plannerPickBtn" aria-expanded="false" aria-controls="plannerPicker">
                            <?= Utils\Icon::render('route') ?><?= __('Wybierz konkretną trasę') ?>
                        </button>
                        <button type="button" class="planner-base__btn planner-base__btn--gpx" id="plannerGpxBtn">
                            <?= Utils\Icon::render('upload') ?><?= __('Wgraj GPX') ?>
                        </button>
                        <input type="file" id="plannerGpxFile" accept=".gpx" hidden>
                    </div>
                    <div class="planner-picker" id="plannerPicker" hidden>
                        <input type="search" id="plannerPickerQuery" class="planner-picker__query"
                               placeholder="<?= htmlspecialchars(__('Szukaj trasy lub przejazdu…')) ?>"
                               aria-label="<?= htmlspecialchars(__('Szukaj trasy lub przejazdu…')) ?>">
                        <div class="planner-picker__results" id="plannerPickerResults"></div>
                        <div class="planner-picker__foot">
                            <span><?= __('Wybrana trasa zastąpi obecną bazę. Punkty trasy zostają.') ?></span>
                            <button type="button" id="plannerPickerCancel"><?= __('Anuluj') ?></button>
                        </div>
                    </div>
                    <div class="planner-base__error" id="plannerBaseError" hidden></div>
                </div>

                <div class="planner-sources__list">
                    <div class="planner-sources__label" id="plannerListLabel"><?= __('Pokaż na mapie i prowadź po nich') ?></div>
                    <label class="planner-src">
                        <input type="checkbox" id="plannerSrcMine">
                        <span class="planner-src__txt"><b><?= __('Moje przejazdy') ?></b><small><?= __n((int) $myRidesCount, '{n} przejazd', '{n} przejazdy', '{n} przejazdów') ?></small></span>
                        <svg class="planner-src__swatch" viewBox="0 0 36 14" aria-hidden="true"><path d="M2 10C10 2 18 12 34 4" fill="none" stroke="#7D4CA8" stroke-width="3" stroke-linecap="round"/></svg>
                    </label>
                    <label class="planner-src">
                        <input type="checkbox" id="plannerSrcKnown" checked>
                        <span class="planner-src__txt"><b><?= __('Znane trasy') ?></b><small><?= __n((int) $knownRoutesCount, '{n} trasa w katalogu', '{n} trasy w katalogu', '{n} tras w katalogu') ?></small></span>
                        <svg class="planner-src__swatch" viewBox="0 0 36 14" aria-hidden="true"><path d="M3 7H33" fill="none" stroke="#15201A" stroke-opacity=".85" stroke-width="6.5" stroke-linecap="round"/><path d="M3 7H33" fill="none" stroke="#B3382C" stroke-width="4" stroke-linecap="round"/></svg>
                    </label>
                    <label class="planner-src">
                        <input type="checkbox" id="plannerSrcCommunity" checked>
                        <span class="planner-src__txt"><b><?= __('Przejazdy społeczności') ?></b><small><?= __('im częściej, tym mocniej') ?></small></span>
                        <svg class="planner-src__swatch" viewBox="0 0 36 14" aria-hidden="true"><path d="M3 6H33M3 7H33M3 8H33" fill="none" stroke="#D2731A" stroke-opacity=".25" stroke-width="9" stroke-linecap="round"/></svg>
                    </label>
                    <div class="planner-hint" id="plannerPriorityHint" hidden><?= __('Gdy odcinki się pokrywają, wygrywa źródło wyżej na liście.') ?></div>
                </div>

                <div class="planner-sources__list">
                    <div class="planner-sources__label"><?= __('Punkty na mapie') ?></div>
                    <label class="planner-src">
                        <input type="checkbox" id="plannerShowTreasures" checked>
                        <span class="planner-src__txt"><b><?= __('Skarby') ?></b><small><?= __('kliknij, by dodać jako cel lub po drodze') ?></small></span>
                        <svg class="planner-src__swatch" viewBox="0 0 36 14" aria-hidden="true"><circle cx="18" cy="7" r="6.5" fill="none" stroke="#D3D8CE"/><circle cx="18" cy="7" r="5" fill="#F0B41E" stroke="#fff" stroke-width="1.5"/></svg>
                    </label>
                </div>

                <div class="planner-sources__opt">
                    <div class="planner-cfg-label"><?= __('Działanie planera') ?></div>
                    <label class="switch-wrap">
                        <input type="checkbox" class="switch" id="plannerAutoJoin" checked>
                        <span class="switch-label"><?= __('Dołączaj dłuższe odcinki Ridemore') ?></span>
                    </label>
                    <div class="planner-hint" id="plannerAutoJoinHint"></div>
                </div>
            </div>
        </section>

        <details class="planner-cfg" id="plannerCfg">
            <summary>
                <?= Utils\Icon::render('settings') ?>
                <?= __('Konfiguracja trasy') ?>
                <svg class="ic chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
            </summary>
            <div class="planner-cfg-body">
                <div>
                    <div class="planner-cfg-label"><?= __('Profil roweru') ?></div>
                    <div class="planner-profile-row" id="plannerProfileRow">
                        <?php // Typy rowerów ze słownika `bike_type` (panel: Taksonomia → Typ roweru → „Planer"). ?>
                        <?php foreach ($bikeTypes as $i => $bikeType): $speed = (int) round($bikeType['planner']['speedKmh']); ?>
                        <button type="button" class="planner-profile-btn<?= $i === 0 ? ' active' : '' ?>" data-speed="<?= $speed ?>" data-profile="<?= htmlspecialchars($bikeType['code']) ?>"><?= htmlspecialchars($bikeType['name']) ?><span><?= $speed ?> km/h</span></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </details>

        <div class="card" id="plannerWaypointsCard">
            <div class="planner-empty" id="plannerEmpty">
                <?= Utils\Icon::render('route') ?>
                <strong><?= __('Brak punktów trasy') ?></strong>
                <span><?= __('Kliknij na mapie, żeby zacząć.') ?></span>
            </div>
            <div id="plannerWpList" style="display:none;"></div>
        </div>

        <button type="button" class="planner-btn-clear" id="plannerAddHint" style="display:none;border-radius:var(--radius);padding:10px;width:100%;font-size:12.5px;">
            <?= __('Kliknij na mapie, żeby dodać punkt na końcu — albo przeciągnij samą linię, żeby wstawić punkt w środku') ?>
        </button>

        <div class="card" id="plannerStatsCard" style="display:none;padding:6px 0;">
            <div class="planner-stats">
                <div class="planner-stat">
                    <div class="planner-stat-val" id="plannerStatDistance">—</div>
                    <div class="planner-stat-lbl"><?= __('Dystans') ?></div>
                </div>
                <div class="planner-stat">
                    <div class="planner-stat-val" id="plannerStatAscent">—</div>
                    <div class="planner-stat-lbl"><?= __('Wzniesienie') ?></div>
                </div>
                <div class="planner-stat">
                    <div class="planner-stat-val" id="plannerStatTime">—</div>
                    <div class="planner-stat-lbl"><?= __('Czas') ?></div>
                </div>
            </div>
        </div>

        <div style="flex:1;"></div>

        <div class="field">
            <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-soft);margin-bottom:6px;"><?= __('Nazwa trasy') ?></label>
            <input type="text" id="plannerName" placeholder="<?= htmlspecialchars(__('np. Pętla przez Dolinę Bugu')) ?>"
                   style="width:100%;font-size:14px;padding:11px 12px;border-radius:var(--radius);border:1px solid var(--hair-strong);background:var(--paper);color:var(--ink);">
        </div>

        <div class="planner-footer">
            <button type="button" class="planner-btn-clear" id="plannerClear"><?= __('Wyczyść') ?></button>
            <button type="button" class="btn" id="plannerSave" disabled><?= __('Zapisz trasę') ?></button>
        </div>
        <a href="#" class="btn pending" id="plannerGpx" style="display:none;text-align:center;"><?= __('Eksportuj GPX') ?></a>

        <div id="plannerSaveMsg" style="font-size:12.5px;color:var(--accent-dark);display:none;"></div>
    </div>
</div>

<script>
window.PLANNER_CONFIG = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    existingRouteId: <?= json_encode($existingRouteId) ?>,
    // Szablony kafli {z}/{x}/{y} — TA SAMA warstwa „Ślady" co /odkrycia/spolecznosc
    // (Models\TileCache::urlTemplate), Leaflet dociąga je sam przy przesuwaniu mapy.
    trackTiles: <?= json_encode($trackTiles) ?>,
    api: {
        layers: <?= json_encode(Utils\View::url('/api/planer/warstwy')) ?>,
        calculate: <?= json_encode(Utils\View::url('/api/planer/oblicz')) ?>,
        save: <?= json_encode(Utils\View::url('/api/planer/zapisz')) ?>,
        load: <?= json_encode(Utils\View::url('/api/planer/')) ?>,
        gpx: <?= json_encode(Utils\View::url('/planer/')) ?>,
        sourceSearch: <?= json_encode(Utils\View::url('/api/planer/zrodla')) ?>,
        sourceGeometry: <?= json_encode(Utils\View::url('/api/planer/zrodlo')) ?>,
        sourceUpload: <?= json_encode(Utils\View::url('/api/planer/wgraj-gpx')) ?>,
    },
};
</script>
<script defer src="<?= Utils\View::asset('/assets/js/planner/route-model.js') ?>"></script>
<script defer src="<?= Utils\View::asset('/assets/js/planner.js') ?>"></script>
