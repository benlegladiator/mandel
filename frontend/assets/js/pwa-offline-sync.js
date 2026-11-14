/**
 * PWA & Offline Sync Manager - Mandel Papeterie ERP
 * Gestion complète du mode hors-ligne, cash local, bascule en-ligne/hors-ligne et synchronisation manuelle.
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
    alert(window.PWA_DISABLED_MSG || "Service PWA & Mode Hors-Ligne non disponible pour l'instant.");
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

// Enregistrement du Service Worker
if (window.PWA_DISABLED) {
  console.log('[PWA Status] Service PWA & Mode Hors-Ligne désactivé.');
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(registrations => {
      for (let registration of registrations) {
        registration.unregister();
      }
    });
  }
} else {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('./sw.js')
        .then(reg => console.log('[PWA] Service Worker actif, scope:', reg.scope))
        .catch(err => console.warn('[PWA] Échec Service Worker:', err));
    });
  }
}

// -------------------------------------------------------------
// GESTION DU MODE HORS-LIGNE FORCÉ (MANUEL)
// -------------------------------------------------------------
function isForcedOffline() {
  return localStorage.getItem('mandel_force_offline') === 'true';
}

function isAppOffline() {
  return !navigator.onLine || isForcedOffline();
}

function handleForceOfflineToggle(enabled) {
  localStorage.setItem('mandel_force_offline', enabled ? 'true' : 'false');
  updateNetworkIndicators();
  renderOfflineModalData();

  if (enabled) {
    showPwaToast('🟠 Mode Hors-Ligne FORCÉ activé. Vos ventes seront enregistrées en mémoire locale.', 'warning');
  } else {
    showPwaToast('🟢 Mode En Ligne réactivé. Connexion au serveur central rétablie.', 'success');
    if (navigator.onLine) {
      syncOfflineData();
    }
  }
}

// -------------------------------------------------------------
// GESTION D'INDEXEDDB (STOCKAGE LOCAL HORS-LIGNE)
// -------------------------------------------------------------
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
    alert(window.PWA_DISABLED_MSG || "Service PWA non disponible pour l'instant.");
    return false;
  }
  try {
    const db = await openOfflineDB();
    const tx = db.transaction('offline_orders', 'readwrite');
    const store = tx.objectStore('offline_orders');
    orderData.saved_at = new Date().toISOString();
    store.add(orderData);
    return new Promise((resolve) => {
      tx.oncomplete = () => {
        updateNetworkIndicators();
        resolve(true);
      };
    });
  } catch (err) {
    console.error('[OfflineDB] Erreur sauvegarde hors-ligne:', err);
    return false;
  }
}

// Récupérer toutes les ventes hors-ligne
function getOfflineOrders() {
  return new Promise(async (resolve) => {
    try {
      const db = await openOfflineDB();
      const tx = db.transaction('offline_orders', 'readonly');
      const store = tx.objectStore('offline_orders');
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => resolve([]);
    } catch (e) {
      resolve([]);
    }
  });
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

// Supprimer une vente hors-ligne par ID
async function deleteOfflineOrder(id) {
  if (!confirm("Voulez-vous vraiment supprimer définitivement cette vente hors-ligne de la mémoire locale ?")) return;
  try {
    const db = await openOfflineDB();
    const tx = db.transaction('offline_orders', 'readwrite');
    tx.objectStore('offline_orders').delete(id);
    tx.oncomplete = () => {
      showPwaToast("Vente locale supprimée avec succès.", 'info');
      updateNetworkIndicators();
      renderOfflineModalData();
    };
  } catch (e) {
    console.error("Erreur suppression vente locale:", e);
  }
}

// -------------------------------------------------------------
// SYNCHRONISATION MANUELLE ET AUTOMATIQUE
// -------------------------------------------------------------
async function triggerManualSync() {
  const btn = document.getElementById('manualSyncBtn');
  const icon = document.getElementById('manualSyncIcon');

  if (!navigator.onLine) {
    alert("Impossible de synchroniser : Votre appareil est actuellement hors connexion Internet.");
    return;
  }

  if (isForcedOffline()) {
    if (confirm("Le mode hors-ligne forcé est actif. Souhaitez-vous le désactiver pour lancer la synchronisation maintenant ?")) {
      handleForceOfflineToggle(false);
    } else {
      return;
    }
  }

  if (btn) btn.disabled = true;
  if (icon) icon.classList.add('fa-spin');

  await syncOfflineData(true);

  if (btn) btn.disabled = false;
  if (icon) icon.classList.remove('fa-spin');
  renderOfflineModalData();
}

async function syncOfflineData(isManual = false) {
  if (window.PWA_DISABLED) return;
  if (!navigator.onLine || isForcedOffline()) {
    if (isManual) alert("Connexion Internet requise pour synchroniser avec le serveur.");
    return;
  }

  try {
    const db = await openOfflineDB();
    const tx = db.transaction('offline_orders', 'readonly');
    const store = tx.objectStore('offline_orders');

    const getAllReq = store.getAll();
    getAllReq.onsuccess = async () => {
      const pendingOrders = getAllReq.result || [];
      if (pendingOrders.length === 0) {
        if (isManual) {
          showPwaToast("✅ Aucune vente en attente : tout est déjà synchronisé avec le serveur !", 'success');
        }
        updateNetworkIndicators();
        renderOfflineModalData();
        return;
      }

      console.log(`[PWA Sync] Début synchronisation de ${pendingOrders.length} vente(s)...`);

      let syncedCount = 0;
      let errorCount = 0;

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
          } else {
            errorCount++;
            console.warn('[PWA Sync] Rejet item:', result);
          }
        } catch (err) {
          errorCount++;
          console.warn('[PWA Sync] Échec requête:', err);
        }
      }

      if (syncedCount > 0) {
        showPwaToast(`🎉 ${syncedCount} vente(s) hors-ligne synchronisée(s) avec succès !`, 'success');
        localStorage.setItem('mandel_last_sync', new Date().toISOString());
      }
      if (errorCount > 0) {
        showPwaToast(`⚠️ ${errorCount} vente(s) n'ont pas pu être synchronisées. Veuillez réessayer.`, 'warning');
      }

      updateNetworkIndicators();
      renderOfflineModalData();

      if (syncedCount > 0) {
        setTimeout(() => {
          if (window.location.pathname.includes('orders.php') || window.location.pathname.includes('caisse.php')) {
            window.location.reload();
          }
        }, 2200);
      }
    };
  } catch (err) {
    console.error('[PWA Sync] Erreur synchro:', err);
  }
}

// -------------------------------------------------------------
// RENDU DU MODAL CENTRE HORS-LIGNE & CASH
// -------------------------------------------------------------
function formatMoney(n) {
  return Math.round(n || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

async function renderOfflineModalData() {
  const orders = await getOfflineOrders();

  let totalCash = 0;
  let totalSales = 0;

  orders.forEach(o => {
    const subtotal = (o.items || []).reduce((acc, it) => acc + (parseFloat(it.price) * parseInt(it.quantity)), 0);
    const tot = Math.max(0, subtotal - (parseFloat(o.discount) || 0));
    totalSales += tot;
    if (o.payment_method === 'CASH') {
      totalCash += tot;
    }
  });

  const cashEl = document.getElementById('offlineCashTotal');
  const salesEl = document.getElementById('offlineSalesTotal');
  const countEl = document.getElementById('offlineCount');
  const emptyEl = document.getElementById('offlineEmptyState');
  const tableBody = document.getElementById('offlineOrdersTableBody');
  const toggleEl = document.getElementById('forceOfflineToggle');
  const statusBadgeEl = document.getElementById('modalNetworkStatusBadge');
  const syncTimeEl = document.getElementById('modalSyncTimestamp');

  if (cashEl) cashEl.textContent = formatMoney(totalCash) + ' FCFA';
  if (salesEl) salesEl.textContent = formatMoney(totalSales) + ' FCFA';
  if (countEl) countEl.textContent = orders.length;

  if (toggleEl) {
    toggleEl.checked = isForcedOffline();
  }

  if (statusBadgeEl) {
    if (isForcedOffline()) {
      statusBadgeEl.className = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1.5 fw-bold';
      statusBadgeEl.innerHTML = '<i class="fa-solid fa-hand me-1"></i>Mode Forcé Hors-Ligne';
    } else if (!navigator.onLine) {
      statusBadgeEl.className = 'badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 fw-bold';
      statusBadgeEl.innerHTML = '<i class="fa-solid fa-wifi-slash me-1"></i>Déconnecté d\'Internet';
    } else {
      statusBadgeEl.className = 'badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 fw-bold';
      statusBadgeEl.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i>Connecté à Internet';
    }
  }

  const lastSync = localStorage.getItem('mandel_last_sync');
  if (syncTimeEl) {
    syncTimeEl.textContent = lastSync ? ('Dernière synchro : ' + new Date(lastSync).toLocaleTimeString()) : '';
  }

  if (tableBody) {
    tableBody.innerHTML = '';
    if (orders.length === 0) {
      if (emptyEl) emptyEl.classList.remove('d-none');
    } else {
      if (emptyEl) emptyEl.classList.add('d-none');
      orders.forEach(ord => {
        const tr = document.createElement('tr');
        const dt = ord.saved_at ? new Date(ord.saved_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : 'Récemment';
        const itemsDesc = (ord.items || []).map(it => `${it.product_name || 'Article'} (x${it.quantity} @ ${formatMoney(it.price)} F)`).join(', ');
        const subtotal = (ord.items || []).reduce((acc, it) => acc + (parseFloat(it.price) * parseInt(it.quantity)), 0);
        const totalAmt = Math.max(0, subtotal - (parseFloat(ord.discount) || 0));
        const pmLabels = { 'CASH': 'Espèces', 'MOBILE_MONEY': 'MOMO', 'CREDIT': 'Crédit', 'CARD': 'Carte', 'VIREMENT': 'Virement' };
        const pmStr = pmLabels[ord.payment_method] || ord.payment_method;

        tr.innerHTML = `
          <td class="fw-semibold text-muted">${dt}</td>
          <td class="fw-bold">${ord.client_id ? 'Client #' + ord.client_id : 'Client comptoir'}</td>
          <td class="small" style="max-width: 250px;"><span class="text-truncate d-inline-block" style="max-width: 240px;" title="${itemsDesc}">${itemsDesc}</span></td>
          <td class="text-center"><span class="badge ${ord.payment_method === 'CASH' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-light text-dark border'}">${pmStr}</span></td>
          <td class="text-end fw-bold text-slate-800">${formatMoney(totalAmt)} F</td>
          <td class="text-center">
            <button type="button" class="btn btn-outline-danger btn-sm p-1" onclick="deleteOfflineOrder(${ord.id})" title="Supprimer"><i class="fa-solid fa-trash"></i></button>
          </td>
        `;
        tableBody.appendChild(tr);
      });
    }
  }
}

// -------------------------------------------------------------
// GESTION DU BANDEAU ET DES INDICATEURS HEADER
// -------------------------------------------------------------
async function updateNetworkIndicators() {
  const pendingCount = await getPendingOfflineCount();
  const btn = document.getElementById('networkStatusBtn');
  const dot = document.getElementById('networkStatusDot');
  const txt = document.getElementById('networkStatusText');
  const badge = document.getElementById('networkPendingBadge');
  let banner = document.getElementById('pwa-network-banner');

  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'pwa-network-banner';
    banner.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; z-index: 9999; font-size: 0.85rem; font-weight: bold; text-align: center; padding: 6px 12px; transition: all 0.3s ease;';
    document.body.prepend(banner);
  }

  const forced = isForcedOffline();

  // Mise à jour du bouton Header
  if (btn && dot && txt) {
    if (forced) {
      btn.className = 'btn btn-sm btn-outline-warning text-dark fw-bold me-2 text-nowrap d-flex align-items-center gap-1.5 shadow-sm';
      dot.style.backgroundColor = '#ea580c';
      txt.textContent = 'Mode Hors-Ligne';
    } else if (!navigator.onLine) {
      btn.className = 'btn btn-sm btn-danger text-white fw-bold me-2 text-nowrap d-flex align-items-center gap-1.5 shadow-sm';
      dot.style.backgroundColor = '#ffffff';
      txt.textContent = 'Hors-Ligne';
    } else {
      btn.className = 'btn btn-sm btn-light border fw-bold me-2 text-nowrap d-flex align-items-center gap-1.5 shadow-sm';
      dot.style.backgroundColor = '#22c55e';
      txt.textContent = 'En ligne';
    }

    if (badge) {
      if (pendingCount > 0) {
        badge.textContent = pendingCount + ' en attente';
        badge.classList.remove('d-none');
      } else {
        badge.classList.add('d-none');
      }
    }
  }

  // Mise à jour de la bannière supérieure
  if (window.PWA_DISABLED) {
    banner.style.display = 'none';
    return;
  }

  if (!navigator.onLine) {
    banner.style.backgroundColor = '#dc2626';
    banner.style.color = '#ffffff';
    banner.innerHTML = `<i class="fa-solid fa-wifi-slash me-2"></i>CONNEXION COUPÉE — Mode Hors-Ligne actif. Vos ventes sont enregistrées localement dans le navigateur. ${pendingCount > 0 ? `(${pendingCount} vente(s) en attente)` : ''} <button type="button" class="btn btn-sm btn-light py-0 px-2 ms-2 fw-bold" data-bs-toggle="modal" data-bs-target="#offlineSyncModal">Voir Cash & Synchro</button>`;
    banner.style.display = 'block';
  } else if (forced) {
    banner.style.backgroundColor = '#ea580c';
    banner.style.color = '#ffffff';
    banner.innerHTML = `<i class="fa-solid fa-hand me-2"></i>MODE HORS-LIGNE FORCÉ ACTIF — Ventes enregistrées localement. ${pendingCount > 0 ? `(${pendingCount} vente(s) en attente)` : ''} <button type="button" class="btn btn-sm btn-light py-0 px-2 ms-2 fw-bold" onclick="handleForceOfflineToggle(false)">Repasser En Ligne</button> <button type="button" class="btn btn-sm btn-dark py-0 px-2 ms-1 fw-bold" data-bs-toggle="modal" data-bs-target="#offlineSyncModal">Voir Cash</button>`;
    banner.style.display = 'block';
  } else if (pendingCount > 0) {
    banner.style.backgroundColor = '#f59e0b';
    banner.style.color = '#000000';
    banner.innerHTML = `<i class="fa-solid fa-arrows-rotate fa-spin me-2"></i>${pendingCount} vente(s) hors-ligne en attente de synchronisation. <button onclick="triggerManualSync()" class="btn btn-sm btn-dark py-0 px-2 ms-2 fw-bold">Synchroniser maintenant</button> <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 ms-1 fw-bold" data-bs-toggle="modal" data-bs-target="#offlineSyncModal">Voir Détail Cash</button>`;
    banner.style.display = 'block';
  } else {
    banner.style.display = 'none';
  }
}

// Notifications Toasts
function showPwaToast(msg, type = 'info') {
  const toast = document.createElement('div');
  const bgClass = type === 'success' ? 'bg-success text-white' : (type === 'warning' ? 'bg-warning text-dark' : 'bg-primary text-white');
  toast.className = `alert ${bgClass} border-0 shadow-lg position-fixed bottom-0 end-0 m-3 p-3`;
  toast.style.cssText = 'z-index: 10000; border-radius: 12px; max-width: 400px;';
  toast.innerHTML = `<div class="d-flex align-items-center justify-content-between"><div>${msg}</div><button type="button" class="btn-close ms-2" onclick="this.parentElement.parentElement.remove()"></button></div>`;
  document.body.appendChild(toast);
  setTimeout(() => { if (toast) toast.remove(); }, 5000);
}

// -------------------------------------------------------------
// ÉCOUTEURS D'ÉVÉNEMENTS RÉSEAU ET INITIALISATION
// -------------------------------------------------------------
window.addEventListener('online', () => {
  updateNetworkIndicators();
  renderOfflineModalData();
  if (!window.PWA_DISABLED && !isForcedOffline()) {
    showPwaToast('🌐 Connexion rétablie ! Lancement de la synchronisation automatique...', 'info');
    syncOfflineData();
  }
});

window.addEventListener('offline', () => {
  updateNetworkIndicators();
  renderOfflineModalData();
  if (window.PWA_DISABLED) {
    showPwaToast('⚠️ ' + (window.PWA_DISABLED_MSG || 'Service web non disponible.'), 'warning');
  } else {
    showPwaToast('🔴 Vous êtes actuellement Hors-Ligne. Les nouvelles ventes seront enregistrées localement.', 'warning');
  }
});

document.addEventListener('DOMContentLoaded', () => {
  updateNetworkIndicators();
  if (navigator.onLine && !window.PWA_DISABLED && !isForcedOffline()) {
    syncOfflineData();
  }

  // Écouteur pour rafraîchir le modal lors de son ouverture
  const modalEl = document.getElementById('offlineSyncModal');
  if (modalEl) {
    modalEl.addEventListener('show.bs.modal', () => {
      renderOfflineModalData();
    });
  }
});
