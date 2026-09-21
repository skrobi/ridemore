/* ============================================================================
 POI CREATOR MODULE
 Tworzenie nowych POI przez użytkowników (prawy klik na mapie + mobile FAB)
 ============================================================================ */

const PoiCreatorModule = {

    // ========================================================================
    // MOBILE POI CREATOR (from FAB)
    // ========================================================================

    /**
     * Open mobile POI creator - triggered from FAB camera button
     */
    async openMobilePoiCreator(app) {
        //console.log('📱 Opening mobile POI creator...');
        
        // Load categories if not loaded
        if (app.addPoiModal.categories.length === 0) {
            await this.loadCategories(app);
        }
        
        // Trigger camera input
        const input = document.querySelector('[x-ref="mobilePoiPhotoInput"]');
        if (input) {
            input.click();
        }
    },

    /**
     * Handle mobile photo selection with EXIF + geolocation
     */
    async handleMobilePoiPhoto(app, event) {
        const file = event.target.files[0];
        if (!file) return;

        //console.log('📷 Mobile photo selected:', file.name);

        // Validate file
        if (!file.type.match(/^image\/(jpeg|jpg|png)$/)) {
            app.addPoiModal.error = 'Dozwolone formaty: JPG, PNG';
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            app.addPoiModal.error = 'Plik zbyt duży. Maksymalny rozmiar: 10MB';
            return;
        }

        try {
            // 1. Read EXIF data
            const exifData = await this.readExifGPS(file);
            console.log('📍 EXIF GPS:', exifData);

            let lat = null;
            let lon = null;

            // 2. Try EXIF GPS first
            if (exifData.lat && exifData.lon) {
                lat = exifData.lat;
                lon = exifData.lon;
                console.log('✅ Using GPS from EXIF:', lat, lon);
            } else {
                // 3. Fallback to device geolocation
                console.log('⚠️ No GPS in EXIF, requesting device location...');
                const position = await this.getDeviceLocation();
                lat = position.coords.latitude;
                lon = position.coords.longitude;
                console.log('✅ Using device GPS:', lat, lon);
            }

            // 4. Create preview
            const reader = new FileReader();
            reader.onload = (e) => {
                // Reset and open modal
                app.addPoiModal = {
                    ...app.addPoiModal,
                    open: true,
                    mobileMode: true,
                    lat: lat,
                    lon: lon,
                    subtypes: [],
                    showExtraFields: false,
                    data: {
                        name: '',
                        category_id: '',
                        subtype_id: '',
                        description: '',
                        url: '',
                        phone: '',
                        email: '',
                        opening_hours: '',
                        address: '',
                        photo_file: file,
                        photo_preview: e.target.result
                    },
                    error: null,
                    submitting: false
                };

                console.log('✅ Mobile POI modal opened with GPS:', lat, lon);
            };
            reader.readAsDataURL(file);

        } catch (error) {
            console.error('❌ Mobile POI error:', error);
            
            // ✅ Pokaż modal z błędem
            app.addPoiModal.open = true;
            app.addPoiModal.mobileMode = false;
            app.addPoiModal.error = error.message || 'Nie udało się pobrać lokalizacji. Włącz GPS w telefonie.';
            app.addPoiModal.lat = null;
            app.addPoiModal.lon = null;
            app.addPoiModal.data.photo_preview = null;
            app.addPoiModal.data.photo_file = null;
        }

        // Clear input
        event.target.value = '';
    },

    /**
     * Read GPS from EXIF data using exif-js library
     */
    async readExifGPS(file) {
        return new Promise((resolve) => {
            // Check if EXIF library is loaded
            if (typeof EXIF === 'undefined') {
                console.warn('⚠️ EXIF library not loaded, falling back to device GPS');
                resolve({ lat: null, lon: null });
                return;
            }

            const reader = new FileReader();
            
            reader.onload = function(e) {
                try {
                    // Use EXIF.js to read data
                    const exifData = EXIF.readFromBinaryFile(e.target.result);
                    
                    if (!exifData) {
                        console.warn('⚠️ No EXIF data found');
                        resolve({ lat: null, lon: null });
                        return;
                    }

                    const lat = exifData.GPSLatitude;
                    const latRef = exifData.GPSLatitudeRef;
                    const lon = exifData.GPSLongitude;
                    const lonRef = exifData.GPSLongitudeRef;

                    if (lat && lon) {
                        // Convert to decimal degrees
                        const latDecimal = PoiCreatorModule.convertDMSToDD(lat[0], lat[1], lat[2], latRef);
                        const lonDecimal = PoiCreatorModule.convertDMSToDD(lon[0], lon[1], lon[2], lonRef);
                        
                        console.log('✅ EXIF GPS found:', latDecimal, lonDecimal);
                        resolve({ lat: latDecimal, lon: lonDecimal });
                    } else {
                        console.warn('⚠️ No GPS in EXIF');
                        resolve({ lat: null, lon: null });
                    }
                    
                } catch (err) {
                    console.warn('⚠️ EXIF parse error:', err);
                    resolve({ lat: null, lon: null });
                }
            };
            
            reader.onerror = () => {
                console.warn('⚠️ File read error');
                resolve({ lat: null, lon: null });
            };
            
            reader.readAsArrayBuffer(file);
        });
    },

    /**
     * Convert DMS (Degrees, Minutes, Seconds) to Decimal Degrees
     */
    convertDMSToDD(degrees, minutes, seconds, direction) {
        let dd = degrees + minutes / 60 + seconds / 3600;
        if (direction === 'S' || direction === 'W') {
            dd = dd * -1;
        }
        return dd;
    },

    /**
     * Get device location using Geolocation API
     */
    async getDeviceLocation() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Geolokalizacja nie jest dostępna w tej przeglądarce'));
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    resolve(position);
                },
                (error) => {
                    console.error('Geolocation error:', error);
                    let message = 'Nie udało się pobrać lokalizacji. ';
                    
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            message += 'Włącz dostęp do lokalizacji w ustawieniach przeglądarki.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            message += 'Lokalizacja nie jest dostępna.';
                            break;
                        case error.TIMEOUT:
                            message += 'Przekroczono limit czasu.';
                            break;
                        default:
                            message += 'Nieznany błąd.';
                    }
                    
                    reject(new Error(message));
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        });
    },

    // ========================================================================
    // INIT (DESKTOP - right click)
    // ========================================================================

    init(app) {
        if (!window.map) {
            console.warn('⏳ Map not ready, waiting...');

            const waitForMap = setInterval(() => {
                if (window.map) {
                    clearInterval(waitForMap);
                    this.attachContextMenu(app);
                }
            }, 100);

            return;
        }

        this.attachContextMenu(app);
    },

    attachContextMenu(app) {
        const mapEl = document.getElementById('map');

        // Prevent double attachment (init się uruchomia 2x)
        if (mapEl._poiContextMenuAttached) {
            console.log('⏭️ Context menu already attached');
            return;
        }

        console.log('🗺️ Attaching context menu to map...');

        mapEl.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            
            const currentZoom = window.map.getZoom();
            if (currentZoom < 12) {
                console.log('🔍 Zoom too low for adding POI:', currentZoom);
                return;  // Nic nie robiĘ‡ - ani menu ani modal
            }
            
            // Konwertuj pixel position na lat/lng
            const rect = mapEl.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const latlng = window.map.containerPointToLatLng([x, y]);

            console.log('🖱️ Right click at:', latlng);

            this.openAddPoiModal(app, latlng);
        });

        mapEl._poiContextMenuAttached = true;
        console.log('✅ POI Creator context menu attached');
    },

    // ========================================================================
    // MODAL (DESKTOP)
    // ========================================================================

    async openAddPoiModal(app, latlng) {
        console.log('📍 Opening Add POI modal at:', latlng);

        // Load categories if not loaded
        if (app.addPoiModal.categories.length === 0) {
            await this.loadCategories(app);
        }

        // Reset form (DESKTOP mode)
        app.addPoiModal = {
            ...app.addPoiModal,
            open: true,
            mobileMode: false, // ✅ DESKTOP
            lat: latlng.lat,
            lon: latlng.lng,
            subtypes: [],
            showExtraFields: false,
            data: {
                name: '',
                category_id: '',
                subtype_id: '',
                description: '',
                url: '',
                phone: '',
                email: '',
                opening_hours: '',
                address: '',
                photo_file: null,
                photo_preview: null
            },
            error: null,
            submitting: false
        };
    },

    // ========================================================================
    // CATEGORIES / SUBTYPES
    // ========================================================================

    async loadCategories(app) {
        try {
            const response = await fetch(window.APP_CONFIG.api('poi/categories.php'));
            const data = await response.json();

            if (data.success) {
                app.addPoiModal.categories = data.data;
                console.log('✅ Loaded', data.data.length, 'POI categories');
            }
        } catch (error) {
            console.error('❌ Failed to load categories:', error);
        }
    },

    async loadPoiSubtypes(app) {
        const categoryId = app.addPoiModal.data.category_id;

        if (!categoryId) {
            app.addPoiModal.subtypes = [];
            app.addPoiModal.data.name = ''; // Clear name
            return;
        }

        try {
            const response = await fetch(window.APP_CONFIG.api('poi/subtypes_grouped.php'));
            const data = await response.json();

            if (data.success) {
                app.addPoiModal.subtypes = data.data.filter(
                    st => st.category_id == categoryId
                );
                console.log('✅ Loaded', app.addPoiModal.subtypes.length, 'subtypes for category', categoryId);
                
                // ✅ Auto-fill name from category (mobile mode)
                if (app.addPoiModal.mobileMode && !app.addPoiModal.data.name) {
                    const category = app.addPoiModal.categories.find(c => c.category_id == categoryId);
                    if (category) {
                        app.addPoiModal.data.name = category.name;
                        console.log('✅ Auto-filled name:', category.name);
                    }
                }
            }
        } catch (error) {
            console.error('❌ Failed to load subtypes:', error);
        }
    },

    // ========================================================================
    // PHOTO (DESKTOP)
    // ========================================================================

    handlePoiPhotoSelect(app, event) {
        const file = event.target.files[0];

        if (!file) return;

        if (!file.type.match(/^image\/(jpeg|jpg|png)$/)) {
            app.addPoiModal.error = 'Dozwolone formaty: JPG, PNG';
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            app.addPoiModal.error = 'Plik zbyt duży. Maksymalny rozmiar: 10MB';
            return;
        }

        app.addPoiModal.data.photo_file = file;

        const reader = new FileReader();
        reader.onload = (e) => {
            app.addPoiModal.data.photo_preview = e.target.result;
        };
        reader.readAsDataURL(file);

        console.log('✅ Photo selected:', file.name);
    },

    removePoiPhoto(app) {
        app.addPoiModal.data.photo_file = null;
        app.addPoiModal.data.photo_preview = null;
    },

    // ========================================================================
    // SUBMIT
    // ========================================================================

    async submitNewPoi(app) {
        console.log('📤 Submitting new POI...');

        app.addPoiModal.submitting = true;
        app.addPoiModal.error = null;

        try {
            // 1. Upload photo first (if exists)
            let photoId = null;

            if (app.addPoiModal.data.photo_file) {
                photoId = await this.uploadPhoto(app, app.addPoiModal.data.photo_file);
                console.log('✅ Photo uploaded, photo_id:', photoId);
            }

            // 2. Create POI
            const poiData = {
                name: app.addPoiModal.data.name,
                category_id: parseInt(app.addPoiModal.data.category_id),
                subtype_id: app.addPoiModal.data.subtype_id ? parseInt(app.addPoiModal.data.subtype_id) : null,
                lat: app.addPoiModal.lat,
                lon: app.addPoiModal.lon,
                description: app.addPoiModal.data.description || null,
                url: app.addPoiModal.data.url || null,
                phone: app.addPoiModal.data.phone || null,
                email: app.addPoiModal.data.email || null,
                opening_hours: app.addPoiModal.data.opening_hours || null,
                address: app.addPoiModal.data.address || null,
                photo_id: photoId
            };

            const response = await fetch(window.APP_CONFIG.api('poi/create.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${localStorage.getItem('auth_token')}`
                },
                body: JSON.stringify(poiData)
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Nie udało się dodać POI');
            }

            console.log('✅ POI created:', result.data.poi_id);

            // 3. Reload POI layer
            if (window.mapManager && window.mapManager.layers.poi) {
                const bbox = window.mapManager.getBbox();
                const activeSubtypes = Array.from(
                    document.querySelectorAll('.poi-subtype-toggle:checked')
                ).map(cb => cb.dataset.subtype);

                window.mapManager.layers.poi.fetch(bbox, { subtypes: activeSubtypes })
                    .then(data => {
                        if (data) {
                            window.mapManager.layers.poi.render(data);
                        }
                    });
            }

            // 4. Close modal
            app.addPoiModal.open = false;

        } catch (error) {
            console.error('❌ Failed to create POI:', error);
            app.addPoiModal.error = error.message;
        } finally {
            app.addPoiModal.submitting = false;
        }
    },

    async uploadPhoto(app, file) {
        const formData = new FormData();
        formData.append('image', file);
        formData.append('auto_create_poi', '0');

        const response = await fetch(window.APP_CONFIG.api('photos/photos.php?action=upload'), {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${localStorage.getItem('auth_token')}`
            },
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Nie udało się przesłać zdjęcia');
        }

        return data.data.photo_id;
    }
};

window.PoiCreatorModule = PoiCreatorModule;