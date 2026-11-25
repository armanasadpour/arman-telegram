<?php

namespace App\Services\Finance;

use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only writer of wallets and ledger entries.
 *  - Atomic: each post runs in a DB transaction with the wallet row locked (SELECT ... FOR UPDATE).
 *  - Never negative: a debit larger than the balance throws and writes nothing.
 *  - Idempotent: the same idempotency key returns the existing entry instead of posting twice
 *    (and refuses if the same key is reused for a different amount/wallet).
 *  - Auditable: entries are immutable; balance is a cache verified by reconcile().
 */
class WalletService
{
    public function __construct(private AuditLogger $audit) {}

    public function walletFor(string $ownerType, int $ownerId): Wallet
    {
        return Wallet::query()->firstOrCreate(['owner_type' => $ownerType, 'owner_id' => $ownerId]);
    }

    /**
     * @param  int  $amount  signed rial: > 0 credit, < 0 debit
     */
    public function post(
        Wallet $wallet,
        int $amount,
        string $type,
        string $idempotencyKey,
        ?string $description = null,
        ?string $reason = null,
        ?int $actorId = null,
        ?Model $reference = null,
        ?int $reversesEntryId = null,
    ): LedgerEntry {
        if ($amount === 0) {
            throw new InvalidArgumentException('Amount must not be zero.');
        }
        if (! array_key_exists($type, LedgerEntry::TYPES)) {
            throw new InvalidArgumentException("Unknown ledger type $type");
        }

        try {
            return DB::transaction(function () use ($wallet, $amount, $type, $idempotencyKey, $description, $reason, $actorId, $reference, $reversesEntryId) {
                $w = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

                if ($existing = LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first()) {
                    return $this->assertSameRequest($existing, $w, $amount);
                }

                $newBalance = $w->balance + $amount;
                if ($newBalance < 0) {
                    throw new InsufficientBalanceException('موجودی کافی نیست. موجودی فعلی: '.fa_money($w->balance).'، مبلغ لازم: '.fa_money(-$amount).'.');
                }

                $entry = LedgerEntry::query()->create([
                    'wallet_id' => $w->id,
                    'type' => $type,
                    'amount' => $amount,
                    'balance_after' => $newBalance,
                    'idempotency_key' => $idempotencyKey,
                    'reference_type' => $reference ? class_basename($reference) : null,
                    'reference_id' => $reference?->getKey(),
                    'description' => $description !== null ? mb_substr($description, 0, 255) : null,
                    'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
                    'actor_id' => $actorId,
                    'reverses_entry_id' => $reversesEntryId,
                    'created_at' => now(),
                ]);
                $w->balance = $newBalance;
                $w->save();
                $wallet->setRawAttributes($w->getAttributes(), true);

                return $entry;
            });
        } catch (QueryException $e) {
            // Two concurrent requests with the same key: the unique index rejected the second insert.
            if ($existing = LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $this->assertSameRequest($existing, $wallet, $amount);
            }
            throw $e;
        }
    }

    /** Admin correction of a wrong entry: a new opposite row that references the original. */
    public function reverse(LedgerEntry $entry, string $reason, int $actorId): LedgerEntry
    {
        if ($entry->reverses_entry_id !== null) {
            throw new RuntimeException('یک ردیف اصلاحی را نمی‌توان دوباره معکوس کرد.');
        }
        if (LedgerEntry::query()->where('reverses_entry_id', $entry->id)->exists()) {
            throw new RuntimeException('این ردیف قبلاً معکوس شده است.');
        }
        $rev = $this->post($entry->wallet, -$entry->amount, 'reversal', 'reversal:'.$entry->id,
            'اصلاح ردیف #'.$entry->id, $reason, $actorId, null, $entry->id);
        $this->audit->log('wallets.reversal', $rev, 'معکوس کردن ردیف دفتر کل #'.$entry->id, ['amount' => [$entry->amount, $rev->amount]], $reason);

        return $rev;
    }

    /**
     * Verify the cached balance against the ledger and the running balance_after chain.
     *
     * @return array{ok:bool, cached:int, ledger_sum:int, chain_ok:bool, entries:int}
     */
    public function reconcile(Wallet $wallet): array
    {
        $sum = (int) LedgerEntry::query()->where('wallet_id', $wallet->id)->sum('amount');
        $running = 0;
        $chainOk = true;
        $count = 0;
        LedgerEntry::query()->where('wallet_id', $wallet->id)->orderBy('id')->chunk(500, function ($rows) use (&$running, &$chainOk, &$count) {
            foreach ($rows as $r) {
                $running += $r->amount;
                $count++;
                if ($r->balance_after !== $running || $running < 0) {
                    $chainOk = false;
                }
            }
        });
        $cached = (int) Wallet::query()->whereKey($wallet->id)->value('balance');

        return ['ok' => $chainOk && $sum === $cached, 'cached' => $cached, 'ledger_sum' => $sum, 'chain_ok' => $chainOk, 'entries' => $count];
    }

    private function assertSameRequest(LedgerEntry $existing, Wallet $wallet, int $amount): LedgerEntry
    {
        if ($existing->wallet_id !== $wallet->id || $existing->amount !== $amount) {
            throw new RuntimeException('کلید تراکنش تکراری با مبلغ یا کیف پول متفاوت استفاده شده است.');
        }

        return $existing;
    }
}
