/* ============================================================================
   PROFILE MODULE - User profile management
   ============================================================================ */

window.ProfileModule = {

    /**
     * Open profile modal and load data
     */
    async openProfileModal(state) {
        state.showProfileModal = true;
        state.profileActiveTab = 'personal';
        await this.loadProfile(state);
    },

    /**
     * Close profile modal
     */
    closeProfileModal(state) {
        state.showProfileModal = false;
        state.profileError = null;
    },

    /**
     * Switch profile tab
     */
    switchTab(state, tab) {
        state.profileActiveTab = tab;
        state.profileError = null;
    },

    /**
     * Load user profile data
     */
    async loadProfile(state) {
        state.profileLoading = true;
        state.profileError = null;

        try {
            const url = window.APP_CONFIG.api('user/profile.php');
            //console.log('📡 Fetching profile from:', url);
            
            const response = await Utils.fetchAPI(url);
            //console.log('📦 Raw response:', response);
            //console.log('📦 Response type:', typeof response);
            //console.log('📦 Response.success:', response.success);
            //console.log('📦 Response.data:', response.data);

            if (!response.success) {
                throw new Error(response.error || 'Failed to load profile');
            }

            // ✅ Utils.fetchAPI zwraca {success, data: {success, data}}
            // Musimy wyciągnąć response.data.data
            const profile = response.data.data || response.data;

            //console.log('🔍 Profile from API:', profile);
            //console.log('🔍 Profile type:', typeof profile);
            //console.log('🔍 First name:', profile.first_name);
            //console.log('🔍 Last name:', profile.last_name);
            //console.log('🔍 Phone:', profile.phone);
            //console.log('🔍 API key:', profile.api_key);

            //console.log('📝 BEFORE assignment - state.profileData:', JSON.parse(JSON.stringify(state.profileData)));

            // Update state - assign properties individually for Alpine reactivity
            state.profileData.username = profile.username ?? '';
            state.profileData.first_name = profile.first_name ?? '';
            state.profileData.last_name = profile.last_name ?? '';
            state.profileData.phone = profile.phone ?? '';
            state.profileData.shipping_address = profile.shipping_address ?? '';
            state.profileData.api_key = profile.api_key ?? '';

            //console.log('📝 AFTER assignment - state.profileData:', JSON.parse(JSON.stringify(state.profileData)));
            //console.log('✅ Profile loaded into state:', {
            //    username: state.profileData.username,
            //   first_name: state.profileData.first_name,
            //    last_name: state.profileData.last_name,
             //   phone: state.profileData.phone,
            //    api_key: state.profileData.api_key
            //});

        } catch (error) {
            console.error('❌ Load profile error:', error);
            state.profileError = error.message;
        } finally {
            state.profileLoading = false;
        }
    },

    /**
     * Update profile (specific tab)
     */
    async updateProfile(state, tab) {
        state.profileSaving = true;
        state.profileError = null;
        state.profileSuccess = false;

        try {
            const payload = {
                tab: tab
            };

            // Add data based on active tab
            if (tab === 'personal') {
                payload.username = state.profileData.username;
                payload.first_name = state.profileData.first_name;
                payload.last_name = state.profileData.last_name;
                payload.phone = state.profileData.phone;
                payload.shipping_address = state.profileData.shipping_address;
            }

            const url = window.APP_CONFIG.api('user/profile.php');
            const response = await Utils.fetchAPI(url, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!response.success) {
                throw new Error(response.error || 'Failed to update profile');
            }

            state.profileSuccess = true;
            //console.log('✅ Profile updated');

            // Hide success message after 3s
            setTimeout(() => {
                state.profileSuccess = false;
            }, 3000);

        } catch (error) {
            console.error('❌ Update profile error:', error);
            state.profileError = error.message;
        } finally {
            state.profileSaving = false;
        }
    },

    /**
     * Regenerate API key
     */
    async regenerateApiKey(state) {
        if (!confirm('Czy na pewno chcesz wygenerować nowy klucz API? Stary klucz przestanie działać.')) {
            return;
        }

        state.profileSaving = true;
        state.profileError = null;

        try {
            const url = window.APP_CONFIG.api('user/profile.php');
            const response = await Utils.fetchAPI(url, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tab: 'account',
                    regenerate_api_key: true
                })
            });

            if (!response.success) {
                throw new Error(response.error || 'Failed to regenerate API key');
            }

            // ✅ Backend zwraca {success, data: {api_key}}
            const newKey = response.data.data?.api_key || response.data.api_key;
            state.profileData.api_key = newKey;
            //console.log('✅ API key regenerated:', newKey);

        } catch (error) {
            console.error('❌ Regenerate API key error:', error);
            state.profileError = error.message;
        } finally {
            state.profileSaving = false;
        }
    },

    /**
     * Copy API key to clipboard
     */
    async copyApiKey(state) {
        try {
            await navigator.clipboard.writeText(state.profileData.api_key);
            
            // Show temporary feedback
            const originalKey = state.profileData.api_key;
            state.profileData.api_key = 'Skopiowano! ✓';
            
            setTimeout(() => {
                state.profileData.api_key = originalKey;
            }, 2000);

        } catch (error) {
            console.error('Failed to copy:', error);
            alert('Nie udało się skopiować. Zaznacz i skopiuj ręcznie.');
        }
    }
};