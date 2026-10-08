<?php
/**
 * Page admin : importer la base de production vers la DB compliance.
 * Affiche les stats des deux bases, un bouton d'import, un modal de confirmation.
 */

// Paths
$prodPath = dirname(__DIR__, 3) . '/data/keepnew.sqlite';
$complPath = DB_PATH;

$prodExists = is_file($prodPath);

// Compliance stats (current, via already-open PDO)
$db = getDB();
$complStats = [];
foreach (['technicians', 'cleaning_types', 'services', 'cash_movements',
          'abonnements', 'pointages', 'clients', 'notifications',
          'service_history', 'cash_history', 'daily_close'] as $tbl) {
    try {
        $complStats[$tbl] = (int)$db->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
    } catch (Throwable $e) {
        $complStats[$tbl] = null;
    }
}
$complSize = is_file($complPath) ? filesize($complPath) : 0;
$complMtime = is_file($complPath) ? date('d/m/Y H:i', filemtime($complPath)) : '—';

// Prod stats (read-only peek, via separate PDO)
$prodStats = [];
$prodSize = 0;
$prodMtime = '—';
$prodReadError = null;
if ($prodExists) {
    $prodSize = filesize($prodPath);
    $prodMtime = date('d/m/Y H:i', filemtime($prodPath));
    try {
        $prod = new PDO('sqlite:' . $prodPath);
        $prod->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (array_keys($complStats) as $tbl) {
            try {
                $prodStats[$tbl] = (int)$prod->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
            } catch (Throwable $e) {
                $prodStats[$tbl] = null; // table absente côté prod (ex. cash_history avant compliance)
            }
        }
        $prod = null;
    } catch (Throwable $e) {
        $prodReadError = $e->getMessage();
    }
}

$retroDone = getSetting('compliance_retroconformed_at');

$fmtBytes = function ($b) {
    if ($b < 1024) return $b . ' o';
    if ($b < 1024*1024) return number_format($b / 1024, 1, ',', ' ') . ' Ko';
    return number_format($b / 1024 / 1024, 1, ',', ' ') . ' Mo';
};

$labelPeople = [
    'technicians'          => 'Techniciens / admins',
    'cleaning_types'       => 'Types de nettoyage',
    'services'             => 'Prestations',
    'cash_movements'       => 'Mouvements de caisse',
    'abonnements'          => 'Abonnements',
    'pointages'            => 'Pointages',
    'clients'              => 'Clients',
    'notifications'        => 'Notifications',
    'service_history'      => 'Historique services',
    'cash_history'         => 'Historique caisse',
    'daily_close'          => 'Journées clôturées',
];
?>

