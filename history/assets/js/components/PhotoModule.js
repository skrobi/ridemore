/* ============================================================================
 PHOTO MODULE - Universal photo management
 Version: 2.0 - REFACTORED
 
 Handles photo operations for ANY entity type (route, challenge, poi, user, etc)
 ============================================================================ */

window.PhotoModule = {

    // State
    uploading: false,
    uploadProgress: 0,

    // ========================================================================
    // HIGH-LEVEL API - Universal methods for ANY entity
    // ========================================================================

    /**
     * Open file picker, upload, and associate with entity
     * Universal method - works for route, challenge, poi, user, etc.
     * 
     * @param {string} entityType - 'route', 'challenge', 'poi', 'user', 'achievement', 'activity'
     * @param {number} entityId - ID of the entity
     * @param {Object} options - {multiple, auto_create_poi, is_public}
     * @returns {Promise<Object>} - {uploaded, associated, failed, errors}
     */
    async pickAndUpload(entityType, entityId, options = {}) {
        console.log(`📸 Pick & upload for ${entityType} ${entityId}`);

        if (!entityId) {
            throw new Error(`Invalid entityId: ${entityId}`);
        }

        try {
            // Step 1: Open file picker
            const files = await this.openFileDialog({
                multiple: options.multiple ?? true
            });

            if (files.length === 0) {
                return null;
            }

            // Step 2: Upload and associate
            return await this.uploadAndAssociate(files, entityType, entityId, options);

        } catch (error) {
            console.error('❌ Pick & upload error:', error);
            throw error;
    }
    },

    /**
     * Upload multiple files and associate with entity
     * Core universal method
     * 
     * @param {Array<File>} files - Files to upload
     * @param {string} entityType - Entity type
     * @param {number} entityId - Entity ID
     * @param {Object} options - Upload options
     * @returns {Promise<Object>} - Upload results
     */
    /**
     * Upload multiple files and associate with entity
     */
    async uploadAndAssociate(files, entityType, entityId, options = {}) {
        console.log('═══════════════════════════════════════════════════');
        console.log('📸 DEBUG: uploadAndAssociate() called');
        console.log('   files:', files.length);
        console.log('   entityType:', entityType);
        console.log('   entityId:', entityId);
        console.log('   options:', options);
        console.log('═══════════════════════════════════════════════════');

        // Validate
        if (!entityId) {
            throw new Error(`Invalid entityId: ${entityId}`);
        }

        const allowedTypes = ['route', 'challenge', 'poi', 'user', 'achievement', 'activity'];
        if (!allowedTypes.includes(entityType)) {
            throw new Error(`Invalid entityType: ${entityType}. Allowed: ${allowedTypes.join(', ')}`);
        }

        this.uploading = true;
        this.uploadProgress = 0;

        try {
            // Step 1: Upload all photos
            console.log('📤 Step 1: Starting upload...');
            const uploadResults = await this.uploadMultiple(files, {
                auto_create_poi: options.auto_create_poi ?? true,
                is_public: options.is_public ?? 1
            });

            console.log('✅ Upload results:', uploadResults);
            console.log('   Uploaded:', uploadResults.uploaded);
            console.log('   Failed:', uploadResults.failed);
            console.log('   Success array:', uploadResults.success);

            if (uploadResults.uploaded === 0) {
                throw new Error('No photos were uploaded successfully');
            }

            // Step 2: Associate all successful uploads with entity
            console.log('📤 Step 2: Starting association...');
            let associated = 0;
            const associationErrors = [];

            for (let i = 0; i < uploadResults.success.length; i++) {
                const photo = uploadResults.success[i];

                console.log(`───────────────────────────────────────────────────`);
                console.log(`🔗 Association ${i + 1}/${uploadResults.success.length}`);
                console.log('   Photo object:', photo);
                console.log('   photo.photo_id:', photo.photo_id);
                console.log('   typeof photo.photo_id:', typeof photo.photo_id);
                console.log('   entityType:', entityType);
                console.log('   entityId:', entityId);
                console.log('   typeof entityId:', typeof entityId);
                console.log(`───────────────────────────────────────────────────`);

                try {
                    await this.associatePhoto(
                            photo.photo_id,
                            entityType,
                            entityId,
                            {
                                is_primary: options.is_primary || false,
                                caption_override: options.caption_override || null
                            }
                    );

                    associated++;
                    console.log(`✅ Associated ${associated}/${uploadResults.uploaded}`);

                } catch (e) {
                    console.error(`❌ Failed to associate photo ${photo.photo_id}:`, e);
                    associationErrors.push({
                        photo_id: photo.photo_id,
                        error: e.message
                    });
                }
            }

            console.log('═══════════════════════════════════════════════════');
            console.log('✅ Association complete');
            console.log('   Total associated:', associated);
            console.log('   Total failed:', associationErrors.length);
            console.log('═══════════════════════════════════════════════════');

            return {
                uploaded: uploadResults.uploaded,
                associated: associated,
                failed_upload: uploadResults.failed,
                failed_association: associationErrors.length,
                total: files.length,
                errors: {
                    upload: uploadResults.errors,
                    association: associationErrors
                }
            };

        } catch (error) {
            console.error('❌ Upload & associate error:', error);
            throw error;
        } finally {
            this.uploading = false;
            this.uploadProgress = 0;
    }
    },

    /**
     * Handle drag & drop upload
     * Universal method
     * 
     * @param {DragEvent} event - Drop event
     * @param {string} entityType - Entity type
     * @param {number} entityId - Entity ID
     * @param {Object} options - Upload options
     * @returns {Promise<Object>} - Upload results
     */
    async handleDrop(event, entityType, entityId, options = {}) {
        console.log(`📸 Drop handler for ${entityType} ${entityId}`);

        const files = Array.from(event.dataTransfer.files).filter(file =>
            file.type.startsWith('image/')
        );

        if (files.length === 0) {
            console.log('ℹ️ No image files in drop');
            return null;
        }

        console.log(`📸 Dropped ${files.length} image files`);

        return await this.uploadAndAssociate(files, entityType, entityId, options);
    },

    /**
     * Load photos for entity
     * Universal method
     * 
     * @param {string} entityType - Entity type
     * @param {number} entityId - Entity ID
     * @returns {Promise<Array>} - Array of photos
     */
    async loadPhotos(entityType, entityId) {
        console.log(`📷 Loading photos for ${entityType} ${entityId}`);

        try {
            const photos = await this.getEntityPhotos(entityType, entityId);
            console.log(`✅ Loaded ${photos.length} photos`);
            return photos;

        } catch (error) {
            console.error('❌ Failed to load photos:', error);
            return [];
        }
    },

    /**
     * Set photo as primary for entity
     * Universal method
     * 
     * @param {number} photoId - Photo ID
     * @param {string} entityType - Entity type
     * @param {number} entityId - Entity ID
     * @returns {Promise<boolean>} - Success
     */
    async setPrimary(photoId, entityType, entityId) {
        console.log(`⭐ Setting photo ${photoId} as primary for ${entityType} ${entityId}`);

        try {
            await this.setPrimaryPhoto(photoId, entityType, entityId);
            console.log('✅ Primary photo set');
            return true;

        } catch (error) {
            console.error('❌ Failed to set primary:', error);
            throw error;
        }
    },

    /**
     * Delete photo
     * Universal method (removes from ALL associations)
     * 
     * @param {number} photoId - Photo ID
     * @returns {Promise<boolean>} - Success
     */
    async remove(photoId) {
        console.log(`🗑️ Deleting photo ${photoId}`);

        if (!confirm('Czy na pewno usunąć to zdjęcie?')) {
            return false;
        }

        try {
            const deleted = await this.deletePhoto(photoId);

            if (deleted) {
                console.log('✅ Photo deleted');
                return true;
            }

            return false;

        } catch (error) {
            console.error('❌ Failed to delete photo:', error);
            throw error;
        }
    },

    // ========================================================================
    // LOW-LEVEL API - Upload operations
    // ========================================================================

    /**
     * Upload single photo
     */
    /**
 * Upload single photo
 */
async uploadPhoto(file, options = {}) {
    console.log('═══════════════════════════════════════════════════');
    console.log('📸 DEBUG: uploadPhoto() called');
    console.log('   file.name:', file.name);
    console.log('   file.size:', file.size);
    console.log('   file.type:', file.type);
    console.log('   options:', options);
    console.log('═══════════════════════════════════════════════════');

    const validation = this.validateFile(file);
    if (!validation.valid) {
        throw new Error(validation.error);
    }

    this.uploading = true;

    try {
        const formData = new FormData();
        formData.append('image', file);

        if (options.caption) {
            formData.append('caption', options.caption);
        }
        if (options.is_public !== undefined) {
            formData.append('is_public', options.is_public ? 1 : 0);
        }
        if (options.auto_create_poi !== undefined) {
            formData.append('auto_create_poi', options.auto_create_poi ? 1 : 0);
        }

        console.log('📤 FormData entries:');
        for (let pair of formData.entries()) {
            console.log('   ' + pair[0] + ':', pair[1]);
        }

        const token = localStorage.getItem('auth_token');
        if (!token) {
            throw new Error('Authentication required');
        }

        console.log('📤 Sending request to:', window.APP_CONFIG.api('photos/photos.php?action=upload'));
        console.log('   Token present:', !!token);

        const response = await fetch(
            window.APP_CONFIG.api('photos/photos.php?action=upload'),
            {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`
                },
                body: formData
            }
        );

        console.log('📥 Response status:', response.status);
        console.log('📥 Response ok:', response.ok);
        console.log('📥 Response headers:');
        for (let pair of response.headers.entries()) {
            console.log('   ' + pair[0] + ':', pair[1]);
        }

        const text = await response.text();
        console.log('📥 Raw response body:');
        console.log(text);

        let data;
        try {
            data = JSON.parse(text);
            console.log('📥 Parsed response:', data);
        } catch (e) {
            console.error('❌ Failed to parse JSON response:', e);
            console.error('   Raw text was:', text);
            throw new Error('Invalid JSON response from server');
        }

        if (!data.success) {
            console.error('❌ Server returned error:', data.error);
            throw new Error(data.error || 'Upload failed');
        }

        console.log('✅ Photo uploaded successfully');
        console.log('   photo_id:', data.data.photo_id);
        console.log('   file_path:', data.data.file_path);
        console.log('   has_gps:', data.data.has_gps);
        console.log('═══════════════════════════════════════════════════');

        return data.data;

    } catch (error) {
        console.error('❌ Upload error:', error);
        console.error('   Error stack:', error.stack);
        console.log('═══════════════════════════════════════════════════');
        throw error;

    } finally {
        this.uploading = false;
    }
},

    /**
     * Upload multiple photos (batch)
     */
    async uploadMultiple(files, options = {}) {
        console.log(`📸 Uploading ${files.length} photos...`);

        const results = [];
        const errors = [];

        for (let i = 0; i < files.length; i++) {
            try {
                const result = await this.uploadPhoto(files[i], options);
                results.push(result);

                this.uploadProgress = Math.round(((i + 1) / files.length) * 100);
                console.log(`✅ Uploaded ${i + 1}/${files.length}: ${files[i].name}`);

            } catch (error) {
                console.error(`❌ Failed to upload ${files[i].name}:`, error);
                errors.push({
                    file: files[i].name,
                    error: error.message
                });
            }
        }

        return {
            success: results,
            errors: errors,
            total: files.length,
            uploaded: results.length,
            failed: errors.length
        };
    },

    // ========================================================================
    // LOW-LEVEL API - Association operations
    // ========================================================================

    /**
     * Associate photo with entity
     */
    async associatePhoto(photoId, entityType, entityId, options = {}) {
        console.log('═══════════════════════════════════════════════════');
        console.log('🔗 DEBUG: associatePhoto() called');
        console.log('   photoId:', photoId);
        console.log('   typeof photoId:', typeof photoId);
        console.log('   entityType:', entityType);
        console.log('   typeof entityType:', typeof entityType);
        console.log('   entityId:', entityId);
        console.log('   typeof entityId:', typeof entityId);
        console.log('   options:', options);
        console.log('═══════════════════════════════════════════════════');

        // ✅ VALIDATE parameters
        if (!photoId || !entityType || !entityId) {
            const error = `Missing parameters: photoId=${photoId}, entityType=${entityType}, entityId=${entityId}`;
            console.error('❌', error);
            throw new Error(error);
        }

        try {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                throw new Error('Authentication required');
            }

            const payload = {
                photo_id: parseInt(photoId),
                entity_type: entityType,
                entity_id: parseInt(entityId),
                is_primary: options.is_primary || false,
                caption_override: options.caption_override || null
            };

            console.log('📤 Payload before sending:');
            console.log(JSON.stringify(payload, null, 2));
            console.log('   photo_id type:', typeof payload.photo_id);
            console.log('   entity_id type:', typeof payload.entity_id);

            const response = await fetch(
                    window.APP_CONFIG.api('photos/photos.php?action=associate'),
                    {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    }
            );

            console.log('📥 Response status:', response.status);
            console.log('📥 Response headers:', Object.fromEntries(response.headers.entries()));

            const text = await response.text();
            console.log('📥 Raw response body:');
            console.log(text);

            const data = JSON.parse(text);
            console.log('📥 Parsed response:', data);

            if (!data.success) {
                throw new Error(data.error || 'Association failed');
            }

            console.log('✅ Photo associated successfully');
            console.log('═══════════════════════════════════════════════════');

            return data.data;

        } catch (error) {
            console.error('❌ Association error:', error);
            console.error('   Error stack:', error.stack);
            console.log('═══════════════════════════════════════════════════');
            throw error;
    }
    },

    /**
     * Set photo as primary (cover) for entity
     */
    async setPrimaryPhoto(photoId, entityType, entityId) {
        console.log(`⭐ Setting photo ${photoId} as primary`);

        try {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                throw new Error('Authentication required');
            }

            const response = await fetch(
                    window.APP_CONFIG.api('photos/photos.php?action=set_primary'),
                    {
                        method: 'PATCH',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            photo_id: parseInt(photoId),
                            entity_type: entityType,
                            entity_id: parseInt(entityId)
                        })
                    }
            );

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to set primary');
            }

            console.log('✅ Primary photo set');

            return data.data;

        } catch (error) {
            console.error('❌ Set primary error:', error);
            throw error;
        }
    },

    // ========================================================================
    // LOW-LEVEL API - List & Delete
    // ========================================================================

    /**
     * Get photos for entity
     */
    async getEntityPhotos(entityType, entityId) {
        try {
            const url = window.APP_CONFIG.api(
                    `photos/photos.php?action=list&entity_type=${entityType}&entity_id=${entityId}`
                    );

            const response = await fetch(url);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load photos');
            }

            return data.data.photos || [];

        } catch (error) {
            console.error('❌ Load photos error:', error);
            return [];
        }
    },

    /**
     * Get user's photos
     */
    async getUserPhotos(limit = 50) {
        try {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                throw new Error('Authentication required');
            }

            const url = window.APP_CONFIG.api(
                    `photos/photos.php?action=list&user_id=me&limit=${limit}`
                    );

            const response = await fetch(url, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load photos');
            }

            return data.data.photos || [];

        } catch (error) {
            console.error('❌ Load user photos error:', error);
            return [];
    }
    },

    /**
     * Delete photo
     */
    async deletePhoto(photoId) {
        console.log(`🗑️ Deleting photo ${photoId}`);

        try {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                throw new Error('Authentication required');
            }

            const response = await fetch(
                    window.APP_CONFIG.api(`photos/photos.php?action=delete&photo_id=${photoId}`),
                    {
                        method: 'DELETE',
                        headers: {
                            'Authorization': `Bearer ${token}`
                        }
                    }
            );

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Delete failed');
            }

            console.log('✅ Photo deleted');

            return true;

        } catch (error) {
            console.error('❌ Delete error:', error);
            alert('Nie udało się usunąć zdjęcia: ' + error.message);
            return false;
        }
    },

    // ========================================================================
    // VALIDATION
    // ========================================================================

    validateFile(file) {
        const maxSize = 10 * 1024 * 1024; // 10MB
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

        if (!allowedTypes.includes(file.type)) {
            return {
                valid: false,
                error: 'Nieprawidłowy typ pliku. Dozwolone: JPEG, PNG, WEBP'
            };
        }

        if (file.size > maxSize) {
            return {
                valid: false,
                error: 'Plik za duży. Maksymalnie 10MB'
            };
        }

        return {valid: true};
    },

    // ========================================================================
    // UI HELPERS
    // ========================================================================

    /**
     * Create file input and trigger upload dialog
     */
    openFileDialog(options = {}) {
        return new Promise((resolve, reject) => {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/jpeg,image/jpg,image/png,image/webp';
            input.multiple = options.multiple || false;

            input.onchange = (e) => {
                const files = Array.from(e.target.files);

                if (files.length === 0) {
                    reject(new Error('No files selected'));
                    return;
                }

                resolve(files);
            };

            input.click();
        });
    },

    /**
     * Format file size for display
     */
    formatFileSize(bytes) {
        if (bytes < 1024)
            return bytes + ' B';
        if (bytes < 1024 * 1024)
            return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }
};

console.log('✅ PhotoModule loaded (Universal v2.0)');