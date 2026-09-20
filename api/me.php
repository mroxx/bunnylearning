<?php
/** GET /api/me.php — current user + CSRF token */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$u = current_user();
jsend(['ok' => true, 'user' => $u ? public_user($u) : null, 'csrf' => csrf_token()]);
