// background.js — service worker. Celowo minimalny: cała logika (skanowanie,
// tryb "wskaż na stronie", wypełnianie kreatora) siedzi w sidepanel.js, który
// ma bezpośredni dostęp do chrome.scripting/chrome.tabs i nie potrzebuje
// pośrednika. Tło tylko włącza otwieranie panelu kliknięciem ikony — bez
// tego kliknięcie ikony w pasku nic by nie robiło (domyślne zachowanie MV3).
chrome.runtime.onInstalled.addListener(() => {
  chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
});
