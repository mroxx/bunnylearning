<?php
/**
 * Bunny Learning — shared bootstrap for API endpoints.
 * Loads config, opens PDO, starts the session, provides helpers.
 */

declare(strict_types=1);

$BL_CONFIG_FILE = __DIR__ . '/../config.php';
if (!file_exists($BL_CONFIG_FILE)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'not_installed']);
    exit;
}
$GLOBALS['BL_CFG'] = require $BL_CONFIG_FILE;

function cfg(string $key, $default = null) {
    return $GLOBALS['BL_CFG'][$key] ?? $default;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
            cfg('db_host'), cfg('db_name'), cfg('db_charset', 'utf8mb4'));
        try {
            $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (Throwable $e) {
            jerr('Database connection failed — check the db_* values in config.php. (' . $e->getMessage() . ')', 500, 'db_unreachable');
        }
    }
    return $pdo;
}

function uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

/* ---------- JSON helpers ---------- */
function jsend($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function jerr(string $msg, int $code = 400, string $err = 'error'): void {
    jsend(['ok' => false, 'error' => $err, 'message' => $msg], $code);
}
function jbody(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ?: '', true);
    return is_array($d) ? $d : [];
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $t = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        jerr('Security token mismatch — please refresh the page.', 403, 'csrf');
    }
}

/* ---------- auth ---------- */
function create_session_token(string $userId): void {
    $token = bin2hex(random_bytes(32));
    $st = db()->prepare('INSERT INTO sessions (id, user_id, token_hash, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 30 DAY))');
    $st->execute([uuid(), $userId, hash('sha256', $token)]);
    setcookie('bl_refresh', $token, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}

function current_user(): ?array {
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND status = 'active'");
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch();
        if ($u) return $u;
    }
    /* transparent refresh via 30-day cookie (SRS REQ-3.6.14) */
    $tok = $_COOKIE['bl_refresh'] ?? '';
    if ($tok !== '') {
        $st = db()->prepare('SELECT * FROM sessions WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()');
        $st->execute([hash('sha256', $tok)]);
        $s = $st->fetch();
        if ($s) {
            $st = db()->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND status = 'active'");
            $st->execute([$s['user_id']]);
            $u = $st->fetch();
            if ($u) {
                $_SESSION['uid'] = $u['id'];
                /* rotate token on use */
                db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE id = ?')->execute([$s['id']]);
                create_session_token($u['id']);
                return $u;
            }
        }
    }
    return null;
}

function require_user(): array {
    $u = current_user();
    if (!$u) jerr('Please log in.', 401, 'auth');
    return $u;
}
function require_admin(): array {
    $u = require_user();
    if ($u['role'] !== 'admin') jerr('Admins only.', 403, 'forbidden');
    return $u;
}

function revoke_sessions(string $userId, ?string $exceptTokenHash = null): void {
    if ($exceptTokenHash) {
        db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND token_hash <> ?')
          ->execute([$userId, $exceptTokenHash]);
    } else {
        db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ?')->execute([$userId]);
    }
}

function audit(string $actorId, string $action, ?string $targetId = null, $meta = null): void {
    db()->prepare('INSERT INTO audit_log (actor_id, action, target_user_id, meta, ip) VALUES (?,?,?,?,?)')
      ->execute([$actorId, $action, $targetId, $meta === null ? null : json_encode($meta), $_SERVER['REMOTE_ADDR'] ?? '']);
}

/* ---------- validation (SRS 3.6) ---------- */
function valid_username(string $u): ?string {
    if (!preg_match('/^[A-Za-z0-9._-]{3,20}$/', $u)) return 'Username must be 3–20 characters: letters, digits, dot, dash or underscore.';
    if (in_array(strtolower($u), ['admin', 'root', 'support', 'api', 'www'], true)) return 'That username is reserved.';
    return null;
}
function valid_password(string $p): ?string {
    if (strlen($p) < 8 || strlen($p) > 72) return 'Password must be 8–72 characters.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9]/', $p)) return 'Password needs at least one letter and one digit.';
    return null;
}
function valid_email(string $e): bool {
    return (bool) filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 255;
}

/* ---------- login rate limiting (SRS REQ-3.6.9) ----------
   Throttles on BOTH the account and the source IP, catches slow brute
   force via a consecutive-fail streak, and caps the backoff so lockout
   windows cannot compound indefinitely. */
function login_throttle_seconds(string $usernameLc): int {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $st = db()->prepare(
        'SELECT
           (SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND username_lc = ?
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS u,
           (SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND ip = ?
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS i,
           (SELECT COUNT(*) FROM login_attempts
             WHERE username_lc = ? AND success = 0
               AND attempted_at > COALESCE(
                     (SELECT MAX(attempted_at) FROM login_attempts
                       WHERE username_lc = ? AND success = 1), "1970-01-01")) AS streak');
    $st->execute([$usernameLc, $ip, $usernameLc, $usernameLc]);
    $r = $st->fetch();
    $fails = max((int)($r['u'] ?? 0), (int)($r['i'] ?? 0), (int)($r['streak'] ?? 0));
    if ($fails < 5) return 0;
    // Cap the compounding: backoff tops out at 15 minutes per window and
    // the streak is floored at 10, so an attacker cannot chain lockouts forever.
    $wait = min(900, 30 * (2 ** min($fails - 5, 5)));
    $st2 = db()->prepare('SELECT MAX(attempted_at) last FROM login_attempts WHERE success = 0 AND (username_lc = ? OR ip = ?)');
    $st2->execute([$usernameLc, $ip]);
    $last = $st2->fetch()['last'] ?? null;
    if (!$last) return 0;
    return max(0, (int)($wait - (time() - strtotime($last))));
}
function login_attempt(string $usernameLc, bool $success): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    db()->prepare('INSERT INTO login_attempts (username_lc, ip, success) VALUES (?,?,?)')
      ->execute([$usernameLc, $ip, $success ? 1 : 0]);
    if ($success) {
        db()->prepare('DELETE FROM login_attempts WHERE success = 0 AND (username_lc = ? OR ip = ?)')
          ->execute([$usernameLc, $ip]);
    }
}

/* ---------- tokens (email verify / password reset) ---------- */
function make_token(string $userId, string $purpose, int $hours): string {
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO tokens (id, user_id, token_hash, purpose, expires_at) VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? HOUR))')
      ->execute([uuid(), $userId, hash('sha256', $token), $purpose, $hours]);
    return $token;
}
function use_token(string $token, string $purpose): ?array {
    $st = db()->prepare('SELECT * FROM tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()');
    $st->execute([hash('sha256', $token), $purpose]);
    $t = $st->fetch();
    if (!$t) return null;
    db()->prepare('UPDATE tokens SET used_at = NOW() WHERE id = ?')->execute([$t['id']]);
    return $t;
}

/* ---------- public user shape ---------- */
function public_user(array $u): array {
    $emailState = 'none';
    if (!empty($u['email_pending'])) $emailState = 'pending';
    elseif (!empty($u['email']) && !empty($u['email_verified_at'])) $emailState = 'verified';
    elseif (!empty($u['email'])) $emailState = 'pending';
    return [
        'id'                    => $u['id'],
        'username'              => $u['username'],
        'role'                  => $u['role'],
        'email'                 => $u['email_pending'] ?: $u['email'],
        'email_state'           => $emailState,
        'must_change_password'  => (bool) $u['must_change_password'],
        'created_at'            => $u['created_at'],
        'last_login_at'         => $u['last_login_at'],
    ];
}
