<?php
$db    = getDB();
$isAdm = isAdmin();
$id    = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php?page=abonnements'); exit; }

$stmt = $db->prepare("
    SELECT a.*, c.nom as client_nom,
        COALESCE(SUM(p.nettoyages_debites),0) as utilises
    FROM abonnements a
    JOIN clients c ON c.id = a.client_id
    LEFT JOIN abonnement_passages p ON p.abonnement_id = a.id
    WHERE a.id = ? AND a.active = 1
    GROUP BY a.id
");
$stmt->execute([$id]);
$abo = $stmt->fetch();
if (!$abo) { header('Location: index.php?page=abonnements'); exit; }

$restants = $abo['nettoyages_total'] - $abo['utilises'];
$pct      = $abo['nettoyages_total'] > 0 ? round(($abo['utilises'] / $abo['nettoyages_total']) * 100) : 0;
$status   = $restants <= 0 ? 'epuise' : ($restants <= 2 ? 'faible' : 'actif');

// Passages history
$stmtP = $db->prepare("
    SELECT p.*, t.name as tech_name, t.color as tech_color
    FROM abonnement_passages p
    JOIN technicians t ON t.id = p.technician_id
    WHERE p.abonnement_id = ?
    ORDER BY p.date DESC, p.id DESC
");
$stmtP->execute([$id]);
$passages = $stmtP->fetchAll();
?>

<div class="abo-detail-page">

    <!-- Header card -->
    <div class="section-card abo-detail-header">
        <div class="abo-detail-top">
            <div>
                <h2 class="abo-detail-name"><?= htmlspecialchars($abo['client_nom']) ?></h2>
                <span class="abo-status-badge abo-badge-<?= $status ?>">
                    <?php if ($status==='epuise'): ?>Épuisé<?php elseif ($status==='faible'): ?>Bientôt épuisé<?php else: ?>Actif<?php endif; ?>
                </span>
            </div>
            <div class="abo-detail-counts">
                <span class="abo-detail-restants <?= $restants<=0?'text-red':($restants<=2?'text-orange':'text-green') ?>">
                    <?= $restants ?>
                </span>
                <span class="abo-detail-restants-label">restant<?= $restants>1?'s':'' ?></span>
            </div>
        </div>

        <div class="abo-progress-wrap abo-progress-lg">
            <div class="abo-progress-bar" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="abo-detail-sub">
            <?= $abo['utilises'] ?> utilisé<?= $abo['utilises']>1?'s':'' ?> · <?= $abo['nettoyages_total'] ?> total
            <?php if ($abo['prix_total'] > 0): ?>
             · <?= number_format($abo['prix_total'], 2, ',', '.') ?> €
            <?php endif; ?>
        </div>
        <?php if ($abo['notes']): ?>
        <div class="abo-detail-notes"><?= htmlspecialchars($abo['notes']) ?></div>
        <?php endif; ?>
    </div>

    <!-- Passage form -->
    <?php if ($status !== 'epuise'): ?>
    <div class="section-card" id="passageFormCard">
        <h3 class="card-section-title">Encoder un passage</h3>
        <input type="hidden" id="aboId" value="<?= $id ?>">
        <input type="hidden" id="passagePhotoAvant">
        <input type="hidden" id="passagePhotoApres">

        <div class="form-group">
            <label class="form-label">Date</label>
            <input type="date" id="passageDate" class="form-input" value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Nombre de nettoyages à décompter</label>
            <div class="stepper-wrapper">
                <button type="button" class="stepper-btn" onclick="stepPassage(-1)">−</button>
                <input type="number" id="passageNb" class="form-input stepper-input" value="1" min="1" max="<?= $restants ?>">
                <button type="button" class="stepper-btn" onclick="stepPassage(1)">+</button>
            </div>
            <p class="step-note">Max <?= $restants ?> nettoyage<?= $restants>1?'s':'' ?> disponible<?= $restants>1?'s':'' ?></p>
        </div>

        <!-- Photo avant -->
        <div class="form-group">
            <label class="form-label">Photo avant <span class="label-optional">(optionnel)</span></label>
            <div class="photo-capture-area" id="pAvantArea">
                <div class="photo-placeholder" id="pAvantPlaceholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <span>Aucune photo</span>
                </div>
                <img id="pAvantPreview" class="photo-preview" style="display:none" alt="Avant">
            </div>
            <div class="photo-actions">
                <label class="btn btn-outline btn-camera" for="pAvantInput">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Choisir une photo
                </label>
                <input type="file" id="pAvantInput" accept="image/*" class="photo-file-input">
                <button type="button" class="btn btn-outline btn-sm" id="pAvantClear" style="display:none" onclick="clearPassagePhoto('avant')">Retirer</button>
            </div>
        </div>

        <!-- Photo après -->
        <div class="form-group">
            <label class="form-label">Photo après <span class="label-optional">(optionnel)</span></label>
            <div class="photo-capture-area" id="pApresArea">
                <div class="photo-placeholder" id="pApresPlaceholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <span>Aucune photo</span>
                </div>
                <img id="pApresPreview" class="photo-preview" style="display:none" alt="Après">
            </div>
            <div class="photo-actions">
                <label class="btn btn-outline btn-camera" for="pApresInput">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Choisir une photo
                </label>
                <input type="file" id="pApresInput" accept="image/*" class="photo-file-input">
                <button type="button" class="btn btn-outline btn-sm" id="pApresClear" style="display:none" onclick="clearPassagePhoto('apres')">Retirer</button>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Notes <span class="label-optional">(optionnel)</span></label>
            <textarea id="passageNotes" class="form-input form-textarea" rows="2" placeholder="Ex: 2 voitures, berline + SUV..."></textarea>
        </div>

        <div id="passageError" class="alert alert-error" style="display:none"></div>
        <button class="btn btn-primary btn-full" id="passageSubmitBtn" onclick="submitPassage()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            Enregistrer le passage
        </button>
    </div>
    <?php endif; ?>

    <!-- Passages history -->
    <?php if (!empty($passages)): ?>
    <div class="section-card">
        <h3 class="card-section-title">Historique des passages (<?= count($passages) ?>)</h3>
        <div class="passage-list">
            <?php foreach ($passages as $p): ?>
            <div class="passage-item">
                <div class="passage-item-top">
                    <div class="tech-avatar tech-avatar-sm" style="background:<?= htmlspecialchars($p['tech_color']) ?>">
                        <?= strtoupper(substr($p['tech_name'], 0, 1)) ?>
                    </div>
                    <div class="passage-info">
                        <span class="passage-date"><?= date('d/m/Y', strtotime($p['date'])) ?></span>
                        <span class="passage-tech"><?= htmlspecialchars($p['tech_name']) ?></span>
                        <?php if ($p['notes']): ?><span class="passage-notes-text"><?= htmlspecialchars($p['notes']) ?></span><?php endif; ?>
                    </div>
                    <span class="passage-debit">−<?= $p['nettoyages_debites'] ?> nettoyage<?= $p['nettoyages_debites']>1?'s':'' ?></span>
                </div>
                <?php if ($p['photo_avant'] || $p['photo_apres']): ?>
                <div class="passage-photos">
                    <?php if ($p['photo_avant']): ?>
                    <div class="passage-photo-wrap">
                        <span class="passage-photo-label">Avant</span>
                        <img src="uploads/<?= htmlspecialchars($p['photo_avant']) ?>" class="passage-photo photo-thumb-clickable" alt="Avant"
                             onclick="openPassageLightbox('uploads/<?= htmlspecialchars($p['photo_avant']) ?>', 'Photo avant')">
                    </div>
                    <?php endif; ?>
                    <?php if ($p['photo_apres']): ?>
                    <div class="passage-photo-wrap">
                        <span class="passage-photo-label">Après</span>
                        <img src="uploads/<?= htmlspecialchars($p['photo_apres']) ?>" class="passage-photo photo-thumb-clickable" alt="Après"
                             onclick="openPassageLightbox('uploads/<?= htmlspecialchars($p['photo_apres']) ?>', 'Photo après')">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Lightbox -->
<div class="lightbox" id="passageLightbox">
    <div class="lightbox-inner">
        <div class="lightbox-header">
            <span class="lightbox-label" id="passageLightboxLabel"></span>
            <div class="lightbox-actions">
                <a class="btn btn-outline btn-sm lightbox-dl" id="passageLightboxDl" href="#" download>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Télécharger
                </a>
                <button class="lightbox-close" onclick="document.getElementById('passageLightbox').classList.remove('open')">&times;</button>
            </div>
        </div>
        <img id="passageLightboxImg" class="lightbox-img" src="" alt="">
    </div>
</div>

<script>
const _maxPassage = <?= $restants ?>;

function stepPassage(d) {
    const inp = document.getElementById('passageNb');
    inp.value = Math.min(_maxPassage, Math.max(1, parseInt(inp.value || 1) + d));
}

function clearPassagePhoto(which) {
    const pi = document.getElementById(which === 'avant' ? 'passagePhotoAvant' : 'passagePhotoApres');
    const prev = document.getElementById(which === 'avant' ? 'pAvantPreview' : 'pApresPreview');
    const ph   = document.getElementById(which === 'avant' ? 'pAvantPlaceholder' : 'pApresPlaceholder');
    const clr  = document.getElementById(which === 'avant' ? 'pAvantClear' : 'pApresClear');
    pi.value = ''; prev.style.display = 'none'; prev.src = '';
    ph.style.display = 'flex'; if (clr) clr.style.display = 'none';
}

async function handlePassagePhoto(inputEl, which) {
    const file = inputEl.files[0]; if (!file) return;
    const ph   = document.getElementById(which === 'avant' ? 'pAvantPlaceholder' : 'pApresPlaceholder');
    ph.innerHTML = '<div class="photo-loading">Upload...</div>';
    try {
        const blob = await compressImage(file, 1200, 0.82);
        const fd = new FormData();
        fd.append('photo', blob, 'photo.jpg');
        fd.append('csrf_token', document.getElementById('csrfToken').value);
        const res  = await fetch('api/photo_upload.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.path) {
            document.getElementById(which === 'avant' ? 'passagePhotoAvant' : 'passagePhotoApres').value = data.path;
            const prev = document.getElementById(which === 'avant' ? 'pAvantPreview' : 'pApresPreview');
            prev.src = 'uploads/' + data.path; prev.style.display = 'block';
            ph.style.display = 'none';
            const clr = document.getElementById(which === 'avant' ? 'pAvantClear' : 'pApresClear');
            if (clr) clr.style.display = 'inline-flex';
        } else { ph.innerHTML = '<span>Erreur upload</span>'; ph.style.display = 'flex'; }
    } catch(e) { ph.innerHTML = '<span>Erreur réseau</span>'; ph.style.display = 'flex'; }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('pAvantInput')?.addEventListener('change', function() { handlePassagePhoto(this,'avant'); });
    document.getElementById('pApresInput')?.addEventListener('change', function() { handlePassagePhoto(this,'apres'); });
});

async function submitPassage() {
    const btn   = document.getElementById('passageSubmitBtn');
    const err   = document.getElementById('passageError');
    const nb    = parseInt(document.getElementById('passageNb').value || 1);
    const date  = document.getElementById('passageDate').value;
    const notes = document.getElementById('passageNotes').value;
    const pav   = document.getElementById('passagePhotoAvant').value;
    const pap   = document.getElementById('passagePhotoApres').value;
    err.style.display = 'none';
    if (nb < 1 || nb > _maxPassage) { err.textContent = `Nombre invalide (max ${_maxPassage})`; err.style.display = 'block'; return; }
    btn.disabled = true; btn.textContent = 'Enregistrement...';
    const fd = new FormData();
    fd.append('abonnement_id', document.getElementById('aboId').value);
    fd.append('date', date); fd.append('nettoyages_debites', nb);
    fd.append('photo_avant_path', pav); fd.append('photo_apres_path', pap);
    fd.append('notes', notes);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    const res  = await fetch('api/abonnement_passage.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) { window.location.href = 'index.php?page=abonnements'; }
    else {
        err.textContent = data.error || 'Erreur'; err.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Enregistrer le passage';
    }
}

function openPassageLightbox(src, label) {
    document.getElementById('passageLightboxImg').src = src;
    document.getElementById('passageLightboxLabel').textContent = label;
    document.getElementById('passageLightboxDl').href = src;
    document.getElementById('passageLightboxDl').download = src.split('/').pop();
    document.getElementById('passageLightbox').classList.add('open');
}
document.getElementById('passageLightbox').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.getElementById('passageLightbox').classList.remove('open'); });
</script>
