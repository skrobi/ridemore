/* ============================================================================
   PLIK: assets/js/app/achievements.js
   ACHIEVEMENTS MODULE - ENHANCED with modal & showcase + PRESTIGE support
   ============================================================================ */

window.AchievementsModule = {
  
  // ========================================================================
  // LOAD USER ACHIEVEMENTS
  // ========================================================================
  
  async loadUserAchievements(state) {
    state.loadingAchievements = true;
    
    try {
      const url = window.APP_CONFIG.api(`challenges/badges.php?user_local_id=${state.user.local_id}`);
      //console.log('📥 Loading badges from:', url);
      
      const response = await fetch(url);
      const data = await response.json();
      
      if (data.success) {
        state.userAchievements = data.data.badges || [];
        state.showcaseMedals = data.data.showcase || [];
        state.achievementStats = data.data.stats || {};
        
        //console.log(`✅ Loaded ${state.userAchievements.length} badges`);
        //console.log(`   - Earned: ${state.achievementStats.earned}`);
        //console.log(`   - Locked: ${state.achievementStats.locked}`);
        //console.log(`   - Showcase: ${state.showcaseMedals.length}`);
        //console.log(`   - Prestige medals: ${state.userAchievements.filter(b => b.is_prestige).length}`);
      } else {
        console.error('❌ Failed to load badges:', data.error);
        state.userAchievements = [];
      }
    } catch (error) {
      console.error('❌ Failed to load achievements:', error);
      state.userAchievements = [];
    } finally {
      state.loadingAchievements = false;
    }
  },
  
  // ========================================================================
  // MANUAL MEDAL CHECK
  // ========================================================================
  
  async checkMyMedals(state) {
    //console.log('🔍 Manual medal check requested');
    
    state.loadingAchievements = true;
    
    try {
      const url = window.APP_CONFIG.api('challenges/check-medals.php');
      
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          user_local_id: state.user.local_id
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        const newMedals = data.data.new_medals || 0;
        const updated = data.data.updated_medals || 0;
        
        //console.log(`✅ Medal check complete: ${newMedals} new, ${updated} updated`);
        
        // Show notification
        if (newMedals > 0) {
          alert(`🎉 Gratulacje! Zdobyłeś ${newMedals} ${newMedals === 1 ? 'nowy medal' : 'nowe medale'}!`);
        } else if (updated > 0) {
          alert(`✅ Zaktualizowano ${updated} ${updated === 1 ? 'medal' : 'medale'}`);
        } else {
          alert('✅ Twoje medale są aktualne. Kontynuuj treningi!');
        }
        
        // Reload badges
        await this.loadUserAchievements(state);
        
      } else {
        console.error('❌ Medal check failed:', data.error);
        alert('❌ Nie udało się sprawdzić medali. Spróbuj ponownie.');
      }
      
    } catch (error) {
      console.error('❌ Medal check error:', error);
      alert('❌ Błąd połączenia. Spróbuj ponownie.');
    } finally {
      state.loadingAchievements = false;
    }
  },
  
  // ========================================================================
  // OPEN MEDAL MODAL (NEW)
  // ========================================================================
  
  openMedalModal(state, badge) {
    //console.log('🏅 Opening medal modal:', badge.medal_name);
    
    state.medalModal = {
      show: true,
      badge: badge,
      loading: false
    };
  },
  
  closeMedalModal(state) {
    state.medalModal.show = false;
  },
  
  // ========================================================================
  // TOGGLE SHOWCASE (ADD/REMOVE combined)
  // ========================================================================
  
  async toggleShowcase(state, levelId) {
    //console.log('⭐ Toggling showcase for medal:', levelId);
    
    // Find current badge
    const badge = state.userAchievements.find(b => b.level_id === levelId);
    if (!badge) {
      console.error('❌ Badge not found');
      return;
    }
    
    const isCurrentlyInShowcase = badge.is_in_showcase;
    const action = isCurrentlyInShowcase ? 'remove' : 'add';
    
    try {
      const url = window.APP_CONFIG.api('achievements/toggle-showcase.php');
      
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          user_local_id: state.user.local_id,
          level_id: levelId,
          action: action
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        //console.log(`✅ Showcase ${action}ed`);
        
        // Reload badges to refresh showcase
        await this.loadUserAchievements(state);
        
        // Update modal if open
        if (state.medalModal.show && state.medalModal.badge.level_id === levelId) {
          const updatedBadge = state.userAchievements.find(b => b.level_id === levelId);
          if (updatedBadge) {
            state.medalModal.badge = updatedBadge;
          }
        }
        
        alert(action === 'add' ? '✅ Medal dodany do gabloty!' : '✅ Medal usunięty z gabloty');
      } else {
        console.error('❌ Failed to update showcase:', data.error);
        alert('❌ ' + (data.error || 'Nie udało się zaktualizować gabloty'));
      }
      
    } catch (error) {
      console.error('❌ Showcase toggle error:', error);
      alert('❌ Błąd połączenia');
    }
  },
  
  // ========================================================================
  // ADD TO SHOWCASE (legacy - kept for compatibility)
  // ========================================================================
  
  async addToShowcase(state, levelId) {
    return await this.toggleShowcase(state, levelId);
  },
  
  // ========================================================================
  // REMOVE FROM SHOWCASE (legacy - kept for compatibility)
  // ========================================================================
  
  async removeFromShowcase(state, levelId) {
    return await this.toggleShowcase(state, levelId);
  },
  
  // ========================================================================
  // SHARE ACHIEVEMENT (NEW)
  // ========================================================================
  
  async shareAchievement(state, badge, platform) {
    //console.log(`📤 Sharing achievement to ${platform}:`, badge.medal_name);
    
    // Generate share text
    const prestigeText = badge.is_prestige ? '⭐ PRESTIGE MEDAL ⭐\n' : '';
    const rarityText = badge.rarity_pct < 5 ? '⭐ LEGENDARY' : badge.rarity_pct < 15 ? '💎 EPIC' : '';
    
    const shareText = `🏆 Właśnie zdobyłem medal "${badge.medal_name}" w ${badge.challenge_name}!\n\n${prestigeText}${rarityText ? rarityText + ' ' : ''}(tylko ${badge.rarity_pct.toFixed(1)}% ma ten medal)`;
    
    const shareUrl = `${window.location.origin}/?achievement=${badge.level_id}`;
    
    try {
      if (platform === 'copy_link') {
        // Copy link to clipboard
        await navigator.clipboard.writeText(shareUrl);
        alert('✅ Link skopiowany do schowka!');
        
      } else if (platform === 'native') {
        // Use Web Share API if available
        if (navigator.share) {
          await navigator.share({
            title: `Medal: ${badge.medal_name}`,
            text: shareText,
            url: shareUrl
          });
        } else {
          alert('❌ Sharing nie jest dostępny w tej przeglądarce');
          return;
        }
        
      } else {
        // Social media links
        const urls = {
          facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareUrl)}&quote=${encodeURIComponent(shareText)}`,
          twitter: `https://twitter.com/intent/tweet?text=${encodeURIComponent(shareText)}&url=${encodeURIComponent(shareUrl)}`,
          strava: shareUrl // TODO: Strava integration
        };
        
        if (urls[platform]) {
          window.open(urls[platform], '_blank', 'width=600,height=400');
        }
      }
      
      // Log share to backend
      await this.logShare(state, badge.level_id, platform);
      
    } catch (error) {
      console.error('❌ Share error:', error);
    }
  },
  
  async logShare(state, levelId, platform) {
    try {
      const url = window.APP_CONFIG.api('challenges/log-share.php');
      
      await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          user_local_id: state.user.local_id,
          level_id: levelId,
          platform: platform
        })
      });
      
      //console.log('📊 Share logged');
      
    } catch (error) {
      //console.log('⚠️ Failed to log share (non-critical)');
    }
  },
  
  // ========================================================================
  // FILTER ACHIEVEMENTS
  // ========================================================================
  
  getFilteredAchievements(state) {
    switch(state.achievementFilter) {
      case 'earned':
        return state.userAchievements.filter(a => a.earned === true);
      case 'locked':
        return state.userAchievements.filter(a => a.earned === false);
      default:
        return state.userAchievements;
    }
  },
  
  // ========================================================================
  // SET FILTER
  // ========================================================================
  
  setFilter(state, filter) {
    state.achievementFilter = filter;
    //console.log('🎖️ Achievement filter:', filter);
  }
};

//console.log('✅ Achievements module v2.1 loaded (with toggleShowcase)');