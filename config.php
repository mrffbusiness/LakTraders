<?php
declare(strict_types=1);

$config = [
    'db_host' => 'localhost',
    'db_name' => 'rafay_pos',
    'db_user' => 'root',
    'db_pass' => '',
    'app_name' => 'Rafay POS',
    'timezone' => 'Asia/Karachi',
    'session_secure' => null,
];
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) $config = array_merge($config, $override);
}
date_default_timezone_set((string)$config['timezone']);

// Baseline security headers. HTTPS/HSTS should be enabled by the hosting environment.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
$secure = $config['session_secure'] === null
    ? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    : (bool)$config['session_secure'];
session_name('rafay_pos_session');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => $secure,
    'path' => '/',
]);
session_start();

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function input_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function client_ip(): string {
    return clean_string($_SERVER['REMOTE_ADDR'] ?? 'unknown', 64);
}
function clean_string(mixed $value, int $max = 255): string {
    $s = trim((string)($value ?? ''));
    return mb_substr($s, 0, $max);
}
function money(mixed $value): float {
    $n = round((float)$value, 2);
    return is_finite($n) && $n >= 0 ? $n : 0.0;
}
function require_login(): array {
    if (empty($_SESSION['user'])) json_response(['error' => 'Authentication required'], 401);
    return $_SESSION['user'];
}
function require_role(array $roles): array {
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) json_response(['error' => 'Permission denied'], 403);
    return $user;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function require_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        json_response(['error' => 'Invalid security token. Refresh the page and try again.'], 419);
    }
}
function tenant_id(array $user): int {
    if ($user['role'] === 'super_admin') {
        $id = (int)($_SESSION['active_shop_id'] ?? 0);
        if ($id < 1) json_response(['error' => 'Select a shop first'], 400);
        return $id;
    }
    return (int)$user['shop_id'];
}
function audit(?int $shopId, int $userId, string $action, ?string $type = null, ?int $entityId = null, array $details = []): void {
    $st = db()->prepare('INSERT INTO audit_logs(shop_id,user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$shopId, $userId, $action, $type, $entityId, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, client_ip()]);
}
function login_rate_limited(string $email): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE created_at >= (NOW() - INTERVAL 15 MINUTE) AND (email=? OR ip_address=?) AND success=0');
    $st->execute([$email, client_ip()]);
    return (int)$st->fetchColumn() >= 8;
}
function record_login(string $email, bool $success): void {
    db()->prepare('INSERT INTO login_attempts(email,ip_address,success) VALUES(?,?,?)')->execute([$email, client_ip(), $success ? 1 : 0]);
    if ($success) db()->prepare('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)')->execute();
}
function same_origin(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return true;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return hash_equals($scheme . '://' . $host, rtrim($origin, '/'));
}
