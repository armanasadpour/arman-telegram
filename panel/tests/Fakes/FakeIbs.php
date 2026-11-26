<?php

namespace Tests\Fakes;

use App\Ibs\IbsException;
use App\Ibs\IbsUnavailableException;
use App\Ibs\Transport;

/**
 * In-memory emulation of the IBSng A1.24 XML-RPC API, modelled on the A1.24 source and on
 * the responses captured from the production server in phase 1 (shapes, error codes,
 * "CODE|message" faults, 30-day months, MultiStr ids, empty-struct username check).
 *
 * Used directly as a Transport in PHPUnit, and wrapped by tests/Fakes/ibsng-server.php as a
 * real HTTP XML-RPC server for browser end-to-end tests (state persisted to a JSON file).
 */
class FakeIbs implements Transport
{
    public array $groups = [];

    public array $charges = [];

    /** Simulate an IBSng that ignores deleting first_login. */
    public bool $keepFirstLogin = false;

    public array $users = [];      // id => ['username','password','group','credit','attrs'=>[], 'first_login'=>?int, 'created'=>int]

    public array $onlines = [];    // id => ['ras_ip','in_bytes','out_bytes','login_time_epoch','unique_id_val']

    public int $nextId = 2597800;

    /** @var array<string, list<\Throwable|callable>> queued failures per method */
    public array $failures = [];

    /** @var list<string> */
    public array $calls = [];

    public function __construct(public string $adminUser = 'system', public string $adminPass = 'secret', public int $clockSkew = 0)
    {
        $this->charges = [
            'Internet' => [['rule_id' => 3, 'cpm' => 0, 'cpk' => 0, 'charge_name' => 'Internet']],
            'Volume-MB' => [['rule_id' => 4, 'cpm' => 0, 'cpk' => 0.0009765625, 'charge_name' => 'Volume-MB']],
        ];
        $this->groups = [
            '1-Month-kerio' => ['id' => 2, 'attrs' => ['rel_exp_date' => 2592000, 'multi_login' => 2, 'normal_charge' => 'Internet']],
            'test' => ['id' => 10, 'attrs' => ['rel_exp_date' => 432000, 'multi_login' => 2, 'normal_charge' => 'Internet']],
            'vol-30d' => ['id' => 11, 'attrs' => ['rel_exp_date' => 2592000, 'multi_login' => 1, 'normal_charge' => 'Volume-MB']],
        ];
    }

    /** Queue a failure for the next call of $method (an exception, or a callable run before succeeding). */
    public function failNext(string $method, \Throwable|callable $what): void
    {
        $this->failures[$method][] = $what;
    }

    public function now(): int
    {
        return time() + $this->clockSkew;
    }

    /** Simulate the user connecting for the first time (and being online now). */
    public function connect(string $username, int $inBytes = 1000, int $outBytes = 5000, ?string $stationIp = null): void
    {
        $id = $this->idByUsername($username) ?? throw new \LogicException('no such user');
        $this->users[$id]['first_login'] ??= $this->now();
        $this->onlines[$id] = ['ras_ip' => '10.0.0.1', 'in_bytes' => $inBytes, 'out_bytes' => $outBytes,
            'login_time_epoch' => $this->now(), 'unique_id_val' => 'ovpn-'.$id] + ($stationIp ? ['station_ip' => $stationIp] : []);
    }

    public function call(string $method, array $params): mixed
    {
        $this->calls[] = $method;
        if (! empty($this->failures[$method])) {
            $f = array_shift($this->failures[$method]);
            if ($f instanceof \Throwable) {
                throw $f;
            }
            $f($this);
        }
        if (($params['auth_type'] ?? '') !== 'ADMIN') {
            $this->fault('ACCESS_DENIED', 'Access Denied');
        }
        if (($params['auth_name'] ?? '') !== $this->adminUser) {
            $this->fault('ADMIN_USERNAME_INVALID', 'Admin Username '.($params['auth_name'] ?? '').' is invalid');
        }
        if (($params['auth_pass'] ?? '') !== $this->adminPass) {
            $this->fault('INCORRECT_PASSWORD', 'Incorrect password');
        }
        unset($params['auth_name'], $params['auth_pass'], $params['auth_type']);

        $handler = 'm_'.str_replace('.', '_', $method);
        if (! method_exists($this, $handler)) {
            throw new IbsUnavailableException('HTTP 500 from IBSng');
        }

        return $this->$handler($params) ?? ''; // IBSng turns None into ""
    }

    // ------------------------------------------------------------------ methods

