// scripts/dom-features.js
// Wstrzykiwane (chrome.scripting.executeScript, world: ISOLATED) po kliknięciu
// "🧠 AI-Engine". To jest "Pierwszy etap" z dokumentu projektowego
// silnika: zbiera PEŁNY materiał wejściowy, ale NICZEGO nie interpretuje
// (żadnego "to jest cena/data/tytuł") — ten etap widzi tylko cechy.
//
// W odróżnieniu od usuniętego ai-extract.js (tylko meta/JSON-LD/próbka
// tekstu) budujemy tu "Model elementu strony": dla każdego znaczącego węzła —
// tekst, pozycja/rozmiar (już WYRENDEROWANE przez przeglądarkę użytkownika,
// stąd zero potrzeby Playwrighta po stronie serwera), wybrane cechy stylu i
// rodzic — dokładnie ta struktura z sekcji 6 dokumentu. Kolejność
// (ranking/relacje przestrzenne "nad/pod") liczy dopiero silnik Pythona z
// współrzędnych rect, nie tutaj.
(function gatherEngineContext() {
  // Obniżone z 400 po realnym HTTP 413 z Groq ("Request too large... TPM
  // limit 12000") na prawdziwej stronie eventu — darmowy/on_demand tier ma
  // twardy limit tokenów na minutę, nie tylko na zapytanie. Razem z dedupem
  // (patrz seenElementKeys niżej) i krótszym tekstem/JSON-LD to daje spory
  // margines nawet dla mniejszego modelu (patrz DEFAULT_MODEL w
  // providers/groq_provider.py po stronie Pythona).
  const MAX_ELEMENTS = 200;
  const MAX_TEXT_LEN = 180;
  const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();
  const abs = (url) => { try { return new URL(url, document.baseURI).href; } catch (e) { return null; } };

  // --- Sygnały tanie i bogate w informację (jak w usuniętym ai-extract.js) —
  // Groq dostaje pełniejszy model elementów niżej, ale te pola bywają jedynym
  // miejscem z "surową prawdą" (np. og:title na stronach zbudowanych w SPA).
  const meta = {};
  document.querySelectorAll('meta[property], meta[name]').forEach((m) => {
    const key = m.getAttribute('property') || m.getAttribute('name');
    if (!key) return;
    // `event:` (Open Graph wydarzeń — np. event:start_time/end_time, używane
    // m.in. przez strony wydarzeń) dołączone 2026-09-17: to często JEDYNE
    // maszynowo-czytelne źródło daty, gdy widoczny tekst jest porozbijany na
    // osobne <span>-y (typowe dla FB) i model nie potrafi go skleić.
    if (/^(og:|twitter:|article:|event:)/i.test(key) || /^(description|author)$/i.test(key)) {
      const val = clean(m.getAttribute('content'));
      if (val) meta[key] = val;
    }
  });
  // JSON-LD bywa OGROMNY (całe katalogi produktów/eventów w jednym blogu) —
  // to jeden z głównych winowajców HTTP 413 opisanego przy MAX_ELEMENTS
  // wyżej. Twardy budżet łączny (nie tylko per-blok) + max 3 pierwsze bloki.
  const jsonLd = [];
  let jsonLdBudget = 3000;
  document.querySelectorAll('script[type="application/ld+json"]').forEach((s) => {
    if (jsonLd.length >= 3 || jsonLdBudget <= 0) return;
    const txt = (s.textContent || '').trim().slice(0, jsonLdBudget);
    if (txt) { jsonLd.push(txt); jsonLdBudget -= txt.length; }
  });

  // --- Kandydaci linki/obrazy, numerowani — Groq (po stronie Pythona) ma
  // wskazywać WYŁĄCZNIE numer "index" z tych tablic, nigdy wpisywać URL-a od
  // siebie — zabezpieczenie przed zmyślonym adresem (ten sam wzorzec, co przy
  // usuniętym trybie Claude).
  const linkCandidates = [];
  const seenLinks = new Set();
  document.querySelectorAll('a[href]').forEach((a) => {
    if (linkCandidates.length >= 60) return;
    const href = abs(a.getAttribute('href'));
    if (!href || !/^https?:/i.test(href)) return;
    const text = clean(a.textContent).slice(0, 120);
    const looksLikeFile = /\.(gpx|pdf)(\?|$)/i.test(href);
    if (!text && !looksLikeFile) return; // pomiń linki bez tekstu i bez sensownego rozszerzenia
    const key = href + '|' + text;
    if (seenLinks.has(key)) return;
    seenLinks.add(key);
    linkCandidates.push({ index: linkCandidates.length, text, href });
  });
  const imageCandidates = [];
  document.querySelectorAll('img').forEach((img) => {
    if (imageCandidates.length >= 20) return;
    const w = img.naturalWidth || parseInt(img.getAttribute('width') || '0', 10);
    const h = img.naturalHeight || parseInt(img.getAttribute('height') || '0', 10);
    if (w < 200 || h < 150) return; // pomija ikonki/logotypy
    const src = abs(img.currentSrc || img.src);
    if (!src) return;
    imageCandidates.push({ index: imageCandidates.length, alt: clean(img.alt).slice(0, 150), src, width: w, height: h });
  });

  // --- Model elementów strony: tekst + pozycja/rozmiar + styl + rodzic.
  // Ograniczone do elementów Z WŁASNYM tekstem (nie czystych kontenerów) albo
  // <a>/<img> — inaczej lista eksploduje do tysięcy węzłów-wrapperów bez
  // żadnej informacji. querySelectorAll('*') zwraca kolejność dokumentu
  // (rodzic zawsze przed dzieckiem), więc idOf ma już rodzica w chwili
  // przetwarzania dziecka.
  const idOf = new Map();
  let nextId = 1;
  function ancestorIdOf(el) {
    let p = el.parentElement;
    while (p) {
      if (idOf.has(p)) return idOf.get(p);
      p = p.parentElement;
    }
    return null;
  }

  const elements = [];
  // Deduplikacja (tag+tekst) — strony pełne są powtórzeń (menu mobile+desktop
  // w DOM naraz, powtórzone karty produktów/wydarzeń, stopki) i identyczny
  // tekst nie niesie dla modelu żadnej DODATKOWEJ informacji przy drugim i
  // kolejnym wystąpieniu. Bezpieczne dla precyzji linków/zdjęć: te rozwiązują
  // się przez OSOBNE, niededuplikowane tablice links/images (indeksy), nie
  // przez ten model elementów.
  const seenElementKeys = new Set();
  const allNodes = document.body ? document.body.querySelectorAll('*') : [];
  for (const el of allNodes) {
    if (elements.length >= MAX_ELEMENTS) break;
    const tag = el.tagName.toLowerCase();
    if (['script', 'style', 'noscript', 'svg', 'path', 'template', 'br'].includes(tag)) continue;

    const ownText = clean(Array.from(el.childNodes)
      .filter((n) => n.nodeType === Node.TEXT_NODE)
      .map((n) => n.textContent)
      .join(' '));
    const isMedia = tag === 'img' || tag === 'a';
    // Maszynowy znacznik czasu: <time datetime="2026-09-20T09:00">, ale też
    // dowolny element z atrybutem datetime. To najpewniejsze źródło daty, gdy
    // widoczny tekst jest porozbijany na osobne <span>-y (typowe dla FB) —
    // dołączamy go do tekstu jako "@ISO", żeby model dostał datę wprost.
    // Dzięki temu <time datetime> BEZ własnego tekstu też trafia do modelu.
    const dt = (el.getAttribute && clean(el.getAttribute('datetime'))) || '';
    if (!ownText && !isMedia && !dt) continue;

    const rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) continue; // niewidoczne/zwinięte
    const style = getComputedStyle(el);
    if (style.visibility === 'hidden' || style.display === 'none' || parseFloat(style.opacity || '1') === 0) continue;

    let text = (isMedia ? clean(el.alt || el.textContent) : ownText);
    if (dt) text = (text ? text + ' ' : '') + '@' + dt; // doklej maszynowy znacznik czasu (ISO)
    text = text.slice(0, MAX_TEXT_LEN);
    const dedupeKey = tag + '|' + text;
    if (text && seenElementKeys.has(dedupeKey)) continue;
    if (text) seenElementKeys.add(dedupeKey);

    const id = nextId++;
    idOf.set(el, id);
    elements.push({
      id,
      tag,
      text,
      // y absolutne (+ scrollY) — porównywalne między elementami niezależnie
      // od aktualnego przewinięcia w momencie skanu.
      rect: {
        x: Math.round(rect.x),
        y: Math.round(rect.y + window.scrollY),
        w: Math.round(rect.width),
        h: Math.round(rect.height),
      },
      style: {
        fontSize: parseFloat(style.fontSize) || null,
        fontWeight: style.fontWeight,
        color: style.color,
      },
      parentId: ancestorIdOf(el),
    });
  }

  return {
    sourceUrl: location.href,
    pageTitle: document.title,
    meta,
    jsonLd,
    elements,
    links: linkCandidates,
    images: imageCandidates,
  };
})();
