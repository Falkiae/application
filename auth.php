<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function login(string $name, string $pin): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM technicians WHERE name = ? AND active = 1");
    $stmt->execute([$name]);
    $tech = $stmt->fetch();
    if ($tech && password_verify($pin, $tech['pin_hash'])) {
        $_SESSION['user_id'] = $tech['id'];
        $_SESSION['user_name'] = $tech['name'];
        $_SESSION['user_role'] = $tech['role'];
        $_SESSION['user_color'] = $tech['color'];
        session_regenerate_id(true);
        return true;
    }
    return false;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function isAdmin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentUserName(): string {
    return $_SESSION['user_name'] ?? '';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: index.php?page=dashboard');
        exit;
    }
}

function generateCsrfToken(): string {
    if (empty($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_SESSION_KEY];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION[CSRF_SESSION_KEY]) && hash_equals($_SESSION[CSRF_SESSION_KEY], $token);
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function addServiceHistory(int $serviceId, int $techId, string $action, ?array $oldValues = null, ?array $newValues = null): void {
    $db = getDB();
    $changedFields = null;
    if ($oldValues && $newValues) {
        $changed = [];
        foreach ($newValues as $k => $v) {
            if (isset($oldValues[$k]) && $oldValues[$k] != $v) {
                $changed[] = $k;
            }
        }
        $changedFields = json_encode($changed);
    }
    $db->prepare("INSERT INTO service_history (service_id, technician_id, action, changed_fields, old_values, new_values) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$serviceId, $techId, $action, $changedFields, $oldValues ? json_encode($oldValues) : null, $newValues ? json_encode($newValues) : null]);
}

function addNotification(int $fromTechId, string $action, ?int $serviceId, string $message): void {
    $db = getDB();
    $db->prepare("INSERT INTO notifications (from_technician_id, action, service_id, message, read_by) VALUES (?, ?, ?, ?, '[]')")
        ->execute([$fromTechId, $action, $serviceId, $message]);
}
