<?php
$db = getDB();
$techId = currentUserId();
$isAdm = isAdmin();
$today = date('Y-m-d');
$thisMonth = date('Y-m');

// Stats today
if ($isAdm) {
    $stmtToday = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(montant),0) as total FROM services WHERE date = ?");
} else {
    $stmtToday = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(montant),0) as total FROM services WHERE date = ? AND technician_id = ?");
}
$stmtToday->execute($isAdm ? [$today] : [$today, $techId]);
$statsToday = $stmtToday->fetch();

// Stats this month
if ($isAdm) {
    $stmtMonth = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(montant),0) as total FROM services WHERE strftime('%Y-%m', date) = ?");
} else {
    $stmtMonth = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(montant),0) as total FROM services WHERE strftime('%Y-%m', date) = ? AND technician_id = ?");
}
$stmtMonth->execute($isAdm ? [$thisMonth] : [$thisMonth, $techId]);
$statsMonth = $stmtMonth->fetch();

// Global cash total
if ($isAdm) {
    $stmtCash = $db->query("
        SELECT
            COALESCE((SELECT SUM(montant) FROM cash_movements WHERE type='initial'),0) +
            COALESCE((SELECT SUM(montant) FROM services WHERE paiement='cash'),0) -
            COALESCE((SELECT SUM(montant) FROM cash_movements WHERE type='depot_banque'),0) as global_solde
    ");
    $globalCash = (float)$stmtCash->fetchColumn();
} else {
    $stmtCash = $db->prepare("
        SELECT
            COALESCE((SELECT SUM(montant) FROM cash_movements WHERE technician_id=? AND type='initial'),0) +
            COALESCE((SELECT SUM(montant) FROM services WHERE technician_id=? AND paiement='cash'),0) -
            COALESCE((SELECT SUM(montant) FROM cash_movements WHERE technician_id=? AND type='depot_banque'),0) as solde
    ");
    $stmtCash->execute([$techId, $techId, $techId]);
    $globalCash = (float)$stmtCash->fetchColumn();
}

// Pending invoices (facture_a_faire=1 AND facture_envoyee=0)
$invoiceWhere = "facture_a_faire=1 AND (facture_envoyee IS NULL OR facture_envoyee=0)";
$invoiceParams = [];
if (!$isAdm) { $invoiceWhere .= " AND s.technician_id=?"; $invoiceParams[] = $techId; }

$stmtInvoices = $db->prepare("
    SELECT s.id, s.date, s.montant, s.paiement, ct.label as type_label, t.name as tech_name, t.color as tech_color
    FROM services s
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    JOIN technicians t ON t.id = s.technician_id
    WHERE $invoiceWhere
    ORDER BY s.date ASC
    LIMIT 10
");
$stmtInvoices->execute($invoiceParams);
$pendingInvoices = $stmtInvoices->fetchAll();
$invoiceCount = count($pendingInvoices);

// Count total pending (may be more than 10)
$stmtInvCount = $db->prepare("SELECT COUNT(*) FROM services s WHERE $invoiceWhere");
$stmtInvCount->execute($invoiceParams);
$totalPendingInvoices = (int)$stmtInvCount->fetchColumn();

// Recent services (last 8)
if ($isAdm) {
    $stmtRecent = $db->prepare("
        SELECT s.*, t.name as tech_name, t.color as tech_color, ct.label as type_label
        FROM services s
        JOIN technicians t ON t.id = s.technician_id
        JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
        ORDER BY s.created_at DESC LIMIT 8
    ");
    $stmtRecent->execute([]);
} else {
    $stmtRecent = $db->prepare("
        SELECT s.*, t.name as tech_name, t.color as tech_color, ct.label as type_label
        FROM services s
        JOIN technicians t ON t.id = s.technician_id
        JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
        WHERE s.technician_id = ?
        ORDER BY s.created_at DESC LIMIT 8
    ");
    $stmtRecent->execute([$techId]);
}
$recentServices = $stmtRecent->fetchAll();

$paiementLabels = ['cash'=>'Cash','virement'=>'Virement','qrcode'=>'QR Code','facture'=>'Facture'];
$lieuLabels = ['domicile'=>'Domicile','atelier'=>'Atelier'];
?>

<div class="dashboard">
    <!-- Stats row -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-blue">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $statsToday['cnt'] ?></span>
                <span class="stat-label">Aujourd'hui</span>
                <span class="stat-amount"><?= number_format($statsToday['total'], 2, ',', '.') ?> €</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-purple">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $statsMonth['cnt'] ?></span>
                <span class="stat-label">Ce mois</span>
                <span class="stat-amount"><?= number_format($statsMonth['total'], 2, ',', '.') ?> €</span>
            </div>
        </div>
        <div class="stat-card <?= $totalPendingInvoices > 0 ? 'stat-card-warning' : '' ?>">
            <div class="stat-icon stat-icon-orange">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $totalPendingInvoices ?></span>
                <span class="stat-label">Factures à faire</span>
                <?php if ($totalPendingInvoices > 0): ?>
                <a href="index.php?page=prestations&filter=pending" class="stat-link">Voir →</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Pending invoices widget -->
    <?php if ($totalPendingInvoices > 0): ?>
    <div class="section-card">
        <div class="section-header">
            <h2 class="section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
                Factures à faire
                <span class="badge badge-invoice"><?= $totalPendingInvoices ?></span>
            </h2>
            <a href="index.php?page=prestations&filter=pending" class="btn-link">Voir tout →</a>
        </div>
        <div class="invoice-list">
            <?php foreach ($pendingInvoices as $inv): ?>
            <div class="invoice-item">
                <div class="tech-avatar tech-avatar-sm" style="background:<?= htmlspecialchars($inv['tech_color']) ?>">
                    <?= strtoupper(substr($inv['tech_name'], 0, 1)) ?>
                </div>
                <div class="invoice-info">
                    <span class="invoice-type"><?= htmlspecialchars($inv['type_label']) ?></span>
                    <span class="invoice-meta"><?= date('d/m/Y', strtotime($inv['date'])) ?><?php if ($isAdm): ?> · <?= htmlspecialchars($inv['tech_name']) ?><?php endif; ?></span>
                </div>
                <span class="invoice-amount"><?= number_format($inv['montant'], 2, ',', '.') ?> €</span>
                <button class="btn btn-invoice-send btn-xs" onclick="markSent(this, <?= $inv['id'] ?>)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
                    Envoyée
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Cash section -->
    <div class="section-card">
        <div class="section-header">
            <h2 class="section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/></svg>
                Liquidités en caisse
            </h2>
            <a href="index.php?page=cash" class="btn-link">Gérer →</a>
        </div>
        <div class="cash-global-recap">
            <span class="cash-global-label"><?= $isAdm ? 'Total tous techniciens' : 'Votre solde cash' ?></span>
            <span class="cash-global-amount <?= $globalCash < 0 ? 'amount-red' : 'amount-green' ?>">
                <?= number_format($globalCash, 2, ',', '.') ?> €
            </span>
        </div>
    </div>

    <!-- Recent services -->
    <div class="section-card">
        <div class="section-header">
            <h2 class="section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Dernières prestations
            </h2>
            <a href="index.php?page=prestations" class="btn-link">Voir tout →</a>
        </div>
        <?php if (empty($recentServices)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <p>Aucune prestation pour l'instant</p>
            <a href="index.php?page=prestation_new" class="btn btn-primary btn-sm">Ajouter une prestation</a>
        </div>
        <?php else: ?>
        <div class="service-list">
            <?php foreach ($recentServices as $s): ?>
            <div class="service-card" onclick="window.location='index.php?page=prestation_edit&id=<?= $s['id'] ?>'">
                <div class="service-card-left">
                    <div class="tech-avatar tech-avatar-sm" style="background:<?= htmlspecialchars($s['tech_color']) ?>">
                        <?= strtoupper(substr($s['tech_name'], 0, 1)) ?>
                    </div>
                    <div class="service-info">
                        <div class="service-type"><?= htmlspecialchars($s['type_label']) ?></div>
                        <div class="service-meta">
                            <?= htmlspecialchars(date('d/m/Y', strtotime($s['date']))) ?>
                            · <?= $lieuLabels[$s['lieu']] ?? $s['lieu'] ?>
                            <?php if ($isAdm): ?> · <?= htmlspecialchars($s['tech_name']) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="service-card-right">
                    <div class="service-amount"><?= number_format($s['montant'], 2, ',', '.') ?> €</div>
                    <span class="badge badge-<?= $s['paiement'] ?>"><?= $paiementLabels[$s['paiement']] ?? $s['paiement'] ?></span>
                    <?php if ($s['facture_a_faire'] && !$s['facture_envoyee']): ?>
                    <span class="badge badge-invoice">Facture</span>
                    <?php elseif ($s['facture_envoyee']): ?>
                    <span class="badge badge-invoice-sent">✓ Envoyée</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
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

<script>
let _sentBtn = null, _sentId = null;

function markSent(btn, id) {
    _sentBtn = btn;
    _sentId  = id;
    document.getElementById('confirmSentModal').classList.add('open');
}

document.getElementById('confirmSentOk').addEventListener('click', async function() {
    document.getElementById('confirmSentModal').classList.remove('open');
    if (!_sentId) return;
    _sentBtn.disabled = true;
    const fd = new FormData();
    fd.append('id', _sentId);
    fd.append('envoyee', '1');
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/facture_mark.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        const item = _sentBtn.closest('.invoice-item');
        if (item) { item.style.opacity='0'; item.style.transition='opacity .3s'; setTimeout(()=>window.location.reload(),300); }
        else window.location.reload();
    } else {
        alert(data.error || 'Erreur');
        _sentBtn.disabled = false;
    }
});
</script>
