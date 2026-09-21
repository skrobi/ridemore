/* ============================================================================
 Challenge Form JavaScript - WERSJA 2.0 - Z UPLOADEM + BEARER TOKEN
 ============================================================================ */

(function () {
    'use strict';

    // Elements
    const form = document.getElementById('challenge-form');
    const navBtns = document.querySelectorAll('.form-nav-btn');
    const sections = document.querySelectorAll('.challenge-form-section');

    // Basic info
    const nameInput = document.getElementById('challenge-name');
    const slugInput = document.getElementById('challenge-slug');

    // Routes
    const routeSearchInput = document.getElementById('route-search-input');
    const routeSearchResults = document.getElementById('route-search-results');
    const selectedRoutesContainer = document.getElementById('selected-routes-container');
    const routesEmptyState = document.getElementById('routes-empty-state');
    const routeIdsHidden = document.getElementById('route-ids-hidden');

    // Upload GPX
    const uploadGpxBtn = document.getElementById('upload-gpx-btn');
    const gpxFileInput = document.getElementById('gpx-file-input');
    const uploadProgress = document.getElementById('upload-progress');
    const progressFill = uploadProgress?.querySelector('.progress-fill');
    const progressText = uploadProgress?.querySelector('.progress-text');

    // Upload Challenge Image
    const uploadChallengeImageBtn = document.getElementById('upload-challenge-image-btn');
    const challengeImageFile = document.getElementById('challenge-image-file');
    const challengeImageUrl = document.getElementById('challenge-image-url');
    const challengeImagePreview = document.getElementById('challenge-image-preview');

    // Medals
    const addMedalBtn = document.getElementById('add-medal-btn');
    const medalsContainer = document.getElementById('medals-container');
    const medalsEmptyState = document.getElementById('medals-empty-state');
    const medalsDataHidden = document.getElementById('medals-data-hidden');

    let searchTimeout = null;
    let selectedRouteIds = window.challengeFormData?.selectedRoutes || [];

    // =========================================================================
    // NAVIGATION
    // =========================================================================

    navBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const sectionName = this.dataset.section;
            switchSection(sectionName);
        });
    });

    function switchSection(sectionName) {
        sections.forEach(section => {
            section.classList.remove('active');
        });

        navBtns.forEach(btn => {
            btn.classList.remove('active');
        });

        const targetSection = document.querySelector(`.challenge-form-section[data-section="${sectionName}"]`);
        const targetBtn = document.querySelector(`.form-nav-btn[data-section="${sectionName}"]`);

        if (targetSection)
            targetSection.classList.add('active');
        if (targetBtn)
            targetBtn.classList.add('active');
    }

    // =========================================================================
    // AUTO SLUG GENERATION
    // =========================================================================

    if (nameInput && slugInput) {
        nameInput.addEventListener('input', function () {
            if (!slugInput.value || slugInput.dataset.auto === 'true') {
                slugInput.value = generateSlug(this.value);
                slugInput.dataset.auto = 'true';
            }
        });

        slugInput.addEventListener('input', function () {
            delete this.dataset.auto;
        });
    }

    function generateSlug(text) {
        return text
                .toLowerCase()
                .replace(/ą/g, 'a').replace(/ć/g, 'c').replace(/ę/g, 'e')
                .replace(/ł/g, 'l').replace(/ń/g, 'n').replace(/ó/g, 'o')
                .replace(/ś/g, 's').replace(/ź|ż/g, 'z')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
    }

    // =========================================================================
    // AUTH TOKEN HELPER - POBIERA Z COOKIE
    // =========================================================================

    function getAuthToken() {
        return getCookie('auth_token');
    }

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2)
            return parts.pop().split(';').shift();
        return null;
    }

    // =========================================================================
    // IMAGE UPLOAD - CHALLENGE
    // =========================================================================

    if (uploadChallengeImageBtn && challengeImageFile) {
        uploadChallengeImageBtn.addEventListener('click', function () {
            challengeImageFile.click();
        });

        challengeImageFile.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                uploadImage(file, 'challenge', challengeImageUrl, challengeImagePreview);
            }
        });
    }

    // =========================================================================
    // IMAGE UPLOAD - MEDALS (delegated)
    // =========================================================================

    medalsContainer.addEventListener('click', function (e) {
        const uploadBtn = e.target.closest('.upload-medal-image-btn');
        if (uploadBtn) {
            const medalRow = uploadBtn.closest('.medal-row-item');
            const field = uploadBtn.dataset.field;
            const type = uploadBtn.dataset.type;

            // Create temporary file input
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.style.display = 'none';

            fileInput.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (file) {
                    const targetInput = medalRow.querySelector(`[data-medal-field="${field}"]`);
                    uploadImage(file, type, targetInput, null);
                }
            });

            document.body.appendChild(fileInput);
            fileInput.click();
            document.body.removeChild(fileInput);
        }
    });

    function uploadImage(file, type, targetInput, previewElement) {
        const baseUrl = window.challengeFormData?.baseUrl || '';
        const authToken = getAuthToken();

        if (!authToken) {
            alert('Brak autoryzacji. Zaloguj się ponownie.');
            return;
        }

        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            alert('Nieprawidłowy typ pliku. Dozwolone: JPG, PNG, GIF, WEBP');
            return;
        }

        // Validate file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('Plik za duży. Maksymalnie 5MB');
            return;
        }

        const formData = new FormData();
        formData.append('image', file);
        formData.append('type', type);

        // Show loading
        if (targetInput) {
            targetInput.value = 'Uploading...';
            targetInput.disabled = true;
        }

        fetch(`${baseUrl}/api/manage/upload-image.php`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${authToken}`
            },
            body: formData
        })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const relativeUrl = data.data.url;

                        if (targetInput) {
                            targetInput.value = relativeUrl;
                            targetInput.disabled = false;
                        }

                        if (previewElement) {
                            previewElement.src = baseUrl + '/' + relativeUrl;
                            previewElement.style.display = 'block';
                        }

                        alert('Upload zakończony pomyślnie!');
                    } else {
                        throw new Error(data.error || 'Upload failed');
                    }
                })
                .catch(err => {
                    console.error('Upload error:', err);
                    alert('Błąd uploadu: ' + err.message);

                    if (targetInput) {
                        targetInput.value = '';
                        targetInput.disabled = false;
                    }
                });
    }

    // =========================================================================
    // GPX UPLOAD
    // =========================================================================

    if (uploadGpxBtn && gpxFileInput) {
        uploadGpxBtn.addEventListener('click', function () {
            gpxFileInput.click();
        });

        gpxFileInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                uploadGPXFile(file);
            }
        });
    }

    function uploadGPXFile(file) {
        if (!file.name.toLowerCase().endsWith('.gpx')) {
            alert('Tylko pliki GPX są dozwolone');
            return;
        }

        const baseUrl = window.challengeFormData?.baseUrl || '';
        const formData = new FormData();
        formData.append('file', file);
        formData.append('source', 'gpx_upload');
        formData.append('route_purpose', 'user_shared');
        formData.append('visibility', 'public');
        formData.append('name', file.name.replace('.gpx', ''));
        formData.append('route_type', 'challenge');
        formData.append('check_duplicates', '1');

        if (uploadProgress) {
            uploadProgress.style.display = 'block';
            progressFill.style.width = '0%';
            progressText.textContent = 'Uploading...';
        }
        uploadGpxBtn.disabled = true;

        let progress = 0;
        const progressInterval = setInterval(() => {
            progress += 10;
            if (progress <= 90 && progressFill) {
                progressFill.style.width = progress + '%';
            }
        }, 200);

        const authToken = getAuthToken();

        if (!authToken) {
            clearInterval(progressInterval);
            alert('Brak tokenu autoryzacji. Zaloguj się ponownie.');
            if (uploadProgress)
                uploadProgress.style.display = 'none';
            uploadGpxBtn.disabled = false;
            return;
        }

        fetch(`${baseUrl}/api/routes/upload.php`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${authToken}`
            },
            body: formData
        })
                .then(res => {
                    if (!res.ok) {
                        return res.json().then(data => {
                            throw new Error(data.error || `HTTP ${res.status}`);
                        });
                    }
                    return res.json();
                })
                .then(data => {
                    clearInterval(progressInterval);
                    if (progressFill)
                        progressFill.style.width = '100%';

                    if (data.success) {
                        if (progressText)
                            progressText.textContent = 'Upload complete!';

                        setTimeout(() => {
                            window.challengeForm.addRoute(
                                    data.data.route_id,
                                    data.data.data.name,
                                    data.data.data.distance_km,
                                    data.data.data.ascent_m
                                    );

                            if (uploadProgress)
                                uploadProgress.style.display = 'none';
                            gpxFileInput.value = '';
                            uploadGpxBtn.disabled = false;
                        }, 1000);
                    } else {
                        throw new Error(data.error || 'Upload failed');
                    }
                })
                .catch(err => {
                    clearInterval(progressInterval);
                    console.error('Upload error:', err);

                    let errorMsg = 'Błąd uploadu: ' + err.message;
                    if (err.message.includes('401') || err.message.includes('token')) {
                        errorMsg = 'Sesja wygasła. Odśwież stronę i zaloguj się ponownie.';
                    }

                    alert(errorMsg);
                    if (uploadProgress)
                        uploadProgress.style.display = 'none';
                    gpxFileInput.value = '';
                    uploadGpxBtn.disabled = false;
                });
    }

    // =========================================================================
    // ROUTE SEARCH
    // =========================================================================

    if (routeSearchInput) {
        routeSearchInput.addEventListener('input', function () {
            const query = this.value.trim();

            clearTimeout(searchTimeout);

            if (query.length < 2) {
                hideSearchResults();
                return;
            }

            searchTimeout = setTimeout(() => {
                searchRoutes(query);
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.route-search-box')) {
                hideSearchResults();
            }
        });
    }

    function searchRoutes(query) {
        const baseUrl = window.challengeFormData?.baseUrl || '';

        fetch(`${baseUrl}/api/manage/search-routes.php?q=${encodeURIComponent(query)}`, {
            credentials: 'include'
        })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        renderSearchResults(data.data);
                    } else {
                        showEmptySearchResults();
                    }
                })
                .catch(err => {
                    console.error('Search error:', err);
                    showSearchError();
                });
    }

    function renderSearchResults(routes) {
        let html = '';

        routes.forEach(route => {
            const isSelected = selectedRouteIds.includes(route.route_id);
            const disabledClass = isSelected ? 'disabled' : '';
            const onClick = isSelected ? '' : `onclick="window.challengeForm.addRoute(${route.route_id}, '${escapeHtml(route.name)}', ${route.distance_km}, ${route.ascent_m})"`;

            html += `
                <div class="route-search-result ${disabledClass}" ${onClick}>
                    <div class="route-search-result-info">
                        <div class="route-search-result-name">${escapeHtml(route.name)}</div>
                        <div class="route-search-result-meta">${route.distance_km} km • ${route.ascent_m} m</div>
                    </div>
                    <i class="fas fa-${isSelected ? 'check' : 'plus'}"></i>
                </div>
            `;
        });

        routeSearchResults.innerHTML = html;
        routeSearchResults.classList.add('show');
    }

    function showEmptySearchResults() {
        routeSearchResults.innerHTML = '<div class="route-search-empty">Brak wyników</div>';
        routeSearchResults.classList.add('show');
    }

    function showSearchError() {
        routeSearchResults.innerHTML = '<div class="route-search-empty">Błąd wyszukiwania</div>';
        routeSearchResults.classList.add('show');
    }

    function hideSearchResults() {
        routeSearchResults.classList.remove('show');
    }

    // =========================================================================
    // ROUTES MANAGEMENT
    // =========================================================================

    window.challengeForm = {
        addRoute: function (id, name, distance, ascent) {
            if (selectedRouteIds.includes(id))
                return;
            console.log('dodanie wiersza');
            selectedRouteIds.push(id);

            const html = `
                <div class="selected-route-item" data-route-id="${id}">
                    <div class="route-item-info">
                        <div class="route-item-name">${escapeHtml(name)}</div>
                        <div class="route-item-meta">${distance} km • ${ascent} m</div>
                    </div>
                    <div style="display: flex; gap: 4px;">
                        <button type="button" class="btn-icon" onclick="window.openEditRouteModal(${id})" title="Edytuj">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn-icon btn-danger remove-route-btn">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;

            selectedRoutesContainer.insertAdjacentHTML('beforeend', html);
            routesEmptyState.style.display = 'none';
            routeSearchInput.value = '';
            hideSearchResults();
            updateRouteIds();
        }
    };

    selectedRoutesContainer.addEventListener('click', function (e) {
        const removeBtn = e.target.closest('.remove-route-btn');
        if (removeBtn) {
            const routeItem = removeBtn.closest('.selected-route-item');
            const routeId = parseInt(routeItem.dataset.routeId);

            selectedRouteIds = selectedRouteIds.filter(id => id !== routeId);
            routeItem.remove();

            if (selectedRoutesContainer.children.length === 0) {
                routesEmptyState.style.display = 'block';
            }

            updateRouteIds();
        }
    });

    function updateRouteIds() {
        routeIdsHidden.value = JSON.stringify(selectedRouteIds);
    }

    // =========================================================================
    // EDIT ROUTE MODAL
    // =========================================================================

    window.openEditRouteModal = function (routeId) {
        const baseUrl = window.challengeFormData?.baseUrl || '';
        const authToken = getAuthToken();

        if (!authToken) {
            alert('Brak autoryzacji. Zaloguj się ponownie.');
            return;
        }

        fetch(`${baseUrl}/api/routes/details.php?id=${routeId}`, {
            headers: {
                'Authorization': `Bearer ${authToken}`
            }
        })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const route = data.data || data;

                        document.getElementById('edit-route-id').value = routeId;
                        document.getElementById('edit-route-name').value = route.name || '';
                        document.getElementById('edit-route-description').value = route.description || '';
                        document.getElementById('edit-route-type').value = route.route_type || '';
                        document.getElementById('edit-route-difficulty').value = route.difficulty_level || '';

                        document.getElementById('edit-route-modal').style.display = 'flex';
                    } else {
                        throw new Error(data.error || 'Failed to load route');
                    }
                })
                .catch(err => {
                    console.error('Load route error:', err);
                    alert('Nie udało się załadować danych trasy: ' + err.message);
                });
    };

    window.closeEditRouteModal = function () {
        document.getElementById('edit-route-modal').style.display = 'none';
    };

    window.saveRouteEdit = function () {
        const routeId = document.getElementById('edit-route-id').value;
        const baseUrl = window.challengeFormData?.baseUrl || '';
        const authToken = getAuthToken();

        if (!authToken) {
            alert('Brak autoryzacji. Zaloguj się ponownie.');
            return;
        }

        const data = {
            name: document.getElementById('edit-route-name').value,
            description: document.getElementById('edit-route-description').value,
            route_type: document.getElementById('edit-route-type').value,
            difficulty_level: document.getElementById('edit-route-difficulty').value
        };

        fetch(`${baseUrl}/api/routes/update.php?id=${routeId}`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${authToken}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        alert('Trasa zaktualizowana');
                        closeEditRouteModal();

                        const routeItem = document.querySelector(`[data-route-id="${routeId}"]`);
                        if (routeItem) {
                            const nameEl = routeItem.querySelector('.route-item-name');
                            if (nameEl) {
                                nameEl.textContent = data.name;
                            }
                        }
                    } else {
                        throw new Error(result.error || 'Update failed');
                    }
                })
                .catch(err => {
                    console.error('Update error:', err);
                    alert('Błąd aktualizacji: ' + err.message);
                });
    };

    // =========================================================================
    // MEDALS MANAGEMENT
    // =========================================================================

    if (addMedalBtn) {
        addMedalBtn.addEventListener('click', addMedalRow);
    }

    function addMedalRow() {
        const html = `
            <div class="medal-row-item">
                <input type="text" placeholder="Nazwa" class="form-input" data-medal-field="name">
                <input type="text" placeholder="Poziom" class="form-input" data-medal-field="level">
                <input type="number" placeholder="Min %" class="form-input" data-medal-field="min" min="0" max="100" step="0.01">
                <input type="number" placeholder="Max %" class="form-input" data-medal-field="max" min="0" max="100" step="0.01">
                <input type="text" placeholder="Ikona" class="form-input" data-medal-field="icon" maxlength="10" value="🏅">
                <input type="color" class="form-input" data-medal-field="color" value="#ffd700">
                <div class="medal-image-upload">
                    <input type="text" placeholder="uploads/medals/..." class="form-input" data-medal-field="badge_image_url" readonly>
                    <button type="button" class="btn-icon upload-medal-image-btn" data-field="badge_image_url" data-type="medal" title="Upload odznaki">
                        <i class="fas fa-upload"></i>
                    </button>
                </div>
                <label class="form-checkbox">
                    <input type="checkbox" data-medal-field="has_physical">
                    <span>Fizyczny</span>
                </label>
                <input type="number" placeholder="Cena PLN" class="form-input" data-medal-field="price" min="0" step="0.01">
                <div class="medal-image-upload">
                    <input type="text" placeholder="uploads/medals/..." class="form-input" data-medal-field="physical_image_url" readonly>
                    <button type="button" class="btn-icon upload-medal-image-btn" data-field="physical_image_url" data-type="medal" title="Upload fizycznego">
                        <i class="fas fa-upload"></i>
                    </button>
                </div>
                <button type="button" class="btn-icon btn-danger remove-medal-btn">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;

        medalsContainer.insertAdjacentHTML('beforeend', html);
        medalsEmptyState.style.display = 'none';
    }

    medalsContainer.addEventListener('click', function (e) {
        const removeBtn = e.target.closest('.remove-medal-btn');
        if (removeBtn) {
            removeBtn.closest('.medal-row-item').remove();

            if (medalsContainer.children.length === 0) {
                medalsEmptyState.style.display = 'block';
            }
        }
    });

    function collectMedalsData() {
        const medals = [];

        medalsContainer.querySelectorAll('.medal-row-item').forEach(row => {
            medals.push({
                name: row.querySelector('[data-medal-field="name"]').value,
                level: row.querySelector('[data-medal-field="level"]').value,
                min: parseFloat(row.querySelector('[data-medal-field="min"]').value) || 0,
                max: parseFloat(row.querySelector('[data-medal-field="max"]').value) || 100,
                icon: row.querySelector('[data-medal-field="icon"]').value || '🏅',
                color: row.querySelector('[data-medal-field="color"]').value,
                badge_image_url: row.querySelector('[data-medal-field="badge_image_url"]').value || '',
                has_physical: row.querySelector('[data-medal-field="has_physical"]').checked ? 1 : 0,
                price: parseFloat(row.querySelector('[data-medal-field="price"]').value) || null,
                physical_image_url: row.querySelector('[data-medal-field="physical_image_url"]').value || ''
            });
        });

        return medals;
    }

    // =========================================================================
    // FORM SUBMIT
    // =========================================================================

    if (form) {
        form.addEventListener('submit', function (e) {
            updateRouteIds();
            medalsDataHidden.value = JSON.stringify(collectMedalsData());
        });
    }

    // =========================================================================
    // UTILS
    // =========================================================================

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // =========================================================================
    // INIT
    // =========================================================================

    if (window.challengeFormData?.selectedRoutes && window.challengeFormData.selectedRoutes.length > 0) {
        selectedRouteIds = [...window.challengeFormData.selectedRoutes];
    } else {
        const existingRoutes = document.querySelectorAll('.selected-route-item[data-route-id]');
        selectedRouteIds = [];

        existingRoutes.forEach(item => {
            const routeId = parseInt(item.dataset.routeId);
            if (routeId && !selectedRouteIds.includes(routeId)) {
                selectedRouteIds.push(routeId);
            }
        });
    }

    updateRouteIds();

})();