// sidepanel.js — mózg rozszerzenia. Panel boczny to zwykła strona
// rozszerzenia (chrome-extension://...) więc ma bezpośredni dostęp do
// chrome.scripting/chrome.tabs bez pośrednika w background.js.
const RIDEMORE_ORIGIN = 'https://ridemore.bike';
const RIDEMORE_NEW_EVENT_URL = RIDEMORE_ORIGIN + '/wydarzenia/nowe';
const STORAGE_KEY = 'rmiState_v1';
const DICT_CACHE_KEY = 'rmiDictCache_v1';
const DICT_TTL_MS = 24 * 60 * 60 * 1000; // 24h — słowniki zmieniają się rzadko (patrz /admin/taksonomia)

const $ = (id) => document.getElementById(id);
// Zwykłe pola tekstowe/select/number/date/time — jeden input = jedna wartość
// = ta sama logika zapisu/odczytu/czyszczenia. Rowery (checkboxy) i
// organizator (dopasowanie po domenie) mają własną, osobną obsługę niżej.
const FIELD_IDS = [
  'f_organizer', 'f_organizerEmail', 'f_date', 'f_time', 'f_region', 'f_meetingLabel', 'f_lat', 'f_lng', 'f_mapLink',
  'f_gpxUrl', 'f_distance', 'f_elevation', 'f_surface', 'f_title', 'f_description', 'f_photoUrl',
  'f_pace', 'f_difficulty', 'f_isPaid', 'f_priceAmount', 'f_deposit', 'f_priceCurrency',
  'f_priceIncluded', 'f_priceExcluded', 'f_regUrl', 'f_regPhone', 'f_regEmail',
  // Dopisane 2026-08-07 (audyt pól kreatora) — dodane tu, więc automatycznie
  // objęte zapisem/odczytem/czyszczeniem stanu (saveState/restoreState/
  // clearAll wszystkie iterują FIELD_IDS), bez osobnych zmian w tamtych funkcjach.
  'f_endDate', 'f_dateIsFlexible', 'f_minParticipants', 'f_maxParticipants', 'f_priceUnit',
  'f_paymentDeadlineDays', 'f_cancellationDeadlineDays', 'f_cancellationPolicy',
];
const FIELD_LABELS = {
  f_title: 'tytuł', f_description: 'opis', f_organizer: 'organizator', f_date: 'data', f_time: 'godzina',
  f_meetingLabel: 'miejsce zbiórki', f_lat: 'szerokość', f_lng: 'długość', f_gpxUrl: 'link GPX',
  f_distance: 'dystans', f_elevation: 'przewyższenie', f_photoUrl: 'zdjęcie', f_regUrl: 'link do zapisów',
  f_regPhone: 'telefon do zapisów', f_regEmail: 'e-mail do zapisów', f_isPaid: 'wpisowe', f_priceAmount: 'kwota',
  f_deposit: 'zaliczka', f_priceCurrency: 'waluta',
  // Dopisane 2026-08-07 (audyt pól kreatora, patrz EventFormResource.php).
  f_endDate: 'data zakończenia', f_minParticipants: 'min. uczestników', f_maxParticipants: 'maks. uczestników',
  f_priceUnit: 'cena za', f_paymentDeadlineDays: 'dopłata do', f_cancellationDeadlineDays: 'odwołanie do',
  f_cancellationPolicy: 'polityka anulowania', f_organizerEmail: 'e-mail organizatora',
};

let dictionaries = null; // { difficulties, paces, bikeTypes, regionsFlat, regionsGrouped, surfaces, currencies, priceUnits, accommodationTypes, mealTypes }
let selectedOrganizer = null; // { id, name, slug, website } | null
const dismissedOrgDomains = new Set();
// Dni wielodniówki mają TERAZ własny edytor w panelu (sekcja "Plan — dni",
// addStageRow/readStages niżej) — AI-Engine dokłada do niego wykryty plan,
// user może go poprawić, gatherPayload() przekazuje dalej do fill.js.
let typeManuallySet = false;

let saveTimer = null;
function scheduleSave() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(saveState, 250);
}
function currentType() {
  const el = document.querySelector('input[name="f_type"]:checked');
  return el ? el.value : 'ustawka';
}

// ---------- Słowniki (API ridemore.bike) ----------
// Jedno wywołanie zwraca WSZYSTKIE słowniki formularza eventu naraz — patrz
// Support::eventFormDictOptions() po stronie ridemore.bike (dokładnie te
// same dane, co widzi kreator). Cache w chrome.storage.local, bo słowniki
// zmieniają się rzadko, a panel bywa otwierany dziesiątki razy dziennie.
async function loadDictionaries(forceRefresh) {
  const dictStatus = $('dictStatus');
  const cached = (await chrome.storage.local.get(DICT_CACHE_KEY))[DICT_CACHE_KEY];
  if (!forceRefresh && cached && (Date.now() - cached.fetchedAt) < DICT_TTL_MS) {
    applyDictionaries(cached.data);
    setStatus(dictStatus, 'Słowniki: z pamięci podręcznej (odśwież: 🔄 poniżej, jeśli coś nowego dodałeś w /admin/taksonomia).');
    return;
  }
  try {
    const res = await fetch(RIDEMORE_ORIGIN + '/api/dictionaries');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    await chrome.storage.local.set({ [DICT_CACHE_KEY]: { data, fetchedAt: Date.now() } });
    applyDictionaries(data);
    setStatus(dictStatus, 'Słowniki: zsynchronizowano z ridemore.bike.', 'ok');
  } catch (e) {
    if (cached) {
      applyDictionaries(cached.data);
      setStatus(dictStatus, 'Słowniki: nie udało się odświeżyć (' + e.message + ') — używam poprzednich.', 'err');
    } else {
      setStatus(dictStatus, 'Słowniki: nie udało się pobrać (' + e.message + '). Region/tempo/trudność/nawierzchnia/waluta trzeba będzie ustawić już w kreatorze.', 'err');
    }
  }
}

function flattenRegions(groups) {
  const out = [];
  (groups || []).forEach((g) => (g.items || []).forEach((it) => out.push(it)));
  return out;
}

function applyDictionaries(data) {
  dictionaries = {
    eventTypes: data.eventTypes || [],
    difficulties: data.difficulties || [],
    paces: data.paces || [],
    bikeTypes: data.bikeTypes || [],
    regionsGrouped: data.regions || [],
    regionsFlat: flattenRegions(data.regions),
    surfaces: data.surfaces || [],
    currencies: data.currencies || [],
    priceUnits: data.priceUnits || [],
    // Dopisane 2026-08-20 (audyt pól kreatora vs panel): słowniki dni
    // wielodniówki (krok Plan) — typy noclegu i posiłki per dzień. Ten sam
    // endpoint /api/dictionaries co reszta (Support::eventFormDictOptions()).
    accommodationTypes: data.accommodationTypes || [],
    mealTypes: data.mealTypes || [],
    // Dopisane 2026-08-21 — kategorie skarbów (tryb "🗺️ Dodaj skarb"):
    // taşıte z /api/dictionaries (Support::eventFormDictOptions), przekazywane
    // do silnika AI (mode='treasure') do walidacji kodów kategorii.
    treasureCategories: data.treasureCategories || [],
  };
  syncEventTypeRadios($('f_typeRow'), dictionaries.eventTypes);
  populateSelect($('f_region'), dictionaries.regionsGrouped, true);
  populateSelect($('f_surface'), dictionaries.surfaces);
  populateSelect($('f_pace'), dictionaries.paces);
  populateSelect($('f_difficulty'), dictionaries.difficulties);
  populateSelect($('f_priceCurrency'), dictionaries.currencies);
  if (!$('f_priceCurrency').value) {
    const pln = dictionaries.currencies.find((c) => c.code === 'PLN');
    if (pln) $('f_priceCurrency').value = 'PLN';
  }
  populateSelect($('f_priceUnit'), dictionaries.priceUnits);
  populateBikeTypeChips($('f_bikeTypes'), dictionaries.bikeTypes);
}

function populateSelect(selectEl, items, grouped) {
  const keep = selectEl.value;
  selectEl.innerHTML = '<option value="">— nie wybrano —</option>';
  if (grouped) {
    (items || []).forEach((g) => {
      if (g.label === null) {
        (g.items || []).forEach((it) => selectEl.appendChild(new Option(it.name, it.code)));
      } else {
        const og = document.createElement('optgroup');
        og.label = g.label;
        (g.items || []).forEach((it) => og.appendChild(new Option(it.name, it.code)));
        selectEl.appendChild(og);
      }
    });
  } else {
    (items || []).forEach((it) => selectEl.appendChild(new Option(it.name, it.code)));
  }
  if (keep && Array.from(selectEl.options).some((o) => o.value === keep)) selectEl.value = keep;
}

// "Typ wydarzenia" (patrz sidepanel.html) ma 3 radiobuttony wprost w HTML
// jako baza odporna na brak sieci — w odróżnieniu od reszty słowników niżej
// (populateSelect/populateBikeTypeChips), które CZYSZCZĄ i budują od zera,
// ta funkcja tylko DOKŁADA radio dla każdego kodu z ridemore.bike, którego
// jeszcze nie ma w DOM. Dzięki temu: (1) nowy typ dodany po stronie serwera
// (np. 'wyscig') pojawia się tu automatycznie, bez zmian w rozszerzeniu —
// to był realny problem: dodanie 4. typu do aplikacji wymagało osobnej,
// ręcznej aktualizacji tego pliku; (2) gdyby /api/dictionaries było akurat
// nieosiągalne, user i tak ma bazowe 3 typy do wyboru, nie pusty formularz.
// Listener 'change' dokładany tu ręcznie — delegowany listener z init()
// (DOMContentLoaded) przechwytuje TYLKO radiobuttony istniejące w chwili
// jego rejestracji, więc nowo dodane wymagają własnego wpięcia.
function syncEventTypeRadios(container, items) {
  (items || []).forEach((it) => {
    if (container.querySelector(`input[name="f_type"][value="${CSS.escape(it.code)}"]`)) return;
    const label = document.createElement('label');
    label.innerHTML = `<input type="radio" name="f_type" value="${it.code}"> ${it.name}`;
    label.querySelector('input').addEventListener('change', () => { typeManuallySet = true; syncTypeVisibility(); });
    container.appendChild(label);
  });
}

function populateBikeTypeChips(container, items) {
  const checked = getCheckedBikeTypes(container);
  container.innerHTML = '';
  items.forEach((it) => {
    const label = document.createElement('label');
    label.className = 'chip';
    label.innerHTML = `<input type="checkbox" value="${it.code}"> ${it.name}`;
    if (checked.includes(it.code)) label.querySelector('input').checked = true;
    label.querySelector('input').addEventListener('change', scheduleSave);
    container.appendChild(label);
  });
}
function getCheckedBikeTypes(container) {
  container = container || $('f_bikeTypes');
  return Array.from(container.querySelectorAll('input:checked')).map((i) => i.value);
}
function checkBikeType(code) {
  const input = $('f_bikeTypes').querySelector(`input[value="${CSS.escape(code)}"]`);
  if (input) input.checked = true;
}

// Proste dopasowanie tekstu (np. zeskanowanego z obcej strony albo
// wskazanego trybem 🎯) do prawdziwego kodu słownika ridemore.bike —
// zawieranie stringów po normalizacji polskich znaków, wystarcza dla
// krótkich nazw kategorii.
const PL_MAP = { ą: 'a', ć: 'c', ę: 'e', ł: 'l', ń: 'n', ó: 'o', ś: 's', ź: 'z', ż: 'z' };
function normPl(s) {
  return String(s || '').toLowerCase().replace(/[ąćęłńóśźż]/g, (ch) => PL_MAP[ch]).replace(/[^a-z0-9]+/g, ' ').trim();
}
function bestMatchDict(guess, items) {
  const g = normPl(guess);
  if (!g) return null;
  let best = null, bestScore = 0;
  for (const it of items || []) {
    const label = normPl(it.name);
    if (!label) continue;
    let score = 0;
    if (label === g) score = 100;
    else if (label.includes(g) || g.includes(label)) score = 60 + Math.min(label.length, g.length);
    else {
      const shared = g.split(' ').filter((w) => w.length > 2 && label.split(' ').includes(w)).length;
      if (shared) score = 20 + shared * 10;
    }
    if (score > bestScore) { bestScore = score; best = it; }
  }
  return bestScore >= 20 ? best : null;
}

// ---------- Organizator: dopasowanie po domenie ----------
async function matchOrganizerDomain(sourceUrl) {
  let host;
  try { host = new URL(sourceUrl).hostname.replace(/^www\./, ''); } catch (e) { return; }
  if (!host || dismissedOrgDomains.has(host)) return;
  try {
    const res = await fetch(RIDEMORE_ORIGIN + '/api/organizers/match-domain?domain=' + encodeURIComponent(host));
    if (!res.ok) return;
    const matches = await res.json();
    if (matches && matches.length) renderOrgMatchBox(matches, host);
  } catch (e) { /* cicho — to tylko podpowiedź, nie krytyczna ścieżka */ }
}
function renderOrgMatchBox(matches, host) {
  const box = $('orgMatchBox');
  box.hidden = false;
  box.innerHTML = `<div><b>Ta domena (${host}) pasuje do istniejącego organizatora na ridemore.bike:</b></div>`;
  matches.forEach((m) => {
    const row = document.createElement('div');
    row.className = 'orgmatch__row';
    row.innerHTML = `<span>${m.name}</span>`;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Użyj';
    btn.addEventListener('click', () => {
      selectedOrganizer = { id: m.id, name: m.name, slug: m.slug, website: m.website };
      renderSelectedOrganizer();
      box.hidden = true;
      scheduleSave();
    });
    row.appendChild(btn);
    box.appendChild(row);
  });
  const dismiss = document.createElement('button');
  dismiss.type = 'button';
  dismiss.className = 'dismiss';
  dismiss.textContent = 'Nie teraz';
  dismiss.style.marginTop = '6px';
  dismiss.addEventListener('click', () => { dismissedOrgDomains.add(host); box.hidden = true; });
  box.appendChild(dismiss);
}
function renderSelectedOrganizer() {
  const box = $('orgMatchBox');
  if (!selectedOrganizer) { box.hidden = true; box.innerHTML = ''; return; }
  box.hidden = false;
  box.innerHTML = '';
  const row = document.createElement('div');
  row.className = 'org-selected';
  row.innerHTML = `<span>✓ Wybrano istniejącego organizatora: <b>${selectedOrganizer.name}</b></span>`;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.textContent = 'Zmień';
  btn.addEventListener('click', () => { selectedOrganizer = null; box.hidden = true; box.innerHTML = ''; scheduleSave(); });
  row.appendChild(btn);
  box.appendChild(row);
}

