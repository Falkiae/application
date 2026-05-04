<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

$db = getDB();
$isAdm = isAdmin();
$currentTechId = currentUserId();

$year      = (int)($_GET['year'] ?? date('Y'));
$techFilter = (int)($_GET['tech'] ?? 0);
$typeFilter = (int)($_GET['type'] ?? 0);

if (!$isAdm) $techFilter = $currentTechId;

$where  = "strftime('%Y', s.date) = :year";
$params = [':year' => (string)$year];
if ($techFilter > 0) { $where .= " AND s.technician_id = :tech"; $params[':tech'] = $techFilter; }
if ($typeFilter > 0) { $where .= " AND s.type_nettoyage_id = :type"; $params[':type'] = $typeFilter; }

// KPI
$kpi = $db->prepare("SELECT COUNT(*) as nb, COALESCE(SUM(montant),0) as ca, COALESCE(AVG(montant),0) as avg_ca FROM services s WHERE $where");
$kpi->execute($params);
$kpiRow = $kpi->fetch();

// Best month
$bm = $db->prepare("SELECT strftime('%m', s.date) as m, SUM(montant) as ca FROM services s WHERE $where GROUP BY m ORDER BY ca DESC LIMIT 1");
$bm->execute($params);
$bestMonth = $bm->fetch();

// Monthly totals (12 months, with domicile/atelier split)
$mStmt = $db->prepare("
    SELECT strftime('%m', s.date) as m,
           COALESCE(SUM(s.montant),0) as ca,
           COUNT(*) as nb,
           COALESCE(SUM(CASE WHEN s.lieu='domicile' THEN s.montant ELSE 0 END),0) as ca_dom,
           COALESCE(SUM(CASE WHEN s.lieu='atelier'  THEN s.montant ELSE 0 END),0) as ca_atl
    FROM services s WHERE $where GROUP BY m ORDER BY m
");
$mStmt->execute($params);
$mRaw = [];
foreach ($mStmt->fetchAll() as $r) $mRaw[$r['m']] = $r;

// Monthly per technician for stacked bars (only when showing all techs)
$techMonthly = [];
if ($techFilter === 0) {
    $tmStmt = $db->prepare("
        SELECT strftime('%m', s.date) as m, s.technician_id, t.name, t.color,
               COALESCE(SUM(s.montant),0) as ca
        FROM services s
        JOIN technicians t ON t.id = s.technician_id
        WHERE $where
        GROUP BY m, s.technician_id
        ORDER BY m, t.name
    ");
    $tmStmt->execute($params);
    $techMap = [];
    foreach ($tmStmt->fetchAll() as $r) {
        if (!isset($techMap[$r['technician_id']])) {
            $techMap[$r['technician_id']] = [
                'name'   => $r['name'],
                'color'  => $r['color'],
                'months' => array_fill(0, 12, 0),
            ];
        }
        $techMap[$r['technician_id']]['months'][(int)$r['m'] - 1] = round((float)$r['ca'], 2);
    }
    $techMonthly = array_values($techMap);
}

// Build complete 12-month array
$monthly = [];
for ($i = 1; $i <= 12; $i++) {
    $m = str_pad($i, 2, '0', STR_PAD_LEFT);
    $r = $mRaw[$m] ?? ['ca' => 0, 'nb' => 0, 'ca_dom' => 0, 'ca_atl' => 0];
    $monthly[] = [
        'm'       => $m,
        'ca'      => round((float)$r['ca'], 2),
        'nb'      => (int)$r['nb'],
        'ca_dom'  => round((float)$r['ca_dom'], 2),
        'ca_atl'  => round((float)$r['ca_atl'], 2),
    ];
}

// By service type
$tStmt = $db->prepare("
    SELECT ct.label, COUNT(*) as nb,
           COALESCE(SUM(s.montant),0) as ca,
           COALESCE(AVG(s.montant),0) as avg_ca
    FROM services s
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    WHERE $where
    GROUP BY s.type_nettoyage_id
    ORDER BY ca DESC
");
$tStmt->execute($params);
$byType = [];
foreach ($tStmt->fetchAll() as $r) {
    $byType[] = [
        'label'  => $r['label'],
        'nb'     => (int)$r['nb'],
        'ca'     => round((float)$r['ca'], 2),
        'avg_ca' => round((float)$r['avg_ca'], 2),
    ];
}

// By payment method
$pStmt = $db->prepare("
    SELECT paiement, COUNT(*) as nb, COALESCE(SUM(montant),0) as ca
    FROM services s WHERE $where GROUP BY paiement ORDER BY ca DESC
");
$pStmt->execute($params);
$byPayment = [];
foreach ($pStmt->fetchAll() as $r) {
    $byPayment[] = ['paiement' => $r['paiement'], 'nb' => (int)$r['nb'], 'ca' => round((float)$r['ca'], 2)];
}

// By lieu (domicile / atelier)
$lStmt = $db->prepare("
    SELECT lieu, COUNT(*) as nb, COALESCE(SUM(montant),0) as ca
    FROM services s WHERE $where GROUP BY lieu
");
$lStmt->execute($params);
$byLieu = ['domicile' => ['nb' => 0, 'ca' => 0], 'atelier' => ['nb' => 0, 'ca' => 0]];
foreach ($lStmt->fetchAll() as $r) {
    $byLieu[$r['lieu']] = ['nb' => (int)$r['nb'], 'ca' => round((float)$r['ca'], 2)];
}

// Available years (for year selector)
$years = $db->query("SELECT DISTINCT strftime('%Y', date) as y FROM services ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((string)date('Y'), $years)) array_unshift($years, (string)date('Y'));

header('Content-Type: application/json');
echo json_encode([
    'kpi' => [
        'total_ca'      => round((float)$kpiRow['ca'], 2),
        'nb'            => (int)$kpiRow['nb'],
        'avg_ca'        => round((float)$kpiRow['avg_ca'], 2),
        'best_month'    => $bestMonth ? (int)$bestMonth['m'] : null,
        'best_month_ca' => $bestMonth ? round((float)$bestMonth['ca'], 2) : 0,
    ],
    'monthly'      => $monthly,
    'tech_monthly' => $techMonthly,
    'by_type'      => $byType,
    'by_payment'   => $byPayment,
    'by_lieu'      => $byLieu,
    'years'        => $years,
]);
