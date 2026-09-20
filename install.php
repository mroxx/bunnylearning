<?php
/**
 * Bunny Learning — installation wizard.
 * Steps: 1 requirements → 2 database → 3 admin account → 4 mail server → 5 done.
 * Writes config.php + install.lock. Blocks itself once installed.
 */
declare(strict_types=1);
session_start();

$BASE   = __DIR__;
$CFG    = $BASE . '/config.php';
$LOCK   = $BASE . '/install.lock';
$SCHEMA = $BASE . '/schema.sql';

$installed = file_exists($CFG) && file_exists($LOCK);
$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
if ($installed) $step = 99;

$err = '';
$info = '';

/* ---------- tiny standalone SMTP sender for the test mail (config not written yet) ---------- */
function install_smtp_test(string $host, int $port, string $secure, string $user, string $pass,
                           string $from, string $fromName, string $to): ?string {
    try {
        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @fsockopen($remote, $port, $errno, $errstr, 15);
        if (!$fp) return "Cannot connect to $remote: $errstr ($errno)";
        stream_set_timeout($fp, 15);
        $read = function () use ($fp) {
            $d = '';
            while (($l = fgets($fp, 515)) !== false) { $d .= $l; if (isset($l[3]) && $l[3] === ' ') break; }
            return $d;
        };
        $cmd = function ($c, array $ok) use ($fp, $read) {
            if ($c !== '') fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array(substr($r, 0, 3), $ok, true)) throw new Exception('Server replied: ' . trim($r));
        };
        $cmd('', ['220']);
        $cmd('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), ['250']);
        if ($secure === 'tls') {
            $cmd('STARTTLS', ['220']);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return 'STARTTLS failed.';
            $cmd('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), ['250']);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN', ['334']);
            $cmd(base64_encode($user), ['334']);
            $cmd(base64_encode($pass), ['235']);
        }
        $cmd("MAIL FROM:<$from>", ['250']);
        $cmd("RCPT TO:<$to>", ['250', '251']);
        $cmd('DATA', ['354']);
        $subj = '=?UTF-8?B?' . base64_encode('Bunny Learning — test mail') . '?=';
        $fn   = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $body = "If you can read this, your Bunny Learning mail settings work. 🎉";
        fwrite($fp, "From: $fn <$from>\r\nTo: $to\r\nSubject: $subj\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($body)) . "\r\n.\r\n");
        $read();
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return null;   /* null = success */
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/* keep wizard state across steps */
$S = &$_SESSION['install'];
if (!is_array($S ?? null)) $S = [];

/* ---------- step handlers ---------- */
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($step === 2) {   /* database: test connection + create tables */
        $S['db_host'] = trim((string)$_POST['db_host']);
        $S['db_name'] = trim((string)$_POST['db_name']);
        $S['db_user'] = trim((string)$_POST['db_user']);
        $S['db_pass'] = (string)$_POST['db_pass'];
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $S['db_host'], $S['db_name']),
                $S['db_user'], $S['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $sql = file_get_contents($SCHEMA);
            if ($sql === false) throw new Exception('schema.sql not found — please upload all files.');
            $pdo->exec($sql);
            $step = 3;
            $info = 'Database connected and all tables created. ✔';
        } catch (Throwable $e) {
            $err = 'Database error: ' . $e->getMessage();
        }
    }

    elseif ($step === 3) {   /* admin account */
        $u = trim((string)$_POST['admin_user']);
        $p = (string)$_POST['admin_pass'];
        $e = trim((string)$_POST['admin_email']);
        if (!preg_match('/^[A-Za-z0-9._-]{3,20}$/', $u)) {
            $err = 'Username must be 3–20 characters (letters, digits, dot, dash, underscore).';
        } elseif (strlen($p) < 8 || strlen($p) > 72 || !preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9]/', $p)) {
            $err = 'Password must be 8–72 characters with at least one letter and one digit.';
        } elseif ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $err = 'That admin email does not look valid (you can leave it empty).';
        } else {
            $S['admin_user'] = $u; $S['admin_pass'] = $p; $S['admin_email'] = $e;
            $step = 4;
        }
    }

    elseif ($step === 4) {   /* mail server */
        $S['smtp_host']      = trim((string)$_POST['smtp_host']);
        $S['smtp_port']      = (int)$_POST['smtp_port'];
        $S['smtp_secure']    = in_array($_POST['smtp_secure'] ?? 'tls', ['none', 'tls', 'ssl'], true) ? $_POST['smtp_secure'] : 'tls';
        $S['smtp_user']      = trim((string)$_POST['smtp_user']);
        $S['smtp_pass']      = (string)$_POST['smtp_pass'];
        $S['smtp_from']      = trim((string)$_POST['smtp_from']);
        $S['smtp_from_name'] = trim((string)$_POST['smtp_from_name']) ?: 'Bunny Learning';
        $S['app_url']        = rtrim(trim((string)$_POST['app_url']), '/');
        $testTo              = trim((string)$_POST['test_to']);
        $skip                = !empty($_POST['skip_mail']);

        if ($skip) {
            $step = 5;
            $info = 'Mail test skipped — you can test later in the admin console.';
        } else {
            if ($S['smtp_host'] === '' || $S['smtp_from'] === '' || !filter_var($S['smtp_from'], FILTER_VALIDATE_EMAIL)) {
                $err = 'Please fill in at least SMTP host and a valid sender address.';
            } elseif (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                $err = 'Please enter a valid address for the test mail.';
            } else {
                $fail = install_smtp_test($S['smtp_host'], $S['smtp_port'] ?: 587, $S['smtp_secure'],
                                          $S['smtp_user'], $S['smtp_pass'], $S['smtp_from'],
                                          $S['smtp_from_name'], $testTo);
                if ($fail === null) { $step = 5; $info = 'Test mail sent to ' . htmlspecialchars($testTo) . ' — check your inbox! ✔'; }
                else $err = 'Test mail failed: ' . $fail . ' — fix the settings or tick “skip mail test”.';
            }
        }
    }

    elseif ($step === 5) {   /* write config + finish */
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $guess  = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php'), '/');
        $appUrl = $S['app_url'] ?? $guess;

        $cfg = [
            'db_host' => $S['db_host'] ?? 'localhost',
            'db_name' => $S['db_name'] ?? '',
            'db_user' => $S['db_user'] ?? '',
            'db_pass' => $S['db_pass'] ?? '',
            'db_charset' => 'utf8mb4',
            'smtp_host' => $S['smtp_host'] ?? '',
            'smtp_port' => $S['smtp_port'] ?? 587,
            'smtp_secure' => $S['smtp_secure'] ?? 'tls',
            'smtp_user' => $S['smtp_user'] ?? '',
            'smtp_pass' => $S['smtp_pass'] ?? '',
            'smtp_from' => $S['smtp_from'] ?? '',
            'smtp_from_name' => $S['smtp_from_name'] ?? 'Bunny Learning',
            'app_url' => $appUrl,
            'app_name' => 'Bunny Learning',
        ];
        $php = "<?php\n/* Bunny Learning configuration — generated by install.php on " . date('c') . "\n"
             . " * Edit values here if you move servers or change mail settings. */\n\nreturn "
             . var_export($cfg, true) . ";\n";
        if (@file_put_contents($CFG, $php) === false) {
            $err = 'Could not write config.php — please make the app folder writable for the web server and try again.';
        } else {
            try {
                $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_name']),
                               $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $uid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $st = $pdo->prepare('SELECT id FROM users WHERE username_lc = ?');
                $st->execute([strtolower($S['admin_user'])]);
                if (!$st->fetch()) {
                    $pdo->prepare('INSERT INTO users (id, username, username_lc, password_hash, role, status, email, email_verified_at, created_at)
                                   VALUES (?,?,?,?,?,?,?, ?, NOW())')
                        ->execute([$uid, $S['admin_user'], strtolower($S['admin_user']),
                                   password_hash($S['admin_pass'], PASSWORD_DEFAULT),
                                   'admin', 'active',
                                   $S['admin_email'] ?: null,
                                   $S['admin_email'] ? date('Y-m-d H:i:s') : null]);
                }
                file_put_contents($LOCK, 'installed ' . date('c') . "\n");
                unset($_SESSION['install']);
                $step = 99;   /* done page */
                $installed = true;
            } catch (Throwable $e) {
                @unlink($CFG);
                $err = 'Could not create the admin account: ' . $e->getMessage();
                $step = 4;
            }
        }
    }
}

