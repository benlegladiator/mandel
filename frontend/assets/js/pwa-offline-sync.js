/**
 * PWA & Offline Sync Manager - Mandel Papeterie ERP
 * Gestion complète du mode hors-ligne, cash local, bascule en-ligne/hors-ligne et synchronisation manuelle.
 */

// -------------------------------------------------------------
// GESTION DU PROMPT D'INSTALLATION AUTOMATIQUE PWA
// -------------------------------------------------------------
let deferredPwaPrompt = null;
let pwaInstallCardShown = false;

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPwaPrompt = e;
  console.log('[PWA] Événement beforeinstallprompt capturé.');
  if (!window.PWA_DISABLED) {
    // Proposer automatiquement l'installation dès que le navigateur est prêt
    setTimeout(() => {
      showAutoInstallPrompt();
    }, 1200);
  }
});

// Détecter si l'application est déjà lancée en mode autonome (déjà installée)
function isRunningStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches || 
         window.navigator.standalone === true || 
         document.referrer.includes('android-app://');
}

// Afficher la proposition d'installation automatique
function showAutoInstallPrompt() {
  if (window.PWA_DISABLED || isRunningStandalone() || pwaInstallCardShown) {
    return;
  }

  // Si l'utilisateur a fermé la notification dans la session actuelle
  if (sessionStorage.getItem('mandel_pwa_dismissed') === 'true') {
    return;
  }

  pwaInstallCardShown = true;
  let card = document.getElementById('mandelAutoInstallPrompt');
  if (!card) {
    card = document.createElement('div');
    card.id = 'mandelAutoInstallPrompt';
    card.style.cssText = `
      position: fixed;
      bottom: 24px;
      right: 24px;
      width: calc(100% - 48px);
      max-width: 420px;
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 16px 40px rgba(0, 0, 0, 0.18), 0 2px 8px rgba(0,0,0,0.06);
      border: 1px solid #e2e8f0;
      z-index: 10500;
      padding: 18px 20px;
      font-family: inherit;
      animation: mandelSlideUp 0.35s ease-out;
    `;

    // Injecter style d'animation
    if (!document.getElementById('mandel-pwa-anim-style')) {
      const st = document.createElement('style');
      st.id = 'mandel-pwa-anim-style';
      st.textContent = `
        @keyframes mandelSlideUp {
          from { opacity: 0; transform: translateY(20px); }
          to { opacity: 1; transform: translateY(0); }
        }
      `;
      document.head.appendChild(st);
    }

    card.innerHTML = `
      <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
        <img src="assets/images/logo.png" alt="Mandel" style="height: 40px; width: auto; object-fit: contain;">
        <div>
          <h6 style="margin: 0; font-weight: 800; color: #0f172a; font-size: 0.98rem; letter-spacing: -0.2px;">Installer Mandel Papeterie</h6>
          <span style="font-size: 0.78rem; color: #059669; font-weight: 600;">Application Officielle ERP</span>
        </div>
        <button type="button" class="btn-close ms-auto" onclick="dismissAutoInstallPrompt()" aria-label="Fermer" style="font-size: 0.8rem;"></button>
      </div>
      <p style="font-size: 0.82rem; color: #475569; margin-bottom: 14px; line-height: 1.4;">
        Installez Mandel sur votre écran d'accueil pour un accès en 1 clic et continuer à encaisser et vendre <strong>même en cas de coupure Internet</strong>.
      </p>
      <div id="pwaInstallActionContainer" style="display: flex; gap: 8px;">
        <button type="button" class="btn btn-success fw-bold flex-grow-1" onclick="executePwaInstall()" style="background: #059669; border: none; border-radius: 10px; padding: 8px 14px; font-size: 0.88rem;">
          <i class="fa-solid fa-download me-1.5"></i> Installer Maintenant
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="dismissAutoInstallPrompt()" style="border-radius: 10px; padding: 8px 14px; font-size: 0.85rem;">
          Plus tard
        </button>
      </div>
      <div id="pwaManualInstructions" style="display: none; margin-top: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; font-size: 0.8rem; color: #334155; line-height: 1.4;">
      </div>
    `;

    document.body.appendChild(card);
  } else {
    card.style.display = 'block';
  }
}

