const CACHE_NAME = 'mandel-pwa-v1';
const ASSETS_TO_CACHE = [
  './',
  './index.php',
  './caisse.php',
  './order-create.php',
  './orders.php',
  './products.php',
  './product-history.php',
  './stock-transfers.php',
  './stock-exit.php',
  './stats-stock.php',
  './depot.php',
  './assets/css/style.css',
  './assets/js/pwa-offline-sync.js',
  './manifest.json',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
];

// Installation : Mise en cache initiale des fichiers essentiels
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[Service Worker] Mise en cache des ressources PWA...');
      return cache.addAll(ASSETS_TO_CACHE).catch(err => {
        console.warn('[Service Worker] Erreur partielle lors de la mise en cache initiale:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

// Activation : Nettoyage des anciens caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cache) => {
          if (cache !== CACHE_NAME) {
            console.log('[Service Worker] Suppression de l\'ancien cache :', cache);
            return caches.delete(cache);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Interception des requêtes réseau (Stratégie Network-First avec Fallback Cache)
self.addEventListener('fetch', (event) => {
  // Ignorer les requêtes non-GET et les requêtes d'API POST (traitées séparément par IndexedDB)
  if (event.request.method !== 'GET') return;

  event.respondWith(
    fetch(event.request)
      .then((networkResponse) => {
        // Si la requête réseau réussit, mettre à jour le cache
        if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
        }
        return networkResponse;
      })
      .catch(() => {
        // Si pas de connexion réseau (Hors-Ligne), servir depuis le cache local
        console.log('[Service Worker] Mode Hors-Ligne : Servir la ressource depuis le cache pour :', event.request.url);
        return caches.match(event.request, { ignoreSearch: true }).then((cachedResponse) => {
          if (cachedResponse) {
            return cachedResponse;
          }
          // Si la page spécifique n'est pas en cache, renvoyer vers index.php ou order-create.php
          if (event.request.headers.get('accept') && event.request.headers.get('accept').includes('text/html')) {
            return caches.match('./order-create.php', { ignoreSearch: true }).then(r => r || caches.match('./index.php'));
          }
        });
      })
  );
});
