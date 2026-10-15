<?php
/* OORA Media enquiry service — public entry point.
   Website forms POST to  api/index.php?route=enquiries
   The Admin Dashboard uses  api/index.php?route=admin/…  (requires the admin token). */
declare(strict_types=1);
require __DIR__ . '/lib/oora.php';
oora_handle();
