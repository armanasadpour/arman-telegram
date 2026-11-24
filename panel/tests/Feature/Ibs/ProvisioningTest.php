<?php

namespace Tests\Feature\Ibs;

use App\Ibs\IbsException;
use App\Ibs\IbsUnavailableException;
use App\Models\AuditLog;
use App\Models\ProvisioningJob;
use App\Models\VpnAccount;
use App\Services\Ibs\Provisioning;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fakes\FakeIbs;

class ProvisioningTest extends IbsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->syncCatalog();
    }

    private function spec(array $o = []): array
    {
        return $o + ['username' => 'cust01', 'password' => 'Secret123', 'group' => 'test', 'days' => null, 'multi_login' => null, 'volume_mb' => null, 'comment' => 'order 42'];
    }

    public function test_happy_path_creates_account_in_ibsng_and_mirror(): void
    {
        $job = app(Provisioning::class)->create((string) Str::uuid(), $this->spec(['days' => 30, 'multi_login' => 3]));
        $this->assertSame('completed', $job->status);
        $this->assertNull($job->password_encrypted, 'password must be wiped after completion');

        $id = $this->fake->idByUsername('cust01');
        $this->assertNotNull($id);
        $this->assertSame('Secret123', $this->fake->users[$id]['password']);
        $this->assertSame(30 * 86400, $this->fake->users[$id]['attrs']['rel_exp_date']);
        $this->assertSame('3', $this->fake->users[$id]['attrs']['multi_login']);
        $this->assertStringStartsWith('panel:'.$job->job_key, $this->fake->users[$id]['attrs']['comment']);

        $a = VpnAccount::query()->where('username', 'cust01')->firstOrFail();
        $this->assertSame($id, $a->ibs_user_id);
        $this->assertSame(30 * 86400, $a->effectiveRelExpSeconds());
        $this->assertSame('not_started', $a->status());
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounts.create', 'subject_id' => (string) $a->id]);
    }

    public function test_same_idempotency_key_never_creates_twice(): void
    {
        $key = (string) Str::uuid();
        $p = app(Provisioning::class);
        $j1 = $p->create($key, $this->spec());
        $j2 = $p->create($key, $this->spec());
        $this->assertSame($j1->id, $j2->id);
        $this->assertCount(1, $this->fake->users);
        $this->assertSame(1, ProvisioningJob::query()->count());
    }

    public function test_username_taken_in_ibsng_is_refused_before_any_write(): void
    {
        $this->ibsUser('cust01');
        try {
            app(Provisioning::class)->create((string) Str::uuid(), $this->spec());
            $this->fail('expected refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('وجود دارد', $e->getMessage());
        }
        $this->assertNotContains('user.addNewUsers', $this->fake->calls);
    }

    public function test_volume_group_sets_credit_from_megabytes(): void
    {
        $job = app(Provisioning::class)->create((string) Str::uuid(), $this->spec(['group' => 'vol-30d', 'volume_mb' => 10240]));
        $this->assertSame('completed', $job->status);
        $a = VpnAccount::query()->where('username', 'cust01')->firstOrFail();
        $this->assertSame(10240.0, (float) $this->fake->users[$a->ibs_user_id]['credit']); // cpk=1/1024 -> 1 credit = 1 MB
        $this->assertSame(10 * 1073741824, $a->remainingBytes());
        $this->assertSame(10 * 1073741824, $a->volume_total_bytes);
        $this->assertSame(0, $a->usedBytes());
    }

    public function test_volume_rules_are_enforced(): void
    {
        $p = app(Provisioning::class);
        $this->expectException(RuntimeException::class);
        $p->create((string) Str::uuid(), $this->spec(['group' => 'vol-30d', 'volume_mb' => null]));
    }

    public function test_step1_rejected_by_ibsng_fails_cleanly(): void
    {
        $this->fake->failNext('user.addNewUsers', new IbsException('ACCESS_DENIED', 'Access Denied'));
        $job = app(Provisioning::class)->create((string) Str::uuid(), $this->spec());
        $this->assertSame('failed', $job->status);
        $this->assertCount(0, $this->fake->users);
        $this->assertNull($job->password_encrypted);
    }

    public function test_step1_timeout_goes_to_review_because_outcome_is_unknown(): void
    {
        $this->fake->failNext('user.addNewUsers', new IbsUnavailableException('timeout'));
        $job = app(Provisioning::class)->create((string) Str::uuid(), $this->spec());
        $this->assertSame('needs_review', $job->status);
        $this->assertStringContainsString('panel-job:'.$job->job_key, $job->last_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounts.provision_review']);
    }

    public function test_step2_timeout_is_resumed_on_the_same_raw_user(): void
    {
        $this->fake->failNext('user.updateUserAttrs', new IbsUnavailableException('timeout'));
        $p = app(Provisioning::class);
        $job = $p->create((string) Str::uuid(), $this->spec());
        $this->assertSame('raw_created', $job->status);
        $this->assertCount(1, $this->fake->users);

        $job = $p->run($job);   // what the cron retry does
        $this->assertSame('completed', $job->status);
        $this->assertCount(1, $this->fake->users, 'still exactly one IBSng user');
        $this->assertNotNull($this->fake->idByUsername('cust01'));
    }

    public function test_step2_timeout_after_ibsng_applied_it_is_detected_as_success(): void
    {
        $fake = new class('system', 'secret') extends FakeIbs
        {
            public bool $dropReply = true;

            public function call(string $method, array $params): mixed
            {
                $r = parent::call($method, $params);
                if ($method === 'user.updateUserAttrs' && $this->dropReply) {
                    $this->dropReply = false;
                    throw new IbsUnavailableException('timeout after apply');
                }

                return $r;
            }
        };
        $this->useFake($fake);
        $this->syncCatalog();
        $job = app(Provisioning::class)->create((string) Str::uuid(), $this->spec());
        $this->assertSame('completed', $job->status);
        $this->assertCount(1, $fake->users);
    }

    public function test_step2_rejected_compensates_by_deleting_raw_user(): void
    {
        $this->fake->failNext('user.updateUserAttrs', new IbsException('NORMAL_USERNAME_EXISTS', 'Internet username(s) cust01 already exist'));
        $job = app(Provisioning::class)->create((string) Str::uuid(), $this->spec());
        $this->assertSame('compensated', $job->status);
        $this->assertCount(0, $this->fake->users, 'raw user removed from IBSng');
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounts.provision_compensated']);
    }

    public function test_failed_compensation_goes_to_review_and_admin_can_retry(): void
    {
        $this->fake->failNext('user.updateUserAttrs', new IbsException('INVALID_MULTI_LOGIN', 'x'));
        $this->fake->failNext('user.delUser', new IbsUnavailableException('down'));
        $p = app(Provisioning::class);
        $job = $p->create((string) Str::uuid(), $this->spec());
        $this->assertSame('needs_review', $job->status);
        $this->assertNotNull($job->ibs_user_id);
        $this->assertCount(1, $this->fake->users);

        $job = $p->retry($job);
        $this->assertSame('completed', $job->status);
        $this->assertNotNull($this->fake->idByUsername('cust01'));
    }

    public function test_generated_credentials_are_safe(): void
    {
        $p = app(Provisioning::class);
        $this->assertMatchesRegularExpression('/^u\d{6}$/', $p->generateUsername());
        $pw = Provisioning::generatePassword();
        $this->assertSame(10, strlen($pw));
        $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $pw);
    }

    public function test_create_via_http_shows_password_once_and_resubmit_is_safe(): void
    {
        $admin = $this->superAdmin();
        $key = (string) Str::uuid();
        $r = $this->actingAs($admin)->post('/accounts', ['job_key' => $key, 'group' => 'test', 'username' => 'webuser']);
        $a = VpnAccount::query()->where('username', 'webuser')->firstOrFail();
        $r->assertRedirect(route('accounts.show', $a))->assertSessionHas('new_password');
        $this->assertSame(session('new_password'), $this->fake->users[$a->ibs_user_id]['password']);

        $r2 = $this->actingAs($admin)->post('/accounts', ['job_key' => $key, 'group' => 'test', 'username' => 'webuser']);
        $r2->assertRedirect(route('accounts.show', $a))->assertSessionMissing('new_password');
        $this->assertCount(1, $this->fake->users);
        $this->assertFalse(AuditLog::query()->where('action', 'accounts.create')->get()->toJson() === '[]');
        $this->assertStringNotContainsString((string) $this->fake->users[$a->ibs_user_id]['password'], AuditLog::query()->get()->toJson());
    }
}
