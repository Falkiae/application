<?php
require_once __DIR__ . '/../lib/compliance.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php?page=prestations'); exit; }

$stmt = $db->prepare("
    SELECT s.*, ct.label as type_label,
           o1.receipt_no AS cancels_receipt_no,
           o2.receipt_no AS supersedes_receipt_no
    FROM services s
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    LEFT JOIN services o1 ON o1.id = s.cancels_id
    LEFT JOIN services o2 ON o2.id = s.supersedes_id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) { header('Location: index.php?page=prestations'); exit; }

// Non-admin can only edit own services
if (!isAdmin() && $service['technician_id'] != currentUserId()) {
    header('Location: index.php?page=prestations'); exit;
}

// Compliance context: is this row's date sealed? Is this row itself reversed/cancelled?
$sealed = complianceIsDateSealed($service['date']);
$check = $db->prepare("SELECT receipt_no FROM services WHERE cancels_id = ? LIMIT 1");
$check->execute([$id]);
$reversedByReceipt = $check->fetchColumn() ?: null;
$isCancelled = $service['cancelled_at'] !== null;

$cleaningTypes = $db->query("SELECT id, label FROM cleaning_types WHERE active=1 ORDER BY sort_order, label")->fetchAll();

// If the service's type was deactivated, add it to the list so it gets correctly pre-selected
$activeTypeIds = array_map('intval', array_column($cleaningTypes, 'id'));
if (!in_array((int)$service['type_nettoyage_id'], $activeTypeIds)) {
    $stmt = $db->prepare("SELECT id, label FROM cleaning_types WHERE id = ?");
    $stmt->execute([$service['type_nettoyage_id']]);
    $inactiveType = $stmt->fetch();
    if ($inactiveType) {
        array_unshift($cleaningTypes, [
            'id'    => $inactiveType['id'],
            'label' => $inactiveType['label'] . ' (inactif)',
        ]);
    }
}
$technicians = $db->query("SELECT id, name FROM technicians WHERE active=1 ORDER BY name")->fetchAll();

