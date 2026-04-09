<?php
// Script d'import one-shot - À SUPPRIMER APRÈS UTILISATION
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
if ($token !== 'import_kn_avril2026') {
    http_response_code(403);
    die('Accès refusé');
}

$pdo = getDB();

// Créer les techniciens si absents
$pin = password_hash('1234', PASSWORD_DEFAULT);
$pdo->prepare('INSERT OR IGNORE INTO technicians (name, pin_hash, role, color, active) VALUES (?,?,?,?,1)')
    ->execute(['Mathieu', $pin, 'technician', '#596FF3']);
$pdo->prepare('INSERT OR IGNORE INTO technicians (name, pin_hash, role, color, active) VALUES (?,?,?,?,1)')
    ->execute(['Thibault', $pin, 'technician', '#bd264b']);

// Créer le type Canapé / Tissu si absent
$pdo->exec("INSERT OR IGNORE INTO cleaning_types (label, active, sort_order) VALUES ('Canapé / Tissu', 1, 7)");

// Récupérer les IDs
$techIds = [];
foreach ($pdo->query("SELECT id, name FROM technicians") as $r) $techIds[$r['name']] = $r['id'];
$typeIds = [];
foreach ($pdo->query("SELECT id, label FROM cleaning_types") as $r) $typeIds[$r['label']] = $r['id'];

$M = $techIds['Mathieu'];
$T = $techIds['Thibault'];
$V = $typeIds['Voiture'];
$C = $typeIds['Canapé / Tissu'];

$rows = [
    [$M, '2026-04-01', $C, 'domicile', 0, 'cash',     1, 220.00, 'Demande de facture à son nom',   '2026-04-01 12:00:00'],
    [$T, '2026-04-01', $V, 'domicile', 1, 'qrcode',   0,  75.00, null,                              '2026-04-01 13:00:00'],
    [$T, '2026-04-01', $V, 'domicile', 1, 'qrcode',   1, 170.00, 'Canapé à facturer en voiture',   '2026-04-01 14:00:00'],
    [$T, '2026-04-02', $V, 'domicile', 1, 'qrcode',   0, 105.00, null,                              '2026-04-02 09:00:00'],
    [$M, '2026-04-02', $V, 'atelier',  0, 'virement', 0, 155.00, null,                              '2026-04-02 10:00:00'],
    [$T, '2026-04-02', $C, 'domicile', 0, 'cash',     0, 160.00, null,                              '2026-04-02 11:00:00'],
    [$M, '2026-04-03', $C, 'domicile', 0, 'qrcode',   0, 140.00, null,                              '2026-04-03 09:00:00'],
    [$T, '2026-04-03', $C, 'domicile', 0, 'cash',     0, 170.00, null,                              '2026-04-03 10:00:00'],
    [$M, '2026-04-03', $C, 'domicile', 0, 'virement', 0, 195.00, null,                              '2026-04-03 11:00:00'],
    [$T, '2026-04-03', $V, 'domicile', 1, 'qrcode',   0, 100.00, null,                              '2026-04-03 12:00:00'],
    [$M, '2026-04-04', $V, 'atelier',  0, 'virement', 0,  95.00, null,                              '2026-04-04 09:00:00'],
    [$M, '2026-04-04', $C, 'domicile', 0, 'virement', 0, 170.00, null,                              '2026-04-04 10:00:00'],
    [$T, '2026-04-06', $V, 'domicile', 1, 'facture',  1,  67.50, null,                              '2026-04-06 09:00:00'],
    [$T, '2026-04-06', $V, 'domicile', 1, 'qrcode',   0, 190.00, null,                              '2026-04-06 10:00:00'],
    [$M, '2026-04-07', $V, 'atelier',  0, 'cash',     0, 215.00, 'Remise à neuf',                   '2026-04-07 09:00:00'],
    [$M, '2026-04-08', $V, 'atelier',  0, 'cash',     0, 155.00, null,                              '2026-04-08 09:00:00'],
    [$T, '2026-04-08', $V, 'atelier',  0, 'qrcode',   0,  90.00, null,                              '2026-04-08 10:00:00'],
    [$T, '2026-04-09', $V, 'domicile', 1, 'facture',  1, 270.00, 'GMF à facturer',                  '2026-04-09 09:00:00'],
    [$M, '2026-04-09', $C, 'domicile', 0, 'cash',     0, 140.00, null,                              '2026-04-09 10:00:00'],
    [$T, '2026-04-09', $V, 'domicile', 1, 'facture',  1, 104.50, 'Monsieur Menuisier',              '2026-04-09 11:00:00'],
];

$ins = $pdo->prepare('INSERT INTO services
    (technician_id, date, type_nettoyage_id, lieu, ticket_tva, paiement, facture_a_faire, facture_envoyee, montant, notes, created_at)
    VALUES (?,?,?,?,?,?,?,0,?,?,?)');

$count = 0;
$errors = [];
foreach ($rows as $r) {
    try {
        $ins->execute($r);
        $count++;
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

$total = array_sum(array_column($rows, 7));
echo "<h2>Import terminé</h2>";
echo "<p><strong>{$count} prestations insérées</strong> sur " . count($rows) . " — Total : " . number_format($total, 2, ',', '.') . " €</p>";
echo "<p>Mathieu ID={$M} | Thibault ID={$T}</p>";
if ($errors) echo "<pre style='color:red'>" . implode("\n", $errors) . "</pre>";
echo "<p style='color:red'><strong>Supprime ce fichier (import_avril2026.php) du serveur !</strong></p>";
