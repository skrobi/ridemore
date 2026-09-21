<?php
// Lista wariantów trasy (pętle) — używana w kroku TRASA, gdy variantsEnabled.
// Każdy wariant: nazwa + własny GPX (reużywa gpx-surface.php z prefiksem
// 'variants') + dystans/przewyższenie + własna cena/zaliczka + własny limit
// miejsc. Parsowane przez Resources\EventFormInput ($post['variants']),
// zapisywane przez Models\EventRouteVariant::replaceForEvent.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<div class="k-fields">
    <template x-for="(variant, i) in variants" :key="i">
        <div class="k-day" :style="'--dc:' + DAY_COLORS[i % DAY_COLORS.length]">
            <div class="k-day__h">
                <span class="k-day__n" x-text="__('Wariant {n}', {n: i+1})"></span>
                <button type="button" class="k-day__x" x-show="variants.length>1" @click="removeVariant(i)" aria-label="<?= htmlspecialchars(__('Usuń wariant')) ?>">×</button>
            </div>
            <div class="k-fields">
                <label class="k-fl"><span><?= __('Nazwa wariantu') ?></span>
                    <input type="text" :name="'variants['+i+'][name]'" x-model="variant.name" placeholder="<?= htmlspecialchars(__('np. Pętla 300 km')) ?>">
                </label>

                <label class="k-big" :class="(variant.gpxToken || variant.gpxUrl) && 'done'">
                    <?= Utils\Icon::render('upload') ?>
                    <span x-show="!variantGpxUploading[i]">
                        <span x-text="(variant.gpxToken || variant.gpxUrl) ? __('trasa.gpx — wczytano') : 'Wgraj GPX tego wariantu'"></span>
                        <small x-show="!(variant.gpxToken || variant.gpxUrl)"><?= __('albo wpisz dystans niżej') ?></small>
                    </span>
                    <span x-show="variantGpxUploading[i]"><?= __('Analizuję trasę i nawierzchnię… (do 30 s)') ?></span>
                    <input type="file" accept=".gpx" style="display:none;" @change="uploadVariantGpx(i, $event)">
                </label>
                <input type="hidden" :name="'variants['+i+'][gpx_token]'" x-model="variant.gpxToken">
                <input type="hidden" :name="'variants['+i+'][existing_gpx_url]'" :value="variant.gpxUrl || ''">

                <div class="k-two">
                    <label class="k-fl"><span><?= __('Dystans (km)') ?></span><input type="number" step="0.1" :name="'variants['+i+'][distance_km]'" x-model="variant.distanceKm" placeholder="300"></label>
                    <label class="k-fl"><span><?= __('Przewyższenie (m)') ?> <small><?= __('opcjonalnie') ?></small></span><input type="number" :name="'variants['+i+'][elevation_m]'" x-model="variant.elevationM" placeholder="4200"></label>
                </div>

                <?php $sRef = 'variant'; $sIdx = 'i'; $sPrefix = 'variants'; $sLegend = false; require __DIR__ . '/gpx-surface.php'; ?>

                <label class="k-fl"><span><?= __('Limit miejsc') ?> <small><?= __('na ten wariant, opcjonalnie') ?></small></span><input type="number" :name="'variants['+i+'][max_participants]'" x-model="variant.maxParticipants" min="1" placeholder="<?= htmlspecialchars(__('np. 50')) ?>"></label>
                <p class="k-note" x-show="isPaid" x-cloak><?= __('Cenę tego wariantu ustawisz w kroku „Wpisowe".') ?></p>
            </div>
        </div>
    </template>
    <button type="button" class="k-addr" @click="addVariant()"><?= __('+ Dodaj wariant') ?></button>
</div>