// Exécuter l'installation lors du clic sur le bouton automatique
function executePwaInstall() {
  if (deferredPwaPrompt) {
    deferredPwaPrompt.prompt();
    deferredPwaPrompt.userChoice.then((choiceResult) => {
      if (choiceResult.outcome === 'accepted') {
        console.log('[PWA] L\'utilisateur a accepté l\'installation.');
        showPwaToast('🎉 Application Mandel installée avec succès ! Retrouvez-la sur votre écran.', 'success');
        dismissAutoInstallPrompt();
      }
      deferredPwaPrompt = null;
    });
  } else {
    // Si deferredPrompt non disponible (ex: iOS Safari ou navigateur PC sans hook direct)
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const infoBox = document.getElementById('pwaManualInstructions');
    const actions = document.getElementById('pwaInstallActionContainer');
    if (infoBox) {
      if (isIOS) {
        infoBox.innerHTML = `<strong>Sur iPhone / iPad :</strong><br>1. Appuyez sur le bouton <strong>Partager</strong> <i class="fa-solid fa-arrow-up-from-bracket"></i> en bas de Safari.<br>2. Choisissez <strong>« Sur l'écran d'accueil »</strong> ➕.`;
      } else {
        infoBox.innerHTML = `<strong>Installation rapide :</strong><br>• <strong>Sur PC/Mac :</strong> Cliquez sur l'icône d'installation dans la barre d'adresse en haut à droite 🖥️.<br>• <strong>Sur Android :</strong> Ouvrez le menu Chrome (3 points) puis cliquez sur <strong>« Installer l'application »</strong>.`;
      }
      infoBox.style.display = 'block';
      if (actions) {
        actions.innerHTML = `<button type="button" class="btn btn-dark w-100 fw-bold" onclick="dismissAutoInstallPrompt()" style="border-radius: 8px; font-size: 0.85rem;">J'ai compris</button>`;
      }
    }
  }
}

// Fermer la proposition d'installation
function dismissAutoInstallPrompt() {
  const card = document.getElementById('mandelAutoInstallPrompt');
  if (card) {
    card.style.display = 'none';
  }
  sessionStorage.setItem('mandel_pwa_dismissed', 'true');
}

// Déclenchement automatique au chargement de la page
window.addEventListener('load', () => {
  if (!isRunningStandalone() && !window.PWA_DISABLED) {
    setTimeout(() => {
      showAutoInstallPrompt();
    }, 2000);
  }
});

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
        .then(reg => {
          console.log('[PWA] Service Worker Mandel actif, scope:', reg.scope);
        })
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

// Supprimer une commande hors-ligne après synchronisation
async function deleteOfflineOrder(id) {
  try {
    const db = await openOfflineDB();
    const tx = db.transaction('offline_orders', 'readwrite');
    const store = tx.objectStore('offline_orders');
    store.delete(id);
    return new Promise((resolve) => {
      tx.oncomplete = () => {
        updateNetworkIndicators();
        renderOfflineModalData();
        resolve(true);
      };
    });
  } catch (err) {
    console.error('[OfflineDB] Erreur suppression:', err);
    return false;
  }
}

// Vider toutes les commandes hors-ligne
async function clearAllOfflineOrders() {
  try {
    const db = await openOfflineDB();
    const tx = db.transaction('offline_orders', 'readwrite');
    const store = tx.objectStore('offline_orders');
    store.clear();
    return new Promise((resolve) => {
      tx.oncomplete = () => {
        updateNetworkIndicators();
        renderOfflineModalData();
        resolve(true);
      };
    });
  } catch (err) {
    return false;
  }
}

// -------------------------------------------------------------
// SYNCHRONISATION DES DONNÉES AVEC LE BACKEND
// -------------------------------------------------------------
let isSyncing = false;

