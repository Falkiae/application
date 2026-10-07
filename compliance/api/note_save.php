<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'Token invalide'], 403);

$month = $_POST['month'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonResponse(['error' => 'Mois invalide'], 400);

setSetting("note_$month", trim($_POST['note'] ?? ''));
jsonResponse(['success' => true]);
