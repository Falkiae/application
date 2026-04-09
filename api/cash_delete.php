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

$db->prepare("DELETE FROM cash_movements WHERE id=?")->execute([$id]);
jsonResponse(['success' => true]);
