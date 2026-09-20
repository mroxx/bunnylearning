<?php
/**
 * GET /api/health.php — server self-check.
 * Open this URL in a browser to diagnose installation problems.
 * Returns JSON even when things are broken (never loads bootstrap).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = [
    'ok'         => true,
    'php'        => PHP_VERSION,
    'php_ok'     => version_compare(PHP_VERSION, '8.0.0', '>='),
    'pdo_mysql'  => extension_loaded('pdo_mysql'),
    'openssl'    => extension_loaded('openssl'),
    'config'     => file_exists(__DIR__ . '/../config.php'),
    'install_lock' => file_exists(__DIR__ . '/../install.lock'),
];

if (!$out['config']) {
    $out['ok'] = false;
    $out['problem'] = 'config.php missing — finish install.php (step 5).';
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

$cfg = require __DIR__ . '/../config.php';
$out['app_url'] = $cfg['app_url'] ?? null;
$out['smtp_configured'] = !empty($cfg['smtp_host']) && !empty($cfg['smtp_from']);

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s',
            $cfg['db_host'] ?? '', $cfg['db_name'] ?? '', $cfg['db_charset'] ?? 'utf8mb4'),
        $cfg['db_user'] ?? '', $cfg['db_pass'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $out['db'] = 'connected';
    $out['tables'] = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $need = ['users', 'sessions', 'progress', 'tokens', 'login_attempts', 'audit_log'];
    $missing = array_diff($need, $out['tables']);
    if ($missing) {
        $out['ok'] = false;
        $out['problem'] = 'Missing tables: ' . implode(', ', $missing) . ' — re-run install.php step 2.';
    }
    if (in_array('users', $out['tables'] ?? [], true)) {
        $out['users_total'] = (int) $pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
        $out['admins'] = (int) $pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin' AND deleted_at IS NULL")->fetch()['c'];
    }
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['db'] = 'FAILED: ' . $e->getMessage();
    $out['problem'] = 'Database unreachable — check the db_* values in config.php.';
}

echo json_encode($out, JSON_PRETTY_PRINT);