    private function m_admin_getAdminInfo(array $p): array
    {
        $this->need($p, 'admin_username');

        return ['admin_id' => 0, 'username' => $this->adminUser, 'name' => 'IBS Internal System Account', 'deposit' => '-9166152935.0',
            'creator_id' => 0, 'locks' => [], 'online_status' => true, 'last_activity' => '', 'last_request_ip' => '127.0.0.1', 'comment' => '', 'creator' => 'system'];
    }

    private function m_group_listGroups(array $p): array
    {
        $names = array_keys($this->groups);
        sort($names);

        return $names;
    }

    private function m_group_getGroupInfo(array $p): array
    {
        $this->need($p, 'group_name');
        $g = $this->groups[$p['group_name']] ?? $this->fault('GROUP_NAME_INVALID', 'Group name '.$p['group_name'].' is invalid');
        $raw = array_map('strval', $g['attrs']);
        $attrs = $g['attrs'];
        if (isset($g['attrs']['normal_charge'])) {
            $raw['normal_charge'] = (string) (array_search($g['attrs']['normal_charge'], array_keys($this->charges), true) + 1);
        }
        unset($attrs['rel_exp_date']);
        if (isset($g['attrs']['rel_exp_date'])) {
            $attrs += $this->relParsed($g['attrs']['rel_exp_date']);
        }
        if (isset($g['attrs']['multi_login'])) {
            $attrs['multi_login'] = (string) $g['attrs']['multi_login'];
        }

        return ['group_id' => $g['id'], 'group_name' => $p['group_name'], 'comment' => '', 'owner_id' => 0, 'owner_name' => 'system', 'raw_attrs' => $raw, 'attrs' => $attrs];
    }

    private function m_group_addNewGroup(array $p): int
    {
        $this->need($p, 'group_name', 'comment');
        if ($p['group_name'] === '' || preg_match('/[^a-zA-Z0-9_\-]/', $p['group_name'])) {
            $this->fault('GROUP_NAME_INVALID', 'Group name '.$p['group_name'].' is invalid');
        }
        if (isset($this->groups[$p['group_name']])) {
            $this->fault('GROUP_NAME_TAKEN', 'Group name '.$p['group_name'].' already exists');
        }
        $id = max(array_column($this->groups, 'id') ?: [0]) + 1;
        $this->groups[$p['group_name']] = ['id' => $id, 'attrs' => []];

        return $id;
    }

    private function m_group_updateGroupAttrs(array $p): string
    {
        $this->need($p, 'group_name', 'attrs', 'to_del_attrs');
        isset($this->groups[$p['group_name']]) || $this->fault('GROUP_NAME_INVALID', 'Group name '.$p['group_name'].' is invalid');
        $a = (array) $p['attrs'];
        if (array_key_exists('normal_charge', $a) && ! isset($this->charges[$a['normal_charge']])) {
            $this->fault('CHARGE_NAME_INVALID', 'Charge name '.$a['normal_charge'].' is invalid');
        }
        $g = &$this->groups[$p['group_name']]['attrs'];
        if (array_key_exists('rel_exp_date', $a)) {
            $g['rel_exp_date'] = (int) $a['rel_exp_date'] * ['Hours' => 3600, 'Days' => 86400, 'Months' => 2592000, 'Years' => 31536000][$a['rel_exp_date_unit']];
        }
        foreach (['multi_login', 'normal_charge'] as $k) {
            if (array_key_exists($k, $a)) {
                $g[$k] = $k === 'multi_login' ? (int) $a[$k] : $a[$k];
            }
        }
        foreach ((array) $p['to_del_attrs'] as $k) {
            unset($g[$k]);
        }

        return '';
    }

    private function m_charge_listCharges(array $p): array
    {
        return array_keys($this->charges);
    }

    private function m_charge_listChargeRules(array $p): array
    {
        $this->need($p, 'charge_name');

        return array_map(fn ($r) => $r + ['start_time' => '00:00:00', 'end_time' => '23:59:59', 'ras' => '_ALL_', 'ports' => ['_ALL_'], 'assumed_kps' => 8, 'bandwidth_limit' => 0, 'type' => 'Internet'],
            $this->charges[$p['charge_name']] ?? []);
    }

    private function m_report_getOnlineUsers(array $p): array
    {
        $this->need($p, 'normal_sort_by', 'normal_desc', 'voip_sort_by', 'voip_desc', 'conds');
        $list = [];
        foreach ($this->onlines as $id => $o) {
            $u = $this->users[$id] ?? null;
            if (! $u) {
                continue;
            }
            $list[] = $o + ['user_id' => $id, 'normal_username' => $u['username'], 'group_name' => $u['group'], 'owner_name' => 'system', 'owner_id' => 0,
                'current_credit' => $u['credit'], 'service' => 'Internet', 'in_rate' => 0, 'out_rate' => 0, 'ras_description' => 'mikrotik',
                'duration_secs' => $this->now() - $o['login_time_epoch'], 'unique_id' => 'port', 'login_time' => date('Y-m-d H:i', $o['login_time_epoch']), 'attrs' => []];
        }

        return [$list, []];
    }