// ---------- Stan (zapis/odczyt/czyszczenie) ----------
async function saveState() {
  const state = {
    type: currentType(),
    typeManuallySet,
    variants: readVariants(),
    stages: readStages(),
    equipment: readEquipment(),
    additionalDates: readAdditionalDates(),
    fields: {},
    bikeTypes: getCheckedBikeTypes(),
    selectedOrganizer,
  };
  FIELD_IDS.forEach((id) => {
    const el = $(id);
    if (!el) return;
    state.fields[id] = el.type === 'checkbox' ? el.checked : el.value;
  });
  const photoPreview = $('photoPreview');
  state.photoPreviewSrc = photoPreview.hidden ? '' : photoPreview.src;
  await chrome.storage.local.set({ [STORAGE_KEY]: state });
}
async function loadState() {
  const got = await chrome.storage.local.get(STORAGE_KEY);
  const state = got[STORAGE_KEY];
  if (!state) return;
  FIELD_IDS.forEach((id) => {
    const el = $(id);
    if (!el || !(id in (state.fields || {}))) return;
    if (el.type === 'checkbox') el.checked = !!state.fields[id];
    else el.value = state.fields[id];
  });
  if (state.type) {
    const radio = document.querySelector(`input[name="f_type"][value="${state.type}"]`);
    if (radio) radio.checked = true;
  }
  typeManuallySet = !!state.typeManuallySet;
  (state.variants || []).forEach((v) => addVariantRow(v));
  (state.additionalDates || []).forEach((iso) => addDateRow(iso));
  (state.equipment || []).forEach((e) => addEquipmentRow(e));
  (state.stages || []).forEach((s) => addStageRow(s));
  (state.bikeTypes || []).forEach((code) => checkBikeType(code));
  if (state.selectedOrganizer) { selectedOrganizer = state.selectedOrganizer; renderSelectedOrganizer(); }
  if (state.photoPreviewSrc) {
    $('photoPreview').src = state.photoPreviewSrc;
    $('photoPreview').hidden = false;
  }
}

function readVariants() {
  return Array.from(document.querySelectorAll('#variantsList .variant-row')).map((row) => ({
    name: row.querySelector('.v-name').value.trim(),
    distanceKm: row.querySelector('.v-dist').value ? parseFloat(row.querySelector('.v-dist').value) : null,
    elevationM: row.querySelector('.v-elev').value ? parseInt(row.querySelector('.v-elev').value, 10) : null,
    // Cena/zaliczka/limit/nawierzchnia per wariant (kreator: variants.php +
    // step-pieniadze.php) — dopisane 2026-08-20, patrz variantRowTpl.
    priceAmount: row.querySelector('.v-price').value ? parseFloat(row.querySelector('.v-price').value) : null,
    deposit: row.querySelector('.v-deposit').value ? parseFloat(row.querySelector('.v-deposit').value) : null,
    maxParticipants: row.querySelector('.v-max').value ? parseInt(row.querySelector('.v-max').value, 10) : null,
    surface: row.querySelector('.v-surface').value,
    // Wykryty (albo dograny ręcznie — patrz addVariantRow) plik GPX TEGO
    // wariantu — event-form.php wspiera własny GPX per wariant (Models\
    // EventRouteVariant), więc każdy wiersz niesie SWÓJ URL, nie jeden
    // wspólny dla całego eventu (patrz payload.gpxUrl gdzie indziej).
    gpxUrl: row.querySelector('.gpx-url').value || null,
  }));
}
function addVariantRow(v) {
  const tpl = $('variantRowTpl').content.cloneNode(true);
  const row = tpl.querySelector('.variant-row');
  // Select nawierzchni per wariant — opcje ze słownika (jeśli zdążył się
  // załadować; bez niego zostaje sam placeholder, jak reszta selectów).
  if (dictionaries) {
    populateSelect(row.querySelector('.v-surface'), dictionaries.surfaces);
  }
  if (v) {
    row.querySelector('.v-name').value = v.name || '';
    row.querySelector('.v-dist').value = v.distanceKm ?? '';
    row.querySelector('.v-elev').value = v.elevationM ?? '';
    row.querySelector('.v-price').value = v.priceAmount ?? '';
    row.querySelector('.v-deposit').value = v.deposit ?? '';
    row.querySelector('.v-max').value = v.maxParticipants ?? '';
    row.querySelector('.v-surface').value = v.surface || '';
    setRowGpx(row, v.gpxUrl || null);
  }
  row.querySelector('.v-remove').addEventListener('click', () => { row.remove(); scheduleSave(); });
  row.querySelector('.gpx-clear').addEventListener('click', () => { setRowGpx(row, null); scheduleSave(); });
  row.querySelector('.gpx-pick').addEventListener('click', (e) => pickRowGpx(e.currentTarget, row));
  $('variantsList').appendChild(row);
}

// Ręczne wskazanie GPX per wiersz (wariant LUB dzień wielodniówki) — NIE
// przez startPick()/applyPickResult() (te są zbudowane wokół JEDNEGO stałego
// id elementu na stronie, `$(targetId)`; wiersze wariantów i dni są
// dynamiczne/powielane, więc każdy potrzebuje własnej obsługi wyniku — ta
// funkcja aktualizuje badge/hidden-input TEGO wiersza przez setRowGpx(),
// zamiast zakładać jeden globalny cel). Reużywa tego samego mechanizmu
// wskazywania na stronie (rmiPickOnPage) co startPick().
async function pickRowGpx(button, row) {
  const tab = await getActiveTab();
  if (!tab || !tab.id) return;
  document.querySelectorAll('.pickbtn').forEach((b) => (b.disabled = true));
  button.classList.add('picking');
  const status = $('scanStatus');
  setStatus(status, 'Kliknij link do pliku GPX na stronie (Esc = anuluj)…');
  try {
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: rmiPickOnPage,
    });
    if (result && result.href) {
      setRowGpx(row, result.href);
      scheduleSave();
      setStatus(status, '✓ Wstawiono link GPX.', 'ok');
    } else if (result) {
      setStatus(status, 'Wskazany element nie ma linku (href) — kliknij sam link do pliku, nie tekst wokół.', 'err');
    } else {
      setStatus(status, 'Anulowano wskazywanie.');
    }
  } catch (e) {
    setStatus(status, 'Nie udało się uruchomić trybu wskazywania (' + e.message + ').', 'err');
  } finally {
    document.querySelectorAll('.pickbtn').forEach((b) => (b.disabled = false));
    button.classList.remove('picking');
  }
}
// Ustawia/pokazuje link GPX wiersza (wariant/dzień) — klasy gpx-badge/
// gpx-clear/gpx-url są wspólne dla obu szablonów (variantRowTpl/stageRowTpl).
function setRowGpx(row, url) {
  const badge = row.querySelector('.gpx-badge');
  const clearBtn = row.querySelector('.gpx-clear');
  row.querySelector('.gpx-url').value = url || '';
  badge.href = url || '#';
  badge.hidden = !url;
  clearBtn.hidden = !url;
}

// Dodatkowe terminy (turnusy) — dopisane 2026-08-07 po audycie pól kreatora
// (additional_edition_dates[], patrz EventFormResource::additionalDates).
// Prostsza lista niż warianty (sam tekst daty, bez plików/GPX).
function readAdditionalDates() {
  return Array.from(document.querySelectorAll('#datesList .date-row'))
    .map((row) => row.querySelector('.d-date').value)
    .filter(Boolean);
}
function addDateRow(dateIso) {
  const tpl = $('dateRowTpl').content.cloneNode(true);
  const row = tpl.querySelector('.date-row');
  if (dateIso) row.querySelector('.d-date').value = dateIso;
  row.querySelector('.d-remove').addEventListener('click', () => { row.remove(); scheduleSave(); });
  $('datesList').appendChild(row);
}

// ---------- Dni wielodniówki (edytor, dopisany 2026-08-20) ----------
// Odpowiednik kroku "Plan" kreatora (step-plan.php): każdy wiersz = dzień z
// datą/tytułem/startem/metą/dystansem/przewyższeniem/nawierzchnią/noclegiem/
// posiłkami/notatkami + własnym GPX. AI-Engine DOKŁADA wykryte dni
// (applyEngineResult -> addStageRow), użytkownik może je poprawić — wcześniej
// to wszystko trzeba było dopisywać ręcznie w kreatorze. readStages() czyta
// z powrotem do payloadu (gatherPayload), a fill.js mapuje na stages[].
function readStages() {
  return Array.from(document.querySelectorAll('#stagesList .stage-row')).map((row) => ({
    dateIso: row.querySelector('.s-date').value,
    title: row.querySelector('.s-title').value.trim(),
    startPoint: row.querySelector('.s-start').value.trim(),
    endPoint: row.querySelector('.s-end').value.trim(),
    distanceKm: row.querySelector('.s-dist').value ? parseFloat(row.querySelector('.s-dist').value) : null,
    elevationM: row.querySelector('.s-elev').value ? parseInt(row.querySelector('.s-elev').value, 10) : null,
    surface: row.querySelector('.s-surface').value,
    accommodationType: row.querySelector('.s-accom-type').value,
    accommodationName: row.querySelector('.s-accom-name').value.trim(),
    meals: Array.from(row.querySelectorAll('.s-meals input:checked')).map((i) => i.value),
    notes: row.querySelector('.s-notes').value.trim(),
    gpxUrl: row.querySelector('.gpx-url').value || null,
  }));
}
function addStageRow(v) {
  const tpl = $('stageRowTpl').content.cloneNode(true);
  const row = tpl.querySelector('.stage-row');
  // Selecty nawierzchni/typu noclegu + chipsy posiłków — opcje ze słowników
  // (jak reszta selectów; bez załadowanych słowników zostają placeholdery).
  if (dictionaries) {
    populateSelect(row.querySelector('.s-surface'), dictionaries.surfaces);
    populateSelect(row.querySelector('.s-accom-type'), dictionaries.accommodationTypes);
    populateMealChips(row.querySelector('.s-meals'), (v && v.meals) || []);
  }
  if (v) {
    row.querySelector('.s-date').value = v.dateIso || '';
    row.querySelector('.s-title').value = v.title || '';
    row.querySelector('.s-start').value = v.startPoint || '';
    row.querySelector('.s-end').value = v.endPoint || '';
    row.querySelector('.s-dist').value = v.distanceKm ?? '';
    row.querySelector('.s-elev').value = v.elevationM ?? '';
    row.querySelector('.s-surface').value = v.surface || '';
    row.querySelector('.s-accom-type').value = v.accommodationType || '';
    row.querySelector('.s-accom-name').value = v.accommodationName || '';
    row.querySelector('.s-notes').value = v.notes || '';
    setRowGpx(row, v.gpxUrl || null);
  }
  row.querySelector('.s-remove').addEventListener('click', () => { row.remove(); scheduleSave(); });
  row.querySelector('.gpx-clear').addEventListener('click', () => { setRowGpx(row, null); scheduleSave(); });
  row.querySelector('.gpx-pick').addEventListener('click', (e) => pickRowGpx(e.currentTarget, row));
  $('stagesList').appendChild(row);
}
function populateMealChips(container, checkedCodes) {
  container.innerHTML = '';
  ((dictionaries && dictionaries.mealTypes) || []).forEach((it) => {
    const label = document.createElement('label');
    label.className = 'chip';
    label.innerHTML = `<input type="checkbox" value="${it.code}"> ${it.name}`;
    if ((checkedCodes || []).includes(it.code)) label.querySelector('input').checked = true;
    label.querySelector('input').addEventListener('change', scheduleSave);
    container.appendChild(label);
  });
}

// ---------- Sprzęt (edytor, dopisany 2026-08-20) ----------
// Odpowiednik equipment[i] w kreatorze (step-dlakogo.php): nazwa + flaga
// "obowiązkowy". Wcześniej panel wysyłał gołe nazwy i wszystko w kreatorze
// lądowało jako NIEobowiązkowe (fill.js hardcodował mandatory:false).
function readEquipment() {
  return Array.from(document.querySelectorAll('#equipmentList .equipment-row')).map((row) => ({
    name: row.querySelector('.e-name').value.trim(),
    mandatory: row.querySelector('.e-mandatory').checked,
  }));
}
function addEquipmentRow(item) {
  const tpl = $('equipmentRowTpl').content.cloneNode(true);
  const row = tpl.querySelector('.equipment-row');
  if (item) {
    row.querySelector('.e-name').value = item.name || '';
    row.querySelector('.e-mandatory').checked = !!item.mandatory;
  }
  row.querySelector('.e-remove').addEventListener('click', () => { row.remove(); scheduleSave(); });
  $('equipmentList').appendChild(row);
}

async function getActiveTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab;
}
function setStatus(el, text, cls) {
  el.textContent = text || '';
  el.className = 'hint' + (cls ? ' ' + cls : '');
}

// Ustawia pole TYLKO gdy jest puste — kolejne skanowanie (np. wejście na
// podstronę "Kontakt" tego samego organizatora) DOKŁADA brakujące dane, nigdy
// nie nadpisuje tego, co już zebrano albo co użytkownik sam poprawił.
// Zwraca true, gdy realnie coś ustawiło (pod podsumowanie w engineScanActiveTab()).
function setIfEmpty(id, value) {
  const el = $(id);
  if (!el) return false;
  if ((el.value || '').toString().trim() === '' && value != null && value !== '') {
    el.value = value;
    return true;
  }
  return false;
}

function confidenceLabel(c) { return { low: 'niska', medium: 'średnia', high: 'wysoka' }[c] || c; }

