<?php

namespace App\Payments;

use App\Bots\Notifier;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\Wallet;
use App\Services\AuditLogger;
use App\Services\Finance\CustomerOrders;
use App\Services\Finance\WalletService;
use App\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Payment lifecycle. markPaid() is the only place money enters a wallet: under a row lock, once, with
 * ledger key "pay:{id}". Everything else (bank callbacks, cron re-verification, admin approval, USDT checks)
 * funnels into it, so a payment can never be credited twice.
 */
class Payments
{
    public const MIN_RIAL = 10_000;       // 1,000 toman

    public const MAX_RIAL = 2_000_000_000;

    public function __construct(
        private WalletService $wallets,
        private Settings $settings,
        private AuditLogger $audit,
        private UsdtVerifier $usdt,
        private Notifier $notifier,
    ) {}

    public function gateway(string $method): Gateway
    {
        return match ($method) {
            'zarinpal' => app(Zarinpal::class),
            'sep' => app(Sep::class),
            'mellat' => app(Mellat::class),
            'sadad' => app(Sadad::class),
            default => throw new RuntimeException('روش پرداخت نامعتبر است.'),
        };
    }

    /** Methods the admin enabled and configured. @return array<string,string> */
    public function enabledMethods(): array
    {
        $out = [];
        foreach (Payment::METHODS as $m => $label) {
            if ($this->settings->get('pay.'.$m.'.enabled') !== '1') {
                continue;
            }
            $ok = match ($m) {
                'card' => $this->cards() !== [],
                'usdt' => $this->usdtNetworks() !== [] && $this->usdt->usdtFor(10_000_000) !== null,
                default => $this->gateway($m)->isConfigured(),
            };
            if ($ok) {
                $out[$m] = $label;
            }
        }

        return $out;
    }

    /** @return list<array{number:string,holder:string,bank:string}> */
    public function cards(): array
    {
        return array_values(array_filter((array) json_decode((string) $this->settings->get('pay.card.cards'), true), fn ($c) => ! empty($c['number'])));
    }

    /** @return array<string,string> network => address */
    public function usdtNetworks(): array
    {
        $out = [];
        foreach (array_keys(Payment::NETWORKS) as $n) {
            if ($this->settings->get('pay.usdt.'.$n.'.enabled') === '1' && $this->usdt->address($n) !== '') {
                $out[$n] = $this->usdt->address($n);
            }
        }

        return $out;
    }

    public function create(string $method, Wallet $wallet, int $amountRial, array $attrs = []): Payment
    {
        if (! isset($this->enabledMethods()[$method])) {
            throw new RuntimeException('این روش پرداخت فعال نیست.');
        }
        if ($amountRial < self::MIN_RIAL || $amountRial > self::MAX_RIAL) {
            throw new RuntimeException('مبلغ باید بین '.fa_money(self::MIN_RIAL).' و '.fa_money(self::MAX_RIAL).' باشد.');
        }
        $p = new Payment(['uuid' => (string) Str::uuid(), 'method' => $method, 'wallet_id' => $wallet->id, 'amount_rial' => $amountRial] + $attrs);
        if ($method === 'usdt') {
            $net = (string) ($attrs['usdt_network'] ?? '');
            if (! isset($this->usdtNetworks()[$net])) {
                throw new RuntimeException('این شبکه فعال نیست.');
            }
            $p->usdt_amount = $this->usdt->usdtFor($amountRial);
        }
        $p->save();

        return $p;
    }

    public function callbackUrl(Payment $p): string
    {
        return route('pay.callback', $p->uuid);
    }

    /** Register with the bank and return where to send the payer. */
    public function start(Payment $p): GatewayRedirect
    {
        if (! $p->isOnline() || ! in_array($p->status, ['pending', 'redirected'], true)) {
            throw new RuntimeException('این پرداخت قابل شروع نیست.');
        }
        $redirect = $this->gateway($p->method)->start($p, $this->callbackUrl($p));
        $p->forceFill(['status' => 'redirected', 'meta' => ($p->meta ?? []) + ['started_at' => now()->toIso8601String()]])->save();

        return $redirect;
    }

