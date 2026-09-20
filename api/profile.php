<?php
/**
 * POST /api/profile.php — account self-service (SRS 3.7)
 * action: change_password | add_email | remove_email | resend_verification | delete_account
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/smtp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Method not allowed', 405);
csrf_check();
$u = require_user();
$b = jbody();
$action = (string)($b['action'] ?? '');

$reload = function () use ($u) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    return $st->fetch();
};

switch ($action) {

    case 'change_password': {
        $old = (string)($b['old_password'] ?? '');
        $new = (string)($b['new_password'] ?? '');
        if (!password_verify($old, $u['password_hash'])) jerr('Current password is wrong.', 403, 'bad_password');
        if ($m = valid_password($new)) jerr($m, 422, 'validation');
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
          ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        /* keep this session, revoke all other devices (SRS REQ-3.7.x) */
        $keep = !empty($_COOKIE['bl_refresh']) ? hash('sha256', $_COOKIE['bl_refresh']) : null;
        revoke_sessions($u['id'], $keep);
        audit($u['id'], 'password_changed', $u['id']);
        jsend(['ok' => true, 'message' => 'Password changed.', 'user' => public_user($reload())]);
    }

    case 'add_email': {
        $email = trim((string)($b['email'] ?? ''));
        if (!valid_email($email)) jerr('That does not look like an email address.', 422, 'validation');
        $st = db()->prepare('SELECT id FROM users WHERE (email = ? OR email_pending = ?) AND id <> ? AND deleted_at IS NULL');
        $st->execute([$email, $email, $u['id']]);
        if ($st->fetch()) jerr('That email address is already used by another account.', 409, 'email_taken');
        db()->prepare('UPDATE users SET email_pending = ? WHERE id = ?')->execute([$email, $u['id']]);
        $token = make_token($u['id'], 'verify_email', 24);
        try {
            send_verification_mail($email, $token);
        } catch (Throwable $e) {
            db()->prepare('UPDATE users SET email_pending = NULL WHERE id = ?')->execute([$u['id']]);
            jerr('The verification mail could not be sent. Check the mail server settings.', 502, 'mail_failed');
        }
        audit($u['id'], 'email_added', $u['id']);
        jsend(['ok' => true, 'message' => 'Verification mail sent — please check your inbox.', 'user' => public_user($reload())]);
    }

    case 'resend_verification': {
        if (empty($u['email_pending'])) jerr('No pending email address.', 422, 'validation');
        /* resend at most once per 5 minutes (SRS REQ-3.7.x) */
        $st = db()->prepare("SELECT MAX(created_at) last FROM tokens WHERE user_id = ? AND purpose = 'verify_email'");
        $st->execute([$u['id']]);
        $last = $st->fetch()['last'] ?? null;
        if ($last && time() - strtotime($last) < 300) {
            jerr('Please wait a few minutes before resending.', 429, 'throttled');
        }
        $token = make_token($u['id'], 'verify_email', 24);
        try {
            send_verification_mail($u['email_pending'], $token);
        } catch (Throwable $e) {
            jerr('The mail could not be sent. Please try again later.', 502, 'mail_failed');
        }
        jsend(['ok' => true, 'message' => 'Verification mail sent again.']);
    }

    case 'remove_email': {
        $pw = (string)($b['password'] ?? '');
        if (!password_verify($pw, $u['password_hash'])) jerr('Password is wrong.', 403, 'bad_password');
        db()->prepare('UPDATE users SET email = NULL, email_verified_at = NULL, email_pending = NULL WHERE id = ?')
          ->execute([$u['id']]);
        audit($u['id'], 'email_removed', $u['id']);
        jsend(['ok' => true, 'message' => 'Email removed.', 'user' => public_user($reload())]);
    }

    case 'delete_account': {
        $pw = (string)($b['password'] ?? '');
        if (!password_verify($pw, $u['password_hash'])) jerr('Password is wrong.', 403, 'bad_password');
        /* last-admin protection */
        if ($u['role'] === 'admin') {
            $st = db()->query("SELECT COUNT(*) c FROM users WHERE role = 'admin' AND status = 'active' AND deleted_at IS NULL");
            if ((int)$st->fetch()['c'] <= 1) jerr('You are the last admin — promote another admin first.', 409, 'last_admin');
        }
        /* soft-delete + anonymize (SRS REQ-3.7.x) */
        $anon = 'deleted-' . substr($u['id'], 0, 8);
        db()->prepare('UPDATE users SET username = ?, username_lc = ?, email = NULL, email_pending = NULL,
                       email_verified_at = NULL, password_hash = ?, status = ?, deleted_at = NOW() WHERE id = ?')
          ->execute([$anon, $anon, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'deactivated', $u['id']]);
        revoke_sessions($u['id']);
        audit($u['id'], 'account_deleted', $u['id']);
        $_SESSION = [];
        jsend(['ok' => true, 'message' => 'Account deleted. Bye-bye!']);
    }

    default:
        jerr('Unknown action.', 422, 'validation');
}
