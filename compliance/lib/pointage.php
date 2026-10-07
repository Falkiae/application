<?php
/**
 * Pointage helpers: daily aggregation with the 30-minute break rule,
 * and lazy auto-close of stale open sessions.
 *
 * Business rule: for each day, we deduct at least 30 minutes of break
 * (the greater of 30 min and the sum of actual gaps between the day's sessions).
 * Rationale: even a technician who does not clock a break has an implicit
 * 30 min break subtracted; a longer break is deducted for its full length.
 */

const POINTAGE_MIN_PAUSE_SEC = 30 * 60;

/**
 * Format a duration in seconds as "H:MM".
 */
function pointageFmtHM(int $sec): string {
    $sec = max(0, $sec);
    return sprintf('%d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
}

/**
 * Given the sessions of a SINGLE day (any order), compute:
 *   brut         = sum of session durations (closed sessions only)
 *   pause_reelle = sum of gaps between consecutive closed sessions
 *   deduction    = max(30 min, pause_reelle) — but 0 if brut = 0
 *   net          = max(0, brut - deduction)
 *
 * $sessions: [ ['debut' => 'Y-m-d H:i:s', 'fin' => 'Y-m-d H:i:s'|null], ... ]
 * Sessions without a fin are excluded (still-open sessions do not count).
 */
function pointageNetHoursForDay(array $sessions): array {
    $closed = [];
    foreach ($sessions as $s) {
        if (!empty($s['fin'])) {
            $closed[] = [
                'debut_ts' => strtotime($s['debut']),
                'fin_ts'   => strtotime($s['fin']),
            ];
        }
    }
    usort($closed, fn($a, $b) => $a['debut_ts'] <=> $b['debut_ts']);

    $brut = 0;
    $pause = 0;
    $prevEnd = null;
    foreach ($closed as $s) {
        $brut += max(0, $s['fin_ts'] - $s['debut_ts']);
        if ($prevEnd !== null && $s['debut_ts'] > $prevEnd) {
            $pause += $s['debut_ts'] - $prevEnd;
        }
        $prevEnd = max($prevEnd ?? 0, $s['fin_ts']);
    }

    if ($brut === 0) {
        return ['brut' => 0, 'pause_reelle' => 0, 'deduction' => 0, 'net' => 0, 'sessions' => count($closed)];
    }
    $deduction = max(POINTAGE_MIN_PAUSE_SEC, $pause);
    $net = max(0, $brut - $deduction);
    return [
        'brut'         => $brut,
        'pause_reelle' => $pause,
        'deduction'    => $deduction,
        'net'          => $net,
        'sessions'     => count($closed),
    ];
}

/**
 * Aggregate a flat list of sessions (possibly spanning many days) into
 * per-day summaries and a total_net.
 *
 * $sessions: same shape as above. Each session's day = date(debut).
 * Returns:
 *   [
 *     'total_net' => int (seconds),
 *     'days'      => [ 'YYYY-MM-DD' => (result of pointageNetHoursForDay for that day), ... ]
 *                    sorted by day ASC
 *   ]
 */
function pointageAggregateSessions(array $sessions): array {
    $byDay = [];
    foreach ($sessions as $s) {
        $day = substr($s['debut'], 0, 10);   // 'YYYY-MM-DD'
        $byDay[$day][] = $s;
    }
    ksort($byDay);
    $days = [];
    $total = 0;
    foreach ($byDay as $day => $ss) {
        $r = pointageNetHoursForDay($ss);
        $days[$day] = $r;
        $total += $r['net'];
    }
    return ['total_net' => $total, 'days' => $days];
}

/**
 * Close any open pointage session (fin IS NULL) whose date is BEFORE today.
 * Sets fin = 'YYYY-MM-DD 23:59:59' (23:59:59 of the session's own date).
 * Also prefixes the notes with "[Fermeture auto le YYYY-MM-DD] " so an admin
 * opening the correction modal can see it needs review.
 * Every close is logged in pointage_history with action='auto_close', admin_id=NULL.
 *
 * Idempotent: only touches rows where fin IS NULL AND date(debut) < today.
 * Returns the number of sessions closed.
 *
 * @param int|null $techId  If provided, restrict to this technician (used for tech-owned page loads).
 */
function pointageAutoCloseStale(?int $techId = null): int {
    $db = getDB();
    $today = date('Y-m-d');
    $params = [$today];
    $sql = "SELECT id, technician_id, date, debut, fin, notes FROM pointages
            WHERE fin IS NULL AND date(debut) < ?";
    if ($techId !== null) {
        $sql .= " AND technician_id = ?";
        $params[] = $techId;
    }
    $stale = $db->prepare($sql);
    $stale->execute($params);
    $rows = $stale->fetchAll();
    if (!$rows) return 0;

    $upd = $db->prepare("UPDATE pointages SET fin = ?, notes = ? WHERE id = ?");
    foreach ($rows as $row) {
        $sessionDay = date('Y-m-d', strtotime($row['debut']));
        $newFin = $sessionDay . ' 23:59:59';
        $tag = "[Fermeture auto le $sessionDay]";
        $newNotes = trim($row['notes'] ?? '') === ''
            ? $tag
            : $tag . ' ' . trim($row['notes']);
        $upd->execute([$newFin, $newNotes, $row['id']]);

        addPointageHistory(
            (int)$row['id'],
            (int)$row['technician_id'],
            null,                              // system, not an admin
            'auto_close',
            ['fin' => null,      'notes' => $row['notes']],
            ['fin' => $newFin,   'notes' => $newNotes]
        );
    }
    return count($rows);
}
