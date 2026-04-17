<?php
$db = getDB();
$isAdm = isAdmin();
$techId = currentUserId();

// Month options (last 18 months)
$months = [];
for ($i = 0; $i < 18; $i++) {
    $dt = new DateTime("first day of -$i month");
    $months[] = ['value' => $dt->format('Y-m'), 'label' => $dt->format('m/Y')];
}
$selectedMonth = $_GET['month'] ?? date('Y-m');

// Count for selected month
$where = "strftime('%Y-%m', date) = ?";
$params = [$selectedMonth];
if (!$isAdm) { $where .= " AND technician_id = ?"; $params[] = $techId; }
$stmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(montant),0) as total FROM services WHERE $where");
$stmt->execute($params);
$preview = $stmt->fetch();
?>

<div class="export-page">
    <div class="section-card">
        <div class="export-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
        </div>
        <h2 class="export-title">Export Excel mensuel</h2>
        <p class="export-desc">Génère un fichier .xlsx avec 3 onglets :<br>
            <strong>Toutes les prestations</strong> · <strong>Avec facture</strong> · <strong>Mouvements cash</strong>
        </p>

        <div class="form-group">
            <label class="form-label">Mois à exporter</label>
            <select class="form-select" id="exportMonth" onchange="updatePreview()">
                <?php foreach ($months as $m): ?>
                <option value="<?= $m['value'] ?>" <?= $m['value'] === $selectedMonth ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="export-preview" id="exportPreview">
            <div class="preview-stat">
                <span class="preview-num" id="previewCount"><?= $preview['cnt'] ?></span>
                <span class="preview-label">prestation<?= $preview['cnt'] > 1 ? 's' : '' ?></span>
            </div>
            <div class="preview-sep">·</div>
            <div class="preview-stat">
                <span class="preview-num" id="previewTotal"><?= number_format($preview['total'], 2, ',', '.') ?> €</span>
                <span class="preview-label">total</span>
            </div>
        </div>

        <a id="exportBtn" class="btn btn-primary btn-full btn-export" href="#" onclick="doExport(event)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Télécharger le fichier Excel
        </a>

        <p class="export-note">Le fichier inclut les prestations <?= $isAdm ? 'de tous les techniciens' : 'de votre compte' ?> pour le mois sélectionné.</p>
    </div>

    <?php if ($isAdm): ?>
    <div class="section-card">
        <h3 class="card-section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Sauvegarde de la base de données
        </h3>
        <p class="text-muted">Créez une copie de sauvegarde de la base de données SQLite.</p>
        <button class="btn btn-outline" onclick="triggerBackup(this)">Créer une sauvegarde maintenant</button>
        <div id="backupMsg" style="margin-top:12px"></div>
    </div>
    <?php endif; ?>
</div>

<script>
function updatePreview() {
    const month = document.getElementById('exportMonth').value;
    window.location.href = 'index.php?page=export&month=' + month;
}

function doExport(e) {
    e.preventDefault();
    const month = document.getElementById('exportMonth').value;
    window.location.href = 'api/export_xlsx.php?month=' + month + '&csrf_token=' + encodeURIComponent(document.getElementById('csrfToken').value);
}

async function triggerBackup(btn) {
    btn.disabled = true;
    btn.textContent = 'Sauvegarde en cours...';
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/backup.php', {method:'POST', body:fd});
    const data = await res.json();
    const msg = document.getElementById('backupMsg');
    if (data.success) {
        msg.innerHTML = '<div class="alert alert-success">Sauvegarde créée : ' + data.filename + '</div>';
    } else {
        msg.innerHTML = '<div class="alert alert-error">' + (data.error || 'Erreur') + '</div>';
    }
    btn.disabled = false;
    btn.textContent = 'Créer une sauvegarde maintenant';
}
</script>