async function syncOfflineData() {
  if (window.PWA_DISABLED) return;
  if (isForcedOffline()) {
    console.log('[PWA Sync] Synchronisation ignorée (Mode Hors-Ligne Forcé actif).');
    return;
  }
  if (!navigator.onLine) {
    console.log('[PWA Sync] Pas de connexion internet disponible.');
    return;
  }
  if (isSyncing) return;

  const orders = await getOfflineOrders();
  if (orders.length === 0) {
    return;
  }

  isSyncing = true;
  showPwaToast(`🔄 Synchronisation de ${orders.length} commande(s) hors-ligne en cours...`, 'info');

  try {
    const res = await fetch('../backend/api/sync_offline.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ orders: orders })
    });

    const data = await res.json();
    if (data && data.success) {
      await clearAllOfflineOrders();
      showPwaToast(`✅ Synchronisation réussie ! ${data.synced_count} commande(s) enregistrée(s) au serveur central.`, 'success');
      updateNetworkIndicators();
      renderOfflineModalData();
    } else {
      showPwaToast(`⚠️ Problème de synchronisation: ${data.message || 'Erreur serveur'}`, 'warning');
    }
  } catch (err) {
    console.warn('[PWA Sync] Échec communication serveur:', err);
  } finally {
    isSyncing = false;
  }
}

// Synchronisation manuelle déclenchée par l'utilisateur
async function triggerManualSync() {
  if (!navigator.onLine) {
    alert("Impossible de synchroniser : Votre appareil n'est pas connecté à Internet. Veuillez reconnecter le Wi-Fi ou les données mobiles.");
    return;
  }
  if (isForcedOffline()) {
    if (confirm("Le Mode Hors-Ligne Forcé est activé. Voulez-vous le désactiver pour lancer la synchronisation maintenant ?")) {
      handleForceOfflineToggle(false);
      return;
    }
    return;
  }
  await syncOfflineData();
}

