<?php
/** GET /verify_email.php?t=... — landing page for email verification links */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$ok = false; $msg = '';
$t = (string)($_GET['t'] ?? '');
if ($t !== '') {
    $st = db()->prepare("SELECT tk.*, u.email_pending FROM tokens tk
                         JOIN users u ON u.id = tk.user_id
                         WHERE tk.token_hash = ? AND tk.purpose = 'verify_email'
                           AND tk.used_at IS NULL AND tk.expires_at > NOW()");
    $st->execute([hash('sha256', $t)]);
    $r = $st->fetch();
    if ($r && !empty($r['email_pending'])) {
        db()->prepare('UPDATE users SET email = ?, email_verified_at = NOW(), email_pending = NULL WHERE id = ?')
          ->execute([$r['email_pending'], $r['user_id']]);
        db()->prepare('UPDATE tokens SET used_at = NOW() WHERE id = ?')->execute([$r['id']]);
        audit($r['user_id'], 'email_verified', $r['user_id']);
        $ok = true;
    } else {
        $msg = 'This verification link is invalid or has expired. Please request a new one in the app (Me tab).';
    }
} else {
    $msg = 'Missing token.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email verification — Bunny Learning</title>
<style>
 body{font-family:system-ui,sans-serif;background:#fdf6e3;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
 .card{background:#fff;border:3px solid #2b2b2b;border-radius:20px;box-shadow:6px 6px 0 #2b2b2b;padding:32px;max-width:420px;text-align:center}
 a{color:#2563eb}
</style></head><body><div class="card">
<h1><?= $ok ? '🎉 Email confirmed!' : '😕 Oops…' ?></h1>
<p><?= $ok ? 'Your email address is now verified. You can close this page and go back to the app.' : htmlspecialchars($msg) ?></p>
<p><a href="index.html">Back to Bunny Learning</a></p>
</div></body></html>
