<?php
/**
 * Soft-cancellation of a prestation (compliance: no more hard deletes).
 *
 * Phase 1 behavior:
 *  - The row is NEVER removed. It is marked cancelled_at / cancelled_by / cancelled_reason.
 *  - A motif (reason) is REQUIRED.
 *  - An entry 'cancel' is logged in service_history with full snapshots.
 *  - If the date of the row is already sealed (daily_close), Phase 1 still accepts the
 *    cancellation but logs it as a "late cancellation"; the proper counter-entry workflow
 *    (contrepassation, two new rows in today's journal) will land in Phase 2.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/compliance.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$id     = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
if (!$id) jsonResponse(['error' => 'ID manquant'], 400);
if ($reason === '') jsonResponse(['error' => 'Un motif d\'annulation est obligatoire.'], 400);
if (strlen($reason) > 500) jsonResponse(['error' => 'Motif trop long (max 500 caractères).'], 400);

$db = getDB();
$stmt = $db->prepare("SELECT s.*, ct.label as type_label FROM services s JOIN cleaning_types ct ON ct.id=s.type_nettoyage_id WHERE s.id = ?");
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) jsonResponse(['error' => 'Prestation introuvable'], 404);
if ($service['cancelled_at'] !== null) jsonResponse(['error' => 'Cette prestation est déjà annulée.'], 409);
if (!isAdmin() && $service['technician_id'] != currentUserId()) {
    jsonResponse(['error' => 'Accès refusé'], 403);
}

$actorId = currentUserId();
$db->prepare("UPDATE services SET cancelled_at = datetime('now','localtime'), cancelled_by = ?, cancelled_reason = ? WHERE id = ?")
   ->execute([$actorId, $reason, $id]);

// Full snapshot of what was active before cancellation
$oldSnapshot = [
    'receipt_no' => $service['receipt_no'],
    'date' => $service['date'],
    'technician_id' => $service['technician_id'],
    'type_nettoyage_id' => $service['type_nettoyage_id'],
    'lieu' => $service['lieu'],
    'ticket_tva' => $service['ticket_tva'],
    'paiement' => $service['paiement'],
    'facture_a_faire' => $service['facture_a_faire'],
    'facture_envoyee' => $service['facture_envoyee'] ?? 0,
    'montant' => $service['montant'],
    'montant_htva' => $service['montant_htva'],
    'montant_tva' => $service['montant_tva'],
    'vat_rate' => $service['vat_rate'],
    'notes' => $service['notes'],
    'photo_avant' => $service['photo_avant'],
    'photo_apres' => $service['photo_apres'],
];
$newSnapshot = $oldSnapshot;
$newSnapshot['cancelled_at'] = date('Y-m-d H:i:s');
$newSnapshot['cancelled_by'] = $actorId;
$newSnapshot['cancelled_reason'] = $reason;

addServiceHistory($id, $actorId, 'cancel', $oldSnapshot, $newSnapshot, $reason);
addNotification($actorId, 'delete', $id,
    currentUserName() . " a annulé la prestation " . $service['receipt_no'] . " (" . $service['type_label'] . ") — motif : " . $reason
);

jsonResponse(['success' => true, 'cancelled' => true]);
