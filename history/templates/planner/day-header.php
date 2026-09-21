<div class="day-header" @click="toggleDay(day.num)">
    <div class="day-info">
        <span class="day-icon">
            <img src="<?= asset('icons/chevron-right.svg') ?>" alt="" 
                 :style="!isDayCollapsed(day.num) ? 'transform: rotate(90deg)' : ''"
                 style="width: 12px; height: 12px; transition: transform 0.2s;">
        </span>
        <span class="day-title">DZIEŃ <span x-text="day.num"></span></span>
    </div>
    <div class="day-stats">
        <span>
            <img src="<?= asset('icons/navigation.svg') ?>" alt="" style="width: 12px; height: 12px; opacity: 0.6;">
            <span x-text="(day.stats.distance / 1000).toFixed(1)"></span> km
        </span>
        <span>
            <img src="<?= asset('icons/clock.svg') ?>" alt="" style="width: 12px; height: 12px; opacity: 0.6;">
            <span x-text="formatDuration(day.stats.duration)"></span>
        </span>
        <span>
            <img src="<?= asset('icons/map-pin.svg') ?>" alt="" style="width: 12px; height: 12px; opacity: 0.6;">
            <span x-text="day.waypoints.length"></span> pkt
        </span>
    </div>
</div>