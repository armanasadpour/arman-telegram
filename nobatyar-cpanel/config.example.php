<?php
// install.php creates config.php automatically; this file only documents the keys.
return [
    'host' => 'localhost',
    'database' => 'cpaneluser_nobatyar',
    'username' => 'cpaneluser_nobat',
    'password' => 'database-password',
    'admin_email' => 'admin@example.com',
    'environment' => 'production', // 'development' only on a test copy: allows SMS test mode
    'cron_token' => 'random-48-hex-characters',
];
