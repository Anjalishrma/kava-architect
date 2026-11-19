<?php
/* Small mail sender: SMTP (TLS, SSL or plain) with login, or PHP mail() when no SMTP host is set.
   Returns [true, ''] or [false, 'reason']. */
function send_mail(array $to, string $subject, string $body, string $replyTo = ''): array {
    $to = array_values(array_filter(array_map('trim', $to), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (!$to) return [false, 'No valid recipient email is set.'];
    $from = setting('from_email'); $fromName = setting('from_name', 'KAVA Architect');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return [false, 'Set a valid From address in Email settings.'];
    $strip = fn($s) => str_replace(["\r", "\n"], ' ', (string)$s);
    $encSubject = '=?UTF-8?B?' . base64_encode($strip($subject)) . '?=';
    $encName = '=?UTF-8?B?' . base64_encode($strip($fromName)) . '?=';
    $host = setting('smtp_host');
    if ($host === '') {
        $headers = "From: $encName <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit"
            . ($replyTo ? "\r\nReply-To: " . $strip($replyTo) : '');
        $ok = @mail(implode(',', $to), $encSubject, $body, $headers);
        return $ok ? [true, ''] : [false, 'PHP mail() failed. Add SMTP details in Email settings.'];
    }
    $port = (int)setting('smtp_port', '587'); $secure = strtolower(setting('smtp_secure', 'tls'));
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, "Could not connect to $host:$port ($errstr)."];
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) { $out = ''; while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; } return $out; };
    $cmd = function (string $c, array $okCodes) use ($fp, $read) { if ($c !== '') fwrite($fp, $c . "\r\n"); $r = $read(); $code = (int)substr($r, 0, 3); if (!in_array($code, $okCodes, true)) throw new RuntimeException(trim($r) ?: 'No reply from mail server'); return $r; };
    try {
        $cmd('', [220]);
        $me = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
        $cmd("EHLO $me", [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('Could not start TLS.');
            $cmd("EHLO $me", [250]);
        }
        $user = setting('smtp_user');
        if ($user !== '') { $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($user), [334]); $cmd(base64_encode(setting('smtp_pass')), [235]); }
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($to as $t) $cmd("RCPT TO:<$t>", [250, 251]);
        $cmd('DATA', [354]);
        $headers = 'Date: ' . date('r') . "\r\nFrom: $encName <$from>\r\nTo: " . implode(', ', $to) . "\r\nSubject: $encSubject\r\n"
            . ($replyTo ? 'Reply-To: ' . $strip($replyTo) . "\r\n" : '')
            . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
        $cmd($headers . "\r\n" . str_replace("\n", "\r\n", $data) . "\r\n.", [250]);
        $cmd('QUIT', [221, 250]);
        fclose($fp);
        return [true, ''];
    } catch (Throwable $e) {
        @fclose($fp);
        return [false, 'Mail server said: ' . mb_substr($e->getMessage(), 0, 200)];
    }
}
