/**
 * PWA & Offline Sync Manager - Mandel Papeterie
 * Gestion du mode hors-ligne avec verrouillage de licence configurable.
 */

// Gestionnaire d'installation PWA via le bouton du Header
let deferredPwaPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPwaPrompt = e;
  const btn = document.getElementById('pwaInstallBtn');
  if (btn && !window.PWA_DISABLED) {
    btn.classList.remove('btn-outline-primary');
    btn.classList.add('btn-primary', 'shadow');
  }
});

function handlePwaInstallClick() {
  if (window.PWA_DISABLED) {
    alert(window.PWA_DISABLED_MSG || "Service PWA & Mode Hors-Ligne non disponible pour l'instant. Veuillez contacter votre prestataire technique / hébergeur pour l'activation de ce service.");
    return;
  }

  if (deferredPwaPrompt) {
    deferredPwaPrompt.prompt();
    deferredPwaPrompt.userChoice.then((choiceResult) => {
      if (choiceResult.outcome === 'accepted') {
        console.log('[PWA] L\'utilisateur a accepté l\'installation.');
        showPwaToast('🎉 Application Mandel installée avec succès sur votre appareil !', 'success');
      }
      deferredPwaPrompt = null;
    });
  } else {
    alert("Pour installer l'application Mandel sur votre appareil :\n\n📱 Android / Chrome : Cliquez sur le menu du navigateur (3 petits points) puis 'Ajouter à l'écran d'accueil' ou 'Installer l'application'.\n\n🍏 iPhone / Safari : Cliquez sur le bouton Partager (carré avec flèche vers le haut) puis 'Sur l'écran d'accueil'.\n\n💻 PC / Mac : Cliquez sur l'icône d'installation dans la barre d'adresse de Chrome/Edge.");
  }
}

// Si le service PWA est verrouillé par le développeur (Client non à jour de paiement)
if (window.PWA_DISABLED) {
  console.log('[PWA Status] Service PWA & Mode Hors-Ligne actuellement désactivé.');

  // Désenregistrer tout Service Worker existant pour bloquer le cache hors-ligne
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(registrations => {
      for (let registration of registrations) {
        registration.unregister();
      }
    });
  }
} else {
  // 1. Enregistrement du Service Worker si la licence PWA_OFFLINE_ENABLED === true
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('./sw.js')
        .then(reg => console.log('[PWA] Service Worker enregistré avec succès sur la portée:', reg.scope))
        .catch(err => console.warn('[PWA] Échec de l\'enregistrement du Service Worker:', err));
    });
  }
}

// 2. Initialisation d'IndexedDB pour le stockage hors-ligne
const DB_NAME = 'MandelOfflineDB';
const DB_VERSION = 1;

function openOfflineDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains('offline_orders')) {
        db.createObjectStore('offline_orders', { keyPath: 'id', autoIncrement: true });
      }
    };
    request.onsuccess = (event) => resolve(event.target.result);
    request.onerror = (event) => reject(event.target.error);
  });
}

// Enregistrer une vente hors-ligne dans IndexedDB
async function saveOfflineOrder(orderData) {
  if (window.PWA_DISABLED) {
    alert(window.PWA_DISABLED_MSG || "Service web PWA non disponible pour l'instant. Veuillez contacter l'opérateur d'hébergement.");
    return false;
  }
  try {
    const db = await openOfflineDB();
    const tx = db.transaction('offline_orders', 'readwrite');
    const store = tx.objectStore('offline_orders');
    orderData.saved_at = new Date().toISOString();
    store.add(orderData);
    return new Promise((resolve) => {
      tx.oncomplete = () => resolve(true);
    });
  } catch (err) {
    console.error('[OfflineDB] Erreur sauvegarde hors-ligne:', err);
    return false;
  }
}

// Compter les transactions hors-ligne en attente
async function getPendingOfflineCount() {
  if (window.PWA_DISABLED) return 0;
  try {
    const db = await openOfflineDB();
    const tx = db.transaction('offline_orders', 'readonly');
    const store = tx.objectStore('offline_orders');
    return new Promise((resolve) => {
      const countReq = store.count();
      countReq.onsuccess = () => resolve(countReq.result);
      countReq.onerror = () => resolve(0);
    });
  } catch (err) {
    return 0;
  }
}

