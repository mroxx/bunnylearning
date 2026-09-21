<?php
/** POST /api/login.php — with rate limiting + generic errors (SRS REQ-3.6.9) */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Method not allowed', 405);
csrf_check();
$b = jbody();

$username = trim((string)($b['username'] ?? ''));
$password = (string)($b['password'] ?? '');
$lc = strtolower($username);

$wait = login_throttle_seconds($lc);
if ($wait > 0) {
    jerr('Too many attempts. Please try again in ' . ceil($wait / 60) . ' minute(s).', 429, 'throttled');
}

$st = db()->prepare("SELECT * FROM users WHERE username_lc = ? AND deleted_at IS NULL");
$st->execute([$lc]);
$u = $st->fetch();

if (!$u || !password_verify($password, $u['password_hash'])) {
    login_attempt($lc, false);
    jerr('Wrong username or password.', 401, 'bad_credentials');  /* generic on purpose */
}
if ($u['status'] !== 'active') {
    jerr('This account has been deactivated. Please ask a parent or teacher.', 403, 'deactivated');
}

login_attempt($lc, true);
db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
$_SESSION['uid'] = $u['id'];
unset($_SESSION['csrf']);            /* rotate CSRF on privilege change */
create_session_token($u['id']);
audit($u['id'], 'login', $u['id']);

$u['last_login_at'] = date('Y-m-d H:i:s');
jsend(['ok' => true, 'user' => public_user($u), 'csrf' => csrf_token()]);