// Ta sama zasada "dokładamy, nie nadpisujemy" co wszędzie indziej — patrz
// setIfEmpty(). Pola słownikowe wstawiamy wprost (Python/Groq po walidacji w
// analyze.py zwraca już prawdziwe kody albo null), gpx/zdjęcie/link do
// zapisów rozwiązujemy z indeksów względem list links/images przekazanych w
// zapytaniu (ten sam mechanizm, który wcześniej miał tryb Claude — silnik
// wskazuje ISTNIEJĄCY wpis zamiast wpisywać URL od siebie).
function applyEngineResult(d, pageContext) {
  const filled = [];
  const mark = (id, ok, label) => { if (ok) filled.push(label || FIELD_LABELS[id] || id); };
  // Przeniesione na górę funkcji (były zdefiniowane niżej) — blok wariantów
  // poniżej też potrzebuje linkAt() do rozwiązania gpxLinkIndex per wariant.
  const linkAt = (i) => (typeof i === 'number' && pageContext.links[i]) ? pageContext.links[i].href : null;
  const imgAt = (i) => (typeof i === 'number' && pageContext.images[i]) ? pageContext.images[i].src : null;

  // Typ wydarzenia — tylko jeśli user jeszcze nie kliknął radio ręcznie
  // (patrz typeManuallySet) i AI faktycznie zwróciło konkretny typ.
  if (d.eventType && !typeManuallySet) {
    const radio = document.querySelector(`input[name="f_type"][value="${CSS.escape(d.eventType)}"]`);
    if (radio && !radio.checked) { radio.checked = true; filled.push('typ wydarzenia'); }
  }

  // Wielodniówka: plan dzień-po-dniu DOKŁADANY do edytora dni (sekcja "Plan —
  // dni"), gdzie można go poprawić (nocleg/posiłki/notatki — to właśnie one
  // wymagały wcześniej ręcznego dopisywania w kreatorze). gpxLinkIndex per
  // dzień (Models\EventStage wspiera WŁASNY GPX per dzień) rozwiązujemy na
  // URL TU, wgrywany w kreatorze przez fill.js, weryfikacja w kroku "Plan".
  if (Array.isArray(d.stages) && d.stages.length > 1) {
    const seen = new Set(readStages().map((s) => (s.dateIso || '').trim() || (s.title || '').trim().toLowerCase() || ''));
    let addedStages = 0;
    d.stages.forEach((s, i) => {
      const key = (s.dateIso || '').trim() || (s.title || '').trim().toLowerCase() || '#' + i;
      if (seen.has(key)) return;
      seen.add(key);
      addStageRow({ ...s, gpxUrl: linkAt(s.gpxLinkIndex) });
      addedStages++;
    });
    if (addedStages) filled.push(addedStages + '-dniowy plan (edytor „Plan — dni”)');
  }

  // Warianty trasy — dokładamy do ISTNIEJĄCEJ listy panelu (ta sama zasada co
  // reszta pól: dokładamy, nie nadpisujemy — pomijamy nazwy już obecne, żeby
  // powtórny skan tej samej strony nie zdublował wierszy).
  if (Array.isArray(d.variants) && d.variants.length) {
    const existingNames = new Set(readVariants().map((v) => v.name.toLowerCase()).filter(Boolean));
    let addedVariants = 0;
    d.variants.forEach((v) => {
      if (!v.name || existingNames.has(v.name.toLowerCase())) return;
      addVariantRow({ ...v, gpxUrl: linkAt(v.gpxLinkIndex) });
      existingNames.add(v.name.toLowerCase());
      addedVariants++;
    });
    if (addedVariants) filled.push(addedVariants + ' wariant(y) trasy');
  }

  mark('f_title', setIfEmpty('f_title', d.title));
  mark('f_description', setIfEmpty('f_description', d.description));
  mark('f_organizer', setIfEmpty('f_organizer', d.organizer));
  mark('f_organizerEmail', setIfEmpty('f_organizerEmail', d.organizerEmail));
  mark('f_date', setIfEmpty('f_date', d.dateIso));
  mark('f_time', setIfEmpty('f_time', d.time));
  mark('f_endDate', setIfEmpty('f_endDate', d.endDate));
  if (d.dateIsFlexible && !$('f_dateIsFlexible').checked) { $('f_dateIsFlexible').checked = true; filled.push('termin elastyczny'); }
  // Dodatkowe terminy (turnusy) — dokładamy, nie nadpisujemy, pomijamy te już
  // obecne (ta sama zasada co warianty — powtórny skan nie duplikuje).
  if (Array.isArray(d.additionalDates) && d.additionalDates.length) {
    const existingDates = new Set(readAdditionalDates());
    let addedDates = 0;
    d.additionalDates.forEach((iso) => {
      if (!iso || existingDates.has(iso)) return;
      addDateRow(iso);
      existingDates.add(iso);
      addedDates++;
    });
    if (addedDates) filled.push(addedDates + ' dodatkowy(e) termin(y)');
  }
  mark('f_meetingLabel', setIfEmpty('f_meetingLabel', d.meetingPointLabel));
  if (d.distanceKm != null) mark('f_distance', setIfEmpty('f_distance', d.distanceKm));
  if (d.elevationM != null) mark('f_elevation', setIfEmpty('f_elevation', d.elevationM));

  if (d.region && !$('f_region').value) { $('f_region').value = d.region; filled.push('region'); }
  if (d.surface && !$('f_surface').value) { $('f_surface').value = d.surface; filled.push('nawierzchnia'); }
  if (d.pace && !$('f_pace').value) { $('f_pace').value = d.pace; filled.push('tempo'); }
  if (d.difficulty && !$('f_difficulty').value) { $('f_difficulty').value = d.difficulty; filled.push('trudność'); }
  if (Array.isArray(d.bikeTypes) && d.bikeTypes.length) {
    let any = false;
    d.bikeTypes.forEach((code) => {
      const input = $('f_bikeTypes').querySelector(`input[value="${CSS.escape(code)}"]`);
      if (input && !input.checked) { input.checked = true; any = true; }
    });
    if (any) filled.push('rowery');
  }
  // Sprzęt — nowy kształt d.equipment: [{name, mandatory}] (analogiczny do
  // equipment[i] w kreatorze, step-dlakogo.php); stary d.whatToBring (gołe
  // stringi) działa dalej jako fallback z mandatory:false. Dokładamy do
  // ISTNIEJĄCEJ listy edytora, pomijając nazwy już obecne.
  const equipItems = Array.isArray(d.equipment)
    ? d.equipment.map((e) => ({ name: e && e.name, mandatory: !!(e && e.mandatory) }))
    : (Array.isArray(d.whatToBring) ? d.whatToBring.map((n) => ({ name: n, mandatory: false })) : null);
  if (equipItems && equipItems.length) {
    const existingNames = new Set(readEquipment().map((e) => e.name.toLowerCase()).filter(Boolean));
    let addedEquip = 0;
    equipItems.forEach((it) => {
      const name = (it.name || '').trim();
      if (!name || existingNames.has(name.toLowerCase())) return;
      addEquipmentRow(it);
      existingNames.add(name.toLowerCase());
      addedEquip++;
    });
    if (addedEquip) filled.push('co zabrać');
  }
  // Limit uczestników CAŁEGO eventu (nie wariantu — ten ma swoje pole w
  // wierszu wariantu) — limitParticipants jest WYLICZANE (min LUB max
  // ustawione), tak samo jak po stronie backendu (EventFormResource), nie
  // ma tu osobnego checkboksa do zaznaczenia.
  if (d.minParticipants != null) mark('f_minParticipants', setIfEmpty('f_minParticipants', d.minParticipants));
  if (d.maxParticipants != null) mark('f_maxParticipants', setIfEmpty('f_maxParticipants', d.maxParticipants));

  if (d.isPaid && !$('f_isPaid').checked) { $('f_isPaid').checked = true; filled.push(FIELD_LABELS.f_isPaid); }
  if (d.priceAmount != null) mark('f_priceAmount', setIfEmpty('f_priceAmount', d.priceAmount));
  if (d.deposit != null) mark('f_deposit', setIfEmpty('f_deposit', d.deposit));
  if (d.priceCurrency && !$('f_priceCurrency').value) { $('f_priceCurrency').value = d.priceCurrency; filled.push(FIELD_LABELS.f_priceCurrency); }
  if (d.priceUnit && !$('f_priceUnit').value) { $('f_priceUnit').value = d.priceUnit; filled.push(FIELD_LABELS.f_priceUnit); }
  if (Array.isArray(d.priceIncluded) && d.priceIncluded.length && !$('f_priceIncluded').value.trim()) {
    $('f_priceIncluded').value = d.priceIncluded.join('\n'); filled.push('co w cenie');
  }
  if (Array.isArray(d.priceExcluded) && d.priceExcluded.length && !$('f_priceExcluded').value.trim()) {
    $('f_priceExcluded').value = d.priceExcluded.join('\n'); filled.push('czego nie w cenie');
  }
  if (d.paymentDeadlineDays != null) mark('f_paymentDeadlineDays', setIfEmpty('f_paymentDeadlineDays', d.paymentDeadlineDays));
  if (d.cancellationDeadlineDays != null) mark('f_cancellationDeadlineDays', setIfEmpty('f_cancellationDeadlineDays', d.cancellationDeadlineDays));
  mark('f_cancellationPolicy', setIfEmpty('f_cancellationPolicy', d.cancellationPolicy));

  mark('f_regPhone', setIfEmpty('f_regPhone', d.registrationPhone));
  mark('f_regEmail', setIfEmpty('f_regEmail', d.registrationEmail));

  mark('f_gpxUrl', setIfEmpty('f_gpxUrl', linkAt(d.gpxLinkIndex)));
  mark('f_regUrl', setIfEmpty('f_regUrl', linkAt(d.registrationLinkIndex)));

  const photoUrl = imgAt(d.photoImageIndex);
  if (photoUrl && ($('f_photoUrl').value || '').trim() === '') {
    $('f_photoUrl').value = photoUrl;
    $('photoPreview').src = photoUrl;
    $('photoPreview').hidden = false;
    filled.push(FIELD_LABELS.f_photoUrl);
  }

  scheduleSave();
  // Radio typu ustawione powyżej programowo (radio.checked, nie click) nie
  // odpala 'change', więc sekcje same się nie przeliczą — a to właśnie AI
  // często pierwszy raz "decyduje", że to wielodniówka (trzeba pokazać
  // edytor dni) albo pokrec_z_kims (schować pieniądze).
  syncTypeVisibility();
  return filled;
}

// ---------- AI-Engine (Groq) — adres mostu ridemore.bike ----------
// PHP jest tu wyłącznie mostem: JS (to rozszerzenie) nie potrafi odpalić
// procesu Pythona, więc panel woła lokalny endpoint ridemore.bike
// (/api/ai/engine-analyze, dev-only — patrz core/Utils/AiEngineBridge.php),
// który uruchamia skrypt Pythona (ai-engine/analyze.py) i zwraca jego wynik.
const ENGINE_URL_STORAGE = 'rmiEngineUrl';
const DEFAULT_ENGINE_URL = 'http://localhost/ridemore/api/ai/engine-analyze';

async function getEngineUrl() {
  const got = await chrome.storage.local.get(ENGINE_URL_STORAGE);
  return got[ENGINE_URL_STORAGE] || DEFAULT_ENGINE_URL;
}
async function saveEngineUrl(url) {
  await chrome.storage.local.set({ [ENGINE_URL_STORAGE]: (url || '').trim() || DEFAULT_ENGINE_URL });
}

// "Twoje dane" (imię/e-mail zgłaszającego) — trwałe, OSOBNE od
// STORAGE_KEY (per-skan): to Twoja własna tożsamość, nie fakt o evencie,
// więc nie ma być czyszczone przez "Wyczyść wszystkie pola" i nie ma go
// szukać AI na stronie źródłowej. Kreator wymaga tego pod submitter_name/
// submitter_email (script.php validateBeforeSubmit()), gdy sesja
// ridemore.bike nie jest zalogowana — patrz sidepanel.html komentarz.
const ME_STORAGE = 'rmiMe';
async function getMe() {
  const got = await chrome.storage.local.get(ME_STORAGE);
  return got[ME_STORAGE] || { name: '', email: '' };
}
async function saveMe(name, email) {
  await chrome.storage.local.set({ [ME_STORAGE]: { name: (name || '').trim(), email: (email || '').trim() } });
}

// Kompaktowa lista kodów+nazw pod walidację enumów po stronie Pythona (patrz
// ai-engine/analyze.py) — ten sam zestaw pól, co dawny buildExtractToolSchema,
// tylko konstruowany raz tutaj zamiast per-wywołanie w JS.
function engineDictionariesPayload(dicts) {
  const codes = (arr) => (arr || []).map((i) => ({ code: i.code, name: i.name }));
  return {
    regions: codes(dicts.regionsFlat),
    surfaces: codes(dicts.surfaces),
    paces: codes(dicts.paces),
    difficulties: codes(dicts.difficulties),
    bikeTypes: codes(dicts.bikeTypes),
    currencies: codes(dicts.currencies),
    // Dopisane 2026-08-07 (audyt pól kreatora) — pole priceUnit potrzebuje
    // walidacji enum po stronie Pythona tak jak reszta słowników.
    priceUnits: codes(dicts.priceUnits),
    // Dopisane 2026-08-20 — słowniki dni wielodniówki (nocleg/posiłki per
    // dzień); analyze.py może z nich korzystać przy wykrywaniu planu.
    accommodationTypes: codes(dicts.accommodationTypes),
    mealTypes: codes(dicts.mealTypes),
    // Dopisane 2026-08-21 — kategorie skarbów do walidacji kodów po stronie
    // Pythona (analyze.py::_validate_treasure).
    treasureCategories: codes(dicts.treasureCategories),
  };
}

async function engineScanActiveTab() {
  const status = $('scanStatus');
  const tab = await getActiveTab();
  if (!tab || !tab.id || !/^https?:/.test(tab.url || '')) {
    setStatus(status, 'Otwórz najpierw stronę wydarzenia w tej karcie.', 'err');
    return;
  }
  if (!dictionaries) await loadDictionaries(false);
  if (!dictionaries) {
    setStatus(status, 'Nie udało się pobrać słowników z ridemore.bike (/api/dictionaries) — bez nich AI-Engine nie może działać. Kliknij 🔄 przy „Słowniki”.', 'err');
    return;
  }
  const engineUrl = await getEngineUrl();
  try {
    setStatus(status, '🧠 Zbieram model elementów strony…');
    const [{ result: pageContext }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      files: ['scripts/dom-features.js'],
    });

    setStatus(status, '🧠 Wysyłam do silnika AI (most PHP→Python, dostawca wg ai-engine/.env)…');
    const body = JSON.stringify({ ...pageContext, dictionaries: engineDictionariesPayload(dictionaries) });
    let res;
    try {
      res = await fetch(engineUrl, { method: 'POST', headers: { 'content-type': 'application/json' }, body });
    } catch (e) {
      throw new Error('Nie połączono z mostem pod ' + engineUrl + ' — sprawdź, czy lokalny ridemore.bike (XAMPP, APP_ENV=dev) działa (⚙️ AI-Engine, jeśli adres inny).');
    }
    let payload;
    try { payload = await res.json(); } catch (e) { throw new Error('Serwer zwrócił niepoprawną odpowiedź (HTTP ' + res.status + ').'); }
    if (!res.ok || payload.ok === false) {
      throw new Error(payload.error || ('HTTP ' + res.status));
    }

    const extracted = payload.data;
    const filled = applyEngineResult(extracted, pageContext);
    matchOrganizerDomain(pageContext.sourceUrl);
    const conf = extracted.confidence ? (' (pewność: ' + confidenceLabel(extracted.confidence) + ')') : '';
    const summary = filled.length
      ? ('🧠 AI-Engine dołączyło: ' + filled.join(', ') + conf + '.')
      : ('🧠 AI-Engine nie znalazło nic nowego do dołączenia' + conf + '.');
    setStatus(status, summary, 'ok');
  } catch (e) {
    setStatus(status, '🧠 Błąd AI-Engine: ' + e.message, 'err');
  }
}

