// sw.js — kill-switch dla starej wersji Service Workera (dawna aplikacja
// One Page pod tym adresem). Nowa strona NIE jest PWA i celowo nie
// rejestruje żadnego Service Workera — ten plik istnieje wyłącznie po to,
// żeby przeglądarki wciąż kontrolowane przez starą wersję mogły się z niej
// wyrejestrować (tylko SW może odinstalować SW pod tym samym scope).
//
// Świadomie bez obsługi 'fetch' — żadne żądanie nie jest przechwytywane,
// wszystko leci normalnie do sieci.

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // Bez tego nowy SW nie przejmuje już otwartych kart (tylko kolejne
    // nawigacje) — client.navigate() niżej nic by nie znalazł.
    await self.clients.claim();

    const keys = await caches.keys();
    await Promise.all(keys.map((key) => caches.delete(key)));

    await self.registration.unregister();

    const clientsList = await self.clients.matchAll({ type: 'window' });
    clientsList.forEach((client) => client.navigate(client.url));
  })());
});
