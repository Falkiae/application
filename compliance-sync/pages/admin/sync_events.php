<?php
/**
 * Page admin : état de la synchronisation Zenbooker ⇄ Odoo + log des événements.
 */

$db = getDB();

$currentMode = getSetting('sync_mode', 'dry_run');
$filterAction = $_GET['fa'] ?? '';
$filterSource = $_GET['fs'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($filterAction) { $where[] = 'action = ?'; $params[] = $filterAction; }
if ($filterSource) { $where[] = 'source = ?'; $params[] = $filterSource; }
$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM sync_events WHERE $whereStr");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare("
    SELECT * FROM sync_events
    WHERE $whereStr
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$events = $stmt->fetchAll();

// Stats rapides
$stats = $db->query("
    SELECT action, COUNT(*) as n FROM sync_events
    WHERE created_at >= datetime('now', '-24 hours')
    GROUP BY action
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Odoo / Zenbooker secrets présents ?
$zenbookerConfigured = defined('ZENBOOKER_API_KEY') && ZENBOOKER_API_KEY !== '';
$odooConfigured      = defined('ODOO_URL') && ODOO_URL !== '' && defined('ODOO_API_KEY') && ODOO_API_KEY !== '';

$actionLabels = [
    'dry_run'       => ['Dry run',       'badge-info'],
    'ignored'       => ['Ignoré',        'badge-cancel'],
    'pending_review'=> ['À approuver',   'badge-reversed'],
    'processed'     => ['Traité',        'badge-active'],
    'error'         => ['Erreur',        'badge-cancellation'],
];
?>

<div class="admin-page">
    <div class="section-card">
        <h2 class="card-title" style="margin-top:0">Synchronisation Zenbooker ⇄ Odoo</h2>

        <!-- Status credentials -->
        <div class="livre-warn" style="background:<?= ($zenbookerConfigured && $odooConfigured) ? '#dcfce7' : '#fef3c7' ?>; color:<?= ($zenbookerConfigured && $odooConfigured) ? '#166534' : '#78350f' ?>; border-color:<?= ($zenbookerConfigured && $odooConfigured) ? '#4ade80' : '#fbbf24' ?>;">
            <strong>Credentials API</strong><br>
            Zenbooker : <?= $zenbookerConfigured ? '✓ configuré' : '⚠ clé manquante dans config_secrets.php' ?><br>
            Odoo (<?= htmlspecialchars(ODOO_URL ?: '—') ?>) : <?= $odooConfigured ? '✓ configuré' : '⚠ URL ou clé manquante' ?>
        </div>

        <!-- Mode selector -->
        <div style="margin:16px 0;">
            <h3 class="card-section-title">Mode de synchronisation</h3>
            <div class="radio-group" style="display:flex; flex-direction:column; gap:10px;">
                <label class="radio-label" style="padding:10px 12px; border:1.5px solid <?= $currentMode === 'dry_run' ? '#3b82f6' : 'var(--gray-200)' ?>; border-radius:8px; cursor:pointer; <?= $currentMode === 'dry_run' ? 'background:#eff6ff;' : '' ?>">
                    <input type="radio" name="syncMode" value="dry_run" <?= $currentMode === 'dry_run' ? 'checked' : '' ?> onchange="setSyncMode(this.value)">
                    <strong>Dry run</strong> — Log tout, aucun appel sortant vers Odoo (défaut, mode le plus sûr)
                </label>
                <label class="radio-label" style="padding:10px 12px; border:1.5px solid <?= $currentMode === 'review_only' ? '#fbbf24' : 'var(--gray-200)' ?>; border-radius:8px; cursor:pointer; <?= $currentMode === 'review_only' ? 'background:#fef3c7;' : '' ?>">
                    <input type="radio" name="syncMode" value="review_only" <?= $currentMode === 'review_only' ? 'checked' : '' ?> onchange="setSyncMode(this.value)">
                    <strong>Review only</strong> — Filtre test, enqueue pour approbation manuelle (jamais d'Odoo auto)
                </label>
                <label class="radio-label" style="padding:10px 12px; border:1.5px solid <?= $currentMode === 'active' ? '#dc2626' : 'var(--gray-200)' ?>; border-radius:8px; cursor:pointer; <?= $currentMode === 'active' ? 'background:#fee2e2;' : '' ?>">
                    <input type="radio" name="syncMode" value="active" <?= $currentMode === 'active' ? 'checked' : '' ?> onchange="setSyncMode(this.value)">
                    <strong>Active</strong> — Filtre test, crée les factures en <em>DRAFT</em> dans Odoo (JAMAIS validée ni envoyée au client)
                </label>
            </div>
            <p style="font-size:.8rem; color:var(--gray-500); margin-top:10px;">
                Rappel : même en mode <em>active</em>, aucune facture n'est envoyée à un vrai client sans validation manuelle depuis l'UI Odoo.
            </p>
        </div>

        <!-- Endpoint info -->
        <div style="margin:16px 0; padding:12px; background:var(--gray-50); border-radius:8px;">
            <h4 style="margin:0 0 6px; font-size:.85rem;">URL du webhook à configurer dans Zenbooker</h4>
            <code style="font-size:.8rem; word-break:break-all; display:block; padding:6px; background:#fff; border:1px solid var(--gray-200); border-radius:4px;"><?= rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'], '/') ?><?= preg_replace('#/[^/]*$#', '', dirname($_SERVER['SCRIPT_NAME'])) ?>/api/zenbooker_webhook.php</code>
            <p style="font-size:.75rem; color:var(--gray-500); margin:6px 0 0;">
                Enregistre cette URL dans <em>Zenbooker → Settings → Developers → Webhooks</em> pour chaque événement que tu veux recevoir (recommandé pour commencer : <code>job.created</code>).
            </p>
        </div>
    </div>

    <!-- Stats 24h -->
    <div class="section-card">
        <h3 class="card-section-title">Dernières 24 heures</h3>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <?php foreach ($actionLabels as $k => [$lbl, $cls]):
                $n = (int)($stats[$k] ?? 0);
            ?>
            <div style="padding:10px 14px; border:1px solid var(--gray-200); border-radius:8px; min-width:120px;">
                <div style="font-size:.72rem; color:var(--gray-500); text-transform:uppercase;"><?= $lbl ?></div>
                <div style="font-size:1.4rem; font-weight:700;"><?= $n ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Event log -->
    <div class="section-card">
        <h3 class="card-section-title">Journal des événements (<?= $totalCount ?>)</h3>
        <div class="filters-bar">
            <select class="form-select filter-select" onchange="applyFilters()" id="fs">
                <option value="">Toutes sources</option>
                <option value="zenbooker" <?= $filterSource === 'zenbooker' ? 'selected' : '' ?>>Zenbooker</option>
                <option value="odoo"      <?= $filterSource === 'odoo'      ? 'selected' : '' ?>>Odoo</option>
                <option value="local"     <?= $filterSource === 'local'     ? 'selected' : '' ?>>Local</option>
            </select>
            <select class="form-select filter-select" onchange="applyFilters()" id="fa">
                <option value="">Toutes actions</option>
                <?php foreach ($actionLabels as $k => [$lbl, $_]): ?>
                <option value="<?= $k ?>" <?= $filterAction === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (empty($events)): ?>
        <p style="color:var(--gray-500); text-align:center; padding:30px 0;">
            Aucun événement reçu pour l'instant. Configure le webhook dans Zenbooker et crée une réservation pour un client contenant <code>TEST</code> dans le nom (ou avec <code>[SYNC-TEST]</code> dans les notes).
        </p>
        <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($events as $e):
                [$lbl, $cls] = $actionLabels[$e['action']] ?? [$e['action'], 'badge-active'];
            ?>
            <div style="padding:12px; border:1px solid var(--gray-200); border-radius:6px; background:#fff;">
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <span class="badge <?= $cls ?>"><?= $lbl ?></span>
                    <strong style="font-family:ui-monospace,monospace; font-size:.85rem;"><?= htmlspecialchars($e['source']) ?> · <?= htmlspecialchars($e['event_type']) ?></strong>
                    <?php if ($e['external_id']): ?>
                    <span style="color:var(--gray-500); font-size:.78rem;">ext=<?= htmlspecialchars($e['external_id']) ?></span>
                    <?php endif; ?>
                    <span style="margin-left:auto; color:var(--gray-500); font-size:.78rem;"><?= date('d/m/Y H:i:s', strtotime($e['created_at'])) ?></span>
                </div>
                <?php if ($e['reason']): ?>
                <div style="margin-top:6px; font-size:.82rem; color:var(--gray-600);"><em><?= htmlspecialchars($e['reason']) ?></em></div>
                <?php endif; ?>
                <?php if ($e['payload_json']): ?>
                <details style="margin-top:6px;">
                    <summary style="font-size:.75rem; color:var(--primary); cursor:pointer;">Voir le payload</summary>
                    <pre style="font-size:.72rem; background:var(--gray-50); padding:8px; border-radius:4px; overflow-x:auto; max-height:300px;"><?= htmlspecialchars($e['payload_json']) ?></pre>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination" style="margin-top:16px;">
            <?php if ($page > 1): ?>
            <a href="?page=admin/sync_events&p=<?= $page-1 ?>&fs=<?= urlencode($filterSource) ?>&fa=<?= urlencode($filterAction) ?>" class="btn btn-outline btn-sm">← Précédent</a>
            <?php endif; ?>
            <span><?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
            <a href="?page=admin/sync_events&p=<?= $page+1 ?>&fs=<?= urlencode($filterSource) ?>&fa=<?= urlencode($filterAction) ?>" class="btn btn-outline btn-sm">Suivant →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
async function setSyncMode(mode) {
    const confirmMsg = mode === 'active'
        ? 'Passer en mode ACTIVE créera des factures DRAFT dans Odoo pour chaque booking test détecté. Jamais envoyées au client sans ton action manuelle depuis Odoo. Confirmer ?'
        : null;
    if (confirmMsg && !confirm(confirmMsg)) {
        // Rollback radio
        document.querySelector('input[name="syncMode"][value="<?= $currentMode ?>"]').checked = true;
        return;
    }
    const fd = new FormData();
    fd.append('mode', mode);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/sync_mode_set.php', { method: 'POST', body: fd });
    const d = await res.json();
    if (d.success) {
        window.location.reload();
    } else {
        alert(d.error || 'Erreur');
        document.querySelector('input[name="syncMode"][value="<?= $currentMode ?>"]').checked = true;
    }
}
function applyFilters() {
    const fs = document.getElementById('fs').value;
    const fa = document.getElementById('fa').value;
    window.location.href = `index.php?page=admin/sync_events&fs=${fs}&fa=${fa}`;
}
</script>