// ---------- Tryb "Skanuj grupę FB" ----------
// Uzupełnienie AI-Engine o feed grupy na Facebooku. scripts/group-feed.js
// zbiera posty (autor/treść/link — WYŁĄCZNIE to, co użytkownik widzi na
// załadowanej stronie grupy), a silnik AI (ai-engine, mode='group') ocenia
// każdy post (czy ktoś SZUKA TOWARZYSTWA na przejazd), uzupełnia pola
// wydarzenia (skróty ludzi -> konkretne dane) i redaguje wiadomość do autora.
// Autor trafia do formularza jako ORGANIZATOR (wybór "Użyj w formularzu"),
// wiadomość wysyła użytkownik RĘCZNIE — przycisk "💬 Czat" otwiera czat FB
// z gotową treścią, rozszerzenie nigdy nie wysyła samo (patrz openAuthorChat).
let groupResults = null; // { feed: {posts, sourceUrl, groupName}, matched: [{postIndex, title, ...}] }

async function scanGroupFeed() {
  const status = $('scanStatus');
  const tab = await getActiveTab();
  if (!tab || !tab.id || !/^https?:/.test(tab.url || '')) {
    setStatus(status, 'Otwórz najpierw stronę grupy na Facebooku w tej karcie.', 'err');
    return;
  }
  let url;
  try { url = new URL(tab.url); } catch (e) { setStatus(status, 'Nie mogę odczytać adresu karty.', 'err'); return; }
  if (!/facebook\.com$/i.test(url.hostname.replace(/^www\./, '')) || !url.pathname.startsWith('/groups/')) {
    setStatus(status, 'To nie jest grupa na Facebooku — wejdź na facebook.com/groups/... i kliknij ponownie.', 'err');
    return;
  }
  if (!dictionaries) await loadDictionaries(false);
  if (!dictionaries) {
    setStatus(status, 'Nie udało się pobrać słowników z ridemore.bike — bez nich tryb grupowy nie może działać. Kliknij 🔄 przy „Słowniki”.', 'err');
    return;
  }
  const engineUrl = await getEngineUrl();
  try {
    setStatus(status, '👥 Zbieram posty widoczne na stronie grupy…');
    const [{ result: feed }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      files: ['scripts/group-feed.js'],
    });
    if (!feed || !Array.isArray(feed.posts) || !feed.posts.length) {
      // Diagnostyka: podajemy, CO strona realnie ma, żeby nie zgadywać —
      // FB zmienia DOM między kontami/regionami (sprawdzone 2026-08-20:
      // bywa ZERO role="article" przy normalnym feedzie).
      const dbg = (feed && feed.debugInfo)
        ? ' — strona ma ' + feed.debugInfo.articles + ' role="article", ' + feed.debugInfo.profileLinks + ' linków profili, ' + feed.debugInfo.textBlocks + ' bloków tekstu (strategia: ' + feed.debugInfo.strategy + ')'
        : '';
      setStatus(status, 'Nie znalazłem postów na tej stronie' + dbg + '. Przewiń feed tak, żeby posty były widoczne, i spróbuj ponownie — albo kliknij 🖱️ „Wskaż posty” i zaznacz je na stronie ręcznie.', 'err');
      return;
    }
    await sendGroupPostsToEngine(feed, '👥', status, engineUrl);
  } catch (e) {
    setStatus(status, '👥 Błąd skanowania grupy: ' + e.message, 'err');
  }
}

// Wspólna część 👥 (auto) i 🖱️ (ręcznie): wysłanie zebranych postów do
// silnika AI (mode='group'), pokazanie wyników i statusu.
async function sendGroupPostsToEngine(feed, icon, status, engineUrl) {
  setStatus(status, icon + ' Wysyłam ' + feed.posts.length + ' postów do silnika AI…');
  const body = JSON.stringify({
    mode: 'group',
    sourceUrl: feed.sourceUrl,
    pageTitle: feed.pageTitle,
    groupName: feed.groupName,
    posts: feed.posts,
    dictionaries: engineDictionariesPayload(dictionaries),
  });
  let res;
  try {
    res = await fetch(engineUrl, { method: 'POST', headers: { 'content-type': 'application/json' }, body });
  } catch (e) {
    throw new Error('Nie połączono z mostem pod ' + engineUrl + ' — sprawdź, czy lokalny ridemore.bike (XAMPP, APP_ENV=dev) działa (⚙️ AI-Engine).');
  }
  let payload;
  try { payload = await res.json(); } catch (e) { throw new Error('Serwer zwrócił niepoprawną odpowiedź (HTTP ' + res.status + ').'); }
  if (!res.ok || payload.ok === false) {
    throw new Error(payload.error || ('HTTP ' + res.status));
  }

  groupResults = { feed, matched: (payload.data && payload.data.posts) || [] };
  renderGroupResults();
  const n = groupResults.matched.length;
  setStatus(status, icon + ' Przeskanowano ' + feed.posts.length + ' postów, AI oznaczyło ' + n + ' jako szukanie towarzystwa. Zatwierdź je w sekcji „Posty z grupy FB”.', 'ok');
}

// Tryb „🔗 Zbierz linki" (Etap 3 importera). scripts/link-harvest.js skanuje
// AKTUALNIE WYRENDEROWANĄ stronę-listę (działa na SPA/JS, czego serwerowy
// import_events.php nie potrafi) i wyłuskuje linki do pojedynczych wydarzeń.
// Nie wysyła nic do silnika ani do bazy — to KROK ODKRYWANIA: użytkownik
// otwiera każdy link i na wyrenderowanej stronie wydarzenia odpala 🧠 AI-Engine
// (istniejący przepływ), który wypełnia formularz. Rozwiązuje źródła „trudne"
// (brevety.pl, kalendarze na JS), gdzie serwer widzi tylko pustą skorupę.
async function collectEventLinks() {
  const status = $('scanStatus');
  const tab = await getActiveTab();
  if (!tab || !tab.id || !/^https?:/.test(tab.url || '')) {
    setStatus(status, 'Otwórz najpierw stronę-listę (kalendarz wydarzeń) w tej karcie.', 'err');
    return;
  }
  try {
    setStatus(status, '🔗 Zbieram linki wydarzeń z wyrenderowanej strony…');
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      files: ['scripts/link-harvest.js'],
    });
    const links = (result && result.links) || [];
    renderCollectedLinks(result, links);
    if (!links.length) {
      setStatus(status, 'Nie znalazłem linków wydarzeń na tej stronie. Przewiń listę, aż wydarzenia będą widoczne, i kliknij ponownie.', 'err');
    } else {
      setStatus(status, '🔗 Znalazłem ' + links.length + ' linków wydarzeń. Otwórz każdy i użyj 🧠 AI-Engine, żeby wypełnić formularz.', 'ok');
    }
  } catch (e) {
    setStatus(status, '🔗 Błąd zbierania linków: ' + e.message, 'err');
  }
}

function renderCollectedLinks(ctx, links) {
  const sec = $('linksSection');
  const list = $('linksResultsList');
  const lbl = $('linksSourceLabel');
  if (!sec || !list) return;
  if (lbl) lbl.textContent = (ctx && ctx.pageTitle) ? ctx.pageTitle : '';
  list.textContent = '';
  if (!links.length) { sec.hidden = true; return; }
  for (const l of links) {
    const row = document.createElement('div');
    row.className = 'link-row';
    const a = document.createElement('a');
    a.href = l.url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.textContent = l.text || l.url;
    const open = document.createElement('button');
    open.type = 'button';
    open.className = 'btn btn-ghost btn-sm';
    open.textContent = 'Otwórz';
    open.addEventListener('click', () => chrome.tabs.create({ url: l.url }));
    row.appendChild(a);
    row.appendChild(open);
    list.appendChild(row);
  }
  sec.hidden = false;
}

// Ręczne wskazywanie postów (fallback, gdy auto-detekcja DOM nie trafi):
// użytkownik klika na stronie grupy posty, które mają trafić na
// ridemore.bike; ✓ Zakończ wysyła je do silnika AI. Esc = anuluj.
async function pickGroupPosts() {
  const status = $('scanStatus');
  const tab = await getActiveTab();
  if (!tab || !tab.id || !/^https?:/.test(tab.url || '')) {
    setStatus(status, 'Otwórz najpierw stronę grupy na Facebooku w tej karcie.', 'err');
    return;
  }
  if (!dictionaries) await loadDictionaries(false);
  if (!dictionaries) {
    setStatus(status, 'Nie udało się pobrać słowników z ridemore.bike — bez nich tryb grupowy nie może działać. Kliknij 🔄 przy „Słowniki”.', 'err');
    return;
  }
  const engineUrl = await getEngineUrl();
  const btn = $('btnPickPosts');
  btn.disabled = true;
  btn.classList.add('picking');
  try {
    setStatus(status, '🖱️ Klikaj na stronie grupy posty, które mają trafić na ridemore.bike (Esc = anuluj, na stronie u góry ✓ Zakończ)…');
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: rmiPickPostsOnPage,
    });
    if (!result || !Array.isArray(result.posts) || !result.posts.length) {
      setStatus(status, result && result.cancelled ? 'Anulowano wskazywanie postów.' : 'Nie zebrano żadnych postów — klikaj w treść posta (albo w autora) i zakończ ✓ na stronie.', 'err');
      return;
    }
    const feed = {
      sourceUrl: tab.url,
      pageTitle: tab.title || '',
      groupName: '',
      posts: result.posts.map((p, i) => ({ index: i, ...p })),
    };
    await sendGroupPostsToEngine(feed, '🖱️', status, engineUrl);
  } catch (e) {
    setStatus(status, '🖱️ Nie udało się uruchomić wskazywania (' + e.message + ').', 'err');
  } finally {
    btn.disabled = false;
    btn.classList.remove('picking');
  }
}

// „📄 Post → wydarzenie": wskaż POST będący ogłoszeniem zorganizowanego
// wydarzenia (rajd/wyścig/wycieczka z datą/ceną/trasą) i wypełnij nim formularz.
// Inaczej niż tryb grupowy (mode='group', filtr „szukam towarzystwa", który
// CELOWO odrzuca ogłoszenia wydarzeń — patrz build_group_system_prompt), tu
// treść posta idzie przez ZWYKŁY ekstraktor (mode domyślny), tak jak 🧠 AI-Engine
// dla całej strony — tyle że na tekście JEDNEGO wskazanego posta, a nie całego
// feedu. Reużywa rmiPickPostsOnPage (bierze pierwszy wskazany) + applyEngineResult.
async function pickPostAsEvent() {
  const status = $('scanStatus');
  const tab = await getActiveTab();
  if (!tab || !tab.id || !/^https?:/.test(tab.url || '')) {
    setStatus(status, 'Otwórz najpierw stronę z postem (grupa/profil FB) w tej karcie.', 'err');
    return;
  }
  if (!dictionaries) await loadDictionaries(false);
  if (!dictionaries) {
    setStatus(status, 'Nie udało się pobrać słowników z ridemore.bike — kliknij 🔄 przy „Słowniki”.', 'err');
    return;
  }
  const engineUrl = await getEngineUrl();
  const btn = $('btnPostToEvent');
  btn.disabled = true;
  btn.classList.add('picking');
  try {
    setStatus(status, '📄 Kliknij POST z ogłoszeniem wydarzenia, potem ✓ Zakończ na stronie (Esc = anuluj)…');
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: rmiPickPostsOnPage,
    });
    if (!result || !Array.isArray(result.posts) || !result.posts.length) {
      setStatus(status, result && result.cancelled ? 'Anulowano.' : 'Nie wskazano posta — kliknij w treść posta i zakończ ✓ na stronie.', 'err');
      return;
    }
    const post = result.posts[0];
    const sourceUrl = post.postHref || tab.url;
    // Payload jak dla 🧠 AI-Engine, ale zbudowany z tekstu jednego posta.
    // Autora dokładamy jako element „Organizator: …", żeby model miał go skąd wziąć.
    const pageContext = {
      sourceUrl,
      pageTitle: post.authorName || tab.title || '',
      meta: {},
      jsonLd: [],
      elements: [
        ...(post.authorName ? [{ id: 1, tag: 'h2', text: 'Organizator: ' + post.authorName }] : []),
        { id: 2, tag: 'div', text: (post.text || '').slice(0, 4000) },
      ],
      links: post.postHref ? [{ index: 0, text: 'post', href: post.postHref }] : [],
      images: [],
    };
    setStatus(status, '📄 Wysyłam treść posta do silnika AI…');
    const body = JSON.stringify({ ...pageContext, dictionaries: engineDictionariesPayload(dictionaries) });
    let res;
    try {
      res = await fetch(engineUrl, { method: 'POST', headers: { 'content-type': 'application/json' }, body });
    } catch (e) {
      throw new Error('Nie połączono z mostem pod ' + engineUrl + ' — sprawdź lokalny ridemore.bike (⚙️ AI-Engine).');
    }
    let payload;
    try { payload = await res.json(); } catch (e) { throw new Error('Serwer zwrócił niepoprawną odpowiedź (HTTP ' + res.status + ').'); }
    if (!res.ok || payload.ok === false) throw new Error(payload.error || ('HTTP ' + res.status));

    const extracted = payload.data;
    const filled = applyEngineResult(extracted, pageContext);
    // Organizator: jeśli model nie wyczytał go z tekstu, użyj autora posta
    // (ta sama zasada co w trybie grupowym — autor jest organizatorem).
    if (post.authorName && !$('f_organizer').value) {
      $('f_organizer').value = post.authorName;
      scheduleSave();
    }
    const conf = extracted.confidence ? (' (pewność: ' + confidenceLabel(extracted.confidence) + ')') : '';
    setStatus(
      status,
      filled.length
        ? ('📄 Wypełniono z posta: ' + filled.join(', ') + conf + '. Sprawdź pola i „🚴 Wypełnij formularz”.')
        : ('📄 Nie udało się wyciągnąć pól z tego posta' + conf + ' — może to nie ogłoszenie wydarzenia.'),
      filled.length ? 'ok' : 'err'
    );
  } catch (e) {
    setStatus(status, '📄 Błąd: ' + e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.classList.remove('picking');
  }
}

