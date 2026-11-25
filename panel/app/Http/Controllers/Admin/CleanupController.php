<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ibs\ExpiredCleanup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class CleanupController extends Controller
{
    public function __construct(private ExpiredCleanup $cleanup) {}

    public function show(Request $request): View
    {
        $this->authorize('accounts.delete');
        $days = max(ExpiredCleanup::MIN_DAYS, (int) $request->query('days', 60));
        $cutoff = $this->cleanup->cutoffFor($days);

        return view('accounts.cleanup', [
            'days' => $days,
            'cutoff' => $cutoff,
            'count' => $this->cleanup->query($cutoff)->count(),
            'sample' => $this->cleanup->query($cutoff)->orderBy('expires_at')->limit(15)->get(),
            'state' => $this->cleanup->state(),
        ]);
    }

    /** Sensitive: recent password confirmation (route group) + reason + exact count typed back. */
    public function start(Request $request): RedirectResponse
    {
        $this->authorize('accounts.delete');
        $d = $request->validate([
            'days' => ['required', 'integer', 'min:'.ExpiredCleanup::MIN_DAYS, 'max:3650'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
            'confirm_count' => ['required', 'integer'],
        ], [], ['days' => 'تعداد روز', 'reason' => 'دلیل', 'confirm_count' => 'تعداد تأیید']);
        $count = $this->cleanup->query($this->cleanup->cutoffFor((int) $d['days']))->count();
        if ((int) $d['confirm_count'] !== $count) {
            return back()->withInput()->with('error', 'عدد تأیید با تعداد اکانت‌های قابل حذف ('.fa($count).') برابر نیست.');
        }
        try {
            $this->cleanup->start((int) $d['days'], $d['reason'], $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounts.cleanup', ['days' => $d['days']])->with('success', 'حذف گروهی شروع شد و در پس‌زمینه (Cron) ادامه پیدا می‌کند.');
    }

    public function continue(): RedirectResponse
    {
        $this->authorize('accounts.delete');
        $st = $this->cleanup->step(microtime(true) + 20);

        return back()->with('success', 'تا اینجا '.fa((int) ($st['deleted'] ?? 0)).' اکانت حذف شد.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->authorize('accounts.delete');
        $this->cleanup->cancel($request->user()->id);

        return back()->with('success', 'حذف گروهی متوقف شد.');
    }
}
