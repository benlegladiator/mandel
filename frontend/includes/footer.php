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

    <!-- Bootstrap 5 Bundle JS (includes Popper) -->
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
