<?php
/** POST /api/register.php — username-only signup (SRS 3.6) */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Method not allowed', 405);
csrf_check();
$b = jbody();

$username = trim((string)($b['username'] ?? ''));
$password = (string)($b['password'] ?? '');

if ($m = valid_username($username)) jerr($m, 422, 'validation');
if ($m = valid_password($password)) jerr($m, 422, 'validation');

$lc = strtolower($username);
$st = db()->prepare('SELECT id FROM users WHERE username_lc = ?');
$st->execute([$lc]);
if ($st->fetch()) jerr('That username is already taken.', 409, 'username_taken');

$id = uuid();
db()->prepare('INSERT INTO users (id, username, username_lc, password_hash, role, status, created_at)
               VALUES (?,?,?,?,?, ?, NOW())')
  ->execute([$id, $username, $lc,
             password_hash($password, PASSWORD_DEFAULT),
             'learner', 'active']);

$_SESSION['uid'] = $id;
create_session_token($id);
audit($id, 'register', $id);

$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$id]);
jsend(['ok' => true, 'user' => public_user($st->fetch())]);
