/* assets/js/planner/route-model.js
 * Czysty model trasy planera: uporządkowane waypointy, segmenty i kontekst
 * routingu. Nie zależy od DOM-u ani Leafleta; korzystają z niego aplikacja
 * przeglądarkowa oraz testy uruchamiane w Node.
 */
(function (root) {
    'use strict';

    // ==================================================================
    // MODEL TRASY (2026-09-18, przebudowa po zgłoszeniu usera: przeciągnięty
    // odcinek A–B dawał START → A → NOWY → B → CEL → NOWY, czyli „nowy punkt
    // ląduje na końcu"). TRASA = UPORZĄDKOWANA LISTA WAYPOINTÓW + GEOMETRIA
    // LICZONA WYŁĄCZNIE MIĘDZY KOLEJNYMI PARAMI.
    //
    // waypoints[i]  {id, lat, lng, type, label} — indeks = pozycja w kolejności
    //               przejazdu (0 = START, ostatni = CEL). `type` wynika
    //               z pozycji: START i CEL są specjalne tylko typem, poza tym
    //               to zwykłe punkty (skarb zostaje skarbem). `id` jest stałe —
    //               marker po nim znajduje SWÓJ bieżący indeks.
    // segments[i]   geometria waypoints[i] → waypoints[i+1]. ZAWSZE tyle, ile
    //               par, w tej samej kolejności: po zmianie nietknięte pary
    //               zachowują policzoną geometrię, nowe/zmienione są `pending`
    //               do odpowiedzi serwera — chwycona linia wskazuje więc
    //               BIEŻĄCĄ parę punktów, także zanim wróci przeliczenie.
    // context       z czego planer ma prowadzić („Źródła trasy", 2026-09-18):
    //               zaznaczone źródła + przełącznik dołączania ALBO baza
    //               (konkretna trasa / GPX). Zmiana kontekstu unieważnia
    //               odcinki tak samo jak przesunięcie punktu — do czasu
    //               odpowiedzi rysują się dotychczasową geometrią jako `pending`.
    //
    // Kolejność zmieniają WYŁĄCZNIE: insertWaypoint(index, point) — zawsze
    // z jawnym indeksem, nie ma „domyślnie na koniec" — insertBetween(fromId,
    // toId, point), removeWaypoint(index) i replaceWaypoints(list).
    // moveWaypoint(index, lat, lng) zmienia TYLKO współrzędne istniejącego
    // punktu i nigdy nie tworzy nowego.
    // ==================================================================
    var MAX_WAYPOINTS = 25; // = PlannerController::MAX_WAYPOINTS

    function createRoute(maxWaypoints) {
        var limit = maxWaypoints || MAX_WAYPOINTS;
        var nextId = 1;
        var route = {
            waypoints: [],
            segments: [],
            context: { sources: { mine: false, known: false, community: false }, autoJoin: false, base: null, profile: '' },
            version: 0,
        };

        function pairKeyAt(i) {
            var a = route.waypoints[i];
            var b = route.waypoints[i + 1];
            return a && b ? a.id + '>' + b.id : null;
        }

        // Ta sama para punktów w tych samych miejscach — niezależnie od kontekstu.
        function geomKey(i) {
            var a = route.waypoints[i];
            var b = route.waypoints[i + 1];
            return pairKeyAt(i) + '@' + a.lat + ',' + a.lng + ';' + b.lat + ',' + b.lng;
        }

        // Źródła liczą się tylko bez bazy; przełącznik — tylko przy źródłach.
        function contextSig() {
            var c = route.context;
            // Typ roweru ZAWSZE w sygnaturze: każdy typ może mieć w panelu własny
            // profil silnika routingu, więc zmienia także trasę bazową i dojazdy.
            var bike = '|bike:' + c.profile;
            if (c.base) {
                var p = c.base.points;
                return 'base:' + c.base.kind + ':' + c.base.label + ':' + p.length + ':' + p[0] + ':' + p[p.length - 1] + bike;
            }
            var s = c.sources;
            var on = (s.mine ? 'm' : '') + (s.known ? 'k' : '') + (s.community ? 'c' : '');
            return (on ? 'src:' + on + (c.autoJoin ? ':join' : '') : 'osm') + bike;
        }

        // Odcinek wolno przenieść z poprzedniego stanu tylko wtedy, gdy nic, od
        // czego zależy jego geometria, się nie zmieniło: ta sama para punktów,
        // te same współrzędne obu końców, ten sam kontekst.
        function segmentSig(i) {
            return geomKey(i) + '#' + contextSig();
        }

        // Zły indeks to błąd w kodzie wołającym — ma wybuchnąć, a nie po
        // cichu dopisać punkt na koniec, jak robiło dawne addWaypoint().
        function assertIndex(index, max, op) {
            if (typeof index !== 'number' || index !== Math.floor(index) || index < 0 || index > max) {
                throw new RangeError(op + ': indeks ' + index + ' poza zakresem 0..' + max);
            }
        }

        // Wspólne domknięcie KAŻDEJ zmiany punktów albo kontekstu.
        function afterChange() {
            var last = route.waypoints.length - 1;
            route.waypoints.forEach(function (wp, i) {
                if (wp.type === 'treasure') return;
                wp.type = i === 0 ? 'start' : (i === last ? 'end' : 'via');
            });

            var computed = {};
            var byGeom = {};
            route.segments.forEach(function (s) {
                if (!s.pending) computed[s.sig] = s;
                byGeom[s.geom] = s;
            });
            var segments = [];
            for (var j = 0; j < last; j++) {
                var sig = segmentSig(j);
                if (computed[sig]) {
                    segments.push(computed[sig]);
                    continue;
                }
                // Do czasu odpowiedzi: dotychczasowa geometria tej samej pary
                // (zmienił się tylko kontekst) albo odcinek prosty.
                var a = route.waypoints[j];
                var b = route.waypoints[j + 1];
                var previous = byGeom[geomKey(j)];
                segments.push({
                    key: pairKeyAt(j),
                    sig: sig,
                    geom: geomKey(j),
                    pending: true,
                    coords: previous ? previous.coords : [[a.lat, a.lng], [b.lat, b.lng]],
                    distanceM: null,
                    durationS: null,
                    ridemoreM: 0,
                    ridemore: [],
                });
            }
            route.segments = segments;
            route.version++;
        }

        function makeWaypoint(point) {
            return {
                id: nextId++,
                lat: Number(point.lat),
                lng: Number(point.lng),
                type: point.type === 'treasure' ? 'treasure' : 'via',
                label: point.label || '',
            };
        }

        route.pairKeyAt = pairKeyAt;

        route.indexOf = function (id) {
            for (var i = 0; i < route.waypoints.length; i++) {
                if (route.waypoints[i].id === id) return i;
            }
            return -1;
        };

        route.insertWaypoint = function (index, point) {
            assertIndex(index, route.waypoints.length, 'insertWaypoint');
            if (route.waypoints.length >= limit) return null;
            var wp = makeWaypoint(point);
            route.waypoints.splice(index, 0, wp);
            afterChange();
            return wp;
        };

        // Przeciągnięty odcinek: nowy punkt DOKŁADNIE między `fromId` a `toId`
        // (indeks = indeks poprzedniego + 1). Para jest ustalona w chwili
        // chwytu — jeśli do upuszczenia przestała sąsiadować, nic nie
        // wstawiamy, zamiast zgadywać miejsce.
        route.insertBetween = function (fromId, toId, point) {
            var i = route.indexOf(fromId);
            if (i < 0 || !route.waypoints[i + 1] || route.waypoints[i + 1].id !== toId) return null;
            return route.insertWaypoint(i + 1, point);
        };

        route.moveWaypoint = function (index, lat, lng) {
            assertIndex(index, route.waypoints.length - 1, 'moveWaypoint');
            var wp = route.waypoints[index];
            wp.lat = Number(lat);
            wp.lng = Number(lng);
            afterChange();
            return wp;
        };

        route.removeWaypoint = function (index) {
            assertIndex(index, route.waypoints.length - 1, 'removeWaypoint');
            var removed = route.waypoints.splice(index, 1)[0];
            afterChange();
            return removed;
        };

        // Cała lista naraz, w podanej kolejności (wczytanie zapisanej trasy,
        // końce wybranej bazy, wyczyszczenie planera).
        route.replaceWaypoints = function (points) {
            route.waypoints = (points || []).slice(0, limit).map(makeWaypoint);
            route.segments = [];
            afterChange();
        };

        // Zwraca true, gdy kontekst naprawdę się zmienił (i odcinki czekają na
        // przeliczenie). Zaznaczanie źródeł przy aktywnej bazie zmienia tylko
        // mapę — trasa się wtedy nie przelicza.
        route.setContext = function (ctx) {
            var before = contextSig();
            var s = ctx.sources || {};
            route.context = {
                sources: { mine: !!s.mine, known: !!s.known, community: !!s.community },
                autoJoin: !!ctx.autoJoin,
                // Kod typu roweru ze słownika — serwer i tak sprawdza go ze słownikiem.
                profile: typeof ctx.profile === 'string' && /^[a-z0-9_]{1,64}$/.test(ctx.profile) ? ctx.profile : '',
                base: ctx.base && ctx.base.points && ctx.base.points.length >= 2
                    ? { kind: ctx.base.kind, label: ctx.base.label || '', points: ctx.base.points }
                    : null,
            };
            if (contextSig() === before) return false;
            afterChange();
            return true;
        };

        // Dokładnie to, co idzie do /api/planer/oblicz: waypointy w KOLEJNOŚCI
        // tablicy (serwer liczy odcinek i → i+1 i niczego nie sortuje) i kontekst.
        route.routingPayload = function () {
            var c = route.context;
            return {
                waypoints: route.waypoints.map(function (w) {
                    return { lat: w.lat, lng: w.lng, type: w.type, label: w.label };
                }),
                sources: { mine: c.sources.mine, known: c.sources.known, community: c.sources.community },
                autoJoin: c.autoJoin,
                profile: c.profile,
                base: c.base ? { points: c.base.points, label: c.base.label } : null,
            };
        };

        // Odpowiedź serwera wolno przyjąć WYŁĄCZNIE dla tej wersji modelu,
        // z której zbudowano zapytanie — inaczej odcinki liczone dla starej
        // kolejności (albo starych źródeł) nadpisałyby nowe.
        route.applyRouting = function (version, data) {
            var count = route.waypoints.length - 1;
            if (version !== route.version || !data || !Array.isArray(data.segments) || data.segments.length !== count) {
                return false;
            }
            route.segments = data.segments.map(function (s, i) {
                return {
                    key: pairKeyAt(i),
                    sig: segmentSig(i),
                    geom: geomKey(i),
                    pending: false,
                    coords: s.coords,
                    distanceM: s.distanceM,
                    durationS: s.durationS,
                    ridemoreM: s.ridemoreM || 0,
                    ridemore: Array.isArray(s.ridemore) ? s.ridemore : [],
                    variant: s.variant || null,
                };
            });
            return true;
        };

        route.isComplete = function () {
            return route.waypoints.length >= 2
                && route.segments.length === route.waypoints.length - 1
                && route.segments.every(function (s) { return !s.pending; });
        };

        route.mergedCoords = function () {
            var merged = [];
            route.segments.forEach(function (seg) {
                seg.coords.forEach(function (c, i) {
                    if (i === 0 && merged.length) return; // pomiń duplikat granicy segmentów
                    merged.push([c[0], c[1]]);
                });
            });
            return merged;
        };

        // Odcinek trasy najbliższy punktowi — skarb „Po drodze" wstawia się
        // między jego końce (index + 1). Płaska aproksymacja (długość geogr.
        // skalowana cos(szerokości)) wystarcza do wyboru „który odcinek".
        route.nearestSegment = function (lat, lng) {
            var kx = Math.cos(lat * Math.PI / 180);
            var best = -1;
            var bestDist = Infinity;
            route.segments.forEach(function (seg, i) {
                var c = seg.coords;
                for (var j = 0; j < c.length - 1; j++) {
                    var ax = c[j][1] * kx, ay = c[j][0], bx = c[j + 1][1] * kx, by = c[j + 1][0];
                    var px = lng * kx, py = lat;
                    var dx = bx - ax, dy = by - ay;
                    var l2 = dx * dx + dy * dy;
                    var t = l2 > 0 ? Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / l2)) : 0;
                    var d = Math.hypot(px - (ax + t * dx), py - (ay + t * dy));
                    if (d < bestDist) { bestDist = d; best = i; }
                }
            });
            return best;
        };

        return route;
    }

    root.RidemorePlannerRoute = { MAX_WAYPOINTS: MAX_WAYPOINTS, create: createRoute };


})(typeof window !== 'undefined' ? window : this);
