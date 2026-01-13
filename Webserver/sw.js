// Minimaler Service Worker, damit die Registrierung nicht 404t.
self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', () => {
  // bewusst kein Offline-Caching
});
