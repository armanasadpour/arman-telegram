<?php
/**
 * Phase-1 connection proof for IBSng A1.24.
 *
 * Every method name and argument used here was taken from the IBSng A1.24
 * source (core/*_handler.py) — nothing is guessed. Steps are split into:
 *   - environment checks (this PHP host)
 *   - network check (TCP connect)
 *   - read-only API calls
 *   - an optional, explicitly-enabled controlled write test that creates a
 *     LOCKED throw-away user and always deletes it again.
 */

declare(strict_types=1);

namespace IbsProbe;

final class Probe
{
    /** @var list<array<string,mixed>> */
    private array $results = [];
    private XmlRpcClient $client;
    /** @var array<string,mixed> */
    private array $cfg;

    /** @param array<string,mixed> $cfg */
    public function __construct(array $cfg)
    {
        $this->cfg = $cfg + [
            'port' => 1235,
            'timeout' => 15,
            'connect_timeout' => 5,
            'sample_username' => '',
            'allow_write_test' => false,
            'write_test_group' => '',
        ];
        $this->client = new XmlRpcClient(
            (string) $this->cfg['host'],
            (int) $this->cfg['port'],
            '/',
            (float) $this->cfg['timeout'],
            (float) $this->cfg['connect_timeout']
        );
    }

    /** @return list<array<string,mixed>> */
    public function results(): array
    {
        return $this->results;
    }

    public function runAll(bool $doWriteTest): void
    {
        $this->checkEnvironment();
        if (!$this->checkTcp()) {
            return; // nothing else can succeed
        }
        if (!$this->checkAuth()) {
            return;
        }
        $groups = $this->readGroups();
        if ($groups) {
            $this->readGroupInfo($this->cfg['write_test_group'] !== '' ? (string) $this->cfg['write_test_group'] : $groups[0]);
        }
        $this->readCharges();
        $this->readOnlines();
        if ($this->cfg['sample_username'] !== '') {
            $this->readUser((string) $this->cfg['sample_username']);
        }
        if ($doWriteTest) {
            if (!$this->cfg['allow_write_test']) {
                $this->add('write.disabled', 'تست نوشتن', 'skip', 'allow_write_test در config.php فعال نیست.');
            } elseif ($this->cfg['write_test_group'] === '') {
                $this->add('write.disabled', 'تست نوشتن', 'skip', 'write_test_group در config.php تعیین نشده است.');
            } else {
                $this->controlledWriteTest();
            }
        }
    }

    // ------------------------------------------------------------ environment

    public function checkEnvironment(): void
    {
        $v = PHP_VERSION;
        $this->add('env.php', 'نسخه PHP', version_compare($v, '8.1.0', '>=') ? 'pass' : 'fail', "PHP $v (حداقل لازم: 8.1)");
        $exts = [
            'dom' => true, 'json' => true, 'mbstring' => true, 'pdo_mysql' => true,
            'openssl' => true, 'curl' => false, 'zip' => false, 'intl' => false, 'gd' => false, 'bcmath' => false,
        ];
        foreach ($exts as $e => $required) {
            $ok = extension_loaded($e);
            $this->add("env.ext.$e", "افزونه $e", $ok ? 'pass' : ($required ? 'fail' : 'warn'), $ok ? 'فعال' : ($required ? 'ضروری و غیرفعال' : 'اختیاری و غیرفعال'));
        }
        if (!extension_loaded('curl')) {
            $this->add('env.transport', 'روش ارسال درخواست', ini_get('allow_url_fopen') ? 'warn' : 'fail',
                ini_get('allow_url_fopen') ? 'curl نیست؛ از allow_url_fopen استفاده می‌شود' : 'نه curl و نه allow_url_fopen');
        }
        $this->add('env.limits', 'محدودیت‌های هاست', 'info',
            'max_execution_time=' . ini_get('max_execution_time') . ' | memory_limit=' . ini_get('memory_limit')
            . ' | disable_functions=' . (ini_get('disable_functions') ?: '—'));
        $this->add('env.tz', 'منطقه زمانی PHP', 'info', date_default_timezone_get());
    }

    // ------------------------------------------------------------ network

