<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/compliance.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '') ?: null;
if (!$id) jsonResponse(['error' => 'ID manquant'], 400);

$stmt = $db->prepare("SELECT * FROM cash_movements WHERE id=?");
$stmt->execute([$id]);
$mvt = $stmt->fetch();
if (!$mvt) jsonResponse(['error' => 'Mouvement introuvable'], 404);
if ($mvt['cancelled_at'] !== null) jsonResponse(['error' => 'Ce mouvement est annulé, il ne peut plus être modifié.'], 409);

if (!isAdmin() && $mvt['technician_id'] !== currentUserId()) {
    jsonResponse(['error' => 'Accès refusé'], 403);
}

$type    = trim($_POST['type'] ?? '');
$montant = (float)($_POST['montant'] ?? 0);
$date    = trim($_POST['date'] ?? '');
$notes   = trim($_POST['notes'] ?? '');

if (!in_array($type, ['initial', 'depot_banque', 'achat_liquide', 'note'])) {
    jsonResponse(['error' => 'Type invalide'], 400);
}
if ($montant <= 0) jsonResponse(['error' => 'Montant invalide'], 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(['error' => 'Date invalide'], 400);

$stored = in_array($type, ['depot_banque', 'achat_liquide']) ? -abs($montant) : abs($montant);

$old = [
    'type' => $mvt['type'],
    'montant' => (float)$mvt['montant'],
    'notes' => $mvt['notes'],
    'date' => $mvt['date'],
];
$new = [
    'type' => $type,
    'montant' => $stored,
    'notes' => $notes,
    'date' => $date,
];

// receipt_no stays immutable — never in SET
$db->prepare("UPDATE cash_movements SET type=?, montant=?, notes=?, date=? WHERE id=?")
   ->execute([$type, $stored, $notes ?: null, $date, $id]);

addCashHistory($id, (int)$mvt['technician_id'], 'update', $old, $new, $reason);

jsonResponse(['success' => true]);