    private function m_normal_user_checkNormalUsernameForAdd(array $p): array
    {
        $this->need($p, 'normal_username', 'current_username');
        $out = [];
        $names = explode(',', $p['normal_username']);
        $bad = array_values(array_filter($names, fn ($n) => $n === '' || preg_match('/[^A-Za-z0-9_.@-]/', $n)));
        $exists = array_values(array_filter($names, fn ($n) => $this->idByUsername($n) !== null && $n !== $p['current_username']));
        if ($bad) {
            $out['Bad Internet username '] = $bad;
        }
        if ($exists) {
            $out['Internet username(s)  already exist'] = $exists;
        }

        return $out;
    }

    private function m_user_addNewUsers(array $p): array
    {
        $this->need($p, 'count', 'credit', 'owner_name', 'group_name', 'credit_comment');
        if (! isset($this->groups[$p['group_name']])) {
            $this->fault('GROUP_NAME_INVALID', 'Group name '.$p['group_name'].' is invalid');
        }
        $ids = [];
        for ($i = 0; $i < (int) $p['count']; $i++) {
            $id = $this->nextId++;
            $this->users[$id] = ['username' => null, 'password' => null, 'group' => $p['group_name'], 'credit' => (float) $p['credit'],
                'attrs' => [], 'first_login' => null, 'created' => $this->now(), 'credit_comment' => $p['credit_comment']];
            $ids[] = $id;
        }

        return $ids;
    }

    private function m_user_updateUserAttrs(array $p): void
    {
        $this->need($p, 'user_id', 'attrs', 'to_del_attrs');
        $ids = $this->ids($p['user_id']);
        $a = (array) $p['attrs'];
        if (array_key_exists('normal_username', $a)) {
            foreach (['normal_password', 'normal_generate_password', 'normal_generate_password_len', 'normal_save_usernames'] as $k) {
                $this->need($a, $k);
            }
            $other = $this->idByUsername($a['normal_username']);
            if ($other !== null && ! in_array($other, $ids, true)) {
                $this->fault('NORMAL_USERNAME_EXISTS', 'Internet username(s) '.$a['normal_username'].' already exist');
            }
            if (preg_match('/[^A-Za-z0-9_.@-]/', $a['normal_username'])) {
                $this->fault('BAD_NORMAL_USERNAME', 'Bad Internet username '.$a['normal_username']);
            }
        }
        if (array_key_exists('multi_login', $a) && ((int) $a['multi_login'] < 0 || (int) $a['multi_login'] > 255)) {
            $this->fault('INVALID_MULTI_LOGIN', 'Invalid multi login');
        }
        foreach ($ids as $id) {
            $u = &$this->users[$id];
            if (array_key_exists('normal_username', $a)) {
                $u['username'] = $a['normal_username'];
                $u['password'] = $a['normal_password'];
            }
            if (array_key_exists('rel_exp_date', $a)) {
                $u['attrs']['rel_exp_date'] = (int) $a['rel_exp_date'] * ['Hours' => 3600, 'Days' => 86400, 'Months' => 2592000, 'Years' => 31536000][$a['rel_exp_date_unit']];
            }
            foreach (['lock', 'multi_login', 'comment'] as $k) {
                if (array_key_exists($k, $a)) {
                    $u['attrs'][$k] = (string) $a[$k];
                }
            }
            foreach ((array) $p['to_del_attrs'] as $k) {
                unset($u['attrs'][$k]);
                if ($k === 'first_login' && ! $this->keepFirstLogin) {
                    $u['first_login'] = null;
                }
            }
            unset($u);
        }
    }

    private function m_user_killUser(array $p): bool
    {
        $this->need($p, 'user_id', 'ras_ip', 'unique_id_val', 'kill');
        $id = (int) $p['user_id'];
        if (! isset($this->onlines[$id]) || $this->onlines[$id]['unique_id_val'] !== $p['unique_id_val']) {
            $this->fault('USER_NOT_ONLINE', 'User is not online');
        }
        unset($this->onlines[$id]);

        return true;
    }

