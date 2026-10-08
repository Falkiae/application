<?php
$db = getDB();
$techId = currentUserId();
$isAdm = isAdmin();

$filterMonth = $_GET['month'] ?? date('Y-m');
$filterTech = $isAdm ? (int)($_GET['tech'] ?? 0) : $techId;
$filterPayment = $_GET['payment'] ?? '';
$filterInvoice = $_GET['filter'] ?? ''; // 'pending' = à faire, 'sent' = envoyées
$filterType = (int)($_GET['type'] ?? 0);

// Build query
$where = ["strftime('%Y-%m', s.date) = ?"];
$params = [$filterMonth];
if (!$isAdm) {
    $where[] = "s.technician_id = ?";
    $params[] = $techId;
} elseif ($filterTech > 0) {
    $where[] = "s.technician_id = ?";
    $params[] = $filterTech;
}
if ($filterPayment) {
    $where[] = "s.paiement = ?";
    $params[] = $filterPayment;
}
if ($filterInvoice === 'pending') {
    $where[] = "s.facture_a_faire = 1 AND (s.facture_envoyee IS NULL OR s.facture_envoyee = 0)";
} elseif ($filterInvoice === 'sent') {
    $where[] = "s.facture_envoyee = 1";
}
if ($filterType > 0) {
    $where[] = "s.type_nettoyage_id = ?";
    $params[] = $filterType;
}
$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT s.id,
           s.receipt_no,
           s.date,
           s.ticket_tva,
           s.cancelled_at,
           s.cancelled_reason,
           s.supersedes_id,
           s.cancels_id,
           -- Effective values: if this original has a correction row, use its values
           COALESCE(corr.montant,       s.montant)       AS montant,
           COALESCE(corr.montant_htva,  s.montant_htva)  AS montant_htva,
           COALESCE(corr.montant_tva,   s.montant_tva)   AS montant_tva,
           COALESCE(corr.vat_rate,      s.vat_rate)      AS vat_rate,
           COALESCE(corr.lieu,          s.lieu)          AS lieu,
           COALESCE(corr.paiement,      s.paiement)      AS paiement,
           COALESCE(corr.notes,         s.notes)         AS notes,
           COALESCE(corr.technician_id, s.technician_id) AS display_technician_id,
           COALESCE(corr.facture_a_faire, s.facture_a_faire) AS facture_a_faire,
           COALESCE(corr.facture_envoyee, s.facture_envoyee) AS facture_envoyee,
           COALESCE(corr.facture_ref,    s.facture_ref)   AS facture_ref,
           COALESCE(corr.photo_avant,    s.photo_avant)   AS photo_avant,
           COALESCE(corr.photo_apres,    s.photo_apres)   AS photo_apres,
           -- Correction / annulation references (for badges)
           corr.id         AS correction_id,
           corr.receipt_no AS correction_receipt_no,
           corr.date       AS correction_date,
           annul.id         AS annulation_id,
           annul.receipt_no AS annulation_receipt_no,
           annul.date       AS annulation_date,
           -- Display tech / type based on effective technician_id and type_nettoyage_id
           t.name  AS tech_name,
           t.color AS tech_color,
           ct.label AS type_label
    FROM services s
    JOIN technicians t ON t.id = COALESCE((SELECT c.technician_id FROM services c WHERE c.supersedes_id = s.id LIMIT 1), s.technician_id)
    JOIN cleaning_types ct ON ct.id = COALESCE((SELECT c.type_nettoyage_id FROM services c WHERE c.supersedes_id = s.id LIMIT 1), s.type_nettoyage_id)
    LEFT JOIN services corr  ON corr.supersedes_id = s.id
    LEFT JOIN services annul ON annul.cancels_id   = s.id
    WHERE s.cancels_id IS NULL AND s.supersedes_id IS NULL
      AND $whereStr
    ORDER BY s.date DESC, s.receipt_no DESC
");
$stmt->execute($params);
$services = $stmt->fetchAll();

// Totals (effective values from the SQL above already):
//  - Count = logical prestations (soft-cancelled excluded, counter-entries hidden by the WHERE)
//  - Sum   = effective amounts (correction's montant if any, else original)
$totalMontant = 0;
$totalCount   = 0;
foreach ($services as $s) {
    if ($s['cancelled_at'] !== null) continue;  // soft-cancelled: 0 for stats
    $totalMontant += (float)$s['montant'];
    $totalCount   += 1;
}

