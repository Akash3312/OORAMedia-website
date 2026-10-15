<?php
/* Minimal SMTP client + MIME builder for sending notifications through the Hostinger mailbox
   (smtp.hostinger.com, port 465 SSL). No external libraries needed. */
declare(strict_types=1);

final class OoraSmtpError extends Exception {
  public bool $retryable;
  public function __construct(string $msg, bool $retryable) { parent::__construct($msg); $this->retryable = $retryable; }
}

/* Build a complete RFC 5322 message: multipart/alternative (text + HTML), wrapped in multipart/mixed when there are attachments. */
function oora_mime(array $m): string {
  $enc = function (string $s): string {      // UTF-8 header words, folded so no line is too long
    if (!preg_match('/[^\x20-\x7E]/', $s) && strlen($s) < 70) return $s;
    $parts = [];
    foreach (mb_str_split($s, 18) as $chunk) $parts[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
    return implode("\r\n ", $parts);
  };
  $addr = fn(string $email, string $name = '') => $name !== '' ? $enc($name) . ' <' . $email . '>' : '<' . $email . '>';
  $b64 = fn(string $s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
  $alt = '=_alt_' . bin2hex(random_bytes(8));
  $headers = [
    'Date: ' . date('r'),
    'From: ' . $addr($m['from'], $m['from_name'] ?? ''),
    'To: ' . implode(', ', array_map(fn($t) => '<' . $t . '>', $m['to'])),
    'Subject: ' . $enc($m['subject']),
    'Message-ID: <' . $m['message_id'] . '>',
    'MIME-Version: 1.0',
    'X-Mailer: OORA Media Website',
  ];
  if (!empty($m['reply_to'])) $headers[] = 'Reply-To: <' . $m['reply_to'] . '>';
  $altBody = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($m['text']) . "\r\n"
           . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($m['html']) . "\r\n--$alt--";
  if (empty($m['attachments'])) {
    $headers[] = "Content-Type: multipart/alternative; boundary=\"$alt\"";
    return implode("\r\n", $headers) . "\r\n\r\n" . $altBody . "\r\n";
  }
  $mix = '=_mix_' . bin2hex(random_bytes(8));
  $headers[] = "Content-Type: multipart/mixed; boundary=\"$mix\"";
  $body = "--$mix\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n" . $altBody . "\r\n";
  foreach ($m['attachments'] as $a) {
    $name = preg_replace('/[^\w.-]/', '_', $a['filename']);
    $body .= "--$mix\r\nContent-Type: {$a['type']}; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"\r\n\r\n"
           . rtrim(chunk_split(preg_replace('/\s+/', '', $a['base64']), 76, "\r\n")) . "\r\n";
  }
  return implode("\r\n", $headers) . "\r\n\r\n" . $body . "--$mix--\r\n";
}

/* Deliver one message. Returns the server's acceptance line (usually includes a queue id). Throws OoraSmtpError. */
function oora_smtp_send(array $cfg, string $from, array $to, string $message): string {
  $host = (string) $cfg['host']; $port = (int) ($cfg['port'] ?? 465); $secure = $cfg['secure'] ?? 'ssl';
  $verify = $cfg['verify_peer'] ?? true;
  $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'SNI_enabled' => true, 'peer_name' => $host]]);
  $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, (int) ($cfg['timeout'] ?? 20), STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) throw new OoraSmtpError("Could not connect to the mail server $host:$port ($errstr)", true);
  stream_set_timeout($fp, (int) ($cfg['timeout'] ?? 20));
  $read = function () use ($fp): string {
    $resp = '';
    while (($line = fgets($fp, 2048)) !== false) { $resp .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
    if ($resp === '') { $meta = stream_get_meta_data($fp); throw new OoraSmtpError($meta['timed_out'] ? 'The mail server timed out' : 'The mail server closed the connection', true); }
    return $resp;
  };
  $write = function (string $data) use ($fp): void {
    for ($done = 0, $len = strlen($data); $done < $len; $done += $n) { $n = fwrite($fp, substr($data, $done, 65536)); if ($n === false || $n === 0) throw new OoraSmtpError('Lost connection to the mail server while sending', true); }
  };
  $cmd = function (?string $line, $expect, string $what) use ($read, $write): string {
    if ($line !== null) $write($line . "\r\n");
    $r = $read(); $code = (int) substr($r, 0, 3);
    if (!in_array($code, (array) $expect, true)) {
      $msg = trim(preg_replace('/\s+/', ' ', $r));
      // 4xx = temporary (try again later); 5xx = permanent (needs a settings fix), e.g. 535 wrong password
      throw new OoraSmtpError("Mail server rejected $what: $msg", $code >= 400 && $code < 500);
    }
    return $r;
  };
  try {
    $domain = explode('@', $from)[1] ?? 'localhost';
    $cmd(null, 220, 'the connection');
    $cmd("EHLO $domain", 250, 'EHLO');
    if ($secure === 'tls') {
      $cmd('STARTTLS', 220, 'STARTTLS');
      if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new OoraSmtpError('Could not start a secure connection with the mail server', true);
      $cmd("EHLO $domain", 250, 'EHLO');
    }
    if (!empty($cfg['user'])) {
      $cmd('AUTH LOGIN', 334, 'login');
      $cmd(base64_encode((string) $cfg['user']), 334, 'the username');
      $cmd(base64_encode((string) ($cfg['pass'] ?? '')), 235, 'the mailbox password (check smtp user/pass in oora-config.php)');
    }
    $cmd("MAIL FROM:<$from>", 250, 'the sender address');
    foreach ($to as $rcpt) $cmd("RCPT TO:<$rcpt>", [250, 251], "the recipient $rcpt");
    $cmd('DATA', 354, 'DATA');
    $write(preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message)));   // normalise line endings, dot-stuff
    $ok = $cmd('.', 250, 'the message');
    try { $cmd('QUIT', 221, 'QUIT'); } catch (OoraSmtpError $ignored) {}
    return mb_substr(trim($ok), 0, 250);
  } finally {
    fclose($fp);
  }
}
