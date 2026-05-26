<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db     = getDB();
$id     = (int)($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? 'archive');

if (!$id) jsonResponse(['error' => 'ID manquant'], 400);
if (!in_array($action, ['archive', 'restore', 'delete'])) jsonResponse(['error' => 'Action invalide'], 400);

if ($action === 'archive') {
    $db->prepare("UPDATE abonnements SET active=0 WHERE id=?")->execute([$id]);
    jsonResponse(['success' => true]);
}

if ($action === 'restore') {
    $db->prepare("UPDATE abonnements SET active=1 WHERE id=?")->execute([$id]);
    jsonResponse(['success' => true]);
}

if ($action === 'delete') {
    // Permanently delete passages then the abonnement
    $db->prepare("DELETE FROM abonnement_passages WHERE abonnement_id=?")->execute([$id]);
    $db->prepare("DELETE FROM abonnements WHERE id=?")->execute([$id]);
    jsonResponse(['success' => true]);
}