// Technicians for filter (admin only)
$technicians = [];
if ($isAdm) {
    $technicians = $db->query("SELECT id, name, color FROM technicians WHERE active=1 ORDER BY name")->fetchAll();
}

// Service types for filter
$allTypes = $db->query("SELECT id, label FROM cleaning_types WHERE active=1 ORDER BY sort_order, label")->fetchAll();

$paiementLabels = ['cash'=>'Cash','virement'=>'Virement','qrcode'=>'QR Code','facture'=>'Facture'];
$lieuLabels = ['domicile'=>'Domicile','atelier'=>'Atelier'];

// Generate month options (last 12 months)
$months = [];
$frMonths = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
for ($i = 0; $i < 12; $i++) {
    $dt = new DateTime("first day of -$i month");
    $label = ucfirst($frMonths[(int)$dt->format('n') - 1]) . ' ' . $dt->format('Y');
    $months[] = ['value' => $dt->format('Y-m'), 'label' => $label];
}
?>

<div class="prestations-page">
    <!-- Filters -->
    <div class="filters-bar">
        <select class="form-select filter-select" id="filterMonth" onchange="applyFilters()">
            <?php foreach ($months as $m): ?>
            <option value="<?= $m['value'] ?>" <?= $m['value'] === $filterMonth ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['label']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($isAdm): ?>
        <select class="form-select filter-select" id="filterTech" onchange="applyFilters()">
            <option value="0">Tous les techniciens</option>
            <?php foreach ($technicians as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filterTech == $t['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select class="form-select filter-select" id="filterPayment" onchange="applyFilters()">
            <option value="">Tout paiement</option>
            <option value="cash" <?= $filterPayment==='cash'?'selected':'' ?>>Cash</option>
            <option value="virement" <?= $filterPayment==='virement'?'selected':'' ?>>Virement</option>
            <option value="qrcode" <?= $filterPayment==='qrcode'?'selected':'' ?>>QR Code</option>
            <option value="facture" <?= $filterPayment==='facture'?'selected':'' ?>>Facture</option>
        </select>
        <select class="form-select filter-select" id="filterType" onchange="applyFilters()">
            <option value="0">Tous les types</option>
            <?php foreach ($allTypes as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filterType == $t['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['label']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select class="form-select filter-select" id="filterInvoice" onchange="applyFilters()">
            <option value="">Toutes</option>
            <option value="pending" <?= $filterInvoice==='pending'?'selected':'' ?>>Factures à faire</option>
            <option value="sent" <?= $filterInvoice==='sent'?'selected':'' ?>>Factures envoyées</option>
        </select>
    </div>

    <!-- Summary bar -->
    <div class="summary-bar">
        <span class="summary-count"><?= $totalCount ?> prestation<?= $totalCount > 1 ? 's' : '' ?></span>
        <span class="summary-total"><?= number_format($totalMontant, 2, ',', '.') ?> €</span>
    </div>

    <!-- Service list -->
    <?php if (empty($services)): ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <p>Aucune prestation pour cette période</p>
        <a href="index.php?page=prestation_new" class="btn btn-primary btn-sm">Ajouter une prestation</a>
    </div>
    <?php else: ?>
    <div class="service-list">
        <?php foreach ($services as $s):
            $isSoftCancelled = $s['cancelled_at'] !== null;
            $hasCorrection   = $s['correction_id'] !== null;
            $hasAnnulation   = $s['annulation_id'] !== null && !$hasCorrection;  // bare cancel
            // The row the user should land on when clicking: correction if any, else self
            $clickId = $hasCorrection ? (int)$s['correction_id'] : (int)$s['id'];
            $cardCls = $isSoftCancelled ? 'svc-cancelled' : '';
        ?>
        <div class="service-card service-card-full <?= $cardCls ?>" id="sc-<?= $s['id'] ?>">
            <div class="service-card-main" onclick="window.location='index.php?page=prestation_edit&id=<?= $clickId ?>'">
                <div class="service-card-left">
                    <div class="tech-avatar tech-avatar-sm" style="background:<?= htmlspecialchars($s['tech_color']) ?>">
                        <?= strtoupper(substr($s['tech_name'], 0, 1)) ?>
                    </div>
                    <div class="service-info">
                        <div class="service-type">
                            <?= htmlspecialchars($s['type_label']) ?>
                            <?php if ($s['receipt_no']): ?>
                            <span class="svc-receipt-no">#<?= htmlspecialchars($s['receipt_no']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="service-meta">
                            <?= htmlspecialchars(date('d/m/Y', strtotime($s['date']))) ?>
                            · <?= $lieuLabels[$s['lieu']] ?? $s['lieu'] ?>
                            <?php if ($isAdm): ?> · <strong><?= htmlspecialchars($s['tech_name']) ?></strong><?php endif; ?>
                            <?php if ($hasCorrection): ?>
                            · <span class="svc-corrected-mark" title="Modifiée le <?= date('d/m/Y', strtotime($s['correction_date'])) ?> par la recette <?= htmlspecialchars($s['correction_receipt_no']) ?>">✎ Modifiée le <?= date('d/m/Y', strtotime($s['correction_date'])) ?></span>
                            <?php endif; ?>
                            <?php if ($hasAnnulation): ?>
                            · <span class="svc-corrected-mark" style="color:#991b1b" title="Annulée le <?= date('d/m/Y', strtotime($s['annulation_date'])) ?> par la recette <?= htmlspecialchars($s['annulation_receipt_no']) ?>">✕ Annulée le <?= date('d/m/Y', strtotime($s['annulation_date'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($s['notes']): ?>
                        <div class="service-notes"><?= htmlspecialchars(mb_substr($s['notes'], 0, 60)) ?><?= strlen($s['notes']) > 60 ? '…' : '' ?></div>
                        <?php endif; ?>
                        <div class="service-badges">
                            <span class="badge badge-<?= $s['paiement'] ?>"><?= $paiementLabels[$s['paiement']] ?? $s['paiement'] ?></span>
                            <?php if ($s['ticket_tva']): ?><span class="badge badge-tva">TVA</span><?php endif; ?>
                            <?php if ($s['facture_a_faire'] && !$s['facture_envoyee']): ?>
                            <span class="badge badge-invoice">Facture à faire</span>
                            <?php elseif ($s['facture_envoyee']): ?>
                            <span class="badge badge-invoice-sent">Facture envoyée</span>
                            <?php endif; ?>
                            <?php if ($isSoftCancelled): ?>
                            <span class="badge badge-cancel">Annulée</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="service-card-right">
                    <div class="service-amount"><?= number_format($s['montant'], 2, ',', '.') ?> €</div>
                    <?php if ($s['photo_avant'] || $s['photo_apres']): ?>
                    <div class="service-photos-indicator">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="service-card-actions">
                <?php if ($s['facture_a_faire'] && !$s['facture_envoyee']): ?>
                <button class="btn btn-invoice-send btn-sm" onclick="markFactureEnvoyee(event, <?= $s['id'] ?>)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
                    Facture envoyée
                </button>
                <?php elseif ($s['facture_envoyee']): ?>
                <button class="btn btn-invoice-undo btn-sm" onclick="markFactureEnvoyee(event, <?= $s['id'] ?>, 0)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                    Annuler envoi
                </button>
                <?php endif; ?>
                <a href="index.php?page=prestation_edit&id=<?= $s['id'] ?>" class="btn-icon btn-edit" title="Modifier">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
                <button class="btn-icon btn-delete" title="Supprimer"
                        onclick="deleteService(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['type_label'])) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Confirm invoice sent modal -->
<div class="modal-overlay" id="confirmSentModal">
    <div class="modal">
        <div class="modal-icon modal-icon-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
        </div>
        <h3 class="modal-title">Marquer la facture comme envoyée ?</h3>
        <p class="modal-body">Confirmez que la facture a bien été envoyée au client.</p>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="document.getElementById('confirmSentModal').classList.remove('open')">Annuler</button>
            <button class="btn btn-primary" id="confirmSentOk">Confirmer</button>
        </div>
    </div>
</div>

<!-- Confirm undo sent modal -->
<div class="modal-overlay" id="confirmUndoModal">
    <div class="modal">
        <div class="modal-icon modal-icon-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
        </div>
        <h3 class="modal-title">Annuler l'envoi de la facture ?</h3>
        <p class="modal-body">La facture repassera en statut "à faire".</p>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="document.getElementById('confirmUndoModal').classList.remove('open')">Annuler</button>
            <button class="btn btn-danger" id="confirmUndoOk">Confirmer</button>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-icon modal-icon-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        </div>
        <h3 class="modal-title">Annuler la prestation ?</h3>
        <p class="modal-body" id="deleteModalBody">La ligne restera dans le journal mais sera marquée annulée.</p>
        <div class="form-group" style="margin:12px 0;text-align:left;">
            <label class="form-label" for="deleteReason">Motif <span style="color:#dc2626;">*</span></label>
            <textarea id="deleteReason" class="form-input" rows="2" maxlength="500"
                placeholder="Pourquoi annuler cette prestation ? (obligatoire)"></textarea>
        </div>
        <div id="deleteError" class="alert alert-error" style="display:none;"></div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeDeleteModal()">Fermer</button>
            <button class="btn btn-danger" id="confirmDelete">Annuler la prestation</button>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const month = document.getElementById('filterMonth').value;
    const tech = document.getElementById('filterTech')?.value || '0';
    const payment = document.getElementById('filterPayment').value;
    const type = document.getElementById('filterType').value;
    const invoice = document.getElementById('filterInvoice').value;
    let url = `index.php?page=prestations&month=${month}`;
    if (tech !== '0') url += `&tech=${tech}`;
    if (payment) url += `&payment=${payment}`;
    if (type !== '0') url += `&type=${type}`;
    if (invoice) url += `&filter=${invoice}`;
    window.location.href = url;
}

let deleteId = null;
function deleteService(id, label) {
    deleteId = id;
    document.getElementById('deleteModalBody').textContent = `La prestation "${label}" sera marquée annulée (elle reste dans le journal pour la traçabilité comptable).`;
    document.getElementById('deleteReason').value = '';
    document.getElementById('deleteError').style.display = 'none';
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    deleteId = null;
}
document.getElementById('confirmDelete').addEventListener('click', async function() {
    if (!deleteId) return;
    const reason = document.getElementById('deleteReason').value.trim();
    const errEl = document.getElementById('deleteError');
    errEl.style.display = 'none';
    if (!reason) {
        errEl.textContent = 'Le motif est obligatoire.';
        errEl.style.display = 'block';
        document.getElementById('deleteReason').focus();
        return;
    }
    this.disabled = true;
    this.textContent = 'Annulation…';
    const fd = new FormData();
    fd.append('id', deleteId);
    fd.append('reason', reason);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/prestation_delete.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        window.location.reload();
    } else if (data.sealed) {
        closeDeleteModal();
        alert((data.error || 'Journée clôturée') + '\n\nRedirection vers la page de correction : utilisez le bouton « Contrepasser ».');
        window.location.href = 'index.php?page=prestation_edit&id=' + deleteId;
    } else {
        errEl.textContent = data.error || 'Erreur lors de l\'annulation';
        errEl.style.display = 'block';
        this.disabled = false;
        this.textContent = 'Annuler la prestation';
    }
});

let _fBtn = null, _fId = null, _fVal = 1;

function markFactureEnvoyee(e, id, envoyee = 1) {
    e.stopPropagation();
    _fBtn = e.currentTarget;
    _fId  = id;
    _fVal = envoyee;
    if (envoyee === 1) {
        document.getElementById('confirmSentModal').classList.add('open');
    } else {
        // "Annuler envoi" : confirmation simple aussi
        document.getElementById('confirmUndoModal').classList.add('open');
    }
}

document.getElementById('confirmSentOk').addEventListener('click', async function() {
    document.getElementById('confirmSentModal').classList.remove('open');
    await _doMark();
});
document.getElementById('confirmUndoOk').addEventListener('click', async function() {
    document.getElementById('confirmUndoModal').classList.remove('open');
    await _doMark();
});

async function _doMark() {
    if (!_fId) return;
    _fBtn.disabled = true;
    const fd = new FormData();
    fd.append('id', _fId);
    fd.append('envoyee', _fVal);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/facture_mark.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { window.location.reload(); }
    else { alert(data.error || 'Erreur'); _fBtn.disabled = false; }
}
</script>
