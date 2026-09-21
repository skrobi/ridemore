window.MedalRenderer = {
    render(data, options = {}) {
        const a = data;
        const size = options.size || 'default';
        
        // LARGE = tylko ring, bez karty
        if (size === 'large') {
            const ringSize = 120;
            const ringRadius = 54;
            const ringStroke = 4;
            const imageSize = 96;
            const iconSize = 80;
            const lockIconSize = 40;
            const checkmarkSize = 16;
            const prestigeSize = 32;
            
            return `
                <div style="text-align: center; position: relative;">
                    ${a.is_prestige ? `
                        <div style="position: absolute; top: 0; right: 50%; transform: translateX(80px); z-index: 30;">
                            <svg width="${prestigeSize}" height="${prestigeSize}" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="11" fill="white"/>
                                <path d="M12 2 L22 12 L12 22 L2 12 Z" fill="none" stroke="#fbbf24" stroke-width="2.5" stroke-linejoin="miter"/>
                                <circle cx="12" cy="12" r="2" fill="#fbbf24"/>
                            </svg>
                        </div>
                    ` : ''}
                    
                    <div style="position: relative; width: ${ringSize}px; height: ${ringSize}px; margin: 0 auto 20px;">
                        <svg style="position: absolute; top: 0; left: 0; transform: rotate(-90deg);" width="${ringSize}" height="${ringSize}" viewBox="0 0 ${ringSize} ${ringSize}">
                            <circle cx="${ringSize/2}" cy="${ringSize/2}" r="${ringRadius}" fill="none" stroke="rgba(0,0,0,0.06)" stroke-width="${ringStroke}"/>
                            <circle cx="${ringSize/2}" cy="${ringSize/2}" r="${ringRadius}" fill="none" 
                                    stroke="${a.earned ? '#10b981' : '#3b82f6'}"
                                    stroke-width="${ringStroke}" stroke-linecap="round"
                                    stroke-dasharray="${2 * Math.PI * ringRadius}"
                                    stroke-dashoffset="${2 * Math.PI * ringRadius * (1 - (a.earned ? 1 : (a.current_progress_pct || 0) / 100))}"/>
                        </svg>
                        
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: ${imageSize}px; height: ${imageSize}px; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                            ${a.earned && a.badge_image_url ? `
                                <img src="${a.badge_image_url}" alt="${a.medal_name || a.name}" style="width: 100%; height: 100%; object-fit: cover;">
                            ` : !a.earned && a.badge_image_url ? `
                                <div style="width: ${imageSize}px; height: ${imageSize}px; background: rgba(0,0,0,0.75); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <svg width="${lockIconSize}" height="${lockIconSize}" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                </div>
                            ` : a.earned && !a.badge_image_url ? `
                                <svg width="${iconSize}" height="${iconSize}" viewBox="0 0 24 24" fill="${a.badge_color || '#fbbf24'}" stroke="none">
                                    <circle cx="12" cy="8" r="7"/>
                                    <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>
                                </svg>
                            ` : `
                                <div style="width: ${imageSize}px; height: ${imageSize}px; background: rgba(0,0,0,0.75); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <svg width="${lockIconSize}" height="${lockIconSize}" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                </div>
                            `}
                        </div>
                        
                        ${a.earned ? `
                            <div style="position: absolute; top: 0; right: 0; background: #10b981; border: 3px solid white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 10;">
                                <svg width="${checkmarkSize}" height="${checkmarkSize}" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </div>
                        ` : ''}
                    </div>
                    
                    <h3 style="margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: var(--color-text);">${a.medal_name || a.name || ''}</h3>
                    
                    ${!a.is_prestige && a.tier_label ? `
                        <div style="display: inline-flex; background: white; border: 1.5px solid #3b82f6; border-radius: 8px; padding: 6px 12px; margin-bottom: 12px;">
                            <span style="font-size: 12px; font-weight: 700; color: #3b82f6;">${a.tier_label}</span>
                        </div>
                    ` : ''}
                    
                    ${!a.earned ? `
                        <div style="font-size: 32px; font-weight: 300; margin-top: 12px; color: var(--color-text);">${(a.current_progress_pct || 0).toFixed(0)}%</div>
                    ` : ''}
                </div>
            `;
        }
        
        // DEFAULT = card z obwódką (oryginalny kod)
        const sizeClass = '';
        
        return `
            <div class="medal-card ${a.earned ? 'medal-card-earned' : 'medal-card-locked'}">
                <div class="card-gradient ${a.earned ? 'gradient-earned' : 'gradient-locked'}"></div>
                <div class="badge-pattern"></div>
                
                <div style="position: relative; z-index: 2;">
                    ${a.is_prestige ? `
                        <div class="badge-prestige">
                            <svg width="24" height="24" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="11" fill="white"/>
                                <path d="M12 2 L22 12 L12 22 L2 12 Z" fill="none" stroke="#fbbf24" stroke-width="2.5" stroke-linejoin="miter"/>
                                <circle cx="12" cy="12" r="2" fill="#fbbf24"/>
                            </svg>
                        </div>
                    ` : ''}
                    
                    ${a.has_physical && !a.is_prestige ? `
                        <div class="badge-physical">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                                <circle cx="9" cy="21" r="1"/>
                                <circle cx="20" cy="21" r="1"/>
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                            </svg>
                            <span style="font-size: 8px; font-weight: 700; color: #f59e0b;">FIZYCZNY</span>
                        </div>
                    ` : ''}
                    
                    ${!a.is_prestige ? `
                        <div class="badge-tier" style="border-color: ${a.earned ? '#3b82f6' : '#d1d5db'};">
                            <span style="font-size: 10px; font-weight: 700; color: ${a.earned ? '#3b82f6' : '#9ca3af'};">
                                ${a.tier_label || ''}
                            </span>
                        </div>
                    ` : ''}
                    
                    <div class="medal-ring">
                        <svg style="position: absolute; top: 0; left: 0; transform: rotate(-90deg);" width="80" height="80" viewBox="0 0 80 80">
                            <circle cx="40" cy="40" r="36" fill="none" stroke="rgba(0,0,0,0.06)" stroke-width="3"/>
                            <circle cx="40" cy="40" r="36" fill="none" 
                                    stroke="${a.earned ? '#10b981' : '#3b82f6'}"
                                    stroke-width="3" stroke-linecap="round"
                                    stroke-dasharray="${2 * Math.PI * 36}"
                                    stroke-dashoffset="${2 * Math.PI * 36 * (1 - (a.earned ? 1 : (a.current_progress_pct || 0) / 100))}"/>
                        </svg>
                        
                        <div class="medal-image-container">
                            ${a.earned && a.badge_image_url ? `
                                <img src="${a.badge_image_url}" alt="${a.medal_name || a.name}" class="medal-image">
                            ` : !a.earned && a.badge_image_url ? `
                                <div class="medal-locked-overlay">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                </div>
                            ` : a.earned && !a.badge_image_url ? `
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="${a.badge_color || '#fbbf24'}" stroke="none">
                                    <circle cx="12" cy="8" r="7"/>
                                    <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>
                                </svg>
                            ` : `
                                <div class="medal-locked-overlay">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                </div>
                            `}
                        </div>
                        
                        ${a.earned ? `
                            <div class="medal-checkmark">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </div>
                        ` : ''}
                    </div>
                    
                    <div class="medal-name">${a.medal_name || a.name || ''}</div>
                    <div class="medal-challenge-name">${a.challenge_name || ''}</div>
                    
                    ${a.earned ? `
                        ${a.remaining_tiers > 0 ? `
                            <div style="font-size: 9px; color: #3b82f6; font-weight: 600; margin-top: 6px;">
                                🏆 +${a.remaining_tiers} poziom
                            </div>
                        ` : ''}
                    ` : `
                        <div class="medal-progress">${(a.current_progress_pct || 0).toFixed(0)}%</div>
                        ${a.next_milestone ? `
                            <div class="medal-milestone">${a.next_milestone.message}</div>
                        ` : ''}
                    `}
                </div>
            </div>
        `;
    }
};