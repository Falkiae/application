// Multi-step wizard for new prestation
let currentStep = 1;
const totalSteps = 9;
const uploadedPhotos = { avant: null, apres: null };

function updateProgress() {
    const pct = (currentStep / totalSteps) * 100;
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('stepLabel').textContent = `Étape ${currentStep} sur ${totalSteps}`;

    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const submitBtn = document.getElementById('submitBtn');

    prevBtn.style.display = currentStep > 1 ? 'block' : 'none';
    nextBtn.style.display = currentStep < totalSteps ? 'block' : 'none';
    submitBtn.style.display = currentStep === totalSteps ? 'block' : 'none';

    if (currentStep === totalSteps) buildSummary();
}

function showStep(step) {
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
    const target = document.querySelector(`.wizard-step[data-step="${step}"]`);
    if (target) {
        target.classList.add('active');
        target.scrollIntoView({behavior:'smooth', block:'start'});
    }
}

function validateCurrentStep() {
    const step = document.querySelector(`.wizard-step[data-step="${currentStep}"]`);
    if (!step) return true;

    // Step 1: date, type, lieu
    if (currentStep === 1) {
        const date = document.getElementById('serviceDate')?.value;
        const type = document.querySelector('input[name="type_nettoyage_id"]:checked');
        const lieu = document.querySelector('input[name="lieu"]:checked');
        if (!date) { showStepError('Veuillez saisir une date'); return false; }
        if (!type) { showStepError('Veuillez sélectionner un type de nettoyage'); return false; }
        if (!lieu) { showStepError('Veuillez sélectionner le lieu'); return false; }
    }
    // Step 5: paiement
    if (currentStep === 5) {
        const paiement = document.querySelector('input[name="paiement"]:checked');
        if (!paiement) { showStepError('Veuillez sélectionner un mode de paiement'); return false; }
    }
    // Step 7: montant
    if (currentStep === 7) {
        const montant = parseFloat(document.getElementById('montantInput')?.value || '0');
        if (isNaN(montant) || montant < 0) { showStepError('Veuillez saisir un montant valide'); return false; }
    }
    clearStepError();
    return true;
}

function showStepError(msg) {
    let err = document.getElementById('stepError');
    if (!err) {
        err = document.createElement('div');
        err.id = 'stepError';
        err.className = 'alert alert-error';
        document.querySelector('.wizard-nav').before(err);
    }
    err.textContent = msg;
    err.style.display = 'block';
}

function clearStepError() {
    const err = document.getElementById('stepError');
    if (err) err.style.display = 'none';
}

function wizardNext() {
    if (!validateCurrentStep()) return;
    if (currentStep < totalSteps) {
        currentStep++;
        showStep(currentStep);
        updateProgress();
    }
}

function wizardPrev() {
    if (currentStep > 1) {
        currentStep--;
        showStep(currentStep);
        updateProgress();
    }
}

function setAmount(val) {
    document.getElementById('montantInput').value = val;
}

function buildSummary() {
    const date = document.getElementById('serviceDate')?.value || '';
    const typeEl = document.querySelector('input[name="type_nettoyage_id"]:checked');
    const typeLabel = typeEl?.parentElement?.querySelector('span')?.textContent || '';
    const lieu = document.querySelector('input[name="lieu"]:checked')?.value || '';
    const tva = document.querySelector('input[name="ticket_tva"]:checked')?.value;
    const paiement = document.querySelector('input[name="paiement"]:checked')?.value || '';
    const facture = document.querySelector('input[name="facture_a_faire"]:checked')?.value;
    const montant = document.getElementById('montantInput')?.value || '0';
    const notes = document.getElementById('notesInput')?.value || '';

    const lieuLabel = lieu === 'domicile' ? 'Domicile' : 'Atelier';
    const paiementLabels = {cash:'Cash',virement:'Virement',qrcode:'QR Code',facture:'Sur facture'};

    const formatDate = (d) => {
        if (!d) return '';
        const parts = d.split('-');
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    };

    const photoAvant = uploadedPhotos.avant ? '✓ Photo uploadée' : 'Aucune photo';
    const photoApres = uploadedPhotos.apres ? '✓ Photo uploadée' : 'Aucune photo';

    document.getElementById('summaryCard').innerHTML = `
        <div class="summary-row"><span>Date</span><strong>${formatDate(date)}</strong></div>
        <div class="summary-row"><span>Type</span><strong>${escHtml(typeLabel)}</strong></div>
        <div class="summary-row"><span>Lieu</span><strong>${lieuLabel}</strong></div>
        <div class="summary-row"><span>Photo avant</span><strong>${photoAvant}</strong></div>
        <div class="summary-row"><span>Photo après</span><strong>${photoApres}</strong></div>
        <div class="summary-row"><span>Ticket TVA</span><strong>${tva === '1' ? 'Oui' : 'Non'}</strong></div>
        <div class="summary-row"><span>Paiement</span><strong>${paiementLabels[paiement] || paiement}</strong></div>
        <div class="summary-row"><span>Facture à faire</span><strong>${facture === '1' ? 'Oui' : 'Non'}</strong></div>
        <div class="summary-row summary-row-total"><span>Montant</span><strong>${parseFloat(montant).toFixed(2).replace('.',',')} €</strong></div>
        ${notes ? `<div class="summary-row"><span>Notes</span><span>${escHtml(notes)}</span></div>` : ''}
    `;
}

