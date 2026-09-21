// scripts/fill.js
// Wstrzykiwane WYŁĄCZNIE na ridemore.bike (/wydarzenia/nowe), world: "MAIN"
// (musi widzieć window.Alpine strony — content scripty w world "ISOLATED"
// mają własny obiekt window i go nie widzą). Ten plik tylko DEFINIUJE
// window.__rmiFillWizard — samo wstrzyknięcie pliku nic nie robi (celowo:
// sidepanel.js woła potem tę funkcję osobnym executeScript(func:..., args:
// [payload]), bo files+args nie da się łączyć w jednym wywołaniu API).
//
// Zasada bezpieczeństwa: NIC nie wysyłamy. Wypełniamy stan Alpine (to co i
// tak leci do <input>-ów po drodze), lądujemy na kroku "podsumowanie" i tam
// się zatrzymujemy — publikuje/zapisuje szkic wyłącznie użytkownik, ręcznie.
(function () {
  if (window.__rmiFillWizard) return; // już zdefiniowane — nic do zrobienia

  function waitFor(check, timeoutMs) {
    return new Promise((resolve, reject) => {
      const start = Date.now();
      (function tick() {
        const v = check();
        if (v) return resolve(v);
        if (Date.now() - start > timeoutMs) return reject(new Error('timeout'));
        requestAnimationFrame(tick);
      })();
    });
  }

  // Dopasowywanie tekstu na kody słownika NIE dzieje się już tutaj — panel
  // boczny (sidepanel.js) rozwiązuje region/nawierzchnię/rowery/tempo/
  // trudność/walutę na prawdziwe kody PRZED wywołaniem tej funkcji, przez
  // GET /api/dictionaries na ridemore.bike (patrz md/routing.md). payload.*
  // niesie więc gotowe kody — to pole tu tylko je przypisuje, bez zgadywania
  // z DOM-u kreatora (poprzednia wersja parsowała atrybuty "@click" kafelków
  // tempo/trudność — działało, ale było kruche i zależne od markupu).

  // base_path bywa niepusty na dev (np. "/ridemore", patrz md/architecture.md) —
  // wyliczamy go z bieżącego adresu zamiast zakładać root-relative "/api/...",
  // żeby to samo działało i na ridemore.bike (prod), i na lokalnym XAMPP (dev).
  function apiBasePrefix() {
    return location.pathname.replace(/\/wydarzenia\/nowe\/?$/, '');
  }

  async function uploadGpxAndGetToken(root, base64, filename) {
    const csrfInput = root.querySelector('input[name="csrf_token"]');
    if (!csrfInput || !base64) return null;
    const byteChars = atob(base64);
    const bytes = new Uint8Array(byteChars.length);
    for (let i = 0; i < byteChars.length; i++) bytes[i] = byteChars.charCodeAt(i);
    const file = new File([bytes], filename || 'trasa.gpx', { type: 'application/gpx+xml' });
    const fd = new FormData();
    fd.append('gpx', file);
    fd.append('csrf_token', csrfInput.value);
    const res = await fetch(apiBasePrefix() + '/api/gpx/parse', { method: 'POST', body: fd });
    if (!res.ok) return null;
    return res.json();
  }

  function setFileInput(inputEl, base64, filename, mime) {
    if (!inputEl || !base64) return false;
    const byteChars = atob(base64);
    const bytes = new Uint8Array(byteChars.length);
    for (let i = 0; i < byteChars.length; i++) bytes[i] = byteChars.charCodeAt(i);
    const file = new File([bytes], filename, { type: mime || 'application/octet-stream' });
    const dt = new DataTransfer();
    dt.items.add(file);
    inputEl.files = dt.files;
    inputEl.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  window.__rmiFillWizard = async function (payload) {
    const rootEl = await waitFor(() => document.querySelector('.k-page[x-data]'), 15000);
    await waitFor(() => window.Alpine && typeof window.Alpine.$data === 'function', 15000);
    const data = window.Alpine.$data(rootEl);
    if (!data) throw new Error('Nie znaleziono stanu kreatora (Alpine).');

    // Ostatnia linia obrony przed wstrzyknięciem nieznanego typu do kreatora
    // (startWizard() gołym tekstem trafia do TYPE_META[type]/STEP_MIN[type] —
    // nieznany klucz = undefined, dalsze wyliczenia sypią się cicho). Lista
    // musi być zsynchronizowana z dictionary_items(event_type) na ridemore.bike
    // i z syncEventTypeRadios() w sidepanel.js — to jedyne miejsce w fill.js,
    // które trzeba dopisać przy kolejnym nowym typie (panel sam dociąga
    // radiobuttony z /api/dictionaries, ale ten guard, uruchamiany W KONTEKŚCIE
    // strony ridemore.bike, nie ma tam skąd pobrać listy na bieżąco).
    const type = ['ustawka', 'wycieczka_wielodniowa', 'pokrec_z_kims', 'wyscig'].includes(payload.type) ? payload.type : 'ustawka';
    data.startWizard(type);
    await new Promise((r) => setTimeout(r, 30)); // 1 tick — patrz gotcha w md/views-and-frontend.md (ustaw-potem-odczytaj)

    if (payload.title) { data.title = payload.title; data.titleManuallyEdited = true; }
    if (payload.description) data.description = payload.description;

    // organizerId ustawiony -> panel dopasował istniejący profil po domenie
    // strony WWW (GET /api/organizers/match-domain) i user to potwierdził —
    // to samo, co ręczne selectOrganizer(r) w script.php. Bez organizerId, ale
    // z nazwą -> zwykła propozycja "nowego organizatora" jak dotąd.
    if (payload.organizerId) {
      data.organizerMode = 'existing';
      data.organizerId = payload.organizerId;
      data.organizerSearchQuery = payload.organizer || '';
      data.organizerConfirmed = true;
    } else if (payload.organizer) {
      data.organizerMode = 'new';
      data.organizerSearchQuery = payload.organizer;
      data.organizerConfirmed = false;
      // WYMAGANE przez validateBeforeSubmit() w script.php dla KAŻDEGO
      // nowego organizatora (niezależnie od zalogowania) — bez tego kreator
      // blokuje publikację i wraca do kroku "Kogo". Input pokazuje się
      // dopiero po organizerConfirmed=true (user klika w kroku "Kogo"), ale
      // stan Alpine już jest gotowy, gdy tam dotrze.
      if (payload.organizerEmail) data.newOrganizerEmail = payload.organizerEmail;
      try { data.searchOrganizers(); } catch (e) { /* nieszkodliwe — user i tak dopatrzy w kroku "Kogo" */ }
    }

    // "Zgłaszający" (submitter_name/submitter_email) — WYMAGANE przez
    // validateBeforeSubmit(), gdy sesja ridemore.bike NIE jest zalogowana
    // (patrz script.php x-show="!isLoggedIn"). Ustawiamy zawsze, gdy panel
    // je ma (patrz "Twoje dane" w sidepanel.html) — nieszkodliwe, jeśli
    // sesja jest zalogowana (te pola wtedy nie są nawet pokazywane).
    if (payload.submitterName) data.submitterName = payload.submitterName;
    if (payload.submitterEmail) data.submitterEmail = payload.submitterEmail;

    // Wielodniówka: AI może wykryć osobny plan dzień-po-dniu (payload.stages,
    // patrz ai-engine/analyze.py _as_stage_list) — zastępujemy CAŁĄ tablicę
    // stages, nie tylko stages[0]. Świeży kreator zaczyna z jednym pustym
    // dniem (EventFormResource::empty()), więc nadpisanie tu nic nie gubi.
    // Kształt musi zgadzać się z emptyStage()/addStage() w script.php — pola
    // bez odpowiednika u AI (nocleg/posiłki/lat-lng per dzień) zostają
    // puste, user dogrywa ręcznie w kroku "Plan". GPX per dzień — event-form.php:
    // Models\EventStage wspiera WŁASNY plik per dzień (tak jak per wariant,
    // patrz niżej) — payload.stages[i].gpxBase64 przygotowane wcześniej w
    // sidepanel.js (fetchAsBase64 na URL rozwiązany z gpxLinkIndex).
    const stageGpxResults = [];
    const multiDayStages = (type === 'wycieczka_wielodniowa' && Array.isArray(payload.stages) && payload.stages.length > 1)
      ? payload.stages
      : null;
    if (multiDayStages) {
      data.stages = [];
      for (const s of multiDayStages) {
        const stage = {
          date: s.dateIso || '', title: s.title || '',
          startLabel: s.startPoint || '', startLat: null, startLng: null,
          endLabel: s.endPoint || '', endLat: null, endLng: null,
          distanceKm: s.distanceKm ?? '', elevationM: s.elevationM ?? '',
          // Nawierzchnia: dzień może mieć własną (edytor dni w panelu), dopiero
          // potem spadek na wspólną dla eventu i na domyślną jak w kreatorze.
          surface: s.surface || payload.surface || 'asfalt',
          surfaceAsphaltPct: null, surfaceGravelPct: null, surfaceTrailPct: null,
          gpxUrl: null, gpxToken: '',
          // Nocleg/posiłki/notatki per dzień (step-plan.php) — teraz wypełniane
          // z payload.stages[i] (edytor dni w panelu / AI), nie zostawiane
          // puste do ręcznego dopisywania w kreatorze.
          accommodationType: s.accommodationType || '', accommodationName: s.accommodationName || '', accommodationAddress: '',
          meals: Array.isArray(s.meals) ? s.meals.filter(Boolean) : [],
          notes: s.notes || '',
        };
        if (s.gpxBase64) {
          try {
            const parsed = await uploadGpxAndGetToken(rootEl, s.gpxBase64, s.gpxFilename || 'trasa.gpx');
            if (parsed && parsed.token) {
              stage.gpxToken = parsed.token;
              stage.distanceKm = parsed.distanceKm;
              stage.elevationM = parsed.elevationGainM;
              if (parsed.surface) {
                stage.surfaceAsphaltPct = parsed.surface.asphaltPct;
                stage.surfaceGravelPct = parsed.surface.gravelPct;
                stage.surfaceTrailPct = parsed.surface.trailPct;
              }
            } else {
              stageGpxResults.push({ name: stage.title || stage.date, error: 'Serwer nie rozpoznał pliku GPX.' });
            }
          } catch (e) {
            stageGpxResults.push({ name: stage.title || stage.date, error: 'Nie udało się wysłać pliku GPX (' + e.message + ').' });
          }
        }
        data.stages.push(stage);
      }
    }

    if (!multiDayStages && payload.dateIso) data.stages[0].date = payload.dateIso;
    if (payload.time) data.startTime = payload.time;
    // endDate: albo z panelu (f_endDate), albo z AI. Wcześniej ustawiane
    // TYLKO dla "pokrec_z_kims" (dorzucane = dateIso, bo ten typ nie ma
    // osobnego pola "do") — teraz ogólne, ale nie nadpisuje tamtego
    // fallbacku, jeśli AI/panel nic konkretnego nie podały.
    if (payload.endDate) data.endDate = payload.endDate;
    else if (type === 'pokrec_z_kims' && payload.dateIso) data.endDate = payload.dateIso;
    if (payload.dateIsFlexible) data.dateIsFlexible = true;
    if (Array.isArray(payload.additionalDates) && payload.additionalDates.length) {
      data.additionalDates = payload.additionalDates.filter(Boolean);
    }

    if (payload.region) data.region = payload.region;
    if (payload.meetingLat != null && payload.meetingLng != null) {
      data.meetingPoint = { lat: payload.meetingLat, lng: payload.meetingLng, label: payload.meetingPointLabel || data.meetingPoint.label || '' };
      if (!data.stages[0].startLat) { data.stages[0].startLat = payload.meetingLat; data.stages[0].startLng = payload.meetingLng; }
    } else if (payload.meetingPointLabel) {
      data.meetingPoint.label = payload.meetingPointLabel;
    }

    if (!multiDayStages) {
      if (payload.distanceKm != null) data.stages[0].distanceKm = payload.distanceKm;
      if (payload.elevationM != null) data.stages[0].elevationM = payload.elevationM;
      if (payload.surface) data.stages[0].surface = payload.surface;
    }

    if (Array.isArray(payload.bikeTypes) && payload.bikeTypes.length) data.bikeTypes = payload.bikeTypes;
    if (payload.pace) data.pace = payload.pace;
    if (payload.difficulty) data.difficulty = payload.difficulty;
    // Sprzęt — nowy kształt z panelu: payload.equipment = [{name, mandatory}]
    // (edytor "Co zabrać" w panelu, odpowiednik equipment[i] w kreatorze,
    // step-dlakogo.php). Legacy payload.whatToBring (gołe stringi) działa
    // dalej jako fallback z mandatory:false — kompatybilność ze starszymi
    // mostami AI-Engine, które panelu nie mają.
    const equipItems = Array.isArray(payload.equipment)
      ? payload.equipment
      : (Array.isArray(payload.whatToBring) ? payload.whatToBring.map((name) => ({ name, mandatory: false })) : null);
    if (equipItems && equipItems.length) {
      data.equipment = equipItems
        .filter((e) => e && e.name && e.name.trim())
        .map((e) => ({ name: e.name.trim(), mandatory: !!e.mandatory }));
      data.whoMoreOpen = true;
    }

    // Limit uczestników CAŁEGO eventu — limitParticipants jest WYLICZANE
    // (min LUB max ustawione), ten sam wzorzec co
    // Resources\EventFormResource (limitParticipants = min!==null||max!==null),
    // żeby stan Alpine i backend zgadzały się bez osobnego pola do ustawienia.
    if (payload.minParticipants != null || payload.maxParticipants != null) {
      data.limitParticipants = true;
      if (payload.minParticipants != null) data.minParticipants = payload.minParticipants;
      if (payload.maxParticipants != null) data.maxParticipants = payload.maxParticipants;
    }

    if (payload.isPaid) {
      data.isPaid = true;
      if (payload.priceAmount != null) data.priceAmount = payload.priceAmount;
      if (payload.priceCurrency) data.currency = payload.priceCurrency;
      // Zaliczka głównego eventu (kreator: step-pieniadze.php). Panel już jej
      // nie wysyła przy wariantach (backend i tak ją tam ignoruje), ale i tak
      // bronimy się przed wysłaniem czegokolwiek poza płatnym eventem.
      if (payload.deposit != null) data.deposit = payload.deposit;
    }
    if (payload.priceUnit) data.priceUnit = payload.priceUnit;
    if (payload.paymentDeadlineDays != null) data.paymentDeadlineDays = payload.paymentDeadlineDays;
    if (payload.cancellationDeadlineDays != null) data.cancellationDeadlineDays = payload.cancellationDeadlineDays;
    if (payload.cancellationPolicy) data.cancellationPolicy = payload.cancellationPolicy;
    const included = Array.isArray(payload.priceIncluded) ? payload.priceIncluded.filter(Boolean) : [];
    const excluded = Array.isArray(payload.priceExcluded) ? payload.priceExcluded.filter(Boolean) : [];
    if (included.length || excluded.length) {
      data.priceItems = [
        ...included.map((category) => ({ category, isIncluded: true })),
        ...excluded.map((category) => ({ category, isIncluded: false })),
      ];
    }

    if (payload.registrationUrl || payload.registrationPhone || payload.registrationEmail) {
      data.regBoxOpen = true;
      data.externalUrl = payload.registrationUrl || '';
      data.externalPhone = payload.registrationPhone || '';
      data.externalEmail = payload.registrationEmail || '';
      data.registrationType = 'external';
    }

    // Warianty trasy — KAŻDY może mieć WŁASNY plik GPX (event-form.php:
    // Models\EventRouteVariant, ta sama obsługa co główny event: upload ->
    // /api/gpx/parse -> dystans/przewyższenie/nawierzchnia z pliku).
    // payload.variants[i].gpxBase64 przygotowane wcześniej w sidepanel.js
    // (fetchAsBase64 na URL rozwiązany z gpxLinkIndex) — tu tylko wysyłka,
    // sekwencyjnie (nie Promise.all — każdy upload i tak trafia w ten sam
    // endpoint/CSRF, kolejno jest prościej debugować przy błędzie).
    const variantGpxResults = [];
    // Guard typów: kreator pokazuje warianty TYLKO dla ustawka/wyścig
    // (step-trasa.php x-if="type==='ustawka' || type==='wyscig'"). Panel już
    // ich nie wysyła dla innych typów (gatherPayload), ale bronimy się też tu
    // — backend (EventFormInput) przyjmuje variants dla KAŻDEGO typu, więc
    // przypadkowe wstrzyknięcie wygenerowałoby stan, którego kreator nie
    // potrafi odtworzyć.
    if ((type === 'ustawka' || type === 'wyscig') && Array.isArray(payload.variants) && payload.variants.length) {
      data.variantsEnabled = true;
      data.variants = [];
      for (const v of payload.variants) {
        const variant = {
          name: v.name || '', distanceKm: v.distanceKm ?? '', elevationM: v.elevationM ?? '',
          // Nawierzchnia wariantu (edytor panelu / AI); spadek na główną
          // etapu, bo kreator i tak domyślnie bierze stages[0].surface.
          surface: v.surface || data.stages[0].surface || 'asfalt',
          surfaceAsphaltPct: null, surfaceGravelPct: null, surfaceTrailPct: null,
          gpxUrl: null, gpxToken: '',
          // Cena/zaliczka/limit per wariant (variants.php + step-pieniadze.php)
          // — teraz wypełniane z payload.variants[i] (puste stringi = jak
          // "bez limitu/bez własnej ceny" w kreatorze).
          priceAmount: v.priceAmount ?? '', deposit: v.deposit ?? '', maxParticipants: v.maxParticipants ?? '',
        };
        if (v.gpxBase64) {
          try {
            const parsed = await uploadGpxAndGetToken(rootEl, v.gpxBase64, v.gpxFilename || 'trasa.gpx');
            if (parsed && parsed.token) {
              variant.gpxToken = parsed.token;
              variant.distanceKm = parsed.distanceKm;
              variant.elevationM = parsed.elevationGainM;
              if (parsed.surface) {
                variant.surfaceAsphaltPct = parsed.surface.asphaltPct;
                variant.surfaceGravelPct = parsed.surface.gravelPct;
                variant.surfaceTrailPct = parsed.surface.trailPct;
              }
            } else {
              variantGpxResults.push({ name: variant.name, error: 'Serwer nie rozpoznał pliku GPX.' });
            }
          } catch (e) {
            variantGpxResults.push({ name: variant.name, error: 'Nie udało się wysłać pliku GPX (' + e.message + ').' });
          }
        }
        data.variants.push(variant);
      }
    }

    // GPX — przez prawdziwy endpoint /api/gpx/parse (te same metryki co ręczny
    // upload: dystans/przewyższenie/nawierzchnia wyliczone z pliku, nadpisują
    // wpisane wyżej wartości tekstowe, jeśli plik faktycznie się wczytał).
    // Pomijamy przy wielodniówce (multiDayStages) — inaczej ten "główny" plik
    // (koncepcyjnie nie ma jednego przy kilku dniach) nadpisałby PO CZASIE
    // to, co dzień-po-dniu blok wyżej już poprawnie ustawił dla stages[0] z
    // WŁASNEGO pliku tego dnia (błąd znaleziony żywo 2026-08-07, przy
    // dodawaniu GPX per dzień — bug istniał już wcześniej, po prostu bez
    // realnego skutku, dopóki stages[0].gpxToken i tak zawsze było puste).
    const gpxResult = { ok: false, error: null };
    if (!multiDayStages && payload.gpxBase64) {
      try {
        const parsed = await uploadGpxAndGetToken(rootEl, payload.gpxBase64, payload.gpxFilename || 'trasa.gpx');
        if (parsed && parsed.token) {
          data.stages[0].gpxToken = parsed.token;
          data.stages[0].distanceKm = parsed.distanceKm;
          data.stages[0].elevationM = parsed.elevationGainM;
          if (parsed.surface) {
            data.stages[0].surfaceAsphaltPct = parsed.surface.asphaltPct;
            data.stages[0].surfaceGravelPct = parsed.surface.gravelPct;
            data.stages[0].surfaceTrailPct = parsed.surface.trailPct;
          }
          gpxResult.ok = true;
        } else {
          gpxResult.error = 'Serwer nie rozpoznał pliku GPX.';
        }
      } catch (e) {
        gpxResult.error = 'Nie udało się wysłać pliku GPX (' + e.message + ').';
      }
    }

    // Zdjęcie okładki — DWIE ścieżki (patrz komentarz w sidepanel.js przy
    // gatherPayload()/fillRidemore(), sekcja "Zdjęcie okładki"):
    // 1) payload.photoBase64 — prawdziwy lokalny plik, wstawiany przez
    //    <input type=file> (DataTransfer to jedyny sposób programowego
    //    ustawienia plików w przeglądarce — input.value jest zablokowany z
    //    powodów bezpieczeństwa, input.files nie).
    // 2) payload.photoUrl — link do zdjęcia, NIE pobierany przez rozszerzenie
    //    w ogóle; wstawiamy sam string do ukrytego pola
    //    cover_photo_source_url — serwer sam go ściągnie przy zapisie
    //    (Resources\EventFormInput, Utils\Upload::saveCoverPhotoFromUrl()).
    let photoOk = false;
    if (payload.photoBase64) {
      const fileInput = rootEl.querySelector('input[type="file"][name="cover_photo"]');
      photoOk = setFileInput(fileInput, payload.photoBase64, payload.photoFilename || 'zdjecie.jpg', payload.photoMime || 'image/jpeg');
    } else if (payload.photoUrl) {
      // Przez STAN Alpine (nie przez .value na inpucie) — pole jest x-model
      // owane, więc ręczne ustawienie DOM-u i tak zostałoby nadpisane przy
      // najbliższym przerysowaniu; ten sam wzorzec co reszta pól w tym pliku.
      data.coverPhotoSourceUrl = payload.photoUrl;
      photoOk = true;
    }

    data.goToStep('podsumowanie');
    window.scrollTo({ top: 0, behavior: 'smooth' });

    showBanner(rootEl, gpxResult, photoOk, variantGpxResults, stageGpxResults);
    return { ok: true, gpx: gpxResult, photoOk, variantGpx: variantGpxResults, stageGpx: stageGpxResults };
  };

  function showBanner(rootEl, gpxResult, photoOk, variantGpxResults, stageGpxResults) {
    const id = 'rmi-fill-banner';
    document.getElementById(id) && document.getElementById(id).remove();
    const div = document.createElement('div');
    div.id = id;
    div.setAttribute('style', [
      'position:fixed', 'top:14px', 'left:50%', 'transform:translateX(-50%)', 'z-index:99999',
      'background:#2E7D4F', 'color:#fff', 'padding:12px 18px', 'border-radius:10px',
      'font:14px/1.4 -apple-system,Segoe UI,Roboto,sans-serif', 'box-shadow:0 6px 24px rgba(0,0,0,.25)',
      'max-width:460px',
    ].join(';'));
    let msg = '✓ Pola uzupełnione z rozszerzenia Ridemore Importer — sprawdź wszystko przed publikacją.';
    if (gpxResult && gpxResult.error) msg += '\n⚠ GPX: ' + gpxResult.error + ' Wgraj plik ręcznie w kroku „Trasa”.';
    if (Array.isArray(variantGpxResults) && variantGpxResults.length) {
      variantGpxResults.forEach((r) => {
        msg += '\n⚠ GPX wariantu „' + (r.name || '?') + '”: ' + r.error + ' Wgraj ręcznie w kroku „Trasa”.';
      });
    }
    if (Array.isArray(stageGpxResults) && stageGpxResults.length) {
      stageGpxResults.forEach((r) => {
        msg += '\n⚠ GPX dnia „' + (r.name || '?') + '”: ' + r.error + ' Wgraj ręcznie w kroku „Plan”.';
      });
    }
    const p = document.createElement('div');
    p.style.whiteSpace = 'pre-line';
    p.textContent = msg;
    const btn = document.createElement('button');
    btn.textContent = '✕';
    btn.setAttribute('style', 'position:absolute;top:6px;right:8px;background:none;border:0;color:#fff;font-size:16px;cursor:pointer;');
    btn.onclick = () => div.remove();
    div.appendChild(p);
    div.appendChild(btn);
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 12000);
  }
})();
