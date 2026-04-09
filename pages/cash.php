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

    $stmt = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM cash_movements WHERE technician_id=? AND type='initial'");
    $stmt->execute([$tid]);
    $initial = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM services WHERE technician_id=? AND paiement='cash'");
    $stmt->execute([$tid]);
    $cashIn = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM cash_movements WHERE technician_id=? AND type='depot_banque'");
    $stmt->execute([$tid]);
    $deposits = (float)$stmt->fetchColumn();

    // Recent movements
    $stmt = $db->prepare("
        SELECT 'movement' as src, type, montant, notes, date, created_at FROM cash_movements WHERE technician_id=?
        UNION ALL
        SELECT 'service' as src, 'cash_service' as type, montant, COALESCE(notes,'Prestation cash') as notes, date, created_at
        FROM services WHERE technician_id=? AND paiement='cash'
        ORDER BY date DESC, created_at DESC LIMIT 20
    ");
    $stmt->execute([$tid, $tid]);
    $movements = $stmt->fetchAll();

    $cashSummary[$tid] = [
        'tech' => $tech,
        'initial' => $initial,
        'cash_in' => $cashIn,
        'deposits' => $deposits,
        'solde' => $initial + $cashIn - $deposits,
        'movements' => $movements,
    ];
}

$mvtTypeLabels = [
    'initial' => 'Montant initial',
    'depot_banque' => 'Versement banque',
    'cash_service' => 'Prestation cash',
    'note' => 'Note',
];
?>

<div class="cash-page">
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
                <span class="amount-red">- <?= number_format($data['deposits'], 2, ',', '.') ?> €</span>
            </div>
            <div class="cash-breakdown-total">
                <span>Solde actuel</span>
                <span class="<?= $data['solde'] < 0 ? 'amount-red' : 'amount-green' ?>"><?= number_format($data['solde'], 2, ',', '.') ?> €</span>
            </div>
        </div>

        <?php if (!empty($data['movements'])): ?>
        <div class="movements-section">
            <h4 class="movements-title">Mouvements récents</h4>
            <div class="movements-list">
                <?php foreach ($data['movements'] as $m): ?>
                <div class="movement-item">
                    <div class="movement-icon <?= $m['type'] === 'depot_banque' ? 'movement-out' : 'movement-in' ?>">
                        <?php if ($m['type'] === 'depot_banque'): ?>
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
                    <div class="movement-amount <?= $m['type'] === 'depot_banque' ? 'amount-red' : 'amount-green' ?>">
                        <?= $m['type'] === 'depot_banque' ? '-' : '+' ?><?= number_format(abs($m['montant']), 2, ',', '.') ?> €
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Cash entry modal -->
<div class="modal-overlay" id="cashModal" style="display:none">
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
                    <label class="radio-label"><input type="radio" name="cashType" value="initial" id="cashTypeInitial"> Définir montant initial</label>
                    <label class="radio-label"><input type="radio" name="cashType" value="depot_banque" checked> Versement banque</label>
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
                <input type="text" id="cashNotes" class="form-input" placeholder="Ex: Versement agence BNP...">
            </div>
            <div id="cashError" class="alert alert-error" style="display:none"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeCashModal()">Annuler</button>
            <button class="btn btn-primary" onclick="saveCashMovement()">Enregistrer</button>
        </div>
    </div>
</div>

<script>
function showCashForm(techId) {
    document.getElementById('cashTechId').value = techId;
    document.getElementById('cashMontant').value = '';
    document.getElementById('cashNotes').value = '';
    document.getElementById('cashError').style.display = 'none';
    document.querySelector('input[name="cashType"][value="depot_banque"]').checked = true;
    document.getElementById('cashModal').style.display = 'flex';
}
function closeCashModal() {
    document.getElementById('cashModal').style.display = 'none';
}
async function saveCashMovement() {
    const techId = document.getElementById('cashTechId').value;
    const type = document.querySelector('input[name="cashType"]:checked')?.value;
    const montant = document.getElementById('cashMontant').value;
    const date = document.getElementById('cashDate').value;
    const notes = document.getElementById('cashNotes').value;
    const err = document.getElementById('cashError');
    err.style.display = 'none';

    if (!montant || parseFloat(montant) <= 0) {
        err.textContent = 'Veuillez saisir un montant valide';
        err.style.display = 'block'; return;
    }

    const fd = new FormData();
    fd.append('technician_id', techId);
    fd.append('type', type);
    fd.append('montant', montant);
    fd.append('date', date);
    fd.append('notes', notes);
    fd.append('csrf_token', document.getElementById('csrfToken').value);

    const res = await fetch('api/cash_save.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        window.location.reload();
    } else {
        err.textContent = data.error || 'Erreur';
        err.style.display = 'block';
    }
}
</script>
