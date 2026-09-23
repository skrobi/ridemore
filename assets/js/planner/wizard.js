/* assets/js/planner/wizard.js
 * Czysty model kreatora planera („gdzie warto pojechać”, Etap A —
 * tasks/active/planer-uproszczona-architektura.md). Bez DOM-u i Leafleta:
 * trzyma odpowiedzi na cztery pytania i buduje z nich zapytanie do
 * /api/planer/generuj. Korzysta z niego planner.js, a testy uruchamiają go
 * w Node (tests/planner_kolejnosc.js).
 */
(function (root) {
    'use strict';

    // Style jazdy — ta sama biała lista co PlannerController::STYLES; serwer
    // i tak sprawdza ją ponownie. „Odkrywczo” dochodzi w Etapie D.
    var STYLES = ['proven', 'fast'];
    var DEFAULT_STYLE = 'proven';

    function validPoint(p) {
        if (!p) return null;
        var lat = Number(p.lat);
        var lng = Number(p.lng);
        if (!isFinite(lat) || !isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            return null;
        }
        return { lat: lat, lng: lng };
    }

    function createWizard() {
        var wizard = {
            start: null,
            end: null,
            profile: '',
            style: DEFAULT_STYLE,
        };

        // kind: 'start' | 'end'. Zły punkt czyści odpowiedź zamiast zostawiać starą.
        wizard.setPoint = function (kind, point) {
            if (kind !== 'start' && kind !== 'end') {
                throw new RangeError('setPoint: nieznany punkt ' + kind);
            }
            wizard[kind] = validPoint(point);
            return wizard[kind];
        };

        wizard.setProfile = function (code) {
            wizard.profile = typeof code === 'string' && /^[a-z0-9_]{1,64}$/.test(code) ? code : '';
        };

        wizard.setStyle = function (style) {
            wizard.style = STYLES.indexOf(style) >= 0 ? style : DEFAULT_STYLE;
        };

        // Czego jeszcze brakuje do wygenerowania trasy — w kolejności pytań.
        wizard.missing = function () {
            var out = [];
            if (!wizard.start) out.push('start');
            if (!wizard.end) out.push('end');
            return out;
        };

        wizard.isReady = function () {
            return wizard.missing().length === 0;
        };

        wizard.payload = function (csrfToken) {
            if (!wizard.isReady()) return null;
            return {
                csrf_token: csrfToken,
                start: { lat: wizard.start.lat, lng: wizard.start.lng },
                end: { lat: wizard.end.lat, lng: wizard.end.lng },
                profile: wizard.profile,
                style: wizard.style,
            };
        };

        return wizard;
    }

    root.RidemorePlannerWizard = { STYLES: STYLES, DEFAULT_STYLE: DEFAULT_STYLE, create: createWizard };
})(typeof window !== 'undefined' ? window : this);
