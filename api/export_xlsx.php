<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

$csrf = $_GET['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) {
    http_response_code(403);
    die('Token invalide');
}

require_once __DIR__ . '/../lib/XlsxWriter.php';

$db = getDB();
$isAdm = isAdmin();
$techId = currentUserId();

$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    die('Mois invalide');
}

$monthLabel = DateTime::createFromFormat('Y-m', $month)->format('m-Y');

// --- Sheet 1: All services ---
$where1 = "strftime('%Y-%m', s.date) = ?";
$params1 = [$month];
if (!$isAdm) { $where1 .= " AND s.technician_id = ?"; $params1[] = $techId; }

$stmt = $db->prepare("
    SELECT s.date, t.name as technicien, ct.label as type_nettoyage,
           CASE s.lieu WHEN 'domicile' THEN 'Domicile' ELSE 'Atelier' END as lieu,
           CASE s.ticket_tva WHEN 1 THEN 'Oui' ELSE 'Non' END as ticket_tva,
           CASE s.paiement WHEN 'cash' THEN 'Cash' WHEN 'virement' THEN 'Virement' WHEN 'qrcode' THEN 'QR Code' ELSE 'Sur facture' END as paiement,
           CASE WHEN s.facture_a_faire = 1 OR s.facture_envoyee = 1 THEN 'Oui' ELSE 'Non' END as facture_a_faire,
           s.montant, ROUND(s.montant / 1.21, 2) as montant_htva,
           s.notes, s.created_at
    FROM services s
    JOIN technicians t ON t.id = s.technician_id
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    WHERE $where1
    ORDER BY s.date ASC, s.created_at ASC
");
$stmt->execute($params1);
$allServices = $stmt->fetchAll(PDO::FETCH_NUM);

$headers1 = ['Date', 'Technicien', 'Type de nettoyage', 'Lieu', 'Ticket TVA', 'Paiement', 'Facture', 'Montant TVAC (€)', 'Montant HTVA (€)', 'Notes', 'Enregistré le'];

// --- Sheet 2: Services with invoice (facture à faire ou envoyée) ---
$where2 = "strftime('%Y-%m', s.date) = ? AND (s.facture_a_faire = 1 OR s.facture_envoyee = 1)";
$params2 = [$month];
if (!$isAdm) { $where2 .= " AND s.technician_id = ?"; $params2[] = $techId; }

$stmt2 = $db->prepare("
    SELECT s.date, t.name as technicien, ct.label as type_nettoyage,
           CASE s.lieu WHEN 'domicile' THEN 'Domicile' ELSE 'Atelier' END as lieu,
           CASE s.ticket_tva WHEN 1 THEN 'Oui' ELSE 'Non' END as ticket_tva,
           CASE s.paiement WHEN 'cash' THEN 'Cash' WHEN 'virement' THEN 'Virement' WHEN 'qrcode' THEN 'QR Code' ELSE 'Sur facture' END as paiement,
           CASE WHEN s.facture_envoyee = 1 THEN 'Envoyée' ELSE 'À faire' END as statut_facture,
           s.montant, ROUND(s.montant / 1.21, 2) as montant_htva,
           s.notes
    FROM services s
    JOIN technicians t ON t.id = s.technician_id
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    WHERE $where2
    ORDER BY s.date ASC
");
$stmt2->execute($params2);
$invoiceServices = $stmt2->fetchAll(PDO::FETCH_NUM);

$headers2 = ['Date', 'Technicien', 'Type de nettoyage', 'Lieu', 'Ticket TVA', 'Paiement', 'Statut facture', 'Montant TVAC (€)', 'Montant HTVA (€)', 'Notes'];

// --- Sheet 3: Cash movements ---
$where3 = "strftime('%Y-%m', date) = ?";
$params3 = [$month];
if (!$isAdm) { $where3 .= " AND technician_id = ?"; $params3[] = $techId; }

// Cash services
$stmt3a = $db->prepare("
    SELECT s.date, t.name as technicien, 'Prestation cash' as type,
           s.montant, s.notes
    FROM services s
    JOIN technicians t ON t.id = s.technician_id
    WHERE strftime('%Y-%m', s.date) = ? AND s.paiement = '" . ($isAdm ? "cash' " : "cash' AND s.technician_id = ? ") . "
    ORDER BY s.date ASC
");
$params3a = $isAdm ? [$month] : [$month, $techId];
$stmt3a->execute($params3a);
$cashServices = $stmt3a->fetchAll(PDO::FETCH_NUM);

// Cash movements (initial, depots)
$stmt3b = $db->prepare("
    SELECT cm.date, t.name as technicien,
           CASE cm.type WHEN 'initial' THEN 'Montant initial' WHEN 'depot_banque' THEN 'Versement banque' ELSE 'Note' END as type,
           cm.montant, cm.notes
    FROM cash_movements cm
    JOIN technicians t ON t.id = cm.technician_id
    WHERE $where3
    ORDER BY cm.date ASC
");
$stmt3b->execute($params3);
$cashMovements = $stmt3b->fetchAll(PDO::FETCH_NUM);

$allCash = array_merge($cashServices, $cashMovements);
usort($allCash, fn($a, $b) => strcmp($a[0], $b[0]));

$headers3 = ['Date', 'Technicien', 'Type de mouvement', 'Montant (€)', 'Notes'];

// Generate XLSX
$xlsx = new XlsxWriter();
$xlsx->addSheet('Toutes les prestations', $headers1, $allServices);
$xlsx->addSheet('Avec facture', $headers2, $invoiceServices);
$xlsx->addSheet('Mouvements cash', $headers3, $allCash);
$xlsx->download("keepnew_$monthLabel.xlsx");