    public function checkTcp(): bool
    {
        $t = microtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen((string) $this->cfg['host'], (int) $this->cfg['port'], $errno, $errstr, (float) $this->cfg['connect_timeout']);
        $ms = (int) round((microtime(true) - $t) * 1000);
        if ($fp === false) {
            $this->add('net.tcp', 'اتصال TCP به IBSng', 'fail',
                "اتصال برقرار نشد ($errno: $errstr) پس از {$ms}ms. علت‌های رایج: IBSng روی 127.0.0.1 گوش می‌دهد (IBS_SERVER_IP)، فایروال سرور IBSng، یا مسدود بودن پورت خروجی توسط هاست.");
            return false;
        }
        fclose($fp);
        $this->add('net.tcp', 'اتصال TCP به IBSng', 'pass', "اتصال برقرار شد ({$ms}ms)");
        return true;
    }

    // ------------------------------------------------------------ read-only

    public function checkAuth(): bool
    {
        // admin.getAdminInfo is always allowed for the admin's own username (admin_handler.py).
        $r = $this->rpc('read.auth', 'احراز هویت مدیر (admin.getAdminInfo)', 'admin.getAdminInfo',
            ['admin_username' => (string) $this->cfg['admin_username']]);
        if ($r === null) {
            return false;
        }
        $summary = [];
        foreach (['admin_id', 'username', 'name', 'deposit', 'creator_id', 'locked'] as $k) {
            if (array_key_exists($k, $r)) {
                $summary[$k] = $r[$k];
            }
        }
        $this->patchLast('pass', 'ورود موفق. فیلدهای دریافتی: ' . implode(', ', array_keys($r)), $summary);
        return true;
    }

    /** @return list<string> */
    public function readGroups(): array
    {
        $r = $this->rpc('read.groups', 'فهرست گروه‌ها (group.listGroups)', 'group.listGroups', []);
        if (!is_array($r)) {
            return [];
        }
        $this->patchLast('pass', count($r) . ' گروه قابل دسترس', ['groups' => $r]);
        return array_values(array_map('strval', $r));
    }

    public function readGroupInfo(string $name): void
    {
        $r = $this->rpc('read.group_info', "اطلاعات گروه «{$name}» (group.getGroupInfo)", 'group.getGroupInfo', ['group_name' => $name]);
        if (is_array($r)) {
            $this->patchLast('pass', 'کلیدهای دریافتی: ' . implode(', ', array_keys($r)), $r);
        }
    }

    public function readCharges(): void
    {
        $r = $this->rpc('read.charges', 'فهرست تعرفه‌ها (charge.listCharges)', 'charge.listCharges', []);
        if (!is_array($r)) {
            return;
        }
        $this->patchLast('pass', count($r) . ' تعرفه', ['charges' => $r]);
        foreach (array_slice($r, 0, 5) as $name) {
            $rules = $this->rpc('read.charge_rules.' . $name, "قوانین تعرفه «{$name}» (charge.listChargeRules)", 'charge.listChargeRules', ['charge_name' => (string) $name]);
            if (is_array($rules)) {
                // cpm = credit per minute, cpk = credit per kilobyte: tells us whether volume billing is configured.
                $this->patchLast('pass', count($rules) . ' قانون', ['rules' => $rules]);
            }
        }
    }

    public function readOnlines(): void
    {
        // Arguments per report_handler.getOnlineUsers; sort keys from report/online.py.
        $r = $this->rpc('read.onlines', 'کاربران آنلاین (report.getOnlineUsers)', 'report.getOnlineUsers', [
            'normal_sort_by' => 'login_time_epoch', 'normal_desc' => true,
            'voip_sort_by' => 'login_time_epoch', 'voip_desc' => true,
            'conds' => new \stdClass(),
        ]);
        if (!is_array($r)) {
            return;
        }
        $normal = is_array($r[0] ?? null) ? $r[0] : [];
        $voip = is_array($r[1] ?? null) ? $r[1] : [];
        $sampleKeys = isset($normal[0]) && is_array($normal[0]) ? array_keys($normal[0]) : [];
        $this->patchLast('pass', 'اینترنتی: ' . count($normal) . ' | VoIP: ' . count($voip), ['online_record_keys' => $sampleKeys]);
    }

