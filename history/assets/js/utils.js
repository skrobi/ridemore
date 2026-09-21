/* ============================================================================
 KORONA ROWEROWA POLSKI - UTILS
 Funkcje pomocnicze, formatowanie, localStorage
 ============================================================================ */

const Utils = {
    /**
     * Generuje UUID v4
     * @returns {string} UUID
     */
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    },

    /**
     * Formatuje datę
     * @param {Date|string} date
     * @param {string} format - 'short' | 'long' | 'relative'
     * @returns {string}
     */
    formatDate(date, format = 'short') {
        const d = new Date(date);

        if (format === 'relative') {
            const now = new Date();
            const diff = now - d;
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);

            if (minutes < 1)
                return 'przed chwilą';
            if (minutes < 60)
                return `${minutes} min temu`;
            if (hours < 24)
                return `${hours} godz. temu`;
            if (days < 7)
                return `${days} dni temu`;
            if (days < 30)
                return `${Math.floor(days / 7)} tyg. temu`;
            return d.toLocaleDateString('pl-PL');
        }

        if (format === 'long') {
            return d.toLocaleDateString('pl-PL', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }

        return d.toLocaleDateString('pl-PL');
    },

    /**
     * Formatuje dystans
     * @param {number} km
     * @returns {string}
     */
    formatDistance(km) {
        if (km < 1) {
            return `${Math.round(km * 1000)} m`;
        }
        return `${km.toFixed(1)} km`;
    },

    /**
     * Formatuje przewyższenie
     * @param {number} meters
     * @returns {string}
     */
    formatElevation(meters) {
        return `${Math.round(meters)} m`;
    },

    /**
     * Formatuje czas trwania
     * @param {number} minutes
     * @returns {string}
     */
    formatDuration(minutes) {
        const hours = Math.floor(minutes / 60);
        const mins = Math.round(minutes % 60);

        if (hours === 0) {
            return `${mins} min`;
        }

        return `${hours}h ${mins}min`;
    },

    /**
     * Konwertuje Leaflet bounds na bbox string
     * @param {L.LatLngBounds} bounds
     * @returns {string} "minLng,minLat,maxLng,maxLat"
     */
    getBboxString(bounds) {
        const sw = bounds.getSouthWest();
        const ne = bounds.getNorthEast();
        return `${sw.lng},${sw.lat},${ne.lng},${ne.lat}`;
    },

    /**
     * Debounce function
     * @param {Function} func
     * @param {number} delay - ms
     * @returns {Function}
     */
    debounce(func, delay = 300) {
        let timeoutId;
        return function (...args) {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => func.apply(this, args), delay);
        };
    },

    /**
     * Throttle function
     * @param {Function} func
     * @param {number} delay - ms
     * @returns {Function}
     */
    throttle(func, delay = 300) {
        let lastCall = 0;
        return function (...args) {
            const now = Date.now();
            if (now - lastCall >= delay) {
                lastCall = now;
                func.apply(this, args);
            }
        };
    },

    /**
     * LocalStorage wrapper z error handling
     */
    storage: {
        /**
         * Pobiera wartość z localStorage
         * @param {string} key
         * @param {*} defaultValue
         * @returns {*}
         */
        get(key, defaultValue = null) {
            try {
                const item = localStorage.getItem(key);
                return item ? JSON.parse(item) : defaultValue;
            } catch (error) {
                console.error('Storage get error:', error);
                return defaultValue;
        }
        },

        /**
         * Zapisuje wartość do localStorage
         * @param {string} key
         * @param {*} value
         * @returns {boolean} success
         */
        set(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
                return true;
            } catch (error) {
                console.error('Storage set error:', error);
                return false;
            }
        },

        /**
         * Usuwa klucz z localStorage
         * @param {string} key
         * @returns {boolean} success
         */
        remove(key) {
            try {
                localStorage.removeItem(key);
                return true;
            } catch (error) {
                console.error('Storage remove error:', error);
                return false;
            }
        },

        /**
         * Czyści całe localStorage
         * @returns {boolean} success
         */
        clear() {
            try {
                localStorage.clear();
                return true;
            } catch (error) {
                console.error('Storage clear error:', error);
                return false;
            }
        }
    },

    /**
     * Fetch wrapper z error handling
     * @param {string} url
     * @param {object} options
     * @returns {Promise<object>}
     */
    async fetchAPI(url, options = {}) {
        try {
            // ✅ Pobierz token bezpośrednio z localStorage – NIE przez storage.get()
            // storage.get() robi JSON.parse, a JWT jest raw string
            const token = localStorage.getItem('auth_token');

            if (token) {
                options.headers = {
                    ...options.headers,
                    'Authorization': `Bearer ${token}`
                };
            }

            const response = await fetch(url, options);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            return {success: true, data};
        } catch (error) {
            console.error('Fetch error:', error);
            return {success: false, error: error.message};
        }
    },

    /**
     * Upload pliku
     * @param {string} url
     * @param {File} file
     * @param {object} additionalData
     * @returns {Promise<object>}
     */
    async uploadFile(url, file, additionalData = {}) {
        try {
            const formData = new FormData();
            formData.append('file', file);

            // Dodaj dodatkowe dane
            Object.keys(additionalData).forEach(key => {
                formData.append(key, additionalData[key]);
            });

            const token = this.storage.get('auth_token');
            const headers = {};
            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }

            const response = await fetch(url, {
                method: 'POST',
                headers,
                body: formData
            });

            if (!response.ok) {
                throw new Error(`Upload failed: ${response.statusText}`);
            }

            const data = await response.json();
            return {success: true, data};
        } catch (error) {
            console.error('Upload error:', error);
            return {success: false, error: error.message};
    }
    },

    /**
     * Parsuje plik GPX
     * @param {File} file
     * @returns {Promise<object>}
     */
    async parseGPX(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = (e) => {
                try {
                    const parser = new DOMParser();
                    const xml = parser.parseFromString(e.target.result, 'text/xml');

                    // Sprawdź czy to valid XML
                    if (xml.querySelector('parsererror')) {
                        throw new Error('Invalid GPX file');
                    }

                    // Wyciągnij podstawowe info
                    const name = xml.querySelector('trk > name')?.textContent || file.name;
                    const points = Array.from(xml.querySelectorAll('trkpt'));

                    if (points.length === 0) {
                        throw new Error('No track points found in GPX');
                    }

                    const coords = points.map(pt => ({
                            lat: parseFloat(pt.getAttribute('lat')),
                            lng: parseFloat(pt.getAttribute('lon')),
                            ele: parseFloat(pt.querySelector('ele')?.textContent || 0)
                        }));

                    resolve({
                        name,
                        points: coords,
                        pointCount: coords.length
                    });
                } catch (error) {
                    reject(error);
                }
            };

            reader.onerror = () => reject(new Error('Failed to read file'));
            reader.readAsText(file);
        });
    },

    /**
     * Oblicza dystans między dwoma punktami (Haversine)
     * @param {number} lat1
     * @param {number} lon1
     * @param {number} lat2
     * @param {number} lon2
     * @returns {number} dystans w km
     */
    calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // promień Ziemi w km
        const dLat = this.deg2rad(lat2 - lat1);
        const dLon = this.deg2rad(lon2 - lon1);

        const a =
                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(this.deg2rad(lat1)) * Math.cos(this.deg2rad(lat2)) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);

        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    },

    /**
     * Konwersja stopni na radiany
     * @param {number} deg
     * @returns {number}
     */
    deg2rad(deg) {
        return deg * (Math.PI / 180);
    },

    /**
     * Generuje rating stars HTML
     * @param {number} rating - 0-5
     * @returns {string} HTML
     */
    generateStars(rating) {
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating % 1 >= 0.5;
        const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

        let html = '';
        for (let i = 0; i < fullStars; i++) {
            html += '<i class="fas fa-star"></i>';
        }
        if (hasHalfStar) {
            html += '<i class="fas fa-star-half-alt"></i>';
        }
        for (let i = 0; i < emptyStars; i++) {
            html += '<i class="far fa-star"></i>';
        }

        return html;
    },

    /**
     * Sprawdza czy użytkownik jest na mobile
     * @returns {boolean}
     */
    isMobile() {
        return window.innerWidth <= 600;
    },

    /**
     * Pokazuje toast notification
     * @param {string} message
     * @param {string} type - 'success' | 'error' | 'info'
     */
    showToast(message, type = 'info') {
        // Prosty toast - możesz rozbudować o bibliotekę
        //console.log(`[${type.toUpperCase()}] ${message}`);

        // TODO: Dodaj wizualny toast
        alert(message);
    },

    /**
     * Road Types Helper
     * Utilities for managing road surface type percentages
     */
    roadTypes: {
        /**
         * Calculate sum of road type percentages
         * @param {Object} roadTypes - {asphalt_pct, gravel_pct, trail_pct}
         * @returns {number} Sum of percentages
         */
        getSum(roadTypes) {
            const a = parseInt(roadTypes.asphalt_pct) || 0;
            const g = parseInt(roadTypes.gravel_pct) || 0;
            const t = parseInt(roadTypes.trail_pct) || 0;
            return a + g + t;
        },

        /**
         * Check if road types are valid (sum = 100%)
         * @param {Object} roadTypes
         * @returns {boolean}
         */
        isValid(roadTypes) {
            const sum = this.getSum(roadTypes);
            return sum === 100;
        },

        /**
         * Normalize road types to sum to 100%
         * @param {Object} roadTypes - Will be modified in place
         * @returns {Object} Normalized road types
         */
        normalize(roadTypes) {
            const sum = this.getSum(roadTypes);

            if (sum === 0) {
                console.warn('Cannot normalize - sum is 0');
                return roadTypes;
            }

            const a = parseInt(roadTypes.asphalt_pct) || 0;
            const g = parseInt(roadTypes.gravel_pct) || 0;
            const t = parseInt(roadTypes.trail_pct) || 0;

            // Normalize to 100%
            roadTypes.asphalt_pct = Math.round((a / sum) * 100);
            roadTypes.gravel_pct = Math.round((g / sum) * 100);

            // Last one gets remainder to ensure exactly 100%
            roadTypes.trail_pct = 100 - roadTypes.asphalt_pct - roadTypes.gravel_pct;

            //console.log('✅ Normalized road types:', roadTypes);
            return roadTypes;
        },

        /**
         * Parse road_types_json from database
         * @param {string|Object} json - JSON string or already parsed object
         * @returns {Object} {asphalt_pct, gravel_pct, trail_pct}
         */
        parse(json) {
            if (!json) {
                return {asphalt_pct: 0, gravel_pct: 0, trail_pct: 0};
            }

            try {
                let parsed = json;

                if (typeof parsed === 'string') {
                    parsed = JSON.parse(parsed);
                }

                const roadTypes = {
                    asphalt_pct: parseInt(parsed.asphalt_pct) || 0,
                    gravel_pct: parseInt(parsed.gravel_pct) || 0,
                    trail_pct: parseInt(parsed.trail_pct) || 0
                };

                const sum = this.getSum(roadTypes);
                //console.log('✅ Parsed road types (sum=' + sum + '):', roadTypes);

                return roadTypes;

            } catch (e) {
                console.warn('⚠️ Failed to parse road_types_json:', e);
                return {asphalt_pct: 0, gravel_pct: 0, trail_pct: 0};
            }
        },

        /**
         * Convert to JSON string for database
         * @param {Object} roadTypes
         * @returns {string|null} JSON string or null if invalid
         */
        toJSON(roadTypes) {
            if (!this.isValid(roadTypes)) {
                return null;
            }

            return JSON.stringify({
                asphalt_pct: parseInt(roadTypes.asphalt_pct) || 0,
                gravel_pct: parseInt(roadTypes.gravel_pct) || 0,
                trail_pct: parseInt(roadTypes.trail_pct) || 0
            });
        },

        /**
         * Get validation error message
         * @param {Object} roadTypes
         * @returns {string|null} Error message or null if valid
         */
        getValidationError(roadTypes) {
            const sum = this.getSum(roadTypes);

            if (sum === 0) {
                return null; // Empty is OK
            }

            if (sum !== 100) {
                return `Suma typów nawierzchni musi wynosić 100% (obecnie: ${sum}%)`;
            }

            return null;
        },

        /**
         * Format for display
         * @param {Object} roadTypes
         * @returns {string} e.g. "70% asfalt, 20% gravel, 10% trasa"
         */
        format(roadTypes) {
            const parts = [];

            if (roadTypes.asphalt_pct > 0) {
                parts.push(`${roadTypes.asphalt_pct}% asfalt`);
            }
            if (roadTypes.gravel_pct > 0) {
                parts.push(`${roadTypes.gravel_pct}% gravel`);
            }
            if (roadTypes.trail_pct > 0) {
                parts.push(`${roadTypes.trail_pct}% trasa`);
            }

            return parts.join(', ') || 'Nie określono';
        }
    },
    /**
     * Skraca tekst do określonej długości
     * @param {string} text
     * @param {number} maxLength
     * @returns {string}
     */
    truncate(text, maxLength = 150) {
        if (!text)
            return '';
        if (text.length <= maxLength)
            return text;
        return text.substring(0, maxLength).trim() + '...';
    }
};

// Export do globalnego scope
window.Utils = Utils;