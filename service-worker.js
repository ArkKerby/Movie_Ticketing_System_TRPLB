// Ticketix Service Worker - PWA Offline Support
const CACHE_NAME = 'ticketix-cache-v1';

// Files to cache for offline use
const PRECACHE_URLS = [
  '/ticketix/TICKETIX%20NI%20CLAIRE.php',
  '/ticketix/css/style.css',
  '/ticketix/css/ticketix-main.css',
  '/ticketix/images/brand%20x.png',
  '/ticketix/icons/favicon-96x96.png',
  '/ticketix/icons/web-app-manifest-192x192.png',
  '/ticketix/icons/web-app-manifest-512x512.png',
  '/ticketix/icons/apple-touch-icon.png',
  '/ticketix/mainbg/Main.png'
];

// Install event — cache essential assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

// Activate event — clean up old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames
          .filter(name => name !== CACHE_NAME)
          .map(name => caches.delete(name))
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event — network first, fall back to cache
self.addEventListener('fetch', event => {
  // Skip non-GET requests
  if (event.request.method !== 'GET') return;

  // Skip cross-origin requests
  if (!event.request.url.startsWith(self.location.origin)) return;

  event.respondWith(
    fetch(event.request)
      .then(response => {
        // Clone the response and cache it
        if (response.status === 200) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then(cache => {
            cache.put(event.request, responseClone);
          });
        }
        return response;
      })
      .catch(() => {
        // Network failed — try cache
        return caches.match(event.request);
      })
  );
});
