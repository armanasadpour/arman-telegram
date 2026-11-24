<?php

return [
    // Release version; bumped by the release script. Also used for cache-busting static assets.
    'version' => '0.3.0',

    // Path of the lock file written by the web installer. While it exists, install.php refuses to run.
    'install_lock' => storage_path('app/installed.lock'),

    // Secret for the web-cron URL (/cron/{key}) on hosts without CLI cron. Set by the installer.
    'cron_key' => env('PANEL_CRON_KEY'),
];
