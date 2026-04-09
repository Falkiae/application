<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
if (!$id) jsonResponse(['error' => 'ID manquant'], 400);

$stmt = $db->prepare("SELECT technician_id FROM cash_movements WHERE id=?");
$stmt->execute([$id]);
$mvt = $stmt->fetch();
if (!$mvt) jsonResponse(['error' => 'Mouvement introuvable'], 404);

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

$db->prepare("UPDATE cash_movements SET type=?, montant=?, notes=?, date=? WHERE id=?")
   ->execute([$type, $stored, $notes ?: null, $date, $id]);

jsonResponse(['success' => true]);
