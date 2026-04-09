<?php
$db = getDB();
$techId = currentUserId();
$isAdm = isAdmin();

// Get all active technicians (admin) or just self
if ($isAdm) {
    $techs = $db->query("SELECT id, name, color FROM technicians WHERE active=1 ORDER BY name")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT id, name, color FROM technicians WHERE id=?");
    $stmt->execute([$techId]);
    $techs = $stmt->fetchAll();
}

// Build cash summary per technician
$cashSummary = [];
foreach ($techs as $tech) {
    $tid = $tech['id'];

    // Single query for all breakdown totals
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='initial' THEN montant ELSE 0 END), 0) as initial,
            COALESCE(ABS(SUM(CASE WHEN type='depot_banque' THEN montant ELSE 0 END)), 0) as depot_banque,
            COALESCE(ABS(SUM(CASE WHEN type='achat_liquide' THEN montant ELSE 0 END)), 0) as achats,
            COALESCE(SUM(montant), 0) as total_mvt
        FROM cash_movements WHERE technician_id=?
    ");
    $stmt->execute([$tid]);
    $totals = $stmt->fetch();

    $stmt = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM services WHERE technician_id=? AND paiement='cash'");
    $stmt->execute([$tid]);
    $cashIn = (float)$stmt->fetchColumn();

    // Solde = total mouvements (initial - sorties) + recettes cash services
    $solde = (float)$totals['total_mvt'] + $cashIn;

    // Cash movements with id for edit/delete
    $stmt = $db->prepare("
        SELECT id, type, montant, notes, date FROM cash_movements
        WHERE technician_id=? ORDER BY date DESC, id DESC LIMIT 30
    ");
    $stmt->execute([$tid]);
    $cashMvts = $stmt->fetchAll();

    // Cash service entries (read-only in this list)
    $stmt = $db->prepare("
        SELECT s.id as sid, s.montant, COALESCE(s.notes,'Prestation cash') as notes, s.date
        FROM services s WHERE s.technician_id=? AND s.paiement='cash'
        ORDER BY s.date DESC, s.id DESC LIMIT 20
    ");
    $stmt->execute([$tid]);
    $cashServices = $stmt->fetchAll();

    $cashSummary[$tid] = [
        'tech'        => $tech,
        'initial'     => (float)$totals['initial'],
        'cash_in'     => $cashIn,
        'depot_banque'=> (float)$totals['depot_banque'],
        'achats'      => (float)$totals['achats'],
        'solde'       => $solde,
        'cash_mvts'   => $cashMvts,
        'cash_svcs'   => $cashServices,
    ];
}

$mvtTypeLabels = [
    'initial'       => 'Montant initial',
    'depot_banque'  => 'Versement banque',
    'achat_liquide' => 'Achat en liquide',
    'note'          => 'Note / ajustement',
];

// Global total (all technicians)
$globalTotal = array_sum(array_column(array_values($cashSummary), 'solde'));
?>

<div class="cash-page">
    <?php if ($isAdm): ?>
    <div class="section-card cash-global-card">
        <div class="cash-global-inner">
            <div class="cash-global-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/></svg>
            </div>
            <div class="cash-global-info">
                <span class="cash-global-label">Total liquidités (tous techniciens)</span>
                <span class="cash-global-amount <?= $globalTotal < 0 ? 'amount-red' : 'amount-green' ?>">
                    <?= number_format($globalTotal, 2, ',', '.') ?> €
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($cashSummary as $tid => $data): ?>
    <div class="section-card">
        <div class="cash-header">
            <div class="tech-avatar" style="background:<?= htmlspecialchars($data['tech']['color']) ?>">
                <?= strtoupper(substr($data['tech']['name'], 0, 1)) ?>
            </div>
            <div class="cash-tech-info">
                <h2 class="cash-tech-name"><?= htmlspecialchars($data['tech']['name']) ?></h2>
                <span class="cash-solde-badge <?= $data['solde'] < 0 ? 'solde-negative' : 'solde-positive' ?>">
                    <?= number_format($data['solde'], 2, ',', '.') ?> €
                </span>
            </div>
            <?php if ($isAdm || $tid == $techId): ?>
            <button class="btn btn-primary btn-sm" onclick="showCashForm(<?= $tid ?>)">+ Enregistrer</button>
            <?php endif; ?>
        </div>

        <div class="cash-breakdown">
            <div class="cash-breakdown-item">
                <span>Montant initial</span>
                <span class="amount-green">+ <?= number_format($data['initial'], 2, ',', '.') ?> €</span>
            </div>
            <div class="cash-breakdown-item">
                <span>Prestations cash</span>
                <span class="amount-green">+ <?= number_format($data['cash_in'], 2, ',', '.') ?> €</span>
            </div>
            <div class="cash-breakdown-item">
                <span>Versements banque</span>
                <span class="amount-red">- <?= number_format($data['depot_banque'], 2, ',', '.') ?> €</span>
            </div>
            <div class="cash-breakdown-item">
                <span>Achats en liquide</span>
                <span class="amount-red">- <?= number_format($data['achats'], 2, ',', '.') ?> €</span>
            </div>
            <div class="cash-breakdown-total">
                <span>Solde actuel</span>
                <span class="<?= $data['solde'] < 0 ? 'amount-red' : 'amount-green' ?>"><?= number_format($data['solde'], 2, ',', '.') ?> €</span>
            </div>
        </div>

        <?php $hasMvts = !empty($data['cash_mvts']) || !empty($data['cash_svcs']); ?>
        <?php if ($hasMvts): ?>
        <div class="movements-section">
            <h4 class="movements-title">Mouvements</h4>
            <div class="movements-list">

                <?php foreach ($data['cash_mvts'] as $m):
                    $isOut = $m['montant'] < 0;
                ?>
                <div class="movement-item" id="mvt-<?= $m['id'] ?>">
                    <div class="movement-icon <?= $isOut ? 'movement-out' : 'movement-in' ?>">
                        <?php if ($isOut): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="movement-info">
                        <span class="movement-type"><?= $mvtTypeLabels[$m['type']] ?? $m['type'] ?></span>
                        <?php if ($m['notes']): ?>
                        <span class="movement-note"><?= htmlspecialchars($m['notes']) ?></span>
                        <?php endif; ?>
                        <span class="movement-date"><?= date('d/m/Y', strtotime($m['date'])) ?></span>
                    </div>
                    <div class="movement-amount <?= $isOut ? 'amount-red' : 'amount-green' ?>">
                        <?= $isOut ? '-' : '+' ?><?= number_format(abs($m['montant']), 2, ',', '.') ?> €
                    </div>
                    <?php if ($isAdm || $tid == $techId): ?>
                    <div class="movement-actions">
                        <button class="btn-icon btn-edit" title="Modifier"
                            onclick="editMvt(<?= $m['id'] ?>, '<?= $m['type'] ?>', <?= abs($m['montant']) ?>, '<?= $m['date'] ?>', <?= json_encode($m['notes'] ?? '') ?>)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn-icon btn-delete" title="Supprimer"
                            onclick="deleteMvt(<?= $m['id'] ?>)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php foreach ($data['cash_svcs'] as $s): ?>
                <div class="movement-item movement-item-service">
                    <div class="movement-icon movement-in">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                    </div>
                    <div class="movement-info">
                        <span class="movement-type">Prestation cash</span>
                        <?php if ($s['notes']): ?>
                        <span class="movement-note"><?= htmlspecialchars(mb_substr($s['notes'], 0, 40)) ?></span>
                        <?php endif; ?>
                        <span class="movement-date"><?= date('d/m/Y', strtotime($s['date'])) ?></span>
                    </div>
                    <div class="movement-amount amount-green">
                        +<?= number_format($s['montant'], 2, ',', '.') ?> €
                    </div>
                    <div class="movement-actions">
                        <a href="index.php?page=prestation_edit&id=<?= $s['sid'] ?>" class="btn-icon btn-edit" title="Modifier la prestation">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add movement modal -->
<div class="modal-overlay" id="cashModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3>Enregistrer un mouvement</h3>
            <button class="modal-close" onclick="closeCashModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="cashTechId">
            <div class="form-group">
                <label class="form-label">Type de mouvement</label>
                <div class="radio-group">
                    <label class="radio-label"><input type="radio" name="cashType" value="initial"> Définir montant initial</label>
                    <label class="radio-label"><input type="radio" name="cashType" value="depot_banque" checked> Versement banque</label>
                    <label class="radio-label"><input type="radio" name="cashType" value="achat_liquide"> Achat en liquide</label>
                    <label class="radio-label"><input type="radio" name="cashType" value="note"> Note / ajustement</label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Montant (€)</label>
                <div class="amount-input-wrapper">
                    <input type="number" id="cashMontant" class="form-input amount-input" placeholder="0.00" min="0" step="0.01" inputmode="decimal">
                    <span class="amount-currency">€</span>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Date</label>
                <input type="date" id="cashDate" class="form-input" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Notes (optionnel)</label>
                <input type="text" id="cashNotes" class="form-input" placeholder="Ex: Achat produits nettoyage...">
            </div>
            <div id="cashError" class="alert alert-error" style="display:none"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeCashModal()">Annuler</button>
            <button class="btn btn-primary" onclick="saveCashMovement()">Enregistrer</button>
        </div>
    </div>
</div>

<!-- Edit movement modal -->
<div class="modal-overlay" id="editMvtModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3>Modifier le mouvement</h3>
            <button class="modal-close" onclick="closeEditMvt()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editMvtId">
            <div class="form-group">
                <label class="form-label">Type de mouvement</label>
                <div class="radio-group">
                    <label class="radio-label"><input type="radio" name="editCashType" value="initial"> Montant initial</label>
                    <label class="radio-label"><input type="radio" name="editCashType" value="depot_banque"> Versement banque</label>
                    <label class="radio-label"><input type="radio" name="editCashType" value="achat_liquide"> Achat en liquide</label>
                    <label class="radio-label"><input type="radio" name="editCashType" value="note"> Note / ajustement</label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Montant (€)</label>
                <div class="amount-input-wrapper">
                    <input type="number" id="editMvtMontant" class="form-input amount-input" placeholder="0.00" min="0" step="0.01" inputmode="decimal">
                    <span class="amount-currency">€</span>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Date</label>
                <input type="date" id="editMvtDate" class="form-input">
            </div>
            <div class="form-group">
                <label class="form-label">Notes (optionnel)</label>
                <input type="text" id="editMvtNotes" class="form-input">
            </div>
            <div id="editMvtError" class="alert alert-error" style="display:none"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeEditMvt()">Annuler</button>
            <button class="btn btn-primary" onclick="saveEditMvt()">Enregistrer</button>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal-overlay" id="deleteMvtModal">
    <div class="modal">
        <div class="modal-icon modal-icon-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
        </div>
        <h3 class="modal-title">Supprimer ce mouvement ?</h3>
        <p class="modal-body">Cette action est irréversible.</p>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeDeleteMvt()">Annuler</button>
            <button class="btn btn-danger" id="confirmDeleteMvt">Supprimer</button>
        </div>
    </div>
</div>

<script>
// ── Add movement ──────────────────────────────────────────
function showCashForm(techId) {
    document.getElementById('cashTechId').value = techId;
    document.getElementById('cashMontant').value = '';
    document.getElementById('cashNotes').value = '';
    document.getElementById('cashError').style.display = 'none';
    document.querySelector('input[name="cashType"][value="depot_banque"]').checked = true;
    document.getElementById('cashModal').classList.add('open');
}
function closeCashModal() { document.getElementById('cashModal').classList.remove('open'); }

async function saveCashMovement() {
    const type    = document.querySelector('input[name="cashType"]:checked')?.value;
    const montant = document.getElementById('cashMontant').value;
    const date    = document.getElementById('cashDate').value;
    const notes   = document.getElementById('cashNotes').value;
    const techId  = document.getElementById('cashTechId').value;
    const err     = document.getElementById('cashError');
    err.style.display = 'none';
    if (!montant || parseFloat(montant) <= 0) {
        err.textContent = 'Veuillez saisir un montant valide'; err.style.display = 'block'; return;
    }
    const fd = new FormData();
    fd.append('technician_id', techId); fd.append('type', type);
    fd.append('montant', montant); fd.append('date', date); fd.append('notes', notes);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/cash_save.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { window.location.reload(); }
    else { err.textContent = data.error || 'Erreur'; err.style.display = 'block'; }
}

// ── Edit movement ─────────────────────────────────────────
function editMvt(id, type, montant, date, notes) {
    document.getElementById('editMvtId').value = id;
    document.getElementById('editMvtMontant').value = montant;
    document.getElementById('editMvtDate').value = date;
    document.getElementById('editMvtNotes').value = notes || '';
    document.getElementById('editMvtError').style.display = 'none';
    const radio = document.querySelector(`input[name="editCashType"][value="${type}"]`);
    if (radio) radio.checked = true;
    document.getElementById('editMvtModal').classList.add('open');
}
function closeEditMvt() { document.getElementById('editMvtModal').classList.remove('open'); }

async function saveEditMvt() {
    const id      = document.getElementById('editMvtId').value;
    const type    = document.querySelector('input[name="editCashType"]:checked')?.value;
    const montant = document.getElementById('editMvtMontant').value;
    const date    = document.getElementById('editMvtDate').value;
    const notes   = document.getElementById('editMvtNotes').value;
    const err     = document.getElementById('editMvtError');
    err.style.display = 'none';
    if (!montant || parseFloat(montant) <= 0) {
        err.textContent = 'Veuillez saisir un montant valide'; err.style.display = 'block'; return;
    }
    const fd = new FormData();
    fd.append('id', id); fd.append('type', type);
    fd.append('montant', montant); fd.append('date', date); fd.append('notes', notes);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/cash_update.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { window.location.reload(); }
    else { err.textContent = data.error || 'Erreur'; err.style.display = 'block'; }
}

// ── Delete movement ───────────────────────────────────────
let deleteMvtId = null;
function deleteMvt(id) {
    deleteMvtId = id;
    document.getElementById('deleteMvtModal').classList.add('open');
}
function closeDeleteMvt() {
    document.getElementById('deleteMvtModal').classList.remove('open');
    deleteMvtId = null;
}
document.getElementById('confirmDeleteMvt').addEventListener('click', async function() {
    if (!deleteMvtId) return;
    this.disabled = true; this.textContent = 'Suppression...';
    const fd = new FormData();
    fd.append('id', deleteMvtId);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/cash_delete.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { window.location.reload(); }
    else { alert(data.error || 'Erreur'); this.disabled = false; this.textContent = 'Supprimer'; }
});
</script>
