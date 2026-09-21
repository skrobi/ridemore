// tests/planner_kolejnosc.js
// Sterownik Node dla tests/planner_kolejnosc_test.php. Wczytuje PRAWDZIWY
// assets/js/planner.js — bez DOM-u plik kończy się na modelu trasy
// (RidemorePlannerRoute) — i przepuszcza przez niego scenariusze ze
// zgłoszenia 2026-09-18 („NOWY ląduje na końcu"). Te same wywołania co UI:
// przeciągnięcie odcinka = insertBetween(id poprzedniego, id następnego),
// przesunięcie markera = moveWaypoint(indeks po id). Wypisuje JSON, asercje
// robi PHP (jeden runner testów w projekcie).
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'planner.js'), 'utf8');
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: 'planner.js' });
const Route = sandbox.RidemorePlannerRoute;
if (!Route || typeof Route.create !== 'function') {
    process.stdout.write(JSON.stringify({ error: 'planner.js nie wystawił RidemorePlannerRoute.create' }));
    process.exit(1);
}

const byLabel = (r, label) => r.waypoints.find((w) => w.label === label);
const idOf = (r, label) => byLabel(r, label).id;
const indexOfLabel = (r, label) => r.indexOf(idOf(r, label));

function snapshot(r) {
    const payload = r.routingPayload();
    return {
        order: r.waypoints.map((w) => w.label),
        types: r.waypoints.map((w) => w.type),
        ids: r.waypoints.map((w) => w.id),
        coords: r.waypoints.map((w) => [w.lat, w.lng]),
        payloadOrder: payload.waypoints.map((w) => w.label),
        payloadCoords: payload.waypoints.map((w) => [w.lat, w.lng]),
        segmentCount: r.segments.length,
    };
}

// Odpowiedź serwera dla BIEŻĄCEJ kolejności: odcinek i biegnie od punktu i
// do i+1 (z jednym punktem „po drodze", żeby dało się odróżnić policzony
// odcinek od prostej `pending`).
function fakeRouting(r) {
    const segments = [];
    for (let i = 0; i < r.waypoints.length - 1; i++) {
        const a = r.waypoints[i];
        const b = r.waypoints[i + 1];
        segments.push({
            coords: [[a.lat, a.lng], [(a.lat + b.lat) / 2 + 0.001, (a.lng + b.lng) / 2], [b.lat, b.lng]],
            distanceM: 1000 * (i + 1),
            durationS: 100,
            ridemoreM: 0,
            ridemore: [],
        });
    }
    return { success: true, segments };
}

function build(labels) {
    const r = Route.create(25);
    labels.forEach((label, i) => {
        // Jak klik w pustą mapę: jawnie na koniec.
        r.insertWaypoint(r.waypoints.length, { lat: 50 + i * 0.01, lng: 20 + i * 0.02, label });
    });
    r.applyRouting(r.version, fakeRouting(r));
    return r;
}

function runSteps(r, steps) {
    return steps.map(([operation, fn]) => {
        const before = snapshot(r);
        const result = fn();
        r.applyRouting(r.version, fakeRouting(r)); // jak odpowiedź serwera po zmianie
        return { operation, before, after: snapshot(r), returned: result ? result.label : null };
    });
}

const out = {};

// --- Testy akceptacyjne 1–7 z punktu 11 zgłoszenia ---------------------
{
    const r = build(['START', 'A', 'B', 'CEL']);
    out.akceptacja = runSteps(r, [
        ['TEST 1: przeciągnięcie odcinka A–B', () => r.insertBetween(idOf(r, 'A'), idOf(r, 'B'), { lat: 50.2, lng: 20.2, label: 'NOWY' })],
        ['TEST 2: przesunięcie A', () => r.moveWaypoint(indexOfLabel(r, 'A'), 50.31, 20.31)],
        ['TEST 3: przesunięcie START', () => r.moveWaypoint(indexOfLabel(r, 'START'), 49.91, 19.91)],
        ['TEST 4: przesunięcie CEL', () => r.moveWaypoint(indexOfLabel(r, 'CEL'), 50.91, 20.91)],
        ['TEST 5: przeciągnięcie odcinka NOWY–B', () => r.insertBetween(idOf(r, 'NOWY'), idOf(r, 'B'), { lat: 50.4, lng: 20.4, label: 'NOWY2' })],
        ['TEST 6: przesunięcie NOWY2', () => r.moveWaypoint(indexOfLabel(r, 'NOWY2'), 50.51, 20.51)],
    ]);
}