// Fetch history
$histStmt = $db->prepare("
    SELECT sh.*, t.name as tech_name
    FROM service_history sh
    JOIN technicians t ON t.id = sh.technician_id
    WHERE sh.service_id = ?
    ORDER BY sh.created_at DESC
    LIMIT 20
");
$histStmt->execute([$id]);
$history = $histStmt->fetchAll();

$paiementLabels = ['cash'=>'Cash','virement'=>'Virement','qrcode'=>'QR Code','facture'=>'Sur facture'];
$actionLabels = ['create'=>'Créé','update'=>'Modifié','delete'=>'Supprimé'];
?>

<div class="edit-page">
    <?php if ($service['receipt_no']): ?>
    <div class="livre-receipt-badge">N° recette : <strong><?= htmlspecialchars($service['receipt_no']) ?></strong></div>
    <?php endif; ?>

    <?php if ($isCancelled): ?>
    <div class="livre-warn" style="background:#fee2e2;color:#991b1b;border-color:#f87171;">
        <strong>Prestation annulée</strong> le <?= date('d/m/Y H:i', strtotime($service['cancelled_at'])) ?>
        — motif : <em><?= htmlspecialchars($service['cancelled_reason'] ?? '') ?></em>.
        Cette prestation est en lecture seule.
    </div>
    <?php elseif ($reversedByReceipt): ?>
    <div class="livre-warn" style="background:#fef3c7;color:#78350f;border-color:#fbbf24;">
        <strong>Prestation contrepassée</strong> par la recette <strong>#<?= htmlspecialchars($reversedByReceipt) ?></strong>.
        Les totaux restent cohérents grâce à la contre-écriture. Lecture seule.
    </div>
    <?php elseif ($sealed): ?>
    <div class="livre-warn" style="background:#fef3c7;color:#78350f;border-color:#fbbf24;">
        <strong>Journée clôturée (<?= date('d/m/Y', strtotime($service['date'])) ?>)</strong>.
        Les champs comptables (date, montant, paiement, type, lieu, taux TVA) sont verrouillés.
        Vous pouvez encore modifier les notes et photos. Pour corriger ou annuler cette prestation,
        utilisez les boutons <strong>Contrepasser</strong> ou <strong>Annuler</strong> en bas de page.
    </div>
    <?php endif; ?>

    <?php if ($service['cancels_id']): ?>
    <div class="livre-warn" style="background:#fecaca;color:#991b1b;border-color:#f87171;">
        Ligne d'<strong>annulation</strong> de la recette <strong>#<?= htmlspecialchars($service['cancels_receipt_no']) ?></strong>.
    </div>
    <?php endif; ?>
    <?php if ($service['supersedes_id']): ?>
    <div class="livre-warn" style="background:#dcfce7;color:#166534;border-color:#4ade80;">
        Ligne de <strong>correction</strong> de la recette <strong>#<?= htmlspecialchars($service['supersedes_receipt_no']) ?></strong>.
    </div>
    <?php endif; ?>

    <form id="editForm">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="photo_avant_path" id="editPhotoAvantPath" value="<?= htmlspecialchars($service['photo_avant'] ?? '') ?>">
        <input type="hidden" name="photo_apres_path" id="editPhotoApresPath" value="<?= htmlspecialchars($service['photo_apres'] ?? '') ?>">
        <input type="hidden" name="facture_envoyee" id="editFactureEnvoyee" value="<?= (int)($service['facture_envoyee'] ?? 0) ?>">

        <div class="edit-grid">
            <!-- Left column: form -->
            <div class="edit-form-col">
                <div class="section-card">
                    <h3 class="card-section-title">Informations</h3>

                    <div class="form-group">
                        <label class="form-label">Date</label>
                        <input type="date" name="date" class="form-input" value="<?= htmlspecialchars($service['date']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Technicien</label>
                        <select name="technician_id" class="form-select" required>
                            <?php foreach ($technicians as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $t['id'] == $service['technician_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Type de nettoyage</label>
                        <select name="type_nettoyage_id" class="form-select" required>
                            <?php foreach ($cleaningTypes as $ct): ?>
                            <option value="<?= $ct['id'] ?>" <?= $ct['id'] == $service['type_nettoyage_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ct['label']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Lieu</label>
                        <div class="radio-group">
                            <label class="radio-label">
                                <input type="radio" name="lieu" value="domicile" <?= $service['lieu']==='domicile'?'checked':'' ?>> Domicile
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="lieu" value="atelier" <?= $service['lieu']==='atelier'?'checked':'' ?>> Atelier
                            </label>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Ticket TVA</label>
                            <label class="toggle-switch">
                                <input type="checkbox" name="ticket_tva" value="1" id="editTva" <?= $service['ticket_tva']?'checked':'' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Facture à faire</label>
                            <label class="toggle-switch">
                                <input type="checkbox" name="facture_a_faire" value="1" id="editFacture" <?= $service['facture_a_faire']?'checked':'' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <?php if ($service['facture_a_faire']): ?>
                    <div class="form-group" id="factureEnvoyeeRow">
                        <label class="form-label">Facture envoyée</label>
                        <label class="toggle-switch">
                            <input type="checkbox" id="editFactureEnvoyeeChk" <?= ($service['facture_envoyee'] ?? 0) ? 'checked' : '' ?> onchange="document.getElementById('editFactureEnvoyee').value=this.checked?1:0">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Paiement</label>
                        <div class="radio-group radio-group-payment">
                            <?php foreach ($paiementLabels as $val => $label): ?>
                            <label class="radio-badge radio-badge-<?= $val ?>">
                                <input type="radio" name="paiement" value="<?= $val ?>" <?= $service['paiement']===$val?'checked':'' ?>>
                                <?= $label ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Montant (€)</label>
                        <div class="amount-input-wrapper">
                            <input type="number" name="montant" class="form-input amount-input"
                                   value="<?= $service['montant'] ?>" min="0" step="0.01" required>
                            <span class="amount-currency">€</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-input form-textarea" rows="3"><?= htmlspecialchars($service['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Photos -->
                <div class="section-card">
                    <h3 class="card-section-title">Photos</h3>
                    <div class="photos-edit-grid">
                        <div class="photo-edit-item">
                            <label class="photo-label">Avant</label>
                            <?php if ($service['photo_avant']): ?>
                            <img src="uploads/<?= htmlspecialchars($service['photo_avant']) ?>" class="photo-thumb photo-thumb-clickable" id="editAvantImg" alt="Photo avant"
                                 onclick="openLightbox('uploads/<?= htmlspecialchars($service['photo_avant']) ?>', 'Photo avant')">
                            <?php else: ?>
                            <div class="photo-placeholder-sm" id="editAvantPlaceholder">Aucune</div>
                            <img id="editAvantImg" class="photo-thumb photo-thumb-clickable" style="display:none" alt="Photo avant"
                                 onclick="openLightbox(this.src, 'Photo avant')">
                            <?php endif; ?>
                            <label class="btn btn-outline btn-sm btn-camera-sm" for="editPhotoAvantInput">Changer</label>
                            <input type="file" id="editPhotoAvantInput" accept="image/*" class="photo-file-input">
                            <?php if ($service['photo_avant']): ?>
                            <button type="button" class="btn-text-danger btn-sm" onclick="clearEditPhoto('avant')">Supprimer</button>
                            <?php endif; ?>
                        </div>
                        <div class="photo-edit-item">
                            <label class="photo-label">Après</label>
                            <?php if ($service['photo_apres']): ?>
                            <img src="uploads/<?= htmlspecialchars($service['photo_apres']) ?>" class="photo-thumb photo-thumb-clickable" id="editApresImg" alt="Photo après"
                                 onclick="openLightbox('uploads/<?= htmlspecialchars($service['photo_apres']) ?>', 'Photo après')">
                            <?php else: ?>
                            <div class="photo-placeholder-sm" id="editApresPlaceholder">Aucune</div>
                            <img id="editApresImg" class="photo-thumb photo-thumb-clickable" style="display:none" alt="Photo après"
                                 onclick="openLightbox(this.src, 'Photo après')">
                            <?php endif; ?>
                            <label class="btn btn-outline btn-sm btn-camera-sm" for="editPhotoApresInput">Changer</label>
                            <input type="file" id="editPhotoApresInput" accept="image/*" class="photo-file-input">
                            <?php if ($service['photo_apres']): ?>
                            <button type="button" class="btn-text-danger btn-sm" onclick="clearEditPhoto('apres')">Supprimer</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="editError" class="alert alert-error" style="display:none"></div>
                <div id="editSuccess" class="alert alert-success" style="display:none"></div>

                <div class="edit-actions">
                    <a href="index.php?page=prestations" class="btn btn-outline">Retour</a>
                    <?php if (!$isCancelled && !$reversedByReceipt): ?>
                    <button type="button" class="btn btn-primary" onclick="saveEdit(this)"
                        <?= $sealed ? 'title="Journée clôturée — seules les notes/photos seront sauvegardées"' : '' ?>>
                        Enregistrer les modifications
                    </button>
                    <?php if ($sealed): ?>
                    <button type="button" class="btn btn-warning" onclick="openReverseModal('supersede')">Contrepasser (corriger)</button>
                    <button type="button" class="btn btn-danger" onclick="openReverseModal('cancel')">Annuler cette prestation</button>
                    <?php else: ?>
                    <button type="button" class="btn btn-danger" onclick="openDeleteModal()">Supprimer</button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right column: history -->
            <div class="edit-history-col">
                <div class="section-card">
                    <h3 class="card-section-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Historique
                    </h3>
                    <?php if (empty($history)): ?>
                    <p class="text-muted">Aucun historique disponible</p>
                    <?php else: ?>
                    <div class="history-list">
                        <?php foreach ($history as $h): ?>
                        <div class="history-item">
                            <div class="history-icon history-<?= $h['action'] ?>">
                                <?php if ($h['action'] === 'create'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                <?php elseif ($h['action'] === 'update'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="history-info">
                                <div class="history-action">
                                    <?= $actionLabels[$h['action']] ?? $h['action'] ?> par <strong><?= htmlspecialchars($h['tech_name']) ?></strong>
                                </div>
                                <?php if ($h['changed_fields']):
                                    $fields = json_decode($h['changed_fields'], true) ?? [];
                                    $fieldLabels = ['date'=>'Date','type_nettoyage_id'=>'Type','lieu'=>'Lieu','ticket_tva'=>'Ticket TVA','paiement'=>'Paiement','facture_a_faire'=>'Facture','montant'=>'Montant','notes'=>'Notes','technician_id'=>'Technicien'];
                                    $readable = array_map(fn($f) => $fieldLabels[$f] ?? $f, $fields);
                                ?>
                                <div class="history-fields">Modifié: <?= implode(', ', $readable) ?></div>
                                <?php endif; ?>
                                <div class="history-date"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
async function saveEdit(btn) {
    const form = document.getElementById('editForm');
    const err = document.getElementById('editError');
    const suc = document.getElementById('editSuccess');
    err.style.display = 'none';
    suc.style.display = 'none';

    // Handle checkbox -> hidden fields
    const tva = document.getElementById('editTva');
    const facture = document.getElementById('editFacture');

    const fd = new FormData(form);
    // Ensure unchecked checkboxes send 0
    if (!tva.checked) fd.set('ticket_tva', '0');
    if (!facture.checked) fd.set('facture_a_faire', '0');

    btn.disabled = true;
    btn.textContent = 'Enregistrement...';

    try {
        const res = await fetch('api/prestation_save.php', {method:'POST', body: fd});
        const data = await res.json();
        if (data.success) {
            suc.textContent = 'Modifications enregistrées avec succès !';
            suc.style.display = 'block';
            setTimeout(() => window.location.href = 'index.php?page=prestations', 1500);
        } else if (data.sealed) {
            err.innerHTML = (data.error || '') + '<br><em>Astuce : utilisez le bouton <strong>Contrepasser</strong> ci-dessous pour créer une correction.</em>';
            err.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Enregistrer les modifications';
        } else {
            err.textContent = data.error || 'Erreur lors de l\'enregistrement';
            err.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Enregistrer les modifications';
        }
    } catch(e) {
        err.textContent = 'Erreur réseau';
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Enregistrer les modifications';
    }
}

function clearEditPhoto(which) {
    const pathInput = document.getElementById(which === 'avant' ? 'editPhotoAvantPath' : 'editPhotoApresPath');
    pathInput.value = '__deleted__';
    const img = document.getElementById(which === 'avant' ? 'editAvantImg' : 'editApresImg');
    img.style.display = 'none';
}

// Handle edit photo uploads
['editPhotoAvantInput','editPhotoApresInput'].forEach(inputId => {
    const input = document.getElementById(inputId);
    const which = inputId.includes('Avant') ? 'avant' : 'apres';
    input.addEventListener('change', async function() {
        if (!this.files[0]) return;
        try {
            const blob = await compressImage(this.files[0], 1200, 0.82);
            const fd = new FormData();
            fd.append('photo', blob, 'photo.jpg');
            fd.append('csrf_token', document.getElementById('csrfToken').value);
            const res = await fetch('api/photo_upload.php', {method:'POST', body:fd});
            const data = await res.json();
            if (data.path) {
                const pathInput = document.getElementById(which === 'avant' ? 'editPhotoAvantPath' : 'editPhotoApresPath');
                pathInput.value = data.path;
                const img = document.getElementById(which === 'avant' ? 'editAvantImg' : 'editApresImg');
                img.src = 'uploads/' + data.path;
                img.style.display = 'block';
            }
        } catch(e) {
            alert(e.message || 'Erreur lors du chargement de la photo.');
        }
    });
});

// Lightbox
function openLightbox(src, label) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightboxLabel').textContent = label;
    document.getElementById('lightboxDownload').href = src;
    // Derive filename from path
    const filename = src.split('/').pop();
    document.getElementById('lightboxDownload').download = filename;
    document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.getElementById('lightboxImg').src = '';
}
document.getElementById('lightbox').addEventListener('click', function(e) {
    if (e.target === this) closeLightbox();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});
</script>

<!-- Reverse / Delete modals -->
<div class="modal-overlay" id="reverseModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 id="reverseTitle">Contrepasser cette prestation</h3>
            <button class="modal-close" onclick="closeReverseModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p id="reverseExplain" style="font-size:.88rem;color:var(--gray-600);"></p>
            <div class="form-group">
                <label class="form-label">Motif <span style="color:#dc2626;">*</span></label>
                <textarea id="reverseReason" class="form-input" rows="3" placeholder="Expliquez brièvement la raison (obligatoire)" maxlength="500"></textarea>
            </div>
            <div id="reverseError" class="alert alert-error" style="display:none;"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeReverseModal()">Annuler</button>
            <button class="btn btn-primary" id="reverseConfirmBtn" onclick="confirmReverse()">Confirmer</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-icon modal-icon-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
        </div>
        <h3 class="modal-title">Annuler cette prestation ?</h3>
        <div class="form-group" style="margin:12px 0;">
            <label class="form-label">Motif <span style="color:#dc2626;">*</span></label>
            <textarea id="deleteReason" class="form-input" rows="2" maxlength="500" placeholder="Motif de l'annulation (obligatoire)"></textarea>
        </div>
        <div id="deleteError" class="alert alert-error" style="display:none;"></div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeDeleteModal()">Annuler</button>
            <button class="btn btn-danger" id="deleteConfirmBtn" onclick="confirmDelete()">Supprimer</button>
        </div>
    </div>
</div>

<script>
let reverseMode = 'cancel';

function openReverseModal(mode) {
    reverseMode = mode;
    const isSupersede = mode === 'supersede';
    document.getElementById('reverseTitle').textContent = isSupersede
        ? 'Contrepasser (corriger) cette prestation'
        : 'Annuler cette prestation (contrepassation)';
    document.getElementById('reverseExplain').innerHTML = isSupersede
        ? 'La prestation originale restera dans son jour clôturé. Deux nouvelles lignes seront créées <strong>dans la journée courante</strong> : une annulation (−montant) et une correction avec les valeurs du formulaire ci-dessus.'
        : 'La prestation originale restera dans son jour clôturé. Une nouvelle ligne d\'annulation (−montant) sera créée <strong>dans la journée courante</strong>.';
    document.getElementById('reverseReason').value = '';
    document.getElementById('reverseError').style.display = 'none';
    document.getElementById('reverseModal').classList.add('open');
}
function closeReverseModal() {
    document.getElementById('reverseModal').classList.remove('open');
}
async function confirmReverse() {
    const reason = document.getElementById('reverseReason').value.trim();
    const errEl = document.getElementById('reverseError');
    const btn = document.getElementById('reverseConfirmBtn');
    errEl.style.display = 'none';
    if (!reason) { errEl.textContent = 'Le motif est obligatoire.'; errEl.style.display = 'block'; return; }

    btn.disabled = true;
    const fd = new FormData();
    fd.append('id', '<?= $id ?>');
    fd.append('mode', reverseMode);
    fd.append('reason', reason);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    if (reverseMode === 'supersede') {
        // Reuse current form values as the correction payload
        const form = document.getElementById('editForm');
        new FormData(form).forEach((v, k) => {
            if (k !== 'id' && k !== 'action' && k !== 'csrf_token') fd.append(k, v);
        });
    }
    try {
        const res = await fetch('api/prestation_reverse.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) {
            alert('Contrepassation effectuée. Nouvelles recettes : ' +
                (d.annulation_receipt || '') + (d.correction_receipt ? ' + ' + d.correction_receipt : ''));
            window.location.href = 'index.php?page=prestations';
        } else {
            errEl.textContent = d.error || 'Erreur';
            errEl.style.display = 'block';
            btn.disabled = false;
        }
    } catch (e) {
        errEl.textContent = 'Erreur réseau';
        errEl.style.display = 'block';
        btn.disabled = false;
    }
}

function openDeleteModal() {
    document.getElementById('deleteReason').value = '';
    document.getElementById('deleteError').style.display = 'none';
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
}
async function confirmDelete() {
    const reason = document.getElementById('deleteReason').value.trim();
    const errEl = document.getElementById('deleteError');
    const btn = document.getElementById('deleteConfirmBtn');
    errEl.style.display = 'none';
    if (!reason) { errEl.textContent = 'Le motif est obligatoire.'; errEl.style.display = 'block'; return; }

    btn.disabled = true;
    const fd = new FormData();
    fd.append('id', '<?= $id ?>');
    fd.append('reason', reason);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    try {
        const res = await fetch('api/prestation_delete.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) {
            window.location.href = 'index.php?page=prestations';
        } else if (d.sealed) {
            // Date was sealed between page load and submit → fall back to reverse
            closeDeleteModal();
            alert(d.error);
            openReverseModal('cancel');
        } else {
            errEl.textContent = d.error || 'Erreur';
            errEl.style.display = 'block';
            btn.disabled = false;
        }
    } catch (e) {
        errEl.textContent = 'Erreur réseau';
        errEl.style.display = 'block';
        btn.disabled = false;
    }
}
</script>

<!-- Lightbox -->
<div class="lightbox" id="lightbox">
    <div class="lightbox-inner">
        <div class="lightbox-header">
            <span class="lightbox-label" id="lightboxLabel"></span>
            <div class="lightbox-actions">
                <a class="btn btn-outline btn-sm lightbox-dl" id="lightboxDownload" href="#" download>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Télécharger
                </a>
                <button class="lightbox-close" onclick="closeLightbox()" aria-label="Fermer">&times;</button>
            </div>
        </div>
        <img id="lightboxImg" class="lightbox-img" src="" alt="">
    </div>
</div>
