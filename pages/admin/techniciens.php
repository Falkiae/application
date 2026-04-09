<?php
$db = getDB();
$technicians = $db->query("SELECT * FROM technicians ORDER BY name")->fetchAll();
$msg = $_GET['msg'] ?? '';
?>

<div class="admin-page">
    <?php if ($msg === 'saved'): ?><div class="alert alert-success">Technicien enregistré avec succès.</div><?php endif; ?>
    <?php if ($msg === 'deleted'): ?><div class="alert alert-success">Technicien désactivé.</div><?php endif; ?>
    <?php if ($msg === 'error'): ?><div class="alert alert-error">Une erreur est survenue.</div><?php endif; ?>

    <div class="section-header">
        <h2 class="section-title">Techniciens</h2>
        <button class="btn btn-primary btn-sm" onclick="showTechForm()">+ Ajouter</button>
    </div>

    <div class="tech-list">
        <?php foreach ($technicians as $t): ?>
        <div class="tech-list-item <?= !$t['active'] ? 'tech-inactive' : '' ?>">
            <div class="tech-avatar" style="background:<?= htmlspecialchars($t['color']) ?>">
                <?= strtoupper(substr($t['name'], 0, 1)) ?>
            </div>
            <div class="tech-list-info">
                <strong><?= htmlspecialchars($t['name']) ?></strong>
                <span class="badge <?= $t['role'] === 'admin' ? 'badge-admin' : 'badge-tech' ?>">
                    <?= $t['role'] === 'admin' ? 'Admin' : 'Technicien' ?>
                </span>
                <?php if (!$t['active']): ?><span class="badge badge-inactive">Inactif</span><?php endif; ?>
            </div>
            <div class="tech-list-actions">
                <button class="btn-icon btn-edit" onclick="editTech(<?= htmlspecialchars(json_encode($t)) ?>)" title="Modifier">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <?php if ($t['id'] != currentUserId()): ?>
                <button class="btn-icon btn-delete" onclick="toggleTech(<?= $t['id'] ?>, <?= $t['active'] ?>)" title="<?= $t['active'] ? 'Désactiver' : 'Réactiver' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Tech form modal -->
<div class="modal-overlay" id="techModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 id="techModalTitle">Ajouter un technicien</h3>
            <button class="modal-close" onclick="closeTechModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="techId" value="">
            <div class="form-group">
                <label class="form-label">Prénom / Nom</label>
                <input type="text" id="techName" class="form-input" placeholder="Ex: Jean Dupont" required>
            </div>
            <div class="form-group">
                <label class="form-label">PIN (4 à 8 chiffres)</label>
                <input type="password" id="techPin" class="form-input" placeholder="Laisser vide pour ne pas changer" inputmode="numeric" maxlength="8">
            </div>
            <div class="form-group">
                <label class="form-label">Rôle</label>
                <select id="techRole" class="form-select">
                    <option value="technician">Technicien</option>
                    <option value="admin">Administrateur</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Couleur d'identification</label>
                <div class="color-picker">
                    <?php foreach (['#596FF3','#bd264b','#22c55e','#f97316','#8b5cf6','#06b6d4','#ec4899','#14b8a6'] as $color): ?>
                    <label class="color-option">
                        <input type="radio" name="techColor" value="<?= $color ?>">
                        <span style="background:<?= $color ?>"></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="techError" class="alert alert-error" style="display:none"></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeTechModal()">Annuler</button>
            <button class="btn btn-primary" onclick="saveTech()">Enregistrer</button>
        </div>
    </div>
</div>

<script>
function showTechForm() {
    document.getElementById('techId').value = '';
    document.getElementById('techName').value = '';
    document.getElementById('techPin').value = '';
    document.getElementById('techRole').value = 'technician';
    document.querySelector('input[name="techColor"][value="#596FF3"]').checked = true;
    document.getElementById('techModalTitle').textContent = 'Ajouter un technicien';
    document.getElementById('techPin').placeholder = 'PIN obligatoire (4-8 chiffres)';
    document.getElementById('techError').style.display = 'none';
    document.getElementById('techModal').style.display = 'flex';
}

function editTech(tech) {
    document.getElementById('techId').value = tech.id;
    document.getElementById('techName').value = tech.name;
    document.getElementById('techPin').value = '';
    document.getElementById('techRole').value = tech.role;
    const colorInput = document.querySelector(`input[name="techColor"][value="${tech.color}"]`);
    if (colorInput) colorInput.checked = true;
    document.getElementById('techModalTitle').textContent = 'Modifier ' + tech.name;
    document.getElementById('techPin').placeholder = 'Laisser vide pour conserver le PIN actuel';
    document.getElementById('techError').style.display = 'none';
    document.getElementById('techModal').style.display = 'flex';
}

function closeTechModal() {
    document.getElementById('techModal').style.display = 'none';
}

async function saveTech() {
    const id = document.getElementById('techId').value;
    const name = document.getElementById('techName').value.trim();
    const pin = document.getElementById('techPin').value;
    const role = document.getElementById('techRole').value;
    const color = document.querySelector('input[name="techColor"]:checked')?.value || '#596FF3';
    const err = document.getElementById('techError');
    err.style.display = 'none';

    if (!name) { err.textContent = 'Le nom est requis'; err.style.display = 'block'; return; }
    if (!id && (!pin || pin.length < 4)) { err.textContent = 'PIN requis (min 4 chiffres)'; err.style.display = 'block'; return; }
    if (pin && !/^\d{4,8}$/.test(pin)) { err.textContent = 'Le PIN doit contenir 4 à 8 chiffres'; err.style.display = 'block'; return; }

    const fd = new FormData();
    fd.append('id', id);
    fd.append('name', name);
    fd.append('pin', pin);
    fd.append('role', role);
    fd.append('color', color);
    fd.append('csrf_token', document.getElementById('csrfToken').value);

    const res = await fetch('api/admin_tech_save.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        window.location.href = 'index.php?page=admin/techniciens&msg=saved';
    } else {
        err.textContent = data.error || 'Erreur';
        err.style.display = 'block';
    }
}

async function toggleTech(id, active) {
    const action = active ? 'désactiver' : 'réactiver';
    if (!confirm(`Voulez-vous ${action} ce technicien ?`)) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('active', active ? '0' : '1');
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res = await fetch('api/admin_tech_save.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) window.location.reload();
    else alert(data.error || 'Erreur');
}
</script>