// Wstrzykiwana do karty z grupy FB (world: ISOLATED). Multi-wskazywanie:
// pojedyncze kliknięcie = jeden post, zbierane do listy; badge z ✓ Zakończ
// kończy zbieranie i wraca listę do panelu (panel NIGDY niczego nie wysyła
// bez tego kroku). Ta sama idea co rmiPickOnPage, tyle że kilka kliknięć
// i ekstrakcja posta zamiast surowego elementu. Kod ekstrakcji jest celowo
// zduplikowany z scripts/group-feed.js (executeScript wymaga samowystarczalnej
// funkcji — nie ma dostępu do plików skryptów strony).
function rmiPickPostsOnPage() {
  return new Promise((resolve) => {
    const MAX = 25;
    const MAX_TEXT = 1500;
    const MIN_TEXT = 15;
    const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();
    const abs = (url) => { try { return new URL(url, document.baseURI).href; } catch (e) { return null; } };
    const looksProfile = (href) => /(?:^|\/)(?:user|profile)\/(\d+)(?:\/|$)/i.test(href) || /[?&]id=\d+/.test(href);
    // Link do KONKRETNEGO posta — drugi, niezależny sygnał „to jest post".
    // Dzisiejszy FB często ma autora jako link „vanity" (/nazwa), którego
    // looksProfile nie rozpozna; permalink/story_fbid jest pewniejszy.
    const looksPost = (href) => /(?:\/posts\/|\/permalink\/|[?&]story_fbid=|[?&]multi_permalinks=)/i.test(href);
    function extractPost(container) {
      let authorHref = null;
      let authorName = '';
      let postHref = null;
      for (const a of container.querySelectorAll('a[href]')) {
        const href = abs(a.getAttribute('href'));
        if (!href) continue;
        const name = clean(a.getAttribute('aria-label') || a.textContent).slice(0, 80);
        if (looksProfile(href) && !authorHref) {
          authorHref = href;
          if (name && !authorName) authorName = name;
        }
        if (!postHref && looksPost(href)) postHref = href;
        if (authorHref && authorName && postHref) break;
      }
      let text = '';
      for (const b of container.querySelectorAll('div[dir="auto"]')) {
        if (b.closest('a') || b.closest('[role="button"]')) continue;
        const t = clean(b.textContent);
        if (t.length > text.length) text = t;
      }
      return { authorHref, authorName, postHref, text: text.slice(0, MAX_TEXT) };
    }
    // Najbliższy przodek (albo sam element) mający i link autora, i blok
    // tekstu — kliknięcie w treść posta i w autora dają ten sam post.
    // Guard rozmiaru (<= 90% ekranu) + limit głębokości: wspinaczka nie może
    // wyjść na całą stronę, bo losowy klik w tło zebrałby ją jako "post".
    function nearestPostContainer(el) {
      let p = el;
      let depth = 0;
      while (p && p !== document.body && p !== document.documentElement && depth < 7) {
        let hasAuthor = false;
        let hasPost = false;
        for (const a of p.querySelectorAll('a[href]')) {
          const h = abs(a.getAttribute('href'));
          if (!h) continue;
          if (looksProfile(h)) hasAuthor = true;
          if (looksPost(h)) hasPost = true;
          if (hasAuthor && hasPost) break;
        }
        let text = '';
        for (const b of p.querySelectorAll('div[dir="auto"]')) {
          if (b.closest('a') || b.closest('[role="button"]')) continue;
          const t = clean(b.textContent);
          if (t.length > text.length) text = t;
        }
        // Post rozpoznany, gdy jest link autora ALBO link do posta + dość tekstu.
        // Dawniej wymagany był WYŁĄCZNIE autor-profil, co na „vanity" linkach FB
        // nie trafiało i klik nie zbierał nic (zgłoszenie usera 2026-09-17).
        if ((hasAuthor || hasPost) && text.length >= MIN_TEXT) {
          const r = p.getBoundingClientRect();
          if (r.height <= window.innerHeight * 0.9) return p;
        }
        p = p.parentElement;
        depth++;
      }
      return null;
    }

    const posts = [];
    const seenKeys = new Set();
    const badge = document.createElement('div');
    badge.setAttribute('style', 'position:fixed;top:10px;right:10px;z-index:2147483647;background:#2E7D4F;color:#fff;padding:8px 14px;border-radius:8px;font:13px/1.3 sans-serif;box-shadow:0 4px 18px rgba(0,0,0,.35);pointer-events:none;display:flex;align-items:center;gap:8px;');
    const countEl = document.createElement('b');
    countEl.textContent = '0';
    const doneBtn = document.createElement('button');
    doneBtn.type = 'button';
    doneBtn.textContent = '✓ Zakończ';
    doneBtn.setAttribute('style', 'pointer-events:auto;background:#fff;color:#2E7D4F;border:0;border-radius:6px;padding:3px 10px;font:700 12px/1.4 sans-serif;cursor:pointer;');
    badge.append('Ridemore: klikaj posty (', countEl, ')', doneBtn);
    document.documentElement.appendChild(badge);

    let hovered = null;
    const prevOutline = new WeakMap();
    function onMove(e) {
      const el = document.elementFromPoint(e.clientX, e.clientY);
      if (!el || el === hovered) return;
      if (hovered) hovered.style.outline = prevOutline.get(hovered) || '';
      hovered = el;
      prevOutline.set(el, el.style.outline);
      el.style.outline = '2px solid #2E7D4F';
    }
    function cleanup() {
      document.removeEventListener('mousemove', onMove, true);
      document.removeEventListener('click', onClick, true);
      document.removeEventListener('keydown', onKey, true);
      if (hovered) hovered.style.outline = prevOutline.get(hovered) || '';
      badge.remove();
    }
    function finish(v) { cleanup(); resolve(v); }
    function addPost(container) {
      if (posts.length >= MAX) return;
      const p = extractPost(container);
      if (!p.text) return;
      const key = (p.authorHref || '') + '|' + p.text.slice(0, 200);
      if (seenKeys.has(key)) return;
      seenKeys.add(key);
      posts.push(p);
      countEl.textContent = String(posts.length);
      const prev = container.style.outline;
      container.style.outline = '2px solid #FFC107';
      setTimeout(() => { if (container.isConnected) container.style.outline = prev; }, 350);
    }
    function onClick(e) {
      if (doneBtn.contains(e.target)) return; // to obsłuży listener ✓ Zakończ
      e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
      const el = e.target;
      const container = el.closest('[role="article"]') || nearestPostContainer(el);
      if (!container) {
        // Klik zarejestrowany, ale nie rozpoznano posta — mrugnij na czerwono,
        // żeby user wiedział, że ma kliknąć w treść posta (a nie w tło/pusty margines).
        const bg = badge.style.background;
        badge.style.background = '#c0392b';
        setTimeout(() => { badge.style.background = bg; }, 400);
        return;
      }
      addPost(container);
    }
    function onKey(e) { if (e.key === 'Escape') finish({ cancelled: true, posts: [] }); }
    doneBtn.addEventListener('click', () => finish({ cancelled: false, posts }));
    document.addEventListener('mousemove', onMove, true);
    document.addEventListener('click', onClick, true);
    document.addEventListener('keydown', onKey, true);
  });
}

// ---------- Tryb "🗺️ Dodaj skarb" ----------
// Skanuje stronę Wikipedii (lub inną) i proponuje skarb na ridemore.bike.
// dom-features.js zbiera kontekst strony, silnik AI (mode='treasure')
// ekstraktuje współrzędne/nazwę/opis/kategorię, panel renderuje kartę.
let treasureResult = null;

async function scanTreasurePage() {
  const status = $('scanStatus');
  const tab = await getActiveTab();
  if (!tab || !tab.id || !/^https?:/.test(tab.url || '')) {
    setStatus(status, 'Otwórz najpierw stronę Wikipedii (lub inną o miejscu) w tej karcie.', 'err');
    return;
  }
  if (!dictionaries) await loadDictionaries(false);
  if (!dictionaries) {
    setStatus(status, 'Nie udało się pobrać słowników z ridemore.bike — bez nich tryb skarbów nie może działać. Kliknij 🔄 przy „Słowniki”.', 'err');
    return;
  }
  const engineUrl = await getEngineUrl();
  try {
    setStatus(status, '🗺️ Zbieram kontekst strony…');
    const [{ result: pageContext }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      files: ['scripts/dom-features.js'],
    });

    setStatus(status, '🗺️ Wysyłam do silnika AI (mode=treasure)…');
    const body = JSON.stringify({
      mode: 'treasure',
      ...pageContext,
      dictionaries: engineDictionariesPayload(dictionaries),
    });
    let res;
    try {
      res = await fetch(engineUrl, { method: 'POST', headers: { 'content-type': 'application/json' }, body });
    } catch (e) {
      throw new Error('Nie połączono z mostem pod ' + engineUrl + ' — sprawdź, czy lokalny ridemore.bike (XAMPP, APP_ENV=dev) działa (⚙️ AI-Engine).');
    }
    let payload;
    try { payload = await res.json(); } catch (e) { throw new Error('Serwer zwrócił niepoprawną odpowiedź (HTTP ' + res.status + ').'); }
    if (!res.ok || payload.ok === false) {
      throw new Error(payload.error || ('HTTP ' + res.status));
    }

    const extracted = payload.data;
    if (!extracted || !extracted.name || extracted.lat == null || extracted.lon == null) {
      setStatus(status, '🗺️ AI nie znalazło wystarczających danych (współrzędne + nazwa) na tej stronie — spróbuj inną stronę o konkretnym miejscu.', 'err');
      return;
    }

    treasureResult = extracted;
    renderTreasureResult(extracted);
    const conf = extracted.confidence ? (' (pewność: ' + confidenceLabel(extracted.confidence) + ')') : '';
    setStatus(status, '🗺️ Skarb znaleziony: ' + extracted.name + conf + '. Sprawdź dane w sekcji „Propozycja skarbu" i kliknij 📌 Zapisz.', 'ok');
  } catch (e) {
    setStatus(status, '🗺️ Błąd AI-Engine: ' + e.message, 'err');
  }
}

function renderTreasureResult(m) {
  const section = $('treasureSection');
  const container = $('treasureResult');
  container.innerHTML = '';
  if (!m) { section.hidden = true; return; }
  section.hidden = false;
  $('treasureSourceLabel').textContent = m.name || '';

  const card = document.createElement('div');
  card.className = 'tcard';

  // Nagłówek: nazwa + współrzędne
  const head = document.createElement('div');
  head.className = 'tcard__head';
  const name = document.createElement('span');
  name.className = 'tcard__name';
  name.textContent = m.name || 'Bez nazwy';
  head.appendChild(name);
  if (m.lat != null && m.lon != null) {
    const coords = document.createElement('span');
    coords.className = 'tcard__coords';
    coords.textContent = m.lat.toFixed(5) + ', ' + m.lon.toFixed(5);
    head.appendChild(coords);
  }
  card.appendChild(head);

  // Kategoria
  if (m.category && dictionaries) {
    const cat = dictionaries.treasureCategories && dictionaries.treasureCategories.find((c) => c.code === m.category);
    const catEl = document.createElement('div');
    catEl.className = 'tcard__cat';
    catEl.textContent = cat ? ('Kategoria: ' + cat.name) : ('Kategoria: ' + m.category);
    card.appendChild(catEl);
  }

  // Opis
  if (m.description) {
    const desc = document.createElement('p');
    desc.className = 'tcard__desc';
    desc.textContent = m.description;
    card.appendChild(desc);
  }

  // Wskazówka
  if (m.hint) {
    const hint = document.createElement('p');
    hint.className = 'tcard__hint';
    hint.textContent = 'Wskazówka: ' + m.hint;
    card.appendChild(hint);
  }

  // Pewność
  if (m.confidence) {
    const conf = document.createElement('div');
    conf.className = 'tcard__conf';
    conf.textContent = 'Pewność: ' + confidenceLabel(m.confidence);
    card.appendChild(conf);
  }

  // Akcje
  const actions = document.createElement('div');
  actions.className = 'tcard__actions';
  const saveBtn = document.createElement('button');
  saveBtn.type = 'button';
  saveBtn.className = 'btn btn-primary btn-sm';
  saveBtn.textContent = '📌 Zapisz';
  saveBtn.title = 'Otwiera formularz zgłoszenia skarbu na ridemore.bike i wypełnia go danymi z tego skarbu';
  saveBtn.addEventListener('click', () => openTreasureProposal(m));
  actions.appendChild(saveBtn);
  const retryBtn = document.createElement('button');
  retryBtn.type = 'button';
  retryBtn.className = 'btn btn-ghost btn-sm';
  retryBtn.textContent = '🔄 Skanuj ponownie';
  retryBtn.title = 'Ponownie skanuje aktualną stronę w poszukiwaniu danych skarbu';
  retryBtn.addEventListener('click', () => scanTreasurePage());
  actions.appendChild(retryBtn);
  card.appendChild(actions);

  container.appendChild(card);
}

// Otwiera formularz /skarby/zglos na ridemore.bike i wypełnia go danymi
// skarbu z silnika AI. Mechanika analogiczna do fillRidemore(): otwarcie
// karty →注入 treasure-fill.js → postMessage z danymi.
async function openTreasureProposal(m) {
  const status = $('scanStatus');
  try {
    setStatus(status, '📌 Otwieram formularz zgłoszenia skarbu…');
    const [existing] = await chrome.tabs.query({ url: 'https://ridemore.bike/skarby/zglos' });
    let tab;
    if (existing) {
      tab = await chrome.tabs.update(existing.id, { url: RIDEMORE_ORIGIN + '/skarby/zglos', active: true });
    } else {
      tab = await chrome.tabs.create({ url: RIDEMORE_ORIGIN + '/skarby/zglos' });
    }
    await chrome.windows.update(tab.windowId, { focused: true }).catch(() => {});
    await waitTabComplete(tab.id, 30000);
    await new Promise((r) => setTimeout(r, 500));

    // Inject treasure-fill.js (ISOLATED → MAIN przez world: 'MAIN')
    await chrome.scripting.executeScript({ target: { tabId: tab.id }, world: 'MAIN', files: ['scripts/treasure-fill.js'] });
    // Wyślij dane do wypełnienia
    await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      world: 'MAIN',
      func: (data) => window.postMessage({ type: 'RMI_FILL_TREASURE', payload: data }, '*'),
      args: [{ name: m.name, description: m.description, lat: m.lat, lon: m.lon, category: m.category }],
    });

    setStatus(status, '📌 Formularz otwarty i wypełniony — sprawdź kartę ridemore.bike i kliknij „Zgłoś to miejsce".', 'ok');
  } catch (e) {
    setStatus(status, '📌 Nie udało się otworzyć formularza (' + e.message + ') — otwórz ręcznie ridemore.bike/skarby/zglos i wypełnij dane z karty obok.', 'err');
  }
}