// -------------------------------------------------------------
// CALCUL DU CASH & RENDU DU MODAL DE CONTRÔLE HORS-LIGNE
// -------------------------------------------------------------
function formatMoney(num) {
  return (Math.round(num) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

async function renderOfflineModalData() {
  const orders = await getOfflineOrders();
  const forced = isForcedOffline();
  const online = navigator.onLine;

  const forceToggle = document.getElementById('forceOfflineToggle');
  if (forceToggle) {
    forceToggle.checked = forced;
  }

  const modalBadge = document.getElementById('modalNetworkStatusBadge');
  if (modalBadge) {
    if (forced) {
      modalBadge.className = 'badge bg-warning text-dark border px-2.5 py-1.5 fw-bold';
      modalBadge.innerHTML = '<i class="fa-solid fa-hand me-1"></i> Mode Hors-Ligne FORCÉ';
    } else if (!online) {
      modalBadge.className = 'badge bg-danger text-white border border-danger px-2.5 py-1.5 fw-bold';
      modalBadge.innerHTML = '<i class="fa-solid fa-wifi-slash me-1"></i> Hors-Ligne (Pas d\'Internet)';
    } else {
      modalBadge.className = 'badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 fw-bold';
      modalBadge.innerHTML = '<i class="fa-solid fa-wifi me-1"></i> En ligne (Connecté)';
    }
  }

  let totalCash = 0;
  let totalMomo = 0;
  let totalCredit = 0;
  let totalOverall = 0;

  orders.forEach(ord => {
    const subtotal = (ord.items || []).reduce((acc, it) => acc + (parseFloat(it.price) * parseInt(it.quantity)), 0);
    const totalAmt = Math.max(0, subtotal - (parseFloat(ord.discount) || 0));
    totalOverall += totalAmt;

    const pm = (ord.payment_method || 'CASH').toUpperCase();
    if (pm === 'CASH') {
      totalCash += totalAmt;
    } else if (pm === 'MOBILE_MONEY') {
      totalMomo += totalAmt;
    } else if (pm === 'CREDIT') {
      totalCredit += totalAmt;
    }
  });

  const cashEl = document.getElementById('offlineCashTotal');
  const momoEl = document.getElementById('offlineMomoTotal');
  const countEl = document.getElementById('offlineCountTotal');
  const overallEl = document.getElementById('offlineOverallTotal');

  if (cashEl) cashEl.textContent = formatMoney(totalCash) + ' FCFA';
  if (momoEl) momoEl.textContent = formatMoney(totalMomo) + ' FCFA';
  if (countEl) countEl.textContent = orders.length;
  if (overallEl) overallEl.textContent = formatMoney(totalOverall) + ' FCFA';

  const tableBody = document.getElementById('offlineOrdersTableBody');
  const emptyState = document.getElementById('offlineOrdersEmptyState');
  const tableContainer = document.getElementById('offlineOrdersTableContainer');

  if (tableBody) {
    tableBody.innerHTML = '';
    if (orders.length === 0) {
      if (emptyState) emptyState.classList.remove('d-none');
      if (tableContainer) tableContainer.classList.add('d-none');
    } else {
      if (emptyState) emptyState.classList.add('d-none');
      if (tableContainer) tableContainer.classList.remove('d-none');

      orders.forEach((ord) => {
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
// GESTION DU BANDEAU ET DES INDICATEURS RÉSEAU (HEADER & ALERTES)
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
    const nav = document.querySelector('nav.navbar');
    if (nav) {
      nav.after(banner);
    } else {
      document.body.prepend(banner);
    }
  }

  const forced = isForcedOffline();
  const isOffline = !navigator.onLine || forced;

  // Mise à jour du bouton Header
  if (btn && dot && txt) {
    if (forced) {
      btn.className = 'btn btn-sm btn-warning text-dark fw-bold me-2 text-nowrap d-flex align-items-center gap-2 shadow-sm';
      dot.style.backgroundColor = '#ea580c';
      dot.style.width = '10px';
      dot.style.height = '10px';
      dot.style.borderRadius = '50%';
      txt.innerHTML = '<i class="fa-solid fa-pause me-1"></i>Hors-Ligne (Forcé)';
    } else if (!navigator.onLine) {
      btn.className = 'btn btn-sm btn-danger text-white fw-bold me-2 text-nowrap d-flex align-items-center gap-2 shadow animate-pulse';
      dot.style.backgroundColor = '#ffffff';
      dot.style.width = '10px';
      dot.style.height = '10px';
      dot.style.borderRadius = '50%';
      txt.innerHTML = '<i class="fa-solid fa-wifi-slash me-1"></i>Hors-Ligne';
    } else {
      btn.className = 'btn btn-sm btn-light border fw-bold me-2 text-nowrap d-flex align-items-center gap-2 shadow-sm';
      dot.style.backgroundColor = '#22c55e';
      dot.style.width = '10px';
      dot.style.height = '10px';
      dot.style.borderRadius = '50%';
      txt.innerHTML = 'En ligne';
    }

    if (badge) {
      if (pendingCount > 0) {
        badge.textContent = pendingCount + ' vente(s)';
        badge.classList.remove('d-none');
      } else {
        badge.classList.add('d-none');
      }
    }
  }

  // Mise à jour de la bannière supérieure informative
  if (window.PWA_DISABLED) {
    banner.style.display = 'none';
    return;
  }

  if (!navigator.onLine) {
    banner.className = 'alert alert-danger mb-3 mx-3 py-2 px-3 border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2';
    banner.style.borderRadius = '12px';
    banner.style.display = 'flex';
    banner.innerHTML = `
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-danger p-2"><i class="fa-solid fa-wifi-slash fs-6"></i></span>
        <div>
          <strong class="text-danger">CONNEXION COUPÉE — Le mode Hors-Ligne Mandel est ACTIF</strong>
          <div class="text-secondary small">Vous pouvez continuer à enregistrer vos ventes. Elles sont sauvegardées en sécurité locale et seront transmises au retour du réseau. ${pendingCount > 0 ? `(<strong>${pendingCount} vente(s)</strong> en attente)` : ''}</div>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-dark fw-bold" data-bs-toggle="modal" data-bs-target="#offlineSyncModal">
          <i class="fa-solid fa-wallet me-1"></i> Voir Cash & Enregistrements
        </button>
      </div>
    `;
  } else if (forced) {
    banner.className = 'alert alert-warning mb-3 mx-3 py-2 px-3 border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2';
    banner.style.borderRadius = '12px';
    banner.style.display = 'flex';
    banner.innerHTML = `
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-warning text-dark p-2"><i class="fa-solid fa-hand fs-6"></i></span>
        <div>
          <strong class="text-dark">MODE HORS-LIGNE FORCÉ ACTIF</strong>
          <div class="text-secondary small">Ventes stockées localement sur cet appareil. ${pendingCount > 0 ? `(<strong>${pendingCount} vente(s)</strong> en attente)` : ''}</div>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-success fw-bold text-white" onclick="handleForceOfflineToggle(false)">
          <i class="fa-solid fa-play me-1"></i> Repasser En Ligne
        </button>
        <button type="button" class="btn btn-sm btn-dark fw-bold" data-bs-toggle="modal" data-bs-target="#offlineSyncModal">
          <i class="fa-solid fa-wallet me-1"></i> Voir Cash
        </button>
      </div>
    `;
  } else if (pendingCount > 0) {
    banner.className = 'alert alert-warning mb-3 mx-3 py-2 px-3 border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2';
    banner.style.borderRadius = '12px';
    banner.style.display = 'flex';
    banner.innerHTML = `
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-warning text-dark p-2"><i class="fa-solid fa-arrows-rotate fa-spin fs-6"></i></span>
        <div>
          <strong class="text-dark">${pendingCount} vente(s) hors-ligne en attente d'envoi vers le serveur central</strong>
          <div class="text-secondary small">La connexion Internet est active. Cliquez pour finaliser la synchronisation.</div>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button onclick="triggerManualSync()" class="btn btn-sm btn-dark fw-bold">
          <i class="fa-solid fa-cloud-arrow-up me-1"></i> Synchroniser maintenant
        </button>
        <button type="button" class="btn btn-sm btn-outline-dark fw-bold" data-bs-toggle="modal" data-bs-target="#offlineSyncModal">
          Voir Cash
        </button>
      </div>
    `;
  } else {
    banner.style.display = 'none';
  }
}

// Notifications Toasts
function showPwaToast(msg, type = 'info') {
  const toast = document.createElement('div');
  const bgClass = type === 'success' ? 'bg-success text-white' : (type === 'warning' ? 'bg-warning text-dark' : 'bg-primary text-white');
  toast.className = `alert ${bgClass} border-0 shadow-lg position-fixed bottom-0 end-0 m-3 p-3`;
  toast.style.cssText = 'z-index: 10600; border-radius: 12px; max-width: 420px;';
  toast.innerHTML = `<div class="d-flex align-items-center justify-content-between"><div>${msg}</div><button type="button" class="btn-close ms-2" onclick="this.parentElement.parentElement.remove()"></button></div>`;
  document.body.appendChild(toast);
  setTimeout(() => { if (toast) toast.remove(); }, 6000);
}

// -------------------------------------------------------------
// ÉCOUTEURS D'ÉVÉNEMENTS RÉSEAU ET INITIALISATION
// -------------------------------------------------------------
window.addEventListener('online', () => {
  console.log('[Réseau] Connexion rétablie.');
  updateNetworkIndicators();
  renderOfflineModalData();
  if (!window.PWA_DISABLED && !isForcedOffline()) {
    showPwaToast('🌐 Connexion Internet rétablie ! Lancement de la synchronisation automatique...', 'success');
    syncOfflineData();
  }
});

window.addEventListener('offline', () => {
  console.log('[Réseau] Connexion coupée.');
  updateNetworkIndicators();
  renderOfflineModalData();
  showPwaToast('🔴 Connexion Internet coupée : Mandel bascule automatiquement en mode Hors-Ligne.', 'warning');
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

// Alias pour rétro-compatibilité
window.updateOfflineBanner = updateNetworkIndicators;