// --- Punkt 7 zgłoszenia: START → A → B → C → CEL, sekwencja edycji ------
{
    const r = build(['START', 'A', 'B', 'C', 'CEL']);
    out.sekwencja = runSteps(r, [
        ['przesunięcie B', () => r.moveWaypoint(indexOfLabel(r, 'B'), 50.12, 20.12)],
        ['przeciągnięcie odcinka B_new–C', () => r.insertBetween(idOf(r, 'B'), idOf(r, 'C'), { lat: 50.15, lng: 20.15, label: 'D' })],
        ['przesunięcie START', () => r.moveWaypoint(0, 49.8, 19.8)],
        ['przesunięcie D', () => r.moveWaypoint(indexOfLabel(r, 'D'), 50.17, 20.17)],
    ]);
}

// --- Zły indeks nigdy nie kończy się dopisaniem na koniec -----------------
{
    const r = build(['START', 'A', 'B', 'CEL']);
    const errors = [];
    [7, -1, 1.5, undefined, '2'].forEach((bad) => {
        try {
            r.insertWaypoint(bad, { lat: 1, lng: 1, label: 'ZŁY' });
            errors.push('brak błędu');
        } catch (e) {
            errors.push(e.name);
        }
    });
    let moveError = 'brak błędu';
    try { r.moveWaypoint(4, 1, 1); } catch (e) { moveError = e.name; }
    out.zlyIndeks = { errors, moveError, after: snapshot(r) };
}

// --- insertBetween z parą, która już nie sąsiaduje, nic nie wstawia -------
{
    const r = build(['START', 'A', 'B', 'CEL']);
    const a = idOf(r, 'A');
    const b = idOf(r, 'B');
    r.insertBetween(a, b, { lat: 50.2, lng: 20.2, label: 'NOWY' });
    const stale = r.insertBetween(a, b, { lat: 50.3, lng: 20.3, label: 'DRUGI' }); // A i B nie sąsiadują już
    out.nieaktualnaPara = { returned: stale, after: snapshot(r) };
}

// --- Klik w mapę = jawnie na koniec, „na początku" = indeks 0 -------------
{
    const r = build(['START', 'CEL']);
    r.insertWaypoint(r.waypoints.length, { lat: 51, lng: 21, label: 'KLIK' });
    r.insertWaypoint(0, { lat: 49, lng: 19, label: 'PRZED' });
    out.klikIPoczatek = snapshot(r);
}

// --- Odcinki zawsze wyrównane do par; odpowiedź dla starej wersji odrzucona
{
    const r = build(['START', 'A', 'B', 'CEL']);
    const oldBtoCel = r.segments[2].coords;
    const staleVersion = r.version;
    const staleResponse = fakeRouting(r); // policzona dla START, A, B, CEL
    r.insertBetween(idOf(r, 'A'), idOf(r, 'B'), { lat: 50.2, lng: 20.2, label: 'NOWY' });
    const afterInsert = {
        pending: r.segments.map((s) => s.pending),
        ends: r.segments.map((s) => [s.coords[0], s.coords[s.coords.length - 1]]),
        waypoints: r.waypoints.map((w) => [w.lat, w.lng]),
        bToCelKept: JSON.stringify(r.segments[3].coords) === JSON.stringify(oldBtoCel),
        complete: r.isComplete(),
    };
    const staleApplied = r.applyRouting(staleVersion, staleResponse);
    const afterStale = { pending: r.segments.map((s) => s.pending), count: r.segments.length };
    const freshApplied = r.applyRouting(r.version, fakeRouting(r));
    out.odcinki = { afterInsert, staleApplied, afterStale, freshApplied, complete: r.isComplete() };
}

