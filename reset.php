<?php
/** GET /reset.php?t=... — password reset landing page (posts to api/reset_password.php) */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
$token = htmlspecialchars((string)($_GET['t'] ?? ''), ENT_QUOTES);
$csrf = csrf_token();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset password — Bunny Learning</title>
<style>
 body{font-family:system-ui,sans-serif;background:#fdf6e3;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
 .card{background:#fff;border:3px solid #2b2b2b;border-radius:20px;box-shadow:6px 6px 0 #2b2b2b;padding:32px;max-width:420px;width:calc(100% - 48px)}
 input{width:100%;box-sizing:border-box;border:3px solid #2b2b2b;border-radius:12px;padding:12px;font-size:16px;margin:6px 0}
 button{background:#38bdf8;border:3px solid #2b2b2b;border-radius:12px;box-shadow:4px 4px 0 #2b2b2b;padding:12px 20px;font-size:16px;font-weight:700;cursor:pointer;width:100%}
 button:active{transform:translate(2px,2px);box-shadow:2px 2px 0 #2b2b2b}
 .msg{margin:12px 0;font-weight:600}
 a{color:#2563eb}
</style></head><body><div class="card">
<h1>🔑 New password</h1>
<div id="f">
 <input type="password" id="p1" placeholder="New password (8+ chars, letter + digit)" autocomplete="new-password">
 <input type="password" id="p2" placeholder="Repeat new password" autocomplete="new-password">
 <button onclick="go()">Set new password</button>
</div>
<div class="msg" id="m"></div>
<p><a href="index.html">Back to Bunny Learning</a></p>
<script>
const CSRF = <?= json_encode($csrf) ?>, TOKEN = <?= json_encode($token) ?>;
async function go(){
  const m = document.getElementById('m');
  const p1 = document.getElementById('p1').value, p2 = document.getElementById('p2').value;
  if (p1 !== p2){ m.textContent = 'The two passwords do not match.'; return; }
  const r = await fetch('api/reset_password.php', {method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF':CSRF},
    body: JSON.stringify({token: TOKEN, password: p1})});
  const d = await r.json().catch(()=>({message:'Something went wrong.'}));
  m.textContent = d.message || (d.ok ? 'Done!' : 'Error.');
  if (d.ok) document.getElementById('f').style.display = 'none';
}
</script>
</div></body></html>
