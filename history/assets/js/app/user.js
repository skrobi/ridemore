/* ============================================================================
 USER MODULE - Authentication & User Management - FIXED
 ============================================================================ */
(function() {
    const originalRemoveItem = localStorage.removeItem;
    localStorage.removeItem = function(key) {
        if (key === 'auth_token') {
            console.error('🚨 AUTH TOKEN REMOVED!');
            console.trace('Stack trace:'); // Pokaże kto wywołał
        }
        return originalRemoveItem.apply(this, arguments);
    };
    //console.log('✅ localStorage.removeItem() trap installed');
})();


window.UserModule = {

    async initUser(state) {
    //console.log('=== INIT USER DEBUG ===');
    //console.log('localStorage keys:', Object.keys(localStorage));
    //console.log('auth_token RAW:', localStorage.getItem('auth_token'));
    //console.log('user_local_id RAW:', localStorage.getItem('user_local_id'));
    //console.log('user_email RAW:', localStorage.getItem('user_email'));
    
    this.checkAuthToken(state);
    
    let localId = localStorage.getItem('user_local_id');
    if (localId && typeof localId === 'string') {
        localId = localId.replace(/^["']|["']$/g, '');
    }
    if (!localId || localId === 'null' || localId === 'undefined') {
        localId = this.generateUUID();
        localStorage.setItem('user_local_id', localId);
        //console.log('🆔 Created new local_id:', localId);
    }
    state.user.local_id = localId;
    
    const token = localStorage.getItem('auth_token');
    
    // ✅ DODAJ DEBUG TUTAJ
    //console.log('🔍 Token check:');
    //console.log('   token variable:', token ? 'EXISTS' : 'NULL');
    //console.log('   token length:', token?.length);
    //console.log('   token type:', typeof token);
    //console.log('   token value:', token);
    //console.log('   if (token) will be:', !!token);
    
    if (token) {
        //console.log('✅ Entering token block...');
        state.user.token = token.replace(/^["']|["']$/g, '');
        //console.log('✅ state.user.token set to:', state.user.token.substring(0, 30) + '...');
        //console.log('🔄 Calling loadUserData...');
        await this.loadUserData(state);
        //console.log('✅ loadUserData completed');
    } else {
        //console.log('❌ Token is falsy, initializing as guest...');
        await this.initGuestUser(state);
    }
},

    async initGuestUser(state) {
        try {
            const response = await fetch(window.APP_CONFIG.api('auth/init.php'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({user_local_id: state.user.local_id})
            });

            const data = await response.json();

            if (data.success) {
                //console.log('✅ Guest user initialized');
            } else {
                console.warn('⚠️ Guest init failed:', data.error);
            }
        } catch (error) {
            console.error('❌ Failed to init guest user:', error);
        }
    },

    async loadUserData(state) {
        try {
            const response = await fetch(window.APP_CONFIG.api('auth/me.php'), {
                headers: {
                    'Authorization': `Bearer ${state.user.token}`
                }
            });

            const data = await response.json();

            if (data.success) {
                // ✅ Zapisz WSZYSTKIE dane usera
                state.user.email = data.data.email;
                state.user.username = data.data.username;
                state.user.status = data.data.status;
                state.user.role = data.data.role;  // ✅ DODAJ
                state.user.has_manage_access = data.data.has_manage_access;  // ✅ DODAJ
                state.user.manage_role = data.data.manage_role;  // ✅ DODAJ
                
                //console.log('✅ User data loaded:', state.user.email);
                //console.log('   Role:', state.user.role);
                //console.log('   Manage access:', state.user.has_manage_access);
            } else {
                localStorage.removeItem('auth_token');
                state.user.token = null;
            }
        } catch (error) {
            console.error('❌ Failed to load user data:', error);
        }
    },

    async sendMagicLink(state) {
        if (!state.loginEmail) {
            alert('Podaj adres email');
            return;
        }

        if (!this.validateEmail(state.loginEmail)) {
            alert('Nieprawidłowy format email');
            return;
        }

        // ✅ NOWE: Zapisz temp local_id w cookie (dla verify.php)
        document.cookie = `temp_local_id=${state.user.local_id}; path=/; max-age=900`; // 15min

        try {
            const response = await fetch(window.APP_CONFIG.api('auth/send_magic_link.php'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    email: state.loginEmail,
                    user_local_id: state.user.local_id
                })
            });

            const data = await response.json();

            if (data.success) {
                alert('✅ Link wysłany! Sprawdź swoją skrzynkę email.');
                state.showLoginModal = false;
                state.loginEmail = '';
            } else {
                alert('❌ ' + data.error);
            }
        } catch (error) {
            console.error('❌ Failed to send magic link:', error);
            alert('❌ Nie udało się wysłać linka');
        }
    },

    checkAuthToken(state) {
    const params = new URLSearchParams(window.location.search);
    const token = params.get('auth_token');
    const email = params.get('email');
    const syncLocalId = params.get('sync_local_id');

    // ✅ ZAWSZE loguj - nawet jak nie ma tokena
    //console.log('🔍 checkAuthToken() START');
    //console.log('🔍 checkAuthToken() called');
    //console.log('   Has token in URL?', !!token);
    //console.log('   Token length:', token?.length);


    if (token) {
        //console.log('🔑 FOUND TOKEN IN URL - SAVING NOW!');
        
        // Zapisz BEZ żadnych modyfikacji najpierw
        localStorage.setItem('auth_token', token);
        localStorage.setItem('user_email', email);
        
        // ✅ WERYFIKACJA natychmiast po zapisie
        const saved = localStorage.getItem('auth_token');
        //console.log('✅ Verification after save:');
        //console.log('   Saved token length:', saved?.length);
        //console.log('   Match:', saved === token);
        
        if (!saved) {
            console.error('❌❌❌ TOKEN NOT SAVED TO LOCALSTORAGE!');
            alert('BŁĄD: Token nie został zapisany!');
        }
        
        // Sync local_id
        if (syncLocalId) {
            localStorage.setItem('user_local_id', syncLocalId);
            if (state.user) {
                state.user.local_id = syncLocalId;
            }
            //console.log('🔄 Synced local_id:', syncLocalId);
        }

        // Update state
        if (state.user) {
            state.user.token = token;
            state.user.email = email;
            state.user.status = 'verified';
        }

        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);

        //console.log('✅ Token processing complete');
    } else {
        //console.log('ℹ️ No token in URL (normal page load)');
    }
    
    //console.log('🔍 checkAuthToken() END');
    //console.log('   localStorage auth_token after:', localStorage.getItem('auth_token') ? 'EXISTS' : 'NULL');

},

    logout(state) {
        if (!confirm('Czy na pewno chcesz się wylogować?')) {
            return;
        }

        // Usuń token
        localStorage.removeItem('auth_token');
        localStorage.clear();
        
        document.cookie.split(";").forEach(c => document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/"));

        // Wygeneruj nowy local_id (nowy guest)
        const newLocalId = this.generateUUID();
        localStorage.setItem('user_local_id', newLocalId);

        // Reset state
        state.user.local_id = newLocalId;
        state.user.token = null;
        state.user.email = null;
        state.user.username = null;
        state.user.status = 'guest';

        // Wyczyść cached data
        state.userChallenges = [];
        state.challengeGroups = [];
        state.userAchievements = [];
        state.topRoutes = [];
        state.libraryRoutes = [];

        alert('Wylogowano');
        location.reload();
    },

    // Utilities
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    },

    validateEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
};