<?php
$db = getDB();
$techId = currentUserId();
$isAdm = isAdmin();

$filterMonth = $_GET['month'] ?? date('Y-m');
$filterTech = $isAdm ? (int)($_GET['tech'] ?? 0) : $techId;
$filterPayment = $_GET['payment'] ?? '';
$filterInvoice = isset($_GET['filter']) && $_GET['filter'] === 'invoice';

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
if ($filterInvoice) {
    $where[] = "s.facture_a_faire = 1";
}
$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT s.*, t.name as tech_name, t.color as tech_color, ct.label as type_label
    FROM services s
    JOIN technicians t ON t.id = s.technician_id
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    WHERE $whereStr
    ORDER BY s.date DESC, s.created_at DESC
");
$stmt->execute($params);
$services = $stmt->fetchAll();

// Totals
$totalMontant = array_sum(array_column($services, 'montant'));
$totalCount = count($services);

// Technicians for filter (admin only)
$technicians = [];
if ($isAdm) {
    $technicians = $db->query("SELECT id, name, color FROM technicians WHERE active=1 ORDER BY name")->fetchAll();
}

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
        <div class="filter-group">
            <select class="form-select filter-select" id="filterMonth" onchange="applyFilters()">
                <?php foreach ($months as $m): ?>
                <option value="<?= $m['value'] ?>" <?= $m['value'] === $filterMonth ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($isAdm): ?>
        <div class="filter-group">
            <select class="form-select filter-select" id="filterTech" onchange="applyFilters()">
                <option value="0">Tous les techniciens</option>
                <?php foreach ($technicians as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $filterTech == $t['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="filter-group">
            <select class="form-select filter-select" id="filterPayment" onchange="applyFilters()">
                <option value="">Tout paiement</option>
                <option value="cash" <?= $filterPayment==='cash'?'selected':'' ?>>Cash</option>
                <option value="virement" <?= $filterPayment==='virement'?'selected':'' ?>>Virement</option>
                <option value="qrcode" <?= $filterPayment==='qrcode'?'selected':'' ?>>QR Code</option>
                <option value="facture" <?= $filterPayment==='facture'?'selected':'' ?>>Facture</option>
            </select>
        </div>
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
        <?php foreach ($services as $s): ?>
        <div class="service-card service-card-full">
            <div class="service-card-main" onclick="window.location='index.php?page=prestation_edit&id=<?= $s['id'] ?>'">
                <div class="service-card-left">
                    <div class="tech-avatar tech-avatar-sm" style="background:<?= htmlspecialchars($s['tech_color']) ?>">
                        <?= strtoupper(substr($s['tech_name'], 0, 1)) ?>
                    </div>
                    <div class="service-info">
                        <div class="service-type"><?= htmlspecialchars($s['type_label']) ?></div>
                        <div class="service-meta">
                            <?= htmlspecialchars(date('d/m/Y', strtotime($s['date']))) ?>
                            · <?= $lieuLabels[$s['lieu']] ?? $s['lieu'] ?>
                            <?php if ($isAdm): ?> · <strong><?= htmlspecialchars($s['tech_name']) ?></strong><?php endif; ?>
                        </div>
                        <?php if ($s['notes']): ?>
                        <div class="service-notes"><?= htmlspecialchars(mb_substr($s['notes'], 0, 60)) ?><?= strlen($s['notes']) > 60 ? '…' : '' ?></div>
                        <?php endif; ?>
                        <div class="service-badges">
                            <span class="badge badge-<?= $s['paiement'] ?>"><?= $paiementLabels[$s['paiement']] ?? $s['paiement'] ?></span>
                            <?php if ($s['ticket_tva']): ?><span class="badge badge-tva">TVA</span><?php endif; ?>
                            <?php if ($s['facture_a_faire']): ?><span class="badge badge-invoice">Facture à faire</span><?php endif; ?>
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
                <a href="index.php?page=prestation_edit&id=<?= $s['id'] ?>" class="btn-icon btn-edit" title="Modifier">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
                <button class="btn-icon btn-delete" title="Supprimer"
                        onclick="deleteService(<?= $s['id'] ?>, '<?= htmlspecialchars($s['type_label']) ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Delete confirmation modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-icon modal-icon-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        </div>
        <h3 class="modal-title">Supprimer la prestation ?</h3>
        <p class="modal-body" id="deleteModalBody">Cette action est irréversible.</p>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeDeleteModal()">Annuler</button>
            <button class="btn btn-danger" id="confirmDelete">Supprimer</button>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const month = document.getElementById('filterMonth').value;
    const tech = document.getElementById('filterTech')?.value || '0';
    const payment = document.getElementById('filterPayment').value;
    let url = `index.php?page=prestations&month=${month}`;
    if (tech !== '0') url += `&tech=${tech}`;
    if (payment) url += `&payment=${payment}`;
    window.location.href = url;
}

let deleteId = null;
function deleteService(id, label) {
    deleteId = id;
    document.getElementById('deleteModalBody').textContent = `Supprimer la prestation "${label}" ? Cette action est irréversible.`;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    deleteId = null;
}
document.getElementById('confirmDelete').addEventListener('click', async function() {
    if (!deleteId) return;
    this.disabled = true;
    this.textContent = 'Suppression...';
    const fd = new FormData();
    fd.append('id', deleteId);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/prestation_delete.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        window.location.reload();
    } else {
        alert(data.error || 'Erreur lors de la suppression');
        this.disabled = false;
        this.textContent = 'Supprimer';
    }
});
</script>
