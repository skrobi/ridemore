/* ============================================================================
 CHALLENGE VERIFY MODULE v3.0 - CLEAN UI
 
 CHANGES:
 - Progress bar pod nazwą wyzwania
 - SVG icons zamiast emoji
 - "Zobacz szczegóły" otwiera panel zamiast redirect
 - Gradient medium dla wyzwania, gradient hard dla tras
 ============================================================================ */

window.ChallengeVerify = {

    container: null,
    routeId: null,
    userLocalId: null,
    data: null,

    init() {
        console.log('🏆 ChallengeVerify: Initializing...');
        this.container = document.getElementById('challenge-verify-container');

        if (!this.container) {
            console.warn('⚠️ Challenge verify container not found');
            return;
        }

        if (this.container.dataset.initialized === 'true') {
            console.log('⚠️ Already initialized, skipping');
            return;
        }

        this.container.dataset.initialized = 'true';

        this.routeId = parseInt(this.container.dataset.routeId);
        this.userLocalId = this.container.dataset.userLocalId || null;

        console.log('📋 Route ID:', this.routeId);
        console.log('👤 User Local ID:', this.userLocalId || 'guest');

        if (!this.routeId) {
            this.renderError('Brak ID trasy');
            return;
        }

        this.load();
    },

    async load() {
        //console.log('🔄 Loading challenges for route:', this.routeId);

        try {
            const url = window.APP_CONFIG.api(
                    `routes/challenges-verify.php?route_id=${this.routeId}` +
                    (this.userLocalId ? `&user_local_id=${this.userLocalId}` : '')
                    );

            const response = await fetch(url);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            //console.log('📦 API Response:', result);

            if (!result.success) {
                throw new Error(result.error || 'API error');
            }

            this.data = result.data;
            this.render();

        } catch (error) {
            console.error('❌ Challenge verify error:', error);
            this.renderError('Nie udało się załadować wyzwań');
        }
    },

    render() {
        if (!this.data || !this.data.type) {
            this.renderError('Brak danych');
            return;
        }

        //console.log('🎨 Rendering type:', this.data.type);

        const renderers = {
            'reference_no_challenge': this.renderReferenceNoChallenge,
            'reference_no_activities': this.renderReferenceNoActivities,
            'reference_with_activities': this.renderReferenceWithActivities,
            'challenge_guest': this.renderChallengeGuest,
            'challenge_not_enrolled': this.renderChallengeNotEnrolled,
            'challenge_enrolled': this.renderChallengeEnrolled,
            'activity_no_challenges': this.renderActivityNoChallenges,
            'activity_with_challenges': this.renderActivityWithChallenges,
            'no_challenges': this.renderNoChallenges
        };

        const renderer = renderers[this.data.type];
        if (renderer) {
            renderer.call(this);
        } else {
            this.renderGeneric();
        }
    },

    // ========================================================================
    // SVG ICONS
    // ========================================================================

    icons: {
        search: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>`,
        bike: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/></svg>`,

        trophy: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>`,

        lock: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`,

        warning: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>`,

        check: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,

        map: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" x2="9" y1="3" y2="18"/><line x1="15" x2="15" y1="6" y2="21"/></svg>`
    },

    icon(name, size = 14) {
        return `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/${name}.svg" width="${size}" height="${size}" style="display: inline-block; vertical-align: middle;" alt="">`;
    },

    // ========================================================================
    // RENDERERS
    // ========================================================================

    renderNoChallenges() {
        this.container.innerHTML = `
      <div style="text-align: center; padding: 32px 20px; color: var(--color-text-muted);">
        ${this.icons.search}
        <div style="font-size: 13px; margin-top: 12px; line-height: 1.5;">Ta trasa nie należy do żadnego wyzwania</div>
      </div>
    `;
    },

    renderReferenceNoChallenge() {
        this.container.innerHTML = `
      <div style="text-align: center; padding: 32px 20px; color: var(--color-text-muted);">
        ${this.icons.search}
        <div style="font-size: 13px; margin-top: 12px; line-height: 1.5;">${this.data.message}</div>
      </div>
    `;
    },

    renderReferenceNoActivities() {
        this.container.innerHTML = `
      <div style="text-align: center; padding: 32px 20px;">
        ${this.icons.bike}
        <div style="font-size: 14px; color: var(--color-text); margin: 12px 0 8px;">
          Nie masz jeszcze aktywności w tym obszarze
        </div>
        <div style="font-size: 12px; color: var(--color-text-muted); line-height: 1.5;">
          Wgraj GPX swojego przejazdu, aby sprawdzić pokrycie tej trasy
        </div>
      </div>
    `;
    },

    renderReferenceWithActivities() {
    const {
        total_coverage_pct, 
        total_covered_km, 
        route_distance_km, 
        matched_activities,
        unmatched_activities,
        can_match
    } = this.data;

    const hasMatched = matched_activities && matched_activities.length > 0;
    const hasUnmatched = unmatched_activities && unmatched_activities.length > 0;

    this.container.innerHTML = `
      <div>
        ${hasMatched ? `
          <!-- ✅ ZMATCHOWANE - Pokazuje pokrycie -->
          <div style="margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
              <span style="font-size: 12px; font-weight: 500; color: var(--color-text-muted);">Twoje pokrycie</span>
              <span style="font-size: 12px; font-weight: 600; color: #3b82f6;">
                ${total_covered_km}km / ${route_distance_km}km
              </span>
            </div>
            
            <!-- Gradient progress bar (hard - dla trasy) -->
            <div style="height: 8px; background: rgba(0,0,0,0.06); border-radius: 4px; overflow: hidden;">
              <div style="width: ${total_coverage_pct}%; height: 100%; background: var(--gradient-hard); transition: width 0.3s;"></div>
            </div>
            
            <div style="font-size: 13px; font-weight: 600; color: #3b82f6; margin-top: 6px;">
              ${total_coverage_pct}%
            </div>
          </div>
          
          <!-- Lista zmatchowanych aktywności -->
          <div style="font-size: 11px; font-weight: 600; color: var(--color-text-light); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.02em;">
            Zweryfikowane aktywności (${matched_activities.length})
          </div>
          
          <div style="
            max-height: 240px; 
            overflow-y: auto; 
            margin-bottom: 12px;
            scrollbar-width: thin;
            scrollbar-color: rgba(0,0,0,0.2) transparent;
          ">
            ${matched_activities.map(a => `
              <div 
                data-activity-id="${a.route_id}"
                onclick="window.RouteHighlightModule?.showRouteOnMap(${a.route_id})"
                style="
                  padding: 10px 12px; 
                  background: white; 
                  border: 1px solid var(--color-border-light); 
                  border-radius: 8px; 
                  margin-bottom: 8px; 
                  cursor: pointer;
                  transition: all 0.15s ease;
                "
                >
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                  <div style="font-weight: 500; font-size: 12px; color: var(--color-text);">
                    ${a.name || 'Aktywność'}
                  </div>
                  ${a.is_completed ? `
                    <span style="display: inline-flex; align-items: center; gap: 3px; font-size: 9px; color: #10b981; font-weight: 600; background: rgba(16, 185, 129, 0.1); padding: 3px 6px; border-radius: 4px;">
                      ${this.icons.check}
                      UKOŃCZONA
                    </span>
                  ` : ''}
                </div>
                
                <div style="color: var(--color-text-muted); font-size: 10px; margin-bottom: 6px;">
                  ${a.activity_date}${a.external_source ? ` • ${a.external_source === 'strava' ? '🔗 Strava' : '📁 GPX'}` : ''}
                </div>
                
                <!-- Progress bar -->
                <div style="height: 4px; background: rgba(0,0,0,0.06); border-radius: 2px; overflow: hidden; margin-bottom: 4px;">
                  <div style="width: ${a.coverage_pct}%; height: 100%; background: ${a.is_completed ? '#10b981' : 'var(--gradient-hard)'}; transition: width 0.3s;"></div>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <span style="font-size: 11px; font-weight: 600; color: ${a.is_completed ? '#10b981' : '#3b82f6'};">
                    ${a.coverage_pct}% pokrycia
                  </span>
                  <div style="color: var(--color-text-muted);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                  </div>
                </div>
              </div>
            `).join('')}
          </div>
          
          <!-- Custom scrollbar -->
          <style>
            div[style*="max-height: 240px"]::-webkit-scrollbar {
              width: 6px;
            }
            div[style*="max-height: 240px"]::-webkit-scrollbar-track {
              background: transparent;
            }
            div[style*="max-height: 240px"]::-webkit-scrollbar-thumb {
              background: rgba(0,0,0,0.2);
              border-radius: 3px;
            }
          </style>
        ` : ''}

        ${hasUnmatched ? `
          <!-- ⚠️ NIEZMATCHOWANE - Wymaga akcji -->
          <div style="
            background: rgba(245, 158, 11, 0.05); 
            border: 1px solid rgba(245, 158, 11, 0.3); 
            border-radius: 10px; 
            padding: 14px; 
            margin-bottom: 16px;
          ">
            <div style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px;">
              ${this.icons.warning}
              <div>
                <div style="font-size: 12px; font-weight: 600; color: #f59e0b; margin-bottom: 4px;">
                  Potencjalne aktywności (${unmatched_activities.length})
                </div>
                <div style="font-size: 11px; color: var(--color-text-muted); line-height: 1.4;">
                  Te aktywności mogą pokrywać tę trasę, ale nie zostały jeszcze potwierdzone
                </div>
              </div>
            </div>

            <!-- Lista niezmatchowanych -->
            <div style="
              max-height: 200px; 
              overflow-y: auto; 
              margin-bottom: 12px;
              scrollbar-width: thin;
              scrollbar-color: rgba(0,0,0,0.2) transparent;
            ">
              ${unmatched_activities.map(a => `
                <div 
                  data-activity-id="${a.route_id}"
                  style="
                    padding: 8px 10px; 
                    background: white; 
                    border: 1px solid var(--color-border-light); 
                    border-radius: 6px; 
                    margin-bottom: 6px;
                    cursor: pointer;
                    transition: background 0.15s ease, border-color 0.15s ease;
                  ">
                  
                  <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="flex: 1; min-width: 0;">
                      <div style="font-size: 11px; font-weight: 500; color: var(--color-text); margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        ${a.name || 'Aktywność'}
                      </div>
                      <div style="font-size: 10px; color: var(--color-text-muted);">
                        ${a.activity_date} • ${a.distance_km}km
                        ${a.external_source ? ` • ${a.external_source === 'strava' ? '🔗 Strava' : '📁 GPX'}` : ''}
                      </div>
                    </div>
                    
                    <div style="margin-left: 8px; color: var(--color-text-muted);">
                      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                      </svg>
                    </div>
                  </div>
                </div>
              `).join('')}
            </div>

            <!-- Custom scrollbar -->
            <style>
              div[style*="max-height: 200px"]::-webkit-scrollbar {
                width: 6px;
              }
              div[style*="max-height: 200px"]::-webkit-scrollbar-track {
                background: transparent;
              }
              div[style*="max-height: 200px"]::-webkit-scrollbar-thumb {
                background: rgba(0,0,0,0.2);
                border-radius: 3px;
              }
            </style>

            ${can_match ? `
              <button 
                onclick="window.ChallengeVerify.matchThisRoute()" 
                class="btn btn-primary btn-block"
                style="display: flex; align-items: center; justify-content: center; gap: 6px;">
                ${this.icons.search}
                Zmatchuj ${unmatched_activities.length} ${unmatched_activities.length === 1 ? 'aktywność' : unmatched_activities.length < 5 ? 'aktywności' : 'aktywności'}
              </button>
            ` : `
              <div style="text-align: center; padding: 12px; background: rgba(0,0,0,0.03); border-radius: 6px;">
                <div style="font-size: 11px; color: var(--color-text-muted);">
                  Brak uprawnień do matchowania
                </div>
              </div>
            `}
          </div>
        ` : ''}

        ${!hasMatched && !hasUnmatched ? `
          <!-- Brak aktywności -->
          <div style="text-align: center; padding: 32px 20px;">
            ${this.icons.bike}
            <div style="font-size: 14px; color: var(--color-text); margin: 12px 0 8px;">
              Nie masz jeszcze aktywności w tym obszarze
            </div>
            <div style="font-size: 12px; color: var(--color-text-muted); line-height: 1.5;">
              Wgraj GPX swojego przejazdu, aby sprawdzić pokrycie tej trasy
            </div>
          </div>
        ` : ''}
      </div>
    `;
},

    renderChallengeGuest() {
        const challenges = this.data.challenges || [];

        this.container.innerHTML = `
      <div style="text-align: center; padding: 32px 20px;">
        ${this.icons.lock}
        <div style="font-size: 14px; font-weight: 500; margin: 12px 0 8px;">
          Ta trasa należy do ${challenges.length} wyzwań
        </div>
        <div style="font-size: 12px; color: var(--color-text-muted); margin-bottom: 16px; line-height: 1.5;">
          ${this.data.message}
        </div>
      </div>
    `;
    },

    renderChallengeNotEnrolled() {
        const challenge = this.data.challenge;

        this.container.innerHTML = `
      <div style="text-align: center; padding: 32px 20px;">
        ${this.icons.trophy}
        <div style="font-size: 14px; font-weight: 500; margin: 12px 0 8px;">
          ${challenge.name}
        </div>
        <div style="font-size: 12px; color: var(--color-text-muted); margin-bottom: 16px; line-height: 1.5;">
          ${this.data.message}
        </div>
        <button onclick="window.ChallengeVerify.enrollChallenge(${challenge.challenge_id})" class="btn btn-primary">
          Dołącz do wyzwania
        </button>
      </div>
    `;
    },

    renderChallengeEnrolled() {
        const {challenge, user_progress, this_route} = this.data;

        const completedRoutes = user_progress.completed_routes || [];
        const isThisRouteCompleted = this_route.is_completed;
        const hasActivities = this_route.activities && this_route.activities.length > 0;
        const hasCoverage = this_route.coverage_pct > 0;

        this.container.innerHTML = `
      <div>
        <!-- Challenge Header z Progress Bar -->
        <div style="margin-bottom: 20px;">
          <div style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: var(--color-text);">
            ${challenge.name}
          </div>
          
          <!-- Progress Bar (gradient medium - dla wyzwania) -->
          <div style="margin-bottom: 8px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
              <span style="font-size: 11px; font-weight: 500; color: var(--color-text-muted);">Twój postęp</span>
              <span style="font-size: 11px; font-weight: 600; color: #f59e0b;">
                ${completedRoutes.length}/${user_progress.total_routes} tras
              </span>
            </div>
            
            <div style="height: 6px; background: rgba(0,0,0,0.06); border-radius: 3px; overflow: hidden;">
              <div style="width: ${user_progress.progress_pct}%; height: 100%; background: var(--gradient-medium); transition: width 0.3s;"></div>
            </div>
            
            <div style="font-size: 12px; font-weight: 600; color: #f59e0b; margin-top: 4px;">
              ${user_progress.progress_pct}%
            </div>
          </div>
        </div>
        
        ${hasCoverage ? `
          <!-- Ta trasa - pokrycie -->
          <div style="background: ${isThisRouteCompleted ? 'rgba(16, 185, 129, 0.05)' : 'white'}; border: 1px solid ${isThisRouteCompleted ? 'rgba(16, 185, 129, 0.3)' : 'var(--color-border-light)'}; border-radius: 10px; padding: 14px; margin-bottom: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
              <span style="font-size: 12px; font-weight: 600; color: var(--color-text);">Ta trasa</span>
              ${isThisRouteCompleted ? `
                <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 10px; color: #10b981; font-weight: 600; background: rgba(16, 185, 129, 0.1); padding: 4px 8px; border-radius: 4px;">
                  ${this.icons.check}
                  UKOŃCZONA
                </span>
              ` : ''}
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
              <span style="font-size: 11px; color: var(--color-text-muted);">Przejechane</span>
              <span style="font-size: 11px; font-weight: 600; color: var(--color-text);">
                ${this_route.covered_km}km / ${this_route.total_km}km
              </span>
            </div>
            
            <!-- Progress bar (gradient hard - dla trasy) -->
            <div style="height: 5px; background: rgba(0,0,0,0.06); border-radius: 2.5px; overflow: hidden; margin-bottom: 12px;">
              <div style="width: ${this_route.coverage_pct}%; height: 100%; background: ${isThisRouteCompleted ? '#10b981' : 'var(--gradient-hard)'}; transition: width 0.3s;"></div>
            </div>
            
            <div style="font-size: 12px; font-weight: 600; color: ${isThisRouteCompleted ? '#10b981' : '#3b82f6'};">
              ${this_route.coverage_pct}%
            </div>
            
            ${hasActivities ? `
                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--color-border-light);">
                  <div style="font-size: 10px; font-weight: 600; color: var(--color-text-light); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.02em;">
                    Aktywności (${this_route.activities.length})
                  </div>

                  <!-- Scrollable container -->
                  <div style="
                    max-height: 240px; 
                    overflow-y: auto; 
                    margin-bottom: 10px;
                    scrollbar-width: thin;
                    scrollbar-color: rgba(0,0,0,0.2) transparent;
                  ">
                    ${this_route.activities
                .sort((a, b) => new Date(b.activity_date) - new Date(a.activity_date))
                .map(a => `
                        <div 
                          data-activity-id="${a.route_id}"
                          onclick="window.RouteHighlightModule?.showRouteOnMap(${a.route_id})"
                          style="
                            font-size: 11px; 
                            padding: 8px 6px; 
                            border-bottom: 1px solid var(--color-border-light); 
                            display: flex; 
                            justify-content: space-between; 
                            align-items: center;
                            cursor: pointer;
                            transition: background 0.15s ease;
                          "
                          onmouseover="this.style.background='rgba(59, 130, 246, 0.05)'"
                          onmouseout="this.style.background='transparent'">

                          <span style="color: var(--color-text-muted); flex-shrink: 0; margin-right: 8px; font-size: 10px;">
                            ${a.activity_date}
                          </span>

                          <span style="font-weight: 500; flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            ${a.name || 'Aktywność'}
                          </span>

                          <span style="color: #10b981; font-weight: 600; flex-shrink: 0; margin-left: 8px;">
                            ${a.total_coverage_pct}%
                          </span>
                        </div>
                      `).join('')}
                  </div>

                  <!-- Custom scrollbar styling -->
                  <style>
                    div[style*="max-height: 240px"]::-webkit-scrollbar {
                      width: 6px;
                    }
                    div[style*="max-height: 240px"]::-webkit-scrollbar-track {
                      background: transparent;
                    }
                    div[style*="max-height: 240px"]::-webkit-scrollbar-thumb {
                      background: rgba(0,0,0,0.2);
                      border-radius: 3px;
                    }
                    div[style*="max-height: 240px"]::-webkit-scrollbar-thumb:hover {
                      background: rgba(0,0,0,0.3);
                    }
                  </style>

                  <!-- Przycisk pokrycia -->
                  <button 
                    id="coverage-toggle-btn"
                    onclick="window.ChallengeVerify.toggleCoverage(${this.routeId})" 
                    class="btn btn-secondary btn-sm" 
                    style="width: 100%; margin-top: 10px; font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                    ${this.icons.map}
                    Pokaż pokrycie na mapie
                  </button>
                </div>
              ` : ''}
          </div>
        ` : `
          <!-- Brak pokrycia -->
          <div style="text-align: center; padding: 24px; background: white; border: 1px solid var(--color-border-light); border-radius: 10px; margin-bottom: 12px;">
            ${this.icons.bike}
            <div style="font-size: 13px; color: var(--color-text); margin: 12px 0 8px;">
              Nie masz jeszcze pokrycia tej trasy
            </div>
            <div style="font-size: 11px; color: var(--color-text-muted); margin-bottom: 16px; line-height: 1.5;">
              Wgraj swoją aktywność lub zmatchuj istniejące przejazdy
            </div>
            <button onclick="window.ChallengeVerify.matchThisRoute()" class="btn btn-primary btn-sm">
              Sprawdź moje aktywności
            </button>
          </div>
        `}
        
        <!-- Przycisk szczegółów - OTWIERA PANEL -->
        <button 
          onclick="window.ChallengeVerify.openChallengeDetails(${challenge.challenge_id})" 
          class="btn btn-secondary btn-block"
          style="display: flex; align-items: center; justify-content: center; gap: 8px;">
          ${this.icons.trophy}
          Zobacz szczegóły wyzwania
        </button>
      </div>
    `;
    },

    renderActivityNoChallenges() {
        this.container.innerHTML = `
      <div style="text-align: center; padding: 32px 20px;">
        ${this.icons.bike}
        <div style="font-size: 13px; color: var(--color-text-muted); margin-top: 12px; line-height: 1.5;">
          ${this.data.message}
        </div>
      </div>
    `;
    },

    /* ============================================================================
     CHALLENGE VERIFY - renderActivityWithChallenges() FIXED
     Grupowanie matched challenges po challenge_id
     ============================================================================ */

    renderActivityWithChallenges() {
        const matchedChallenges = this.data.matched_challenges || [];
        const potentialChallenges = this.data.potential_challenges || [];

        // ✅ GRUPOWANIE matched challenges po challenge_id
        const groupedMatched = {};
        matchedChallenges.forEach(c => {
            if (!groupedMatched[c.challenge_id]) {
                groupedMatched[c.challenge_id] = {
                    challenge_id: c.challenge_id,
                    challenge_name: c.challenge_name,
                    icon: c.icon,
                    image_url: c.image_url,
                    is_enrolled: c.is_enrolled,
                    routes: []
                };
            }
            groupedMatched[c.challenge_id].routes.push(c);
        });

        // GRUPOWANIE potential challenges po challenge_id
        const groupedPotential = {};
        potentialChallenges.forEach(c => {
            if (!groupedPotential[c.challenge_id]) {
                groupedPotential[c.challenge_id] = {
                    challenge_id: c.challenge_id,
                    challenge_name: c.challenge_name,
                    icon: c.icon,
                    image_url: c.image_url,
                    is_enrolled: c.is_enrolled,
                    user_progress_pct: c.user_progress_pct,
                    routes: []
                };
            }
            groupedPotential[c.challenge_id].routes.push(c);
        });


        this.container.innerHTML = `
      <div>
        ${Object.keys(groupedMatched).length > 0 ? `
          <!-- ZMATCHOWANE WYZWANIA -->
          <div style="margin-bottom: 20px;">
            <div style="font-size: 11px; font-weight: 600; color: var(--color-text-light); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.02em;">
              ✅ ZMATCHOWANE (${Object.keys(groupedMatched).length})
            </div>
            
            ${Object.values(groupedMatched).map(challenge => {
            // Oblicz łączny procent dla wyzwania
            const totalPct = challenge.routes.reduce((sum, r) => sum + parseFloat(r.total_coverage_pct || 0), 0) / challenge.routes.length;
            const completedRoutes = challenge.routes.filter(r => r.is_completed).length;

            return `
              <div 
                onclick="window.ChallengeVerify.openChallengeDetails(${challenge.challenge_id})"
                style="
                  padding: 14px; 
                  background: white; 
                  border: 1px solid var(--color-border-light); 
                  border-radius: 10px; 
                  margin-bottom: 10px;
                  cursor: pointer;
                  transition: all 0.2s ease;
                "
                onmouseover="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 2px 8px rgba(59, 130, 246, 0.1)'"
                onmouseout="this.style.borderColor='var(--color-border-light)'; this.style.boxShadow='none'">
                
                <!-- Nazwa wyzwania + status -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                  <div style="font-size: 13px; font-weight: 600; color: var(--color-text); display: flex; align-items: center; gap: 6px;">
                    ${this.icons.trophy}
                    ${challenge.challenge_name}
                  </div>
                  
                  ${completedRoutes > 0 ? `
                    <span style="display: inline-flex; align-items: center; gap: 3px; font-size: 9px; color: #10b981; font-weight: 600; background: rgba(16, 185, 129, 0.1); padding: 3px 6px; border-radius: 4px;">
                      ${this.icons.check}
                      ${completedRoutes}/${challenge.routes.length}
                    </span>
                  ` : ''}
                </div>
                
                <!-- Progress ogólny wyzwania -->
                <div style="margin-bottom: 12px;">
                  <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 10px; color: var(--color-text-muted);">Średnie pokrycie</span>
                    <span style="font-size: 10px; font-weight: 600; color: #f59e0b;">${totalPct.toFixed(1)}%</span>
                  </div>
                  <div style="height: 5px; background: rgba(0,0,0,0.06); border-radius: 2.5px; overflow: hidden;">
                    <div style="width: ${totalPct}%; height: 100%; background: var(--gradient-medium); transition: width 0.3s;"></div>
                  </div>
                </div>
                
                <!-- Lista tras (collapsed) -->
                <div style="font-size: 10px; font-weight: 600; color: var(--color-text-light); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.02em;">
                  TRASY (${challenge.routes.length})
                </div>
                
                ${challenge.routes.map(route => `
                  <div style="padding: 8px; background: rgba(0,0,0,0.02); border-radius: 6px; margin-bottom: 6px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                      <div style="font-size: 11px; font-weight: 500; color: var(--color-text);">
                        ${route.route_name}
                      </div>
                      ${route.is_completed ? `
                        <span style="display: inline-flex; align-items: center; gap: 2px; font-size: 9px; color: #10b981; font-weight: 600;">
                          ${this.icons.check}
                        </span>
                      ` : ''}
                    </div>
                    
                    <!-- Mini progress bar -->
                    <div style="height: 3px; background: rgba(0,0,0,0.06); border-radius: 1.5px; overflow: hidden; margin-bottom: 4px;">
                      <div style="width: ${route.total_coverage_pct}%; height: 100%; background: ${route.is_completed ? '#10b981' : 'var(--gradient-hard)'}; transition: width 0.3s;"></div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; font-size: 10px;">
                      <span style="color: var(--color-text-muted);">${route.total_coverage_pct}%</span>
                      <button 
                        onclick="event.stopPropagation(); window.ChallengeVerify.showActivityRouteCoverage(${route.challenge_route_id})"
                        style="background: none; border: none; color: #3b82f6; cursor: pointer; padding: 0; font-size: 10px; text-decoration: underline;">
                        Pokaż pokrycie
                      </button>
                    </div>
                  </div>
                `).join('')}
                
                <!-- Przycisk wyzwania -->
                <button 
                  onclick="event.stopPropagation(); window.ChallengeVerify.openChallengeDetails(${challenge.challenge_id})"
                  class="btn btn-secondary btn-sm" 
                  style="width: 100%; margin-top: 10px; font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                  ${this.icons.trophy}
                  Zobacz wyzwanie
                </button>
              </div>
            `;
        }).join('')}
          </div>
        ` : ''}
        
        ${Object.values(groupedPotential).map(challenge => `
        <div 
          style="
            padding: 14px; 
            background: ${challenge.is_enrolled ? 'rgba(245, 158, 11, 0.05)' : 'rgba(59, 130, 246, 0.02)'}; 
            border: 1px ${challenge.is_enrolled ? 'solid' : 'dashed'} ${challenge.is_enrolled ? 'rgba(245, 158, 11, 0.3)' : 'rgba(59, 130, 246, 0.3)'}; 
            border-radius: 10px; 
            margin-bottom: 10px;
          ">

          <!-- Nazwa wyzwania z ikoną -->
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
            <div style="font-size: 13px; font-weight: 600; color: var(--color-text); display: flex; align-items: center; gap: 6px;">
              ${challenge.image_url
                    ? `<img src="${window.APP_CONFIG.baseUrl}/${challenge.image_url}" width="20" height="20" style="border-radius: 4px; object-fit: cover;" alt="">`
                    : (challenge.icon || '🏆')
                    }
              ${challenge.challenge_name}
            </div>

            ${challenge.is_enrolled ? `
              <span style="display: inline-flex; align-items: center; gap: 3px; font-size: 9px; color: #f59e0b; font-weight: 600; background: rgba(245, 158, 11, 0.1); padding: 3px 6px; border-radius: 4px;">
                ${this.icon('check', 12)}
                UCZESTNICZYSZ
              </span>
            ` : `
              <span style="font-size: 9px; color: var(--color-text-muted); background: rgba(0,0,0,0.05); padding: 3px 6px; border-radius: 4px;">
                Nie zapisany
              </span>
            `}
          </div>

          ${challenge.is_enrolled && challenge.user_progress_pct > 0 ? `
            <!-- Progress w całym wyzwaniu -->
            <div style="margin-bottom: 12px; padding: 8px; background: rgba(245, 158, 11, 0.08); border-radius: 6px;">
              <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                <span style="font-size: 10px; color: var(--color-text-muted);">Postęp w wyzwaniu</span>
                <span style="font-size: 10px; font-weight: 600; color: #f59e0b;">${challenge.user_progress_pct}%</span>
              </div>
              <div style="height: 4px; background: rgba(0,0,0,0.1); border-radius: 2px; overflow: hidden;">
                <div style="width: ${challenge.user_progress_pct}%; height: 100%; background: var(--gradient-medium); transition: width 0.3s;"></div>
              </div>
            </div>
          ` : ''}

          <!-- Lista tras w tym wyzwaniu -->
          <div style="margin-bottom: 12px;">
            ${challenge.routes.map(route => `
              <div style="padding: 8px; background: white; border: 1px solid var(--color-border-light); border-radius: 6px; margin-bottom: 6px;">
                <div style="font-size: 11px; color: var(--color-text); font-weight: 500; margin-bottom: 4px;">
                  ${this.icon('map-pin', 12)} ${route.route_name}
                </div>
                <div style="font-size: 10px; color: var(--color-text-muted);">
                  ${route.route_distance_km}km · ~${route.estimated_overlap_km}km pokrycia
                </div>
              </div>
            `).join('')}
          </div>

          ${challenge.is_enrolled ? `
            <!-- Warning - nie zmatchowane -->
            <div style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; padding: 10px; background: rgba(245, 158, 11, 0.08); border-left: 3px solid #f59e0b; border-radius: 4px;">
              ${this.icon('alert-triangle', 16)}
              <div style="font-size: 11px; line-height: 1.4; color: var(--color-text);">
                <strong style="color: #f59e0b;">Trasy wymagają matchowania</strong><br>
                <span style="color: var(--color-text-muted);">Ten przejazd nie został jeszcze zmatchowany z trasami wyzwania</span>
              </div>
            </div>
          ` : ''}

          <!-- Przyciski -->
          <div style="display: flex; gap: 8px;">
            ${challenge.is_enrolled ? `
              <!-- Zapisany – Sprawdź przejazd + Zobacz wyzwanie -->
                <button 
                onclick="window.ChallengeVerify.matchChallengeRoutes(${challenge.challenge_id}, '${challenge.challenge_name}')"
                class="btn btn-primary btn-sm" 
                style="flex: 1; font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 4px;">
                ${this.icon('refresh-cw', 12)}
                Sprawdź przejazd
              </button>
              <button 
                onclick="window.ChallengeVerify.openChallengeDetails(${challenge.challenge_id})"
                class="btn btn-secondary btn-sm" 
                style="flex: 1; font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 4px;">
                ${this.icon('trophy', 12)}
                Zobacz wyzwanie
              </button>
            ` : `
              <!-- Nie zapisany – tylko Zapisz się -->
              <button 
                onclick="window.ChallengeVerify.enrollChallenge(${challenge.challenge_id})"
                class="btn btn-primary btn-sm" 
                style="width: 100%; font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 4px;">
                ${this.icon('user-plus', 14)}
                Zapisz się do wyzwania
              </button>
            `}
          </div>
        </div>
      `).join('')}
        
        ${Object.keys(groupedMatched).length === 0 && Object.keys(groupedPotential).length === 0 ? `
          <!-- Brak wyzwań -->
          <div style="text-align: center; padding: 32px 20px;">
            ${this.icons.search}
            <div style="font-size: 13px; color: var(--color-text-muted); margin-top: 12px; line-height: 1.5;">
              Ta aktywność nie przecina się z żadnym wyzwaniem
            </div>
          </div>
        ` : ''}
      </div>
    `;
    },

// Matchuj wszystkie trasy w wyzwaniu
    async matchChallengeRoutes(challengeId, challengeName) {
        //console.log('🔄 Matching challenge routes:', challengeId);

        // Znajdź wszystkie trasy dla tego wyzwania
        const potentialChallenges = this.data.potential_challenges || [];
        const routes = potentialChallenges.filter(c => c.challenge_id === challengeId);

        if (routes.length === 0) {
            alert('Brak tras do porównania');
            return;
        }

        // Pokaż modal
        this.showMatchModal(challengeName, routes);
    },

    showMatchModal(challengeName, routes) {
        // Utwórz overlay
        const overlay = document.createElement('div');
        overlay.id = 'challenge-match-modal';
        overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
    `;

        // Modal container
        const modal = document.createElement('div');
        modal.style.cssText = `
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 500px;
        max-height: 80vh;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    `;

        // Header
        const header = document.createElement('div');
        header.style.cssText = `
        padding: 20px 24px;
        border-bottom: 1px solid var(--color-border-light);
    `;
        header.innerHTML = `
        <h3 style="font-size: 16px; font-weight: 600; margin: 0 0 8px 0; color: var(--color-text);">
            Sprawdzanie przejazdu
        </h3>
        <p style="font-size: 13px; margin: 0; color: var(--color-text-muted);">
            ${challengeName}
        </p>
    `;

        // Body
        const body = document.createElement('div');
        body.id = 'match-modal-body';
        body.style.cssText = `
        padding: 20px 24px;
        max-height: 400px;
        overflow-y: auto;
    `;

        // Lista tras
        routes.forEach(route => {
            const routeItem = document.createElement('div');
            routeItem.id = `route-item-${route.challenge_route_id}`;
            routeItem.style.cssText = `
            padding: 12px;
            background: rgba(245, 245, 247, 0.5);
            border: 1px solid var(--color-border-light);
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        `;
            routeItem.innerHTML = `
            <div style="flex: 1;">
                <div style="font-size: 13px; font-weight: 500; color: var(--color-text); margin-bottom: 4px;">
                    ${route.route_name}
                </div>
                <div class="route-details" style="font-size: 11px; color: var(--color-text-muted);">
                    ${route.route_distance_km}km · ~${route.estimated_overlap_km}km pokrycia
                </div>
            </div>
            <div class="route-status" style="margin-left: 12px;">
                ${this.icon('clock', 20)}
            </div>
        `;
            body.appendChild(routeItem);
        });

        // Footer
        const footer = document.createElement('div');
        footer.style.cssText = `
        padding: 16px 24px;
        border-top: 1px solid var(--color-border-light);
        display: none;
    `;
        footer.innerHTML = `
        <button 
            onclick="document.getElementById('challenge-match-modal').remove(); window.ChallengeVerify.load();"
            class="btn btn-primary"
            style="width: 100%;">
            Zamknij
        </button>
    `;

        modal.appendChild(header);
        modal.appendChild(body);
        modal.appendChild(footer);
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        // Rozpocznij matchowanie
        this.processMatchQueue(routes, footer);
    },

    async processMatchQueue(routes, footer) {
        let successCount = 0;
        let failCount = 0;

        for (const route of routes) {
            const routeItem = document.getElementById(`route-item-${route.challenge_route_id}`);
            const statusIcon = routeItem.querySelector('.route-status');
            const routeDetails = routeItem.querySelector('.route-details');

            // Pokaż spinner
            statusIcon.innerHTML = `
            <svg class="animate-spin" style="width: 20px; height: 20px; color: #3b82f6;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        `;

            try {
                const url = window.APP_CONFIG.api('routes/match-activities.php');
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        reference_route_id: route.challenge_route_id,
                        user_local_id: this.userLocalId
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // Sukces - pokaż check
                    statusIcon.innerHTML = this.icon('circle-check', 20);
                    statusIcon.style.color = '#10b981';
                    routeItem.style.background = 'rgba(16, 185, 129, 0.05)';
                    routeItem.style.borderColor = 'rgba(16, 185, 129, 0.3)';

                    // Pokaż pokrycie
                    routeDetails.innerHTML = `
                    <span style="color: #10b981; font-weight: 600;">✓ ${result.data.total_covered_km}km pokrycia</span>
                `;

                    successCount++;
                } else {
                    throw new Error(result.error);
                }
            } catch (error) {
                console.error('❌ Match error for route:', route.challenge_route_id, error);
                // Błąd - pokaż X
                statusIcon.innerHTML = this.icon('x-circle', 20);
                statusIcon.style.color = '#ef4444';
                routeItem.style.background = 'rgba(239, 68, 68, 0.05)';
                routeItem.style.borderColor = 'rgba(239, 68, 68, 0.3)';

                // Pokaż błąd
                routeDetails.innerHTML = `
                <span style="color: #ef4444; font-weight: 600;">✗ Błąd matchowania</span>
            `;

                failCount++;
            }

            // Krótka przerwa między requestami
            await new Promise(resolve => setTimeout(resolve, 500));
        }

        // Pokaż podsumowanie
        const body = document.getElementById('match-modal-body');
        const summary = document.createElement('div');
        summary.style.cssText = `
        margin-top: 16px;
        padding: 16px;
        background: rgba(59, 130, 246, 0.05);
        border: 1px solid rgba(59, 130, 246, 0.2);
        border-radius: 8px;
        text-align: center;
    `;
        summary.innerHTML = `
        <div style="font-size: 14px; font-weight: 600; color: var(--color-text); margin-bottom: 8px;">
            Matchowanie zakończone
        </div>
        <div style="font-size: 12px; color: var(--color-text-muted);">
            Zmatchowano: <strong style="color: #10b981;">${successCount}</strong> / ${routes.length} tras
            ${failCount > 0 ? `<br>Błędy: <strong style="color: #ef4444;">${failCount}</strong>` : ''}
        </div>
    `;
        body.appendChild(summary);

        // Pokaż przycisk Zamknij
        footer.style.display = 'block';
    },

    // Matchuj pojedynczą trasę
    async matchSingleRoute(challengeRouteId, challengeName) {
        if (!confirm(`Czy zmatchować tę aktywność z trasą "${challengeName}"?`)) {
            return;
        }

        try {
            const url = window.APP_CONFIG.api('routes/match-activities.php');
            const response = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    reference_route_id: challengeRouteId,
                    user_local_id: this.userLocalId
                })
            });

            const result = await response.json();

            if (result.success) {
                alert(`✅ Zmatchowano! Pokrycie: ${result.data.total_coverage_pct}%`);
                this.load(); // Reload
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            console.error('❌ Match error:', error);
            alert('Błąd matchowania: ' + error.message);
        }
    },

