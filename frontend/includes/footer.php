    </div> <!-- Close Container -->

    <!-- Modal de Confirmation Mandel -->
    <div class="modal fade" id="mandelConfirmModal" tabindex="-1" aria-labelledby="mandelConfirmModalLabel" aria-hidden="true" style="z-index: 1100;">
      <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold text-slate-800" id="mandelConfirmModalLabel">
              <i class="fa-solid fa-circle-question text-primary me-2"></i>Confirmation
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body py-3 text-secondary" id="mandelConfirmModalMessage" style="font-size: 14px;">
            Voulez-vous vraiment effectuer cette action ?
          </div>
          <div class="modal-footer border-0 pt-0 d-flex gap-2">
            <button type="button" class="btn btn-light px-3 py-2 flex-grow-1" data-bs-dismiss="modal" style="border-radius: 10px; font-size: 13px;">Annuler</button>
            <button type="button" class="btn btn-primary px-3 py-2 flex-grow-1" id="mandelConfirmModalBtn" style="border-radius: 10px; font-size: 13px;">Confirmer</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Centre Hors-Ligne & Synchronisation Mandel -->
    <div class="modal fade" id="offlineSyncModal" tabindex="-1" aria-labelledby="offlineSyncModalLabel" aria-hidden="true" style="z-index: 1080;">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
          
          <!-- Header Modal -->
          <div class="modal-header border-bottom pb-3">
            <div class="d-flex align-items-center gap-2">
              <div class="p-2 rounded-3 bg-primary-subtle text-primary">
                <i class="fa-solid fa-wifi fa-lg"></i>
              </div>
              <div>
                <h5 class="modal-title fw-bold text-slate-900 mb-0" id="offlineSyncModalLabel">
                  Centre Hors-Ligne & Synchronisation
                </h5>
                <small class="text-secondary">Suivi du cash hors-ligne et synchronisation des données locales</small>
              </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <!-- Body Modal -->
          <div class="modal-body p-4">
            
            <!-- Carte Statut Réseau & Interrupteur de Mode Forcé -->
            <div class="card border-0 bg-light rounded-3 p-3 mb-4">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                  <div class="small text-secondary fw-semibold mb-1">État actuel de la connexion</div>
                  <div class="d-flex align-items-center gap-2">
                    <span id="modalNetworkStatusBadge" class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 fw-bold" style="font-size: 0.85rem;">
                      <i class="fa-solid fa-circle-check me-1"></i>Connecté à Internet
                    </span>
                    <span id="modalSyncTimestamp" class="small text-muted" style="font-size: 0.78rem;"></span>
                  </div>
                </div>

                <!-- Bouton / Switch pour forcer le mode hors-ligne -->
                <div class="d-flex align-items-center gap-2 p-2 bg-white rounded-3 border">
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="forceOfflineToggle" onchange="handleForceOfflineToggle(this.checked)" style="cursor: pointer;">
                    <label class="form-check-label fw-bold small text-slate-800" for="forceOfflineToggle" style="cursor: pointer;">
                      Forcer le Mode Hors-Ligne
                    </label>
                  </div>
                  <i class="fa-solid fa-circle-question text-muted small" title="Activez pour enregistrer vos ventes en local même si vous êtes connecté (utile en cas de connexion lente ou instable)."></i>
                </div>
              </div>
            </div>

            <!-- KPIs Récapitulatif : Cash hors-ligne, Ventes totales, Nombre de factures -->
            <div class="row g-3 mb-4">
              <div class="col-12 col-md-4">
                <div class="card border border-success-subtle bg-success bg-opacity-10 rounded-3 p-3 text-center">
                  <div class="small fw-bold text-success text-uppercase mb-1">
                    <i class="fa-solid fa-money-bill-wave me-1"></i>Cash Hors-Ligne (Espèces)
                  </div>
                  <div class="fs-4 fw-extrabold text-success" id="offlineCashTotal">0 FCFA</div>
                  <div class="small text-muted" style="font-size: 0.75rem;">Espèces physiques en caisse</div>
                </div>
              </div>
              <div class="col-12 col-md-4">
                <div class="card border border-primary-subtle bg-primary bg-opacity-10 rounded-3 p-3 text-center">
                  <div class="small fw-bold text-primary text-uppercase mb-1">
                    <i class="fa-solid fa-file-invoice-dollar me-1"></i>Total Ventes Locales
                  </div>
                  <div class="fs-4 fw-extrabold text-primary" id="offlineSalesTotal">0 FCFA</div>
                  <div class="small text-muted" style="font-size: 0.75rem;">Tous modes de règlement</div>
                </div>
              </div>
              <div class="col-12 col-md-4">
                <div class="card border border-warning-subtle bg-warning bg-opacity-10 rounded-3 p-3 text-center">
                  <div class="small fw-bold text-warning-emphasis text-uppercase mb-1">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i>Ventes en Attente
                  </div>
                  <div class="fs-4 fw-extrabold text-slate-800" id="offlineCount">0</div>
                  <div class="small text-muted" style="font-size: 0.75rem;">À synchroniser au serveur</div>
                </div>
              </div>
            </div>

            <!-- Bouton d'action Synchroniser Manuellement -->
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="fw-bold text-slate-800 mb-0">
                <i class="fa-solid fa-list-check text-primary me-2"></i>Transactions enregistrées localement
              </h6>
              <button type="button" class="btn btn-primary btn-sm fw-bold shadow-sm px-3" id="manualSyncBtn" onclick="triggerManualSync()">
                <i class="fa-solid fa-arrows-rotate me-1" id="manualSyncIcon"></i>Synchroniser Maintenant
              </button>
            </div>

            <!-- Tableau des ventes enregistrées hors-ligne -->
            <div class="table-responsive border rounded-3 bg-white" style="max-height: 280px;">
              <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.82rem;">
                <thead class="table-light sticky-top">
                  <tr>
                    <th style="width: 130px;">Enregistré le</th>
                    <th>Client / Contact</th>
                    <th>Articles & Quantités</th>
                    <th style="width: 90px;" class="text-center">Règlement</th>
                    <th style="width: 100px;" class="text-end">Montant</th>
                    <th style="width: 50px;" class="text-center">Action</th>
                  </tr>
                </thead>
                <tbody id="offlineOrdersTableBody">
                  <!-- Rempli dynamiquement en JS -->
                </tbody>
              </table>
            </div>

            <div id="offlineEmptyState" class="text-center py-4 text-muted d-none">
              <i class="fa-solid fa-circle-check text-success fa-2x mb-2"></i>
              <div>Toutes vos ventes locales sont synchronisées avec le serveur central !</div>
            </div>

          </div>

          <!-- Footer Modal -->
          <div class="modal-footer border-top bg-light d-flex justify-content-between">
            <div class="small text-secondary">
              <i class="fa-solid fa-shield-halved me-1 text-primary"></i>Stockage sécurisé dans votre navigateur (IndexedDB)
            </div>
            <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Fermer</button>
          </div>

        </div>
      </div>
    </div>

    <!-- Modal Impression Facture Hors-Ligne Mandel -->
    <div class="modal fade" id="offlineInvoiceModal" tabindex="-1" aria-labelledby="offlineInvoiceModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 500px;">
        <div class="modal-content shadow-lg border-0" style="border-radius: 16px;">
          
          <div class="modal-header bg-light border-bottom no-print py-2.5 px-3">
            <h6 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0" id="offlineInvoiceModalLabel">
              <i class="fa-solid fa-receipt text-success"></i> Facture de Vente
            </h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
          </div>

          <div class="modal-body p-3 bg-slate-50" id="offlineInvoiceModalBody">
            <!-- Zone imprimable ticket Mandel -->
            <div id="offlineInvoicePrintArea" class="bg-white p-3 border rounded-3 shadow-sm mx-auto" style="max-width: 440px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #000; line-height: 1.35;">
              
              <!-- En-tête ticket avec Logo Officiel Mandel -->
              <div class="text-center mb-3">
                <div class="mb-1">
                  <img src="assets/images/logo.png" alt="Mandel" style="height: 44px; width: auto; object-fit: contain;">
                </div>
                <h5 class="fw-bold mb-0" style="letter-spacing: -0.5px; font-size: 16px;">Ets MANDEL</h5>
                <div class="fw-semibold text-uppercase" id="offInvShopName" style="font-size: 11px; color: #475569;">Boutique Mandel</div>
                <div style="font-size: 10px; color: #64748b;" id="offInvShopContact">Yaoundé, Cameroun</div>
              </div>

              <hr style="border-top: 1px dashed #000; margin: 6px 0;">

              <!-- Infos Transaction -->
              <div class="mb-2" style="font-size: 11px;">
                <div class="d-flex justify-content-between">
                  <span><strong>Facture N° :</strong> <span id="offInvReference" class="fw-bold">FAC-00001</span></span>
                  <span id="offInvDate" class="text-secondary small">14/11/2026 05:00</span>
                </div>
                <div><strong>Client :</strong> <span id="offInvClient">Client comptoir</span></div>
                <div><strong>Vendeur :</strong> <span id="offInvUser">Personnel</span></div>
                <div><strong>Règlement :</strong> <span id="offInvPaymentMethod" class="fw-bold">Espèces</span></div>
              </div>

              <hr style="border-top: 1px dashed #000; margin: 6px 0;">

              <!-- Tableau des Articles -->
              <table class="w-100 mb-2" style="font-size: 11px; border-collapse: collapse;">
                <thead>
                  <tr style="border-bottom: 1px solid #000;">
                    <th class="text-start py-1" style="width: 55%;">Désignation</th>
                    <th class="text-center py-1" style="width: 15%;">Qté</th>
                    <th class="text-end py-1" style="width: 30%;">Total</th>
                  </tr>
                </thead>
                <tbody id="offInvItemsBody">
                  <!-- Injecté dynamiquement par JS -->
                </tbody>
              </table>

              <hr style="border-top: 1px dashed #000; margin: 6px 0;">

              <!-- Totaux -->
              <div id="offInvTotalsArea" style="font-size: 11px;">
                <div class="d-flex justify-content-between">
                  <span>Sous-Total :</span>
                  <span id="offInvSubtotal" class="fw-semibold">0 FCFA</span>
                </div>
                <div class="d-flex justify-content-between text-danger d-none" id="offInvDiscountRow">
                  <span>Remise accordée :</span>
                  <span id="offInvDiscount">0 FCFA</span>
                </div>
                <div class="d-flex justify-content-between fw-bold pt-1 mt-1" style="border-top: 1px solid #000; font-size: 13px;">
                  <span>NET À PAYER :</span>
                  <span id="offInvTotal" class="fw-extrabold" style="font-size: 15px;">0 FCFA</span>
                </div>
                <div id="offInvCreditDetails" class="d-none mt-1 pt-1" style="border-top: 1px dotted #ccc;">
                  <div class="d-flex justify-content-between text-success">
                    <span>Acompte Reçu :</span>
                    <span id="offInvAmountPaid">0 FCFA</span>
                  </div>
                  <div class="d-flex justify-content-between text-danger fw-bold">
                    <span>Reste à Payer :</span>
                    <span id="offInvRemaining">0 FCFA</span>
                  </div>
                  <div class="d-flex justify-content-between text-muted small" id="offInvDueDateRow">
                    <span>Date Limite de Paiement :</span>
                    <span id="offInvDueDate">-</span>
                  </div>
                </div>
              </div>

              <!-- Notes -->
              <div id="offInvNotesRow" class="mt-2 p-1.5 bg-light border rounded small d-none" style="font-size: 10px;">
                <strong>Note :</strong> <span id="offInvNotes"></span>
              </div>

              <hr style="border-top: 1px dashed #000; margin: 8px 0 6px 0;">

              <!-- Bas de Ticket -->
              <div class="text-center text-muted" style="font-size: 9.5px; line-height: 1.3;">
                <p class="mb-0 fw-bold text-dark">Merci de votre confiance !</p>
                <p class="mb-0">Les articles vendus ne sont ni repris, ni échangés.</p>
                <p class="mt-2 mb-0" style="font-size: 8.5px;">Mandel Stock Manager &copy; <?= date('Y') ?></p>
              </div>

            </div>
          </div>

          <div class="modal-footer bg-light border-top no-print d-flex justify-content-between py-2 px-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
              <i class="fa-solid fa-plus me-1"></i> Nouvelle Vente
            </button>
            <button type="button" class="btn btn-success btn-sm fw-bold px-3 shadow-sm" onclick="printOfflineInvoiceModal()">
              <i class="fa-solid fa-print me-1.5"></i> Imprimer le Ticket
            </button>
          </div>

        </div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Gestion du Mode Compact
        let compactToggleBtn = document.getElementById('compact-toggle');
        if (compactToggleBtn) {
            function updateToggleState() {
                if (document.documentElement.classList.contains('compact-mode')) {
                    compactToggleBtn.innerHTML = '<i class="fa-solid fa-expand me-1"></i> <span class="d-none d-xl-inline">Mode Aéré</span>';
                    compactToggleBtn.classList.remove('btn-secondary');
                    compactToggleBtn.classList.add('btn-outline-secondary');
                } else {
                    compactToggleBtn.innerHTML = '<i class="fa-solid fa-compress me-1"></i> <span class="d-none d-xl-inline">Mode Compact</span>';
                    compactToggleBtn.classList.remove('btn-outline-secondary');
                    compactToggleBtn.classList.add('btn-secondary');
                }
            }
            updateToggleState();
            
            compactToggleBtn.addEventListener('click', function() {
                if (document.documentElement.classList.contains('compact-mode')) {
                    document.documentElement.classList.remove('compact-mode');
                    localStorage.setItem('mandel-compact-mode', 'disabled');
                } else {
                    document.documentElement.classList.add('compact-mode');
                    localStorage.setItem('mandel-compact-mode', 'enabled');
                }
                updateToggleState();
            });
        }

        let confirmModalEl = document.getElementById('mandelConfirmModal');
        if (!confirmModalEl) return;
        let confirmModal = new bootstrap.Modal(confirmModalEl);
        let confirmBtn = document.getElementById('mandelConfirmModalBtn');
        let messageEl = document.getElementById('mandelConfirmModalMessage');
        let actionCallback = null;

        // Fonction globale pour ouvrir la confirmation
        window.confirmMandel = function(message, callback) {
            messageEl.innerHTML = message;
            actionCallback = callback;
            confirmModal.show();
        };

        // Lors du clic sur confirmer
        confirmBtn.addEventListener('click', function() {
            if (actionCallback) actionCallback();
            confirmModal.hide();
        });

        // Intercepter toutes les suppressions ou confirmations classiques
        document.body.addEventListener('click', function(e) {
            let target = e.target.closest('a, button');
            if (!target) return;

            let onclickAttr = target.getAttribute('onclick');
            let hasConfirmClass = target.classList.contains('btn-confirm-action');
            
            if (hasConfirmClass || (onclickAttr && onclickAttr.includes('confirm'))) {
                e.preventDefault();
                e.stopPropagation();

                let message = "Voulez-vous vraiment effectuer cette action ?";
                if (onclickAttr) {
                    let match = onclickAttr.match(/confirm\(['"](.+?)['"]\)/);
                    if (match && match[1]) message = match[1];
                    target.removeAttribute('onclick');
                }
                
                window.confirmMandel(message, function() {
                    if (target.type === 'submit') {
                        let form = target.closest('form');
                        if (form) {
                            if (target.name) {
                                let hiddenInput = document.createElement('input');
                                hiddenInput.type = 'hidden';
                                hiddenInput.name = target.name;
                                hiddenInput.value = target.value || '1';
                                form.appendChild(hiddenInput);
                            }
                            form.submit();
                        }
                    } else if (target.tagName === 'A') {
                        window.location.href = target.href;
                    } else {
                        target.click();
                    }
                });
                
                if (onclickAttr) {
                    setTimeout(() => target.setAttribute('onclick', onclickAttr), 500);
                }
            }
        });

        // Intercepter la soumission du formulaire orderForm (order-create.php)
        let orderForm = document.getElementById('orderForm');
        if (orderForm) {
            orderForm.addEventListener('submit', function(e) {
                e.preventDefault();
                window.confirmMandel("Voulez-vous vraiment enregistrer cette commande ?", function() {
                    orderForm.submit();
                });
            });
        }

        // Intercepter toutes les soumissions de formulaire avec onsubmit="return confirm(...)"
        document.body.addEventListener('submit', function(e) {
            let form = e.target;
            let onsubmitAttr = form.getAttribute('onsubmit');
            
            if (onsubmitAttr && onsubmitAttr.includes('confirm')) {
                e.preventDefault();
                e.stopPropagation();

                let message = "Voulez-vous vraiment effectuer cette action ?";
                let match = onsubmitAttr.match(/confirm\(['"](.+?)['"]\)/);
                if (match && match[1]) message = match[1];
                
                form.removeAttribute('onsubmit');
                
                window.confirmMandel(message, function() {
                    let submitter = e.submitter;
                    if (submitter && submitter.name) {
                        let hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = submitter.name;
                        hiddenInput.value = submitter.value;
                        form.appendChild(hiddenInput);
                    }
                    form.submit();
                });
                
                setTimeout(() => form.setAttribute('onsubmit', onsubmitAttr), 500);
            }
        });
    });
    </script>

    <footer class="text-center py-4 text-secondary small no-print mt-5">
        <hr class="w-50 mx-auto opacity-25">
        &copy; <?= date('Y') ?> Mandel Papeterie - Gestion de Stock & Facturation Multi-boutiques.
    </footer>
</body>
</html>