// --- Kontekst routingu: źródła, przełącznik dołączania, baza ---------------
{
    const r = build(['START', 'A', 'CEL']);
    const computedCoords = JSON.stringify(r.segments.map((s) => s.coords));
    const v0 = r.version;
    const same = r.setContext({ sources: {}, autoJoin: false, base: null }); // jak na starcie: nic
    const znane = r.setContext({ sources: { known: true }, autoJoin: true, base: null });
    const afterKnown = {
        pending: r.segments.map((s) => s.pending),
        keptGeometry: JSON.stringify(r.segments.map((s) => s.coords)) === computedCoords,
        payload: JSON.parse(JSON.stringify(r.routingPayload())),
        versionBumped: r.version > v0,
    };
    r.applyRouting(r.version, fakeRouting(r));
    const baza = { kind: 'route', label: 'VeloDunajec', points: [[50, 20], [50.01, 20.01], [50.02, 20.04]] };
    const withBase = r.setContext({ sources: { known: true }, autoJoin: true, base: baza });
    r.applyRouting(r.version, fakeRouting(r));
    const vBase = r.version;
    // Przy aktywnej bazie zaznaczanie źródeł zmienia tylko mapę — trasa się nie przelicza.
    const sourcesUnderBase = r.setContext({ sources: { known: true, mine: true, community: true }, autoJoin: false, base: baza });
    const payloadBase = JSON.parse(JSON.stringify(r.routingPayload()));
    out.kontekst = {
        same,
        znane,
        afterKnown,
        withBase,
        sourcesUnderBase,
        versionUnderBase: r.version === vBase,
        completeUnderBase: r.isComplete(),
        payloadBase: { base: payloadBase.base, order: payloadBase.waypoints.map((w) => w.label) },
    };
}

// --- Profil roweru (Etap 2b) i powód wyboru (Etap 2c) -----------------------
{
    const r = build(['START', 'A', 'CEL']);
    r.setContext({ sources: { known: true }, autoJoin: true, profile: 'szosowy' });
    const response = fakeRouting(r);
    response.segments[0].variant = { chosen: 'ridemore', extraM: 1200, corridors: [{ source: 'known', label: 'Szlak', lengthM: 9000 }] };
    r.applyRouting(r.version, response);
    const variantKept = r.segments[0].variant && r.segments[0].variant.chosen === 'ridemore' && r.segments[1].variant === null;
    const v0 = r.version;
    // Przy włączonej warstwie profil zmienia wybór odcinków → przeliczenie.
    const withJoin = r.setContext({ sources: { known: true }, autoJoin: true, profile: 'mtb' });
    const payload = JSON.parse(JSON.stringify(r.routingPayload()));
    r.applyRouting(r.version, fakeRouting(r));
    // Bez warstwy też: typ roweru może mieć w panelu własny profil silnika.
    r.setContext({ sources: { known: true }, autoJoin: false, profile: 'mtb' });
    r.applyRouting(r.version, fakeRouting(r));
    const vOff = r.version;
    const withoutJoin = r.setContext({ sources: { known: true }, autoJoin: false, profile: 'szosowy' });
    const sameAgain = r.setContext({ sources: { known: true }, autoJoin: false, profile: 'szosowy' });
    const bogus = r.setContext({ sources: { known: true }, autoJoin: false, profile: 'Rakieta!' });
    out.profil = {
        variantKept,
        withJoin,
        bumped: r.version !== v0,
        payloadProfile: payload.profile,
        withoutJoin,
        versionOff: r.version === vOff,
        sameAgain,
        bogus,
        bogusProfile: r.routingPayload().profile,
    };
}

// --- Skarb „Po drodze": najbliższy odcinek trasy ---------------------------
{
    const r = Route.create(25);
    r.replaceWaypoints([{ lat: 50, lng: 20, label: 'START' }, { lat: 50, lng: 21, label: 'A' }, { lat: 51, lng: 21, label: 'CEL' }]);
    r.applyRouting(r.version, fakeRouting(r));
    const seg = r.nearestSegment(50.6, 21.05); // obok drugiego odcinka (A → CEL)
    r.insertWaypoint(seg + 1, { lat: 50.6, lng: 21.05, type: 'treasure', label: 'SKARB' });
    out.skarb = { seg, order: r.waypoints.map((w) => w.label), types: r.waypoints.map((w) => w.type) };
}

// --- Usuwanie: typy wynikają z pozycji ------------------------------------
{
    const r = build(['START', 'A', 'B', 'CEL']);
    r.removeWaypoint(0);
    const afterStart = snapshot(r);
    r.removeWaypoint(r.waypoints.length - 1);
    out.usuwanie = { afterStart, afterEnd: snapshot(r) };
}

// --- Limit 25 punktów: nadmiarowy punkt odrzucony, nie dopisany -----------
{
    const r = Route.create(25);
    for (let i = 0; i < 25; i++) r.insertWaypoint(r.waypoints.length, { lat: 50 + i * 0.001, lng: 20, label: 'P' + i });
    const extra = r.insertWaypoint(1, { lat: 51, lng: 21, label: 'NADMIAR' });
    out.limit = { returned: extra, count: r.waypoints.length, last: r.waypoints[24].label };
}

process.stdout.write(JSON.stringify(out));