// Synchroniser les commandes hors-ligne avec le serveur central
async function syncOfflineData() {
  if (window.PWA_DISABLED || !navigator.onLine) return;

  try {
    const db = await openOfflineDB();
    const tx = db.transaction('offline_orders', 'readonly');
    const store = tx.objectStore('offline_orders');

    const getAllReq = store.getAll();
    getAllReq.onsuccess = async () => {
      const pendingOrders = getAllReq.result;
      if (!pendingOrders || pendingOrders.length === 0) return;

      console.log(`[PWA Sync] Début de la synchronisation de ${pendingOrders.length} commande(s) hors-ligne...`);

      let syncedCount = 0;
      for (const orderItem of pendingOrders) {
        try {
          const resp = await fetch('backend/api/sync_offline.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: 'ORDER', data: orderItem })
          });
          const result = await resp.json();

          if (result && result.success) {
            syncedCount++;
            const delTx = db.transaction('offline_orders', 'readwrite');
            delTx.objectStore('offline_orders').delete(orderItem.id);
          }
        } catch (err) {
          console.warn('[PWA Sync] Échec de synchro pour un item:', err);
        }
      }

      if (syncedCount > 0) {
        showPwaToast(`🎉 ${syncedCount} vente(s) enregistrée(s) hors-ligne ont été synchronisée(s) avec succès !`, 'success');
        updateOfflineBanner();
        setTimeout(() => {
          if (window.location.pathname.includes('orders.php') || window.location.pathname.includes('caisse.php')) {
            window.location.reload();
          }
        }, 2000);
      }
    };
  } catch (err) {
    console.error('[PWA Sync] Erreur globale synchro:', err);
  }
}

// 3. Gestionnaires d'événements réseau (Online / Offline)
window.addEventListener('online', () => {
  updateOfflineBanner();
  if (!window.PWA_DISABLED) {
    showPwaToast('🌐 Connexion rétablie ! Lancement de la synchronisation automatique...', 'info');
    syncOfflineData();
  }
});

window.addEventListener('offline', () => {
  updateOfflineBanner();
  if (window.PWA_DISABLED) {
    showPwaToast('⚠️ ' + (window.PWA_DISABLED_MSG || 'Service web non disponible. Veuillez contacter l\'opérateur d\'hébergement.'), 'warning');
  } else {
    showPwaToast('🔴 Vous êtes actuellement Hors-Ligne. Les nouvelles ventes seront enregistrées localement.', 'warning');
  }
});

// Mise à jour de la bannière d'état réseau en haut de l'écran
async function updateOfflineBanner() {
  let banner = document.getElementById('pwa-network-banner');
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'pwa-network-banner';
    banner.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; z-index: 9999; font-size: 0.85rem; font-weight: bold; text-align: center; padding: 6px 12px; transition: all 0.3s ease;';
    document.body.prepend(banner);
  }

  if (window.PWA_DISABLED) {
    if (!navigator.onLine) {
      banner.style.backgroundColor = '#991b1b';
      banner.style.color = '#ffffff';
      banner.innerHTML = `<i class="fa-solid fa-lock me-2"></i>${window.PWA_DISABLED_MSG || 'Service web PWA non disponible. Veuillez contacter l\'opérateur d\'hébergement.'}`;
      banner.style.display = 'block';
    } else {
      banner.style.display = 'none';
    }
    return;
  }

  const pendingCount = await getPendingOfflineCount();

  if (!navigator.onLine) {
    banner.style.backgroundColor = '#dc2626';
    banner.style.color = '#ffffff';
    banner.innerHTML = `<i class="fa-solid fa-wifi-slash me-2"></i>MODE HORS-LIGNE ACTIVÉ — Vos ventes sont enregistrées localement. ${pendingCount > 0 ? `(${pendingCount} vente(s) en attente de synchro)` : ''}`;
    banner.style.display = 'block';
  } else if (pendingCount > 0) {
    banner.style.backgroundColor = '#f59e0b';
    banner.style.color = '#000000';
    banner.innerHTML = `<i class="fa-solid fa-arrows-rotate fa-spin me-2"></i>${pendingCount} vente(s) hors-ligne en attente de synchronisation. <button onclick="syncOfflineData()" class="btn btn-sm btn-dark py-0 px-2 ms-2">Synchroniser maintenant</button>`;
    banner.style.display = 'block';
  } else {
    banner.style.display = 'none';
  }
}

// Affichage de messages Toasts PWA
function showPwaToast(msg, type = 'info') {
  const toast = document.createElement('div');
  const bgClass = type === 'success' ? 'bg-success text-white' : (type === 'warning' ? 'bg-warning text-dark' : 'bg-primary text-white');
  toast.className = `alert ${bgClass} border-0 shadow-lg position-fixed bottom-0 end-0 m-3 p-3`;
  toast.style.cssText = 'z-index: 10000; border-radius: 12px; max-width: 380px;';
  toast.innerHTML = `<div class="d-flex align-items-center justify-content-between"><div>${msg}</div><button type="button" class="btn-close ms-2" onclick="this.parentElement.parentElement.remove()"></button></div>`;
  document.body.appendChild(toast);
  setTimeout(() => { if (toast) toast.remove(); }, 5000);
}

// Initialisation au chargement du DOM
document.addEventListener('DOMContentLoaded', () => {
  updateOfflineBanner();
  if (navigator.onLine && !window.PWA_DISABLED) {
    syncOfflineData();
  }
});
