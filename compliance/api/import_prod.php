<?php
/**
 * Import de la base de données de production vers la DB compliance.
 *
 * Flux :
 *  1. Vérifie que data/keepnew.sqlite existe (DB prod sur le même filesystem).
 *  2. Sauvegarde la DB compliance actuelle sous
 *     data/keepnew_compliance.sqlite.pre-import-YYYYMMDD-HHMMSS
 *     ainsi que les fichiers WAL/SHM associés.
 *  3. Supprime les éventuels WAL/SHM compliance résiduels.
 *  4. copy() de la DB prod sur la DB compliance.
 *
 * Au prochain chargement de page, un nouveau PDO s'ouvre sur la nouvelle DB,
 * initSchema() ajoute silencieusement les colonnes compliance manquantes via
 * ALTER TABLE idempotents, et complianceRetroconformHistoricalData() tourne
 * (le flag compliance_retroconformed_at n'existe pas dans la DB prod).
 *
 * Retourne : { success, backup, prod_stats, compliance_stats_before }
 */
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'Token invalide'], 403);

$prodPath = dirname(__DIR__, 2) . '/data/keepnew.sqlite';
$complPath = DB_PATH;

if (!is_file($prodPath)) {
    jsonResponse([
        'error' => "DB prod introuvable à l'emplacement attendu : $prodPath. Vérifie que ce fichier existe sur ton serveur.",
    ], 404);
}
if (!is_readable($prodPath)) {
    jsonResponse(['error' => "DB prod non lisible : $prodPath (permissions ?)."], 500);
}
$complDir = dirname($complPath);
if (!is_dir($complDir) || !is_writable($complDir)) {
    jsonResponse(['error' => "Dossier compliance non inscriptible : $complDir"], 500);
}

$timestamp = date('Ymd-His');

// Backup current compliance DB and its WAL/SHM companions
$backupNames = [];
$toBackup = [
    $complPath            => $complPath . '.pre-import-' . $timestamp,
    $complPath . '-wal'   => $complPath . '.pre-import-' . $timestamp . '-wal',
    $complPath . '-shm'   => $complPath . '.pre-import-' . $timestamp . '-shm',
];
foreach ($toBackup as $src => $dst) {
    if (is_file($src)) {
        if (!@copy($src, $dst)) {
            jsonResponse(['error' => "Impossible de sauvegarder $src"], 500);
        }
        $backupNames[] = basename($dst);
    }
}

// Delete remaining WAL/SHM on compliance side so SQLite reopens the new file cleanly
foreach ([$complPath . '-wal', $complPath . '-shm'] as $trash) {
    if (is_file($trash)) @unlink($trash);
}

// Overwrite compliance DB with a copy of prod DB
if (!@copy($prodPath, $complPath)) {
    jsonResponse(['error' => "Échec de la copie de la DB prod vers compliance."], 500);
}

// Quick peek: open the newly copied DB read-only to count rows (purely informational).
$prodStats = [];
try {
    $peek = new PDO('sqlite:' . $complPath);
    $peek->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (['technicians', 'cleaning_types', 'services', 'cash_movements',
              'abonnements', 'abonnement_passages', 'clients',
              'pointages', 'notifications'] as $tbl) {
        try {
            $prodStats[$tbl] = (int)$peek->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
        } catch (Throwable $e) {
            $prodStats[$tbl] = null;
        }
    }
    $peek = null;
} catch (Throwable $e) {
    $prodStats = ['error' => $e->getMessage()];
}

jsonResponse([
    'success'      => true,
    'backup'       => $backupNames,
    'prod_stats'   => $prodStats,
    'imported_at'  => $timestamp,
]);
