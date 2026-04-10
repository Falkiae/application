<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
if (!$id) jsonResponse(['error' => 'ID manquant'], 400);

// Soft delete
$db->prepare("UPDATE abonnements SET active=0 WHERE id=?")->execute([$id]);
jsonResponse(['success' => true]);
