<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$id = (int)($_POST['id'] ?? 0);
$envoyee = (int)($_POST['envoyee'] ?? 1);

if (!$id) jsonResponse(['error' => 'ID manquant'], 400);

$db = getDB();
$stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) jsonResponse(['error' => 'Prestation introuvable'], 404);
if (!isAdmin() && $service['technician_id'] != currentUserId()) jsonResponse(['error' => 'Accès refusé'], 403);

$db->prepare("UPDATE services SET facture_envoyee=?, updated_at=datetime('now','localtime') WHERE id=?")
   ->execute([$envoyee, $id]);

// History entry
addServiceHistory($id, currentUserId(), 'update',
    ['facture_envoyee' => $service['facture_envoyee']],
    ['facture_envoyee' => $envoyee]
);

jsonResponse(['success' => true, 'envoyee' => $envoyee]);
