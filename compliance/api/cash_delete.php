<?php
/**
 * Soft-cancellation of a cash movement (compliance: no more hard deletes).
 * Mirrors prestation_delete.php: reason mandatory, cancelled_* fields set,
 * row stays in base, full snapshot logged in cash_history.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/compliance.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db     = getDB();
$id     = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
if (!$id) jsonResponse(['error' => 'ID manquant'], 400);
if ($reason === '') jsonResponse(['error' => 'Un motif d\'annulation est obligatoire.'], 400);
if (strlen($reason) > 500) jsonResponse(['error' => 'Motif trop long (max 500 caractères).'], 400);

$stmt = $db->prepare("SELECT * FROM cash_movements WHERE id=?");
$stmt->execute([$id]);
$mvt = $stmt->fetch();
if (!$mvt) jsonResponse(['error' => 'Mouvement introuvable'], 404);
if ($mvt['cancelled_at'] !== null) jsonResponse(['error' => 'Ce mouvement est déjà annulé.'], 409);
if (!isAdmin() && $mvt['technician_id'] !== currentUserId()) {
    jsonResponse(['error' => 'Accès refusé'], 403);
}

// Compliance: if the date is sealed, use api/cash_reverse.php (mode=cancel) instead.
if (complianceIsDateSealed($mvt['date'])) {
    jsonResponse([
        'error'  => 'La journée du ' . $mvt['date'] . ' est clôturée. Utilisez « Contrepasser » pour annuler ce mouvement.',
        'sealed' => true,
        'date'   => $mvt['date'],
    ], 409);
}

$actorId = currentUserId();
$db->prepare("UPDATE cash_movements SET cancelled_at = datetime('now','localtime'), cancelled_by = ?, cancelled_reason = ? WHERE id = ?")
   ->execute([$actorId, $reason, $id]);

$old = [
    'receipt_no' => $mvt['receipt_no'],
    'technician_id' => $mvt['technician_id'],
    'type' => $mvt['type'],
    'montant' => $mvt['montant'],
    'notes' => $mvt['notes'],
    'date' => $mvt['date'],
];
$new = $old;
$new['cancelled_at'] = date('Y-m-d H:i:s');
$new['cancelled_by'] = $actorId;
$new['cancelled_reason'] = $reason;

addCashHistory($id, (int)$mvt['technician_id'], 'cancel', $old, $new, $reason);
jsonResponse(['success' => true, 'cancelled' => true]);
