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
