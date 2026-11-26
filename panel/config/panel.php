<?php

return [
    // Release version; bumped by the release script. Also used for cache-busting static assets.
    'version' => '1.2.13',

    // Testing only: send all messenger API calls to this base URL instead of the real servers.
    'bots_api_override' => env('BOTS_API_OVERRIDE'),
    'pay_api_override' => env('PAY_API_OVERRIDE'),

    // Path of the lock file written by the web installer. While it exists, install.php refuses to run.
    'install_lock' => storage_path('app/installed.lock'),

    // Secret for the web-cron URL (/cron/{key}) on hosts without CLI cron. Set by the installer.
    'cron_key' => env('PANEL_CRON_KEY'),
];
