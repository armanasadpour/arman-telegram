<?php
declare(strict_types=1);
/**
 * 1.0.30: doctor names saved before the «without دکتر» rule keep the title in the stored name, so their
 * profile and the administrator's edit form failed validation until the name was retyped. The title is
 * shown automatically, so it is removed from the stored name once (the same pattern as doctor_bare_name()).
 */
function migrate_v32(PDO $p): void {
    mig_lock($p, 'v32');
    try {
        if (mig_applied($p,32)) return;
        $rows = $p->query("SELECT id,name FROM doctors WHERE name LIKE 'دکتر%' OR name LIKE 'دكتر%' OR name LIKE 'دکتور%' OR name LIKE 'dr%' OR name LIKE 'doctor%'")->fetchAll(PDO::FETCH_ASSOC);
        $update = $p->prepare('UPDATE doctors SET name=? WHERE id=?');
        foreach ($rows as $r) {
            $bare = trim((string) preg_replace('/^(?:(?:دکتر|دكتر|دکتور|doctor|dr\.?)[\s\x{200c}]+)+/iu', '', trim($r['name'])));
            if ($bare !== '' && $bare !== $r['name']) {
                $update->execute([$bare, $r['id']]);
            }
        }
        mig_mark($p,32,'1.0.30');
    } finally { mig_unlock($p,'v32'); }
}