function renderGroupResults() {
  const section = $('groupSection');
  const list = $('groupResultsList');
  list.innerHTML = '';
  if (!groupResults) { section.hidden = true; return; }
  const { feed, matched } = groupResults;
  section.hidden = false;
  $('groupSourceLabel').textContent = feed.groupName || feed.sourceUrl;

  const matchedByIndex = new Map(matched.map((m) => [m.postIndex, m]));
  let withData = 0;
  feed.posts.forEach((post) => {
    const m = matchedByIndex.get(post.index);
    const card = document.createElement('div');
    card.className = 'gpost' + (m ? ' gpost--hit' : '');
    if (m) withData++;

    const head = document.createElement('div');
    head.className = 'gpost__head';
    const author = document.createElement('span');
    author.className = 'gpost__author';
    author.textContent = post.authorName || 'nieznany autor';
    head.appendChild(author);
    if (post.postHref) {
      const link = document.createElement('a');
      link.className = 'gpost__link';
      link.href = post.postHref;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'otwórz post ↗';
      head.appendChild(link);
    }
    card.appendChild(head);

    const excerpt = document.createElement('p');
    excerpt.className = 'gpost__text';
    excerpt.textContent = post.text.length > 280 ? post.text.slice(0, 280) + '…' : post.text;
    card.appendChild(excerpt);

    const meta = document.createElement('div');
    meta.className = 'gpost__meta' + (m ? '' : ' gpost__meta--none');
    if (m) {
      const bits = [];
      if (m.title) bits.push('tytuł: ' + m.title);
      if (m.dateIso) bits.push('data: ' + m.dateIso);
      if (m.time) bits.push('godz.: ' + m.time);
      if (m.meetingPointLabel) bits.push('zbiórka: ' + m.meetingPointLabel);
      if (m.distanceKm != null) bits.push(m.distanceKm + ' km');
      if (m.region) { const r = dictionaries && dictionaries.regionsFlat.find((x) => x.code === m.region); if (r) bits.push('region: ' + r.name); }
      if (m.pace) { const p = dictionaries && dictionaries.paces.find((x) => x.code === m.pace); if (p) bits.push('tempo: ' + p.name); }
      if (m.difficulty) { const df = dictionaries && dictionaries.difficulties.find((x) => x.code === m.difficulty); if (df) bits.push('trudność: ' + df.name); }
      meta.textContent = '✓ szuka towarzystwa (pewność: ' + confidenceLabel(m.confidence) + ')' + (bits.length ? ' — ' + bits.join(', ') : '');
    } else {
      meta.textContent = 'AI nie uznało tego posta za szukanie towarzystwa — pominąć albo napisać do autora.';
    }
    card.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'gpost__actions';
    const chatBtn = document.createElement('button');
    chatBtn.type = 'button';
    chatBtn.className = 'btn btn-ghost btn-sm';
    chatBtn.textContent = '💬 Czat';
    chatBtn.title = 'Otwiera czat FB z autorem i wkleja gotową wiadomość — wysyłasz sam(a), rozszerzenie nigdy nie wysyła';
    chatBtn.addEventListener('click', () => openAuthorChat(post, m ? buildAuthorMessage(post, m) : null));
    actions.appendChild(chatBtn);
    const copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.className = 'btn btn-ghost btn-sm';
    copyBtn.textContent = '📋 Kopiuj';
    copyBtn.title = 'Kopiuje gotową wiadomość do autora do schowka — na wypadek, gdy FB nie pozwala na automatyczne wklejenie w czacie';
    copyBtn.addEventListener('click', () => copyAuthorMessage(post, m));
    actions.appendChild(copyBtn);
    if (m) {
      const useBtn = document.createElement('button');
      useBtn.type = 'button';
      useBtn.className = 'btn btn-primary btn-sm';
      useBtn.textContent = '📥 Użyj w formularzu';
      useBtn.title = 'Wypełnia formularz poniżej danymi z tego posta (autor = organizator) — potem przejrzyj i kliknij „Wypełnij formularz na ridemore.bike”';
      useBtn.addEventListener('click', () => applyGroupPostToForm(m, post));
      actions.appendChild(useBtn);
    }
    card.appendChild(actions);
    list.appendChild(card);
  });

  $('groupHint').textContent = withData
    ? ('AI oznaczyło ' + withData + ' z ' + feed.posts.length + ' postów jako szukanie towarzystwa. Kliknij „Użyj w formularzu”, żeby wypełnić formularz poniżej danymi z posta, potem przejrzyj i kliknij „Wypełnij formularz na ridemore.bike”. Wiadomość do autora: „💬 Czat” albo „📋 Kopiuj”.')
    : 'AI nie znalazło postów o szukaniu towarzystwa — żaden post nie trafił do formularza automatycznie.';
}

// Przenosi dane z wybranego posta do formularza panelu — ta sama zasada
// "dokładamy, nie nadpisujemy" co applyEngineResult(): nic nie psujemy z tego,
// co użytkownik już zebrał z innych źródeł. Autor posta -> pole organizatora
// (nowy organizator w kreatorze wymaga też e-maila — dopisuje go użytkownik).
function applyGroupPostToForm(m, post) {
  const filled = [];
  const mark = (label) => filled.push(label);
  if (setIfEmpty('f_title', m.title)) mark('tytuł');
  if (setIfEmpty('f_description', m.description)) mark('opis');
  if (post.authorName && setIfEmpty('f_organizer', post.authorName)) mark('organizator (autor posta)');
  // Autor posta ma być ORGANIZATOREM tego wydarzenia — wybrany wcześniej
  // istniejący organizator (dopasowanie po domenie) wygrywałby w
  // gatherPayload() (selectedOrganizer ma pierwszeństwo nad polem tekstowym),
  // więc go odpinamy, żeby faktycznie wygrał autor posta.
  if (post.authorName && selectedOrganizer) {
    selectedOrganizer = null;
    renderSelectedOrganizer();
  }
  if (setIfEmpty('f_date', m.dateIso)) mark('data');
  if (setIfEmpty('f_time', m.time)) mark('godzina');
  if (setIfEmpty('f_meetingLabel', m.meetingPointLabel)) mark('miejsce zbiórki');
  if (m.distanceKm != null && setIfEmpty('f_distance', m.distanceKm)) mark('dystans');
  if (m.region && !$('f_region').value) { $('f_region').value = m.region; mark('region'); }
  if (m.pace && !$('f_pace').value) { $('f_pace').value = m.pace; mark('tempo'); }
  if (m.difficulty && !$('f_difficulty').value) { $('f_difficulty').value = m.difficulty; mark('trudność'); }
  if (Array.isArray(m.bikeTypes) && m.bikeTypes.length) {
    let any = false;
    m.bikeTypes.forEach((code) => {
      const input = $('f_bikeTypes').querySelector(`input[value="${CSS.escape(code)}"]`);
      if (input && !input.checked) { input.checked = true; any = true; }
    });
    if (any) mark('rowery');
  }
  scheduleSave();
  setStatus($('scanStatus'), '📥 Wypełniono z posta: ' + (filled.length ? filled.join(', ') : 'nic nowego do dołożenia') + '. Przejrzyj i kliknij „Wypełnij formularz na ridemore.bike”.', 'ok');
}

// Gotowa wiadomość do autora posta — STAŁY szablon (struktura uzgodniona
// z psychologiem, 2026-08-20) sklejany TU, nie przez AI: model pisze tylko
// krótki opis jazdy (messageRide z silnika), reszta listu jest niezmienna.
// Z tego samego tekstu korzysta i 💬 Czat (autowklejenie w oknie FB), i
// 📋 Kopiuj (schowek) — żeby obie ścieżki dały IDENTYCZNĄ wiadomość.
function buildAuthorMessage(post, m) {
  const groupName = (groupResults && groupResults.feed && groupResults.feed.groupName) || 'Facebooka';
  const ride = rideSummary(m);
  return [
    'Cześć! Trafiłem na Twój post, w którym szukasz osób do wspólnej jazdy na grupie ' + groupName + '.',
    '',
    'Właśnie pracujemy nad ridemore.bike – projektem, którego głównym celem jest pomaganie rowerzystom znaleźć osoby, z którymi naprawdę można pojechać, a nie tylko wymienić kilka komentarzy pod postem. Budujemy teraz pierwszą lokalną społeczność i chcemy zrobić to trochę inaczej – zamiast wymyślać sztuczne przykłady, szukamy prawdziwych osób, które właśnie teraz chcą z kimś pojeździć.',
    '',
    '',
    'Dlatego mam do Ciebie nietypową prośbę.',
    '',
    '',
    'Czy zgodziłbyś/zgodziłabyś się, żebyśmy za Twoją zgodą pokazali na Ridemore Twoją potrzebę w uproszczonej formie, np.:',
    '',
    '',
    ride,
    '',
    '',
    'Chcielibyśmy spróbować znaleźć Ci osoby, którym pasuje taki sposób jazdy.',
    '',
    '',
    'Nie musisz zakładać konta ani niczego kupować. Nie chcemy też kopiować Twojego posta ani publikować Twoich danych – chodzi tylko o samą potrzebę. Oczywiście niczego nie opublikujemy bez Twojej zgody. Jeśli chcesz możesz to samemu zrobić i wtedy dostaniesz powiadomienie z dopasowaniem.',
  ].join('\n');
}

// "{TREŚĆ_WSPOLNEJ_JAZDY_PRZEROBIONE_PRZEZ_AI}" z szablonu: opis jazdy
// przepisany przez AI (messageRide z silnika); gdy model go nie dał —
// składamy z pól, które wyciągnął (taki sam tekst, mniej ładny).
function rideSummary(m) {
  if (!m) return 'Przejażdżka rowerowa, na którą szukasz towarzystwa.';
  if (m.messageRide) return m.messageRide;
  const bits = [];
  if (m.title) bits.push(m.title);
  if (m.dateIso) bits.push('termin: ' + m.dateIso);
  if (m.time) bits.push('godzina: ' + m.time);
  if (m.meetingPointLabel) bits.push('zbiórka: ' + m.meetingPointLabel);
  if (m.distanceKm != null) bits.push(m.distanceKm + ' km');
  return bits.length ? bits.join(', ') : 'Przejażdżka rowerowa, na którą szukasz towarzystwa.';
}

// 📋 Kopiuj — FB bywa niewklejalny (contenteditable Reacta z własną obsługą
// wklejania; sprawdzone na żywo: autowklejenie nie zawsze działa), więc
// wiadomość można po prostu skopiować do schowka. Najpierw nowoczesny
// navigator.clipboard, potem awaryjny textarea+execCommand('copy').
async function copyAuthorMessage(post, m) {
  const status = $('scanStatus');
  const text = buildAuthorMessage(post, m);
  try {
    await navigator.clipboard.writeText(text);
    setStatus(status, '📋 Wiadomość skopiowana do schowka — wklej ją w czacie FB (Ctrl+V) i wyślij Enterem.', 'ok');
    return;
  } catch (e) {
    // clipboard API bywa zablokowane poza kontekstem fokusu/aktywnej karty
  }
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  let ok = false;
  try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
  if (ok) {
    ta.remove();
    setStatus(status, '📋 Wiadomość skopiowana do schowka — wklej ją w czacie FB (Ctrl+V) i wyślij Enterem.', 'ok');
  } else {
    // Zostawiamy zaznaczone (niewidoczne) pole — wystarczy Ctrl+C.
    setStatus(status, '📋 Wciśnij Ctrl+C (gotowa wiadomość jest już zaznaczona), potem wklej w czacie FB i wyślij Enterem.', 'ok');
  }
}

// Otwiera czat FB z autorem posta i wkleja gotową wiadomość (dla posta bez
// dopasowania AI — samo otwarcie czatu, bez treści). WIADOMOŚCI NIGDY nie
// wysyłamy za użytkownika — wysyła ją sam(a), Enterem, z własnego konta FB.
async function openAuthorChat(post, message) {
  const status = $('scanStatus');
  const profileId = fbProfileIdFromHref(post.authorHref);
  if (!profileId) {
    setStatus(status, 'Nie udało się ustalić profilu autora posta (' + (post.authorHref || 'brak linku') + ') — otwórz czat FB ręcznie.', 'err');
    return;
  }
  try {
    const tab = await chrome.tabs.create({ url: 'https://www.facebook.com/messages/t/' + profileId });
    await waitTabComplete(tab.id, 30000);
    await new Promise((r) => setTimeout(r, 900)); // React dobiega tuż po 'complete', jak przy wypełnianiu kreatora
    if (!message) {
      setStatus(status, '💬 Otworzyłem czat z autorem — wiadomość wklej i wyślij sam(a).', 'ok');
      return;
    }
    await chrome.scripting.executeScript({ target: { tabId: tab.id }, world: 'MAIN', files: ['scripts/chat-paste.js'] });
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      world: 'MAIN',
      func: (text) => window.__rmiChatPaste(text),
      args: [message],
    });
    if (result && result.ok) {
      setStatus(status, '💬 Czat otwarty, wiadomość wklejona — sprawdź i wyślij Enterem.', 'ok');
    } else {
      setStatus(status, '💬 Czat otwarty, ale ' + ((result && result.error) || 'nie udało się wkleić') + ' — wklej ręcznie (Ctrl+V).', 'err');
    }
  } catch (e) {
    setStatus(status, '💬 Nie udało się otworzyć czatu (' + e.message + ') — otwórz facebook.com/messages/t/' + profileId + ' ręcznie.', 'err');
  }
}

// FB nie daje jednego, stabilnego formatu linku do profilu — obsługujemy
// formaty spotykane na żywo w grupach: /groups/<gid>/user/<uid>/,
// /user/<uid>/, profile.php?id=<uid>; spadek: vanity name — Messenger web
// (messages/t/) przyjmuje też nazwy profili, nie tylko numeryczne ID.
function fbProfileIdFromHref(href) {
  if (!href) return null;
  let resolved;
  try { resolved = new URL(href, 'https://www.facebook.com').href; } catch (e) { resolved = href; }
  const mUser = resolved.match(/\/(?:user|profile)\/(\d+)(?:\/|$)/i);
  if (mUser) return mUser[1];
  const mId = resolved.match(/[?&]id=(\d+)/);
  if (mId) return mId[1];
  try {
    const u = new URL(resolved);
    if (/facebook\.com$/i.test(u.hostname.replace(/^www\./, ''))) {
      const path = u.pathname.replace(/^\/+|\/+$/g, '');
      return path || null;
    }
  } catch (e) { /* zostaje null */ }
  return null;
}

