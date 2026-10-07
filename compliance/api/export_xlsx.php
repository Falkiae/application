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
    SELECT s.receipt_no,
           s.date, t.name as technicien, ct.label as type_nettoyage,
           CASE s.lieu WHEN 'domicile' THEN 'Domicile' ELSE 'Atelier' END as lieu,
           CASE s.ticket_tva WHEN 1 THEN 'Oui' ELSE 'Non' END as ticket_tva,
           CASE s.paiement WHEN 'cash' THEN 'Cash' WHEN 'virement' THEN 'Virement' WHEN 'qrcode' THEN 'QR Code' ELSE 'Sur facture' END as paiement,
           CASE WHEN s.facture_a_faire = 1 OR s.facture_envoyee = 1 THEN 'Oui' ELSE 'Non' END as facture_a_faire,
           COALESCE(s.facture_ref, '') as facture_ref,
           s.vat_rate,
           s.montant as montant_tvac,
           s.montant_htva,
           s.montant_tva,
           CASE
             WHEN s.cancelled_at IS NOT NULL THEN 'Annulée'
             WHEN s.cancels_id IS NOT NULL THEN 'Annulation'
             WHEN s.supersedes_id IS NOT NULL THEN 'Correction'
             ELSE 'Actif'
           END as statut,
           s.notes, s.created_at
    FROM services s
    JOIN technicians t ON t.id = s.technician_id
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    WHERE $where1
    ORDER BY s.date ASC, s.receipt_no ASC
");
$stmt->execute($params1);
$allServices = $stmt->fetchAll(PDO::FETCH_NUM);

$headers1 = ['N° recette', 'Date', 'Technicien', 'Type de nettoyage', 'Lieu', 'Ticket TVA', 'Paiement', 'Facture', 'Réf facture', 'Taux TVA (%)', 'Montant TVAC (€)', 'Montant HTVA (€)', 'Montant TVA (€)', 'Statut', 'Notes', 'Enregistré le'];

// --- Sheet 2: Services with invoice (facture à faire ou envoyée) ---
$where2 = "strftime('%Y-%m', s.date) = ? AND (s.facture_a_faire = 1 OR s.facture_envoyee = 1)";
$params2 = [$month];
if (!$isAdm) { $where2 .= " AND s.technician_id = ?"; $params2[] = $techId; }

$stmt2 = $db->prepare("
    SELECT s.receipt_no,
           s.date, t.name as technicien, ct.label as type_nettoyage,
           CASE s.lieu WHEN 'domicile' THEN 'Domicile' ELSE 'Atelier' END as lieu,
           CASE s.ticket_tva WHEN 1 THEN 'Oui' ELSE 'Non' END as ticket_tva,
           CASE s.paiement WHEN 'cash' THEN 'Cash' WHEN 'virement' THEN 'Virement' WHEN 'qrcode' THEN 'QR Code' ELSE 'Sur facture' END as paiement,
           CASE WHEN s.facture_envoyee = 1 THEN 'Envoyée' ELSE 'À faire' END as statut_facture,
           COALESCE(s.facture_ref, '') as facture_ref,
           COALESCE(s.facture_date, '') as facture_date,
           s.vat_rate, s.montant as montant_tvac, s.montant_htva, s.montant_tva,
           s.notes
    FROM services s
    JOIN technicians t ON t.id = s.technician_id
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    WHERE $where2
    ORDER BY s.date ASC, s.receipt_no ASC
");
$stmt2->execute($params2);
$invoiceServices = $stmt2->fetchAll(PDO::FETCH_NUM);

$headers2 = ['N° recette', 'Date', 'Technicien', 'Type de nettoyage', 'Lieu', 'Ticket TVA', 'Paiement', 'Statut facture', 'Réf facture', 'Date facture', 'Taux TVA (%)', 'Montant TVAC (€)', 'Montant HTVA (€)', 'Montant TVA (€)', 'Notes'];

// --- Sheet 3: Cash movements ---
$where3 = "strftime('%Y-%m', date) = ?";
$params3 = [$month];
if (!$isAdm) { $where3 .= " AND technician_id = ?"; $params3[] = $techId; }

// Cash services
$stmt3a = $db->prepare("
    SELECT s.receipt_no, s.date, t.name as technicien, 'Prestation cash' as type,
           s.montant, s.notes,
           CASE
             WHEN s.cancelled_at IS NOT NULL THEN 'Annulée'
             WHEN s.cancels_id IS NOT NULL THEN 'Annulation'
             WHEN s.supersedes_id IS NOT NULL THEN 'Correction'
             ELSE 'Actif'
           END as statut
    FROM services s
    JOIN technicians t ON t.id = s.technician_id
    WHERE strftime('%Y-%m', s.date) = ? AND s.paiement = '" . ($isAdm ? "cash' " : "cash' AND s.technician_id = ? ") . "
    ORDER BY s.date ASC
");
$params3a = $isAdm ? [$month] : [$month, $techId];
$stmt3a->execute($params3a);
$cashServices = $stmt3a->fetchAll(PDO::FETCH_NUM);

// Cash movements (initial, depots, achats, notes)
$stmt3b = $db->prepare("
    SELECT cm.receipt_no, cm.date, t.name as technicien,
           CASE cm.type
             WHEN 'initial' THEN 'Montant initial'
             WHEN 'depot_banque' THEN 'Versement banque'
             WHEN 'achat_liquide' THEN 'Achat en liquide'
             ELSE 'Note'
           END as type,
           cm.montant, cm.notes,
           CASE
             WHEN cm.cancelled_at IS NOT NULL THEN 'Annulée'
             WHEN cm.cancels_id IS NOT NULL THEN 'Annulation'
             WHEN cm.supersedes_id IS NOT NULL THEN 'Correction'
             ELSE 'Actif'
           END as statut
    FROM cash_movements cm
    JOIN technicians t ON t.id = cm.technician_id
    WHERE $where3
    ORDER BY cm.date ASC
");
$stmt3b->execute($params3);
$cashMovements = $stmt3b->fetchAll(PDO::FETCH_NUM);

$allCash = array_merge($cashServices, $cashMovements);
usort($allCash, fn($a, $b) => strcmp($a[1], $b[1]));   // date is col index 1 now (col 0 = receipt_no)

$headers3 = ['N° recette', 'Date', 'Technicien', 'Type de mouvement', 'Montant (€)', 'Notes', 'Statut'];

// Generate XLSX
$xlsx = new XlsxWriter();
// Admin monthly note (settings key note_YYYY-MM) — first sheet if present
$note = getSetting("note_$month");
if ($note !== '') {
    $xlsx->addSheet('Note du mois', ['Note'], [[$note]]);
}
$xlsx->addSheet('Toutes les prestations', $headers1, $allServices);
$xlsx->addSheet('Avec facture', $headers2, $invoiceServices);
$xlsx->addSheet('Mouvements cash', $headers3, $allCash);
$xlsx->download("keepnew_$monthLabel.xlsx");
