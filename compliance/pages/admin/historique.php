<?php
$db = getDB();
$filterAction = $_GET['action'] ?? '';
$filterTech = (int)($_GET['tech'] ?? 0);
$page = (int)($_GET['p'] ?? 1);
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($filterAction) { $where[] = 'sh.action = ?'; $params[] = $filterAction; }
if ($filterTech) { $where[] = 'sh.technician_id = ?'; $params[] = $filterTech; }
$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM service_history sh WHERE $whereStr");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalCount / $perPage));

$stmt = $db->prepare("
    SELECT sh.*, t.name as tech_name, t.color as tech_color
    FROM service_history sh
    JOIN technicians t ON t.id = sh.technician_id
    WHERE $whereStr
    ORDER BY sh.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$history = $stmt->fetchAll();

$technicians = $db->query("SELECT id, name FROM technicians ORDER BY name")->fetchAll();
$actionLabels = ['create'=>'Ajout','update'=>'Modification','delete'=>'Suppression'];
$actionColors = ['create'=>'badge-success','update'=>'badge-warning','delete'=>'badge-danger'];
?>

<div class="admin-page">
    <div class="filters-bar">
        <select class="form-select filter-select" onchange="applyHistFilters()" id="histAction">
            <option value="">Toutes actions</option>
            <option value="create" <?= $filterAction==='create'?'selected':'' ?>>Ajouts</option>
            <option value="update" <?= $filterAction==='update'?'selected':'' ?>>Modifications</option>
            <option value="delete" <?= $filterAction==='delete'?'selected':'' ?>>Suppressions</option>
        </select>
        <select class="form-select filter-select" onchange="applyHistFilters()" id="histTech">
            <option value="0">Tous les techniciens</option>
            <?php foreach ($technicians as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filterTech==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="summary-bar">
        <span class="summary-count"><?= $totalCount ?> entrée<?= $totalCount > 1 ? 's' : '' ?></span>
        <span class="summary-total">Page <?= $page ?>/<?= $totalPages ?></span>
    </div>

    <div class="history-table">
        <?php if (empty($history)): ?>
        <div class="empty-state">
            <p>Aucun historique disponible</p>
        </div>
        <?php else: ?>
        <?php foreach ($history as $h): ?>
        <div class="history-full-item">
            <div class="history-icon history-<?= $h['action'] ?>">
                <?php if ($h['action'] === 'create'): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                <?php elseif ($h['action'] === 'update'): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                <?php endif; ?>
            </div>
            <div class="history-full-info">
                <div class="history-full-header">
                    <div class="tech-avatar tech-avatar-xs" style="background:<?= htmlspecialchars($h['tech_color']) ?>">
                        <?= strtoupper(substr($h['tech_name'], 0, 1)) ?>
                    </div>
                    <strong><?= htmlspecialchars($h['tech_name']) ?></strong>
                    <span class="badge <?= $actionColors[$h['action']] ?? '' ?>"><?= $actionLabels[$h['action']] ?? $h['action'] ?></span>
                    <?php if ($h['service_id']): ?>
                    <a href="index.php?page=prestation_edit&id=<?= $h['service_id'] ?>" class="btn-link-sm">Prestation #<?= $h['service_id'] ?></a>
                    <?php endif; ?>
                </div>
                <?php if ($h['changed_fields']):
                    $fields = json_decode($h['changed_fields'], true) ?? [];
                    $fieldLabels = ['date'=>'Date','type_nettoyage_id'=>'Type','lieu'=>'Lieu','ticket_tva'=>'Ticket TVA','paiement'=>'Paiement','facture_a_faire'=>'Facture','montant'=>'Montant','notes'=>'Notes','photo_avant'=>'Photo avant','photo_apres'=>'Photo après'];
                    $readable = array_map(fn($f) => $fieldLabels[$f] ?? $f, $fields);
                ?>
                <div class="history-changed">Champs modifiés : <?= implode(', ', $readable) ?></div>
                <?php endif; ?>
                <div class="history-date"><?= date('d/m/Y à H:i', strtotime($h['created_at'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?page=admin/historique&p=<?= $page-1 ?>&action=<?= urlencode($filterAction) ?>&tech=<?= $filterTech ?>" class="btn btn-outline btn-sm">← Précédent</a>
        <?php endif; ?>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
        <a href="?page=admin/historique&p=<?= $page+1 ?>&action=<?= urlencode($filterAction) ?>&tech=<?= $filterTech ?>" class="btn btn-outline btn-sm">Suivant →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function applyHistFilters() {
    const action = document.getElementById('histAction').value;
    const tech = document.getElementById('histTech').value;
    window.location.href = `index.php?page=admin/historique&action=${action}&tech=${tech}`;
}
</script>
