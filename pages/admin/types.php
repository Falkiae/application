<?php
$db = getDB();
$types = $db->query("SELECT * FROM cleaning_types ORDER BY sort_order, label")->fetchAll();
$msg = $_GET['msg'] ?? '';
?>

<div class="admin-page">
    <?php if ($msg === 'saved'): ?><div class="alert alert-success">Type enregistré avec succès.</div><?php endif; ?>

    <div class="section-header">
        <h2 class="section-title">Types de nettoyage</h2>
        <button class="btn btn-primary btn-sm" onclick="showTypeForm()">+ Ajouter</button>
    </div>

    <div class="types-list" id="typesList">
        <?php foreach ($types as $i => $type): ?>
        <div class="type-list-item <?= !$type['active'] ? 'type-inactive' : '' ?>" data-id="<?= $type['id'] ?>">
            <div class="type-drag-handle">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
            </div>
            <div class="type-list-info">
                <strong><?= htmlspecialchars($type['label']) ?></strong>
                <?php if (!$type['active']): ?><span class="badge badge-inactive">Inactif</span><?php endif; ?>
            </div>
            <div class="type-list-actions">
                <button class="btn-icon btn-edit" onclick="editType(<?= htmlspecialchars(json_encode($type)) ?>)" title="Modifier">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="btn-icon <?= $type['active'] ? 'btn-delete' : 'btn-activate' ?>"
                        onclick="toggleType(<?= $type['id'] ?>, <?= $type['active'] ?>)" title="<?= $type['active'] ? 'Désactiver' : 'Activer' ?>">
                    <?php if ($type['active']): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 8 12 12 14 14"/></svg>
                    <?php endif; ?>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Type form modal -->
<div class="modal-overlay" id="typeModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3 id="typeModalTitle">Ajouter un type</h3>
            <button class="modal-close" onclick="closeTypeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="typeId" value="">
            <div class="form-group">
                <label class="form-label">Libellé</label>
                <input type="text" id="typeLabel" class="form-input" placeholder="Ex: SUV, Camping-car...">
            </div>
            <div id="typeError" class="alert alert-error" style="display:none"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeTypeModal()">Annuler</button>
            <button class="btn btn-primary" onclick="saveType()">Enregistrer</button>
        </div>
    </div>
</div>

<script>
function showTypeForm() {
    document.getElementById('typeId').value = '';
    document.getElementById('typeLabel').value = '';
    document.getElementById('typeModalTitle').textContent = 'Ajouter un type de nettoyage';
    document.getElementById('typeError').style.display = 'none';
    document.getElementById('typeModal').style.display = 'flex';
}
function editType(type) {
    document.getElementById('typeId').value = type.id;
    document.getElementById('typeLabel').value = type.label;
    document.getElementById('typeModalTitle').textContent = 'Modifier ' + type.label;
    document.getElementById('typeError').style.display = 'none';
    document.getElementById('typeModal').style.display = 'flex';
}
function closeTypeModal() { document.getElementById('typeModal').style.display = 'none'; }

async function saveType() {
    const id = document.getElementById('typeId').value;
    const label = document.getElementById('typeLabel').value.trim();
    const err = document.getElementById('typeError');
    err.style.display = 'none';
    if (!label) { err.textContent = 'Le libellé est requis'; err.style.display = 'block'; return; }
    const fd = new FormData();
    fd.append('id', id);
    fd.append('label', label);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/admin_type_save.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) window.location.href = 'index.php?page=admin/types&msg=saved';
    else { err.textContent = data.error || 'Erreur'; err.style.display = 'block'; }
}

async function toggleType(id, active) {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('active', active ? '0' : '1');
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/admin_type_save.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) window.location.reload();
}
</script>
