const CACHE_NAME = 'mandel-pwa-v3';

const ASSETS_TO_CACHE = [
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
  './assets/js/export-excel.js',
  './assets/images/logo.png',
  './assets/images/favicon.png',
  './assets/images/icon-192.png',
  './assets/images/icon-512.png',
  './manifest.json',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
];

// Installation : Mise en cache résiliente (un échec individuel ne bloque pas les autres)
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      console.log('[Service Worker] Mise en cache résiliente des ressources Mandel...');
      await Promise.allSettled(
        ASSETS_TO_CACHE.map(async (url) => {
          try {
            const response = await fetch(url, { cache: 'reload' });
            if (response && (response.ok || response.type === 'opaque')) {
              await cache.put(url, response);
            }
          } catch (err) {
            console.warn('[Service Worker] Ressource non mise en cache immédiate :', url, err);
          }
        })
      );
      console.log('[Service Worker] Installation terminée avec succès.');
    })
  );
});

// Activation : Nettoyage immédiat des anciens caches et prise de contrôle
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cache) => {
          if (cache !== CACHE_NAME) {
            console.log('[Service Worker] Nettoyage ancien cache :', cache);
            return caches.delete(cache);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Interception des requêtes réseau : Stratégie Network-First avec Fallback Cache robuste
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  if (!event.request.url.startsWith('http')) return;

  event.respondWith(
    fetch(event.request)
      .then((networkResponse) => {
        // Si la requête réseau réussit, mettre à jour le cache (même pour CORS CDN)
        if (networkResponse && networkResponse.status === 200) {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
        }
        return networkResponse;
      })
      .catch(async () => {
        // En cas de coupure de réseau (Hors-Ligne)
        console.log('[Service Worker] Réseau indisponible. Recherche dans le cache pour :', event.request.url);
        
        // 1. Recherche directe dans le cache (avec ou sans paramètres de recherche)
        const cachedResponse = await caches.match(event.request, { ignoreSearch: true });
        if (cachedResponse) {
          return cachedResponse;
        }

        // 2. Si c'est une requête de page HTML de navigation
        const isHtml = event.request.mode === 'navigate' || 
                       (event.request.headers.get('accept') && event.request.headers.get('accept').includes('text/html'));
        if (isHtml) {
          // Servir la page de vente ou l'accueil en cache
          const orderCreateFallback = await caches.match('./order-create.php', { ignoreSearch: true });
          if (orderCreateFallback) return orderCreateFallback;

          const indexFallback = await caches.match('./index.php', { ignoreSearch: true });
          if (indexFallback) return indexFallback;

          // Page de secours hors-ligne Mandel stylisée
          return new Response(
            `<!DOCTYPE html>
            <html lang="fr">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1.0">
              <title>Mandel - Mode Hors-Ligne</title>
              <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; text-align: center; }
                .card { background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px 24px; max-width: 440px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); }
                .badge { display: inline-block; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 9999px; padding: 6px 14px; font-weight: 700; font-size: 0.85rem; margin-bottom: 16px; }
                h2 { margin: 0 0 12px; font-size: 1.35rem; color: #0f172a; }
                p { font-size: 0.95rem; line-height: 1.5; color: #64748b; margin-bottom: 24px; }
                .btn { display: inline-block; background: #059669; color: white; text-decoration: none; font-weight: 600; padding: 12px 24px; border-radius: 10px; transition: 0.2s ease; }
                .btn:hover { background: #047857; }
              </style>
            </head>
            <body>
              <div class="card">
                <span class="badge">🔴 Mode Hors-Ligne Mandel</span>
                <h2>Connexion Internet Indisponible</h2>
                <p>Vous n'êtes plus connecté à Internet. L'application Mandel fonctionne en mode autonome pour continuer vos opérations de caisse et ventes.</p>
                <a href="./order-create.php" class="btn">Créer une Vente Hors-Ligne</a>
                <div style="margin-top: 16px;">
                  <a href="./index.php" style="color: #64748b; text-decoration: underline; font-size: 0.85rem;">Retour à l'accueil</a>
                </div>
              </div>
            </body>
            </html>`,
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        }

        // Pour les autres requêtes introuvables
        return new Response('Ressource hors-ligne non disponible', { status: 503, statusText: 'Offline' });
      })
  );
});
