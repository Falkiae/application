<?php
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php?page=prestations'); exit; }

$stmt = $db->prepare("
    SELECT s.*, ct.label as type_label
    FROM services s
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) { header('Location: index.php?page=prestations'); exit; }

// Non-admin can only edit own services
if (!isAdmin() && $service['technician_id'] != currentUserId()) {
    header('Location: index.php?page=prestations'); exit;
}

$cleaningTypes = $db->query("SELECT id, label FROM cleaning_types WHERE active=1 ORDER BY sort_order, label")->fetchAll();

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
                            <img src="uploads/<?= htmlspecialchars($service['photo_avant']) ?>" class="photo-thumb" id="editAvantImg" alt="Photo avant">
                            <?php else: ?>
                            <div class="photo-placeholder-sm" id="editAvantPlaceholder">Aucune</div>
                            <img id="editAvantImg" class="photo-thumb" style="display:none" alt="Photo avant">
                            <?php endif; ?>
                            <label class="btn btn-outline btn-sm btn-camera-sm" for="editPhotoAvantInput">Changer</label>
                            <input type="file" id="editPhotoAvantInput" accept="image/*" capture="environment" class="photo-file-input">
                            <?php if ($service['photo_avant']): ?>
                            <button type="button" class="btn-text-danger btn-sm" onclick="clearEditPhoto('avant')">Supprimer</button>
                            <?php endif; ?>
                        </div>
                        <div class="photo-edit-item">
                            <label class="photo-label">Après</label>
                            <?php if ($service['photo_apres']): ?>
                            <img src="uploads/<?= htmlspecialchars($service['photo_apres']) ?>" class="photo-thumb" id="editApresImg" alt="Photo après">
                            <?php else: ?>
                            <div class="photo-placeholder-sm" id="editApresPlaceholder">Aucune</div>
                            <img id="editApresImg" class="photo-thumb" style="display:none" alt="Photo après">
                            <?php endif; ?>
                            <label class="btn btn-outline btn-sm btn-camera-sm" for="editPhotoApresInput">Changer</label>
                            <input type="file" id="editPhotoApresInput" accept="image/*" capture="environment" class="photo-file-input">
                            <?php if ($service['photo_apres']): ?>
                            <button type="button" class="btn-text-danger btn-sm" onclick="clearEditPhoto('apres')">Supprimer</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="editError" class="alert alert-error" style="display:none"></div>
                <div id="editSuccess" class="alert alert-success" style="display:none"></div>

                <div class="edit-actions">
                    <a href="index.php?page=prestations" class="btn btn-outline">Annuler</a>
                    <button type="button" class="btn btn-primary" onclick="saveEdit(this)">Enregistrer les modifications</button>
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
                                    $fieldLabels = ['date'=>'Date','type_nettoyage_id'=>'Type','lieu'=>'Lieu','ticket_tva'=>'Ticket TVA','paiement'=>'Paiement','facture_a_faire'=>'Facture','montant'=>'Montant','notes'=>'Notes'];
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
    });
});
</script>
