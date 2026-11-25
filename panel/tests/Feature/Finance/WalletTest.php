<?php

namespace Tests\Feature\Finance;

use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Finance\InsufficientBalanceException;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class WalletTest extends TestCase
{
    private WalletService $w;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = app(WalletService::class);
        $this->wallet = $this->w->walletFor('reseller', 1);
    }

    public function test_credit_debit_and_running_balance(): void
    {
        $this->w->post($this->wallet, 1_000_000, 'manual_credit', 'k1');
        $e = $this->w->post($this->wallet, -300_000, 'purchase', 'k2');
        $this->assertSame(700_000, $e->balance_after);
        $this->assertSame(700_000, $this->wallet->fresh()->balance);
        $this->assertTrue($this->w->reconcile($this->wallet)['ok']);
    }

    public function test_balance_can_never_go_negative_and_nothing_is_written(): void
    {
        $this->w->post($this->wallet, 100_000, 'manual_credit', 'k1');
        try {
            $this->w->post($this->wallet, -100_001, 'purchase', 'k2');
            $this->fail('expected InsufficientBalanceException');
        } catch (InsufficientBalanceException) {
        }
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame(100_000, $this->wallet->fresh()->balance);
        // exactly the balance is allowed
        $this->assertSame(0, $this->w->post($this->wallet, -100_000, 'purchase', 'k3')->balance_after);
    }

    public function test_same_key_is_idempotent_and_reuse_with_other_amount_is_refused(): void
    {
        $a = $this->w->post($this->wallet, 500_000, 'manual_credit', 'same');
        $b = $this->w->post($this->wallet, 500_000, 'manual_credit', 'same');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(500_000, $this->wallet->fresh()->balance);
        $this->expectException(RuntimeException::class);
        $this->w->post($this->wallet, 999, 'manual_credit', 'same');
    }

    public function test_entries_are_immutable(): void
    {
        $e = $this->w->post($this->wallet, 10, 'manual_credit', 'k');
        try {
            $e->update(['amount' => 1_000_000]);
            $this->fail('update must throw');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $e->delete();
    }

    public function test_reconcile_detects_tampered_balance_or_rows(): void
    {
        $this->w->post($this->wallet, 1000, 'manual_credit', 'k1');
        $this->w->post($this->wallet, -400, 'purchase', 'k2');
        DB::table('wallets')->where('id', $this->wallet->id)->update(['balance' => 99999]);
        $this->assertFalse($this->w->reconcile($this->wallet)['ok']);
        DB::table('wallets')->where('id', $this->wallet->id)->update(['balance' => 600]);
        $this->assertTrue($this->w->reconcile($this->wallet)['ok']);
        DB::table('ledger_entries')->where('idempotency_key', 'k1')->update(['amount' => 2000]);
        $r = $this->w->reconcile($this->wallet);
        $this->assertFalse($r['ok']);
        $this->assertFalse($r['chain_ok']);
        $this->artisan('wallets:reconcile')->assertFailed();
    }

    public function test_reversal_once_only(): void
    {
        $admin = $this->superAdmin();
        $e = $this->w->post($this->wallet, 50_000, 'manual_credit', 'k1');
        $rev = $this->w->reverse($e, 'اشتباه تایپی', $admin->id);
        $this->assertSame(-50_000, $rev->amount);
        $this->assertSame($e->id, $rev->reverses_entry_id);
        $this->assertSame(0, $this->wallet->fresh()->balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallets.reversal']);
        try {
            $this->w->reverse($e, 'again', $admin->id);
            $this->fail('second reversal must fail');
        } catch (RuntimeException) {
        }
        $this->expectException(RuntimeException::class);
        $this->w->reverse($rev, 'reverse the reversal', $admin->id);
    }

    public function test_reversal_that_would_go_negative_is_refused(): void
    {
        $e = $this->w->post($this->wallet, 50_000, 'manual_credit', 'k1');
        $this->w->post($this->wallet, -40_000, 'purchase', 'k2');
        $this->expectException(InsufficientBalanceException::class);
        $this->w->reverse($e, 'too late', $this->superAdmin()->id);
    }
}
