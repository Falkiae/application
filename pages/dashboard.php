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

// Cash per technician
$cashQuery = "
    SELECT t.id, t.name, t.color,
        COALESCE((SELECT SUM(montant) FROM cash_movements WHERE technician_id=t.id AND type='initial'),0) as montant_initial,
        COALESCE((SELECT SUM(montant) FROM services WHERE technician_id=t.id AND paiement='cash'),0) as cash_payments,
        COALESCE((SELECT SUM(montant) FROM cash_movements WHERE technician_id=t.id AND type='depot_banque'),0) as bank_deposits
    FROM technicians t WHERE t.active=1 " . ($isAdm ? "" : "AND t.id=?");
$stmtCash = $db->prepare($cashQuery);
$stmtCash->execute($isAdm ? [] : [$techId]);
$cashData = $stmtCash->fetchAll();

// Recent services (last 10)
if ($isAdm) {
    $stmtRecent = $db->prepare("
        SELECT s.*, t.name as tech_name, t.color as tech_color, ct.label as type_label
        FROM services s
        JOIN technicians t ON t.id = s.technician_id
        JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
        ORDER BY s.created_at DESC LIMIT 10
    ");
    $stmtRecent->execute([]);
} else {
    $stmtRecent = $db->prepare("
        SELECT s.*, t.name as tech_name, t.color as tech_color, ct.label as type_label
        FROM services s
        JOIN technicians t ON t.id = s.technician_id
        JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
        WHERE s.technician_id = ?
        ORDER BY s.created_at DESC LIMIT 10
    ");
    $stmtRecent->execute([$techId]);
}
$recentServices = $stmtRecent->fetchAll();

// Invoices to do count
$stmtInvoices = $db->prepare("SELECT COUNT(*) as cnt FROM services WHERE facture_a_faire=1 " . ($isAdm ? "" : "AND technician_id=?"));
$stmtInvoices->execute($isAdm ? [] : [$techId]);
$invoiceCount = $stmtInvoices->fetchColumn();

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
            </div>
            <div class="stat-amount"><?= number_format($statsToday['total'], 2, ',', '.') ?> €</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-purple">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $statsMonth['cnt'] ?></span>
                <span class="stat-label">Ce mois</span>
            </div>
            <div class="stat-amount"><?= number_format($statsMonth['total'], 2, ',', '.') ?> €</div>
        </div>
        <?php if ($invoiceCount > 0): ?>
        <div class="stat-card stat-card-warning">
            <div class="stat-icon stat-icon-orange">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $invoiceCount ?></span>
                <span class="stat-label">Factures à faire</span>
            </div>
            <a href="index.php?page=prestations&filter=invoice" class="stat-link">Voir →</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cash section -->
    <div class="section-card">
        <div class="section-header">
            <h2 class="section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/></svg>
                Liquidités en caisse
            </h2>
            <a href="index.php?page=cash" class="btn-link">Gérer →</a>
        </div>
        <div class="cash-list">
            <?php foreach ($cashData as $c):
                $solde = $c['montant_initial'] + $c['cash_payments'] - $c['bank_deposits'];
            ?>
            <div class="cash-item">
                <div class="tech-avatar" style="background:<?= htmlspecialchars($c['color']) ?>">
                    <?= strtoupper(substr($c['name'], 0, 1)) ?>
                </div>
                <div class="cash-tech-name"><?= htmlspecialchars($c['name']) ?></div>
                <div class="cash-solde <?= $solde < 0 ? 'solde-negative' : '' ?>">
                    <?= number_format($solde, 2, ',', '.') ?> €
                </div>
            </div>
            <?php endforeach; ?>
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
                    <?php if ($s['facture_a_faire']): ?>
                    <span class="badge badge-invoice">Facture</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
