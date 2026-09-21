// scripts/group-feed.js
// Tryb "Skanuj grupę FB" — wstrzykiwane (chrome.scripting.executeScript,
// world: ISOLATED) po kliknięciu "👥 Skanuj grupę FB" w panelu. Zbiera posty
// z feedu grupy na Facebooku — WYŁĄCZNIE to, co użytkownik widzi na
// załadowanej stronie (żadnego pobierania poza widok, żadnego API/scrapingu
// masowego) — dokładnie tak, jak zrobiłby to ręcznie: autor, treść, link.
// Interpretację zostawia silnikowi AI (ai-engine, mode='group'): tu tylko
// zebranie surowych postów, bez żadnej semantyki.
//
// Dwie strategie, bo DOM FB bywa różny między kontami/regionami/wersjami:
//   A) [role="article"] — klasyczny znacznik karty posta w feedzie.
//   B) linki profili (autorów) -> najbliższy przodek z blokiem tekstu
//      div[dir="auto"] — fallback, gdy FB nie używa role="article" albo
//      używa inaczej (sprawdzone na żywo 2026-08-20: strona grupy potrafi
//      mieć ZERO role="article" przy normalnie wyglądającym feedzie).
// Gdy obie strategie nic nie znajdą, panel proponuje ręczne wskazywanie
// (🖱️ "Wskaż posty" w panelu — ta sama idea co 🎯 przy polach).
(function gatherGroupFeed() {
  const MAX_POSTS = 25;
  const MAX_TEXT = 1500;
  // Najkrótszy sensowny post "szukam kogoś" ("szukam kogoś na jutro" = 20
  // znaków) — filtr odcina etykiety/reakcje dla strategii B.
  const MIN_TEXT = 15;
  const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();
  const abs = (url) => { try { return new URL(url, document.baseURI).href; } catch (e) { return null; } };

  const looksProfile = (href) => /(?:^|\/)(?:user|profile)\/(\d+)(?:\/|$)/i.test(href) || /[?&]id=\d+/.test(href);

  // Wspólne wyciąganie jednego posta z kontenera (karta/artykuł): autor =
  // pierwszy link profilowy z tekstem/aria-label, treść = najdłuższy blok
  // div[dir="auto"] SPOZA linków i przycisków (autorzy siedzą w linkach,
  // reakcje w buttonach; najdłuższy wygrywa, bo komentarze bywają dłuższe
  // od krótkich postów — ostateczną ocenę robi i tak AI).
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
      if (!postHref && /(?:^|\/)(?:posts|permalink)\//i.test(href)) postHref = href;
      if (authorHref && authorName && postHref) break;
    }
    let text = '';
    for (const b of container.querySelectorAll('div[dir="auto"]')) {
      if (b.closest('a') || b.closest('[role="button"]')) continue;
      const t = clean(b.textContent);
      if (t.length > text.length) text = t;
    }
    text = text.slice(0, MAX_TEXT);
    return { authorHref, authorName, postHref, text };
  }

  // Najbliższy przodek (albo sam element) mający blok tekstu div[dir="auto"]
  // — dla strategii B (od linku autora w górę) i ręcznego wskazywania.
  // Guard rozmiaru (<= 90% wysokości ekranu): wspinaczka NIE może wyjść na
  // całą stronę — losowy klik w tło inaczej zebrałby "całą stronę" jako post.
  function nearestTextContainer(startEl) {
    let p = startEl;
    let depth = 0;
    while (p && p !== document.body && p !== document.documentElement && depth < 7) {
      const text = postTextOf(p);
      if (text && text.length >= MIN_TEXT) {
        const r = p.getBoundingClientRect();
        if (r.height <= window.innerHeight * 0.9) return p;
      }
      p = p.parentElement;
      depth++;
    }
    return null;
  }
  function postTextOf(container) {
    let text = '';
    for (const b of container.querySelectorAll('div[dir="auto"]')) {
      if (b.closest('a') || b.closest('[role="button"]')) continue;
      const t = clean(b.textContent);
      if (t.length > text.length) text = t;
    }
    return text.slice(0, MAX_TEXT);
  }

  const seenKeys = new Set();
  const posts = [];
  function pushPost(p) {
    if (!p || !p.text) return;
    if (posts.length >= MAX_POSTS) return;
    const key = (p.authorHref || '') + '|' + p.text.slice(0, 200);
    if (seenKeys.has(key)) return;
    seenKeys.add(key);
    posts.push({ index: posts.length, ...p });
  }

  // --- Strategia A: role="article" (klasyczny feed FB) ---
  const articles = Array.from(document.querySelectorAll('[role="article"]'))
    .filter((el) => {
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    });
  articles.forEach((art) => pushPost(extractPost(art)));

  // --- Strategia B (fallback, gdy A nic nie dało): linki profili -> przodek
  // z tekstem. Nie działa na paskach bocznych, bo tam nie ma bloków
  // div[dir="auto"] z treścią — filtr MIN_TEXT to pilnuje. ---
  let strategy = 'articles';
  if (!posts.length) {
    strategy = 'profile-links';
    const anchors = Array.from(document.querySelectorAll('body a[href]'));
    for (const a of anchors) {
      if (posts.length >= MAX_POSTS) break;
      const href = abs(a.getAttribute('href'));
      if (!href || !looksProfile(href)) continue;
      if (!clean(a.getAttribute('aria-label') || a.textContent)) continue;
      const container = nearestTextContainer(a);
      if (!container) continue;
      pushPost(extractPost(container));
    }
  }

  const ogTitle = document.querySelector('meta[property="og:title"]');
  const h1 = document.querySelector('h1');
  const groupName = clean(ogTitle ? ogTitle.getAttribute('content') : (h1 ? h1.textContent : ''));

  return {
    sourceUrl: location.href,
    pageTitle: document.title,
    groupName: groupName || null,
    posts,
    // Diagnostyka — panel pokaże ją w komunikacie błędu, żeby było widać,
    // CO strona faktycznie ma (role=article? linki profili? bloki tekstu?),
    // zamiast zgadywać "co jest nie tak".
    debugInfo: {
      strategy,
      articles: articles.length,
      profileLinks: Array.from(document.querySelectorAll('body a[href]')).filter((a) => looksProfile(abs(a.getAttribute('href') || ''))).length,
      textBlocks: document.querySelectorAll('div[dir="auto"]').length,
    },
  };
})();