    private function m_user_getUserInfo(array $p): array
    {
        if (isset($p['user_id'])) {
            $ids = $this->ids($p['user_id']);
        } elseif (isset($p['normal_username'])) {
            $ids = [];
            foreach (explode(',', $p['normal_username']) as $n) {
                $ids[] = $this->idByUsername($n) ?? $this->fault('NORMAL_USERNAME_DOESNT_EXISTS', "User with Internet username $n does not exists");
            }
        } else {
            $this->fault('INCOMPLETE_REQUEST', 'Incomplete request, argument user_id not found');
        }
        $out = [];
        foreach ($ids as $id) {
            $u = $this->users[$id];
            $attrs = ['user_id' => $id];
            if ($u['username'] !== null) {
                $attrs['normal_username'] = $u['username'];
                $attrs['normal_password'] = $u['password'];
            }
            foreach (['lock', 'multi_login', 'comment'] as $k) {
                if (isset($u['attrs'][$k])) {
                    $attrs[$k] = $u['attrs'][$k];
                }
            }
            if (isset($u['attrs']['rel_exp_date'])) {
                $attrs += $this->relParsed($u['attrs']['rel_exp_date']);
            }
            $rel = $u['attrs']['rel_exp_date'] ?? $this->groups[$u['group']]['attrs']['rel_exp_date'] ?? null;
            if ($u['first_login'] !== null) {
                $attrs['first_login'] = date('Y-m-d H:i', $u['first_login']);
                if ($rel !== null) {
                    $exp = $u['first_login'] + $rel;
                    $attrs['nearest_exp_date'] = date('Y-m-d H:i', $exp);
                    $attrs['nearest_exp_date_epoch'] = $exp;
                    $attrs['time_to_nearest_exp_date'] = (float) ($exp - $this->now()) + 0.25;
                }
            }
            $out[(string) $id] = [
                'online_status' => isset($this->onlines[$id]),
                'attrs' => $attrs,
                'basic_info' => ['user_id' => $id, 'owner_id' => 0, 'owner_name' => 'system', 'group_name' => $u['group'],
                    'group_id' => $this->groups[$u['group']]['id'], 'credit' => $u['credit'], 'creation_date' => date('Y-m-d H:i', $u['created'])],
            ];
        }

        return $out;
    }

    private function m_user_changeCredit(array $p): void
    {
        $this->need($p, 'user_id', 'credit', 'credit_comment');
        foreach ($this->ids($p['user_id']) as $id) {
            $this->users[$id]['credit'] += (float) $p['credit'];
        }
    }

    private function m_user_delUser(array $p): void
    {
        $this->need($p, 'user_id', 'delete_comment', 'del_connection_logs', 'del_audit_logs');
        foreach ($this->ids($p['user_id']) as $id) {
            unset($this->users[$id], $this->onlines[$id]);
        }
    }

    private function m_user_searchUser(array $p): array
    {
        $this->need($p, 'conds', 'from', 'to', 'order_by', 'desc');
        if ($p['to'] - $p['from'] > 3000) {
            $this->fault('TO_VALUE_INVALID', 'To value is invalid');
        }
        $ids = array_keys($this->users);
        sort($ids);

        return [count($ids), array_slice($ids, (int) $p['from'], (int) $p['to'] - (int) $p['from'])];
    }

    // ------------------------------------------------------------------ helpers

    public function idByUsername(string $username): ?int
    {
        foreach ($this->users as $id => $u) {
            if ($u['username'] === $username) {
                return $id;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function ids(string|int $multi): array
    {
        $ids = array_map('intval', explode(',', (string) $multi));
        foreach ($ids as $id) {
            if (! isset($this->users[$id])) {
                $this->fault('USERID_DOESNT_EXISTS', "User with user id $id does not exists");
            }
        }

        return $ids;
    }

    /** IBSng shows relative dates in the largest whole unit (2592000 -> 1 Months). */
    private function relParsed(int $seconds): array
    {
        foreach (['Years' => 31536000, 'Months' => 2592000, 'Days' => 86400, 'Hours' => 3600] as $unit => $f) {
            if ($seconds % $f === 0) {
                return ['rel_exp_date' => intdiv($seconds, $f), 'rel_exp_date_unit' => $unit];
            }
        }

        return ['rel_exp_date' => $seconds, 'rel_exp_date_unit' => 'Seconds'];
    }

    private function need(array $p, string ...$keys): void
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $p)) {
                $this->fault('INCOMPLETE_REQUEST', "Incomplete request, argument $k not found");
            }
        }
    }

    private function fault(string $code, string $msg): never
    {
        throw new IbsException($code, $msg, "$code|$msg");
    }

    // ------------------------------------------------------------------ persistence for the HTTP server

    public function export(): array
    {
        return ['groups' => $this->groups, 'charges' => $this->charges, 'users' => $this->users, 'onlines' => $this->onlines, 'nextId' => $this->nextId];
    }

    public function import(array $s): void
    {
        foreach (['groups', 'charges', 'users', 'onlines', 'nextId'] as $k) {
            if (array_key_exists($k, $s)) {
                $this->$k = $s[$k];
            }
        }
    }
}
