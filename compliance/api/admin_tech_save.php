<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db = getDB();
$id = (int)($_POST['id'] ?? 0);

// Toggle active
if (isset($_POST['active']) && $id) {
    $active = (int)$_POST['active'];
    $db->prepare("UPDATE technicians SET active=? WHERE id=?")->execute([$active, $id]);
    jsonResponse(['success' => true]);
}

// Save technician
$name = trim($_POST['name'] ?? '');
$pin = trim($_POST['pin'] ?? '');
$role = trim($_POST['role'] ?? 'technician');
$color = trim($_POST['color'] ?? '#596FF3');

if (!$name) jsonResponse(['error' => 'Nom requis'], 400);
if (!in_array($role, ['admin', 'technician'])) jsonResponse(['error' => 'Rôle invalide'], 400);
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#596FF3';

if ($id) {
    // Update
    if ($pin) {
        if (!preg_match('/^\d{4,8}$/', $pin)) jsonResponse(['error' => 'PIN invalide (4-8 chiffres)'], 400);
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $db->prepare("UPDATE technicians SET name=?, pin_hash=?, role=?, color=? WHERE id=?")
           ->execute([$name, $hash, $role, $color, $id]);
    } else {
        $db->prepare("UPDATE technicians SET name=?, role=?, color=? WHERE id=?")
           ->execute([$name, $role, $color, $id]);
    }
} else {
    // Create
    if (!$pin || !preg_match('/^\d{4,8}$/', $pin)) jsonResponse(['error' => 'PIN requis (4-8 chiffres)'], 400);
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    try {
        $db->prepare("INSERT INTO technicians (name, pin_hash, role, color) VALUES (?, ?, ?, ?)")
           ->execute([$name, $hash, $role, $color]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) jsonResponse(['error' => 'Ce nom existe déjà'], 409);
        jsonResponse(['error' => 'Erreur base de données'], 500);
    }
}

jsonResponse(['success' => true]);
