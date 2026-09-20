<?php
/** POST /api/reset_password.php — {token, password} */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Method not allowed', 405);
csrf_check();
$b = jbody();

$token    = (string)($b['token'] ?? '');
$password = (string)($b['password'] ?? '');

if ($token === '') jerr('Missing token.', 422, 'validation');
if ($m = valid_password($password)) jerr($m, 422, 'validation');

/* validate without consuming yet */
$st = db()->prepare("SELECT * FROM tokens WHERE token_hash = ? AND purpose = 'password_reset'
                     AND used_at IS NULL AND expires_at > NOW()");
$st->execute([hash('sha256', $token)]);
$t = $st->fetch();
if (!$t) jerr('This reset link is invalid or has expired. Please request a new one.', 410, 'token_invalid');

db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
  ->execute([password_hash($password, PASSWORD_DEFAULT), $t['user_id']]);
db()->prepare('UPDATE tokens SET used_at = NOW() WHERE id = ?')->execute([$t['id']]);
revoke_sessions($t['user_id']);   /* log out all devices */
audit($t['user_id'], 'password_reset_completed', $t['user_id']);

jsend(['ok' => true, 'message' => 'Password changed — you can log in now.']);