    public function readUser(string $username): void
    {
        $r = $this->rpc('read.user', "اطلاعات کاربر «{$username}» (user.getUserInfo)", 'user.getUserInfo', ['normal_username' => $username]);
        if (!is_array($r)) {
            return;
        }
        $this->patchLast('pass', count($r) . ' رکورد', self::redactUserInfos($r));
    }

    // ------------------------------------------------------------ controlled write

    public function controlledWriteTest(): void
    {
        $username = 'ibsprobe_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(6));
        $admin = (string) $this->cfg['admin_username'];
        $group = (string) $this->cfg['write_test_group'];
        $userId = null;

        try {
            $chk = $this->rpc('write.check_username', "بررسی آزاد بودن نام کاربری {$username}", 'normal_user.checkNormalUsernameForAdd',
                ['normal_username' => $username, 'current_username' => '']);
            if ($chk === null) {
                return;
            }
            $this->patchLast('pass', 'پاسخ: ' . json_encode($chk, JSON_UNESCAPED_UNICODE));

            $ids = $this->rpc('write.add_user', 'ایجاد کاربر خام (user.addNewUsers)', 'user.addNewUsers', [
                'count' => 1, 'credit' => 0.0, 'owner_name' => $admin, 'group_name' => $group,
                'credit_comment' => 'ibs-probe phase-1 controlled test',
            ]);
            if (!is_array($ids) || count($ids) !== 1) {
                if ($ids !== null) {
                    $this->patchLast('fail', 'پاسخ غیرمنتظره: ' . json_encode($ids));
                }
                return;
            }
            $userId = (string) $ids[0];
            $this->patchLast('pass', "user_id={$userId}");

            // The user is LOCKED from the first moment it has a username, so it can never log in.
            $upd = $this->rpc('write.set_attrs', 'تنظیم ویژگی‌ها (user.updateUserAttrs)', 'user.updateUserAttrs', [
                'user_id' => $userId,
                'attrs' => [
                    'lock' => 'ibs-probe test user - safe to delete',
                    'normal_username' => $username,
                    'normal_password' => $password,
                    'normal_generate_password' => 0,
                    'normal_generate_password_len' => 0,
                    'normal_save_usernames' => '',
                    'multi_login' => 1,
                    'rel_exp_date' => 30,
                    'rel_exp_date_unit' => 'Days',
                    'comment' => 'ibs-probe',
                ],
                'to_del_attrs' => [],
            ]);
            if ($upd === null) {
                return;
            }
            $this->patchLast('pass', 'ویژگی‌ها ثبت شد');

            $info = $this->rpc('write.read_back', 'خواندن دوباره کاربر (user.getUserInfo)', 'user.getUserInfo', ['user_id' => $userId]);
            if (is_array($info)) {
                $attrs = $info[$userId]['attrs'] ?? [];
                $expect = ['normal_username' => $username, 'multi_login' => '1', 'lock' => null, 'rel_exp_date' => null];
                $missing = [];
                foreach ($expect as $k => $val) {
                    if (!array_key_exists($k, $attrs) || ($val !== null && (string) $attrs[$k] !== $val)) {
                        $missing[] = $k;
                    }
                }
                $this->patchLast($missing ? 'warn' : 'pass',
                    $missing ? 'مقادیر مورد انتظار یافت نشد: ' . implode(', ', $missing) : 'همه ویژگی‌ها تأیید شد',
                    self::redactUserInfos($info));
            }

            $cr = $this->rpc('write.change_credit', 'افزایش اعتبار (user.changeCredit) به میزان 1.5', 'user.changeCredit', [
                'user_id' => $userId, 'credit' => 1.5, 'credit_comment' => 'ibs-probe credit test',
            ]);
            if ($cr !== null) {
                $after = $this->rpc('write.read_credit', 'خواندن اعتبار پس از تغییر', 'user.getUserInfo', ['user_id' => $userId]);
                if (is_array($after)) {
                    $credit = $after[$userId]['basic_info']['credit'] ?? null;
                    $this->patchLast(abs((float) $credit - 1.5) < 0.0001 ? 'pass' : 'warn', 'credit=' . var_export($credit, true));
                }
            }
        } finally {
            if ($userId !== null) {
                $del = $this->rpc('write.delete', 'حذف کاربر آزمایشی (user.delUser)', 'user.delUser', [
                    'user_id' => $userId, 'delete_comment' => 'ibs-probe cleanup',
                    'del_connection_logs' => true, 'del_audit_logs' => true,
                ]);
                if ($del !== null) {
                    $this->patchLast('pass', "کاربر {$userId} حذف شد");
                    $gone = $this->rpcExpectFault('write.verify_delete', 'اطمینان از حذف', 'user.getUserInfo', ['user_id' => $userId]);
                    unset($gone);
                } else {
                    $this->add('write.cleanup_manual', 'پاکسازی دستی لازم است', 'fail',
                        "کاربر قفل‌شده با user_id={$userId} و نام {$username} باید دستی از پنل IBSng حذف شود.");
                }
            }
        }
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string,mixed> $args
     * @return mixed|null null on any failure (already recorded)
     */
    private function rpc(string $id, string $title, string $method, array $args)
    {
        $params = [
            'auth_name' => (string) $this->cfg['admin_username'],
            'auth_pass' => (string) $this->cfg['admin_password'],
            'auth_type' => 'ADMIN',
        ] + $args;
        try {
            $res = $this->client->call($method, $params);
            $this->add($id, $title, 'pass', 'OK', null);
            return $res;
        } catch (XmlRpcFault $f) {
            $this->add($id, $title, 'fail', 'خطای IBSng: ' . $f->getMessage());
        } catch (XmlRpcTransportError $e) {
            $this->add($id, $title, 'fail', 'خطای ارتباط: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->add($id, $title, 'fail', get_class($e) . ': ' . $e->getMessage());
        }
        return null;
    }

    /** @param array<string,mixed> $args */
    private function rpcExpectFault(string $id, string $title, string $method, array $args): bool
    {
        $params = ['auth_name' => (string) $this->cfg['admin_username'], 'auth_pass' => (string) $this->cfg['admin_password'], 'auth_type' => 'ADMIN'] + $args;
        try {
            $this->client->call($method, $params);
            $this->add($id, $title, 'fail', 'کاربر هنوز وجود دارد!');
            return false;
        } catch (XmlRpcFault $f) {
            $this->add($id, $title, 'pass', 'کاربر دیگر وجود ندارد: ' . $f->getMessage());
            return true;
        } catch (\Throwable $e) {
            $this->add($id, $title, 'warn', 'نامشخص: ' . $e->getMessage());
            return false;
        }
    }

    /** @param mixed $data */
    private function add(string $id, string $title, string $status, string $detail, $data = null): void
    {
        $row = ['id' => $id, 'title' => $title, 'status' => $status, 'detail' => $this->scrub($detail)];
        if ($this->client->lastMeta !== null && str_contains($id, '.') && !str_starts_with($id, 'env.') && !str_starts_with($id, 'net.')) {
            $row['meta'] = $this->client->lastMeta;
        }
        if ($data !== null) {
            $row['data'] = $data;
        }
        $this->results[] = $row;
    }

    /** @param mixed $data */
    private function patchLast(string $status, string $detail, $data = null): void
    {
        $i = count($this->results) - 1;
        $this->results[$i]['status'] = $status;
        $this->results[$i]['detail'] = $this->scrub($detail);
        if ($data !== null) {
            $this->results[$i]['data'] = $data;
        }
    }

    /** Never let the admin password leak into the report. */
    private function scrub(string $s): string
    {
        $p = (string) ($this->cfg['admin_password'] ?? '');
        return $p !== '' ? str_replace($p, '***', $s) : $s;
    }

    /**
     * @param array<string,mixed> $infos
     * @return array<string,mixed>
     */
    public static function redactUserInfos(array $infos): array
    {
        foreach ($infos as $uid => $info) {
            if (isset($info['attrs']) && is_array($info['attrs'])) {
                foreach (['normal_password', 'voip_password'] as $secret) {
                    if (array_key_exists($secret, $info['attrs'])) {
                        $infos[$uid]['attrs'][$secret] = '***';
                    }
                }
            }
            if (isset($info['raw_attrs']) && is_array($info['raw_attrs'])) {
                foreach (['normal_password', 'voip_password'] as $secret) {
                    if (array_key_exists($secret, $info['raw_attrs'])) {
                        $infos[$uid]['raw_attrs'][$secret] = '***';
                    }
                }
            }
        }
        return $infos;
    }
}
