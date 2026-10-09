<?php
// Admin-only page (also gated at routing level in index.php via $adminPages)
$isAdm = isAdmin();

$monthNames = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$selYear  = max(2020, min(2030, (int)($_GET['year']  ?? date('Y'))));
$selMonth = max(1,    min(12,   (int)($_GET['month'] ?? date('n'))));
$mStr = sprintf('%04d-%02d', $selYear, $selMonth);
// Prev / next month for navigation
$prevMonth = $selMonth == 1 ? 12 : $selMonth - 1;
$prevYear  = $selMonth == 1 ? $selYear - 1 : $selYear;
$nextMonth = $selMonth == 12 ? 1 : $selMonth + 1;
$nextYear  = $selMonth == 12 ? $selYear + 1 : $selYear;

$note = getSetting("note_$mStr");
?>

<div class="notes-page">
    <div class="cash-month-nav">
        <button class="cash-month-arrow" onclick="notesGoMonth(<?= $prevYear ?>, <?= $prevMonth ?>)" aria-label="Mois précédent">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <span class="cash-month-label"><?= $monthNames[$selMonth] ?> <?= $selYear ?></span>
        <button class="cash-month-arrow" onclick="notesGoMonth(<?= $nextYear ?>, <?= $nextMonth ?>)" aria-label="Mois suivant">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>

    <div class="section-card">
        <div class="section-header">
            <h2 class="section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Note de <?= $monthNames[$selMonth] ?> <?= $selYear ?>
            </h2>
        </div>
        <p class="notes-help">Visible uniquement par les administrateurs. Chaque mois a sa propre note ; elle est incluse dans les exports mensuels.</p>

        <textarea id="noteText" class="form-input notes-textarea" rows="12" placeholder="Choses spéciales à prendre en compte ce mois-ci…"><?= htmlspecialchars($note) ?></textarea>

        <div class="notes-actions">
            <span id="noteStatus" class="notes-status"></span>
            <button class="btn btn-primary" id="noteSaveBtn" onclick="saveNote()">Enregistrer</button>
        </div>
    </div>
</div>

<script>
function notesGoMonth(year, month) {
    const url = new URL(window.location.href);
    url.searchParams.set('year', year);
    url.searchParams.set('month', month);
    window.location.href = url.toString();
}

async function saveNote() {
    const btn    = document.getElementById('noteSaveBtn');
    const status = document.getElementById('noteStatus');
    btn.disabled = true;
    status.textContent = '';
    status.className = 'notes-status';

    const fd = new FormData();
    fd.append('month', '<?= $mStr ?>');
    fd.append('note', document.getElementById('noteText').value);
    fd.append('csrf_token', document.getElementById('csrfToken').value);

    try {
        const res = await fetch('api/note_save.php', {method: 'POST', body: fd});
        const data = await res.json();
        if (data.success) {
            status.textContent = 'Enregistré ✓';
            status.classList.add('notes-status-ok');
        } else {
            status.textContent = data.error || 'Erreur';
            status.classList.add('notes-status-err');
        }
    } catch (e) {
        status.textContent = 'Erreur réseau';
        status.classList.add('notes-status-err');
    }
    btn.disabled = false;
}

// Clear the "saved" indicator as soon as the note is edited again
document.getElementById('noteText').addEventListener('input', function () {
    const status = document.getElementById('noteStatus');
    status.textContent = '';
    status.className = 'notes-status';
});
</script>