    /** Bank callback (or a cron retry with the stored callback). Safe to call any number of times. */
    public function handleCallback(Payment $p, array $callback): Payment
    {
        $callback = array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 300) : null, $callback);
        try {
            $p = $this->verifyLocked($p, $callback);
        } catch (RuntimeException $e) {
            $p->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();

            return $p;
        }

        return $this->afterPaid($p);
    }

    private function verifyLocked(Payment $p, array $callback): Payment
    {
        return DB::transaction(function () use ($p, $callback) {
            $p = Payment::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->isFinal() || ! $p->isOnline()) {
                return $p;
            }
            $p->meta = ($p->meta ?? []) + ['callback' => $callback];
            $res = $this->gateway($p->method)->verify($p, $p->meta['callback']);

            return $this->apply($p, $res, null);
        });
    }

    /** Cron: re-verify payments whose bank answer was uncertain. */
    public function reverifyStuck(): int
    {
        $n = 0;
        foreach (Payment::query()->where('status', 'redirected')->whereIn('method', Payment::ONLINE)->where('updated_at', '<', now()->subMinutes(2))->limit(20)->get() as $p) {
            if (isset($p->meta['callback'])) {
                $this->handleCallback($p, $p->meta['callback']);
                $n++;
            } elseif ($p->created_at->lt(now()->subHours(2))) {
                $p->forceFill(['status' => 'expired', 'error' => 'پرداخت‌کننده به سایت بازنگشت.'])->save();
            }
        }
        foreach (Payment::query()->where('status', 'pending')->where('created_at', '<', now()->subDay())->whereIn('method', [...Payment::ONLINE, 'usdt'])->limit(100)->get() as $p) {
            $p->forceFill(['status' => 'expired'])->save();
        }
        foreach (Payment::query()->where('method', 'usdt')->where('status', 'review')->whereNotNull('tx_hash')->where('updated_at', '<', now()->subMinutes(3))
            ->where('created_at', '>', now()->subDays(2))->limit(20)->get() as $p) {
            if (($p->meta['usdt_retry'] ?? true) !== false) {
                $this->checkUsdt($p);
                $n++;
            }
        }

        return $n;
    }

    /** Card-to-card: the payer reports the transfer; an admin must approve. */
    public function reportCard(Payment $p, string $note, ?string $receiptPath = null): Payment
    {
        if ($p->method !== 'card' || $p->status !== 'pending') {
            throw new RuntimeException('این پرداخت قابل ثبت نیست.');
        }
        $p->forceFill(['status' => 'review', 'payer_note' => mb_substr($note, 0, 300), 'receipt_path' => $receiptPath])->save();
        $this->notifier->toStaff('payments.sensitive', '🏦 واریز کارت‌به‌کارت '.fa_money($p->amount_rial).' — '.$p->payerLabel().' — منتظر بررسی در پنل (پرداخت #'.fa((string) $p->id).').');

        return $p;
    }

    /** USDT: the payer submits the transaction hash. */
    public function submitUsdtHash(Payment $p, string $hash): Payment
    {
        $hash = trim($hash);
        if ($p->method !== 'usdt' || ! in_array($p->status, ['pending', 'review'], true) || $p->tx_hash) {
            throw new RuntimeException('این پرداخت قابل ثبت نیست.');
        }
        if (! UsdtVerifier::validHash((string) $p->usdt_network, $hash)) {
            throw new RuntimeException('هش تراکنش برای شبکه '.(Payment::NETWORKS[$p->usdt_network] ?? '').' معتبر نیست.');
        }
        $hash = $p->usdt_network === 'trc20' ? strtolower($hash) : strtolower($hash);
        try {
            $p->forceFill(['tx_hash' => $hash, 'status' => 'review'])->save();
        } catch (QueryException) {
            throw new RuntimeException('این هش تراکنش قبلاً ثبت شده است.');
        }

        return $this->checkUsdt($p);
    }

    public function checkUsdt(Payment $p): Payment
    {
        $p = DB::transaction(function () use ($p) {
            $p = Payment::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->isFinal()) {
                return $p;
            }
            $res = $this->usdt->verify($p);
            if ($res->outcome === 'failed') {
                // A wrong hash is not final for the money: let an admin look, and stop automatic retries.
                $p->forceFill(['status' => 'review', 'error' => $res->error, 'meta' => ($p->meta ?? []) + ['usdt_retry' => false]])->save();

                return $p;
            }

            return $this->apply($p, $res, null);
        });
        if ($p->status === 'review' && ! isset($p->meta['notified'])) {
            $p->forceFill(['meta' => ($p->meta ?? []) + ['notified' => true]])->save();
            $this->notifier->toStaff('payments.sensitive', '💵 پرداخت تتر '.fa_money($p->amount_rial).' — '.$p->payerLabel().' — نیاز به بررسی: '.($p->error ?? 'در انتظار تأیید شبکه').' (پرداخت #'.fa((string) $p->id).')');
        }

        return $this->afterPaid($p);
    }

    /** Admin approval of a card-to-card or USDT payment under review. */
    public function approve(Payment $p, string $reason, int $actorId, ?string $ref = null): Payment
    {
        $p = DB::transaction(function () use ($p, $reason, $actorId, $ref) {
            $p = Payment::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->status === 'paid') {
                return $p;
            }
            if ($p->status !== 'review' || $p->isOnline()) {
                throw new RuntimeException('فقط پرداخت‌های کارت‌به‌کارت و تتر که پرداخت‌کننده اطلاعات واریز را ثبت کرده، قابل تأیید دستی هستند.');
            }
            $p->forceFill(['reviewed_by' => $actorId, 'review_reason' => mb_substr($reason, 0, 300)]);

            return $this->apply($p, VerifyResult::paid($ref ?: ($p->tx_hash ?? 'manual-'.$p->id)), $actorId);
        });
        $this->audit->log('payments.approve', $p, 'تأیید پرداخت #'.$p->id.' ('.$p->methodLabel().') '.fa_money($p->amount_rial), ['status' => ['review', 'paid']], $reason);

        return $this->afterPaid($p);
    }

    public function reject(Payment $p, string $reason, int $actorId): Payment
    {
        $p = DB::transaction(function () use ($p, $reason, $actorId) {
            $p = Payment::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->status !== 'review' && ! ($p->status === 'pending' && ! $p->isOnline())) {
                throw new RuntimeException('این پرداخت در وضعیت قابل رد نیست.');
            }
            $p->forceFill(['status' => 'rejected', 'reviewed_by' => $actorId, 'review_reason' => mb_substr($reason, 0, 300)])->save();

            return $p;
        });
        $this->audit->log('payments.reject', $p, 'رد پرداخت #'.$p->id.' ('.$p->methodLabel().')', ['status' => ['review', 'rejected']], $reason);
        if ($p->order && $p->order->status === 'awaiting_payment') {
            $p->order->forceFill(['status' => 'cancelled', 'error' => 'پرداخت رد شد'])->save();
        }
        $this->notifyPayer($p, '❌ پرداخت '.fa_money($p->amount_rial).' ('.$p->methodLabel().') تأیید نشد. دلیل: '.$reason);

        return $p;
    }

    /** Must run inside a transaction holding the payment row lock. */
    private function apply(Payment $p, VerifyResult $res, ?int $actorId): Payment
    {
        if ($res->outcome === 'uncertain') {
            $p->forceFill(['error' => $res->error])->save();

            return $p;
        }
        if ($res->outcome === 'failed') {
            $p->forceFill(['status' => 'failed', 'error' => $res->error])->save();

            return $p;
        }
        $entry = $this->wallets->post($p->wallet, $p->amount_rial, 'topup', 'pay:'.$p->id,
            'شارژ از '.$p->methodLabel().' — پرداخت #'.$p->id, $p->review_reason, $actorId, $p);
        try {
            $p->forceFill(['status' => 'paid', 'paid_at' => now(), 'gateway_ref' => $res->ref, 'card_pan' => $res->cardPan ? mb_substr($res->cardPan, 0, 32) : null,
                'ledger_entry_id' => $entry->id, 'error' => null])->save();
        } catch (QueryException) {
            // Same bank reference already used by another payment: never credit twice.
            throw new RuntimeException('این شماره مرجع بانکی قبلاً برای پرداخت دیگری ثبت شده است.');
        }

        return $p;
    }

    /** After commit: tell the payer, and continue the order this payment was for. */
    private function afterPaid(Payment $p): Payment
    {
        if ($p->status !== 'paid' || ($p->meta['after_paid'] ?? false)) {
            return $p;
        }
        $p->forceFill(['meta' => ($p->meta ?? []) + ['after_paid' => true]])->save();
        $order = $p->order;
        if ($order) {
            app(CustomerOrders::class)->fulfil($order);
        } else {
            $this->notifyPayer($p, '✅ پرداخت '.fa_money($p->amount_rial).' ('.$p->methodLabel().') تأیید شد و کیف پول شما شارژ شد. موجودی: '.fa_money($p->wallet->fresh()->balance));
        }

        return $p;
    }

    private function notifyPayer(Payment $p, string $text): void
    {
        $w = $p->wallet;
        if ($w?->owner_type === 'reseller') {
            $uid = Reseller::query()->whereKey($w->owner_id)->value('user_id');
            $uid && $this->notifier->toUser($uid, $text);
        } elseif ($w?->owner_type === 'customer') {
            $this->notifier->toBotUser((int) $w->owner_id, $text);
        }
    }
}