// Zapisz się i zmatchuj
    async enrollAndMatch(challengeId, challengeRouteId) {
        alert(`🏆 Zapisywanie do wyzwania i matchowanie w przygotowaniu`);
        // TODO: Implement enrollment API
    },

// Pokaż pokrycie dla activity route
    showActivityRouteCoverage(challengeRouteId) {
        //console.log('🗺️ Showing coverage for activity route:', challengeRouteId);

        if (!window.RouteCoverageVisualizer) {
            alert('Moduł wizualizacji nie jest dostępny');
            return;
        }

        window.RouteCoverageVisualizer.load(challengeRouteId, this.userLocalId);
    },

    renderGeneric() {
        this.container.innerHTML = `
      <div style="text-align: center; padding: 32px 20px; color: var(--color-text-muted);">
        <div style="font-size: 13px;">${this.data.message || 'Brak danych'}</div>
      </div>
    `;
    },

    renderError(message) {
        if (!this.container)
            return;

        this.container.innerHTML = `
      <div style="text-align: center; padding: 32px 20px; color: var(--color-text-light);">
        ${this.icons.warning}
        <div style="font-size: 13px; margin-top: 12px;">${message}</div>
      </div>
    `;
    },

    // ========================================================================
    // ACTIONS
    // ========================================================================

    async matchThisRoute() {
        //console.log('🔄 Starting match for route:', this.routeId);

        if (!this.routeId || !this.userLocalId) {
            alert('Błąd: brak danych trasy lub użytkownika');
            return;
        }

        // Show loading
        this.container.innerHTML = `
      <div style="text-align: center; padding: 40px;">
        <svg class="animate-spin" style="display: inline-block; width: 40px; height: 40px; margin-bottom: 16px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <div style="font-size: 14px; font-weight: 500; margin-bottom: 8px;">Matchowanie aktywności...</div>
        <div style="font-size: 12px; color: var(--color-text-muted);">To może potrwać kilka sekund</div>
      </div>
    `;

        try {
            const url = window.APP_CONFIG.api('routes/match-activities.php');
            const response = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    reference_route_id: this.routeId,
                    user_local_id: this.userLocalId
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Matching failed');
            }

            const data = result.data;

            // Show success
            this.container.innerHTML = `
        <div style="text-align: center; padding: 30px; background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px;">
          ${this.icons.check}
          <div style="font-size: 16px; font-weight: 600; color: #10b981; margin: 12px 0 8px;">
            Matching zakończony!
          </div>
          <div style="font-size: 13px; color: var(--color-text-muted); margin-bottom: 16px;">
            Znaleziono: ${data.activities_found} aktywności<br>
            Zmatchowano: ${data.activities_matched} aktywności<br>
            Pokrycie: <strong>${data.total_coverage_pct}%</strong> (${data.total_covered_km}km / ${data.total_route_km}km)
          </div>
        </div>
      `;

            // Auto-reload
            setTimeout(() => {
                this.load();
            }, 2000);

        } catch (error) {
            console.error('❌ Match error:', error);

            this.container.innerHTML = `
        <div style="text-align: center; padding: 30px;">
          ${this.icons.warning}
          <div style="font-size: 14px; color: var(--color-text); margin: 12px 0 16px;">
            Błąd matchowania: ${error.message}
          </div>
          <button onclick="window.ChallengeVerify.load()" class="btn btn-secondary btn-sm">
            Spróbuj ponownie
          </button>
        </div>
      `;
        }
    },

    async enrollChallenge(challengeId) {
        //console.log('🏆 Enrolling to challenge:', challengeId);

        // Get Alpine app instance
        const app = Alpine.$data(document.querySelector('[x-data="app"]'));

        if (!app || typeof app.startChallenge !== 'function') {
            console.error('❌ App or startChallenge not found');
            alert('Błąd: Nie można dołączyć do wyzwania');
            return;
        }

        try {
            // Call app's startChallenge method
            await app.startChallenge(challengeId);

            // Reload challenge verify to show updated state
            //console.log('✅ Enrollment successful, reloading...');
            await this.load();

        } catch (error) {
            console.error('❌ Enrollment failed:', error);
            alert('Nie udało się dołączyć do wyzwania: ' + error.message);
        }
    },

    // ✅ NOWA METODA: Otwiera panel szczegółów zamiast redirect
    openChallengeDetails(challengeId) {
        //console.log('📖 Opening challenge details panel:', challengeId);

        const app = Alpine.$data(document.querySelector('[x-data="app"]'));
        if (app && typeof app.openChallengeDetails === 'function') {
            app.openChallengeDetails(challengeId);
        } else {
            console.error('❌ App or openChallengeDetails not found');
            // Fallback do starego przekierowania
            window.location.href = '/challenge.php?id=' + challengeId;
        }
    },

    toggleCoverage(challengeRouteId) {
        //console.log('🗺️ Toggling coverage for challenge route:', challengeRouteId);

        if (!window.RouteCoverageVisualizer) {
            alert('Moduł wizualizacji nie jest dostępny');
            return;
        }

        if (!this.userLocalId) {
            alert('Musisz być zalogowany');
            return;
        }

        const btn = document.getElementById('coverage-toggle-btn');

        if (window.RouteCoverageVisualizer.isVisible()) {
            window.RouteCoverageVisualizer.hide();
            if (btn) {
                btn.innerHTML = `${this.icons.map} Pokaż pokrycie na mapie`;
            }
        } else {
            this.loadAndShowCoverage(challengeRouteId, btn);
        }
    },

    async loadAndShowCoverage(challengeRouteId, btn) {
        if (btn) {
            btn.innerHTML = '⏳ Ładowanie...';
            btn.disabled = true;
        }

        try {
            await window.RouteCoverageVisualizer.load(challengeRouteId, this.userLocalId);

            if (btn) {
                btn.innerHTML = `${this.icons.map} Ukryj pokrycie`;
                btn.disabled = false;
            }
        } catch (error) {
            console.error('❌ Coverage visualization failed:', error);
            alert('Nie udało się wyświetlić pokrycia: ' + error.message);

            if (btn) {
                btn.innerHTML = `${this.icons.map} Pokaż pokrycie na mapie`;
                btn.disabled = false;
            }
        }
    },

    destroy() {
        if (this.container) {
            this.container.dataset.initialized = 'false';
        }
        this.container = null;
        this.routeId = null;
        this.userLocalId = null;
        this.data = null;

    }
};

// ============================================================================
// AUTO-INIT
// ============================================================================

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('challenge-verify-container');
        if (container)
            window.ChallengeVerify.init();
    });
} else {
    const container = document.getElementById('challenge-verify-container');
    if (container)
        window.ChallengeVerify.init();
}

const observer = new MutationObserver(() => {
    const container = document.getElementById('challenge-verify-container');
    if (container && container.dataset.initialized !== 'true') {
        window.ChallengeVerify.init();
    }
});

observer.observe(document.body, {
    childList: true,
    subtree: true
});

console.log('✅ ChallengeVerify v3.0 loaded (Clean UI)');