<?php
// SCRIPT D'IMPORT UNIQUE - Supprimer après usage
// Prestations Janvier, Février, Mars 2026
require_once __DIR__ . '/../auth.php';

// Sécurité minimale : token requis
if (($_GET['token'] ?? '') !== 'keepnew_import_2026') {
    http_response_code(403);
    die('Token requis: ?token=keepnew_import_2026');
}

$db = getDB();

// Résolution dynamique des types de nettoyage par nom
function tid($db, ...$patterns) {
    foreach ($patterns as $p) {
        $s = $db->prepare("SELECT id FROM cleaning_types WHERE label LIKE ? AND active=1 LIMIT 1");
        $s->execute([$p]);
        $id = $s->fetchColumn();
        if ($id) return (int)$id;
    }
    return null;
}

$V = tid($db, 'Voiture%');
$C = tid($db, 'Canapé%', 'Canape%');
$M = tid($db, 'Matelas%');
$P = tid($db, 'Poliss%');

// Résolution des techniciens
$s = $db->prepare("SELECT id FROM technicians WHERE name LIKE 'Thibault%' AND active=1 LIMIT 1");
$s->execute(); $THIBAULT = (int)$s->fetchColumn();

$s = $db->prepare("SELECT id FROM technicians WHERE name LIKE 'Mathieu%' AND active=1 LIMIT 1");
$s->execute(); $MATHIEU = (int)$s->fetchColumn();

$ok = true;
$checks = compact('V','C','M','P','THIBAULT','MATHIEU');
foreach ($checks as $k => $v) {
    if (!$v) { echo "ERREUR: '$k' introuvable en base.\n"; $ok = false; }
}
if (!$ok) die();

echo "Types: V=$V C=$C M=$M P=$P | Techniciens: Thibault=$THIBAULT Mathieu=$MATHIEU\n";

// Samedis 2026 → Mathieu, reste → Thibault
$saturdays = ['2026-01-03','2026-01-10','2026-01-17','2026-01-24','2026-01-31',
              '2026-02-07','2026-02-14','2026-02-21','2026-02-28',
              '2026-03-07','2026-03-14','2026-03-21','2026-03-28'];

