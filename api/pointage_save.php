<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db     = getDB();
$action = trim($_POST['action'] ?? '');
$techId = currentUserId();

if (!in_array($action, ['start', 'stop'])) jsonResponse(['error' => 'Action invalide'], 400);

if ($action === 'start') {
    $open = $db->prepare("SELECT id FROM pointages WHERE technician_id=? AND fin IS NULL");
    $open->execute([$techId]);
    if ($open->fetch()) jsonResponse(['error' => 'Une session est déjà en cours'], 409);

    $db->prepare("INSERT INTO pointages (technician_id, date, debut) VALUES (?, date('now','localtime'), datetime('now','localtime'))")
       ->execute([$techId]);
    jsonResponse(['success' => true, 'action' => 'started']);
}

if ($action === 'stop') {
    $open = $db->prepare("SELECT id FROM pointages WHERE technician_id=? AND fin IS NULL ORDER BY debut DESC LIMIT 1");
    $open->execute([$techId]);
    $session = $open->fetch();
    if (!$session) jsonResponse(['error' => 'Aucune session ouverte'], 404);

    $db->prepare("UPDATE pointages SET fin=datetime('now','localtime') WHERE id=?")->execute([$session['id']]);
    jsonResponse(['success' => true, 'action' => 'stopped']);
}
