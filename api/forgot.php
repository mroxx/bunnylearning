<?php
/**
 * POST /api/forgot.php — request a password-reset mail.
 * Only possible for accounts with a verified email (SRS 3.7);
 * always answers ok to avoid account enumeration.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/smtp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Method not allowed', 405);
csrf_check();
$b = jbody();
$lc = strtolower(trim((string)($b['username'] ?? '')));

/* Rate limiting (SRS 3.7): at most 3 reset mails per 15 minutes per IP
   and per username — stops inbox bombing and SMTP quota burn.
   The table is created lazily so existing installs self-heal. */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
db()->exec('CREATE TABLE IF NOT EXISTS forgot_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username_lc VARCHAR(20) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_user_time (username_lc, attempted_at),
  KEY ix_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$st = db()->prepare('SELECT COUNT(*) c FROM forgot_attempts
                     WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (username_lc = ? OR ip = ?)');
$st->execute([$lc, $ip]);
if ((int)$st->fetch()['c'] >= 3) {
    jerr('Too many requests. Please try again later.', 429, 'throttled');
}
db()->prepare('INSERT INTO forgot_attempts (username_lc, ip) VALUES (?,?)')->execute([$lc, $ip]);

$st = db()->prepare("SELECT * FROM users WHERE username_lc = ? AND deleted_at IS NULL
                     AND email IS NOT NULL AND email_verified_at IS NOT NULL");
$st->execute([$lc]);
$u = $st->fetch();

if ($u) {
    $token = make_token($u['id'], 'password_reset', 2);
    try {
        send_password_reset_mail($u['email'], $token);
        audit($u['id'], 'password_reset_requested', $u['id']);
    } catch (Throwable $e) {
        jerr('The mail could not be sent. Please try again later or ask an admin.', 502, 'mail_failed');
    }
}
jsend(['ok' => true, 'message' => 'If this account has a verified email address, a reset link is on its way.']);
