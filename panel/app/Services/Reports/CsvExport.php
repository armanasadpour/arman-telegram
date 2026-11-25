<?php

namespace App\Services\Reports;

use App\Models\CustomerOrder;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel-friendly CSV (UTF-8 with BOM, Jalali dates, toman amounts). Streamed in chunks, so large
 * ranges do not exhaust shared-hosting memory. Formula-looking cells are neutralised (CSV injection).
 */
class CsvExport
{
    public const TYPES = ['sales', 'orders', 'payments', 'ledger', 'accounts', 'resellers'];

    public function download(string $type, CarbonImmutable $from, CarbonImmutable $to, string $filename, ?int $resellerId = null): StreamedResponse
    {
        return response()->streamDownload(function () use ($type, $from, $to, $resellerId) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($this->rows($type, $from, $to, $resellerId) as $row) {
                fputcsv($out, array_map([$this, 'cell'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function cell(mixed $v): string
    {
        $s = (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $s) && ! is_numeric($s) ? "'".$s : $s;
    }

    private function d($dt): string
    {
        return $dt ? Jalali::toLatinDigits(jdate($dt, 'Y/m/d H:i')) : '';
    }

    private function t(?int $rial): string
    {
        return $rial === null ? '' : (string) intdiv($rial, 10);
    }

    /** @return iterable<list<string|int>> */
    public function rows(string $type, CarbonImmutable $from, CarbonImmutable $to, ?int $resellerId = null): iterable
    {
        switch ($type) {
            case 'sales':
                yield ['شناسه', 'زمان', 'همکار', 'کد همکار', 'بسته', 'نوع', 'اکانت', 'مشتری', 'تلفن مشتری', 'قیمت خرید همکار (تومان)', 'قیمت فروش (تومان)', 'سود (تومان)', 'وضعیت'];
                $q = ResellerSale::query()->with('reseller', 'package', 'account')->whereBetween('created_at', [$from, $to])->orderBy('id');
                if ($resellerId) {
                    $q->where('reseller_id', $resellerId);
                }
                foreach ($q->lazy(500) as $s) {
                    yield [$s->id, $this->d($s->created_at), $s->reseller?->name, $s->reseller?->code, $s->package?->name, $s->type, $s->account?->username,
                        $s->customer_name, $s->customer_phone, $this->t($s->cost_rial), $this->t($s->sale_price_rial), $this->t($s->profit_rial), ResellerSale::STATUS[$s->status][0] ?? $s->status];
                }
                break;
            case 'orders':
                yield ['شناسه', 'زمان', 'مشتری ربات', 'بسته', 'اکانت', 'مبلغ (تومان)', 'وضعیت', 'پرداخت'];
                foreach (CustomerOrder::query()->with('botUser.bot', 'package', 'account', 'payment')->whereBetween('created_at', [$from, $to])->orderBy('id')->lazy(500) as $o) {
                    yield [$o->id, $this->d($o->created_at), ($o->botUser?->bot?->label() ?? '').' '.($o->botUser?->first_name ?? $o->botUser?->chat_id), $o->package?->name,
                        $o->account?->username, $this->t($o->price_rial), CustomerOrder::STATUS[$o->status][0] ?? $o->status, $o->payment?->methodLabel()];
                }
                break;
            case 'payments':
                yield ['شناسه', 'زمان ایجاد', 'زمان پرداخت', 'پرداخت‌کننده', 'روش', 'مبلغ (تومان)', 'وضعیت', 'شماره مرجع', 'کارت', 'تتر', 'هش تراکنش'];
                foreach (Payment::query()->with('wallet')->whereBetween('created_at', [$from, $to])->orderBy('id')->lazy(500) as $p) {
                    yield [$p->id, $this->d($p->created_at), $this->d($p->paid_at), $p->payerLabel(), $p->methodLabel(), $this->t($p->amount_rial), Payment::STATUS[$p->status][0] ?? $p->status,
                        $p->gateway_ref, $p->card_pan, $p->usdt_amount ? rtrim(rtrim((string) $p->usdt_amount, '0'), '.').' '.$p->usdt_network : '', $p->tx_hash];
                }
                break;
            case 'ledger':
                yield ['شناسه', 'زمان', 'کیف پول', 'نوع', 'مبلغ (تومان)', 'موجودی بعد (تومان)', 'شرح', 'دلیل', 'انجام‌دهنده'];
                $q = LedgerEntry::query()->with('wallet', 'actor')->whereBetween('created_at', [$from, $to])->orderBy('id');
                if ($resellerId) {
                    $q->whereHas('wallet', fn ($w) => $w->where('owner_type', 'reseller')->where('owner_id', $resellerId));
                }
                foreach ($q->lazy(500) as $e) {
                    yield [$e->id, $this->d($e->created_at), ($e->wallet?->owner_type === 'reseller' ? 'همکار #' : 'مشتری #').$e->wallet?->owner_id, $e->typeLabel(),
                        $this->t($e->amount), $this->t($e->balance_after), $e->description, $e->reason, $e->actor?->name];
                }
                break;
            case 'accounts':
                yield ['شناسه', 'نام کاربری', 'گروه', 'وضعیت', 'انقضا', 'حجم باقی‌مانده (MB)', 'آنلاین', 'همکار', 'منبع', 'ساخت در پنل'];
                $q = VpnAccount::query()->with('reseller')->whereNull('ibs_deleted_at')->orderBy('id');
                if ($resellerId) {
                    $q->where('reseller_id', $resellerId);
                }
                foreach ($q->lazy(1000) as $a) {
                    yield [$a->id, $a->username, $a->group_name, VpnAccount::STATUS_LABELS[$a->status()][0] ?? $a->status(), $this->d($a->expires_at),
                        $a->remainingBytes() !== null ? (string) intdiv($a->remainingBytes(), 1048576) : '', $a->is_online ? 'بله' : 'خیر', $a->reseller?->code, $a->source, $this->d($a->created_at)];
                }
                break;
            case 'resellers':
                yield ['همکار', 'کد', 'وضعیت', 'تعداد فروش', 'مبلغ فروش (تومان)', 'سود همکار (تومان)', 'موجودی (تومان)', 'اکانت‌ها'];
                $local = fn ($u) => CarbonImmutable::parse($u)->setTimezone(config('app.display_timezone'));
                foreach (app(Reports::class)->resellers($local($from), $local($to)->subDay()) as $r) {
                    yield [$r['reseller']->name, $r['reseller']->code, $r['reseller']->status, $r['sales'], $this->t($r['rial']), $this->t($r['profit']), $this->t($r['balance']), $r['accounts']];
                }
                break;
        }
    }
}
