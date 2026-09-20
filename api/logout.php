<?php
/** POST /api/logout.php */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Method not allowed', 405);
csrf_check();

$u = current_user();
$tok = $_COOKIE['bl_refresh'] ?? '';
if ($tok !== '') {
    db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE token_hash = ?')
      ->execute([hash('sha256', $tok)]);
    setcookie('bl_refresh', '', ['expires' => time() - 3600, 'path' => '/']);
}
if ($u) audit($u['id'], 'logout', $u['id']);
$_SESSION = [];
session_destroy();
jsend(['ok' => true]);
