<?php
/* =====================================================================
   OORA Media enquiry service — PRIVATE settings (template)

   Copy this file to your Hostinger account as:
       /home/<your-user>/domains/ooramedia.com/oora-config.php
   i.e. in the folder ABOVE public_html (never inside public_html), then fill in the values.
   This file holds passwords. Do NOT commit the filled-in version to GitHub.
   ===================================================================== */
return [
  // MySQL database — create it in hPanel → Databases → MySQL Databases, then copy the names here.
  'db' => [
    'dsn'  => 'mysql:host=localhost;dbname=u000000000_oora;charset=utf8mb4',
    'user' => 'u000000000_oora',
    'pass' => 'PASTE-DATABASE-PASSWORD',
  ],

  // Hostinger mailbox used to SEND notifications (smtp.hostinger.com, port 465, SSL).
  'smtp' => [
    'host'   => 'smtp.hostinger.com',
    'port'   => 465,
    'secure' => 'ssl',
    'user'   => 'admin@ooramedia.com',
    'pass'   => 'PASTE-MAILBOX-PASSWORD',
  ],
  'mail_from'      => 'admin@ooramedia.com',     // must be the mailbox above
  'mail_from_name' => 'OORA Media Website',

  // Where notifications are delivered. Change this to update the destination (comma-separate for several).
  'notify_to' => 'admin@ooramedia.com',

  // Long random values. Generate them in Admin → Publishing → Enquiry emails ("Generate"), or any password generator (40+ characters).
  'admin_token' => 'PASTE-ADMIN-TOKEN',          // also pasted into the Admin Dashboard on each device
  'cron_key'    => 'PASTE-ANOTHER-RANDOM-VALUE',  // only needed if you run cron.php over the web
  'ip_salt'     => 'PASTE-ANY-RANDOM-TEXT',       // IPs are only stored as a one-way hash, for rate limiting

  // Other websites allowed to send enquiries (your own site is always allowed).
  'allowed_origins' => ['https://ooramedia.com', 'https://www.ooramedia.com'],

  // Optional: write errors to a private log file instead of the server error log.
  // 'log_file' => '/home/<your-user>/domains/ooramedia.com/oora-enquiries.log',
];
