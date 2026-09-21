<?php
// Wspólny blok „nawierzchnia" dla kroków TRASA (jednodniowa, stages[0]), PLAN
// (wielodniowa, stage w pętli x-for) ORAZ wariantów trasy (variant w pętli).
// Wcześniej skopiowany 1:1 w wielu miejscach. Parametry ustawiane przez
// wołający przed require:
//   $sRef    — wyrażenie JS wskazujące etap/wariant ('stages[0]', 'stage', 'variant')
//   $sIdx    — wyrażenie JS z indeksem do :name ('0', 'i')
//   $sPrefix — prefiks nazwy pola POST ('stages' albo 'variants'); domyślnie 'stages'
//   $sLegend — bool: czy pokazać legendę % (TRASA tak, PLAN/wariant nie — jak dotąd)
// :name (nie statyczne name) — bo indeks bywa dynamiczny (pętla); dla stages[0]
// to i tak ten sam wynik po zamontowaniu Alpine.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
$sLegend = $sLegend ?? false;
$sPrefix = $sPrefix ?? 'stages';
?>
<input type="hidden" :name="'<?= $sPrefix ?>['+(<?= $sIdx ?>)+'][surface_asphalt_pct]'" :value="<?= $sRef ?>.surfaceAsphaltPct">
<input type="hidden" :name="'<?= $sPrefix ?>['+(<?= $sIdx ?>)+'][surface_gravel_pct]'" :value="<?= $sRef ?>.surfaceGravelPct">
<input type="hidden" :name="'<?= $sPrefix ?>['+(<?= $sIdx ?>)+'][surface_trail_pct]'" :value="<?= $sRef ?>.surfaceTrailPct">
<template x-if="<?= $sRef ?>.surfaceAsphaltPct !== null">
    <div class="k-fl"><span><?= __('Nawierzchnia') ?> <small><?= __('wykryta z trasy') ?></small></span>
        <div class="surface-breakdown">
            <div class="surface-bar">
                <span class="surface-seg surface-asphalt" :style="'width:' + <?= $sRef ?>.surfaceAsphaltPct + '%'"></span>
                <span class="surface-seg surface-gravel" :style="'width:' + <?= $sRef ?>.surfaceGravelPct + '%'"></span>
                <span class="surface-seg surface-trail" :style="'width:' + <?= $sRef ?>.surfaceTrailPct + '%'"></span>
            </div>
            <?php if ($sLegend): ?>
            <div class="surface-legend">
                <span class="surface-legend-item"><i class="surface-dot surface-asphalt"></i><?= __('Asfalt') ?> <b x-text="<?= $sRef ?>.surfaceAsphaltPct + '%'"></b></span>
                <span class="surface-legend-item"><i class="surface-dot surface-gravel"></i><?= __('Gravel/szuter') ?> <b x-text="<?= $sRef ?>.surfaceGravelPct + '%'"></b></span>
                <span class="surface-legend-item"><i class="surface-dot surface-trail"></i><?= __('Ścieżka') ?> <b x-text="<?= $sRef ?>.surfaceTrailPct + '%'"></b></span>
            </div>
            <?php endif; ?>
            <button type="button" class="link-button" @click="<?= $sRef ?>.surfaceAsphaltPct = null; <?= $sRef ?>.surfaceGravelPct = null; <?= $sRef ?>.surfaceTrailPct = null;"><?= __('Wpisz ręcznie zamiast wykrytego podziału') ?></button>
        </div>
    </div>
</template>
<label class="k-fl" x-show="<?= $sRef ?>.surfaceAsphaltPct === null"><span><?= __('Nawierzchnia') ?></span>
    <select :name="'<?= $sPrefix ?>['+(<?= $sIdx ?>)+'][surface]'" x-model="<?= $sRef ?>.surface">
        <?php foreach ($dictOptions['surfaces'] as $opt): ?>
        <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
        <?php endforeach; ?>
    </select>
</label>
