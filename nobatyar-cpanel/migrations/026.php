<?php
declare(strict_types=1);
function migrate_v26(PDO $p): void {
    mig_lock($p, 'v26');
    try {
        if (mig_applied($p,26)) return;
        mig_column($p,'sms_templates','delivery_mode',"VARCHAR(16) NOT NULL DEFAULT 'lookup'");
        mig_column($p,'sms_templates','body','TEXT NULL');
        mig_column($p,'sms_messages','delivery_mode',"VARCHAR(16) NOT NULL DEFAULT 'lookup'");
        mig_column($p,'sms_messages','body','TEXT NULL');
        mig_column($p,'payment_transactions','refund_penalty_toman','INT UNSIGNED NOT NULL DEFAULT 0');
        $p->exec("CREATE TABLE IF NOT EXISTS wallets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_type VARCHAR(16) NOT NULL, owner_id BIGINT UNSIGNED NOT NULL, balance BIGINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY owner(owner_type,owner_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $p->exec("CREATE TABLE IF NOT EXISTS wallet_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, wallet_id BIGINT UNSIGNED NOT NULL, amount BIGINT NOT NULL, balance_after BIGINT NOT NULL, operation_key VARCHAR(120) NOT NULL, description VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY operation_key(operation_key), KEY wallet_history(wallet_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $p->exec("CREATE TABLE IF NOT EXISTS wallet_banks (wallet_id BIGINT UNSIGNED PRIMARY KEY, details TEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $p->exec("CREATE TABLE IF NOT EXISTS wallet_payouts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, wallet_id BIGINT UNSIGNED NOT NULL, amount BIGINT NOT NULL, request_key VARCHAR(80) NOT NULL, bank_snapshot TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', reference VARCHAR(120) NULL, note VARCHAR(500) NULL, handled_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, handled_at DATETIME NULL, UNIQUE KEY request_key(request_key), KEY status_created(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $p->exec("CREATE TABLE IF NOT EXISTS wallet_booking_refunds (booking_id BIGINT UNSIGNED PRIMARY KEY, gross BIGINT NOT NULL, penalty BIGINT NOT NULL, refunded BIGINT NOT NULL, rule_hours INT NOT NULL, penalty_percent DECIMAL(5,2) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $p->exec("CREATE TABLE IF NOT EXISTS admin_push_devices (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NOT NULL, endpoint TEXT NOT NULL, endpoint_hash CHAR(64) NOT NULL, p256dh VARCHAR(100) NOT NULL, auth VARCHAR(40) NOT NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY endpoint_hash(endpoint_hash), KEY owner_admin(admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $p->exec("CREATE TABLE IF NOT EXISTS admin_push_queue (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id BIGINT UNSIGNED NOT NULL, event_key VARCHAR(120) NOT NULL, payload TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'queued', attempts INT NOT NULL DEFAULT 0, next_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY dedupe(device_id,event_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $p->exec("CREATE TABLE IF NOT EXISTS admin_notice_preferences (admin_id BIGINT UNSIGNED PRIMARY KEY, preferences TEXT NOT NULL)");
        mig_column($p,'notification_preferences','push_general','TINYINT NOT NULL DEFAULT 1');
        mig_column($p,'notifications','banner_dismissed','TINYINT NOT NULL DEFAULT 0');
        mig_setting_default($p,'wallet_refund_enabled','0');
        mig_setting_default($p,'wallet_refund_hours','24');
        mig_setting_default($p,'wallet_refund_penalty','0');
        mig_setting_default($p,'platform_summary_hour','21');
        require_once __DIR__.'/../lib/sms.php';
        foreach (sms_events() as $event=>$meta) {
            $enabled=in_array($event,['doctor_approved','doctor_rejected','doctor_suspended','manual_message'],true) ? 1 : 0;
            $p->prepare("INSERT IGNORE INTO sms_templates(event,template,enabled,delivery_mode,body) VALUES(?,'',?,'text',?)")->execute([$event,$enabled,$meta['suggested']]);
        }
        mig_mark($p,26,'1.0.0');
    } finally { mig_unlock($p,'v26'); }
}
