/* Czysty stan preferencji routingu; bez DOM-u i bez drugiego profilu roweru. */
(function (root) {
    'use strict';

    function clone(value) {
        return JSON.parse(JSON.stringify(value || {}));
    }

    function create(presets) {
        presets = presets || {};
        var state = { profile: '', character: 'balanced', preferences: {}, configId: null, configs: [] };

        function preset(profile, character) {
            var byProfile = presets[profile] || {};
            return clone(byProfile[character] || byProfile.balanced || {});
        }

        function applyDefault(profile) {
            var saved = state.configs.find(function (c) { return c.bikeProfile === profile && c.isDefault; });
            if (saved) return api.selectConfig(saved.id);
            state.configId = null;
            state.character = 'balanced';
            state.preferences = preset(profile, 'balanced');
            return true;
        }

        var api = {
            setConfigs: function (items, useDefault) {
                state.configs = Array.isArray(items) ? items.map(clone) : [];
                if (useDefault && state.profile) applyDefault(state.profile);
            },
            setProfile: function (profile, useDefault) {
                profile = typeof profile === 'string' ? profile : '';
                if (profile === state.profile && !useDefault) return false;
                state.profile = profile;
                if (useDefault !== false) applyDefault(profile);
                return true;
            },
            setCharacter: function (character) {
                if (['road', 'balanced', 'offroad'].indexOf(character) < 0) character = 'balanced';
                state.character = character;
                state.preferences = preset(state.profile, character);
            },
            setGroup: function (group, keys, value) {
                value = Math.max(-2, Math.min(2, Math.round(Number(value) || 0)));
                state.preferences[group] = state.preferences[group] || {};
                keys.forEach(function (key) { state.preferences[group][key] = value; });
            },
            usePreset: function (character) {
                state.configId = null;
                state.character = ['road', 'balanced', 'offroad'].indexOf(character) >= 0 ? character : 'balanced';
                state.preferences = preset(state.profile, state.character);
            },
            selectConfig: function (id) {
                id = Number(id);
                var found = state.configs.find(function (c) { return c.id === id && c.bikeProfile === state.profile; });
                if (!found) return false;
                state.configId = found.id;
                state.character = found.character || 'balanced';
                state.preferences = clone(found.preferences);
                return true;
            },
            restore: function (routing) {
                routing = routing || {};
                // Wczytana trasa niesie historyczny snapshot. Nawet gdy starszy
                // serwer zwrócił id konfiguracji, nie wolno później zapisać tego
                // snapshotu jako edycji jej aktualnej wersji.
                state.configId = null;
                state.character = ['road', 'balanced', 'offroad'].indexOf(routing.character) >= 0 ? routing.character : 'balanced';
                state.preferences = clone(routing.preferences || preset(state.profile, state.character));
            },
            upsert: function (item) {
                if (!item) return;
                state.configs = state.configs.filter(function (c) { return c.id !== item.id; });
                state.configs.push(clone(item));
                api.selectConfig(item.id);
            },
            remove: function (id) {
                state.configs = state.configs.filter(function (c) { return c.id !== Number(id); });
                if (state.configId === Number(id)) applyDefault(state.profile);
            },
            payload: function () {
                return { configId: state.configId, character: state.character, preferences: clone(state.preferences) };
            },
            signature: function () {
                return JSON.stringify(api.payload());
            },
            current: function () { return clone(state); },
        };
        return api;
    }

    root.RidemoreRoutingPreferences = { create: create };
})(typeof window !== 'undefined' ? window : this);
