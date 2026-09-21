// scripts/link-harvest.js
// Wstrzykiwany przez sidepanel.js (chrome.scripting.executeScript files:[...]),
// tryb „🔗 Zbierz linki". Zbiera z AKTUALNIE WYRENDEROWANEJ strony linki do
// POJEDYNCZYCH wydarzeń — działa więc na stronach renderowanych JavaScriptem
// (SPA: brevety.pl, kalendarze na JS, feedy), których serwerowy harvester
// (core/Utils/EventSourceFetcher) fizycznie nie odczyta (dostaje samą skorupę).
//
// Heurystyka LUSTRZANA do EventSourceFetcher::extractEventLinks (ta sama zasada
// zachowawcza: same-host + sygnał wydarzenia w URL/tekście, albo zaufana
// platforma; odsiew nawigacji/social/plików; dedup po ukośniku). Zwraca ostatnie
// wyrażenie jako wynik executeScript (jak scripts/group-feed.js). NIE wymaga
// niczego z zewnątrz — musi być samowystarczalny (osobny świat wykonania).
(() => {
  const EVENT_HINTS = /wydarzeni|\/event|\/e\/|wyscig|wyścig|zawody|\brajd|maraton|impreza|brevet|\/p\/|edycja|\/20\d{2}[\/-]|\d{4}-\d{2}-\d{2}/i;
  const NON_EVENT = /facebook|instagram|youtube|twitter|tiktok|linkedin|dodaj-wydarzenie|dodaj-event|add-event|\/login|logowanie|rejestracja-konta|regulamin|polityk|prywatn|cookie|kontakt|\/o-nas|\/about|\/page\/|[?&]page=|\/tag\/|\/kategoria\/|\.(jpg|jpeg|png|gif|webp|pdf|gpx|zip|docx?)($|\?)/i;
  const TRUSTED = ['eventbrite.com', 'eventbrite.pl', 'evenea.pl', 'zmierzymyczas.pl', 'dostartu.pl'];

  const baseHost = location.hostname.replace(/^www\./, '');
  const baseNorm = (location.origin + location.pathname).replace(/\/$/, '');
  const seen = new Set();
  const out = [];

  for (const a of document.querySelectorAll('a[href]')) {
    const href = a.href; // DOM rozwiązuje adres do absolutnego
    if (!href || !/^https?:/i.test(href) || NON_EVENT.test(href)) continue;
    const norm = href.replace(/#.*$/, '').replace(/\/$/, '');
    if (norm === baseNorm || seen.has(norm)) continue;
    let host;
    try { host = new URL(href).hostname.replace(/^www\./, ''); } catch (e) { continue; }
    const text = (a.textContent || '').replace(/\s+/g, ' ').trim();
    const sameHost = host === baseHost;
    const trusted = TRUSTED.includes(host);
    const hasHint = EVENT_HINTS.test(href + ' ' + text);
    if ((sameHost && hasHint) || trusted) {
      seen.add(norm);
      out.push({ url: href, text: text.slice(0, 120) });
      if (out.length >= 100) break;
    }
  }

  return { sourceUrl: location.href, pageTitle: document.title, links: out };
})();