// [$date, $type, $lieu, $paiement, $facture_a_faire, $montant]
// lieu: 'd'=domicile  'a'=atelier
// paiement: 'virement' 'cash' 'qrcode' 'facture'
$services = [
    // ── JANVIER ──────────────────────────────────────────────────
    ['2026-01-02', $C, 'd', 'virement', 0, 200],
    ['2026-01-02', $C, 'd', 'virement', 0, 95],
    ['2026-01-02', $V, 'a', 'facture',  1, 180],
    ['2026-01-03', $C, 'd', 'facture',  1, 95],
    ['2026-01-03', $C, 'd', 'virement', 0, 95],
    ['2026-01-08', $C, 'd', 'cash',     0, 120],
    ['2026-01-08', $C, 'd', 'cash',     0, 75],
    ['2026-01-09', $V, 'a', 'cash',     0, 165],
    ['2026-01-10', $C, 'd', 'virement', 0, 95],
    ['2026-01-10', $C, 'd', 'virement', 0, 120],
    ['2026-01-12', $C, 'd', 'qrcode',   0, 170],
    ['2026-01-13', $C, 'd', 'facture',  1, 120],
    ['2026-01-13', $C, 'd', 'qrcode',   0, 95],
    ['2026-01-13', $V, 'a', 'qrcode',   1, 230],
    ['2026-01-14', $C, 'd', 'qrcode',   0, 140],
    ['2026-01-14', $V, 'a', 'facture',  1, 121],
    ['2026-01-15', $V, 'a', 'virement', 0, 215],
    ['2026-01-15', $V, 'a', 'facture',  1, 270],
    ['2026-01-15', $V, 'a', 'facture',  1, 181.5],
    ['2026-01-16', $C, 'd', 'qrcode',   0, 140],
    ['2026-01-16', $C, 'd', 'facture',  1, 170],
    ['2026-01-17', $C, 'd', 'qrcode',   0, 220],
    ['2026-01-17', $V, 'a', 'cash',     0, 95],
    ['2026-01-17', $V, 'a', 'qrcode',   0, 90],
    ['2026-01-19', $C, 'd', 'qrcode',   0, 140],
    ['2026-01-19', $C, 'd', 'qrcode',   0, 150],
    ['2026-01-19', $V, 'a', 'facture',  1, 145],
    ['2026-01-20', $C, 'd', 'cash',     1, 80],
    ['2026-01-20', $C, 'd', 'qrcode',   0, 99],
    ['2026-01-20', $V, 'a', 'cash',     0, 165],
    ['2026-01-21', $C, 'd', 'qrcode',   0, 140],
    ['2026-01-21', $V, 'a', 'qrcode',   1, 220],
    ['2026-01-21', $V, 'a', 'facture',  1, 242],
    ['2026-01-24', $C, 'd', 'cash',     0, 152],
    ['2026-01-24', $V, 'a', 'facture',  1, 140],
    ['2026-01-24', $C, 'd', 'qrcode',   0, 140],
    ['2026-01-26', $C, 'd', 'facture',  1, 145],
    ['2026-01-26', $V, 'a', 'facture',  1, 95],
    ['2026-01-26', $C, 'd', 'facture',  1, 140],
    ['2026-01-26', $V, 'a', 'facture',  1, 140],
    ['2026-01-27', $C, 'd', 'qrcode',   0, 121],
    ['2026-01-28', $C, 'd', 'qrcode',   1, 140],
    ['2026-01-28', $V, 'a', 'facture',  1, 215],
    ['2026-01-28', $V, 'a', 'facture',  1, 242],
    ['2026-01-30', $V, 'a', 'facture',  1, 121],
    ['2026-01-31', $M, 'd', 'facture',  1, 110],
    ['2026-01-31', $V, 'a', 'facture',  1, 165],
    ['2026-01-31', $M, 'd', 'facture',  1, 5808],

    // ── FÉVRIER ──────────────────────────────────────────────────
    ['2026-02-01', $P, 'a', 'facture',  1, 850],
    ['2026-02-02', $C, 'd', 'qrcode',   0, 99],
    ['2026-02-02', $C, 'd', 'qrcode',   0, 140],
    ['2026-02-03', $C, 'd', 'cash',     0, 140],
    // 4-févr Abonnement ignoré
    ['2026-02-04', $C, 'd', 'qrcode',   0, 99],
    ['2026-02-04', $V, 'a', 'qrcode',   0, 230],
    ['2026-02-04', $V, 'a', 'facture',  1, 242],
    ['2026-02-05', $V, 'a', 'qrcode',   0, 105],
    ['2026-02-05', $V, 'a', 'facture',  1, 110],
    ['2026-02-05', $V, 'a', 'qrcode',   1, 110],
    ['2026-02-05', $C, 'd', 'facture',  1, 140],
    ['2026-02-05', $C, 'd', 'virement', 0, 95],
    ['2026-02-06', $V, 'a', 'virement', 0, 80],
    ['2026-02-06', $C, 'd', 'cash',     0, 100],
    ['2026-02-07', $V, 'a', 'facture',  1, 67.5],
    ['2026-02-07', $V, 'a', 'virement', 0, 225],
    ['2026-02-09', $V, 'a', 'qrcode',   0, 110],
    ['2026-02-09', $V, 'a', 'qrcode',   0, 115],
    ['2026-02-09', $V, 'a', 'facture',  1, 484],
    ['2026-02-10', $C, 'd', 'cash',     0, 180],
    ['2026-02-10', $C, 'd', 'qrcode',   0, 140],
    ['2026-02-10', $C, 'd', 'facture',  1, 179],
    ['2026-02-11', $V, 'a', 'facture',  1, 242],
    ['2026-02-12', $V, 'a', 'facture',  1, 104.5],
    ['2026-02-12', $C, 'd', 'cash',     0, 100],
    ['2026-02-12', $V, 'a', 'facture',  1, 110],
    ['2026-02-13', $V, 'a', 'facture',  1, 357],
    ['2026-02-16', $V, 'a', 'facture',  1, 324],
    ['2026-02-17', $V, 'a', 'facture',  1, 114],
    ['2026-02-17', $V, 'a', 'facture',  1, 180],
    ['2026-02-19', $C, 'd', 'qrcode',   0, 210],
    ['2026-02-19', $V, 'a', 'virement', 0, 230],
    ['2026-02-19', $V, 'a', 'virement', 0, 65],
    ['2026-02-23', $C, 'd', 'qrcode',   0, 167],
    ['2026-02-23', $V, 'a', 'facture',  1, 120],
    ['2026-02-24', $V, 'a', 'facture',  1, 255],
    ['2026-02-24', $V, 'a', 'cash',     0, 110],
    ['2026-02-25', $C, 'd', 'cash',     0, 230],
    ['2026-02-25', $V, 'a', 'cash',     1, 275],
    ['2026-02-25', $V, 'a', 'facture',  1, 242],
    ['2026-02-26', $V, 'a', 'qrcode',   1, 95],
    ['2026-02-26', $V, 'a', 'virement', 0, 199],
    ['2026-02-26', $M, 'd', 'cash',     0, 90],
    ['2026-02-26', $C, 'd', 'virement', 0, 165],
    ['2026-02-26', $V, 'a', 'virement', 0, 65],
    ['2026-02-26', $V, 'a', 'virement', 1, 95],
    ['2026-02-27', $V, 'a', 'facture',  1, 95],
    ['2026-02-27', $V, 'a', 'virement', 0, 80],
    ['2026-02-27', $V, 'a', 'facture',  1, 200],
    ['2026-02-27', $V, 'a', 'facture',  1, 200],
    ['2026-02-28', $C, 'd', 'virement', 0, 140],
    ['2026-02-28', $C, 'd', 'virement', 0, 140],
    // 28-févr Abonnement ignoré

    // ── MARS ─────────────────────────────────────────────────────
    ['2026-03-02', $C, 'd', 'cash',     0, 160],
    ['2026-03-02', $V, 'a', 'qrcode',   0, 110],
    ['2026-03-02', $C, 'd', 'qrcode',   0, 180],
    ['2026-03-03', $V, 'a', 'cash',     0, 110],
    ['2026-03-03', $C, 'd', 'qrcode',   0, 170],
    ['2026-03-04', $V, 'a', 'facture',  1, 280],
    ['2026-03-04', $V, 'a', 'qrcode',   1, 99],
    ['2026-03-05', $C, 'd', 'cash',     0, 140],
    ['2026-03-05', $V, 'a', 'facture',  1, 115],
    ['2026-03-05', $P, 'a', 'facture',  1, 423.5],
    ['2026-03-05', $V, 'a', 'cash',     0, 170],
    ['2026-03-05', $C, 'd', 'cash',     0, 140],
    ['2026-03-06', $V, 'a', 'facture',  1, 95],
    ['2026-03-06', $V, 'a', 'facture',  1, 110],
    ['2026-03-06', $V, 'a', 'qrcode',   0, 215],
    ['2026-03-06', $V, 'a', 'virement', 0, 155],
    ['2026-03-06', $V, 'a', 'virement', 0, 65],
    ['2026-03-07', $C, 'd', 'virement', 0, 120],
    ['2026-03-07', $V, 'a', 'virement', 0, 95],
    ['2026-03-09', $C, 'd', 'qrcode',   0, 180],
    ['2026-03-09', $V, 'a', 'virement', 0, 95],
    ['2026-03-10', $V, 'a', 'qrcode',   0, 80],
    ['2026-03-10', $V, 'a', 'facture',  1, 103.5],
    ['2026-03-11', $M, 'd', 'qrcode',   0, 120],
    ['2026-03-11', $V, 'a', 'facture',  1, 195],
    ['2026-03-12', $V, 'a', 'facture',  1, 229.5],
    ['2026-03-12', $V, 'a', 'facture',  1, 225],
    ['2026-03-12', $V, 'a', 'cash',     0, 95],
    ['2026-03-13', $V, 'a', 'facture',  1, 270],
    ['2026-03-13', $V, 'a', 'virement', 1, 170],
    ['2026-03-13', $V, 'a', 'virement', 1, 230],
    ['2026-03-14', $V, 'a', 'virement', 0, 215],
    ['2026-03-16', $V, 'a', 'qrcode',   0, 95],
    ['2026-03-16', $V, 'a', 'facture',  1, 110],
    ['2026-03-18', $V, 'a', 'cash',     0, 100],
    ['2026-03-18', $C, 'd', 'qrcode',   0, 145],
    ['2026-03-19', $C, 'd', 'qrcode',   1, 140],
    ['2026-03-19', $V, 'a', 'virement', 0, 95],
    ['2026-03-19', $M, 'd', 'qrcode',   0, 90],
    ['2026-03-19', $V, 'a', 'virement', 0, 215],
    ['2026-03-20', $V, 'a', 'virement', 0, 75],
    ['2026-03-20', $V, 'a', 'virement', 0, 80],
    ['2026-03-20', $C, 'd', 'cash',     0, 170],
    // 21-mars Abonnement ignoré
    ['2026-03-21', $V, 'a', 'facture',  1, 170],
    ['2026-03-21', $C, 'd', 'cash',     0, 140],
    ['2026-03-23', $V, 'a', 'facture',  1, 640.76],
    // 24-mars Abonnement ignoré
    ['2026-03-24', $V, 'a', 'facture',  1, 240],
    ['2026-03-24', $C, 'd', 'qrcode',   1, 140],
    ['2026-03-25', $V, 'a', 'virement', 1, 255],
    ['2026-03-25', $V, 'a', 'facture',  1, 605],
    ['2026-03-26', $V, 'a', 'facture',  1, 180.5],
    ['2026-03-26', $V, 'a', 'virement', 0, 180],
    ['2026-03-26', $C, 'd', 'qrcode',   0, 150],
    ['2026-03-26', $C, 'd', 'virement', 0, 95],
    ['2026-03-26', $C, 'd', 'qrcode',   0, 145],
    ['2026-03-27', $C, 'd', 'cash',     0, 140],
    ['2026-03-27', $C, 'd', 'virement', 0, 99],
    ['2026-03-27', $V, 'a', 'facture',  1, 110],
    ['2026-03-28', $C, 'd', 'virement', 0, 99],
    ['2026-03-28', $C, 'd', 'virement', 0, 155],
    ['2026-03-30', $V, 'a', 'qrcode',   1, 120],
    ['2026-03-30', $V, 'a', 'facture',  1, 75],
    ['2026-03-30', $C, 'd', 'qrcode',   0, 140],
    ['2026-03-31', $C, 'd', 'cash',     0, 95],
];

$stmt = $db->prepare("
    INSERT INTO services
        (technician_id, date, type_nettoyage_id, lieu, ticket_tva, paiement,
         facture_a_faire, facture_envoyee, montant, created_at, updated_at)
    VALUES (?, ?, ?, ?, 0, ?, ?, 0, ?, ?, ?)
");

$count = 0;
$errors = [];
$db->beginTransaction();
try {
    foreach ($services as $i => $s) {
        [$date, $typeId, $lieu_code, $paiement, $facture, $montant] = $s;
        $lieu   = $lieu_code === 'a' ? 'atelier' : 'domicile';
        $techId = in_array($date, $saturdays) ? $MATHIEU : $THIBAULT;
        $ts     = $date . ' 12:00:00';
        $stmt->execute([$techId, $date, $typeId, $lieu, $paiement, $facture, $montant, $ts, $ts]);
        $count++;
    }
    $db->commit();
    echo "\n✅ Import terminé : $count prestations insérées.\n";
    echo "Répartition : Thibault (semaine) + Mathieu (samedis).\n";
    echo "⚠️  Supprime ce fichier après vérification : api/import_jan_mars_2026.php\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ ERREUR à la ligne $count : " . $e->getMessage() . "\n";
}