// ---------- Tryb "wskaż na stronie" ----------
// Samodzielna funkcja wstrzykiwana przez executeScript — MUSI nie odwoływać
// się do żadnych zmiennych z tego pliku (serializowana jako tekst funkcji).
function rmiPickOnPage() {
  return new Promise((resolve) => {
    const badge = document.createElement('div');
    badge.textContent = 'Ridemore: kliknij element do przeniesienia (Esc = anuluj)';
    badge.setAttribute('style', 'position:fixed;top:10px;right:10px;z-index:2147483647;background:#2E7D4F;color:#fff;padding:8px 14px;border-radius:8px;font:13px/1.3 sans-serif;box-shadow:0 4px 18px rgba(0,0,0,.35);pointer-events:none;');
    document.documentElement.appendChild(badge);
    let hovered = null;
    const prevOutline = new WeakMap();
    function onMove(e) {
      const el = document.elementFromPoint(e.clientX, e.clientY);
      if (!el || el === hovered) return;
      if (hovered) hovered.style.outline = prevOutline.get(hovered) || '';
      hovered = el;
      prevOutline.set(el, el.style.outline);
      el.style.outline = '2px solid #2E7D4F';
    }
    function cleanup() {
      document.removeEventListener('mousemove', onMove, true);
      document.removeEventListener('click', onClick, true);
      document.removeEventListener('keydown', onKey, true);
      if (hovered) hovered.style.outline = prevOutline.get(hovered) || '';
      badge.remove();
    }
    function onClick(e) {
      e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
      const el = e.target;
      const linkEl = el.closest('a[href]');
      const imgEl = el.tagName === 'IMG' ? el : el.closest('img');
      const result = {
        text: (el.innerText || el.textContent || '').trim().slice(0, 4000),
        href: linkEl ? linkEl.href : '',
        imgSrc: imgEl ? imgEl.src : '',
      };
      cleanup();
      resolve(result);
    }
    function onKey(e) { if (e.key === 'Escape') { cleanup(); resolve(null); } }
    document.addEventListener('mousemove', onMove, true);
    document.addEventListener('click', onClick, true);
    document.addEventListener('keydown', onKey, true);
  });
}

async function startPick(button) {
  const targetId = button.dataset.pickTarget;
  const mode = button.dataset.pickMode || 'text';
  const targetEl = $(targetId);
  if (!targetEl) return;
  const tab = await getActiveTab();
  if (!tab || !tab.id) return;
  document.querySelectorAll('.pickbtn').forEach((b) => (b.disabled = true));
  button.classList.add('picking');
  const status = $('scanStatus');
  setStatus(status, 'Kliknij element na stronie (Esc = anuluj)…');
  try {
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: rmiPickOnPage,
    });
    if (result) {
      applyPickResult(mode, targetEl, result);
      scheduleSave();
    } else {
      setStatus(status, 'Anulowano wskazywanie.');
    }
  } catch (e) {
    setStatus(status, 'Nie udało się uruchomić trybu wskazywania (' + e.message + ').', 'err');
  } finally {
    document.querySelectorAll('.pickbtn').forEach((b) => (b.disabled = false));
    button.classList.remove('picking');
  }
}

function applyPickResult(mode, targetEl, result) {
  const status = $('scanStatus');
  if (mode.startsWith('dict:')) {
    const dictKey = mode.slice('dict:'.length); // 'regions' | 'surfaces' | 'paces' | 'difficulties' | 'bikeTypes'
    if (!dictionaries) { setStatus(status, 'Słowniki jeszcze się nie załadowały, spróbuj za chwilę.', 'err'); return; }
    const items = dictKey === 'regions' ? dictionaries.regionsFlat : dictionaries[dictKey];
    const m = bestMatchDict(result.text, items);
    if (!m) { setStatus(status, 'Nie rozpoznano „' + result.text.slice(0, 40) + '” w słowniku — wybierz ręcznie z listy.', 'err'); return; }
    if (dictKey === 'bikeTypes') {
      checkBikeType(m.code);
    } else {
      targetEl.value = m.code;
    }
    setStatus(status, '✓ Dopasowano: ' + m.name, 'ok');
    return;
  }
  if (mode === 'img' && result.imgSrc) {
    targetEl.value = result.imgSrc;
    $('photoPreview').src = result.imgSrc;
    $('photoPreview').hidden = false;
  } else if (mode === 'href') {
    targetEl.value = result.href || result.text || '';
  } else {
    targetEl.value = result.text || result.href || '';
  }
  setStatus(status, '✓ Wstawiono wybrany element.', 'ok');
}

// ---------- Link do mapy -> lat/lng ----------
function extractCoordsFromLink(url) {
  if (!url) return null;
  let m = url.match(/@(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/);
  if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
  m = url.match(/[?&]q=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/);
  if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
  m = url.match(/mlat=(-?\d{1,3}\.\d+)[^&]*&mlon=(-?\d{1,3}\.\d+)/);
  if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
  m = url.match(/[?&]ll=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/);
  if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
  return null;
}

// ---------- Pobieranie plików (GPX/zdjęcie) jako base64 ----------
async function fetchAsBase64(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error('HTTP ' + res.status);
  const buf = await res.arrayBuffer();
  return { base64: arrayBufferToBase64(buf), mime: res.headers.get('content-type') || '' };
}
function arrayBufferToBase64(buf) {
  const bytes = new Uint8Array(buf);
  let binary = '';
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
  }
  return btoa(binary);
}
async function fileToBase64(file) {
  const buf = await file.arrayBuffer();
  return arrayBufferToBase64(buf);
}

// ---------- Skalowanie zdjęcia okładki przed wysyłką ----------
// Bug zgłoszony przez usera 2026-08-09, potwierdzony żywo: publikacja
// przechodziła bez zdjęcia okładki, a z dużym zdjęciem kończyła się gołym
// 403 z serwera hostingu (LiteSpeed/WAF) — ZANIM żądanie w ogóle dotarło do
// aplikacji. Utils\Upload::resizeIfOversized() po stronie ridemore.bike i
// tak by to zdjęcie zeskalowało, ale dopiero PO odebraniu całego uploadu —
// za późno, jeśli firewall blokuje samo żądanie ze względu na rozmiar.
//
// PIERWSZA wersja (jeden rozmiar 1600px + jedna jakość 82%, jak Upload.php)
// okazała się NIEWYSTARCZAJĄCA na żywo: realne zdjęcie spadło z 868 KB do
// tylko 754 KB (wciąż blokowane) — jedna para rozmiar/jakość nie gwarantuje
// niczego dla zdjęcia, które jest bardzo szczegółowe (dużo wysokiej
// częstotliwości = gorsza kompresja JPEG niż prosty test). Naprawione:
// zamiast zgadywać JEDNĄ parę, próbujemy kolejno CORAZ mniejszych par
// rozmiar/jakość, aż wynik faktycznie zmieści się w bezpiecznym limicie
// (COVER_TARGET_BYTES) — niezależnie od tego, jak "ciężkie" jest źródłowe
// zdjęcie. Nie znamy dokładnego progu WAF-a (potwierdzone tylko, że 754 KB
// wciąż za dużo) — 250 KB to spory margines bezpieczeństwa poniżej tego.
// Jeśli nawet najmocniejszy krok nie zmieści się w limicie, wysyłamy
// najmniejszy osiągnięty wynik (zawsze lepszy niż oryginał), nie oryginał.
const COVER_TARGET_BYTES = 250 * 1024;
const COVER_DIMENSION_STEPS = [1600, 1280, 1024, 800, 640];
const COVER_QUALITY_STEPS = [0.82, 0.65, 0.5, 0.35];

function base64ToBlob(base64, mime) {
  const byteChars = atob(base64);
  const bytes = new Uint8Array(byteChars.length);
  for (let i = 0; i < byteChars.length; i++) bytes[i] = byteChars.charCodeAt(i);
  return new Blob([bytes], { type: mime || 'application/octet-stream' });
}

async function resizeCoverPhotoIfNeeded(base64, mime) {
  try {
    const blob = base64ToBlob(base64, mime);
    const bitmap = await createImageBitmap(blob);
    const originalLongSide = Math.max(bitmap.width, bitmap.height);

    // Szybka ścieżka: zdjęcie już mieści się w oryginalnym rozmiarze pliku —
    // nie tykamy go wcale (bez podwójnej kompresji czegoś, co już jest małe).
    if (blob.size <= COVER_TARGET_BYTES) {
      bitmap.close();
      return { base64, mime, resized: false };
    }

    let best = null;
    let lastDim = null;
    for (const dim of COVER_DIMENSION_STEPS) {
      const targetDim = Math.min(dim, originalLongSide);
      if (targetDim === lastDim) continue; // oryginał już mniejszy niż ten krok — pomiń powtórkę
      lastDim = targetDim;
      const ratio = targetDim / originalLongSide;
      const w = Math.max(1, Math.round(bitmap.width * ratio));
      const h = Math.max(1, Math.round(bitmap.height * ratio));
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      canvas.getContext('2d').drawImage(bitmap, 0, 0, w, h);

      for (const q of COVER_QUALITY_STEPS) {
        const resizedBlob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', q));
        if (!resizedBlob) continue;
        if (!best || resizedBlob.size < best.size) best = resizedBlob;
        if (resizedBlob.size <= COVER_TARGET_BYTES) {
          bitmap.close();
          const buf = await resizedBlob.arrayBuffer();
          return { base64: arrayBufferToBase64(buf), mime: 'image/jpeg', resized: true, finalBytes: resizedBlob.size };
        }
      }
    }
    // Żaden krok nie zmieścił się w limicie — wysyłamy najmniejszy osiągnięty
    // wynik zamiast oryginału (zawsze strictly lepszy niż to, co próbowaliśmy przed chwilą).
    bitmap.close();
    if (best) {
      const buf = await best.arrayBuffer();
      return { base64: arrayBufferToBase64(buf), mime: 'image/jpeg', resized: true, finalBytes: best.size };
    }
    return { base64, mime, resized: false };
  } catch (e) {
    console.warn('Nie udało się przeskalować zdjęcia okładki, wysyłam oryginał', e);
    return { base64, mime, resized: false };
  }
}

// ---------- Zbieranie danych z formularza ----------
// Pola, których dany typ NIE ma w kreatorze, są pomijane W PAYLOADZIE (nie
// tylko chowane w UI) — m.in. dlatego, że backend przyjmuje warianty
// (EventFormInput::fromRequest) także dla typów, które kreator ich nie
// pokazuje, więc "dokładanie" ich z panelu dla wielodniówki/pokrec
// tworzyłoby wydarzenia w stanie, którego kreator nie potrafi odtworzyć.
// Widoczność w UI — syncTypeVisibility(); tu tylko odcięcie danych.
function gatherPayload() {
  const val = (id) => ($(id) ? $(id).value.trim() : '');
  const num = (id) => { const v = val(id); return v === '' ? null : parseFloat(v); };
  const lines = (id) => val(id).split('\n').map((s) => s.trim()).filter(Boolean);

  const t = currentType();
  const isPokrec = t === 'pokrec_z_kims';
  const isSingleDay = t === 'ustawka' || t === 'wyscig';
  const isMultiDay = t === 'wycieczka_wielodniowa';

  const variants = readVariants().filter((v) => v.name || v.distanceKm);
  const stages = readStages().filter((s) => s.dateIso || s.title || s.startPoint || s.endPoint
    || s.distanceKm != null || s.elevationM != null || s.accommodationType || s.accommodationName
    || (s.meals && s.meals.length) || s.notes);

  return {
    type: t,
    title: val('f_title'),
    description: val('f_description'),
    organizer: selectedOrganizer ? selectedOrganizer.name : val('f_organizer'),
    organizerId: selectedOrganizer ? selectedOrganizer.id : null,
    // Wymagane przez kreator dla NOWEGO organizatora (patrz komentarz w
    // sidepanel.html przy f_organizerEmail) — puste, gdy organizerId jest
    // ustawiony (dopasowany istniejący, ten mail nie jest wtedy potrzebny).
    organizerEmail: val('f_organizerEmail'),
    // "Twoje dane" — trwałe, NIE per-event (patrz ME_STORAGE) — czytane
    // wprost z pól (już wczytanych z chrome.storage w init()), nie z
    // osobnego zapytania async, żeby gatherPayload() zostało synchroniczne.
    submitterName: val('f_meName'),
    submitterEmail: val('f_meEmail'),
    dateIso: val('f_date'),
    time: val('f_time'),
    // endDate/dateIsFlexible istnieją w kreatorze tylko dla pokrec_z_kims.
    ...(isPokrec ? { endDate: val('f_endDate'), dateIsFlexible: $('f_dateIsFlexible').checked } : {}),
    // Turnusy — kreator pokazuje je tylko dla typów nie-pokrec (step-kiedy.php).
    ...(isPokrec ? {} : { additionalDates: readAdditionalDates() }),
    region: val('f_region'), // już prawdziwy kod słownika (select)
    meetingPointLabel: val('f_meetingLabel'),
    meetingLat: num('f_lat'),
    meetingLng: num('f_lng'),
    gpxUrl: val('f_gpxUrl'),
    distanceKm: num('f_distance'),
    elevationM: num('f_elevation'),
    surface: val('f_surface'), // kod słownika
    photoUrl: val('f_photoUrl'),
    bikeTypes: getCheckedBikeTypes(), // kody słownika
    pace: val('f_pace'), // kod słownika
    difficulty: val('f_difficulty'), // kod słownika
    equipment: readEquipment().filter((e) => e.name), // [{name, mandatory}]
    minParticipants: num('f_minParticipants'),
    maxParticipants: num('f_maxParticipants'),
    // Pieniądze i zapisy — sekcja nie istnieje dla pokrec_z_kims (kreator
    // x-if="type!=='pokrec_z_kims'", backend wymusza darmowe+internal).
    ...(isPokrec ? {} : {
      isPaid: $('f_isPaid').checked,
      priceAmount: num('f_priceAmount'),
      priceCurrency: val('f_priceCurrency'), // kod słownika
      priceUnit: val('f_priceUnit'), // kod słownika
      // Zaliczka głównego eventu: przy wariantach backend ją ignoruje
      // (EventFormInput: $hasVariants ? null : deposit) — nie wysyłamy wtedy.
      deposit: variants.length ? null : num('f_deposit'),
      priceIncluded: lines('f_priceIncluded'),
      priceExcluded: lines('f_priceExcluded'),
      paymentDeadlineDays: num('f_paymentDeadlineDays'),
      cancellationDeadlineDays: num('f_cancellationDeadlineDays'),
      cancellationPolicy: val('f_cancellationPolicy'),
      registrationUrl: val('f_regUrl'),
      registrationPhone: val('f_regPhone'),
      registrationEmail: val('f_regEmail'),
    }),
    // Warianty trasy — tylko ustawka/wyścig (kreator: step-trasa.php).
    ...(isSingleDay ? { variants } : {}),
    // Dni wielodniówki — tylko wycieczka_wielodniowa (krok Plan).
    ...(isMultiDay ? { stages } : {}),
  };
}

