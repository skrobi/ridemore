<div class="segment-stats" @contextmenu.prevent="showSegmentMenu($event, wp.idx)">
    <span>
        <img src="<?= asset('icons/navigation.svg') ?>" alt="" style="width: 11px; height: 11px; opacity: 0.5;">
        <span x-text="(routeSegments[wp.idx].distance / 1000).toFixed(1)"></span> km
    </span>
    <span>
        <img src="<?= asset('icons/clock.svg') ?>" alt="" style="width: 11px; height: 11px; opacity: 0.5;">
        <span x-text="Math.round(routeSegments[wp.idx].duration / 60)"></span> min
    </span>
</div>