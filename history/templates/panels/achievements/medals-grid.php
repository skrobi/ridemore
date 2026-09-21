<div x-show="!loadingAchievements" style="padding: 0 24px 24px;" x-cloak>
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
        <template x-for="achievement in getFilteredAchievements()" :key="achievement.level_id">
            <div @click="openMedalModal(achievement)" 
                 x-html="MedalRenderer.render(achievement)">
            </div>
        </template>
    </div>
</div>