<?php
/**
 * GET  /api/progress.php?app=bunny-zh   — pull progress for one app
 * POST /api/progress.php                — push + server-wins max-merge (SRS 3.9)
 *   body: {app, coins, stars: {...}, done: {...}, updatedAt}
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$u = require_user();
$APPS = ['bunny-zh', 'bunny-es'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $app = (string)($_GET['app'] ?? '');
    if (!in_array($app, $APPS, true)) jerr('Unknown app.', 422, 'validation');
    $st = db()->prepare('SELECT coins, stars, done, updated_at FROM progress WHERE user_id = ? AND app = ?');
    $st->execute([$u['id'], $app]);
    $r = $st->fetch();
    jsend(['ok' => true, 'progress' => $r ? [
        'coins'     => (int)$r['coins'],
        'stars'     => json_decode($r['stars'] ?: '{}', true),
        'done'      => json_decode($r['done'] ?: '{}', true),
        'updatedAt' => strtotime($r['updated_at']) * 1000,
    ] : null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Method not allowed', 405);
csrf_check();
$b = jbody();
$app = (string)($b['app'] ?? '');
if (!in_array($app, $APPS, true)) jerr('Unknown app.', 422, 'validation');

$coins = max(0, (int)($b['coins'] ?? 0));
$stars = is_array($b['stars'] ?? null) ? $b['stars'] : [];
$done  = is_array($b['done'] ?? null)  ? $b['done']  : [];

/* sanitize maps */
$starsC = [];
foreach ($stars as $k => $v) {
    if (is_string($k) && strlen($k) <= 100) $starsC[$k] = max(0, min(3, (int)$v));
}
$doneC = [];
foreach ($done as $k => $v) {
    if (is_string($k) && strlen($k) <= 100) $doneC[$k] = (bool)$v;
}

/* server-wins max merge (SRS REQ-3.9.x): coins = max, stars = per-lesson max, done = union */
$st = db()->prepare('SELECT coins, stars, done FROM progress WHERE user_id = ? AND app = ?');
$st->execute([$u['id'], $app]);
$old = $st->fetch();

$mergedCoins = $coins;
$mergedStars = $starsC;
$mergedDone  = $doneC;
if ($old) {
    $mergedCoins = max((int)$old['coins'], $coins);
    $oStars = json_decode($old['stars'] ?: '{}', true) ?: [];
    foreach ($oStars as $k => $v) {
        $mergedStars[$k] = max((int)$v, (int)($mergedStars[$k] ?? 0));
    }
    $oDone = json_decode($old['done'] ?: '{}', true) ?: [];
    foreach ($oDone as $k => $v) {
        if ($v) $mergedDone[$k] = true;
    }
}

db()->prepare('INSERT INTO progress (user_id, app, coins, stars, done, updated_at)
               VALUES (?,?,?,?,?, NOW())
               ON DUPLICATE KEY UPDATE coins = VALUES(coins), stars = VALUES(stars),
                                       done = VALUES(done), updated_at = NOW()')
  ->execute([$u['id'], $app, $mergedCoins, json_encode($mergedStars), json_encode($mergedDone)]);

jsend(['ok' => true, 'progress' => [
    'coins'     => $mergedCoins,
    'stars'     => $mergedStars,
    'done'      => $mergedDone,
    'updatedAt' => time() * 1000,
]]);