function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}

async function submitPrestation() {
    const btn = document.getElementById('submitBtn');
    const err = document.getElementById('submitError');
    err.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<div class="btn-spinner"></div> Enregistrement...';

    const form = document.getElementById('prestationForm');
    const fd = new FormData(form);
    fd.set('action', 'create');

    // Ensure checkbox values are sent as integers
    const tva = document.querySelector('input[name="ticket_tva"]:checked')?.value || '0';
    const facture = document.querySelector('input[name="facture_a_faire"]:checked')?.value || '0';
    fd.set('ticket_tva', tva);
    fd.set('facture_a_faire', facture);

    try {
        const res = await fetch('api/prestation_save.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            window.location.href = 'index.php?page=prestations';
        } else {
            err.textContent = data.error || 'Erreur lors de l\'enregistrement';
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Enregistrer';
        }
    } catch (e) {
        err.textContent = 'Erreur réseau, réessayez.';
        err.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Enregistrer';
    }
}

function clearPhoto(which) {
    uploadedPhotos[which] = null;
    const pathInput = document.getElementById(which === 'avant' ? 'photoAvantPath' : 'photoApresPath');
    const preview = document.getElementById(which === 'avant' ? 'photoAvantPreview' : 'photoApresPreview');
    const placeholder = document.getElementById(which === 'avant' ? 'photoAvantPlaceholder' : 'photoApresPlaceholder');
    const clearBtn = document.getElementById(which === 'avant' ? 'photoAvantClear' : 'photoApresClear');
    pathInput.value = '';
    preview.style.display = 'none';
    preview.src = '';
    placeholder.style.display = 'flex';
    if (clearBtn) clearBtn.style.display = 'none';
}

async function handlePhotoUpload(inputEl, which) {
    const file = inputEl.files[0];
    if (!file) return;

    const pathInput = document.getElementById(which === 'avant' ? 'photoAvantPath' : 'photoApresPath');
    const preview = document.getElementById(which === 'avant' ? 'photoAvantPreview' : 'photoApresPreview');
    const placeholder = document.getElementById(which === 'avant' ? 'photoAvantPlaceholder' : 'photoApresPlaceholder');
    const clearBtn = document.getElementById(which === 'avant' ? 'photoAvantClear' : 'photoApresClear');

    // Show loading
    placeholder.innerHTML = '<div class="photo-loading">Compression...</div>';

    try {
        const blob = await compressImage(file, 1200, 0.82);
        const fd = new FormData();
        fd.append('photo', blob, 'photo.jpg');
        fd.append('csrf_token', document.getElementById('csrfToken').value);

        placeholder.innerHTML = '<div class="photo-loading">Upload...</div>';
        const res = await fetch('api/photo_upload.php', {method:'POST', body:fd});
        const data = await res.json();

        if (data.path) {
            uploadedPhotos[which] = data.path;
            pathInput.value = data.path;
            preview.src = 'uploads/' + data.path;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
            if (clearBtn) clearBtn.style.display = 'block';
        } else {
            placeholder.innerHTML = '<span>Erreur upload</span>';
            placeholder.style.display = 'flex';
        }
    } catch(e) {
        placeholder.innerHTML = `<span>${e.message || 'Erreur'}</span>`;
        placeholder.style.display = 'flex';
    }
}

// Init on load
document.addEventListener('DOMContentLoaded', () => {
    updateProgress();
    showStep(1);

    // Photo upload listeners
    document.getElementById('photoAvantInput')?.addEventListener('change', function() {
        handlePhotoUpload(this, 'avant');
    });
    document.getElementById('photoApresInput')?.addEventListener('change', function() {
        handlePhotoUpload(this, 'apres');
    });

    // Radio button visual selection
    document.querySelectorAll('.type-btn input, .lieu-btn input, .payment-btn input, .toggle-option input').forEach(radio => {
        radio.addEventListener('change', () => {
            // Remove active from siblings
            const name = radio.name;
            document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                r.closest('.type-btn, .lieu-btn, .payment-btn, .toggle-option')?.classList.remove('selected');
            });
            radio.closest('.type-btn, .lieu-btn, .payment-btn, .toggle-option')?.classList.add('selected');
        });
    });

    // Auto-check facture_a_faire + pre-fill billing note when paiement=facture
    document.querySelectorAll('input[name="paiement"]').forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'facture') {
                const factureOui = document.querySelector('input[name="facture_a_faire"][value="1"]');
                if (factureOui) {
                    factureOui.checked = true;
                    document.querySelectorAll('input[name="facture_a_faire"]').forEach(r => {
                        r.closest('.toggle-option')?.classList.remove('selected');
                    });
                    factureOui.closest('.toggle-option')?.classList.add('selected');
                }
                const notesInput = document.getElementById('notesInput');
                if (notesInput && !notesInput.value.trim()) {
                    notesInput.value = 'Infos facturation : Nom complet, adresse, n° TVA';
                }
            }
        });
    });
});
