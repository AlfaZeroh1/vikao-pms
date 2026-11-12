<?php
/** Vikao PMS + Portal configuration. Copy to config.php. */
return [
    'app' => [
        'name'        => 'Vikao PMS',
        'company'     => 'Vikao Properties Ltd',
        'pms_url'     => 'http://localhost/vikao-pms',          // no trailing slash (used in emails/links)
        'portal_url'  => 'http://localhost/vikao-portal',
        'base_path'   => null,                                 // null = auto-detect
        'env'         => 'local',                              // local | production
        'timezone'    => 'Africa/Nairobi',
        'currency'    => 'KES',
        'key'         => 'change-me-32-chars-minimum-secret!!',// used to encrypt integration secrets
    ],
    // PMS + portal database
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'vikao_pms', 'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4'],
    // Vikao ecommerce database (managed by the Ecommerce module)
    'ecom_db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'vikao', 'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4',
                  'site_url' => 'http://localhost/vikao', 'uploads_dir' => __DIR__ . '/../../vikao/uploads/listings'],
    'uploads' => [
        'dir'       => __DIR__ . '/../uploads',   // PMS and portal must point at the SAME folder
        'max_bytes' => 8 * 1024 * 1024,
    ],
    'cron_token' => 'change-me-cron-token',   // admin/cron.php?token=… for schedulers
];
