<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/compliance.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db = getDB();
$currentId = currentUserId();

$targetId = (int)($_POST['technician_id'] ?? $currentId);
$type = trim($_POST['type'] ?? '');
$montant = (float)($_POST['montant'] ?? 0);
$date = trim($_POST['date'] ?? date('Y-m-d'));
$notes = trim($_POST['notes'] ?? '');

// Non-admin can only record their own movements
if (!isAdmin() && $targetId !== $currentId) {
    jsonResponse(['error' => 'Accès refusé'], 403);
}

if (!in_array($type, ['initial', 'depot_banque', 'achat_liquide', 'note'])) {
    jsonResponse(['error' => 'Type invalide'], 400);
}
if ($montant <= 0) jsonResponse(['error' => 'Montant invalide'], 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(['error' => 'Date invalide'], 400);

// Sorties stockées en négatif
$storedMontant = in_array($type, ['depot_banque', 'achat_liquide']) ? -abs($montant) : abs($montant);

$year = (int)substr($date, 0, 4);
$db->beginTransaction();
try {
    $receiptNo = complianceAllocReceiptNo('cash_movements', $year);
    $db->prepare("INSERT INTO cash_movements (technician_id, type, montant, notes, date, receipt_no) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$targetId, $type, $storedMontant, $notes ?: null, $date, $receiptNo]);
    $newId = (int)$db->lastInsertId();
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    jsonResponse(['error' => 'Enregistrement impossible : ' . $e->getMessage()], 500);
}

addCashHistory($newId, $targetId, 'create', null, [
    'technician_id' => $targetId,
    'type' => $type,
    'montant' => $storedMontant,
    'notes' => $notes,
    'date' => $date,
    'receipt_no' => $receiptNo,
]);

jsonResponse(['success' => true, 'id' => $newId, 'receipt_no' => $receiptNo]);

