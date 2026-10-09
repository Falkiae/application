<?php
$db = getDB();
$filterAction = $_GET['fa'] ?? '';
$filterTech   = (int)($_GET['tech'] ?? 0);
$filterAdmin  = (int)($_GET['admin'] ?? 0);
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($filterAction) { $where[] = 'ph.action = ?';        $params[] = $filterAction; }
if ($filterTech)   { $where[] = 'ph.technician_id = ?'; $params[] = $filterTech; }
if ($filterAdmin)  { $where[] = 'ph.admin_id = ?';      $params[] = $filterAdmin; }
$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM pointage_history ph WHERE $whereStr");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare("
    SELECT ph.*,
           t.name  AS tech_name,  t.color AS tech_color,
           a.name  AS admin_name
    FROM pointage_history ph
    JOIN technicians  t ON t.id = ph.technician_id
    LEFT JOIN technicians a ON a.id = ph.admin_id
    WHERE $whereStr
    ORDER BY ph.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$history = $stmt->fetchAll();

$technicians = $db->query("SELECT id, name FROM technicians ORDER BY name")->fetchAll();
$admins      = $db->query("SELECT id, name FROM technicians WHERE role='admin' ORDER BY name")->fetchAll();

$actionLabels = [
    'create'     => 'Ajout',
    'update'     => 'Modification',
    'delete'     => 'Suppression',
    'auto_close' => 'Fermeture auto',
];
$actionColors = [
    'create'     => 'badge-success',
    'update'     => 'badge-warning',
    'delete'     => 'badge-danger',
    'auto_close' => 'badge-info',
];
$fieldLabels = [
    'date'  => 'Date',
    'debut' => 'Heure début',
    'fin'   => 'Heure fin',
    'notes' => 'Notes',
    'technician_id' => 'Technicien',
];

// Format a value for display in the diff column.
$fmtVal = function ($v) {
    if ($v === null || $v === '') return '—';
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
        return date('d/m/Y H:i', strtotime($v));
    }
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return date('d/m/Y', strtotime($v));
    }
    return htmlspecialchars((string)$v);
};
?>

<div class="admin-page">
    <div class="filters-bar">
        <select class="form-select filter-select" onchange="applyPtHistFilters()" id="phAction">
            <option value="">Toutes actions</option>
            <?php foreach ($actionLabels as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $filterAction === $k ? 'selected' : '' ?>><?= $lbl ?>s</option>
            <?php endforeach; ?>
        </select>
        <select class="form-select filter-select" onchange="applyPtHistFilters()" id="phTech">
            <option value="0">Tous les techniciens</option>
            <?php foreach ($technicians as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filterTech == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select filter-select" onchange="applyPtHistFilters()" id="phAdmin">
            <option value="0">Tous les auteurs</option>
            <?php foreach ($admins as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $filterAdmin == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
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
            <p>Aucune entrée d'historique pour ces filtres.</p>
        </div>
        <?php else: ?>
        <?php foreach ($history as $h):
            $old = $h['old_values'] ? (json_decode($h['old_values'], true) ?: []) : [];
            $new = $h['new_values'] ? (json_decode($h['new_values'], true) ?: []) : [];
            $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        ?>
        <div class="history-full-item">
            <div class="history-icon history-<?= htmlspecialchars($h['action']) ?>">
                <?php if ($h['action'] === 'create'): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                <?php elseif ($h['action'] === 'update'): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <?php elseif ($h['action'] === 'delete'): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?php endif; ?>
            </div>
            <div class="history-full-info">
                <div class="history-full-header">
                    <div class="tech-avatar tech-avatar-xs" style="background:<?= htmlspecialchars($h['tech_color']) ?>">
                        <?= strtoupper(substr($h['tech_name'], 0, 1)) ?>
                    </div>
                    <strong><?= htmlspecialchars($h['tech_name']) ?></strong>
                    <span class="badge <?= $actionColors[$h['action']] ?? '' ?>"><?= $actionLabels[$h['action']] ?? $h['action'] ?></span>
                    <span class="pt-hist-author">
                        par <?= $h['admin_name'] ? htmlspecialchars($h['admin_name']) : '<em>Système</em>' ?>
                    </span>
                    <?php if ($h['pointage_id']): ?>
                    <span class="btn-link-sm">Pointage #<?= (int)$h['pointage_id'] ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($keys)): ?>
                <div class="pt-hist-diff">
                    <?php foreach ($keys as $k):
                        $ov = $old[$k] ?? null;
                        $nv = $new[$k] ?? null;
                        if ($ov === $nv) continue;
                    ?>
                    <div class="pt-hist-diff-row">
                        <span class="pt-hist-field"><?= htmlspecialchars($fieldLabels[$k] ?? $k) ?> :</span>
                        <span class="pt-hist-old"><?= $fmtVal($ov) ?></span>
                        <span class="pt-hist-arrow">→</span>
                        <span class="pt-hist-new"><?= $fmtVal($nv) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="history-date"><?= date('d/m/Y à H:i', strtotime($h['created_at'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php $qBase = "?page=admin/pointage_historique&fa=" . urlencode($filterAction) . "&tech=$filterTech&admin=$filterAdmin"; ?>
        <?php if ($page > 1): ?>
        <a href="<?= $qBase ?>&p=<?= $page - 1 ?>" class="btn btn-outline btn-sm">← Précédent</a>
        <?php endif; ?>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
        <a href="<?= $qBase ?>&p=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Suivant →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function applyPtHistFilters() {
    const fa    = document.getElementById('phAction').value;
    const tech  = document.getElementById('phTech').value;
    const admin = document.getElementById('phAdmin').value;
    window.location.href = `index.php?page=admin/pointage_historique&fa=${fa}&tech=${tech}&admin=${admin}`;
}
</script>
