<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Payments\Payments;
use App\Services\AuditLogger;
use App\Services\Finance\CustomerOrders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentsController extends Controller
{
    public function __construct(private Payments $payments) {}

    public function index(Request $request): View
    {
        $this->authorize('payments.view');
        $q = Payment::query()->with('wallet')->orderByDesc('id');
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($m = $request->query('method')) {
            $q->where('method', $m);
        }

        return view('payments.index', [
            'payments' => $q->paginate(25)->withQueryString(),
            'review' => Payment::query()->where('status', 'review')->count(),
            'today' => (int) Payment::query()->where('status', 'paid')->where('paid_at', '>=', now()->startOfDay())->sum('amount_rial'),
            'month' => (int) Payment::query()->where('status', 'paid')->where('paid_at', '>=', now()->subDays(30))->sum('amount_rial'),
            'orders' => CustomerOrder::query()->with('package', 'botUser', 'account')->whereIn('status', ['needs_review', 'pending'])->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function show(Payment $payment): View
    {
        $this->authorize('payments.view');

        return view('payments.show', ['p' => $payment->load('wallet', 'reviewer', 'order.package', 'order.account')]);
    }

    public function receipt(Payment $payment): StreamedResponse
    {
        $this->authorize('payments.view');
        abort_unless($payment->receipt_path && Storage::disk('local')->exists($payment->receipt_path), 404);

        return Storage::disk('local')->response($payment->receipt_path, null, ['Cache-Control' => 'no-store, private']);
    }

    public function approve(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('payments.sensitive');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300'], 'ref' => ['nullable', 'string', 'max:100']], [], ['reason' => 'توضیح', 'ref' => 'شماره مرجع']);
        try {
            $this->payments->approve($payment, $d['reason'], $request->user()->id, $d['ref'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'پرداخت تأیید و کیف پول شارژ شد.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('payments.sensitive');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], [], ['reason' => 'دلیل'])['reason'];
        try {
            $this->payments->reject($payment, $reason, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'پرداخت رد شد.');
    }

    public function recheck(Payment $payment): RedirectResponse
    {
        $this->authorize('payments.view');
        if ($payment->method === 'usdt' && $payment->tx_hash) {
            $payment = $this->payments->checkUsdt($payment);
        } elseif ($payment->isOnline() && isset($payment->meta['callback'])) {
            $payment = $this->payments->handleCallback($payment, $payment->meta['callback']);
        }

        return back()->with('success', 'وضعیت: '.(Payment::STATUS[$payment->status][0] ?? $payment->status).($payment->error ? ' — '.$payment->error : ''));
    }

    public function refundOrder(Request $request, CustomerOrder $order, CustomerOrders $orders): RedirectResponse
    {
        $this->authorize('payments.sensitive');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];
        try {
            $orders->adminRefund($order, $reason, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        app(AuditLogger::class)->log('payments.order_refund', $order, 'برگشت سفارش مشتری #'.$order->id.' به کیف پول مشتری', [], $reason);

        return back()->with('success', 'مبلغ سفارش به کیف پول مشتری برگشت.');
    }
}
