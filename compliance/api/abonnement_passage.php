<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db               = getDB();
$abonnementId     = (int)($_POST['abonnement_id'] ?? 0);
$date             = trim($_POST['date'] ?? date('Y-m-d'));
$nettoyagesDebites = max(1, (int)($_POST['nettoyages_debites'] ?? 1));
$photoAvant       = trim($_POST['photo_avant_path'] ?? '');
$photoApres       = trim($_POST['photo_apres_path'] ?? '');
$notes            = trim($_POST['notes'] ?? '');

if (!$abonnementId) jsonResponse(['error' => 'Abonnement invalide'], 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(['error' => 'Date invalide'], 400);

// Load abonnement + usage
$stmt = $db->prepare("
    SELECT a.*, COALESCE(SUM(p.nettoyages_debites),0) as utilises
    FROM abonnements a
    LEFT JOIN abonnement_passages p ON p.abonnement_id = a.id
    WHERE a.id = ? AND a.active = 1
    GROUP BY a.id
");
$stmt->execute([$abonnementId]);
$abo = $stmt->fetch();

if (!$abo) jsonResponse(['error' => 'Abonnement introuvable ou inactif'], 404);

$restants = $abo['nettoyages_total'] - $abo['utilises'];
if ($nettoyagesDebites > $restants) {
    jsonResponse(['error' => "Seulement {$restants} nettoyage(s) restant(s)"], 400);
}

// Sanitize photo paths
function sanitizeAboPhoto(?string $p): ?string {
    if (!$p || $p === '__deleted__') return null;
    $p = ltrim($p, '/');
    if (str_contains($p, '..')) return null;
    if (str_starts_with($p, 'uploads/') || preg_match('/^\d{4}\/\d{2}\/[a-f0-9]+\.jpg$/', $p)) return $p;
    return null;
}

$db->prepare("INSERT INTO abonnement_passages
    (abonnement_id, technician_id, date, nettoyages_debites, photo_avant, photo_apres, notes)
    VALUES (?,?,?,?,?,?,?)")
  ->execute([
    $abonnementId,
    currentUserId(),
    $date,
    $nettoyagesDebites,
    sanitizeAboPhoto($photoAvant),
    sanitizeAboPhoto($photoApres),
    $notes ?: null,
  ]);

$newRestants = $restants - $nettoyagesDebites;
jsonResponse(['success' => true, 'restants' => $newRestants]);