/* ---------- requirements (step 1) ---------- */
$reqs = [
    'PHP ≥ 8.0'            => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO MySQL extension'  => extension_loaded('pdo_mysql'),
    'OpenSSL (for TLS mail)' => extension_loaded('openssl'),
    'Folder writable (config.php)' => is_writable($BASE),
    'schema.sql present'   => file_exists($SCHEMA),
];
$reqsOk = !in_array(false, $reqs, true);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install — Bunny Learning</title>
<style>
 body{font-family:system-ui,sans-serif;background:#fdf6e3;margin:0;padding:24px;display:flex;justify-content:center}
 .card{background:#fff;border:3px solid #2b2b2b;border-radius:20px;box-shadow:6px 6px 0 #2b2b2b;padding:28px;max-width:560px;width:100%}
 h1{margin-top:0}
 label{display:block;font-weight:700;margin:14px 0 4px}
 input,select{width:100%;box-sizing:border-box;border:3px solid #2b2b2b;border-radius:12px;padding:10px;font-size:15px}
 button{background:#38bdf8;border:3px solid #2b2b2b;border-radius:12px;box-shadow:4px 4px 0 #2b2b2b;padding:12px 22px;font-size:16px;font-weight:700;cursor:pointer;margin-top:18px}
 button:active{transform:translate(2px,2px);box-shadow:2px 2px 0 #2b2b2b}
 .err{background:#fee2e2;border:2px solid #b91c1c;border-radius:12px;padding:10px;margin:12px 0}
 .ok{background:#dcfce7;border:2px solid #15803d;border-radius:12px;padding:10px;margin:12px 0}
 .req{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #ddd}
 .hint{font-size:13px;color:#666;font-weight:400}
 .steps{font-size:13px;color:#666;margin-bottom:14px}
</style></head><body><div class="card">

<?php if ($step === 99): ?>
  <h1>🎉 Installation complete</h1>
  <?php if ($info): ?><div class="ok"><?= $info ?></div><?php endif; ?>
  <p>Bunny Learning is installed. For security, please <strong>delete <code>install.php</code></strong> from the server now.</p>
  <p>👉 <a href="index.html">Open the app</a> &nbsp;·&nbsp; 👩‍🏫 <a href="admin.php">Open the admin console</a></p>

<?php else: ?>
  <h1>🐰 Bunny Learning — Setup</h1>
  <div class="steps">Step <?= min($step, 5) ?> of 5</div>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="ok"><?= $info ?></div><?php endif; ?>

  <?php if ($step <= 1): ?>
    <h2>1 · Requirements</h2>
    <?php foreach ($reqs as $name => $ok): ?>
      <div class="req"><span><?= h($name) ?></span><span><?= $ok ? '✅' : '❌' ?></span></div>
    <?php endforeach; ?>
    <?php if ($reqsOk): ?>
      <form method="get"><input type="hidden" name="step" value="2"><button>Next: Database →</button></form>
    <?php else: ?>
      <p>Please fix the ❌ items above (ask your hosting provider), then reload this page.</p>
    <?php endif; ?>

  <?php elseif ($step === 2): ?>
    <h2>2 · MySQL database</h2>
    <p class="hint">Create an empty database + user first (in your hosting panel), then enter the details here. The tables are created automatically.</p>
    <form method="post"><input type="hidden" name="step" value="2">
      <label>DB host <input name="db_host" value="<?= h($S['db_host'] ?? 'localhost') ?>" required></label>
      <label>DB name <input name="db_name" value="<?= h($S['db_name'] ?? '') ?>" required></label>
      <label>DB user <input name="db_user" value="<?= h($S['db_user'] ?? '') ?>" required></label>
      <label>DB password <input type="password" name="db_pass" value="<?= h($S['db_pass'] ?? '') ?>"></label>
      <button>Connect &amp; create tables →</button>
    </form>

  <?php elseif ($step === 3): ?>
    <h2>3 · Admin account</h2>
    <p class="hint">This is the teacher/parent account that manages users in the admin console.</p>
    <form method="post"><input type="hidden" name="step" value="3">
      <label>Username <input name="admin_user" value="<?= h($S['admin_user'] ?? '') ?>" required></label>
      <label>Password <input type="password" name="admin_pass" required>
        <span class="hint">8–72 characters, at least one letter and one digit.</span></label>
      <label>Email (optional, for password resets) <input type="email" name="admin_email" value="<?= h($S['admin_email'] ?? '') ?>"></label>
      <button>Next: Mail server →</button>
    </form>

  <?php elseif ($step === 4): ?>
    <h2>4 · Mail server (SMTP)</h2>
    <p class="hint">Used for email verification and password resets. Your email provider (e.g. Gmail, mailbox.org, your hoster) lists these settings as “SMTP”. For Gmail use an <em>app password</em>.</p>
    <form method="post"><input type="hidden" name="step" value="4">
      <label>SMTP host <input name="smtp_host" value="<?= h($S['smtp_host'] ?? '') ?>" placeholder="smtp.example.com" required></label>
      <label>Port <input type="number" name="smtp_port" value="<?= h($S['smtp_port'] ?? 587) ?>" required></label>
      <label>Encryption
        <select name="smtp_secure">
          <option value="tls" <?= ($S['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
          <option value="ssl" <?= ($S['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
          <option value="none" <?= ($S['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None (local only)</option>
        </select></label>
      <label>SMTP username <input name="smtp_user" value="<?= h($S['smtp_user'] ?? '') ?>"></label>
      <label>SMTP password <input type="password" name="smtp_pass" value="<?= h($S['smtp_pass'] ?? '') ?>"></label>
      <label>Sender address <input type="email" name="smtp_from" value="<?= h($S['smtp_from'] ?? '') ?>" placeholder="bunny@example.com" required></label>
      <label>Sender name <input name="smtp_from_name" value="<?= h($S['smtp_from_name'] ?? 'Bunny Learning') ?>"></label>
      <label>App URL <span class="hint">(public address of this app — used in mail links)</span>
        <input name="app_url" value="<?= h($S['app_url'] ?? (($scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php'), '/'))) ?>"></label>
      <label>Send test mail to <input type="email" name="test_to" value="<?= h($S['admin_email'] ?? '') ?>"></label>
      <label style="font-weight:400"><input type="checkbox" name="skip_mail" style="width:auto"> Skip mail test (configure later)</label>
      <button>Send test mail &amp; continue →</button>
    </form>

  <?php elseif ($step === 5): ?>
    <h2>5 · Finish</h2>
    <p>Everything is ready. Click below to write <code>config.php</code> and create the admin account.</p>
    <form method="post"><input type="hidden" name="step" value="5"><button>Finish installation ✔</button></form>
  <?php endif; ?>
<?php endif; ?>

</div></body></html>
