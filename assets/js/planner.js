/* assets/js/planner.js
 * Orkiestrator interfejsu planera. Czysty model trasy znajduje się w
 * assets/js/planner/route-model.js; ten plik łączy go z DOM-em, Leafletem
 * i endpointami HTTP.
 */
(function (root) {
    'use strict';

    if (typeof document === 'undefined' || !root.RidemorePlannerRoute || !root.RidemoreRoutingPreferences) {
        return;
    }

    var cfg = root.PLANNER_CONFIG || {};
    var els = {
        canvas: document.getElementById('plannerMapCanvas'),
        onboarding: document.getElementById('plannerOnboarding'),
        empty: document.getElementById('plannerEmpty'),
        wpList: document.getElementById('plannerWpList'),
        addHint: document.getElementById('plannerAddHint'),
        statsCard: document.getElementById('plannerStatsCard'),
        statDistance: document.getElementById('plannerStatDistance'),
        statAscent: document.getElementById('plannerStatAscent'),
        statTime: document.getElementById('plannerStatTime'),
        name: document.getElementById('plannerName'),
        clearBtn: document.getElementById('plannerClear'),
        saveBtn: document.getElementById('plannerSave'),
        gpxLink: document.getElementById('plannerGpx'),
        saveMsg: document.getElementById('plannerSaveMsg'),
        profileRow: document.getElementById('plannerProfileRow'),
        sources: document.getElementById('plannerSources'),
        base: document.getElementById('plannerBase'),
        baseSwatch: document.getElementById('plannerBaseSwatch'),
        baseFile: document.getElementById('plannerBaseFile'),
        baseName: document.getElementById('plannerBaseName'),
        baseMeta: document.getElementById('plannerBaseMeta'),
        baseCount: document.getElementById('plannerBaseCount'),
        baseClear: document.getElementById('plannerBaseClear'),
        baseSub: document.getElementById('plannerBaseSub'),
        baseError: document.getElementById('plannerBaseError'),
        pickBtn: document.getElementById('plannerPickBtn'),
        gpxBtn: document.getElementById('plannerGpxBtn'),
        gpxFile: document.getElementById('plannerGpxFile'),
        picker: document.getElementById('plannerPicker'),
        pickerQuery: document.getElementById('plannerPickerQuery'),
        pickerResults: document.getElementById('plannerPickerResults'),
        pickerCancel: document.getElementById('plannerPickerCancel'),
        listLabel: document.getElementById('plannerListLabel'),
        srcMine: document.getElementById('plannerSrcMine'),
        srcKnown: document.getElementById('plannerSrcKnown'),
        srcCommunity: document.getElementById('plannerSrcCommunity'),
        priorityHint: document.getElementById('plannerPriorityHint'),
        treasureToggle: document.getElementById('plannerShowTreasures'),
        autoJoin: document.getElementById('plannerAutoJoin'),
        autoJoinHint: document.getElementById('plannerAutoJoinHint'),
        characterRow: document.getElementById('plannerCharacterRow'),
        routingSaved: document.getElementById('plannerRoutingSaved'),
        routingControls: document.getElementById('plannerRoutingControls'),
        routingName: document.getElementById('plannerRoutingName'),
        routingSave: document.getElementById('plannerRoutingSave'),
        routingDelete: document.getElementById('plannerRoutingDelete'),
        routingDefault: document.getElementById('plannerRoutingDefault'),
        routingMsg: document.getElementById('plannerRoutingMsg'),
        routingProgress: document.getElementById('plannerRoutingProgress'),
    };
    if (!els.canvas || typeof ridemoreCreateMap !== 'function') {
        return;
    }

    var map = ridemoreCreateMap(els.canvas, [52.0, 19.3]);
    var route = root.RidemorePlannerRoute.create(root.RidemorePlannerRoute.MAX_WAYPOINTS);
    var routingPreferences = root.RidemoreRoutingPreferences.create(cfg.routingPresets || {});

    var state = {
        speedKmh: 25,
        profile: '',
        routeId: cfg.existingRouteId || null,
        durationMin: null,
        recomputeTimer: null,
        routingSeq: 0,
        layersTimer: null,
        pickerTimer: null,
        pickerSeq: 0,
        // Konkretna baza: {kind: 'route'|'ride'|'gpx', label, file, km, color, points} albo null
        base: null,
    };

    var routeColor = getCss('--accent');
    var INK = '#15201A';
    var RIDE_COLOR = '#7D4CA8'; // próbka „Moje przejazdy" na liście źródeł
    // Kolejność dodania = kolejność w SVG: baza najniżej, potem odcinki, skarby na wierzchu.
    var baseLayer = L.layerGroup().addTo(map);
    var treasureLayer = L.layerGroup().addTo(map);
    // Jedna linia NA ODCINEK, nie jedna scalona dla całej trasy: chwycona
    // linia sama mówi, między którymi dwoma punktami leży.
    var segmentLayer = L.layerGroup().addTo(map);
    var dragHandleLayer = L.layerGroup().addTo(map);
    var baseLines = [];

    function getCss(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#2C6B4F';
    }

    function formatKm(km) {
        return (km >= 10 ? km.toFixed(0) : km.toFixed(1)).replace('.', ',') + ' km';
    }

    function lengthKm(points) {
        var m = 0;
        for (var i = 1; i < points.length; i++) {
            var a = points[i - 1], b = points[i];
            var p1 = a[0] * Math.PI / 180, p2 = b[0] * Math.PI / 180;
            var dp = p2 - p1, dl = (b[1] - a[1]) * Math.PI / 180;
            var h = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) * Math.sin(dl / 2);
            m += 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
        }
        return m / 1000;
    }

    // ------------------------------------------------------------------
    // Źródła trasy — PRAWDZIWE KAFLE warstwy „Ślady" (te same co
    // /odkrycia/spolecznosc, `ridemoreAddTileLayer` z gpx-map.js). Jedno
    // zaznaczenie = warstwa widoczna na mapie I źródło dla planera; przy
    // aktywnej bazie (konkretna trasa / GPX) już tylko widoczność.
    // Kolejność dodania = kolejność rysowania: znane trasy na spodzie, potem
    // społeczność, moje przejazdy na wierzchu.
    // ------------------------------------------------------------------
    var knownRoutesTiles = ridemoreAddTileLayer(map, cfg.trackTiles.kr, { kind: 'tracks' });
    var communityTracksTiles = ridemoreAddTileLayer(map, cfg.trackTiles.all, { kind: 'tracks' });
    var mineTracksTiles = ridemoreAddTileLayer(map, cfg.trackTiles.me, { kind: 'tracks' });

    function bindSource(toggleEl, tileLayer) {
        if (!toggleEl.checked) {
            map.removeLayer(tileLayer);
        }
        toggleEl.addEventListener('change', function () {
            if (toggleEl.checked) {
                map.addLayer(tileLayer);
            } else {
                map.removeLayer(tileLayer);
            }
            syncContext();
        });
    }
    bindSource(els.srcKnown, knownRoutesTiles);
    bindSource(els.srcCommunity, communityTracksTiles);
    bindSource(els.srcMine, mineTracksTiles);
    els.autoJoin.addEventListener('change', syncContext);

    function sourceCount() {
        return [els.srcMine, els.srcKnown, els.srcCommunity].filter(function (c) { return c.checked; }).length;
    }

    function currentContext() {
        return {
            sources: { mine: els.srcMine.checked, known: els.srcKnown.checked, community: els.srcCommunity.checked },
            autoJoin: els.autoJoin.checked,
            profile: state.profile,
            routing: routingPreferences.payload(),
            base: state.base ? { kind: state.base.kind, label: state.base.label, points: state.base.points } : null,
        };
    }

    function syncContext() {
        if (route.setContext(currentContext())) {
            onRouteChanged();
        } else {
            renderSources();
        }
    }

    // ------------------------------------------------------------------
    // Charakter trasy i nazwane ustawienia użytkownika. To warstwa POD
    // istniejącym profilem roweru; zmiana zawsze unieważnia segmenty modelu.
    // ------------------------------------------------------------------
    function renderRoutingPreferences() {
        var current = routingPreferences.current();
        if (els.characterRow) {
            els.characterRow.querySelectorAll('[data-character]').forEach(function (btn) {
                btn.classList.toggle('active', btn.dataset.character === current.character);
            });
        }
        if (els.routingSaved) {
            var first = els.routingSaved.options[0];
            els.routingSaved.innerHTML = '';
            els.routingSaved.appendChild(first);
            current.configs.filter(function (c) { return c.bikeProfile === current.profile; }).forEach(function (c) {
                var option = document.createElement('option');
                option.value = String(c.id);
                option.textContent = c.name + (c.isDefault ? ' ★' : '');
                els.routingSaved.appendChild(option);
            });
            els.routingSaved.value = current.configId ? String(current.configId) : '';
        }
        if (els.routingControls) {
            els.routingControls.querySelectorAll('[data-routing-group]').forEach(function (select) {
                var group = current.preferences[select.dataset.routingGroup] || {};
                var keys = select.dataset.routingKeys.split(',');
                var values = keys.map(function (key) { return Number(group[key] || 0); });
                select.value = String(values.every(function (v) { return v === values[0]; }) ? values[0] : 0);
            });
        }
        if (els.routingName && current.configId) {
            var selected = current.configs.find(function (c) { return c.id === current.configId; });
            if (selected && document.activeElement !== els.routingName) els.routingName.value = selected.name;
        }
        if (els.routingSave) els.routingSave.textContent = current.configId ? __('Zapisz zmiany') : __('Zapisz jako…');
        if (els.routingDelete) els.routingDelete.hidden = !current.configId;
        if (els.routingDefault) els.routingDefault.hidden = !current.configId;
    }

    function routingChanged() {
        renderRoutingPreferences();
        syncContext();
    }

    if (els.characterRow) {
        els.characterRow.querySelectorAll('[data-character]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                routingPreferences.setCharacter(btn.dataset.character);
                routingChanged();
            });
        });
    }
    if (els.routingControls) {
        els.routingControls.querySelectorAll('[data-routing-group]').forEach(function (select) {
            select.addEventListener('change', function () {
                routingPreferences.setGroup(select.dataset.routingGroup, select.dataset.routingKeys.split(','), select.value);
                routingChanged();
            });
        });
    }
    if (els.routingSaved) {
        els.routingSaved.addEventListener('change', function () {
            if (els.routingSaved.value) {
                routingPreferences.selectConfig(Number(els.routingSaved.value));
            } else {
                routingPreferences.usePreset(routingPreferences.current().character);
                if (els.routingName) els.routingName.value = '';
            }
            routingChanged();
        });
    }

    function routingPost(url, payload) {
        payload.csrf_token = cfg.csrfToken;
        return fetch(url, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); });
    }

    function loadRoutingConfigs(useDefault) {
        return fetch(cfg.api.routingList).then(function (r) { return r.json(); }).then(function (data) {
            if (!data.success) return;
            routingPreferences.setConfigs(data.items || [], !!useDefault);
            renderRoutingPreferences();
            if (useDefault) syncContext();
        }).catch(function () {
            if (els.routingMsg) els.routingMsg.textContent = __('Nie udało się wczytać ustawień. Używany jest preset profilu.');
        });
    }

    if (els.routingSave) {
        els.routingSave.addEventListener('click', function () {
            var name = els.routingName.value.trim();
            if (!name) { els.routingMsg.textContent = __('Podaj nazwę ustawień.'); return; }
            var current = routingPreferences.current();
            routingPost(cfg.api.routingSave, {
                id: current.configId, name: name, bikeProfile: current.profile,
                character: current.character, preferences: current.preferences,
            }).then(function (data) {
                if (!data.success) { els.routingMsg.textContent = data.error || __('Nie udało się zapisać ustawień.'); return; }
                routingPreferences.upsert(data.item);
                els.routingMsg.textContent = __('Ustawienia zapisane.');
                routingChanged();
            }).catch(function () { els.routingMsg.textContent = __('Nie udało się zapisać ustawień.'); });
        });
    }
    if (els.routingDelete) {
        els.routingDelete.addEventListener('click', function () {
            var id = routingPreferences.current().configId;
            if (!id) return;
            routingPost(cfg.api.routingDelete, { id: id }).then(function (data) {
                if (!data.success) { els.routingMsg.textContent = __('Nie udało się usunąć ustawień.'); return; }
                routingPreferences.remove(id);
                els.routingName.value = '';
                els.routingMsg.textContent = __('Ustawienia usunięte.');
                routingChanged();
            });
        });
    }
    if (els.routingDefault) {
        els.routingDefault.addEventListener('click', function () {
            var id = routingPreferences.current().configId;
            if (!id) return;
            routingPost(cfg.api.routingDefault, { id: id }).then(function (data) {
                if (!data.success) { els.routingMsg.textContent = __('Nie udało się ustawić konfiguracji domyślnej.'); return; }
                els.routingMsg.textContent = __('Ustawiono jako domyślne dla tego profilu.');
                loadRoutingConfigs(false);
            });
        });
    }

    // Status „Aktualna baza" to JEDYNE miejsce, które mówi, z czego korzysta
    // planer; nagłówek listy mówi tylko, czy zaznaczenie jeszcze prowadzi trasę.
    function renderSources() {
        var base = state.base;
        var count = sourceCount();
        var mode = base ? (base.kind === 'gpx' ? 'gpx' : 'route') : (count ? 'sources' : 'none');
        els.base.dataset.mode = mode;
        els.sources.dataset.mode = base ? 'base' : 'sources';

        els.baseSwatch.hidden = mode !== 'route';
        els.baseFile.hidden = mode !== 'gpx';
        els.baseCount.hidden = mode !== 'sources';
        els.baseClear.hidden = !base;
        els.baseMeta.hidden = !base;

        if (mode === 'none') {
            els.baseName.textContent = __('Brak źródeł');
            els.baseSub.textContent = __('Trasa po zwykłych drogach (OSM). Zaznacz źródło poniżej, żeby planer wolał odcinki Ridemore.');
        } else if (mode === 'sources') {
            els.baseName.textContent = __('Wszystkie zaznaczone źródła');
            els.baseCount.textContent = String(count);
            els.baseSub.textContent = __('Jedzie po odcinkach z zaznaczonych źródeł. Gdzie ich brakuje albo się nie łączą — dojeżdża zwykłymi drogami (OSM).');
        } else if (mode === 'route') {
            els.baseName.textContent = base.label;
            els.baseMeta.textContent = formatKm(base.km);
            els.baseSwatch.style.background = base.color || RIDE_COLOR;
            els.baseSub.textContent = __('Jedzie po tej trasie. Dojazdy do niej i zjazdy z niej — zwykłymi drogami (OSM).');
        } else {
            els.baseName.textContent = __('Mój GPX');
            els.baseMeta.textContent = base.file + ' · ' + formatKm(base.km);
            els.baseSub.textContent = __('Jedzie po Twoim pliku. Dojazdy i zjazdy — zwykłymi drogami (OSM). Pliku nie zapisujemy — działa tylko w tej sesji.');
        }

        els.listLabel.textContent = base
            ? __('Tylko pokaż na mapie — planer trzyma się bazy')
            : __('Pokaż na mapie i prowadź po nich');
        els.priorityHint.hidden = !!base || count < 2;

        els.autoJoin.disabled = mode !== 'sources';
        if (mode === 'none') {
            els.autoJoinHint.textContent = __('Działa, gdy zaznaczysz przejazdy albo trasy.');
        } else if (base) {
            els.autoJoinHint.textContent = __('Nie dotyczy — planer trzyma się wybranej bazy.');
        } else {
            els.autoJoinHint.textContent = __('Planer porównuje dystans, jakość i ciągłość trasy. Gdy już prowadzi korytarzem Ridemore, utrzymuje go między kolejnymi punktami i może zaakceptować kilka kilometrów dodatkowej drogi, jeśli OSRM potwierdzi przejezdny powrót.');
        }
    }

    // ------------------------------------------------------------------
    // Konkretna baza: wyszukiwarka (znane trasy + moje przejazdy, nigdy
    // cudze — §27) albo własny plik GPX. Wybór zastępuje poprzednią bazę;
    // punkty trasy zostają, a pusty planer dostaje START i CEL na końcach bazy.
    // ------------------------------------------------------------------
    function drawBase() {
        baseLayer.clearLayers();
        baseLines = [];
        if (!state.base) return;
        var gpx = state.base.kind === 'gpx';
        var casing = L.polyline(state.base.points, { color: gpx ? '#FFFFFF' : INK, weight: gpx ? 8 : 8.5, opacity: gpx ? 1 : 0.85, interactive: false }).addTo(baseLayer);
        var line = L.polyline(state.base.points, { color: gpx ? INK : (state.base.color || RIDE_COLOR), weight: gpx ? 3.5 : 5, interactive: false }).addTo(baseLayer);
        baseLines = [casing, line];
    }

    function showBaseError(message) {
        els.baseError.textContent = message;
        els.baseError.hidden = false;
    }

    function setBase(base) {
        base.km = lengthKm(base.points);
        state.base = base;
        els.baseError.hidden = true;
        closePicker();
        drawBase();
        if (route.waypoints.length === 0) {
            var first = base.points[0];
            var last = base.points[base.points.length - 1];
            route.replaceWaypoints([{ lat: first[0], lng: first[1] }, { lat: last[0], lng: last[1] }]);
        }
        route.setContext(currentContext());
        var bounds = base.points.reduce(function (b, p) {
            return [[Math.min(b[0][0], p[0]), Math.min(b[0][1], p[1])], [Math.max(b[1][0], p[0]), Math.max(b[1][1], p[1])]];
        }, [[base.points[0][0], base.points[0][1]], [base.points[0][0], base.points[0][1]]]);
        map.fitBounds(bounds, { padding: [40, 40] });
        onRouteChanged();
    }

    els.baseClear.addEventListener('click', function () {
        state.base = null;
        drawBase();
        syncContext();
    });

    function openPicker() {
        els.picker.hidden = false;
        els.pickBtn.setAttribute('aria-expanded', 'true');
        els.pickerQuery.value = '';
        els.pickerQuery.focus();
        runPickerSearch();
    }

    function closePicker() {
        els.picker.hidden = true;
        els.pickBtn.setAttribute('aria-expanded', 'false');
    }

    els.pickBtn.addEventListener('click', function () {
        if (els.picker.hidden) openPicker(); else closePicker();
    });
    els.pickerCancel.addEventListener('click', closePicker);
    els.picker.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closePicker();
            els.pickBtn.focus();
        }
    });
    els.pickerQuery.addEventListener('input', function () {
        clearTimeout(state.pickerTimer);
        state.pickerTimer = setTimeout(runPickerSearch, 300);
    });

    function searchType(type, q) {
        var url = cfg.api.sourceSearch + '?typ=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q);
        return fetch(url).then(function (r) { return r.json(); })
            .then(function (data) { return data && data.success ? data.items : []; })
            .catch(function () { return []; });
    }

    function runPickerSearch() {
        var q = els.pickerQuery.value.trim();
        var seq = ++state.pickerSeq;
        Promise.all([searchType('trasa', q), searchType('przejazd', q)]).then(function (res) {
            if (seq !== state.pickerSeq) return;
            els.pickerResults.innerHTML = '';
            var groups = [
                { type: 'trasa', title: __('Znane trasy'), items: res[0] },
                { type: 'przejazd', title: __('Moje przejazdy'), items: res[1] },
            ];
            var any = false;
            groups.forEach(function (g) {
                if (!g.items.length) return;
                any = true;
                var head = document.createElement('div');
                head.className = 'planner-picker__group';
                head.textContent = g.title;
                els.pickerResults.appendChild(head);
                g.items.slice(0, 8).forEach(function (item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'planner-pick-row';
                    btn.innerHTML = '<span class="planner-pick-row__swatch"></span><span class="planner-pick-row__txt"><b></b><small></small></span>';
                    btn.querySelector('.planner-pick-row__swatch').style.background = g.type === 'trasa' ? (item.color || RIDE_COLOR) : RIDE_COLOR;
                    btn.querySelector('b').textContent = item.label;
                    btn.querySelector('small').textContent = item.meta;
                    btn.addEventListener('click', function () { pickSource(g.type, item); });
                    els.pickerResults.appendChild(btn);
                });
            });
            if (!any) {
                var empty = document.createElement('div');
                empty.className = 'planner-picker__empty';
                empty.textContent = __('Nic nie znaleziono.');
                els.pickerResults.appendChild(empty);
            }
        });
    }

    function pickSource(type, item) {
        var url = cfg.api.sourceGeometry + '?typ=' + encodeURIComponent(type) + '&ref=' + encodeURIComponent(item.ref);
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            if (!data.success) {
                showBaseError(data.error || __('Nie udało się wczytać śladu.'));
                return;
            }
            setBase({
                kind: type === 'trasa' ? 'route' : 'ride',
                label: data.label || item.label,
                color: data.color || item.color || RIDE_COLOR,
                points: data.points,
            });
        }).catch(function () {
            showBaseError(__('Nie udało się wczytać śladu — spróbuj ponownie.'));
        });
    }

    els.gpxBtn.addEventListener('click', function () {
        els.gpxFile.value = '';
        els.gpxFile.click();
    });
    els.gpxFile.addEventListener('change', function () {
        var file = els.gpxFile.files && els.gpxFile.files[0];
        if (!file) return;
        var form = new FormData();
        form.append('csrf_token', cfg.csrfToken);
        form.append('gpx', file);
        els.gpxBtn.disabled = true;
        fetch(cfg.api.sourceUpload, { method: 'POST', body: form }).then(function (r) { return r.json(); }).then(function (data) {
            els.gpxBtn.disabled = false;
            if (!data.success || !data.points || data.points.length < 2) {
                showBaseError(data.error || __('Nie udało się wczytać pliku.'));
                return;
            }
            setBase({ kind: 'gpx', label: __('Mój GPX'), file: data.label || file.name, points: data.points });
        }).catch(function () {
            els.gpxBtn.disabled = false;
            showBaseError(__('Nie udało się wczytać pliku — spróbuj ponownie.'));
        });
    });

    // ------------------------------------------------------------------
    // Skarby — jedyna warstwa, która NADAL jedzie przez AJAX po bboxie.
    // Skarb to PUNKT, nigdy linia bazowa: „Po drodze" wstawia go między dwa
    // najbliższe punkty trasy (zjazd i powrót), „Jedź tu (cel)" — na koniec.
    // ------------------------------------------------------------------
    function addTreasure(t, asDestination) {
        var point = { lat: t.lat, lng: t.lon, type: 'treasure', label: __('Skarb: {name}', { name: t.name }) };
        var index = route.waypoints.length;
        if (!asDestination && route.waypoints.length >= 2) {
            var seg = route.nearestSegment(t.lat, t.lon);
            if (seg >= 0) index = seg + 1;
        }
        if (route.insertWaypoint(index, point)) onRouteChanged();
    }

    function loadLayers() {
        var b = map.getBounds();
        var url = cfg.api.layers + '?south=' + b.getSouth() + '&west=' + b.getWest()
            + '&north=' + b.getNorth() + '&east=' + b.getEast();
        fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            if (!data || !data.success) return;

            treasureLayer.clearLayers();
            if (els.treasureToggle.checked) {
                (data.treasures || []).forEach(function (t) {
                    var marker = L.circleMarker([t.lat, t.lon], {
                        radius: 7, color: '#fff', weight: 2, fillColor: getCss('--star'), fillOpacity: 1,
                    }).addTo(treasureLayer);
                    var wrap = document.createElement('div');
                    wrap.className = 'planner-treasure-pop';
                    wrap.innerHTML = '<b></b><div class="planner-treasure-pop__actions">'
                        + '<button type="button" class="via"></button><button type="button" class="end"></button></div>';
                    wrap.querySelector('b').textContent = t.name;
                    wrap.querySelector('.via').textContent = __('Po drodze');
                    wrap.querySelector('.end').textContent = __('Jedź tu (cel)');
                    wrap.querySelector('.via').addEventListener('click', function () { addTreasure(t, false); marker.closePopup(); });
                    wrap.querySelector('.end').addEventListener('click', function () { addTreasure(t, true); marker.closePopup(); });
                    marker.bindPopup(wrap);
                });
            }
        }).catch(function () { /* warstwa tła — cicha porażka, nie blokuje plannera */ });
    }

    map.on('moveend', function () {
        clearTimeout(state.layersTimer);
        state.layersTimer = setTimeout(loadLayers, 400);
    });
    els.treasureToggle.addEventListener('change', loadLayers);
    loadLayers();

    // ------------------------------------------------------------------
    // Przeglądarka po `mouseup` sama dokłada `click` na wspólnym przodku
    // miejsca wciśnięcia i puszczenia przycisku. Leaflet tłumi taki klik
    // tylko po przeciągnięciu MAPY albo markera, a na czas chwytu linii
    // przeciąganie mapy jest wyłączone — klik docierał więc do
    // map.on('click') jak zwykłe kliknięcie i DOPISYWAŁ drugi punkt na
    // KOŃCU trasy (START → A → NOWY → B → CEL → NOWY). `click` leci w tym
    // samym zadaniu przeglądarki co `mouseup`, więc zdjęcie blokady
    // w setTimeout(0) nie zje żadnego późniejszego, prawdziwego kliknięcia.
    // ------------------------------------------------------------------
    function swallowGestureClick() {
        function swallow(ev) {
            ev.stopPropagation();
            ev.preventDefault();
            release();
        }
        function release() {
            document.removeEventListener('click', swallow, true);
        }
        document.addEventListener('click', swallow, true);
        setTimeout(release, 0);
    }

    // ------------------------------------------------------------------
    // Markery — WIDOK waypointów modelu (id → marker), uzgadniany po każdej
    // zmianie przez syncMarkers(). Marker nie jest częścią modelu.
    // ------------------------------------------------------------------
    var markers = {};
    var markerTypes = {};
    var draggingMarkerId = null;

    function pinHtml(type) {
        var cls = type === 'start' ? 'start' : (type === 'end' ? 'end' : (type === 'treasure' ? 'treasure' : 'via'));
        var letter = type === 'start' ? 'S' : (type === 'end' ? 'C' : '');
        return '<div class="planner-map-pin ' + cls + '"><div class="bubble"><div class="in">' + letter + '</div></div></div>';
    }

    // Widoczna pinezka JEST uchwytem markera. iconAnchor [14, 34]: czubek
    // „kropli" (.bubble 28×28 obrócona o -45°, ostry róg ~20 px pod jej
    // środkiem) wypada dokładnie na współrzędnych punktu.
    function pinIcon(type) {
        return L.divIcon({ html: pinHtml(type), className: '', iconSize: [28, 28], iconAnchor: [14, 34] });
    }

    function createMarker(wp) {
        var marker = L.marker([wp.lat, wp.lng], { icon: pinIcon(wp.type), draggable: true }).addTo(map);
        markerTypes[wp.id] = wp.type;
        // Klik (bez przeciągnięcia) w pinezkę to NIE klik w mapę. Leaflet oddaje
        // mapie klik markera, którego nikt nie słucha — bez tego słuchacza
        // map.on('click') dopisywał nowy CEL w miejscu istniejącego punktu.
        // Marker ma bubblingMouseEvents:false, więc sam słuchacz zatrzymuje klik.
        marker.on('click', function (e) { L.DomEvent.stop(e); });
        marker.on('dragstart', function () {
            cancelLineDrag();
            draggingMarkerId = wp.id;
        });
        // Przesunięcie ISTNIEJĄCEGO punktu — START, pośredni i CEL tak samo:
        // zmieniają się wyłącznie jego współrzędne, bieżący indeks bierzemy
        // z modelu (po `id`) w chwili upuszczenia. Nowy waypoint tu nie powstaje.
        marker.on('dragend', function () {
            draggingMarkerId = null;
            swallowGestureClick();
            var index = route.indexOf(wp.id);
            if (index < 0) return;
            var ll = marker.getLatLng();
            route.moveWaypoint(index, ll.lat, ll.lng);
            onRouteChanged();
        });
        return marker;
    }

    function syncMarkers() {
        var alive = {};
        route.waypoints.forEach(function (wp) {
            alive[wp.id] = true;
            var marker = markers[wp.id];
            if (!marker) {
                markers[wp.id] = createMarker(wp);
                return;
            }
            if (wp.id === draggingMarkerId) return; // nie szarpać markera w trakcie przeciągania
            if (markerTypes[wp.id] !== wp.type) {
                marker.setIcon(pinIcon(wp.type));
                markerTypes[wp.id] = wp.type;
            }
            var ll = marker.getLatLng();
            if (ll.lat !== wp.lat || ll.lng !== wp.lng) {
                marker.setLatLng([wp.lat, wp.lng]);
            }
        });
        Object.keys(markers).forEach(function (id) {
            if (alive[id]) return;
            map.removeLayer(markers[id]);
            delete markers[id];
            delete markerTypes[id];
        });
    }

    // ------------------------------------------------------------------
    // Linie odcinków — segments[i] to ZAWSZE para waypoints[i] → [i+1].
    // Odcinek czekający na przeliczenie jest przerywany; policzony — pełny,
    // a jego kawałki z danych Ridemore (zaznaczone źródła albo baza) mają
    // ciemną obwódkę, jak linie znanych tras. Reszta to łączniki OSM.
    // ------------------------------------------------------------------
    function drawSegments() {
        segmentLayer.clearLayers();
        var casings = [];
        var lines = [];
        route.segments.forEach(function (seg, i) {
            if (!seg.pending) {
                seg.ridemore.forEach(function (piece) {
                    casings.push(L.polyline(seg.coords.slice(piece.from, piece.to + 1), {
                        color: INK, weight: 9, opacity: 0.85, interactive: false,
                    }).addTo(segmentLayer));
                });
            }
            lines.push(drawSegment(seg, i));
        });
        // bringToBack od końca: odcinki pod skarbami, obwódki pod odcinkami,
        // baza najniżej — i każda grupa w naturalnej kolejności między sobą.
        for (var a = lines.length - 1; a >= 0; a--) lines[a].bringToBack();
        for (var b = casings.length - 1; b >= 0; b--) casings[b].bringToBack();
        for (var c = baseLines.length - 1; c >= 0; c--) baseLines[c].bringToBack();
    }

    function drawSegment(seg, index) {
        var line = L.polyline(seg.coords, {
            color: routeColor,
            weight: 5.5,
            opacity: seg.pending ? 0.75 : 1,
            dashArray: seg.pending ? '2 10' : null,
        }).addTo(segmentLayer);
        var path = line.getElement(); // null przy rendererze Canvas — wtedy kursor zostaje domyślny
        if (path) path.style.cursor = 'grab';
        line.on('mousedown', function (e) { startLineDrag(index, e); });
        return line;
    }

    // ------------------------------------------------------------------
    // Przeciąganie ODCINKA trasy → NOWY punkt między jego dwoma końcami.
    // `mousedown` na linii odcinka `index` ustala od razu wszystko: miejsce
    // chwytu, poprzedni punkt (index), następny (index + 1) i pozycję
    // wstawienia (index + 1) — zapamiętane jako para id. Mysz śledzimy na
    // `document`, więc puszczenie poza mapą też kończy chwyt. To NIE jest
    // przesuwanie punktu — to robi wyłącznie marker (wyżej).
    // ------------------------------------------------------------------
    var DRAG_TOLERANCE_PX = 3; // jak clickTolerance Leafletu: krótszy ruch to klik w linię, nie przeciąganie
    var lineDrag = null;
    var previewLine = L.polyline([], { color: routeColor, weight: 4, dashArray: '2 8', opacity: 0.9, interactive: false }).addTo(map);

    function startLineDrag(index, e) {
        if (lineDrag || draggingMarkerId !== null) return;
        var from = route.waypoints[index];
        var to = route.waypoints[index + 1];
        if (!from || !to) return;
        L.DomEvent.stop(e);                         // mapa nie dostaje tego mousedown
        L.DomEvent.preventDefault(e.originalEvent); // i przeglądarka nie zaznacza tekstu w trakcie
        lineDrag = {
            segmentIndex: index,
            insertIndex: index + 1,
            fromId: from.id,
            toId: to.id,
            startPoint: map.mouseEventToContainerPoint(e.originalEvent),
            latlng: e.latlng,
            moved: false,
            handle: null,
            mapWasDraggable: map.dragging.enabled(),
        };
        map.dragging.disable();
        document.addEventListener('mousemove', onLineDragMove);
        document.addEventListener('mouseup', onLineDragEnd);
    }

    function onLineDragMove(ev) {
        if (!lineDrag) return;
        var point = map.mouseEventToContainerPoint(ev);
        if (!lineDrag.moved && point.distanceTo(lineDrag.startPoint) < DRAG_TOLERANCE_PX) return;
        var latlng = map.containerPointToLatLng(point);
        lineDrag.latlng = latlng;
        if (!lineDrag.moved) {
            lineDrag.moved = true;
            map.getContainer().style.cursor = 'grabbing';
            lineDrag.handle = L.marker(latlng, {
                icon: L.divIcon({ html: '<div class="planner-mid-handle"></div>', className: '', iconSize: [16, 16], iconAnchor: [8, 8] }),
                interactive: false,
                keyboard: false,
            }).addTo(dragHandleLayer);
            previewLine.bringToFront();
        }
        lineDrag.handle.setLatLng(latlng);
        var from = route.waypoints[route.indexOf(lineDrag.fromId)];
        var to = route.waypoints[route.indexOf(lineDrag.toId)];
        if (from && to) {
            previewLine.setLatLngs([[from.lat, from.lng], [latlng.lat, latlng.lng], [to.lat, to.lng]]);
        }
    }

    function endLineDrag() {
        var drag = lineDrag;
        lineDrag = null;
        document.removeEventListener('mousemove', onLineDragMove);
        document.removeEventListener('mouseup', onLineDragEnd);
        dragHandleLayer.clearLayers();
        previewLine.setLatLngs([]);
        map.getContainer().style.cursor = '';
        if (drag && drag.mapWasDraggable) map.dragging.enable();
        return drag;
    }

    function cancelLineDrag() {
        if (lineDrag) endLineDrag();
    }

    function onLineDragEnd() {
        if (!lineDrag) return;
        var drag = endLineDrag();
        swallowGestureClick();
        if (!drag.moved) return; // sam klik w linię nie zmienia trasy
        var wp = route.insertBetween(drag.fromId, drag.toId, { lat: drag.latlng.lat, lng: drag.latlng.lng });
        if (wp) onRouteChanged();
    }

    // ------------------------------------------------------------------
    // Lista punktów
    // ------------------------------------------------------------------
    function labelFor(wp, i) {
        if (wp.label) return wp.label;
        if (wp.type === 'start') return 'START';
        if (wp.type === 'end') return 'CEL';
        return 'Punkt ' + i;
    }

    // Udział danych Ridemore w odcinku — odpowiedź na „co, jeśli źródła się
    // nie łączą": widać, ile odcinka jedzie po Ridemore, a ile łączy OSM.
    function shareText(seg) {
        if (seg.pending || !seg.distanceM) return '';
        var pct = Math.max(0, Math.min(100, Math.round(seg.ridemoreM / seg.distanceM * 100)));
        if (state.base) return __('baza') + ' ' + pct + '% · OSM ' + (100 - pct) + '%';
        if (!sourceCount() || !pct) return '';
        return 'Ridemore ' + pct + '% · OSM ' + (100 - pct) + '%';
    }

    // Powód wyboru odcinka przez warstwę Ridemore — tylko gdy planer ODSZEDŁ
    // od najkrótszej trasy OSRM na rzecz sprawdzonych odcinków. Liczby zbiorcze
    // (osoby, przejazdy), nigdy kto; pojedynczej osoby nie pokazujemy wcale.
    function whyText(seg) {
        var v = seg.variant;
        if (seg.pending || state.base || !v || v.chosen !== 'ridemore') return null;
        var extraKm = Math.round(v.extraM / 100) / 10;
        var text = extraKm > 0
            ? __('+{km} km — prowadzi sprawdzonymi odcinkami Ridemore', { km: extraKm.toFixed(1) })
            : __('Prowadzi sprawdzonymi odcinkami Ridemore');
        var parts = (v.corridors || []).map(function (c) {
            var name = c.label || (c.source === 'mine' ? __('Moje przejazdy') : (c.source === 'known' ? __('Znane trasy') : __('Przejazdy społeczności')));
            var line = name + ' · ' + (c.lengthM / 1000).toFixed(1) + ' km';
            if (c.source === 'community' && c.riders >= 2) {
                line += ' · ' + __('liczba osób: {n}', { n: c.riders });
            }
            return line;
        });
        if (v.reason) {
            parts.push(__('Wpływ preferencji: {m} m · bonus popularności: {p} m', {
                m: Math.round(v.reason.roadPreferenceM + v.reason.profileCompatibilityM),
                p: Math.round(v.reason.popularityBonusM),
            }));
        }
        return { text: text, title: parts.join('\n') };
    }

    function renderWaypointList() {
        var has = route.waypoints.length > 0;
        els.empty.style.display = has ? 'none' : '';
        els.wpList.style.display = has ? '' : 'none';
        els.addHint.style.display = has ? '' : 'none';
        els.wpList.innerHTML = '';

        route.waypoints.forEach(function (wp, i) {
            var row = document.createElement('div');
            row.className = 'planner-wp-row';
            // Numer w kółku = pozycja w kolejności (START 0, pierwszy punkt
            // pośredni 1, …) — ten sam, co w „Punkt N" obok.
            var pin = wp.type === 'start' ? 'S' : (wp.type === 'end' ? 'C' : String(i));
            row.innerHTML = '<div class="planner-wp-pin ' + wp.type + '">' + pin + '</div>'
                + '<div class="planner-wp-label"><div class="planner-wp-title"></div>'
                + '<div class="planner-wp-sub">' + wp.lat.toFixed(5) + ', ' + wp.lng.toFixed(5) + '</div></div>'
                + '<button type="button" class="planner-wp-x" aria-label="Usuń punkt">&times;</button>';
            row.querySelector('.planner-wp-title').textContent = labelFor(wp, i);
            row.querySelector('.planner-wp-x').addEventListener('click', function () {
                var index = route.indexOf(wp.id);
                if (index < 0) return;
                route.removeWaypoint(index);
                onRouteChanged();
            });
            els.wpList.appendChild(row);

            var seg = route.segments[i];
            if (seg) {
                var s = document.createElement('div');
                s.className = 'planner-seg';
                if (seg.pending) {
                    s.innerHTML = '<span>…</span>';
                } else {
                    var km = (seg.distanceM / 1000).toFixed(1);
                    var min = Math.round(seg.distanceM / 1000 / state.speedKmh * 60);
                    s.innerHTML = '<span>' + km + ' km</span><span>' + min + ' min</span><span class="rm"></span>';
                    s.querySelector('.rm').textContent = shareText(seg);
                    var why = whyText(seg);
                    if (why) {
                        var w = document.createElement('span');
                        w.className = 'planner-seg__why';
                        w.textContent = why.text;
                        w.title = why.title;
                        s.appendChild(w);
                    }
                }
                els.wpList.appendChild(s);
            }
        });
    }

    // ------------------------------------------------------------------
    // Klik w pustą mapę = nowy CEL: jawnie na KONIEC listy (indeks = jej długość).
    // ------------------------------------------------------------------
    map.on('click', function (e) {
        if (lineDrag) return;
        var wp = route.insertWaypoint(route.waypoints.length, { lat: e.latlng.lat, lng: e.latlng.lng });
        if (wp) onRouteChanged();
    });

    // ------------------------------------------------------------------
    // Render + przeliczanie trasy
    // ------------------------------------------------------------------
    function render() {
        syncMarkers();
        drawSegments();
        renderWaypointList();
        renderSources();
        updateSaveState();
        els.onboarding.style.display = route.waypoints.length ? 'none' : '';
    }

    // Po KAŻDEJ zmianie punktów albo źródeł: od razu widok z modelu (zmienione
    // odcinki jako „pending"), potem przeliczenie.
    function onRouteChanged() {
        render();
        scheduleRecompute();
    }

    function updateSaveState() {
        els.saveBtn.disabled = !els.name.value.trim() || !route.isComplete();
    }

    function showRoutingError(message) {
        els.saveMsg.style.display = '';
        els.saveMsg.textContent = message || 'Usługa routingu chwilowo niedostępna — spróbuj ponownie.';
    }

    function scheduleRecompute() {
        clearTimeout(state.recomputeTimer);
        state.recomputeTimer = setTimeout(recompute, 300);
    }

    function recompute() {
        if (route.waypoints.length < 2) {
            els.statsCard.style.display = 'none';
            if (els.routingProgress) els.routingProgress.hidden = true;
            updateSaveState();
            return;
        }
        var requestId = ++state.routingSeq;
        if (els.routingProgress) els.routingProgress.hidden = false;
        var version = route.version;
        var payload = route.routingPayload();
        payload.csrf_token = cfg.csrfToken;
        fetch(cfg.api.calculate, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); }).then(function (data) {
            // Model zmienił się, zanim wróciła odpowiedź: ta dotyczy innej
            // kolejności punktów albo innych źródeł, a nowsze przeliczenie
            // jest już w drodze.
            if (version !== route.version) return;
            if (requestId === state.routingSeq && els.routingProgress) els.routingProgress.hidden = true;
            if (!data.success) {
                showRoutingError(data.error);
                return;
            }
            if (!route.applyRouting(version, data)) return;
            els.saveMsg.style.display = 'none';

            var durationMin = Math.round(data.distanceKm / state.speedKmh * 60);
            els.statsCard.style.display = '';
            els.statDistance.innerHTML = data.distanceKm.toFixed(1).replace('.', ',') + '<small> km</small>';
            els.statAscent.innerHTML = '<small>po zapisie</small>';
            els.statTime.innerHTML = Math.floor(durationMin / 60) + ':' + String(durationMin % 60).padStart(2, '0');
            state.durationMin = durationMin;

            render();
        }).catch(function () {
            if (version !== route.version) return;
            if (requestId === state.routingSeq && els.routingProgress) els.routingProgress.hidden = true;
            showRoutingError();
        });
    }

    // ------------------------------------------------------------------
    // Typ roweru (słownik `bike_type`, konfiguracja w panelu „Planer"): założona
    // prędkość, profil silnika routingu i zasady warstwy Ridemore (np. szosa
    // omija znane trasy bez asfaltu, MTB woli terenowe).
    // ------------------------------------------------------------------
    function selectProfileButton(code) {
        if (!els.profileRow) return;
        var buttons = els.profileRow.querySelectorAll('.planner-profile-btn');
        var chosen = null;
        buttons.forEach(function (b) { if (b.dataset.profile === code) chosen = b; });
        chosen = chosen || buttons[0] || null;
        buttons.forEach(function (b) { b.classList.toggle('active', b === chosen); });
        if (chosen) {
            state.speedKmh = parseFloat(chosen.dataset.speed) || 25;
            state.profile = chosen.dataset.profile || '';
            routingPreferences.setProfile(state.profile, true);
            renderRoutingPreferences();
        }
    }
    selectProfileButton(null);

    if (els.profileRow) {
        els.profileRow.querySelectorAll('.planner-profile-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                selectProfileButton(btn.dataset.profile || '');
                if (els.routingName) els.routingName.value = '';
                if (route.setContext(currentContext())) {
                    onRouteChanged();
                    return;
                }
                renderWaypointList();
                if (route.segments.length) recompute();
            });
        });
    }

    els.name.addEventListener('input', updateSaveState);

    // Wyczyść = punkty trasy. Baza (konkretna trasa / GPX) ma własne „×".
    function resetPlanner() {
        cancelLineDrag();
        clearTimeout(state.recomputeTimer);
        route.replaceWaypoints([]);
        state.routeId = null;
        state.durationMin = null;
        els.statsCard.style.display = 'none';
        els.name.value = '';
        els.gpxLink.style.display = 'none';
        els.saveMsg.style.display = 'none';
        render();
    }

    els.clearBtn.addEventListener('click', resetPlanner);

    // ------------------------------------------------------------------
    // Zapis — wyłącznie w pełni policzonej trasy (żaden odcinek „pending"),
    // inaczej do bazy poszłaby geometria sprzed ostatniej zmiany.
    // ------------------------------------------------------------------
    els.saveBtn.addEventListener('click', function () {
        if (!route.isComplete()) return;
        els.saveBtn.disabled = true;

        var payload = {
            csrf_token: cfg.csrfToken,
            name: els.name.value.trim(),
            waypoints: route.routingPayload().waypoints,
            coords: route.mergedCoords(),
            durationMin: state.durationMin,
            routeId: state.routeId,
            profile: state.profile,
            routing: routingPreferences.payload(),
        };
        fetch(cfg.api.save, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); }).then(function (data) {
            updateSaveState();
            els.saveMsg.style.display = '';
            if (!data.success) {
                els.saveMsg.textContent = data.error || 'Nie udało się zapisać trasy.';
                return;
            }
            state.routeId = data.routeId;
            els.saveMsg.textContent = 'Trasa zapisana.';
            els.gpxLink.href = cfg.api.gpx + data.routeId + '/gpx';
            els.gpxLink.style.display = '';
        }).catch(function () {
            updateSaveState();
            els.saveMsg.style.display = '';
            els.saveMsg.textContent = 'Nie udało się zapisać trasy — spróbuj ponownie.';
        });
    });

    // ------------------------------------------------------------------
    // Wczytanie istniejącej trasy (edycja) — zapisana kolejność 1:1
    // ------------------------------------------------------------------
    if (cfg.existingRouteId) {
        fetch(cfg.api.load + cfg.existingRouteId).then(function (r) { return r.json(); }).then(function (data) {
            if (!data.success) return;
            els.name.value = data.route.name;
            // Typ roweru, z którym trasę zapisano (gdy dalej jest w słowniku).
            selectProfileButton(data.route.profile || null);
            routingPreferences.restore(data.route.routing || null);
            renderRoutingPreferences();
            route.setContext(currentContext());
            route.replaceWaypoints(data.route.waypoints);
            if (route.waypoints.length) {
                map.fitBounds(route.waypoints.map(function (w) { return [w.lat, w.lng]; }), { padding: [40, 40] });
            }
            onRouteChanged();
        });
    }

    loadRoutingConfigs(!cfg.existingRouteId);
    route.setContext(currentContext());
    render();
})(typeof window !== 'undefined' ? window : this);
