<?php
/**
 * Admin API (SRS 3.8) — all actions require role=admin.
 *
 * GET  /api/admin.php?action=users&q=&role=&status=&sort=&page=
 * GET  /api/admin.php?action=user&id=
 * GET  /api/admin.php?action=audit&page=
 * POST /api/admin.php  {action: patch_user|reset_password|activate|deactivate|delete, id, ...}
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/smtp.php';

$admin = require_admin();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) csrf_check();
$b = $isPost ? jbody() : $_GET;
$action = (string)($b['action'] ?? '');

$PER_PAGE = 20;

switch ($action) {

    /* ---------- list users ---------- */
    case 'users': {
        $where = ['deleted_at IS NULL'];
        $args = [];
        $q = trim((string)($b['q'] ?? ''));
        if ($q !== '') { $where[] = '(username LIKE ? OR email LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
        $role = (string)($b['role'] ?? '');
        if (in_array($role, ['learner', 'admin'], true)) { $where[] = 'role = ?'; $args[] = $role; }
        $status = (string)($b['status'] ?? '');
        if (in_array($status, ['active', 'deactivated'], true)) { $where[] = 'status = ?'; $args[] = $status; }
        $w = implode(' AND ', $where);

        $sort = (string)($b['sort'] ?? 'created_at');
        $sortCol = in_array($sort, ['username', 'created_at', 'last_login_at'], true) ? $sort : 'created_at';
        $dir = (($b['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        $page = max(1, (int)($b['page'] ?? 1));

        $st = db()->prepare("SELECT COUNT(*) c FROM users WHERE $w");
        $st->execute($args);
        $total = (int)$st->fetch()['c'];

        $st = db()->prepare("SELECT id, username, role, status, email, email_verified_at, created_at, last_login_at
                             FROM users WHERE $w ORDER BY $sortCol $dir
                             LIMIT " . $PER_PAGE . " OFFSET " . (($page - 1) * $PER_PAGE));
        $st->execute($args);
        jsend(['ok' => true, 'users' => $st->fetchAll(), 'total' => $total,
               'page' => $page, 'pages' => max(1, (int)ceil($total / $PER_PAGE))]);
    }

    /* ---------- user detail ---------- */
    case 'user': {
        $id = (string)($b['id'] ?? '');
        $st = db()->prepare('SELECT id, username, role, status, email, email_verified_at, email_pending,
                                    must_change_password, created_at, last_login_at
                             FROM users WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $usr = $st->fetch();
        if (!$usr) jerr('User not found.', 404, 'not_found');
        $st = db()->prepare('SELECT app, coins, stars, done, updated_at FROM progress WHERE user_id = ?');
        $st->execute([$id]);
        $prog = [];
        foreach ($st->fetchAll() as $row) {
            $prog[] = [
                'app'        => $row['app'],
                'coins'      => (int)$row['coins'],
                'stars'      => json_decode($row['stars'] ?: '{}', true),
                'done'       => json_decode($row['done'] ?: '{}', true),
                'updated_at' => $row['updated_at'],
            ];
        }
        jsend(['ok' => true, 'user' => $usr, 'progress' => $prog]);
    }

    /* ---------- patch role / username ---------- */
    case 'patch_user': {
        $id = (string)($b['id'] ?? '');
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) jerr('User not found.', 404, 'not_found');

        if (isset($b['username'])) {
            $nu = trim((string)$b['username']);
            if ($m = valid_username($nu)) jerr($m, 422, 'validation');
            $st2 = db()->prepare('SELECT id FROM users WHERE username_lc = ? AND id <> ?');
            $st2->execute([strtolower($nu), $id]);
            if ($st2->fetch()) jerr('That username is already taken.', 409, 'username_taken');
            db()->prepare('UPDATE users SET username = ?, username_lc = ? WHERE id = ?')
              ->execute([$nu, strtolower($nu), $id]);
        }
        if (isset($b['role'])) {
            $nr = (string)$b['role'];
            if (!in_array($nr, ['learner', 'admin'], true)) jerr('Invalid role.', 422, 'validation');
            if ($t['role'] === 'admin' && $nr === 'learner') {
                $c = db()->query("SELECT COUNT(*) c FROM users WHERE role='admin' AND status='active' AND deleted_at IS NULL")->fetch()['c'];
                if ((int)$c <= 1) jerr('This is the last admin — promote another admin first.', 409, 'last_admin');
            }
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$nr, $id]);
        }
        audit($admin['id'], 'admin_patch_user', $id, ['fields' => array_intersect_key($b, array_flip(['username', 'role']))]);
        jsend(['ok' => true, 'message' => 'Saved.']);
    }

    /* ---------- reset password → temp password shown once ---------- */
    case 'reset_password': {
        $id = (string)($b['id'] ?? '');
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        if (!$st->fetch()) jerr('User not found.', 404, 'not_found');
        $temp = ucwords(strtolower(bin2hex(random_bytes(3)))) . (string)random_int(100, 999); /* letter+digit, 8+ chars */
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
          ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
        revoke_sessions($id);
        audit($admin['id'], 'admin_reset_password', $id);
        jsend(['ok' => true, 'temp_password' => $temp,
               'message' => 'Temporary password set — the user must change it at next login.']);
    }

    case 'activate':
    case 'deactivate': {
        $id = (string)($b['id'] ?? '');
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) jerr('User not found.', 404, 'not_found');
        $new = $action === 'activate' ? 'active' : 'deactivated';
        if ($new === 'deactivated' && $t['role'] === 'admin') {
            $c = db()->query("SELECT COUNT(*) c FROM users WHERE role='admin' AND status='active' AND deleted_at IS NULL")->fetch()['c'];
            if ((int)$c <= 1 && $t['status'] === 'active') jerr('This is the last active admin.', 409, 'last_admin');
        }
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $id]);
        if ($new === 'deactivated') revoke_sessions($id);
        audit($admin['id'], 'admin_' . $action, $id);
        jsend(['ok' => true, 'message' => $new === 'active' ? 'Account activated.' : 'Account deactivated.']);
    }

    case 'delete': {
        $id = (string)($b['id'] ?? '');
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) jerr('User not found.', 404, 'not_found');
        if ($t['role'] === 'admin') {
            $c = db()->query("SELECT COUNT(*) c FROM users WHERE role='admin' AND status='active' AND deleted_at IS NULL")->fetch()['c'];
            if ((int)$c <= 1) jerr('This is the last admin — promote another admin first.', 409, 'last_admin');
        }
        $anon = 'deleted-' . substr($id, 0, 8);
        db()->prepare('UPDATE users SET username = ?, username_lc = ?, email = NULL, email_pending = NULL,
                       email_verified_at = NULL, password_hash = ?, status = ?, deleted_at = NOW() WHERE id = ?')
          ->execute([$anon, $anon, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'deactivated', $id]);
        revoke_sessions($id);
        audit($admin['id'], 'admin_delete_user', $id, ['username' => $t['username']]);
        jsend(['ok' => true, 'message' => 'User deleted and anonymized.']);
    }

    /* ---------- audit log ---------- */
    case 'audit': {
        $page = max(1, (int)($b['page'] ?? 1));
        $st = db()->query('SELECT COUNT(*) c FROM audit_log');
        $total = (int)$st->fetch()['c'];
        $st = db()->prepare('SELECT a.*, ua.username actor, ut.username target
                             FROM audit_log a
                             LEFT JOIN users ua ON ua.id = a.actor_id
                             LEFT JOIN users ut ON ut.id = a.target_user_id
                             ORDER BY a.created_at DESC
                             LIMIT ' . $PER_PAGE . ' OFFSET ' . (($page - 1) * $PER_PAGE));
        $st->execute();
        jsend(['ok' => true, 'entries' => $st->fetchAll(), 'total' => $total,
               'page' => $page, 'pages' => max(1, (int)ceil($total / $PER_PAGE))]);
    }

    /* ---------- send test mail (used by install + admin console) ---------- */
    case 'test_mail': {
        $to = trim((string)($b['to'] ?? ($admin['email'] ?? '')));
        if (!valid_email($to)) jerr('Please provide a valid recipient address.', 422, 'validation');
        try {
            smtp_send($to, 'Bunny Learning — test mail',
                "If you can read this, the mail server settings work. 🎉\n\n— Bunny Learning");
        } catch (Throwable $e) {
            jerr('Sending failed: ' . $e->getMessage(), 502, 'mail_failed');
        }
        audit($admin['id'], 'admin_test_mail', null, ['to' => $to]);
        jsend(['ok' => true, 'message' => 'Test mail sent to ' . $to]);
    }

    default:
        jerr('Unknown action.', 422, 'validation');
}
