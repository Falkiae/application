<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

$csrf = $_GET['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) { http_response_code(403); die('Token invalide'); }

require_once __DIR__ . '/../lib/XlsxWriter.php';

$db    = getDB();
$year  = max(2020, min(2030, (int)($_GET['year']  ?? date('Y'))));
$month = max(1,    min(12,   (int)($_GET['month'] ?? date('n'))));
$tech  = (int)($_GET['tech'] ?? 0);

$monthStr   = sprintf('%04d-%02d', $year, $month);
$monthLabel = sprintf('%02d-%04d', $month, $year);

$where  = "strftime('%Y-%m', p.debut) = ?";
$params = [$monthStr];
if ($tech > 0) { $where .= " AND p.technician_id = ?"; $params[] = $tech; }

$stmt = $db->prepare("
    SELECT t.name as technicien,
           date(p.debut) as date,
           strftime('%H:%M', p.debut) as heure_debut,
           CASE WHEN p.fin IS NOT NULL THEN strftime('%H:%M', p.fin) ELSE '' END as heure_fin,
           CASE WHEN p.fin IS NOT NULL THEN
               printf('%d:%02d',
                   CAST((julianday(p.fin)-julianday(p.debut))*24 AS INTEGER),
                   CAST(((julianday(p.fin)-julianday(p.debut))*24 - CAST((julianday(p.fin)-julianday(p.debut))*24 AS INTEGER))*60 AS INTEGER)
               )
           ELSE 'En cours'
           END as duree,
           COALESCE(p.notes, '') as notes
    FROM pointages p
    JOIN technicians t ON t.id = p.technician_id
    WHERE $where
    ORDER BY t.name ASC, p.debut ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_NUM);

$headers = ['Technicien', 'Date', 'Heure début', 'Heure fin', 'Durée (h:mm)', 'Notes'];

$xlsx = new XlsxWriter();
// Admin monthly note (settings key note_YYYY-MM) — first sheet if present
$note = getSetting("note_$monthStr");
if ($note !== '') {
    $xlsx->addSheet('Note du mois', ['Note'], [[$note]]);
}
$xlsx->addSheet("Pointages $monthLabel", $headers, $rows);
$xlsx->download("keepnew_pointages_$monthLabel.xlsx");