// ---------- Otwarcie/przełączenie karty ridemore.bike i wypełnienie ----------
function waitTabComplete(tabId, timeoutMs) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { chrome.tabs.onUpdated.removeListener(listener); reject(new Error('timeout ładowania strony')); }, timeoutMs);
    function listener(id, info) {
      if (id === tabId && info.status === 'complete') {
        clearTimeout(timer);
        chrome.tabs.onUpdated.removeListener(listener);
        resolve();
      }
    }
    chrome.tabs.onUpdated.addListener(listener);
  });
}

// externalPayload — gdy podane, pomija odczyt formularza panelu i lokalnie
// wybrane pliki GPX/zdjęcia; korzysta wyłącznie z gpxUrl/photoUrl zawartych
// w tym payloadzie. Obecnie nieużywane przez żaden przycisk (AI-Engine też
// leci przez zwykłe pola panelu, patrz applyEngineResult) — zostaje jako
// gotowa furtka pod przyszły tryb "wypełnij z gotowego JSON-a".
// UWAGA (bug zgłoszony przez usera 2026-08-09, patrz rejestracja btnFill
// niżej): tej funkcji NIE WOLNO podpinać wprost jako addEventListener('click',
// fillRidemore) — przeglądarka przekazuje wtedy obiekt zdarzenia kliknięcia
// jako externalPayload (zawsze "prawdziwy"/truthy), więc gatherPayload() w
// ogóle się nie wykonuje i sprawdzenie tytułu niżej patrzy na zdarzenie
// kliknięcia zamiast na formularz — stąd "Podaj przynajmniej tytuł" nawet
// gdy tytuł jest wpisany. Trzeba wołać przez () => fillRidemore().
async function fillRidemore(externalPayload) {
  const status = $('fillStatus');
  const btn = $('btnFill');
  btn.disabled = true;
  try {
    const payload = externalPayload || gatherPayload();
    if (!payload.title) {
      setStatus(status, 'Podaj przynajmniej tytuł — bez tego kreator go zawróci.', 'err');
      return;
    }

    setStatus(status, 'Przygotowuję pliki (GPX/zdjęcie)…');
    const gpxFile = externalPayload ? null : $('f_gpxFile').files[0];
    if (gpxFile) {
      payload.gpxBase64 = await fileToBase64(gpxFile);
      payload.gpxFilename = gpxFile.name;
    } else if (payload.gpxUrl) {
      try {
        const { base64 } = await fetchAsBase64(payload.gpxUrl);
        payload.gpxBase64 = base64;
        payload.gpxFilename = payload.gpxUrl.split('/').pop().split('?')[0] || 'trasa.gpx';
      } catch (e) {
        console.warn('GPX fetch failed', e);
      }
    }
    // Warianty trasy — KAŻDY może mieć własny, ODRĘBNY plik GPX (patrz
    // event-form.php: Models\EventRouteVariant). Ta sama obróbka co główny
    // plik wyżej, per wariant — fill.js wysyła każdy osobno do
    // /api/gpx/parse (patrz uploadGpxAndGetToken tam). Błąd pojedynczego
    // wariantu nie przerywa reszty — user i tak dogra ręcznie w kreatorze.
    if (Array.isArray(payload.variants) && payload.variants.length) {
      for (const v of payload.variants) {
        if (!v.gpxUrl) continue;
        try {
          const { base64 } = await fetchAsBase64(v.gpxUrl);
          v.gpxBase64 = base64;
          v.gpxFilename = v.gpxUrl.split('/').pop().split('?')[0] || 'trasa.gpx';
        } catch (e) {
          console.warn('Variant GPX fetch failed', v.name, e);
        }
      }
    }
    // Dni wielodniówki — TA SAMA zasada co warianty wyżej: każdy dzień może
    // mieć własny, odrębny plik GPX (event-form.php: Models\EventStage).
    // payload.stages[i].gpxUrl już rozwiązane z gpxLinkIndex w
    // applyEngineResult() (nie ma panelu do tego, patrz komentarz tam).
    if (Array.isArray(payload.stages) && payload.stages.length) {
      for (const s of payload.stages) {
        if (!s.gpxUrl) continue;
        try {
          const { base64 } = await fetchAsBase64(s.gpxUrl);
          s.gpxBase64 = base64;
          s.gpxFilename = s.gpxUrl.split('/').pop().split('?')[0] || 'trasa.gpx';
        } catch (e) {
          console.warn('Stage GPX fetch failed', s.title || s.dateIso, e);
        }
      }
    }

    // Zdjęcie okładki — DWIE różne ścieżki, celowo różne od 2026-08-09:
    // 1) plik lokalny (wybrany z dysku w panelu) — jedyny przypadek, gdzie
    //    przeglądarka MUSI wysłać same bajty (nie ma linku, z którego serwer
    //    mógłby je sam ściągnąć) — skalujemy PRZED wysyłką (patrz
    //    resizeCoverPhotoIfNeeded() wyżej), żeby duży plik nie zrobił żądania
    //    POST na tyle dużego, że firewall hostingu je odrzuci.
    // 2) link do zdjęcia (payload.photoUrl, np. wykryty przez AI-Engine) —
    //    CELOWO NIE pobieramy go tu wcale. Leci jako zwykły string do
    //    fill.js, które wstawia go do ukrytego pola cover_photo_source_url —
    //    SERWER sam go ściąga przy zapisie (patrz Resources\EventFormInput,
    //    Utils\Upload::saveCoverPhotoFromUrl()). Duże zdjęcie z linku
    //    NIGDY nie trafia do POST-a przeglądarki, więc nie ma czego blokować
    //    firewallowi — to dokładnie ten przypadek, który wcześniej dawał
    //    gołe 403 (zgłoszenie usera, potwierdzone żywo: pierwsza wersja
    //    skalowania PRZED wysyłką pomogła, ale za mało — to podejście usuwa
    //    problem u źródła zamiast tylko go zmniejszać).
    const photoFile = externalPayload ? null : $('f_photoFile').files[0];
    if (photoFile) {
      payload.photoBase64 = await fileToBase64(photoFile);
      payload.photoFilename = photoFile.name;
      payload.photoMime = photoFile.type;
      const resized = await resizeCoverPhotoIfNeeded(payload.photoBase64, payload.photoMime);
      payload.photoBase64 = resized.base64;
      payload.photoMime = resized.mime;
      if (resized.resized) {
        payload.photoFilename = (payload.photoFilename || 'zdjecie').replace(/\.[^.]+$/, '') + '.jpg';
      }
    }

    setStatus(status, 'Otwieram ridemore.bike…');
    const [existing] = await chrome.tabs.query({ url: 'https://ridemore.bike/wydarzenia/*' });
    let tab;
    if (existing) {
      tab = await chrome.tabs.update(existing.id, { url: RIDEMORE_NEW_EVENT_URL, active: true });
    } else {
      tab = await chrome.tabs.create({ url: RIDEMORE_NEW_EVENT_URL });
    }
    await chrome.windows.update(tab.windowId, { focused: true }).catch(() => {});
    await waitTabComplete(tab.id, 30000);
    await new Promise((r) => setTimeout(r, 250)); // Alpine/defer scripts dobiegają tuż po 'complete'

    setStatus(status, 'Wypełniam kreator…');
    await chrome.scripting.executeScript({ target: { tabId: tab.id }, world: 'MAIN', files: ['scripts/fill.js'] });
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      world: 'MAIN',
      func: (p) => window.__rmiFillWizard(p),
      args: [payload],
    });

    if (result && result.ok) {
      let msg = '✓ Wypełniono. Sprawdź kartę ridemore.bike — kreator stoi na kroku „Podsumowanie”, nic nie wysłaliśmy.';
      if (result.gpx && result.gpx.error) msg += ' ⚠ GPX: ' + result.gpx.error;
      if (payload.photoBase64 && !result.photoOk) msg += ' ⚠ Nie udało się dołączyć zdjęcia — dodaj ręcznie.';
      setStatus(status, msg, result.gpx && result.gpx.error ? 'err' : 'ok');
    } else {
      setStatus(status, 'Wypełniono częściowo — sprawdź kartę ridemore.bike.', 'err');
    }
  } catch (e) {
    setStatus(status, 'Błąd: ' + e.message, 'err');
  } finally {
    btn.disabled = false;
  }
}

// ---------- Widoczność sekcji wg typu wydarzenia ----------
// Kreator pokazuje różne sekcje dla różnych typów (x-if w step-*.php); panel
// robi to samo, żeby nie zachęcać do wypełniania pól, które nie mają gdzie
// trafić. WAŻNE: samo ukrycie NIE wystarcza — gatherPayload() dodatkowo
// pomija te dane w payloadzie (patrz komentarz przy jego definicji).
function syncTypeVisibility() {
  const t = currentType();
  const isPokrec = t === 'pokrec_z_kims';
  const isSingleDay = t === 'ustawka' || t === 'wyscig';
  const isMultiDay = t === 'wycieczka_wielodniowa';
  // moneySection: kreator x-if="type!=='pokrec_z_kims'" (pokrec = darmowe+internal).
  $('moneySection').hidden = isPokrec;
  // Warianty trasy: tylko ustawka/wyścig (step-trasa.php x-if).
  $('variantsBox').hidden = !isSingleDay;
  // Dni wielodniówki: tylko wycieczka_wielodniowa (krok Plan).
  $('stagesSection').hidden = !isMultiDay;
  // Terminy: pokrec ma start+end (ramy sezonu) zamiast turnusów.
  $('datesBox').hidden = isPokrec;
  $('endDateBox').hidden = !isPokrec;
  // Trasa (GPX/dystans/przewyższenie/nawierzchnia): wielodniówka ma to per
  // dzień, główny event jej nie ma.
  $('trasaSection').hidden = isMultiDay;
}

function clearAll() {
  if (!confirm('Wyczyścić wszystkie pola?')) return;
  FIELD_IDS.forEach((id) => {
    const el = $(id);
    if (!el) return;
    if (el.type === 'checkbox') el.checked = false;
    else el.value = '';
  });
  if (dictionaries) {
    const pln = dictionaries.currencies.find((c) => c.code === 'PLN');
    if (pln) $('f_priceCurrency').value = 'PLN';
  }
  $('variantsList').innerHTML = '';
  $('datesList').innerHTML = '';
  $('equipmentList').innerHTML = '';
  $('stagesList').innerHTML = '';
  $('f_bikeTypes').querySelectorAll('input:checked').forEach((i) => (i.checked = false));
  $('photoPreview').hidden = true;
  $('photoPreview').src = '';
  $('dateRawHint').textContent = '';
  $('f_gpxFile').value = '';
  $('f_photoFile').value = '';
  document.querySelector('input[name="f_type"][value="ustawka"]').checked = true;
  typeManuallySet = false;
  selectedOrganizer = null;
  renderSelectedOrganizer();
  dismissedOrgDomains.clear();
  syncTypeVisibility();
  setStatus($('scanStatus'), '');
  setStatus($('fillStatus'), '');
  chrome.storage.local.remove(STORAGE_KEY);
}

document.addEventListener('DOMContentLoaded', async () => {
  await loadDictionaries(false); // MUSI być przed loadState(), inaczej <select>.value nie złapie się na jeszcze nieistniejące <option>
  await loadState();
  syncTypeVisibility(); // sekcje wg zapisanego/wykrytego typu (przyciski radio są wtedy już wypełnione)
  $('f_engineUrl').value = await getEngineUrl();
  const me = await getMe();
  $('f_meName').value = me.name;
  $('f_meEmail').value = me.email;

  $('btnEngineScan').addEventListener('click', engineScanActiveTab);
  $('btnGroupScan').addEventListener('click', scanGroupFeed);
  $('btnCollectLinks').addEventListener('click', collectEventLinks);
  $('btnPostToEvent').addEventListener('click', pickPostAsEvent);
  $('btnPickPosts').addEventListener('click', pickGroupPosts);
  $('btnTreasureScan').addEventListener('click', scanTreasurePage);
  $('btnEngineSettings').addEventListener('click', () => { $('engineSettingsBox').hidden = !$('engineSettingsBox').hidden; });
  $('btnSaveEngineUrl').addEventListener('click', async () => {
    await saveEngineUrl($('f_engineUrl').value);
    setStatus($('scanStatus'), '✓ Zapisano adres mostu AI-Engine.', 'ok');
  });
  $('btnMeSettings').addEventListener('click', () => { $('meSettingsBox').hidden = !$('meSettingsBox').hidden; });
  $('btnSaveMe').addEventListener('click', async () => {
    await saveMe($('f_meName').value, $('f_meEmail').value);
    setStatus($('scanStatus'), '✓ Zapisano Twoje dane.', 'ok');
  });
  $('btnClear').addEventListener('click', clearAll);
  // () => fillRidemore() — NIE fillRidemore wprost, patrz komentarz przy
  // definicji funkcji (obiekt zdarzenia kliknięcia inaczej trafia jako
  // externalPayload i przykrywa prawdziwe dane z formularza).
  $('btnFill').addEventListener('click', () => fillRidemore());
  $('btnAddVariant').addEventListener('click', () => { addVariantRow(); scheduleSave(); });
  $('btnAddDate').addEventListener('click', () => { addDateRow(); scheduleSave(); });
  $('btnAddStage').addEventListener('click', () => { addStageRow(); scheduleSave(); });
  $('btnAddEquipment').addEventListener('click', () => { addEquipmentRow(); scheduleSave(); });
  $('btnRefreshDict').addEventListener('click', () => loadDictionaries(true));
  $('btnExtractCoords').addEventListener('click', () => {
    const coords = extractCoordsFromLink($('f_mapLink').value.trim());
    if (coords) { $('f_lat').value = coords.lat; $('f_lng').value = coords.lng; scheduleSave(); }
    else setStatus($('scanStatus'), 'Nie rozpoznano współrzędnych w tym linku.', 'err');
  });
  document.querySelectorAll('input[name="f_type"]').forEach((r) => r.addEventListener('change', () => { typeManuallySet = true; syncTypeVisibility(); }));
  document.querySelectorAll('.pickbtn').forEach((b) => b.addEventListener('click', () => startPick(b)));
  document.getElementById('rmiForm').addEventListener('input', scheduleSave);
  document.getElementById('rmiForm').addEventListener('change', scheduleSave);
  $('f_photoFile').addEventListener('change', () => {
    const file = $('f_photoFile').files[0];
    if (!file) return;
    $('photoPreview').src = URL.createObjectURL(file);
    $('photoPreview').hidden = false;
  });
  $('f_gpxFile').addEventListener('change', () => {
    const file = $('f_gpxFile').files[0];
    $('gpxFileStatus').textContent = file ? ('Wybrano: ' + file.name) : '';
  });
});
