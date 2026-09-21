/* ============================================================================
   Route Card Renderer - v5.0
   Dropdown renderowany w body - omija overflow:hidden karty
   ============================================================================ */

window.RouteCardRenderer = {

    icon(name, size = 14) {
        return `<img src="${window.APP_CONFIG.baseUrl}/assets/icons/${name}.svg" width="${size}" height="${size}" style="display:inline-block;vertical-align:middle;" alt="">`;
    },

    patterns: [
        `<path d="M5,30 L40,30 M40,30 L40,60 M40,60 L80,60 M40,30 L70,10 M40,60 L70,90" stroke="currentColor" stroke-width="1.5" fill="none" opacity="0.08"/><circle cx="40" cy="30" r="2.5" fill="currentColor" opacity="0.12"/><circle cx="40" cy="60" r="2.5" fill="currentColor" opacity="0.12"/>`,
        `<path d="M10,20 L90,20 M10,40 L90,40 M10,60 L90,60 M10,80 L90,80" stroke="currentColor" stroke-width="1.5" fill="none" opacity="0.06"/><path d="M25,10 L25,90 M50,10 L50,90 M75,10 L75,90" stroke="currentColor" stroke-width="1.5" fill="none" opacity="0.06"/>`,
        `<path d="M5,20 Q30,40 60,20 T95,20" stroke="currentColor" stroke-width="2" fill="none" opacity="0.07"/><path d="M5,50 Q40,60 70,45 T95,50" stroke="currentColor" stroke-width="2" fill="none" opacity="0.07"/>`,
        `<path d="M50,5 L50,95 M5,50 L95,50" stroke="currentColor" stroke-width="2" fill="none" opacity="0.08"/><path d="M25,25 L75,75 M75,25 L25,75" stroke="currentColor" stroke-width="1.5" fill="none" opacity="0.07"/>`,
        `<path d="M5,40 Q20,25 35,40 T65,40 T95,40" stroke="currentColor" stroke-width="1.5" fill="none" opacity="0.07"/><path d="M5,65 Q25,75 45,65 T85,65" stroke="currentColor" stroke-width="1.5" fill="none" opacity="0.07"/>`
    ],

    getPattern(id) { return this.patterns[id % this.patterns.length]; },

    getDifficultyClass(d) {
        return { easy:'difficulty-easy', medium:'difficulty-medium', hard:'difficulty-hard', extreme:'difficulty-expert', expert:'difficulty-expert' }[d?.toLowerCase()] || 'difficulty-medium';
    },

    getDifficultyLabel(d) {
        return { easy:'ŁATWA', medium:'ŚREDNIA', hard:'TRUDNA', extreme:'EKSTREMALNA', expert:'EKSTREMALNA' }[d] || 'ŚREDNIA';
    },

    getRoadType(t) {
        return { road:'SZOSA', gravel:'GRAVEL', mtb:'MTB', mixed:'Mieszana' }[t?.toLowerCase()] || 'Mieszana';
    },

    formatElapsedTime(s) {
        if (!s || s <= 0) return null;
        const h = Math.floor(s/3600), m = Math.floor((s%3600)/60);
        return h > 0 ? `${h}h ${m}min` : `${m}min`;
    },

    render(route, options = {}) {
        const cfg = {
            showDelete:     options.showDelete    || false,
            showDifficulty: options.showDifficulty !== false,
            showRating:     options.showRating    !== false,
            showBadges:     options.showBadges    !== false,
            compact:        options.compact       || false,
            editUrl:        options.editUrl       || null
        };

        const id          = route.route_id || route.id;
        const name        = route.name || 'Bez nazwy';
        const tagline     = route.tagline || '';
        const distance    = parseFloat(route.distance_km || 0).toFixed(1);
        const ascent      = parseInt(route.ascent_m || 0);
        const rating      = parseFloat(route.rating || route.avg_rating || 0);
        const ratingCount = parseInt(route.rating_count || 0);
        const difficulty  = route.difficulty_level || 'medium';
        const isCompleted = route.is_completed || false;
        const isTop       = route.is_top || rating >= 4.5;
        const inChallenge = route.in_challenge || false;
        const isReference = (route.route_purpose || '') === 'reference';
        const elapsedTime = this.formatElapsedTime(route.elapsed_time_sec);
        const mb          = cfg.compact ? '8px' : '12px';

        return `
<div class="route-card" data-difficulty="${difficulty}" data-completed="${isCompleted}" style="margin-bottom:${cfg.compact?'8px':'16px'};position:relative;overflow:hidden;">

  <svg style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0.15;color:var(--color-text-light);pointer-events:none;z-index:0;" viewBox="0 0 100 100" preserveAspectRatio="none">
    ${this.getPattern(id)}
  </svg>

  <div style="position:relative;z-index:1;">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:${tagline?'4px':mb};">
      <h4 class="route-card-title" style="flex:1;">${name}</h4>
      ${cfg.showBadges ? `
      <div style="display:flex;align-items:center;gap:6px;">
        ${isReference ? `<span style="font-size:9px;font-weight:600;color:#2563eb;background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.3);border-radius:6px;padding:4px 8px;">REF</span>` : ''}
        ${isTop       ? `<div class="top-route-badge">${this.icon('star',12)}</div>` : ''}
        ${isCompleted ? `<span style="font-size:9px;font-weight:600;color:#059669;background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);border-radius:6px;padding:4px 8px;">DONE</span>` : ''}
        ${inChallenge ? `<span style="font-size:9px;font-weight:600;color:#7c3aed;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.3);border-radius:6px;padding:4px 8px;">CHALLENGE</span>` : ''}
      </div>` : ''}
    </div>

    ${tagline ? `<p style="font-size:11px;color:var(--color-text-light);margin:0 0 ${mb} 0;font-style:italic;line-height:1.4;">${tagline}</p>` : ''}
    ${cfg.showDifficulty ? `<div style="margin-bottom:${mb};"><span class="difficulty-badge ${this.getDifficultyClass(difficulty)}">${this.getDifficultyLabel(difficulty)}</span></div>` : ''}

    <div class="route-card-meta" style="margin-bottom:${mb};">
      <div class="route-card-meta-item">${this.icon('navigation',14)}<span style="font-weight:500;">${distance} km</span></div>
      <div class="route-card-meta-divider"></div>
      <div class="route-card-meta-item">${this.icon('trending-up',14)}<span style="font-weight:500;">${ascent} m</span></div>
      <div class="route-card-meta-divider"></div>
      <div class="route-card-meta-item">${this.icon('bike',14)}<span style="font-weight:500;">${this.getRoadType(route.route_type)}</span></div>
      ${elapsedTime ? `<div class="route-card-meta-divider"></div><div class="route-card-meta-item">${this.icon('clock',14)}<span style="font-weight:500;">${elapsedTime}</span></div>` : ''}
    </div>

    ${cfg.showRating && rating > 0 ? `
    <div class="rating-stars">
      ${[1,2,3,4,5].map(i=>`<img src="${window.APP_CONFIG.baseUrl}/assets/icons/star.svg" width="12" height="12" class="${i<=Math.floor(rating)?'star-filled':'star-empty'}" style="display:inline-block;" alt="">`).join('')}
      <span class="rating-count">(${ratingCount})</span>
    </div>` : ''}

  </div>

  ${cfg.showDelete ? `
  <button class="card-menu-btn" data-route-id="${id}" data-edit-url="${cfg.editUrl||''}" style="position:absolute;bottom:12px;right:12px;z-index:20;">···</button>
  ` : ''}

</div>`;
    }
};

