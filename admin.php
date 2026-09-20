<?php
/** Admin console (SRS 3.8) — server-rendered shell, data via api/admin.php */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
$u = current_user();
if (!$u || $u['role'] !== 'admin') {
    header('Location: index.html#/me');
    exit;
}
$csrf = csrf_token();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Bunny Learning</title>
<style>
 :root{--ink:#2b2b2b;--cream:#fdf6e3;--sky:#38bdf8;--pink:#f9a8d4;--green:#86efac}
 *{box-sizing:border-box}
 body{font-family:system-ui,sans-serif;background:var(--cream);margin:0;padding:20px;color:var(--ink)}
 .wrap{max-width:960px;margin:0 auto}
 h1{display:flex;align-items:center;gap:10px}
 .card{background:#fff;border:3px solid var(--ink);border-radius:16px;box-shadow:5px 5px 0 var(--ink);padding:18px;margin-bottom:18px}
 input,select{border:3px solid var(--ink);border-radius:10px;padding:8px 10px;font-size:14px}
 button{border:3px solid var(--ink);border-radius:10px;box-shadow:3px 3px 0 var(--ink);background:var(--sky);padding:7px 14px;font-weight:700;cursor:pointer;font-size:13px}
 button:active{transform:translate(2px,2px);box-shadow:1px 1px 0 var(--ink)}
 button.warn{background:var(--pink)} button.good{background:var(--green)}
 table{width:100%;border-collapse:collapse;font-size:14px}
 th,td{text-align:left;padding:8px;border-bottom:2px solid #eee}
 th{cursor:pointer;user-select:none}
 .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
 .badge{display:inline-block;border:2px solid var(--ink);border-radius:999px;padding:1px 10px;font-size:12px;font-weight:700}
 .b-active{background:var(--green)} .b-deactivated{background:var(--pink)} .b-admin{background:#fde68a}
 .msg{margin:10px 0;font-weight:700}
 .temp{background:#fef9c3;border:3px dashed var(--ink);border-radius:12px;padding:12px;margin:12px 0;font-size:18px;font-family:monospace;display:none}
 .small{font-size:12px;color:#777}
 a{color:#2563eb}
 .nav{margin-bottom:16px;display:flex;gap:14px;align-items:center}
 .tabs button{margin-right:8px}
 .tabs button.on{background:var(--green)}
</style></head><body><div class="wrap">
<div class="nav">
  <h1 style="margin:0">🐰 Admin</h1>
  <span class="small">logged in as <strong><?= htmlspecialchars($u['username']) ?></strong></span>
  <span style="flex:1"></span>
  <a href="index.html">← App</a>
</div>

<div class="tabs">
  <button id="tab-users" class="on" onclick="showTab('users')">Users</button>
  <button id="tab-audit" onclick="showTab('audit')">Audit log</button>
  <button id="tab-mail" onclick="showTab('mail')">Mail test</button>
</div>

<div id="msg" class="msg"></div>
<div id="temp" class="temp"></div>

<!-- USERS -->
<div id="view-users">
  <div class="card">
    <div class="row">
      <input id="q" placeholder="Search username or email…" onkeydown="if(event.key==='Enter')load(1)">
      <select id="frole"><option value="">any role</option><option value="learner">learner</option><option value="admin">admin</option></select>
      <select id="fstatus"><option value="">any status</option><option value="active">active</option><option value="deactivated">deactivated</option></select>
      <button onclick="load(1)">Search</button>
    </div>
    <table><thead><tr>
      <th onclick="sortBy('username')">Username</th>
      <th>Role</th><th>Status</th>
      <th onclick="sortBy('created_at')">Joined</th>
      <th onclick="sortBy('last_login_at')">Last login</th>
      <th></th>
    </tr></thead><tbody id="rows"></tbody></table>
    <div class="row" style="margin-top:12px">
      <button onclick="load(page-1)">← Prev</button>
      <span id="pg"></span>
      <button onclick="load(page+1)">Next →</button>
    </div>
  </div>
  <div class="card" id="detail" style="display:none"></div>
</div>

<!-- AUDIT -->
<div id="view-audit" style="display:none">
  <div class="card">
    <table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>IP</th></tr></thead>
    <tbody id="arows"></tbody></table>
    <div class="row" style="margin-top:12px">
      <button onclick="loadAudit(apage-1)">← Prev</button>
      <span id="apg"></span>
      <button onclick="loadAudit(apage+1)">Next →</button>
    </div>
  </div>
</div>

<!-- MAIL -->
<div id="view-mail" style="display:none">
  <div class="card">
    <h3>Send test mail</h3>
    <p class="small">Uses the SMTP settings from <code>config.php</code>. To change them, edit that file (or reinstall).</p>
    <div class="row"><input id="mailTo" placeholder="recipient@example.com" style="flex:1"><button onclick="testMail()">Send test mail</button></div>
  </div>
</div>

</div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
let page = 1, apage = 1, pages = 1, apages = 1, sort = 'created_at', dir = 'desc';

function msg(t, bad){ const m = document.getElementById('msg'); m.textContent = t||''; m.style.color = bad ? '#b91c1c' : '#15803d'; }
function showTemp(t){ const d = document.getElementById('temp');
  if(!t){ d.style.display='none'; return; }
  d.style.display='block';
  d.innerHTML = '⚠️ Temporary password (shown only once — copy it now): <strong>'+t+'</strong> ' +
    '<button onclick="navigator.clipboard.writeText(\''+t+'\')">Copy</button>';
}
async function api(params, body){
  const opt = body ? {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':CSRF}, body:JSON.stringify(body)} : {};
  const r = await fetch('api/admin.php' + (params ? '?'+params : ''), opt);
  const d = await r.json().catch(()=>({ok:false,message:'Request failed'}));
  if(!d.ok) msg(d.message||'Error', true);
  return d;
}
function showTab(t){
  for(const v of ['users','audit','mail']){
    document.getElementById('view-'+v).style.display = v===t ? '' : 'none';
    document.getElementById('tab-'+v).classList.toggle('on', v===t);
  }
  if(t==='audit') loadAudit(1);
}
function sortBy(col){ dir = (sort===col && dir==='asc') ? 'desc' : 'asc'; sort = col; load(1); }

async function load(p){
  if(p < 1 || (pages && p > pages)) return;
  const q = encodeURIComponent(document.getElementById('q').value);
  const role = document.getElementById('frole').value, status = document.getElementById('fstatus').value;
  const d = await api(`action=users&q=${q}&role=${role}&status=${status}&sort=${sort}&dir=${dir}&page=${p}`);
  if(!d.ok) return;
  page = d.page; pages = d.pages;
  document.getElementById('pg').textContent = `Page ${page} / ${pages} · ${d.total} users`;
  document.getElementById('rows').innerHTML = d.users.map(u => `<tr>
    <td><strong>${esc(u.username)}</strong>${u.email ? '<br><span class="small">'+esc(u.email)+(u.email_verified_at?' ✓':' (unverified)')+'</span>' : ''}</td>
    <td>${u.role==='admin' ? '<span class="badge b-admin">admin</span>' : 'learner'}</td>
    <td><span class="badge b-${u.status}">${u.status}</span></td>
    <td class="small">${esc(u.created_at||'')}</td>
    <td class="small">${esc(u.last_login_at||'—')}</td>
    <td><button onclick="detail('${u.id}')">Manage</button></td>
  </tr>`).join('') || '<tr><td colspan="6">No users found.</td></tr>';
}

async function detail(id){
  const d = await api('action=user&id='+id);
  if(!d.ok) return;
  const u = d.user;
  const el = document.getElementById('detail');
  el.style.display = '';
  el.innerHTML = `<h3>👤 ${esc(u.username)} ${u.role==='admin' ? '<span class="badge b-admin">admin</span>' : ''}
      <span class="badge b-${u.status}">${u.status}</span></h3>
    <p class="small">Joined: ${esc(u.created_at)} · Last login: ${esc(u.last_login_at||'—')}<br>
    Email: ${u.email ? esc(u.email)+(u.email_verified_at?' (verified ✓)':' (unverified)') : '—'}${u.email_pending ? ' · pending: '+esc(u.email_pending) : ''}
    ${u.must_change_password==1 ? '<br>⚠️ Must change password at next login' : ''}</p>
    <p class="small">Progress: ${(d.progress||[]).map(p=>p.app+': '+p.coins+' coins').join(' · ')||'none yet'}</p>
    <div class="row">
      <input id="nu" value="${esc(u.username)}" title="new username">
      <button onclick="patchUser('${u.id}')">Rename</button>
      <button class="good" onclick="toggleRole('${u.id}','${u.role}')">${u.role==='admin'?'Make learner':'Make admin'}</button>
      <button onclick="resetPw('${u.id}')">Reset password</button>
      ${u.status==='active'
        ? '<button class="warn" onclick="setStatus(\''+u.id+'\',\'deactivate\')">Deactivate</button>'
        : '<button class="good" onclick="setStatus(\''+u.id+'\',\'activate\')">Activate</button>'}
      <button class="warn" onclick="delUser('${u.id}')">Delete</button>
    </div>`;
  el.scrollIntoView({behavior:'smooth'});
}
async function patchUser(id){
  const nu = document.getElementById('nu').value.trim();
  const d = await api(null, {action:'patch_user', id, username:nu});
  if(d.ok){ msg(d.message); detail(id); load(page); }
}
async function toggleRole(id, role){
  const nr = role==='admin' ? 'learner' : 'admin';
  if(!confirm(`Make this user ${nr}?`)) return;
  const d = await api(null, {action:'patch_user', id, role:nr});
  if(d.ok){ msg(d.message); detail(id); load(page); }
}
async function resetPw(id){
  if(!confirm("Reset this user's password? All their sessions are logged out.")) return;
  const d = await api(null, {action:'reset_password', id});
  if(d.ok){ msg(d.message); showTemp(d.temp_password); }
}
async function setStatus(id, action){
  if(!confirm(action==='deactivate' ? 'Deactivate this account? The user is logged out immediately.' : 'Activate this account?')) return;
  const d = await api(null, {action, id});
  if(d.ok){ msg(d.message); detail(id); load(page); }
}
async function delUser(id){
  if(!confirm('DELETE this user? Progress is removed and the account is anonymized. This cannot be undone.')) return;
  const d = await api(null, {action:'delete', id});
  if(d.ok){ msg(d.message); document.getElementById('detail').style.display='none'; load(page); }
}
async function loadAudit(p){
  if(p < 1 || (apages && p > apages)) return;
  const d = await api('action=audit&page='+p);
  if(!d.ok) return;
  apage = d.page; apages = d.pages;
  document.getElementById('apg').textContent = `Page ${apage} / ${apages} · ${d.total} entries`;
  document.getElementById('arows').innerHTML = d.entries.map(e => `<tr>
    <td class="small">${esc(e.created_at)}</td><td>${esc(e.actor||'—')}</td>
    <td><code>${esc(e.action)}</code></td><td>${esc(e.target||'—')}</td>
    <td class="small">${esc(e.ip||'')}</td></tr>`).join('') || '<tr><td colspan="5">No entries yet.</td></tr>';
}
async function testMail(){
  const to = document.getElementById('mailTo').value.trim();
  const d = await api(null, {action:'test_mail', to});
  if(d.ok) msg(d.message);
}
function esc(s){ return String(s??'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
load(1);
</script>
</body></html>
