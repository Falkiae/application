<?php
require_once __DIR__ . '/../auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$name = trim($_POST['name'] ?? '');
$pin = trim($_POST['pin'] ?? '');

if (!$name || !$pin) {
    jsonResponse(['error' => 'Nom et PIN requis'], 400);
}

if (login($name, $pin)) {
    jsonResponse(['success' => true, 'redirect' => 'index.php?page=dashboard']);
} else {
    jsonResponse(['error' => 'Nom ou PIN incorrect'], 401);
}
