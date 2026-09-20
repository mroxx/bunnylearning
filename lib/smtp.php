<?php
/**
 * Bunny Learning — minimal SMTP client (AUTH LOGIN, STARTTLS / SSL).
 * No external dependencies; reads smtp_* values from config.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

class SmtpError extends Exception {}

function smtp_read($fp): string {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        /* last line of a reply: "250 ..." (space after code), not "250-..." */
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    if ($data === '') throw new SmtpError('No reply from mail server.');
    return $data;
}

function smtp_expect($fp, string $cmd, array $okCodes): string {
    if ($cmd !== '') fwrite($fp, $cmd . "\r\n");
    $resp = smtp_read($fp);
    $code = substr($resp, 0, 3);
    if (!in_array($code, $okCodes, true)) {
        throw new SmtpError('SMTP error (' . $code . '): ' . trim($resp));
    }
    return $resp;
}

/**
 * Send a plain-text (UTF-8) mail.
 * Throws SmtpError on failure; returns true on success.
 */
function smtp_send(string $to, string $subject, string $body, ?string $toName = null): bool {
    $host   = (string) cfg('smtp_host', '');
    $port   = (int)    cfg('smtp_port', 587);
    $secure = (string) cfg('smtp_secure', 'tls');   // none | tls | ssl
    $user   = (string) cfg('smtp_user', '');
    $pass   = (string) cfg('smtp_pass', '');
    $from   = (string) cfg('smtp_from', '');
    $fromNm = (string) cfg('smtp_from_name', cfg('app_name', 'Bunny Learning'));

    if ($host === '' || $from === '') {
        throw new SmtpError('Mail server is not configured (see install.php).');
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0; $errstr = '';
    $fp = @fsockopen($remote, $port, $errno, $errstr, 15);
    if (!$fp) throw new SmtpError("Cannot connect to {$remote}: {$errstr} ({$errno})");
    stream_set_timeout($fp, 15);

    try {
        smtp_expect($fp, '', ['220']);

        $ehlo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        smtp_expect($fp, $ehlo, ['250']);

        if ($secure === 'tls') {
            smtp_expect($fp, 'STARTTLS', ['220']);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new SmtpError('STARTTLS negotiation failed.');
            }
            smtp_expect($fp, $ehlo, ['250']);   // EHLO again after TLS
        }

        if ($user !== '') {
            smtp_expect($fp, 'AUTH LOGIN', ['334']);
            smtp_expect($fp, base64_encode($user), ['334']);
            smtp_expect($fp, base64_encode($pass), ['235']);
        }

        smtp_expect($fp, 'MAIL FROM:<' . $from . '>', ['250']);
        smtp_expect($fp, 'RCPT TO:<' . $to . '>', ['250', '251']);
        smtp_expect($fp, 'DATA', ['354']);

        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encFromName = '=?UTF-8?B?' . base64_encode($fromNm) . '?=';
        $headers = [
            'From: ' . $encFromName . ' <' . $from . '>',
            'To: ' . ($toName ? '=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>' : $to),
            'Subject: ' . $encSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'bunny.local') . '>',
        ];
        $payload = implode("\r\n", $headers) . "\r\n\r\n"
                 . chunk_split(base64_encode($body));
        /* dot-stuffing */
        $payload = preg_replace('/^\./m', '..', $payload);
        fwrite($fp, $payload . "\r\n.\r\n");
        /* server replies "250 OK" exactly once — read it once */
        smtp_expect($fp, '', ['250']);

        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
    return true;
}

/* ---------- convenience wrappers ---------- */

function send_verification_mail(string $to, string $token): bool {
    $url = rtrim((string) cfg('app_url'), '/') . '/verify_email.php?t=' . urlencode($token);
    $app = (string) cfg('app_name', 'Bunny Learning');
    return smtp_send($to, "Confirm your email for {$app}",
        "Hi there!\n\n"
        . "Please confirm this email address for your {$app} account "
        . "by opening this link within 24 hours:\n\n{$url}\n\n"
        . "If you did not request this, just ignore this mail.\n\n— {$app}");
}

function send_password_reset_mail(string $to, string $token): bool {
    $url = rtrim((string) cfg('app_url'), '/') . '/reset.php?t=' . urlencode($token);
    $app = (string) cfg('app_name', 'Bunny Learning');
    return smtp_send($to, "Reset your {$app} password",
        "Hi there!\n\n"
        . "Someone asked to reset the password for your {$app} account.\n"
        . "Open this link within 2 hours to choose a new password:\n\n{$url}\n\n"
        . "If that was not you, you can ignore this mail — your password stays unchanged.\n\n— {$app}");
}
