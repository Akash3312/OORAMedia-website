<?php
/* Settings for LOCAL TESTS ONLY: SQLite file instead of MySQL, fake SMTP server instead of Hostinger.
   Contains no real credentials. Selected with the OORA_CONFIG environment variable. */
return [
  'db' => ['dsn' => 'sqlite:' . (getenv('OORA_TEST_DB') ?: __DIR__ . '/oora-test.sqlite')],
  'smtp' => ['host' => 'localhost', 'port' => (int) (getenv('OORA_TEST_SMTP_PORT') ?: 2465), 'secure' => getenv('OORA_TEST_SMTP_SECURE') ?: 'ssl', 'verify_peer' => false,
             'user' => 'admin@ooramedia.com', 'pass' => getenv('OORA_TEST_SMTP_PASS') ?: 'test-password', 'timeout' => 5],
  'mail_from' => 'admin@ooramedia.com',
  'mail_from_name' => 'OORA Media Website',
  'notify_to' => 'admin@ooramedia.com',
  'admin_token' => 'test-admin-token-0123456789abcdef-local',
  'cron_key' => 'test-cron-key-0123456789abcdef-local',
  'ip_salt' => 'test',
  'allowed_origins' => ['https://ooramedia.com', 'https://www.ooramedia.com'],
];
