<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'Token invalide'], 403);

$allowed = ['company_name', 'company_form', 'company_bce', 'company_vat', 'company_address', 'company_email', 'company_phone'];

foreach ($allowed as $key) {
    $val = trim($_POST[$key] ?? '');
    if (strlen($val) > 500) jsonResponse(['error' => "Valeur trop longue pour $key"], 400);
    setSetting($key, $val);
}

jsonResponse(['success' => true]);
