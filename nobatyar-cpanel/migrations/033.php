<?php
declare(strict_types=1);
/**
 * 1.0.30: patients' saved doctors, and the doctor's reply under a review (shown after the administrator approves it).
 */
function migrate_v33(PDO $p): void {
    mig_lock($p, 'v33');
    try {
        if (mig_applied($p,33)) return;
        $p->exec(
            'CREATE TABLE IF NOT EXISTS favorite_doctors(user_id BIGINT UNSIGNED NOT NULL,doctor_id BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,' .
            'PRIMARY KEY(user_id,doctor_id),INDEX favorite_doctor(doctor_id),' .
            'CONSTRAINT favorite_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,' .
            'CONSTRAINT favorite_doctor_fk FOREIGN KEY(doctor_id) REFERENCES doctors(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        mig_column($p,'reviews','reply','VARCHAR(1000) NULL');
        mig_column($p,'reviews','reply_status','VARCHAR(12) NULL');
        mig_column($p,'reviews','reply_at','DATETIME NULL');
        mig_mark($p,33,'1.0.30');
    } finally { mig_unlock($p,'v33'); }
}
