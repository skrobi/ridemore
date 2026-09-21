<?php /* Feed Panel */ ?>
<div class="left-panel" :class="{ collapsed: activePanel !== 'feed' }">

    <!-- HEADER -->
    <div style="padding: 24px 24px 16px; border-bottom: 1px solid var(--color-border-light);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
            <h1 style="font-size: 20px; font-weight: 300; letter-spacing: 0.02em; margin: 0; color: var(--color-text); display: flex; align-items: center; gap: 12px;">
                <img src="<?= asset('icons/rss.svg') ?>" alt="" class="icon-svg">
                    RIDEMORE LIVE
            </h1>
            <button class="close-btn" @click="closeAll()" style="background: transparent; border: none; cursor: pointer; padding: 8px; color: #054b5d; transition: color 0.2s;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div style="height: 4px; width: 60px; background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 100%); border-radius: 2px; opacity: 0.6;"></div>
    </div>

    <!-- FILTERS -->
    <div style="padding: 0 24px 16px;">
        <div style="display: flex; gap: 8px;">
            <button 
                class="btn btn-sm"
                :class="feedFilter === 'global' ? 'btn-primary' : 'btn-outline'"
                @click="switchFeedFilter('global')"
                style="font-size: 12px;">
                <i class="fas fa-globe" style="margin-right: 6px;"></i>
                Globalne
            </button>
            <button 
                class="btn btn-sm"
                :class="feedFilter === 'user' ? 'btn-primary' : 'btn-outline'"
                @click="switchFeedFilter('user')"
                style="font-size: 12px;">
                <i class="fas fa-user" style="margin-right: 6px;"></i>
                Moje
            </button>
        </div>
    </div>

    <!-- LOADING -->
    <div x-show="loadingFeed" x-cloak style="text-align: center; padding: 40px 0;">
        <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-text-light); display: inline-block;">
            <line x1="12" y1="2" x2="12" y2="6"/>
            <line x1="12" y1="18" x2="12" y2="22"/>
        </svg>
    </div>

    <!-- FEED EVENTS -->
    <div x-show="!loadingFeed" style="padding: 0 24px 24px;" x-cloak>

        <template x-for="event in feedEvents" :key="event.feed_id">
            <div style="margin-bottom: 16px; padding: 16px; background: white; border-radius: 12px; border: 1px solid var(--color-border-light);">

                <div style="display: flex; align-items: center; gap: 12px;">

                    <!-- ✅ SVG ICON BOX -->
                    <div class="feed-icon-box" :style="`background: ${event.color};`">
                        <img :src="event.icon_url" 
                             alt="" 
                             style="width: 20px; height: 20px; filter: brightness(0) invert(1);">
                    </div>

                    <!-- Content -->
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-size: 13px; font-weight: 500; color: var(--color-text);" x-html="event.message"></div>
                        <div style="font-size: 11px; color: var(--color-text-muted); margin-top: 4px;" x-text="event.time_ago"></div>
                    </div>

                </div>


            </div>
        </template>

        <!-- EMPTY STATE -->
        <div x-show="feedEvents.length === 0" class="empty-state" x-cloak>
            <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
            </svg>
            <div class="empty-state-title">Brak aktywności</div>
            <p style="font-size: 13px; color: var(--color-text-light); margin-top: 8px;">
                Zacznij jeździć i zdobywać medale!
            </p>
        </div>
    </div>

</div>