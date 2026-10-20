<?php
/* OORA Media enquiry service — core logic (runs on Hostinger PHP hosting).
   Flow for every website enquiry: validate → SAVE to the database → respond to the visitor → EMAIL it.
   Failed emails stay in the database and are retried by api/cron.php. */
declare(strict_types=1);

require_once __DIR__ . '/smtp.php';

const OORA_TYPES = [
  'advertiser' => ['label' => 'Advertiser Enquiry', 'code' => 'ADV', 'statuses' => ['New', 'Contacted', 'Proposal Sent', 'Negotiating', 'Confirmed', 'Closed']],
  'partner'    => ['label' => 'Screen Partner Application', 'code' => 'SCR', 'statuses' => ['New', 'Contacted', 'Meeting Scheduled', 'Approved', 'Rejected']],
  'contact'    => ['label' => 'General Enquiry', 'code' => 'GEN', 'statuses' => ['New', 'Replied', 'Closed']],
];
const OORA_GOALS = ['Brand Awareness', 'Promote a Product', 'Promote a Service', 'Local Event', 'Special Offer', 'Other'];
const OORA_MAX_BODY = 2500000;       // bytes (room for one resized photo)
const OORA_MAX_PHOTO = 1600000;      // characters of base64 data URL
const OORA_RATE_MAX = 8;             // submissions…
const OORA_RATE_MINUTES = 10;        // …per this many minutes, per connection
const OORA_MIN_FILL_MS = 1500;       // faster than this is almost certainly a script
const OORA_RETRY_MAX = 6;            // email attempts before giving up (admin can still "Send now")
const OORA_RETRY_MIN_AGE_MS = 120000;
const OORA_STALE_SENDING_MS = 600000;
const OORA_SCREEN_ID_RE = '/^OORA-ADL-[A-Z][0-9]{2}$/';
const OORA_MAX_SCREEN_IDS = 30;      // per enquiry

/* Screens currently published on the website (data/site-data.json — the same file the map uses),
   keyed by Screen ID. Returns null if the file can't be read. */
function oora_listed_screens(): ?array {
  static $cache = false;
  if ($cache !== false) return $cache;
  $path = oora_config()['site_data'] ?? dirname(__DIR__, 2) . '/data/site-data.json';
  $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
  if (!is_array($json) || !is_array($json['screens'] ?? null)) { oora_log('could not read screen list at ' . $path); return $cache = null; }
  $out = [];
  foreach ($json['screens'] as $s) { $sid = strtoupper(trim((string) ($s['screenId'] ?? ''))); if ($sid !== '') $out[$sid] = $s; }
  return $cache = $out;
}

final class OoraInputError extends Exception {}

/* ---------- configuration (private file outside the public website folder) ---------- */
function oora_config_path(): string {
  $env = getenv('OORA_CONFIG');
  return $env ? $env : dirname(__DIR__, 3) . '/oora-config.php';   // …/domains/ooramedia.com/oora-config.php, next to public_html
}
function oora_config(): ?array {
  static $cfg = false;
  if ($cfg === false) { $p = oora_config_path(); $cfg = is_file($p) ? require $p : null; if (!is_array($cfg)) $cfg = null; }
  return $cfg;
}
function oora_log(string $msg): void {
  $line = '[oora] ' . gmdate('c') . ' ' . $msg;
  $cfg = oora_config();
  if ($cfg && !empty($cfg['log_file'])) @error_log($line . PHP_EOL, 3, $cfg['log_file']); else error_log($line);
}

