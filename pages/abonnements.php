<?php
$db    = getDB();
$isAdm = isAdmin();

$filterStatus = $_GET['status'] ?? 'actif';
if (!in_array($filterStatus, ['actif', 'archive'])) $filterStatus = 'actif';
$whereActive = $filterStatus === 'archive' ? 'a.active = 0' : 'a.active = 1';

$stmt = $db->query("
    SELECT a.*, c.nom as client_nom,
        COALESCE(SUM(p.nettoyages_debites),0) as utilises,
        MAX(p.date) as dernier_passage
    FROM abonnements a
    JOIN clients c ON c.id = a.client_id
    LEFT JOIN abonnement_passages p ON p.abonnement_id = a.id
    WHERE $whereActive
    GROUP BY a.id
    ORDER BY c.nom ASC
");
$abonnements = $stmt->fetchAll();

foreach ($abonnements as &$a) {
    $a['restants'] = $a['nettoyages_total'] - $a['utilises'];
    $a['pct']      = $a['nettoyages_total'] > 0
        ? round(($a['utilises'] / $a['nettoyages_total']) * 100)
        : 0;
    $a['status']   = $a['restants'] <= 0 ? 'epuise' : ($a['restants'] <= 2 ? 'faible' : 'actif');
}
unset($a);

$countActive   = (int)$db->query("SELECT COUNT(*) FROM abonnements WHERE active=1")->fetchColumn();
$countArchived = (int)$db->query("SELECT COUNT(*) FROM abonnements WHERE active=0")->fetchColumn();
?>

<div class="abonnements-page">
    <div class="abonnements-toolbar">
        <div class="abo-filter-tabs">
            <a href="index.php?page=abonnements&status=actif"
               class="abo-filter-tab <?= $filterStatus === 'actif' ? 'active' : '' ?>">
                Actifs
                <?php if ($countActive > 0): ?><span class="abo-tab-count"><?= $countActive ?></span><?php endif; ?>
            </a>
            <a href="index.php?page=abonnements&status=archive"
               class="abo-filter-tab <?= $filterStatus === 'archive' ? 'active' : '' ?>">
                Archivés
                <?php if ($countArchived > 0): ?><span class="abo-tab-count"><?= $countArchived ?></span><?php endif; ?>
            </a>
        </div>
        <?php if ($isAdm && $filterStatus === 'actif'): ?>
        <button class="btn btn-primary" onclick="showNewAboModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Nouvel abonnement
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($abonnements)): ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 4v5"/><path d="M15 4v5"/></svg>
        <p><?= $filterStatus === 'archive' ? 'Aucun abonnement archivé' : 'Aucun abonnement actif' ?></p>
        <?php if ($isAdm && $filterStatus === 'actif'): ?>
        <button class="btn btn-primary btn-sm" onclick="showNewAboModal()">Créer un abonnement</button>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="abo-list">
        <?php foreach ($abonnements as $a): ?>
        <div class="abo-card abo-status-<?= $a['status'] ?>">
            <div class="abo-card-header" onclick="window.location='index.php?page=abonnement_detail&id=<?= $a['id'] ?>'">
                <div class="abo-client-info">
                    <span class="abo-client-name"><?= htmlspecialchars($a['client_nom']) ?></span>
                    <span class="abo-status-badge abo-badge-<?= $a['status'] ?>">
                        <?php if ($a['status'] === 'epuise'): ?>Épuisé
                        <?php elseif ($a['status'] === 'faible'): ?>Bientôt épuisé
                        <?php else: ?>Actif<?php endif; ?>
                    </span>
                    <?php if ($filterStatus === 'archive'): ?>
                    <span class="abo-status-badge abo-badge-archive">Archivé</span>
                    <?php endif; ?>
                </div>
                <div class="abo-counts">
                    <span class="abo-restants <?= $a['restants'] <= 0 ? 'text-red' : ($a['restants'] <= 2 ? 'text-orange' : 'text-green') ?>">
                        <?= $a['restants'] ?> restant<?= $a['restants'] > 1 ? 's' : '' ?>
                    </span>
                    <span class="abo-total-label"><?= $a['utilises'] ?> / <?= $a['nettoyages_total'] ?> utilisés</span>
                </div>
                <?php if ($a['dernier_passage']): ?>
                <div class="abo-last-passage">Dernier passage : <?= date('d/m/Y', strtotime($a['dernier_passage'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="abo-progress-wrap">
                <div class="abo-progress-bar" style="width:<?= $a['pct'] ?>%"></div>
            </div>

            <?php if ($a['prix_total'] > 0): ?>
            <div class="abo-prix"><?= number_format($a['prix_total'], 2, ',', '.') ?> € <span class="abo-prix-label">forfait</span></div>
            <?php endif; ?>

            <div class="abo-card-actions">
                <?php if ($filterStatus === 'actif'): ?>
                    <?php if ($a['status'] !== 'epuise'): ?>
                    <a href="index.php?page=abonnement_detail&id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        Encoder un passage
                    </a>
                    <?php else: ?>
                    <a href="index.php?page=abonnement_detail&id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">Voir historique</a>
                    <?php endif; ?>
                    <?php if ($isAdm): ?>
                    <button class="btn-icon btn-edit"
                        data-id="<?= $a['id'] ?>"
                        data-total="<?= $a['nettoyages_total'] ?>"
                        data-prix="<?= (float)$a['prix_total'] ?>"
                        data-notes="<?= htmlspecialchars($a['notes'] ?? '') ?>"
                        onclick="editAbo(this)" title="Modifier">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="btn-icon btn-archive"
                        onclick="archiveAbo(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['client_nom'])) ?>')"
                        title="Archiver">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                    </button>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="index.php?page=abonnement_detail&id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">Voir historique</a>
                    <?php if ($isAdm): ?>
                    <button class="btn-icon btn-restore"
                        onclick="restoreAbo(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['client_nom'])) ?>')"
                        title="Restaurer">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                    </button>
                    <button class="btn-icon btn-delete"
                        onclick="deleteAboPermanent(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['client_nom'])) ?>')"
                        title="Supprimer définitivement">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($isAdm): ?>
<!-- New / Edit modal -->
<div class="modal-overlay" id="aboModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 id="aboModalTitle">Nouvel abonnement</h3>
            <button class="modal-close" onclick="closeAboModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="aboId">
            <div class="form-group">
                <label class="form-label">Nom du client</label>
                <input type="text" id="aboClientNom" class="form-input" placeholder="Ex: Jean Dupont" list="clientsList">
                <datalist id="clientsList">
                    <?php foreach ($db->query("SELECT nom FROM clients ORDER BY nom") as $cl): ?>
                    <option value="<?= htmlspecialchars($cl['nom']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label class="form-label">Nombre de nettoyages achetés</label>
                <div class="stepper-wrapper">
                    <button type="button" class="stepper-btn" onclick="stepAbo(-1)">−</button>
                    <input type="number" id="aboTotal" class="form-input stepper-input" value="10" min="1" max="999">
                    <button type="button" class="stepper-btn" onclick="stepAbo(1)">+</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Prix du forfait (€)</label>
                <div class="amount-input-wrapper">
                    <input type="number" id="aboPrix" class="form-input amount-input" placeholder="0.00" min="0" step="0.01" inputmode="decimal">
                    <span class="amount-currency">€</span>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes internes (optionnel)</label>
                <textarea id="aboNotes" class="form-input form-textarea" rows="2" placeholder="Conditions particulières, véhicule..."></textarea>
            </div>
            <div id="aboModalError" class="alert alert-error" style="display:none"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeAboModal()">Annuler</button>
            <button class="btn btn-primary" id="aboSaveBtn" onclick="saveAbo()">Créer</button>
        </div>
    </div>
</div>

<!-- Archive confirmation -->
<div class="modal-overlay" id="aboArchiveModal">
    <div class="modal">
        <div class="modal-icon modal-icon-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
        </div>
        <h3 class="modal-title">Archiver l'abonnement ?</h3>
        <p class="modal-body" id="aboArchiveBody"></p>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="document.getElementById('aboArchiveModal').classList.remove('open')">Annuler</button>
            <button class="btn btn-warning" id="aboArchiveConfirm">Archiver</button>
        </div>
    </div>
</div>

<!-- Restore confirmation -->
<div class="modal-overlay" id="aboRestoreModal">
    <div class="modal">
        <div class="modal-icon modal-icon-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
        </div>
        <h3 class="modal-title">Restaurer l'abonnement ?</h3>
        <p class="modal-body" id="aboRestoreBody"></p>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="document.getElementById('aboRestoreModal').classList.remove('open')">Annuler</button>
            <button class="btn btn-primary" id="aboRestoreConfirm">Restaurer</button>
        </div>
    </div>
</div>

<!-- Permanent delete confirmation -->
<div class="modal-overlay" id="aboDeleteModal">
    <div class="modal">
        <div class="modal-icon modal-icon-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        </div>
        <h3 class="modal-title">Supprimer définitivement ?</h3>
        <p class="modal-body" id="aboDeleteBody"></p>
        <label class="abo-confirm-check">
            <input type="checkbox" id="aboDeleteCheck" onchange="document.getElementById('aboDeleteBtn').disabled=!this.checked">
            Je confirme la suppression définitive et irréversible
        </label>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeAboDeleteModal()">Annuler</button>
            <button class="btn btn-danger" id="aboDeleteBtn" disabled>Supprimer définitivement</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ── New / Edit ────────────────────────────────────────────
function showNewAboModal() {
    document.getElementById('aboModalTitle').textContent = 'Nouvel abonnement';
    document.getElementById('aboId').value = '';
    document.getElementById('aboClientNom').value = '';
    document.getElementById('aboClientNom').disabled = false;
    document.getElementById('aboTotal').value = 10;
    document.getElementById('aboPrix').value = '';
    document.getElementById('aboNotes').value = '';
    document.getElementById('aboSaveBtn').textContent = 'Créer';
    document.getElementById('aboModalError').style.display = 'none';
    document.getElementById('aboModal').classList.add('open');
}
function editAbo(btn) {
    const { id, total, prix, notes } = btn.dataset;
    document.getElementById('aboModalTitle').textContent = 'Modifier l\'abonnement';
    document.getElementById('aboId').value       = id;
    document.getElementById('aboClientNom').disabled = true;
    document.getElementById('aboTotal').value    = total;
    document.getElementById('aboPrix').value     = prix || '';
    document.getElementById('aboNotes').value    = notes || '';
    document.getElementById('aboSaveBtn').textContent = 'Enregistrer';
    document.getElementById('aboModalError').style.display = 'none';
    document.getElementById('aboModal').classList.add('open');
}
function closeAboModal() { document.getElementById('aboModal').classList.remove('open'); }
function stepAbo(d) {
    const inp = document.getElementById('aboTotal');
    inp.value = Math.max(1, parseInt(inp.value || 1) + d);
}
async function saveAbo() {
    const id    = document.getElementById('aboId').value;
    const nom   = document.getElementById('aboClientNom').value.trim();
    const total = document.getElementById('aboTotal').value;
    const prix  = document.getElementById('aboPrix').value;
    const notes = document.getElementById('aboNotes').value;
    const err   = document.getElementById('aboModalError');
    err.style.display = 'none';
    if (!id && !nom) { err.textContent = 'Le nom du client est requis'; err.style.display = 'block'; return; }
    const fd = new FormData();
    fd.append('action', id ? 'update' : 'create');
    if (id) fd.append('id', id); else fd.append('client_nom', nom);
    fd.append('nettoyages_total', total);
    fd.append('prix_total', prix || '0');
    fd.append('notes', notes);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    try {
        const res  = await fetch('api/abonnement_save.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) { window.location.reload(); }
        else { err.textContent = data.error || 'Erreur'; err.style.display = 'block'; }
    } catch(e) {
        err.textContent = 'Erreur réseau'; err.style.display = 'block';
    }
}

// ── Archive ───────────────────────────────────────────────
let _aboArchiveId = null;
function archiveAbo(id, nom) {
    _aboArchiveId = id;
    document.getElementById('aboArchiveBody').textContent =
        `Archiver l'abonnement de "${nom}" ? Il sera masqué de la liste principale mais son historique sera conservé. Vous pourrez le restaurer depuis l'onglet Archivés.`;
    document.getElementById('aboArchiveModal').classList.add('open');
}
document.getElementById('aboArchiveConfirm')?.addEventListener('click', async function() {
    if (!_aboArchiveId) return;
    this.disabled = true; this.textContent = '…';
    const fd = new FormData();
    fd.append('id', _aboArchiveId);
    fd.append('action', 'archive');
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res  = await fetch('api/abonnement_delete.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { window.location.reload(); }
    else { alert(data.error || 'Erreur'); this.disabled = false; this.textContent = 'Archiver'; }
});

// ── Restore ───────────────────────────────────────────────
let _aboRestoreId = null;
function restoreAbo(id, nom) {
    _aboRestoreId = id;
    document.getElementById('aboRestoreBody').textContent =
        `Restaurer l'abonnement de "${nom}" dans la liste des abonnements actifs ?`;
    document.getElementById('aboRestoreModal').classList.add('open');
}
document.getElementById('aboRestoreConfirm')?.addEventListener('click', async function() {
    if (!_aboRestoreId) return;
    this.disabled = true; this.textContent = '…';
    const fd = new FormData();
    fd.append('id', _aboRestoreId);
    fd.append('action', 'restore');
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res  = await fetch('api/abonnement_delete.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { window.location.href = 'index.php?page=abonnements'; }
    else { alert(data.error || 'Erreur'); this.disabled = false; this.textContent = 'Restaurer'; }
});

// ── Permanent delete ──────────────────────────────────────
let _aboDelId = null;
function deleteAboPermanent(id, nom) {
    _aboDelId = id;
    document.getElementById('aboDeleteBody').textContent =
        `Supprimer définitivement l'abonnement de "${nom}" ainsi que tout son historique de passages ? Cette action est irréversible.`;
    document.getElementById('aboDeleteCheck').checked = false;
    document.getElementById('aboDeleteBtn').disabled  = true;
    document.getElementById('aboDeleteModal').classList.add('open');
}
function closeAboDeleteModal() {
    document.getElementById('aboDeleteModal').classList.remove('open');
    _aboDelId = null;
}
document.getElementById('aboDeleteBtn')?.addEventListener('click', async function() {
    if (!_aboDelId) return;
    this.disabled = true; this.textContent = 'Suppression…';
    const fd = new FormData();
    fd.append('id', _aboDelId);
    fd.append('action', 'delete');
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res  = await fetch('api/abonnement_delete.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { window.location.reload(); }
    else { alert(data.error || 'Erreur'); this.disabled = false; this.textContent = 'Supprimer définitivement'; }
});
</script>