// ============================================================================
// Singleton dropdown w body - omija overflow:hidden
// ============================================================================
(function() {
    // Stwórz jeden globalny dropdown
    const dropdown = document.createElement('div');
    dropdown.id = 'card-menu-dropdown';
    dropdown.style.cssText = `
        display:none;
        position:fixed;
        min-width:160px;
        background:white;
        border:1px solid #e5e7eb;
        border-radius:8px;
        box-shadow:0 4px 16px rgba(0,0,0,0.15);
        z-index:9999;
        overflow:hidden;
        font-size:13px;
    `;
    document.body.appendChild(dropdown);

    let currentRouteId = null;
    let currentEditUrl = null;

    function showDropdown(btn) {
        currentRouteId = btn.dataset.routeId;
        currentEditUrl = btn.dataset.editUrl || null;

        dropdown.innerHTML = `
            <button class="card-dd-item" data-action="details">Szczegóły</button>
            ${currentEditUrl
                ? `<a class="card-dd-item" href="${currentEditUrl}" style="text-decoration:none;display:block;">Edytuj w planerze</a>`
                : `<button class="card-dd-item" data-action="edit">Edytuj</button>`
            }
            <div style="height:1px;background:#f3f4f6;margin:2px 0;"></div>
            <button class="card-dd-item card-dd-danger" data-action="delete">Usuń</button>
        `;

        const rect = btn.getBoundingClientRect();
        dropdown.style.display = 'block';
        dropdown.style.top  = (rect.bottom + 4) + 'px';
        dropdown.style.left = Math.max(8, rect.left - 130) + 'px';
    }

    function hideDropdown() {
        dropdown.style.display = 'none';
        currentRouteId = null;
    }

    document.addEventListener('click', function(e) {
        // Klik w przycisk ···
        const btn = e.target.closest('.card-menu-btn');
        if (btn) {
            e.stopPropagation();
            if (dropdown.style.display === 'block' && currentRouteId === btn.dataset.routeId) {
                hideDropdown();
            } else {
                showDropdown(btn);
            }
            return;
        }

        // Klik w element dropdown
        const item = e.target.closest('.card-dd-item[data-action]');
        if (item) {
            e.stopPropagation();
            const action = item.dataset.action;
            const routeId = parseInt(currentRouteId);
            hideDropdown();

            const app = Alpine.$data(document.querySelector('[x-data="app"]'));
            if (!app) return;

            switch(action) {
                case 'details': app.openRouteDetails(routeId); break;
                case 'edit':    app.editRoute(routeId);        break;
                case 'delete':  app.deleteTrack(routeId);      break;
            }
            return;
        }

        // Klik gdziekolwiek indziej
        if (dropdown.style.display === 'block') {
            hideDropdown();
        }
    });

    // Style dla elementów dropdown
    const style = document.createElement('style');
    style.textContent = `
        .card-menu-btn {
            width:28px;height:28px;
            background:white;
            border:1px solid #e5e7eb;
            border-radius:6px;
            cursor:pointer;
            font-size:14px;
            font-weight:700;
            letter-spacing:1px;
            color:#6b7280;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .card-menu-btn:hover { background:#f3f4f6; }
        .card-dd-item {
            display:block;width:100%;
            padding:9px 14px;
            background:transparent;border:none;
            text-align:left;font-size:13px;
            color:#374151;cursor:pointer;
        }
        .card-dd-item:hover { background:#f3f4f6; }
        .card-dd-danger { color:#ef4444; }
        .card-dd-danger:hover { background:#fef2f2; }
    `;
    document.head.appendChild(style);
})();

window.renderRouteCardFallback = (route) => window.RouteCardRenderer.render(route);
console.log('✅ RouteCardRenderer v5.0 loaded');