<?php
return [
  'db' => [
    'dsn'  => 'mysql:host=localhost;dbname=YOUR_DB_NAME;charset=utf8mb4',
    'user' => 'YOUR_DB_USER',
    'pass' => 'YOUR_DB_PASSWORD',
  ],
  'smtp' => [
    'host'   => 'smtp.hostinger.com',
    'port'   => 465,
    'secure' => 'ssl',
    'user'   => 'admin@ooramedia.com',
    'pass'   => 'YOUR_MAILBOX_PASSWORD',
  ],
  'mail_from'      => 'admin@ooramedia.com',
  'mail_from_name' => 'OORA Media Website',
  'notify_to'      => 'admin@ooramedia.com',
  'admin_token'    => 'PASTE-ADMIN-TOKEN',
  'cron_key'       => 'YOUR_CRON_KEY',
  'ip_salt'        => 'YOUR_IP_SALT',
];
