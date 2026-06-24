<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db     = getDB();
$action = trim($_POST['action'] ?? '');

if (!in_array($action, ['create', 'update', 'delete'])) jsonResponse(['error' => 'Action invalide'], 400);

if ($action === 'create') {
    $techId  = (int)($_POST['technician_id'] ?? 0);
    $date    = trim($_POST['date']    ?? '');
    $h_debut = trim($_POST['h_debut'] ?? '');
    $h_fin   = trim($_POST['h_fin']   ?? '');
    $notes   = trim($_POST['notes']   ?? '') ?: null;

    if (!$techId) jsonResponse(['error' => 'Technicien requis'], 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))  jsonResponse(['error' => 'Date invalide'], 400);
    if (!preg_match('/^\d{2}:\d{2}$/', $h_debut))       jsonResponse(['error' => 'Heure de début invalide'], 400);

    $debut = $date . ' ' . $h_debut . ':00';
    $fin   = null;
    if ($h_fin !== '') {
        if (!preg_match('/^\d{2}:\d{2}$/', $h_fin)) jsonResponse(['error' => 'Heure de fin invalide'], 400);
        $fin = $date . ' ' . $h_fin . ':00';
        if ($fin <= $debut) jsonResponse(['error' => 'La fin doit être après le début'], 400);
    }

    $db->prepare("INSERT INTO pointages (technician_id, date, debut, fin, notes) VALUES (?, ?, ?, ?, ?)")
       ->execute([$techId, $date, $debut, $fin, $notes]);
    jsonResponse(['success' => true]);
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) jsonResponse(['error' => 'ID manquant'], 400);

$check = $db->prepare("SELECT id FROM pointages WHERE id=?");
$check->execute([$id]);
if (!$check->fetch()) jsonResponse(['error' => 'Session introuvable'], 404);

if ($action === 'delete') {
    $db->prepare("DELETE FROM pointages WHERE id=?")->execute([$id]);
    jsonResponse(['success' => true]);
}

if ($action === 'update') {
    $date    = trim($_POST['date']    ?? '');
    $h_debut = trim($_POST['h_debut'] ?? '');
    $h_fin   = trim($_POST['h_fin']   ?? '');
    $notes   = trim($_POST['notes']   ?? '') ?: null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))  jsonResponse(['error' => 'Date invalide'], 400);
    if (!preg_match('/^\d{2}:\d{2}$/', $h_debut))       jsonResponse(['error' => 'Heure de début invalide'], 400);

    $debut = $date . ' ' . $h_debut . ':00';
    $fin   = null;

    if ($h_fin !== '') {
        if (!preg_match('/^\d{2}:\d{2}$/', $h_fin)) jsonResponse(['error' => 'Heure de fin invalide'], 400);
        $fin = $date . ' ' . $h_fin . ':00';
        if ($fin <= $debut) jsonResponse(['error' => 'La fin doit être après le début'], 400);
    }

    $db->prepare("UPDATE pointages SET date=?, debut=?, fin=?, notes=? WHERE id=?")
       ->execute([$date, $debut, $fin, $notes, $id]);

    jsonResponse(['success' => true]);
}
