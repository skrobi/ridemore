window.FeedModule = {

    async loadFeed(state, filter = 'global', limit = 50) {
        state.loadingFeed = true;

        try {
            const headers = {};
            if (state.user.token) {
                headers['Authorization'] = `Bearer ${state.user.token}`;
            }

            // ✅ JEDEN endpoint
            const url = window.APP_CONFIG.api(`feed/list.php?filter=${filter}&limit=${limit}`);

            const response = await fetch(url, {headers});
            const data = await response.json();

            if (data.success) {
                state.feedEvents = data.data.events || [];
                //console.log(`✅ Loaded ${state.feedEvents.length} ${filter} feed events`);
            } else {
                console.error('❌ Feed load failed:', data.error);
                state.feedEvents = [];
            }
        } catch (error) {
            console.error('❌ Feed error:', error);
            state.feedEvents = [];
        } finally {
            state.loadingFeed = false;
    }
    },

    async loadNotifications(state) {
        if (!state.user.token)
            return;

        state.loadingNotifications = true;

        try {
            const headers = {
                'Authorization': `Bearer ${state.user.token}`
            };

            const url = window.APP_CONFIG.api('notifications/list.php');

            const response = await fetch(url, {headers});
            const data = await response.json();

            if (data.success) {
                state.notifications = data.data.notifications || [];
                state.unreadNotificationsCount = data.data.unread_count || 0;
                //console.log(`✅ Loaded ${state.notifications.length} notifications (${state.unreadNotificationsCount} unread)`);
            }
        } catch (error) {
            console.error('❌ Notifications error:', error);
        } finally {
            state.loadingNotifications = false;
        }
    },

    async markAsRead(state, notificationId) {
        if (!state.user.token)
            return;

        try {
            const headers = {
                'Authorization': `Bearer ${state.user.token}`,
                'Content-Type': 'application/json'
            };

            const url = window.APP_CONFIG.api('notifications/mark-read.php');

            const response = await fetch(url, {
                method: 'POST',
                headers,
                body: JSON.stringify({notification_id: notificationId})
            });

            const data = await response.json();

            if (data.success) {
                await this.loadNotifications(state);
            }
        } catch (error) {
            console.error('❌ Mark read error:', error);
        }
    },

    async markAllAsRead(state) {
        if (!state.user.token)
            return;

        try {
            const headers = {
                'Authorization': `Bearer ${state.user.token}`,
                'Content-Type': 'application/json'
            };

            const url = window.APP_CONFIG.api('notifications/mark-read.php');

            const response = await fetch(url, {
                method: 'POST',
                headers,
                body: JSON.stringify({})
            });

            const data = await response.json();

            if (data.success) {
                await this.loadNotifications(state);
            }
        } catch (error) {
            console.error('❌ Mark all read error:', error);
        }
    },

    // ✅ SIMPLIFIED
    switchFeedFilter(state, filter) {
        state.feedFilter = filter;
        this.loadFeed(state, filter);
    }
};

//console.log('✅ Feed module loaded');