/* ============================================================================
 ROUTE STYLES - 3-Layer Rendering System
 Based on: Komoot-style visibility + layer-based coloring
 Version: 2.1 - Fixed hover z-index over selected
 ============================================================================ */

window.RouteStyles = {

    /**
     * Render 3-layer system for routes
     * @param {object} layerConfig - Layer config from API (with style object)
     * @param {number} zoom - Current map zoom level
     * @param {string} state - 'normal'|'hover'|'selected'|'card-hover'
     * @param {object} routeProps - Route properties (for completed state)
     * @returns {array} Array of 3 Leaflet polyline configs [outline, main, core]
     */
    render3Layer(layerConfig, zoom, state = 'normal', routeProps = {}) {
        const baseStyle = layerConfig.style || {};
        const weights = this.getZoomWeights(zoom);
        const isCompleted = routeProps.is_completed || false;
        const pane = this.getPane(state, layerConfig.slug);

        let opacityModifier = 1.0;
        if (isCompleted) {
            opacityModifier = 0.4;
        }

        // ========================================================================
        // CARD-HOVER STATE - Pomarańczowy highlight z kart (NAJWYŻSZY PRIORYTET!)
        // ========================================================================
        if (state === 'card-hover') {
            return {
                outline: {
                    color: '#ffffff',
                    weight: weights.outline + 3, // ✅ Grubszy niż selected
                    opacity: 1.0,
                    lineCap: 'round',
                    lineJoin: 'round',
                    className: 'route-outline route-card-hover-outline',
                    interactive: false,
                    pane: pane  // ✅ cardHoverPane z-index: 550
                },
                main: {
                    color: '#f97316', // ✅ POMARAŃCZOWY (Tailwind orange-500)
                    weight: weights.main + 3, // ✅ Grubszy
                    opacity: 1.0,
                    lineCap: 'round',
                    lineJoin: 'round',
                    className: 'route-main route-card-hover',
                    interactive: true,
                    pane: pane
                },
                core: {
                    color: '#1f2937', // Ciemny jak zawsze
                    weight: weights.core + 1.5, // ✅ Grubszy
                    opacity: 0.95,
                    lineCap: 'round',
                    lineJoin: 'round',
                    dashArray: this.getCorePattern(layerConfig.slug, baseStyle.style),
                    className: 'route-core route-card-hover-core',
                    interactive: false,
                    pane: pane
                }
            };
        }

        // ========================================================================
        // SELECTED STATE - KOMOOT STYLE (czerwony pulsujący)
        // ========================================================================
        if (state === 'selected') {
            return {
                outline: {
                    color: '#ffffff',
                    weight: weights.outline + 3,
                    opacity: 1.0,
                    lineCap: 'round',
                    lineJoin: 'round',
                    className: 'route-outline',
                    interactive: false,
                    pane: pane
                },
                main: {
                    color: '#ef4444', // ✅ Czerwony (selected zawsze czerwony)
                    weight: weights.main + 2,
                    opacity: 1.0,
                    lineCap: 'round',
                    lineJoin: 'round',
                    className: 'route-main route-selected',
                    interactive: true,
                    pane: pane
                },
                core: {
                    color: '#1f2937', // ✅ ZAWSZE CIEMNY (nie baseStyle.color!)
                    weight: weights.core + 1,
                    opacity: 0.9,
                    lineCap: 'round',
                    lineJoin: 'round',
                    dashArray: this.getCorePattern(layerConfig.slug, baseStyle.style),
                    className: 'route-core',
                    interactive: false,
                    pane: pane
                }
            };
        }

        // ========================================================================
        // HOVER STATE (map hover)
        // ========================================================================
        if (state === 'hover') {
            return {
                outline: {
                    color: '#ffffff',
                    weight: weights.outline + 1,
                    opacity: 0.9,
                    lineCap: 'round',
                    lineJoin: 'round',
                    className: 'route-outline',
                    interactive: false,
                    pane: pane
                },
                main: {
                    color: baseStyle.color, // ✅ Kolor warstwy (hover pokazuje oryginalny)
                    weight: weights.main + 1,
                    opacity: 1.0,
                    lineCap: 'round',
                    lineJoin: 'round',
                    className: 'route-main',
                    interactive: true,
                    pane: pane
                },
                core: {
                    color: '#1f2937', // ✅ ZAWSZE CIEMNY
                    weight: weights.core,
                    opacity: 0.8 * opacityModifier,
                    lineCap: 'round',
                    lineJoin: 'round',
                    dashArray: this.getCorePattern(layerConfig.slug, baseStyle.style),
                    className: 'route-core',
                    interactive: false,
                    pane: pane
                }
            };
        }

        // ========================================================================
        // NORMAL STATE
        // ========================================================================
        return {
            outline: {
                color: '#ffffff',
                weight: weights.outline,
                opacity: 0.5 * opacityModifier,
                lineCap: 'round',
                lineJoin: 'round',
                className: 'route-outline',
                interactive: false,
                pane: pane
            },
            main: {
                color: baseStyle.color, // ✅ Kolor warstwy (normal pokazuje oryginalny)
                weight: weights.main,
                opacity: (baseStyle.opacity || 0.8) * opacityModifier,
                lineCap: 'round',
                lineJoin: 'round',
                className: 'route-main',
                interactive: true,
                pane: pane
            },
            core: {
                color: '#1f2937', // ✅ ZAWSZE CIEMNY
                weight: weights.core,
                opacity: 0.7 * opacityModifier,
                lineCap: 'round',
                lineJoin: 'round',
                dashArray: this.getCorePattern(layerConfig.slug, baseStyle.style),
                className: 'route-core',
                interactive: false,
                pane: pane
            }
        };
    },
    /**
     * Challenge ghost routes - semi-transparent reference
     */
    getChallengeGhostStyles(zoom, isCompleted = false) {
        const weights = this.getZoomWeights(zoom);
        const color = isCompleted ? '#10b981' : '#8b5cf6';

        return {
            halo: {
                color: '#ffffff',
                weight: weights.outline + 2, // większa niż outline
                opacity: 0.8,
                lineCap: 'round',
                lineJoin: 'round',
                pane: 'challengePane',
                interactive: false
            },
            outline: {
                color: color,
                weight: weights.main + 1,
                opacity: 0.95,
                lineCap: 'round',
                lineJoin: 'round',
                pane: 'challengePane',
                interactive: false
            },
            main: {
                color: '#ffffff', // ✅ biały środek (ghost)
                weight: weights.core,
                opacity: 0.7,
                dashArray: '8, 5',
                lineCap: 'round',
                lineJoin: 'round',
                pane: 'challengePane',
                interactive: true
            }
        };
    },

    getPane(state, layerSlug) {
        // ✅ NOWA HIERARCHIA Z-INDEX:
        // cardHoverPane: 550 (najwyższy - card hover)
        // selectedPane: 500 (selected route)
        // hoverPane: 450 (map hover)
        // overlayPane: 400 (normal routes)

        if (state === 'card-hover')
            return 'cardHoverPane';  // ✅ NAJWYŻSZY!

        if (state === 'selected')
            return 'selectedPane';

        if (state === 'hover')
            return 'hoverPane';

        // Normal state: różne panes dla różnych warstw
        return 'overlayPane'; // 400
    },

    /**
     * Initialize custom panes on map
     * Call once when map is created
     */
    initializePanes(map) {
        if (map.getPane('overlayPane')) {
            map.getPane('overlayPane').style.zIndex = 400;
        }

        if (!map.getPane('hoverPane')) {
            const hoverPane = map.createPane('hoverPane');
            hoverPane.style.zIndex = 450;
            console.log('✅ Created hoverPane with z-index 450');
        }

        if (!map.getPane('selectedPane')) {
            const selectedPane = map.createPane('selectedPane');
            selectedPane.style.zIndex = 500;
            console.log('✅ Created selectedPane with z-index 500');
        }

        // ✅ NOWY PANE - card hover (najwyższy priorytet!)
        if (!map.getPane('cardHoverPane')) {
            const cardHoverPane = map.createPane('cardHoverPane');
            cardHoverPane.style.zIndex = 550;
            console.log('✅ Created cardHoverPane with z-index 550 (HIGHEST)');
        }

        if (!map.getPane('coveragePane')) {
            const coveragePane = map.createPane('coveragePane');
            coveragePane.style.zIndex = 650;
            console.log('✅ Created coveragePane with z-index 650');
        }
    },

    /**
     * Get zoom-dependent weights
     * Larger lines at small zoom for visibility
     */
    getZoomWeights(zoom) {
        if (zoom < 10) {
            return {
                outline: 8,
                main: 5,
                core: 2
            };
        } else if (zoom >= 10 && zoom < 14) {
            return {
                outline: 7,
                main: 4.5,
                core: 1.8
            };
        } else {
            return {
                outline: 6,
                main: 4,
                core: 1.5
            };
        }
    },

    /**
     * Get core pattern based on layer slug
     * Official = solid, User = dashed, Planned = dotted
     */
    getCorePattern(layerSlug, styleFromDB) {
        // Priority: DB setting > slug-based default
        if (styleFromDB) {
            const patterns = {
                'solid': null,
                'dashed': '5, 3',
                'dotted': '2, 2'
            };
            return patterns[styleFromDB] || null;
        }

        // Fallback: slug-based defaults
        const slugPatterns = {
            'official_routes': null, // solid - trusted
            'user_routes': '5, 3', // dashed - community
            'planned_routes': '2, 2'       // dotted - algorithmic
        };

        return slugPatterns[layerSlug] || null;
    },

    /**
     * Apply state modifiers to existing style
     * Used for hover/selection state changes
     */
    applyStateModifier(baseStyle, state) {
        const modified = {...baseStyle};

        if (state === 'hover') {
            modified.weight = (modified.weight || 4) + 2;
            modified.opacity = 1.0;
        }

        if (state === 'selected') {
            modified.color = '#ef4444';
            modified.weight = (modified.weight || 4) + 3;
            modified.opacity = 1.0;
        }

        if (state === 'card-hover') {
            modified.color = '#f97316';
            modified.weight = (modified.weight || 4) + 3;
            modified.opacity = 1.0;
        }

        return modified;
    },

    /**
     * Get route type icon (for UI, NOT map)
     * Used in route cards and tooltips
     */
    getRouteTypeIcon(type) {
        const icons = {
            'road': '🚴',
            'gravel': '🚵',
            'mtb': '⛰️',
            'mixed': '🔀'
        };
        return icons[type] || '🚴';
    },

    /**
     * LEGACY: Single-layer style (for backwards compatibility)
     * Use render3Layer() for new implementations
     */
    getStyle(route, state = 'normal') {
        console.warn('RouteStyles.getStyle() is deprecated. Use render3Layer() instead.');

        return {
            color: state === 'selected' ? '#ef4444' : '#00d4ff',
            weight: state === 'hover' ? 6 : 4,
            opacity: state === 'selected' ? 1.0 : 0.8,
            lineCap: 'round',
            lineJoin: 'round'
        };
    },

    /**
     * Coverage visualization styles
     * Używane przez RouteCoverageVisualizer
     */
    getCoverageStyles() {
        return {
            // Challenge baseline (purple, thick)
            challengeBaseline: {
                color: '#8b5cf6',
                weight: 6,
                opacity: 0.8,
                lineCap: 'round',
                lineJoin: 'round'
            },

            // Full activity route (blue dashed)
            activityFull: {
                color: '#3b82f6',
                weight: 2,
                opacity: 0.4,
                lineCap: 'round',
                lineJoin: 'round',
                dashArray: '5, 5'
            },

            // Matched vectors (green)
            vectorMatched: {
                color: '#ff29db',
                weight: 4,
                opacity: 0.8,
                lineCap: 'round'
            },

            // Unmatched vectors (red) - currently unused
            vectorUnmatched: {
                color: '#ef4444',
                weight: 2,
                opacity: 0.3,
                lineCap: 'round'
            }
        };
    }
};

// CSS for pulsing animation on selected routes
if (typeof document !== 'undefined') {
    const style = document.createElement('style');
    style.textContent = `
    @keyframes route-pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.7; }
    }
    
    .route-selected {
      animation: route-pulse 2s ease-in-out infinite;
    }
    
    /* ✅ Card hover animation - bardziej dynamiczna */
    @keyframes card-hover-pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.85; }
    }
    
    .route-card-hover {
      animation: card-hover-pulse 1.5s ease-in-out infinite;
    }
  `;
    document.head.appendChild(style);
}

console.log('✅ RouteStyles v2.1 loaded (fixed z-index hierarchy)');