/* ---------- time & ids ---------- */
function oora_now_ms(): int { return (int) round(microtime(true) * 1000); }
function oora_iso(int $ms): string { return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000); }
function oora_uuid(): string { $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }
function oora_reference(string $type, int $ms): string {
  $d = (new DateTime('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('Australia/Adelaide'))->format('ymd');
  $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $code = '';
  for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
  return 'OM-' . OORA_TYPES[$type]['code'] . '-' . $d . '-' . $code;
}
function oora_adelaide_time(string $iso): string {
  return (new DateTime($iso))->setTimezone(new DateTimeZone('Australia/Adelaide'))->format('D j M Y, g:i a') . ' (Adelaide time)';
}

/* ---------- database ---------- */
function oora_db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = oora_config()['db'];
  $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
  oora_migrate($pdo);
  return $pdo;
}
function oora_migrate(PDO $pdo): void {
  if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS enquiries (
      id VARCHAR(40) NOT NULL PRIMARY KEY, reference VARCHAR(32) NOT NULL UNIQUE, type VARCHAR(16) NOT NULL, idempotency_key VARCHAR(80) NOT NULL UNIQUE,
      data MEDIUMTEXT NOT NULL, name VARCHAR(200), business VARCHAR(200), email VARCHAR(254), phone VARCHAR(40),
      status VARCHAR(32) NOT NULL DEFAULT 'New', notify_status VARCHAR(16) NOT NULL DEFAULT 'pending', notify_attempts INT NOT NULL DEFAULT 0,
      notify_error TEXT, notify_message_id VARCHAR(255), notify_claimed_at VARCHAR(30), notified_at VARCHAR(30), ip_hash CHAR(64),
      created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL,
      INDEX idx_created (created_at), INDEX idx_notify (notify_status, created_at), INDEX idx_ip (ip_hash, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } else {   // SQLite (local testing)
    $pdo->exec("CREATE TABLE IF NOT EXISTS enquiries (
      id TEXT PRIMARY KEY, reference TEXT NOT NULL UNIQUE, type TEXT NOT NULL, idempotency_key TEXT NOT NULL UNIQUE, data TEXT NOT NULL,
      name TEXT, business TEXT, email TEXT, phone TEXT, status TEXT NOT NULL DEFAULT 'New', notify_status TEXT NOT NULL DEFAULT 'pending',
      notify_attempts INTEGER NOT NULL DEFAULT 0, notify_error TEXT, notify_message_id TEXT, notify_claimed_at TEXT, notified_at TEXT, ip_hash TEXT,
      created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_created ON enquiries (created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notify ON enquiries (notify_status, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ip ON enquiries (ip_hash, created_at)');
  }
}
function oora_row(?array $r): ?array { if (!$r) return null; $r['data'] = json_decode($r['data'], true); $r['notify_attempts'] = (int) $r['notify_attempts']; return $r; }
function oora_get(string $id): ?array { $s = oora_db()->prepare('SELECT * FROM enquiries WHERE id = ?'); $s->execute([$id]); return oora_row($s->fetch() ?: null); }
function oora_get_by_key(string $key): ?array { $s = oora_db()->prepare('SELECT * FROM enquiries WHERE idempotency_key = ?'); $s->execute([$key]); return oora_row($s->fetch() ?: null); }
function oora_insert(array $r): array {
  try {
    oora_db()->prepare('INSERT INTO enquiries (id, reference, type, idempotency_key, data, name, business, email, phone, status, ip_hash, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$r['id'], $r['reference'], $r['type'], $r['idempotency_key'], json_encode($r['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['name'], $r['business'], $r['email'], $r['phone'], 'New', $r['ip_hash'], $r['created_at'], $r['created_at']]);
    return ['inserted' => true];
  } catch (PDOException $e) {
    if ((string) $e->getCode() === '23000' && ($existing = oora_get_by_key($r['idempotency_key']))) return ['inserted' => false, 'existing' => $existing];
    throw $e;
  }
}
function oora_count_recent_by_ip(string $hash, string $since): int { $s = oora_db()->prepare('SELECT COUNT(*) FROM enquiries WHERE ip_hash = ? AND created_at >= ?'); $s->execute([$hash, $since]); return (int) $s->fetchColumn(); }
/* Atomically take an enquiry for emailing — only one caller can win, so it is never emailed twice at once. */
function oora_claim(string $id, string $now, string $staleBefore): bool {
  $s = oora_db()->prepare("UPDATE enquiries SET notify_status = 'sending', notify_attempts = notify_attempts + 1, notify_claimed_at = ?, updated_at = ?
    WHERE id = ? AND (notify_status IN ('pending', 'failed') OR (notify_status = 'sending' AND notify_claimed_at < ?))");
  $s->execute([$now, $now, $id, $staleBefore]);
  return $s->rowCount() === 1;
}
function oora_finish(string $id, string $status, ?string $messageId, ?string $error, string $at): void {
  oora_db()->prepare('UPDATE enquiries SET notify_status = ?, notify_message_id = ?, notify_error = ?, notified_at = CASE WHEN ? = \'sent\' THEN ? ELSE notified_at END, updated_at = ? WHERE id = ?')
    ->execute([$status, $messageId, $error, $status, $at, $at, $id]);
}

/* ---------- validation ---------- */
function oora_str($v, string $label, int $max = 200, bool $required = false): string {
  $s = is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
  $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
  if ($required && $s === '') throw new OoraInputError("$label is required.");
  if (mb_strlen($s) > $max) throw new OoraInputError("$label is too long (max $max characters).");
  return $s;
}
function oora_email($v, bool $required = true): string { $s = oora_str($v, 'Email', 254, $required); if ($s !== '' && !preg_match('/^[^\s@<>()\[\],;:"]+@[^\s@<>()\[\],;:"]+\.[a-z]{2,}$/i', $s)) throw new OoraInputError('Please enter a valid email address.'); return $s; }
function oora_phone($v, bool $required = true): string { $s = oora_str($v, 'Phone number', 24, $required); if ($s !== '' && !preg_match('/^[+()\d\s.-]{6,24}$/', $s)) throw new OoraInputError('Please enter a valid phone number.'); return $s; }
function oora_list($v, string $label): array {
  if ($v === null) return [];
  if (!is_array($v) || count($v) > 40) throw new OoraInputError("$label is not valid.");
  return array_values(array_filter(array_map(fn($x) => oora_str($x, $label, 80), $v), fn($x) => $x !== ''));
}
function oora_validate(string $type, $d): array {
  if (!isset(OORA_TYPES[$type])) throw new OoraInputError('Unknown enquiry type.');
  if (!is_array($d)) throw new OoraInputError('Missing enquiry details.');
  if ($type === 'advertiser') {
    $goal = oora_str($d['goal'] ?? '', 'Campaign goal', 200, true);
    if (!in_array($goal, OORA_GOALS, true)) throw new OoraInputError('Please choose a campaign goal.');
    $screens = [];
    foreach (array_slice(is_array($d['screens'] ?? null) ? $d['screens'] : [], 0, 40) as $s) {
      if (!is_array($s)) continue;
      $id = oora_str($s['id'] ?? '', 'Screen', 40);
      if ($id !== '') $screens[] = ['id' => $id, 'screenId' => strtoupper(oora_str($s['screenId'] ?? '', 'Screen ID', 20)), 'venue' => oora_str($s['venue'] ?? '', 'Screen', 120), 'suburb' => oora_str($s['suburb'] ?? '', 'Screen', 80)];
    }
    // Optional requested Screen IDs (any number, blanks ignored). Accepts the list `screenIds`, or a single `screenId` from older pages.
    // Each must be a screen currently listed on the website, and none may repeat.
    $rawIds = $d['screenIds'] ?? (isset($d['screenId']) ? [$d['screenId']] : []);
    if (!is_array($rawIds)) $rawIds = [$rawIds];
    if (count($rawIds) > OORA_MAX_SCREEN_IDS) throw new OoraInputError('Too many Screen IDs — please list up to ' . OORA_MAX_SCREEN_IDS . '.');
    $screenIds = []; $requested = []; $listed = null; $listLoaded = false;
    foreach ($rawIds as $raw) {
      $sid = strtoupper(oora_str($raw, 'Screen ID', 20));
      if ($sid === '') continue;
      if (!preg_match(OORA_SCREEN_ID_RE, $sid)) throw new OoraInputError("“{$sid}” isn't a valid Screen ID. Screen IDs look like OORA-ADL-A01. Please check the ID, or leave it blank.");
      if (in_array($sid, $screenIds, true)) throw new OoraInputError("$sid is listed more than once. Please remove the duplicate.");
      if (!$listLoaded) { $listed = oora_listed_screens(); $listLoaded = true; }
      $match = null;
      if ($listed !== null) {   // if the screen list can't be read, accept well-formed IDs rather than lose the enquiry
        $m = $listed[$sid] ?? null;
        if (!$m || ($m['status'] ?? '') === 'Inactive') throw new OoraInputError("We couldn't find a screen with ID $sid. Check the ID on the Screen Map, or leave it blank and we'll recommend suitable screens.");
        $match = ['id' => (string) ($m['id'] ?? ''), 'name' => (string) ($m['name'] ?? ''), 'venue' => (string) ($m['venue'] ?? ''), 'suburb' => (string) ($m['suburb'] ?? '')];
      }
      $screenIds[] = $sid; $requested[] = $match;
    }
    return ['business' => oora_str($d['business'] ?? '', 'Business name', 200, true), 'contact' => oora_str($d['contact'] ?? '', 'Contact name', 200, true),
      'email' => oora_email($d['email'] ?? ''), 'phone' => oora_phone($d['phone'] ?? ''), 'goal' => $goal,
      'suburbs' => oora_list($d['suburbs'] ?? null, 'Preferred locations'), 'types' => oora_list($d['types'] ?? null, 'Preferred venue types'), 'screens' => $screens,
      'duration' => oora_str($d['duration'] ?? '', 'Campaign duration', 40),
      'screenIds' => $screenIds, 'requestedScreens' => $requested,
      // first ID kept in the original single fields so older enquiries and readers stay compatible
      'screenId' => $screenIds[0] ?? '', 'requestedScreen' => $requested[0] ?? null,
      'info' => oora_str($d['info'] ?? '', 'Additional information', 4000)];
  }
  if ($type === 'partner') {
    $photo = '';
    if (!empty($d['photo'])) {
      $photo = (string) $d['photo'];
      if (!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $photo)) throw new OoraInputError('The screen photo could not be read. Please try a JPG or PNG.');
      if (strlen($photo) > OORA_MAX_PHOTO) throw new OoraInputError('The screen photo is too large. Please choose a smaller image.');
    }
    return ['business' => oora_str($d['business'] ?? '', 'Business name', 200, true), 'contact' => oora_str($d['contact'] ?? '', 'Contact name', 200, true),
      'phone' => oora_phone($d['phone'] ?? ''), 'email' => oora_email($d['email'] ?? ''),
      'address' => oora_str($d['address'] ?? '', 'Business address', 300, true), 'suburb' => oora_str($d['suburb'] ?? '', 'Suburb', 80, true),
      'type' => oora_str($d['type'] ?? '', 'Business type', 80, true), 'screens' => oora_str($d['screens'] ?? '', 'Number of screens', 10, true),
      'size' => oora_str($d['size'] ?? '', 'Screen size', 40, true), 'traffic' => oora_str($d['traffic'] ?? '', 'Daily customers', 40, true),
      'hours' => oora_str($d['hours'] ?? '', 'Opening hours', 200, true), 'comments' => oora_str($d['comments'] ?? '', 'Comments', 4000), 'photo' => $photo];
  }
  return ['name' => oora_str($d['name'] ?? '', 'Name', 200, true), 'email' => oora_email($d['email'] ?? ''), 'phone' => oora_phone($d['phone'] ?? '', false),
    'company' => oora_str($d['company'] ?? '', 'Company', 200), 'message' => oora_str($d['message'] ?? '', 'Message', 5000, true)];
}

/* ---------- notification email ---------- */
/* "Screen ID" email line — handles the list (`screenIds`) and older single-ID enquiries (`screenId`). */
function oora_screen_ids_field(array $d): array {
  $ids = $d['screenIds'] ?? (!empty($d['screenId']) ? [$d['screenId']] : []);
  $reqs = $d['requestedScreens'] ?? (!empty($d['screenId']) ? [$d['requestedScreen'] ?? null] : []);
  if (!$ids) return ['Screen ID', 'No specific screen selected'];
  $lines = [];
  foreach (array_values($ids) as $i => $sid) { $r = $reqs[$i] ?? null; $lines[] = $sid . ($r ? ' — ' . $r['venue'] . ', ' . $r['suburb'] : ''); }
  return count($lines) === 1 ? ['Screen ID', $lines[0]] : ['Screen IDs (' . count($lines) . ')', implode("\n", $lines), 'block'];
}
function oora_email_fields(array $e): array {
  $d = $e['data']; $list = fn($a) => $a ? implode(', ', $a) : '—';
  if ($e['type'] === 'advertiser') return [
    ['Business', $d['business']], ['Contact name', $d['contact']], ['Email', $d['email'], 'mailto'], ['Phone', $d['phone'], 'tel'],
    ['Campaign goal', $d['goal']], ['Campaign duration', $d['duration'] ?: '—'],
    oora_screen_ids_field($d),
    ['Preferred locations', $list($d['suburbs'] ?? [])], ['Preferred venue types', $list($d['types'] ?? [])],
    ['Screens selected', !empty($d['screens']) ? implode(', ', array_map(fn($s) => $s['venue'] . ($s['suburb'] ? ' (' . $s['suburb'] . ')' : '') . (!empty($s['screenId']) ? ' [' . $s['screenId'] . ']' : ''), $d['screens'])) : '—'],
    ['Additional information', $d['info'] ?: '—', 'block']];
  if ($e['type'] === 'partner') return [
    ['Business', $d['business']], ['Contact name', $d['contact']], ['Email', $d['email'], 'mailto'], ['Phone', $d['phone'], 'tel'],
    ['Business type', $d['type']], ['Address', $d['address'] . ', ' . $d['suburb']], ['Number of screens', $d['screens']], ['Screen size', $d['size']],
    ['Daily customers', $d['traffic']], ['Opening hours', $d['hours']], ['Screen photo', $d['photo'] ? 'Attached to this email' : 'Not provided'], ['Comments', $d['comments'] ?: '—', 'block']];
  return [['Name', $d['name']], ['Company', $d['company'] ?: '—'], ['Email', $d['email'], 'mailto'], ['Phone', $d['phone'] ?: '—', $d['phone'] ? 'tel' : ''], ['Message', $d['message'], 'block']];
}
function oora_subject(array $e): string {
  $who = $e['data']['business'] ?? $e['data']['company'] ?? '';
  if ($who === '') $who = $e['data']['name'] ?? '';
  return mb_substr('OORA Media — New ' . OORA_TYPES[$e['type']]['label'] . ($who !== '' ? ' · ' . $who : ''), 0, 180);
}
function oora_render_email(array $e): array {
  $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
  $t = OORA_TYPES[$e['type']]; $fields = oora_email_fields($e); $when = oora_adelaide_time($e['created_at']);
  $val = function ($v, $kind = '') use ($h) {
    if ($kind === 'mailto') return '<a href="mailto:' . $h($v) . '" style="color:#C98A06">' . $h($v) . '</a>';
    if ($kind === 'tel') return '<a href="tel:' . $h(preg_replace('/[^+\d]/', '', (string) $v)) . '" style="color:#C98A06">' . $h($v) . '</a>';
    return nl2br($h($v));
  };
  $rows = '';
  foreach ($fields as $f) {
    [$k, $v] = $f; $kind = $f[2] ?? '';
    $rows .= $kind === 'block'
      ? '<tr><td colspan="2" style="padding:14px 0 4px;color:#5E6A80;font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700">' . $h($k) . '</td></tr><tr><td colspan="2" style="padding:0 0 12px;font-size:15px;color:#0A1628;line-height:1.55;border-bottom:1px solid #E2E7EF">' . $val($v) . '</td></tr>'
      : '<tr><td style="padding:9px 16px 9px 0;color:#5E6A80;font-size:14px;width:170px;vertical-align:top;border-bottom:1px solid #E2E7EF">' . $h($k) . '</td><td style="padding:9px 0;font-size:15px;color:#0A1628;font-weight:600;vertical-align:top;border-bottom:1px solid #E2E7EF">' . $val($v, $kind) . '</td></tr>';
  }
  $reply = $e['data']['email'] ?? '';
  $html = '<!doctype html><html><body style="margin:0;background:#F4F6FA;font-family:Arial,Helvetica,sans-serif">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F6FA;padding:24px 12px"><tr><td align="center">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #E2E7EF">'
    . '<tr><td style="background:#0A1628;padding:22px 28px"><span style="font-size:20px;font-weight:700;color:#ffffff">oora</span><span style="font-size:20px;font-weight:700;color:#F5B82E">media</span>'
    . '<div style="color:#FFD36B;font-size:12px;letter-spacing:.14em;text-transform:uppercase;font-weight:700;margin-top:6px">New ' . $h($t['label']) . '</div></td></tr>'
    . '<tr><td style="padding:24px 28px 8px"><p style="margin:0 0 4px;font-size:13px;color:#5E6A80">Reference <b style="color:#0A1628">' . $h($e['reference']) . '</b> · Received ' . $h($when) . '</p>'
    . ($reply !== '' ? '<p style="margin:0 0 18px;font-size:14px;color:#27324A">Reply to this email to respond directly to <b>' . $h($reply) . '</b>.</p>' : '')
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>'
    . '<tr><td style="padding:18px 28px 24px;font-size:12px;color:#8A94A6">Sent automatically by the OORA Media website. This enquiry is also saved in your Admin Dashboard.</td></tr>'
    . '</table></td></tr></table></body></html>';
  $text = "New {$t['label']} — OORA Media\nReference: {$e['reference']}\nReceived: $when\n\n";
  foreach ($fields as $f) $text .= $f[0] . ': ' . $f[1] . "\n";
  if ($reply !== '') $text .= "\nReply to this email to respond to $reply.\n";
  return ['subject' => oora_subject($e), 'html' => $html, 'text' => $text];
}

/* Sends one notification. Returns ['ok'=>true,'id'=>…] or ['ok'=>false,'error'=>…,'retryable'=>bool]. */
function oora_send_notification(array $e): array {
  $cfg = oora_config();
  if (empty($cfg['smtp']['host']) || empty($cfg['smtp']['user']) || empty($cfg['notify_to'])) return ['ok' => false, 'error' => 'Email settings are incomplete in oora-config.php', 'retryable' => true];
  $m = oora_render_email($e);
  $attachments = [];
  if (!empty($e['data']['photo']) && preg_match('#^data:image/(\w+);base64,(.+)$#', $e['data']['photo'], $p))
    $attachments[] = ['filename' => 'screen-photo-' . $e['reference'] . '.' . ($p[1] === 'jpeg' ? 'jpg' : $p[1]), 'type' => 'image/' . $p[1], 'base64' => $p[2]];
  $to = array_values(array_filter(array_map('trim', explode(',', (string) $cfg['notify_to']))));
  $from = $cfg['mail_from'] ?? $cfg['smtp']['user'];
  try {
    $mime = oora_mime(['from' => $from, 'from_name' => $cfg['mail_from_name'] ?? 'OORA Media Website', 'to' => $to, 'reply_to' => $e['data']['email'] ?? '',
      'subject' => $m['subject'], 'text' => $m['text'], 'html' => $m['html'], 'attachments' => $attachments, 'message_id' => 'enquiry-' . $e['id'] . '@' . (explode('@', $from)[1] ?? 'ooramedia.com')]);
    $id = oora_smtp_send($cfg['smtp'], $from, $to, $mime);
    return ['ok' => true, 'id' => $id];
  } catch (OoraSmtpError $err) {
    return ['ok' => false, 'error' => mb_substr($err->getMessage(), 0, 400), 'retryable' => $err->retryable];
  }
}
/* claim → send → record */
function oora_notify(string $id): array {
  $now = oora_now_ms();
  if (!oora_claim($id, oora_iso($now), oora_iso($now - OORA_STALE_SENDING_MS))) return ['skipped' => true];
  $e = oora_get($id);
  $res = oora_send_notification($e);
  $at = oora_iso(oora_now_ms());
  if ($res['ok']) oora_finish($id, 'sent', $res['id'], null, $at);
  else {
    $final = !$res['retryable'] || $e['notify_attempts'] >= OORA_RETRY_MAX;
    oora_finish($id, $final ? 'failed_final' : 'failed', null, $res['error'], $at);
    oora_log("email for enquiry {$e['reference']} failed (attempt {$e['notify_attempts']}): {$res['error']}");   // no visitor details in logs
  }
  return $res;
}
function oora_run_retries(): array {
  $now = oora_now_ms();
  $s = oora_db()->prepare("SELECT id FROM enquiries WHERE created_at <= ? AND notify_attempts < ? AND (notify_status IN ('pending', 'failed') OR (notify_status = 'sending' AND notify_claimed_at < ?)) ORDER BY created_at LIMIT 25");
  $s->execute([oora_iso($now - OORA_RETRY_MIN_AGE_MS), OORA_RETRY_MAX, oora_iso($now - OORA_STALE_SENDING_MS)]);
  $out = [];
  foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $out[] = ['id' => $id] + oora_notify($id);
  return $out;
}

/* ---------- HTTP ---------- */
function oora_json(array $body, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
/* Send the response to the visitor now, keep running to send the email afterwards. */
function oora_finish_response(): void {
  ignore_user_abort(true);
  if (function_exists('litespeed_finish_request')) litespeed_finish_request();
  elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  else { @ob_end_flush(); @flush(); }
}
function oora_client_ip(): string {
  // Hostinger serves the site through its CDN, so the visitor's address may arrive in X-Forwarded-For
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($xff !== '') { $first = trim(explode(',', $xff)[0]); if (filter_var($first, FILTER_VALIDATE_IP)) return $first; }
  return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
function oora_is_admin(): bool {
  $want = (string) (oora_config()['admin_token'] ?? '');
  $got = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
  return strlen($want) >= 24 && $got !== '' && hash_equals($want, $got);
}
function oora_origin_allowed(): bool {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin === '') return true;                                           // same-origin GETs, server-to-server
  $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
  if ($host !== '' && $host === strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0])) return true;   // same website
  return in_array($origin, oora_config()['allowed_origins'] ?? [], true);
}
function oora_public_row(array $r): array {
  return ['id' => $r['id'], 'reference' => $r['reference'], 'type' => $r['type'], 'status' => $r['status'], 'data' => $r['data'], 'created_at' => $r['created_at'],
    'notify' => ['status' => $r['notify_status'], 'attempts' => $r['notify_attempts'], 'error' => $r['notify_error'], 'sent_at' => $r['notified_at']]];
}

function oora_handle(): void {
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store');
  header('Referrer-Policy: same-origin');
  $route = (string) ($_GET['route'] ?? '');
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $cfg = oora_config();

  if ($route === 'health' && !$cfg) { oora_json(['ok' => false, 'configured' => false, 'php' => PHP_VERSION]); return; }
  if (!$cfg) { oora_json(['error' => 'The enquiry service is not set up yet. Please email admin@ooramedia.com directly.', 'code' => 'not_configured'], 503); return; }
  if (!oora_origin_allowed()) { oora_json(['error' => 'This website is not allowed to use the enquiry service.'], 403); return; }
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin !== '' && in_array($origin, $cfg['allowed_origins'] ?? [], true)) {
    header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
  }
  if ($method === 'OPTIONS') { http_response_code(204); return; }

  try {
    if ($route === 'health') {
      oora_db();
      $to = (string) ($cfg['notify_to'] ?? '');
      oora_json(['ok' => true, 'configured' => true, 'email' => !empty($cfg['smtp']['host']) && !empty($cfg['smtp']['pass']), 'notifyTo' => $to ? preg_replace('/^(.).*(@.*)$/', '$1***$2', $to) : null, 'php' => PHP_VERSION]);
      return;
    }

    /* Public: submit an enquiry */
    if ($route === 'enquiries' && $method === 'POST') {
      if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > OORA_MAX_BODY) { oora_json(['error' => 'Your submission is too large. Please use a smaller photo.'], 413); return; }
      $raw = (string) file_get_contents('php://input', false, null, 0, OORA_MAX_BODY + 1);
      if (strlen($raw) > OORA_MAX_BODY) { oora_json(['error' => 'Your submission is too large. Please use a smaller photo.'], 413); return; }
      $body = json_decode($raw, true);
      if (!is_array($body)) { oora_json(['error' => 'Invalid request.'], 400); return; }
      $key = is_string($body['idempotencyKey'] ?? null) ? $body['idempotencyKey'] : '';
      if (!preg_match('/^[A-Za-z0-9-]{16,80}$/', $key)) { oora_json(['error' => 'Invalid request.'], 400); return; }
      // Same submission sent again (double-click, retry, refresh): return the saved enquiry, no second email
      if ($prior = oora_get_by_key($key)) { oora_json(['ok' => true, 'id' => $prior['id'], 'reference' => $prior['reference'], 'duplicate' => true]); return; }
      $now = oora_now_ms(); $type = is_string($body['type'] ?? null) ? $body['type'] : '';
      // Spam trap: a hidden field people never see was filled in. Pretend success, store nothing.
      if (!empty($body['website'])) { oora_json(['ok' => true, 'reference' => oora_reference(isset(OORA_TYPES[$type]) ? $type : 'contact', $now)]); return; }
      $started = (int) ($body['startedAt'] ?? 0);
      if ($started > 0 && $now - $started < OORA_MIN_FILL_MS) { oora_json(['error' => 'That was very quick — please check your details and press submit again.'], 400); return; }
      $data = oora_validate($type, $body['data'] ?? null);
      $ipHash = hash('sha256', ($cfg['ip_salt'] ?? 'oora') . ':' . oora_client_ip());
      if (oora_count_recent_by_ip($ipHash, oora_iso($now - OORA_RATE_MINUTES * 60000)) >= OORA_RATE_MAX) { oora_json(['error' => 'Too many submissions from your connection. Please wait a few minutes and try again.'], 429); return; }
      $row = ['id' => oora_uuid(), 'reference' => oora_reference($type, $now), 'type' => $type, 'idempotency_key' => $key, 'data' => $data,
        'name' => $data['contact'] ?? $data['name'] ?? '', 'business' => $data['business'] ?? $data['company'] ?? '', 'email' => $data['email'] ?? '', 'phone' => $data['phone'] ?? '',
        'ip_hash' => $ipHash, 'created_at' => oora_iso($now)];
      $ins = oora_insert($row);                                          // 1) save first
      if (!$ins['inserted']) { oora_json(['ok' => true, 'id' => $ins['existing']['id'], 'reference' => $ins['existing']['reference'], 'duplicate' => true]); return; }
      oora_json(['ok' => true, 'id' => $row['id'], 'reference' => $row['reference']], 201);
      oora_finish_response();                                            // 2) visitor has their answer…
      try { oora_notify($row['id']); } catch (Throwable $t) { oora_log("email for enquiry {$row['reference']} crashed: " . $t->getMessage()); }   // 3) …then email (cron retries failures)
      return;
    }

    /* Admin (needs the admin token) */
    if (strncmp($route, 'admin/', 6) === 0) {
      if (!oora_is_admin()) { oora_json(['error' => 'Not authorised. Check the admin access token.'], 401); return; }
      if ($route === 'admin/enquiries' && $method === 'GET') {
        $type = $_GET['type'] ?? ''; $limit = max(1, min(500, (int) ($_GET['limit'] ?? 300)));
        $s = isset(OORA_TYPES[$type]) ? oora_db()->prepare('SELECT * FROM enquiries WHERE type = ? ORDER BY created_at DESC LIMIT ' . $limit) : oora_db()->prepare('SELECT * FROM enquiries ORDER BY created_at DESC LIMIT ' . $limit);
        $s->execute(isset(OORA_TYPES[$type]) ? [$type] : []);
        oora_json(['enquiries' => array_map(fn($r) => oora_public_row(oora_row($r)), $s->fetchAll())]);
        return;
      }
      $id = (string) ($_GET['id'] ?? '');
      if ($route === 'admin/status' && $method === 'POST') {
        $e = $id !== '' ? oora_get($id) : null;
        if (!$e) { oora_json(['error' => 'Enquiry not found.'], 404); return; }
        $b = json_decode((string) file_get_contents('php://input'), true) ?: [];
        if (!in_array($b['status'] ?? '', OORA_TYPES[$e['type']]['statuses'], true)) { oora_json(['error' => 'Invalid status.'], 400); return; }
        oora_db()->prepare('UPDATE enquiries SET status = ?, updated_at = ? WHERE id = ?')->execute([$b['status'], oora_iso(oora_now_ms()), $id]);
        oora_json(['ok' => true]);
        return;
      }
      if ($route === 'admin/notify' && $method === 'POST') {
        $e = $id !== '' ? oora_get($id) : null;
        if (!$e) { oora_json(['error' => 'Enquiry not found.'], 404); return; }
        if ($e['notify_status'] === 'sent') { oora_json(['ok' => true, 'alreadySent' => true]); return; }
        oora_db()->prepare("UPDATE enquiries SET notify_status = 'pending', notify_attempts = 0 WHERE id = ? AND notify_status IN ('failed', 'failed_final')")->execute([$id]);
        $res = oora_notify($id);
        oora_json(!empty($res['ok']) ? ['ok' => true] : ['ok' => false, 'error' => $res['error'] ?? 'Could not send right now — it may already be sending.'], !empty($res['ok']) ? 200 : 502);
        return;
      }
      if ($route === 'admin/test-email' && $method === 'POST') {
        $fake = ['id' => 'test-' . oora_uuid(), 'reference' => 'OM-TEST', 'type' => 'contact', 'created_at' => oora_iso(oora_now_ms()),
          'data' => ['name' => 'OORA Media test', 'email' => '', 'phone' => '', 'company' => '', 'message' => 'This is a test notification from your OORA Media website. If you can read this, enquiry emails are set up correctly.']];
        $res = oora_send_notification($fake);
        oora_json($res['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $res['error']], $res['ok'] ? 200 : 502);
        return;
      }
    }
    oora_json(['error' => 'Not found.'], 404);
  } catch (OoraInputError $e) {
    oora_json(['error' => $e->getMessage()], 400);
  } catch (Throwable $t) {
    oora_log("request $method $route failed: " . get_class($t) . ': ' . $t->getMessage());
    if (!headers_sent()) oora_json(['error' => 'Something went wrong on our side. Please try again in a moment, or email admin@ooramedia.com.'], 500);
  }
}
