<?php
declare(strict_types=1);

/**
 * Bootstrap shared by every page, the API and CLI tasks.
 * Order matters: support helpers first, then domain modules that depend on them.
 */

date_default_timezone_set('Asia/Tehran');

// cron.php defines NOBATYAR_NO_SESSION; everything else (pages, API, CLI test helpers) gets a session.
if (!defined('NOBATYAR_NO_SESSION') && session_status() !== PHP_SESSION_ACTIVE) {
    // 1.0.0-rc.18: sessions live as long as a sign-in may (12 hours for administrators and users) instead of
    // ending when the browser or the installed app closes, and are kept in the application's own folder:
    // on shared hosting the common session folder is cleaned after ~24 minutes of inactivity (cPanel's
    // php.ini default), which signed administrators out in the middle of their work.
    $sessionDir = __DIR__ . '/private/sessions';
    if ((is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) && is_writable($sessionDir)) {
        // Carry a session from the host's default folder over once, so updating to this version (or moving the
        // folder) does not sign anyone out. Only the file named by the visitor's own cookie is copied.
        $sid = (string) ($_COOKIE[session_name()] ?? '');
        if ($sid !== '' && preg_match('/^[A-Za-z0-9,-]{20,128}$/', $sid) && !is_file($sessionDir . '/sess_' . $sid)) {
            $previous = (string) session_save_path();
            $previous = rtrim($previous !== '' ? substr($previous, (int) strrpos(';' . $previous, ';')) : sys_get_temp_dir(), '/');
            if ($previous !== '' && realpath($previous) !== realpath($sessionDir) && is_file($previous . '/sess_' . $sid) && is_readable($previous . '/sess_' . $sid)) {
                @copy($previous . '/sess_' . $sid, $sessionDir . '/sess_' . $sid);
                @chmod($sessionDir . '/sess_' . $sid, 0600);
            }
        }
        session_save_path($sessionDir);
        ini_set('session.gc_probability', '0'); // cleaned by the daily cron (session_files_cleanup)
    }
    ini_set('session.gc_maxlifetime', '43200');
    session_set_cookie_params([
        'lifetime' => 43200,
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

require_once __DIR__ . '/lib/version.php';
require_once __DIR__ . '/lib/support.php';
require_once __DIR__ . '/lib/security.php';
security_headers();
install_error_handlers();
maintenance_gate();
require_once __DIR__ . '/lib/migrator.php';
require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/view.php';

require_once __DIR__ . '/lib/domain.php';
require_once __DIR__ . '/lib/locale.php';
require_once __DIR__ . '/lib/geo.php';
require_once __DIR__ . '/lib/specialties.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/sms.php';
require_once __DIR__ . '/lib/admin_roles.php';

require_once __DIR__ . '/lib/scheduling.php';
require_once __DIR__ . '/lib/doctor_actions.php';

require_once __DIR__ . '/lib/reservations.php';
require_once __DIR__ . '/lib/payments.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/subscriptions.php';
require_once __DIR__ . '/lib/patient.php';

require_once __DIR__ . '/lib/reception.php';
require_once __DIR__ . '/lib/webpush.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/waitlist.php';
require_once __DIR__ . '/lib/queue.php';
require_once __DIR__ . '/lib/centers.php';
require_once __DIR__ . '/lib/center_billing.php';
require_once __DIR__ . '/lib/pro.php';
require_once __DIR__ . '/lib/account_admin.php';
require_once __DIR__ . '/lib/center_reports.php';
require_once __DIR__ . '/lib/tickets.php';
require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/home.php';
require_once __DIR__ . '/lib/shell.php';
require_once __DIR__ . '/lib/search.php';
require_once __DIR__ . '/lib/booking_ui.php';
require_once __DIR__ . '/lib/account.php';

require_once __DIR__ . '/lib/wallet.php';
require_once __DIR__ . '/lib/release_comms.php';