<div class="admin-page">
    <div class="section-card">
        <h2 class="card-title" style="margin-top:0">Importer la base de production</h2>
        <p style="color:var(--gray-600); font-size:.9rem; margin-top:0;">
            Cette page remplace la base de données <strong>compliance</strong> actuelle par une copie exacte de la base de données de <strong>production</strong>. Toutes les tables sont importées : techniciens, types de nettoyage, prestations, caisse, abonnements, pointages, clients, paramètres…
        </p>
        <p style="color:var(--gray-600); font-size:.9rem;">
            Après l'import, l'application applique automatiquement les transformations compliance (numéros séquentiels, HTVA/TVA, clôture rétroactive des journées passées, historique propre). Tu te retrouves avec un livre de recettes complet et conforme.
        </p>
        <?php if ($retroDone): ?>
        <div class="livre-warn" style="background:#eef2ff; color:#3730a3; border-color:#a5b4fc;">
            <strong>État actuel :</strong> la rétroconformité a déjà tourné le <?= htmlspecialchars($retroDone) ?>.
            Importer à nouveau écrasera la DB compliance ; la rétroconformité se relancera sur la nouvelle base copiée.
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats comparison -->
    <div class="section-card">
        <h3 class="card-section-title">État des bases de données</h3>
        <?php if (!$prodExists): ?>
        <div class="livre-warn" style="background:#fee2e2; color:#991b1b; border-color:#f87171;">
            <strong>Base de production introuvable</strong> à l'emplacement attendu :
            <code><?= htmlspecialchars($prodPath) ?></code>.
            Vérifie que le fichier existe sur ton serveur avant de pouvoir importer.
        </div>
        <?php elseif ($prodReadError): ?>
        <div class="livre-warn" style="background:#fef3c7; color:#78350f; border-color:#fbbf24;">
            <strong>Impossible de lire la base de production :</strong> <?= htmlspecialchars($prodReadError) ?>
        </div>
        <?php else: ?>
        <div class="import-stats-grid">
            <div class="import-stats-col import-stats-prod">
                <div class="import-stats-head">Production (source)</div>
                <div class="import-stats-meta">
                    <?= $fmtBytes($prodSize) ?> · modifié le <?= $prodMtime ?>
                </div>
                <table class="import-stats-table">
                    <?php foreach ($labelPeople as $tbl => $label):
                        $v = $prodStats[$tbl] ?? null;
                    ?>
                    <tr>
                        <td><?= $label ?></td>
                        <td class="num"><?= $v === null ? '<em>—</em>' : number_format($v, 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="import-stats-col import-stats-compl">
                <div class="import-stats-head">Compliance (actuelle)</div>
                <div class="import-stats-meta">
                    <?= $fmtBytes($complSize) ?> · modifié le <?= $complMtime ?>
                </div>
                <table class="import-stats-table">
                    <?php foreach ($labelPeople as $tbl => $label):
                        $v = $complStats[$tbl] ?? null;
                    ?>
                    <tr>
                        <td><?= $label ?></td>
                        <td class="num"><?= $v === null ? '<em>—</em>' : number_format($v, 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <div class="import-action">
            <button class="btn btn-danger" onclick="openImportModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Importer la base de production (écrase compliance)
            </button>
        </div>
        <?php endif; ?>
    </div>

    <div class="section-card">
        <h3 class="card-section-title">Que fait l'import ?</h3>
        <ol style="color:var(--gray-700); font-size:.88rem; line-height:1.6;">
            <li>Une <strong>sauvegarde horodatée</strong> de la DB compliance actuelle est créée (format <code>keepnew_compliance.sqlite.pre-import-YYYYMMDD-HHMMSS</code>) à côté du fichier actuel. Elle est renommable via FTP pour revenir en arrière.</li>
            <li>La DB prod est copiée vers l'emplacement de la DB compliance.</li>
            <li>Au prochain chargement de page, les migrations ajoutent silencieusement les colonnes compliance manquantes.</li>
            <li>La rétroconformité tourne : numéros séquentiels, calcul HTVA/TVA, clôture rétroactive des journées passées, reconstruction d'un historique propre.</li>
            <li>Tu peux immédiatement consulter le livre de recettes complet et l'historique admin.</li>
        </ol>
    </div>
</div>

<!-- Confirmation modal -->
<div class="modal-overlay" id="importModal">
    <div class="modal modal-lg">
        <div class="modal-icon modal-icon-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h3 class="modal-title">Confirmer l'import</h3>
        <p class="modal-body">
            La base compliance actuelle sera <strong>remplacée</strong> par une copie de la base de production.<br>
            Une sauvegarde horodatée reste disponible via FTP si besoin de revenir en arrière.
        </p>
        <div class="form-group" style="margin: 12px 0; text-align:left;">
            <label class="checkbox-label" style="display:flex; gap:8px; align-items:flex-start;">
                <input type="checkbox" id="importConfirmCheck">
                <span style="font-size:.88rem;">
                    Je comprends que la DB compliance actuelle sera écrasée. Une sauvegarde sera gardée automatiquement.
                </span>
            </label>
        </div>
        <div id="importError" class="alert alert-error" style="display:none;"></div>
        <div id="importSuccess" style="display:none; background:#dcfce7; color:#166534; padding:12px; border-radius:6px; font-size:.9rem; margin-top:10px;"></div>
        <div class="modal-actions">
            <button class="btn btn-outline" id="importCancelBtn" onclick="closeImportModal()">Annuler</button>
            <button class="btn btn-danger" id="importConfirmBtn" onclick="runImport()">Importer maintenant</button>
        </div>
    </div>
</div>

<script>
function openImportModal() {
    document.getElementById('importConfirmCheck').checked = false;
    document.getElementById('importError').style.display = 'none';
    document.getElementById('importSuccess').style.display = 'none';
    document.getElementById('importConfirmBtn').disabled = false;
    document.getElementById('importConfirmBtn').textContent = 'Importer maintenant';
    document.getElementById('importModal').classList.add('open');
}
function closeImportModal() {
    document.getElementById('importModal').classList.remove('open');
}

async function runImport() {
    const confirmCheck = document.getElementById('importConfirmCheck');
    const err = document.getElementById('importError');
    const suc = document.getElementById('importSuccess');
    const btn = document.getElementById('importConfirmBtn');
    err.style.display = 'none';

    if (!confirmCheck.checked) {
        err.textContent = 'Coche la case de confirmation pour continuer.';
        err.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Import en cours…';

    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrfToken').value);

    try {
        const res = await fetch('api/import_prod.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) {
            const backupList = (d.backup || []).map(b => '<code>' + b + '</code>').join(', ') || '<em>aucun fichier précédent</em>';
            const stats = d.prod_stats || {};
            let statsHtml = '';
            const statLabels = {
                technicians: 'Techniciens',
                services: 'Prestations',
                cash_movements: 'Mouvements de caisse',
                abonnements: 'Abonnements',
                pointages: 'Pointages',
                clients: 'Clients'
            };
            Object.keys(statLabels).forEach(k => {
                if (stats[k] !== null && stats[k] !== undefined) {
                    statsHtml += '<li>' + statLabels[k] + ' : <strong>' + stats[k] + '</strong></li>';
                }
            });
            suc.innerHTML =
                '<strong>Import réussi.</strong><br>' +
                '<p style="margin:6px 0;">Sauvegarde créée : ' + backupList + '</p>' +
                '<p style="margin:6px 0;">Contenu importé :</p>' +
                '<ul style="margin:4px 0 10px 20px;">' + statsHtml + '</ul>' +
                '<a href="index.php?page=dashboard" class="btn btn-primary">Recharger l\'application</a>' +
                '<p style="margin:10px 0 0; font-size:.8rem; color:#166534;"><em>La rétroconformité tournera automatiquement au prochain chargement.</em></p>';
            suc.style.display = 'block';
            document.getElementById('importCancelBtn').textContent = 'Fermer';
            btn.style.display = 'none';
        } else {
            err.textContent = d.error || 'Erreur inconnue';
            err.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Importer maintenant';
        }
    } catch (e) {
        err.textContent = 'Erreur réseau : ' + e.message;
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Importer maintenant';
    }
}
</script>
