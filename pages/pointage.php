<?php
$db     = getDB();
$userId = currentUserId();
$isAdm  = isAdmin();

$ptFmt = function(float $h): string {
    if ($h <= 0) return '0h 0m';
    $hrs  = (int)$h;
    $mins = (int)(round(($h - $hrs) * 60));
    return "{$hrs}h {$mins}m";
};

// Open session for current user
$openStmt = $db->prepare("SELECT id, debut FROM pointages WHERE technician_id=? AND fin IS NULL ORDER BY debut DESC LIMIT 1");
$openStmt->execute([$userId]);
$openSession = $openStmt->fetch();

// Today's sessions
$today      = date('Y-m-d');
$todayStmt  = $db->prepare("
    SELECT debut, fin,
    CASE WHEN fin IS NOT NULL THEN
        printf('%d:%02d',
            CAST((julianday(fin)-julianday(debut))*24 AS INTEGER),
            CAST(((julianday(fin)-julianday(debut))*24 - CAST((julianday(fin)-julianday(debut))*24 AS INTEGER))*60 AS INTEGER)
        )
    ELSE NULL
    END as duree
    FROM pointages WHERE technician_id=? AND date=?
    ORDER BY debut ASC
");
$todayStmt->execute([$userId, $today]);
$todaySessions = $todayStmt->fetchAll();

// Personal month filter
$selYear  = max(2020, min(2030, (int)($_GET['year']  ?? date('Y'))));
$selMonth = max(1,    min(12,   (int)($_GET['month'] ?? date('n'))));
$myMonthStr = sprintf('%04d-%02d', $selYear, $selMonth);

$myHoursStmt = $db->prepare("
    SELECT COALESCE(SUM(CASE WHEN fin IS NOT NULL THEN (julianday(fin)-julianday(debut))*24 ELSE 0 END),0) as heures,
           COUNT(*) as nb_sessions
    FROM pointages WHERE technician_id=? AND strftime('%Y-%m', debut)=?
");
$myHoursStmt->execute([$userId, $myMonthStr]);
$myHoursData = $myHoursStmt->fetch();

$myPrestStmt = $db->prepare("
    SELECT COUNT(*) as nb, COALESCE(SUM(montant),0) as ca
    FROM services WHERE technician_id=? AND strftime('%Y-%m', date)=?
");
$myPrestStmt->execute([$userId, $myMonthStr]);
$myPresta = $myPrestStmt->fetch();

$mySessionsStmt = $db->prepare("
    SELECT date(debut) as day,
           strftime('%H:%M', debut) as h_debut,
           strftime('%H:%M', fin) as h_fin,
           CASE WHEN fin IS NOT NULL THEN
               printf('%d:%02d',
                   CAST((julianday(fin)-julianday(debut))*24 AS INTEGER),
                   CAST(((julianday(fin)-julianday(debut))*24 - CAST((julianday(fin)-julianday(debut))*24 AS INTEGER))*60 AS INTEGER)
               )
           ELSE 'En cours'
           END as duree
    FROM pointages WHERE technician_id=? AND strftime('%Y-%m', debut)=?
    ORDER BY debut DESC
");
$mySessionsStmt->execute([$userId, $myMonthStr]);
$mySessions = $mySessionsStmt->fetchAll();

// Admin section
$techStats    = [];
$techSessions = [];
$adminYear    = (int)date('Y');
$adminMonth   = (int)date('n');
$adminMonthStr = '';
$exportUrl    = '';

if ($isAdm) {
    $adminYear     = max(2020, min(2030, (int)($_GET['ayear']  ?? date('Y'))));
    $adminMonth    = max(1,    min(12,   (int)($_GET['amonth'] ?? date('n'))));
    $adminMonthStr = sprintf('%04d-%02d', $adminYear, $adminMonth);

    $techStatsStmt = $db->prepare("
        SELECT t.id, t.name, t.color,
               COALESCE(ph.total_hours, 0) as heures,
               COALESCE(ph.nb_sessions, 0) as nb_sessions,
               COALESCE(sh.nb_presta, 0) as nb_presta,
               COALESCE(sh.ca, 0) as ca
        FROM technicians t
        LEFT JOIN (
            SELECT technician_id,
                   SUM(CASE WHEN fin IS NOT NULL THEN (julianday(fin)-julianday(debut))*24 ELSE 0 END) as total_hours,
                   COUNT(*) as nb_sessions
            FROM pointages WHERE strftime('%Y-%m', debut)=?
            GROUP BY technician_id
        ) ph ON ph.technician_id = t.id
        LEFT JOIN (
            SELECT technician_id, COUNT(*) as nb_presta, COALESCE(SUM(montant),0) as ca
            FROM services WHERE strftime('%Y-%m', date)=?
            GROUP BY technician_id
        ) sh ON sh.technician_id = t.id
        WHERE t.active=1
        ORDER BY t.name ASC
    ");
    $techStatsStmt->execute([$adminMonthStr, $adminMonthStr]);
    $techStats = $techStatsStmt->fetchAll();

    foreach ($techStats as $ts) {
        $sessStmt = $db->prepare("
            SELECT id,
                   date(debut) as day,
                   strftime('%H:%M', debut) as h_debut,
                   strftime('%H:%M', fin) as h_fin,
                   CASE WHEN fin IS NOT NULL THEN
                       printf('%d:%02d',
                           CAST((julianday(fin)-julianday(debut))*24 AS INTEGER),
                           CAST(((julianday(fin)-julianday(debut))*24 - CAST((julianday(fin)-julianday(debut))*24 AS INTEGER))*60 AS INTEGER)
                       )
                   ELSE 'En cours'
                   END as duree,
                   COALESCE(notes, '') as notes
            FROM pointages WHERE technician_id=? AND strftime('%Y-%m', debut)=?
            ORDER BY debut ASC
        ");
        $sessStmt->execute([$ts['id'], $adminMonthStr]);
        $techSessions[$ts['id']] = $sessStmt->fetchAll();
    }

    $exportUrl = 'api/pointage_export.php?csrf_token=' . urlencode($csrfToken)
        . '&year=' . $adminYear . '&month=' . $adminMonth;
}

$monthNames  = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$currentYear = (int)date('Y');
?>

<div class="pointage-page">

<?php if ($isAdm): ?>
<!-- ===== VUE ADMIN ===== -->
<div class="section-card pt-admin-card">
  <div class="pt-admin-header">
    <h2 class="card-title" style="margin:0">Vue administrateur</h2>
    <div class="pt-admin-controls">
      <select id="adminMonthSel" onchange="updateAdminFilter()">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m === $adminMonth ? 'selected' : '' ?>><?= $monthNames[$m] ?></option>
        <?php endfor; ?>
      </select>
      <select id="adminYearSel" onchange="updateAdminFilter()">
        <?php for ($y = $currentYear - 2; $y <= $currentYear + 1; $y++): ?>
        <option value="<?= $y ?>" <?= $y === $adminYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <button class="btn btn-sm" onclick="openPtCreate()" style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Ajouter
      </button>
      <a id="exportLink" href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-sm" style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
        Exporter
      </a>
    </div>
  </div>

  <?php if (empty($techStats)): ?>
  <p class="stats-empty">Aucun technicien actif.</p>
  <?php else: ?>
  <div class="stats-table-wrap" style="margin-top:16px;">
    <table class="stats-table">
      <thead>
        <tr>
          <th>Technicien</th>
          <th class="th-num">Heures pointées</th>
          <th class="th-num">Prestations</th>
          <th class="th-num">CA généré</th>
          <th class="th-num">CA / heure</th>
          <th style="width:32px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($techStats as $ts):
          $ratio = $ts['heures'] > 0 ? ($ts['ca'] / $ts['heures']) : 0;
        ?>
        <tr class="pt-admin-row" onclick="toggleTechDetail(<?= $ts['id'] ?>, this)">
          <td>
            <span class="tech-dot" style="background:<?= htmlspecialchars($ts['color']) ?>"></span>
            <?= htmlspecialchars($ts['name']) ?>
          </td>
          <td class="td-num"><?= $ptFmt((float)$ts['heures']) ?></td>
          <td class="td-num"><?= (int)$ts['nb_presta'] ?></td>
          <td class="td-num"><?= number_format((float)$ts['ca'], 2, ',', ' ') ?> €</td>
          <td class="td-num"><?= $ratio > 0 ? number_format($ratio, 2, ',', ' ') . ' €/h' : '—' ?></td>
          <td class="pt-toggle-cell">
            <svg class="pt-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><polyline points="6 9 12 15 18 9"/></svg>
          </td>
        </tr>
        <tr class="pt-detail-row" id="detail-<?= $ts['id'] ?>" style="display:none;">
          <td colspan="6" class="pt-detail-cell">
            <?php if (empty($techSessions[$ts['id']])): ?>
            <p style="color:var(--gray-400);font-size:.8rem;padding:6px 0;">Aucune session ce mois-ci.</p>
            <?php else: ?>
            <table class="pt-sub-table">
              <thead><tr><th>Date</th><th>Début</th><th>Fin</th><th>Durée</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($techSessions[$ts['id']] as $sess): ?>
                <tr>
                  <td><?= htmlspecialchars($sess['day']) ?></td>
                  <td><?= htmlspecialchars($sess['h_debut']) ?></td>
                  <td><?= $sess['h_fin'] ?? '<em style="color:var(--gray-400)">En cours</em>' ?></td>
                  <td><?= htmlspecialchars($sess['duree']) ?></td>
                  <td class="pt-sub-actions" onclick="event.stopPropagation()">
                    <button class="btn-icon btn-edit"
                        data-id="<?= $sess['id'] ?>"
                        data-date="<?= htmlspecialchars($sess['day']) ?>"
                        data-debut="<?= htmlspecialchars($sess['h_debut']) ?>"
                        data-fin="<?= htmlspecialchars($sess['h_fin'] ?? '') ?>"
                        data-notes="<?= htmlspecialchars($sess['notes']) ?>"
                        onclick="openPtEdit(this)" title="Modifier">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="btn-icon btn-delete"
                        onclick="deletePtSession(<?= $sess['id'] ?>)" title="Supprimer">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===== WIDGET POINTAGE ===== -->
<div class="section-card pt-clock-card">
  <div class="pt-clock-inner">
    <?php if ($openSession): ?>
    <div class="pt-status-badge pt-active">
      <span class="pt-dot"></span> En travail
    </div>
    <div class="pt-timer" id="liveTimer" data-elapsed="<?= max(0, time() - strtotime($openSession['debut'])) ?>">00:00:00</div>
    <p class="pt-since">Depuis <?= date('H:i', strtotime($openSession['debut'])) ?></p>
    <button class="btn btn-stop-pt" id="btnStop" onclick="stopSession()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
      Terminer la journée
    </button>
    <?php else: ?>
    <div class="pt-status-badge pt-idle">
      <span class="pt-dot idle"></span> Pas encore pointé
    </div>
    <button class="btn btn-start-pt" id="btnStart" onclick="startSession()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Démarrer la journée
    </button>
    <?php endif; ?>
  </div>

  <?php if (!empty($todaySessions)): ?>
  <div class="pt-today-sessions">
    <p class="pt-today-title">Aujourd'hui</p>
    <?php foreach ($todaySessions as $s): ?>
    <div class="pt-session-item">
      <span><?= date('H:i', strtotime($s['debut'])) ?></span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      <span><?= $s['fin'] ? date('H:i', strtotime($s['fin'])) : 'En cours' ?></span>
      <?php if ($s['duree']): ?>
      <span class="pt-session-dur"><?= htmlspecialchars($s['duree']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ===== DASHBOARD PERSONNEL ===== -->
<div class="section-card">
  <div class="pt-dash-header">
    <h2 class="card-title" style="margin:0">Mon tableau de bord</h2>
    <div class="pt-month-filter">
      <select id="selMonth" onchange="updatePersonalFilter()">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m === $selMonth ? 'selected' : '' ?>><?= $monthNames[$m] ?></option>
        <?php endfor; ?>
      </select>
      <select id="selYear" onchange="updatePersonalFilter()">
        <?php for ($y = $currentYear - 2; $y <= $currentYear + 1; $y++): ?>
        <option value="<?= $y ?>" <?= $y === $selYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>

  <div class="pt-kpis">
    <div class="pt-kpi">
      <div class="pt-kpi-val"><?= $ptFmt((float)$myHoursData['heures']) ?></div>
      <div class="pt-kpi-lbl">Heures pointées</div>
    </div>
    <div class="pt-kpi">
      <div class="pt-kpi-val"><?= (int)$myPresta['nb'] ?></div>
      <div class="pt-kpi-lbl">Prestations</div>
    </div>
    <div class="pt-kpi">
      <div class="pt-kpi-val"><?= number_format((float)$myPresta['ca'], 0, ',', ' ') ?> €</div>
      <div class="pt-kpi-lbl">CA généré</div>
    </div>
  </div>

  <?php if (!empty($mySessions)): ?>
  <div class="stats-table-wrap" style="margin-top:16px;">
    <table class="stats-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Début</th>
          <th>Fin</th>
          <th class="th-num">Durée</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($mySessions as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['day']) ?></td>
          <td><?= htmlspecialchars($s['h_debut']) ?></td>
          <td><?= $s['h_fin'] ?? '<em style="color:var(--gray-400)">En cours</em>' ?></td>
          <td class="td-num"><?= htmlspecialchars($s['duree']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <p class="stats-empty" style="margin-top:16px;">Aucune session pour <?= $monthNames[$selMonth] ?> <?= $selYear ?>.</p>
  <?php endif; ?>
</div>

</div><!-- .pointage-page -->

<?php if ($isAdm): ?>
<!-- Modal édition/création pointage (admin) -->
<div class="modal-overlay" id="ptEditOverlay">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="ptEditTitle">Modifier le pointage</h3>
      <button class="modal-close" onclick="closePtEdit()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="ptEditId">
      <div class="form-group" id="ptEditTechGroup" style="display:none;">
        <label class="form-label">Technicien</label>
        <select id="ptEditTech" class="form-input">
          <?php foreach ($techStats as $ts): ?>
          <option value="<?= $ts['id'] ?>"><?= htmlspecialchars($ts['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Date</label>
        <input type="date" id="ptEditDate" class="form-input">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
          <label class="form-label">Heure de début</label>
          <input type="time" id="ptEditDebut" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Heure de fin <span style="color:var(--gray-400);font-weight:400">(optionnel)</span></label>
          <input type="time" id="ptEditFin" class="form-input">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Notes <span style="color:var(--gray-400);font-weight:400">(optionnel)</span></label>
        <textarea id="ptEditNotes" class="form-input" rows="2" style="resize:vertical;"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closePtEdit()">Annuler</button>
      <button class="btn" onclick="savePtEdit()">Enregistrer</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function() {
  // Live timer — elapsed seconds calculated server-side (PHP time() - strtotime(debut))
  // avoids any browser/server timezone mismatch
  const timerEl = document.getElementById('liveTimer');
  if (timerEl) {
    let elapsed = parseInt(timerEl.dataset.elapsed) || 0;
    function tick() {
      const h = Math.floor(elapsed / 3600);
      const m = Math.floor((elapsed % 3600) / 60);
      const s = elapsed % 60;
      timerEl.textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
      elapsed++;
    }
    tick();
    setInterval(tick, 1000);
  }

  function callPointage(action, btnId) {
    const CSRF = document.getElementById('csrfToken').value;
    const btn = document.getElementById(btnId);
    if (btn) btn.disabled = true;
    fetch('api/pointage_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `csrf_token=${encodeURIComponent(CSRF)}&action=${action}`
    })
    .then(r => r.json())
    .then(d => {
      if (d.success) { location.reload(); }
      else { alert(d.error || 'Erreur'); if (btn) btn.disabled = false; }
    })
    .catch(() => { alert('Erreur réseau'); if (btn) btn.disabled = false; });
  }

  window.startSession = () => callPointage('start', 'btnStart');
  window.stopSession  = function() {
    if (!confirm('Terminer la session en cours ?')) return;
    callPointage('stop', 'btnStop');
  };

  window.updatePersonalFilter = function() {
    const url = new URL(window.location.href);
    url.searchParams.set('month', document.getElementById('selMonth').value);
    url.searchParams.set('year',  document.getElementById('selYear').value);
    window.location.href = url.toString();
  };

  window.updateAdminFilter = function() {
    const month = document.getElementById('adminMonthSel')?.value;
    const year  = document.getElementById('adminYearSel')?.value;
    if (!month) return;
    const url = new URL(window.location.href);
    url.searchParams.set('amonth', month);
    url.searchParams.set('ayear',  year);
    window.location.href = url.toString();
  };

  window.toggleTechDetail = function(id, row) {
    const detail = document.getElementById('detail-' + id);
    if (!detail) return;
    const open = detail.style.display !== 'none';
    detail.style.display = open ? 'none' : 'table-row';
    const icon = row.querySelector('.pt-toggle-icon');
    if (icon) icon.style.transform = open ? '' : 'rotate(180deg)';
  };

  // Admin: open edit modal (existing session)
  window.openPtEdit = function(btn) {
    document.getElementById('ptEditTitle').textContent = 'Modifier le pointage';
    document.getElementById('ptEditTechGroup').style.display = 'none';
    document.getElementById('ptEditId').value    = btn.dataset.id;
    document.getElementById('ptEditDate').value  = btn.dataset.date;
    document.getElementById('ptEditDebut').value = btn.dataset.debut;
    document.getElementById('ptEditFin').value   = btn.dataset.fin;
    document.getElementById('ptEditNotes').value = btn.dataset.notes;
    document.getElementById('ptEditOverlay').classList.add('open');
  };

  // Admin: open create modal (new session)
  window.openPtCreate = function() {
    document.getElementById('ptEditTitle').textContent = 'Ajouter un pointage';
    document.getElementById('ptEditTechGroup').style.display = '';
    document.getElementById('ptEditId').value    = '';
    document.getElementById('ptEditDate').value  = new Date().toISOString().slice(0, 10);
    document.getElementById('ptEditDebut').value = '';
    document.getElementById('ptEditFin').value   = '';
    document.getElementById('ptEditNotes').value = '';
    document.getElementById('ptEditOverlay').classList.add('open');
  };

  window.closePtEdit = function() {
    document.getElementById('ptEditOverlay').classList.remove('open');
  };

  window.savePtEdit = function() {
    const CSRF    = document.getElementById('csrfToken').value;
    const id      = document.getElementById('ptEditId').value;
    const date    = document.getElementById('ptEditDate').value;
    const h_debut = document.getElementById('ptEditDebut').value;
    const h_fin   = document.getElementById('ptEditFin').value;
    const notes   = document.getElementById('ptEditNotes').value;
    if (!date || !h_debut) { alert('La date et l\'heure de début sont requises.'); return; }

    const params = { csrf_token: CSRF, date, h_debut, h_fin, notes };
    if (id) {
      params.action = 'update';
      params.id = id;
    } else {
      params.action = 'create';
      params.technician_id = document.getElementById('ptEditTech').value;
    }
    const body = new URLSearchParams(params);
    fetch('api/pointage_edit.php', { method: 'POST', body })
      .then(r => r.json())
      .then(d => { if (d.success) { location.reload(); } else { alert(d.error || 'Erreur'); } })
      .catch(() => alert('Erreur réseau'));
  };

  // Admin: delete a session
  window.deletePtSession = function(id) {
    if (!confirm('Supprimer cette session de pointage ?')) return;
    const CSRF = document.getElementById('csrfToken').value;
    fetch('api/pointage_edit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `csrf_token=${encodeURIComponent(CSRF)}&action=delete&id=${id}`
    })
    .then(r => r.json())
    .then(d => { if (d.success) { location.reload(); } else { alert(d.error || 'Erreur'); } })
    .catch(() => alert('Erreur réseau'));
  };
})();
</script>
