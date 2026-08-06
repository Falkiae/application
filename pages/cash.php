<?php
$db = getDB();
$techId = currentUserId();
$isAdm = isAdmin();

// Selected month (global to the page)
$monthNames = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$selYear  = max(2020, min(2030, (int)($_GET['cyear']  ?? date('Y'))));
$selMonth = max(1,    min(12,   (int)($_GET['cmonth'] ?? date('n'))));
$mStr   = sprintf('%04d-%02d', $selYear, $selMonth);
$mStart = sprintf('%04d-%02d-01', $selYear, $selMonth);
// Prev / next month for navigation
$prevMonth = $selMonth == 1 ? 12 : $selMonth - 1;
$prevYear  = $selMonth == 1 ? $selYear - 1 : $selYear;
$nextMonth = $selMonth == 12 ? 1 : $selMonth + 1;
$nextYear  = $selMonth == 12 ? $selYear + 1 : $selYear;

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

    // ── Opening balance (running balance before selected month) ──
    $stmt = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM cash_movements WHERE technician_id=? AND date < ?");
    $stmt->execute([$tid, $mStart]);
    $stmt2 = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM services WHERE technician_id=? AND paiement='cash' AND date < ?");
    $stmt2->execute([$tid, $mStart]);
    $soldeDebut = (float)$stmt->fetchColumn() + (float)$stmt2->fetchColumn();

    // ── Month flows from cash_movements ─────────────────────────
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='initial' THEN montant ELSE 0 END), 0) as initial,
            COALESCE(ABS(SUM(CASE WHEN type='depot_banque' THEN montant ELSE 0 END)), 0) as depot_banque,
            COALESCE(ABS(SUM(CASE WHEN type='achat_liquide' THEN montant ELSE 0 END)), 0) as achats,
            COALESCE(SUM(CASE WHEN type='note' THEN montant ELSE 0 END), 0) as notes,
            COALESCE(SUM(montant), 0) as net_mvt
        FROM cash_movements WHERE technician_id=? AND strftime('%Y-%m', date)=?
    ");
    $stmt->execute([$tid, $mStr]);
    $flux = $stmt->fetch();

    // ── Month cash-in from services ─────────────────────────────
    $stmt = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM services WHERE technician_id=? AND paiement='cash' AND strftime('%Y-%m', date)=?");
    $stmt->execute([$tid, $mStr]);
    $cashInMois = (float)$stmt->fetchColumn();

    $soldeFin = $soldeDebut + (float)$flux['net_mvt'] + $cashInMois;

    // ── Merged movement list for the month ──────────────────────
    $stmt = $db->prepare("
        SELECT id, type, montant, notes, date FROM cash_movements
        WHERE technician_id=? AND strftime('%Y-%m', date)=?
    ");
    $stmt->execute([$tid, $mStr]);
    $entries = [];
    foreach ($stmt->fetchAll() as $m) {
        $entries[] = [
            'kind'    => 'mvt',
            'id'      => $m['id'],
            'type'    => $m['type'],
            'montant' => (float)$m['montant'],
            'notes'   => $m['notes'],
            'date'    => $m['date'],
        ];
    }
    $stmt = $db->prepare("
        SELECT id as sid, montant, COALESCE(notes,'Prestation cash') as notes, date
        FROM services WHERE technician_id=? AND paiement='cash' AND strftime('%Y-%m', date)=?
    ");
    $stmt->execute([$tid, $mStr]);
    foreach ($stmt->fetchAll() as $s) {
        $entries[] = [
            'kind'    => 'svc',
            'id'      => $s['sid'],
            'type'    => 'service',
            'montant' => (float)$s['montant'],
            'notes'   => $s['notes'],
            'date'    => $s['date'],
        ];
    }
    // Sort by date DESC, then id DESC (most recent first)
    usort($entries, function ($a, $b) {
        if ($a['date'] === $b['date']) return $b['id'] <=> $a['id'];
        return strcmp($b['date'], $a['date']);
    });

    $cashSummary[$tid] = [
        'tech'         => $tech,
        'solde_debut'  => $soldeDebut,
        'solde_fin'    => $soldeFin,
        'initial'      => (float)$flux['initial'],
        'cash_in'      => $cashInMois,
        'notes'        => (float)$flux['notes'],
        'depot_banque' => (float)$flux['depot_banque'],
        'achats'       => (float)$flux['achats'],
        'entries'      => $entries,
    ];
}

$mvtTypeLabels = [
    'initial'       => 'Montant initial',
    'depot_banque'  => 'Versement banque',
    'achat_liquide' => 'Achat en liquide',
    'note'          => 'Note / ajustement',
];

// Global total (all technicians) — closing balance of the selected month
$globalTotal = array_sum(array_column(array_values($cashSummary), 'solde_fin'));
?>

<div class="cash-page">
    <!-- Month navigation (applies to all cards) -->
    <div class="cash-month-nav">
        <button class="cash-month-arrow" onclick="cashGoMonth(<?= $prevYear ?>, <?= $prevMonth ?>)" aria-label="Mois précédent">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <span class="cash-month-label"><?= $monthNames[$selMonth] ?> <?= $selYear ?></span>
        <button class="cash-month-arrow" onclick="cashGoMonth(<?= $nextYear ?>, <?= $nextMonth ?>)" aria-label="Mois suivant">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>

    <?php if ($isAdm): ?>
    <div class="section-card cash-global-card">
        <div class="cash-global-inner">
            <div class="cash-global-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/></svg>
            </div>
            <div class="cash-global-info">
                <span class="cash-global-label">Total liquidités à fin <?= $monthNames[$selMonth] ?> <?= $selYear ?> (tous techniciens)</span>
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
                <span class="cash-solde-badge <?= $data['solde_fin'] < 0 ? 'solde-negative' : 'solde-positive' ?>" title="Solde à fin <?= $monthNames[$selMonth] ?> <?= $selYear ?>">
                    <?= number_format($data['solde_fin'], 2, ',', '.') ?> €
                </span>
            </div>
            <?php if ($isAdm || $tid == $techId): ?>
            <button class="btn btn-primary btn-sm" onclick="showCashForm(<?= $tid ?>)">+ Enregistrer</button>
            <?php endif; ?>
        </div>

        <div class="cash-statement">
            <div class="cash-statement-row cash-statement-open">
                <span>Solde début de mois</span>
                <span class="<?= $data['solde_debut'] < 0 ? 'amount-red' : '' ?>"><?= number_format($data['solde_debut'], 2, ',', '.') ?> €</span>
            </div>
            <div class="cash-statement-row">
                <span>+ Prestations cash</span>
                <span class="amount-green">+ <?= number_format($data['cash_in'], 2, ',', '.') ?> €</span>
            </div>
            <?php if ($data['initial'] != 0): ?>
            <div class="cash-statement-row">
                <span>+ Montant initial</span>
                <span class="amount-green">+ <?= number_format($data['initial'], 2, ',', '.') ?> €</span>
            </div>
            <?php endif; ?>
            <?php if ($data['notes'] != 0): ?>
            <div class="cash-statement-row">
                <span><?= $data['notes'] < 0 ? '−' : '+' ?> Notes / ajustements</span>
                <span class="<?= $data['notes'] < 0 ? 'amount-red' : 'amount-green' ?>"><?= $data['notes'] < 0 ? '- ' : '+ ' ?><?= number_format(abs($data['notes']), 2, ',', '.') ?> €</span>
            </div>
            <?php endif; ?>
            <div class="cash-statement-row">
                <span>− Versements banque</span>
                <span class="amount-red">- <?= number_format($data['depot_banque'], 2, ',', '.') ?> €</span>
            </div>
            <div class="cash-statement-row">
                <span>− Achats en liquide</span>
                <span class="amount-red">- <?= number_format($data['achats'], 2, ',', '.') ?> €</span>
            </div>
            <div class="cash-statement-row cash-statement-close">
                <span>= Solde fin de mois</span>
                <span class="<?= $data['solde_fin'] < 0 ? 'amount-red' : 'amount-green' ?>"><?= number_format($data['solde_fin'], 2, ',', '.') ?> €</span>
            </div>
        </div>

        <div class="movements-section">
            <h4 class="movements-title">Mouvements de <?= $monthNames[$selMonth] ?></h4>
            <?php if (empty($data['entries'])): ?>
            <p class="movements-empty">Aucun mouvement en <?= $monthNames[$selMonth] ?> <?= $selYear ?>.</p>
            <?php else: ?>
            <div class="movements-list">
                <?php foreach ($data['entries'] as $e):
                    $isOut = $e['montant'] < 0;
                    $isService = $e['kind'] === 'svc';
                ?>
                <div class="movement-item<?= $isService ? ' movement-item-service' : '' ?>"<?= $isService ? '' : ' id="mvt-' . $e['id'] . '"' ?>>
                    <div class="movement-icon <?= $isOut ? 'movement-out' : 'movement-in' ?>">
                        <?php if ($isOut): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="movement-info">
                        <span class="movement-type"><?= $isService ? 'Prestation cash' : ($mvtTypeLabels[$e['type']] ?? $e['type']) ?></span>
                        <?php if ($e['notes'] && !($isService && $e['notes'] === 'Prestation cash')): ?>
                        <span class="movement-note"><?= htmlspecialchars(mb_substr($e['notes'], 0, 40)) ?></span>
                        <?php endif; ?>
                        <span class="movement-date"><?= date('d/m/Y', strtotime($e['date'])) ?></span>
                    </div>
                    <div class="movement-amount <?= $isOut ? 'amount-red' : 'amount-green' ?>">
                        <?= $isOut ? '-' : '+' ?><?= number_format(abs($e['montant']), 2, ',', '.') ?> €
                    </div>
                    <?php if ($isService): ?>
                    <div class="movement-actions">
                        <a href="index.php?page=prestation_edit&id=<?= $e['id'] ?>" class="btn-icon btn-edit" title="Modifier la prestation">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    </div>
                    <?php elseif ($isAdm || $tid == $techId): ?>
                    <div class="movement-actions">
                        <button class="btn-icon btn-edit" title="Modifier"
                            onclick="editMvt(<?= $e['id'] ?>, '<?= $e['type'] ?>', <?= abs($e['montant']) ?>, '<?= $e['date'] ?>', <?= json_encode($e['notes'] ?? '') ?>)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn-icon btn-delete" title="Supprimer"
                            onclick="deleteMvt(<?= $e['id'] ?>)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
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
// ── Month navigation ──────────────────────────────────────
function cashGoMonth(year, month) {
    const url = new URL(window.location.href);
    url.searchParams.set('cyear', year);
    url.searchParams.set('cmonth', month);
    window.location.href = url.toString();
}

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
