<?php
$db = getDB();
$cleaningTypes = $db->query("SELECT id, label FROM cleaning_types WHERE active=1 ORDER BY sort_order, label")->fetchAll();
$today = date('Y-m-d');
?>

<div class="wizard-container">
    <!-- Progress bar -->
    <div class="wizard-progress">
        <div class="wizard-progress-bar" id="progressBar" style="width:11%"></div>
    </div>
    <div class="wizard-steps-indicator">
        <span id="stepLabel">Étape 1 sur 9</span>
    </div>

    <form id="prestationForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="photo_avant_path" id="photoAvantPath">
        <input type="hidden" name="photo_apres_path" id="photoApresPath">

        <!-- STEP 1: Info de base -->
        <div class="wizard-step active" data-step="1">
            <div class="step-header">
                <h2>Informations de base</h2>
                <p class="step-desc">Date et type de nettoyage</p>
            </div>
            <div class="form-group">
                <label class="form-label">Date de la prestation</label>
                <input type="date" name="date" id="serviceDate" class="form-input" value="<?= $today ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Type de nettoyage</label>
                <div class="type-grid">
                    <?php foreach ($cleaningTypes as $ct): ?>
                    <label class="type-btn">
                        <input type="radio" name="type_nettoyage_id" value="<?= $ct['id'] ?>" required>
                        <span><?= htmlspecialchars($ct['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Lieu</label>
                <div class="lieu-grid">
                    <label class="lieu-btn">
                        <input type="radio" name="lieu" value="domicile" required>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <span>Domicile</span>
                    </label>
                    <label class="lieu-btn">
                        <input type="radio" name="lieu" value="atelier" required>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        <span>Atelier</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- STEP 2: Photo avant -->
        <div class="wizard-step" data-step="2">
            <div class="step-header">
                <h2>Photo avant</h2>
                <p class="step-desc">Prenez une photo avant le nettoyage</p>
            </div>
            <div class="photo-capture-area" id="photoAvantArea">
                <div class="photo-placeholder" id="photoAvantPlaceholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <span>Aucune photo</span>
                </div>
                <img id="photoAvantPreview" class="photo-preview" style="display:none" alt="Photo avant">
            </div>
            <div class="photo-actions">
                <label class="btn btn-primary btn-camera" for="photoAvantInput">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Prendre / Choisir une photo
                </label>
                <input type="file" id="photoAvantInput" accept="image/*" capture="environment" class="photo-file-input">
                <button type="button" class="btn btn-outline" id="photoAvantClear" style="display:none" onclick="clearPhoto('avant')">
                    Retirer la photo
                </button>
            </div>
            <p class="step-note">* La photo avant est optionnelle mais recommandée</p>
        </div>

        <!-- STEP 3: Photo après -->
        <div class="wizard-step" data-step="3">
            <div class="step-header">
                <h2>Photo après</h2>
                <p class="step-desc">Prenez une photo après le nettoyage</p>
            </div>
            <div class="photo-capture-area" id="photoApresArea">
                <div class="photo-placeholder" id="photoApresPlaceholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <span>Aucune photo</span>
                </div>
                <img id="photoApresPreview" class="photo-preview" style="display:none" alt="Photo après">
            </div>
            <div class="photo-actions">
                <label class="btn btn-primary btn-camera" for="photoApresInput">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Prendre / Choisir une photo
                </label>
                <input type="file" id="photoApresInput" accept="image/*" capture="environment" class="photo-file-input">
                <button type="button" class="btn btn-outline" id="photoApresClear" style="display:none" onclick="clearPhoto('apres')">
                    Retirer la photo
                </button>
            </div>
            <p class="step-note">* La photo après est optionnelle mais recommandée</p>
        </div>

        <!-- STEP 4: Ticket TVA -->
        <div class="wizard-step" data-step="4">
            <div class="step-header">
                <h2>Ticket TVA</h2>
                <p class="step-desc">Le client souhaite-t-il un ticket TVA ?</p>
            </div>
            <div class="toggle-choice">
                <label class="toggle-option">
                    <input type="radio" name="ticket_tva" value="1">
                    <div class="toggle-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        <span>Oui, avec ticket TVA</span>
                    </div>
                </label>
                <label class="toggle-option">
                    <input type="radio" name="ticket_tva" value="0" checked>
                    <div class="toggle-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <span>Non, sans ticket TVA</span>
                    </div>
                </label>
            </div>
        </div>

        <!-- STEP 5: Mode de paiement -->
        <div class="wizard-step" data-step="5">
            <div class="step-header">
                <h2>Mode de paiement</h2>
                <p class="step-desc">Comment le client paie-t-il ?</p>
            </div>
            <div class="payment-grid">
                <label class="payment-btn payment-cash">
                    <input type="radio" name="paiement" value="cash" required>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/></svg>
                    <span>Cash</span>
                </label>
                <label class="payment-btn payment-virement">
                    <input type="radio" name="paiement" value="virement">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    <span>Virement</span>
                </label>
                <label class="payment-btn payment-qrcode">
                    <input type="radio" name="paiement" value="qrcode">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="3" height="3"/><rect x="18" y="18" width="3" height="3"/><rect x="14" y="18" width="3" height="3"/><rect x="18" y="14" width="3" height="3"/></svg>
                    <span>QR Code</span>
                </label>
                <label class="payment-btn payment-facture">
                    <input type="radio" name="paiement" value="facture">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Sur facture</span>
                </label>
            </div>
        </div>

        <!-- STEP 6: Facture à faire -->
        <div class="wizard-step" data-step="6">
            <div class="step-header">
                <h2>Facture à établir ?</h2>
                <p class="step-desc">Faut-il établir une facture pour ce client ?</p>
            </div>
            <div class="toggle-choice">
                <label class="toggle-option">
                    <input type="radio" name="facture_a_faire" value="1">
                    <div class="toggle-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
                        <span>Oui, facture à faire</span>
                    </div>
                </label>
                <label class="toggle-option">
                    <input type="radio" name="facture_a_faire" value="0" checked>
                    <div class="toggle-card">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <span>Non, pas de facture</span>
                    </div>
                </label>
            </div>
        </div>

        <!-- STEP 7: Montant -->
        <div class="wizard-step" data-step="7">
            <div class="step-header">
                <h2>Montant</h2>
                <p class="step-desc">Quel est le montant de la prestation ?</p>
            </div>
            <div class="amount-input-wrapper">
                <input type="number" name="montant" id="montantInput" class="form-input amount-input"
                       placeholder="0.00" min="0" step="0.01" inputmode="decimal">
                <span class="amount-currency">€</span>
            </div>
            <div class="amount-presets">
                <?php foreach ([50, 80, 100, 120, 150, 200, 250, 300] as $preset): ?>
                <button type="button" class="preset-btn" onclick="setAmount(<?= $preset ?>)"><?= $preset ?> €</button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- STEP 8: Notes -->
        <div class="wizard-step" data-step="8">
            <div class="step-header">
                <h2>Notes</h2>
                <p class="step-desc">Informations complémentaires (optionnel)</p>
            </div>
            <div class="form-group">
                <textarea name="notes" id="notesInput" class="form-input form-textarea"
                          placeholder="Ex: Véhicule BMW X5, client régulier, produit spécial utilisé..."
                          rows="5"></textarea>
            </div>
        </div>

        <!-- STEP 9: Résumé -->
        <div class="wizard-step" data-step="9">
            <div class="step-header">
                <h2>Confirmation</h2>
                <p class="step-desc">Vérifiez les informations avant d'enregistrer</p>
            </div>
            <div class="summary-card" id="summaryCard">
                <!-- Filled by JS -->
            </div>
            <div id="submitError" class="alert alert-error" style="display:none"></div>
        </div>

        <!-- Navigation -->
        <div class="wizard-nav">
            <button type="button" class="btn btn-outline" id="prevBtn" onclick="wizardPrev()" style="display:none">
                ← Précédent
            </button>
            <button type="button" class="btn btn-primary" id="nextBtn" onclick="wizardNext()">
                Suivant →
            </button>
            <button type="button" class="btn btn-primary" id="submitBtn" style="display:none" onclick="submitPrestation()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Enregistrer
            </button>
        </div>
    </form>
</div>
