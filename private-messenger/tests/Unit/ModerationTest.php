<?php
declare(strict_types=1);

use PM\Moderation\ConversationAdmin;
use PM\Moderation\PolicyService;
use PM\Moderation\SupportService;
use PM\Settings\Settings;
use PM\Support\UserError;

function modConv(\PM\Infra\Database $db, string $type, array $members, int $channel = 0): int {
    static $n = 0; $n++;
    $id = $db->insert('INSERT INTO conversations (public_id, type, is_channel, created_by, created_at) VALUES (?, ?, ?, ?, NOW(3))', [str_pad('MODCONV' . $n, 26, '0'), $type, $channel, $members[0]]);
    foreach ($members as $u) { $db->run("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW(3))", [$id, $u]); }
    return $id;
}

test('M: service switches — global, per user, per conversation; most specific blocks; timed rows expire', function () {
    [$db, $users] = svc();
    [$a] = $users->create(1, 'alice', 'A', '', true, '');
    [$b] = $users->create(1, 'bob', 'B', '', true, '');
    $g = modConv($db, 'group', [$a, $b]);
    $p = new PolicyService($db);
    ok($p->blocked('image', $a) === null);
    $p->setScope('global', 0, ['image', 'nonsense'], null, 'x', 1);
    ok($p->blocked('image', $a) !== null && $p->blocked('image', $b) !== null, 'global');
    eq(1, (int)$db->scalar('SELECT COUNT(*) FROM service_policies'), 'unknown service ignored');
    $p->setScope('global', 0, [], null, null, 1);
    $p->setScope('user', $a, ['voice_call'], PolicyService::untilFrom('1h'), null, 1);
    ok($p->blocked('voice_call', $a) !== null && $p->blocked('voice_call', $b) === null, 'per user');
    eq(['voice_call'], array_keys($p->forUser($a)));
    $p->setScope('conversation', $g, ['group_message', 'voice_call'], null, null, 1);
    eq([$g => ['group_message']], $p->forConversations([$g]), 'calls are not a conversation-level switch');
    ok($p->blocked('group_message', $b, $g) !== null && $p->blocked('group_message', $b, 999) === null, 'per conversation');
    throws(fn () => $p->assertAllowed('group_message', $b, $g), UserError::class);
    $db->run('UPDATE service_policies SET until_at = ? WHERE scope = ?', [gmdate('Y-m-d H:i:s', time() - 10), 'user']);
    ok($p->blocked('voice_call', $a) === null, 'expired row no longer blocks');
    ConversationAdmin::expire($db);
    eq(0, (int)$db->scalar("SELECT COUNT(*) FROM service_policies WHERE scope = 'user'"), 'and cron removes it');
});

test('M: banned group refuses messages; timed bans and suspensions lift by themselves', function () {
    [$db, $users] = svc();
    [$a] = $users->create(1, 'alice', 'A', '', true, '');
    [$b] = $users->create(1, 'bob', 'B', '', true, '');
    $g = modConv($db, 'group', [$a, $b]);
    $ca = new ConversationAdmin($db, new \PM\Audit\AuditLog($db));
    $ca->ban(1, $g, gmdate('Y-m-d H:i:s', time() - 5), 'spam', '1.1.1.1');
    eq('banned', $db->scalar('SELECT status FROM conversations WHERE id = ?', [$g]));
    $users->setStatus(1, $b, 'suspended', '', gmdate('Y-m-d H:i:s', time() - 5), 'rude');
    eq('rude', $db->scalar('SELECT status_reason FROM users WHERE id = ?', [$b]));
    eq('active', $users->expireStatus($users->get($b))['status'], 'login lifts an expired suspension');
    eq(1, ConversationAdmin::expire($db), 'cron lifts the expired ban');
    eq('active', $db->scalar('SELECT status FROM conversations WHERE id = ?', [$g]));
    $ca->removeMember(1, $g, $a, '');
    ok($db->scalar('SELECT left_at FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$g, $a]) !== null);
    ok((new \PM\Audit\AuditLog($db))->verify()['ok']);
});

test('M: support chat — one per user, members = user + current agents, agents leave when removed', function () {
    [$db, $users] = svc();
    $s = new Settings($db, str_repeat('k', 32));
    [$a] = $users->create(1, 'alice', 'A', '', true, '');
    [$x] = $users->create(1, 'agent1', 'X', '', true, '');
    [$y] = $users->create(1, 'agent2', 'Y', '', true, '');
    $sup = new SupportService($db, $s);
    eq(null, $sup->ensureFor($a), 'disabled without agents');
    $s->set('support.enabled', true);
    $sup->setAgents(['agent1', 'AGENT2']);
    $cid = $sup->ensureFor($a);
    eq($cid, $sup->ensureFor($a), 'only one support chat per user');
    eq(null, $sup->ensureFor($x), 'agents have no support chat of their own');
    $members = fn () => array_map('intval', array_column($db->all('SELECT user_id FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL ORDER BY user_id', [$cid]), 'user_id'));
    eq([$a, $x, $y], $members());
    $sup->setAgents(['agent2']);
    eq([$a, $y], $members(), 'removed agent leaves every support chat');
    throws(fn () => $sup->setAgents(['nobody']), UserError::class);
    ok(str_starts_with($sup->name(), 'پشتیبانی'));
});
