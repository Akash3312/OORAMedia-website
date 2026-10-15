<?php
/* Retries notification emails that failed or never went out.
   Set up in Hostinger hPanel → Advanced → Cron Jobs, every 10 minutes:
       /usr/bin/php /home/<user>/domains/ooramedia.com/public_html/api/cron.php
   It can also be called over the web as  api/cron.php?key=<cron_key from oora-config.php>. */
declare(strict_types=1);
require __DIR__ . '/lib/oora.php';

$cli = PHP_SAPI === 'cli';
$cfg = oora_config();
if (!$cli) {
  header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
  $key = (string) ($_GET['key'] ?? '');
  if (!$cfg || strlen((string) ($cfg['cron_key'] ?? '')) < 24 || !hash_equals((string) $cfg['cron_key'], $key)) { http_response_code(403); echo "Forbidden\n"; exit; }
}
if (!$cfg) { fwrite(STDERR, "oora-config.php not found at " . oora_config_path() . "\n"); exit(1); }

try {
  $results = oora_run_retries();
  $sent = count(array_filter($results, fn($r) => !empty($r['ok'])));
  echo gmdate('c') . " retried " . count($results) . " notification(s), $sent sent\n";
} catch (Throwable $t) {
  oora_log('cron failed: ' . $t->getMessage());
  if ($cli) fwrite(STDERR, "cron failed: " . $t->getMessage() . "\n"); else { http_response_code(500); echo "cron failed\n"; }
  exit(1);
}
