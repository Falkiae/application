<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

$db = getDB();
$userId = currentUserId();

$stmt = $db->query("
    SELECT n.*, t.name as from_name, t.color as from_color
    FROM notifications n
    JOIN technicians t ON t.id = n.from_technician_id
    ORDER BY n.created_at DESC
    LIMIT 50
");
$notifs = $stmt->fetchAll();

$unread = 0;
$items = [];
foreach ($notifs as $n) {
    $readBy = json_decode($n['read_by'] ?? '[]', true);
    $isRead = in_array($userId, $readBy);
    if (!$isRead) $unread++;
    $items[] = [
        'id' => $n['id'],
        'message' => $n['message'],
        'action' => $n['action'],
        'service_id' => $n['service_id'],
        'from_name' => $n['from_name'],
        'from_color' => $n['from_color'],
        'is_read' => $isRead,
        'created_at' => $n['created_at'],
        'time_ago' => timeAgo($n['created_at']),
    ];
}

jsonResponse(['unread' => $unread, 'items' => $items]);

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return "À l'instant";
    if ($diff < 3600) return round($diff/60) . ' min';
    if ($diff < 86400) return round($diff/3600) . ' h';
    if ($diff < 604800) return round($diff/86400) . ' j';
    return date('d/m/Y', strtotime($datetime));
}
