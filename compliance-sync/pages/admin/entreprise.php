<?php
/**
 * Admin page: company identity (used on the official livre de recettes PDF header).
 * Values are stored in the `settings` key/value table with keys prefixed `company_`.
 */
$fields = [
    'company_name'    => 'Raison sociale',
    'company_form'    => 'Forme juridique (SRL, SA, …)',
    'company_bce'     => 'Numéro BCE / KBO',
    'company_vat'     => 'Numéro TVA (BE…)',
    'company_address' => 'Adresse (rue, numéro, code postal, ville, pays)',
    'company_email'   => 'Email',
    'company_phone'   => 'Téléphone',
];

$values = [];
foreach ($fields as $key => $_) {
    $values[$key] = getSetting($key);
}
?>

<div class="admin-page">
    <div class="section-card">
        <h2 class="card-title" style="margin-top:0">Fiche entreprise</h2>
        <p style="color:var(--gray-500); font-size:.88rem; margin:-4px 0 16px;">
            Ces informations apparaissent en en-tête du livre de recettes et sur les exports légaux. Elles sont requises par la loi belge (art. 5 AR n°1 TVA) pour tout document comptable officiel.
        </p>

        <form id="entrepriseForm" style="display:flex; flex-direction:column; gap:12px;">
            <?php foreach ($fields as $key => $label):
                $isTextarea = $key === 'company_address';
                $val = $values[$key];
            ?>
            <div class="form-group">
                <label class="form-label" for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                <?php if ($isTextarea): ?>
                <textarea id="<?= $key ?>" name="<?= $key ?>" class="form-input" rows="3" style="resize:vertical;"><?= htmlspecialchars($val) ?></textarea>
                <?php else: ?>
                <input type="text" id="<?= $key ?>" name="<?= $key ?>" class="form-input" value="<?= htmlspecialchars($val) ?>">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:12px;">
                <span id="entrepriseStatus" class="notes-status"></span>
                <button type="button" class="btn btn-primary" onclick="saveEntreprise()">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
async function saveEntreprise() {
    const fd = new FormData(document.getElementById('entrepriseForm'));
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const status = document.getElementById('entrepriseStatus');
    status.textContent = '';
    status.className = 'notes-status';
    try {
        const res = await fetch('api/entreprise_save.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) {
            status.textContent = 'Enregistré ✓';
            status.classList.add('notes-status-ok');
        } else {
            status.textContent = d.error || 'Erreur';
            status.classList.add('notes-status-err');
        }
    } catch (e) {
        status.textContent = 'Erreur réseau';
        status.classList.add('notes-status-err');
    }
}
</script>
