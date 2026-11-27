<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PanelNotice;
use App\Services\Notices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Notification center: server / router / IBSng down and back up. */
class NoticesController extends Controller
{
    public function index(Request $request, Notices $notices): View
    {
        abort_unless($request->user()->can('system.view') || $request->user()->can('payments.view'), 403);
        $user = $request->user();
        $seen = $user->notices_seen_at;
        $user->forceFill(['notices_seen_at' => now()])->save();

        return view('notices.index', [
            'open' => $notices->openProblems(),
            'rows' => PanelNotice::query()->orderByDesc('id')->paginate(40),
            'seen' => $seen,
        ]);
    }

    /** Test notice: shows up right away as toast + sound + browser notification on this device. */
    public function test(Request $request, Notices $notices): RedirectResponse
    {
        abort_unless($request->user()->can('system.view') || $request->user()->can('payments.view'), 403);
        $notices->info('test:'.$request->user()->id, '🔔 اعلان آزمایشی', 'اگر این پیام را می‌بینید، اعلان‌های پنل درست کار می‌کند.', route('notices.index'));

        return back()->with('success', 'اعلان آزمایشی ساخته شد؛ چند ثانیه دیگر روی صفحه (و اگر اعلان مرورگر فعال است، روی گوشی) نشان داده می‌شود.')
            ->with('notice_test', $notices->lastId());
    }

    /** Polled by the panel every minute: unread count + notices newer than ?after (for toasts / browser notifications). */
    public function poll(Request $request, Notices $notices): JsonResponse
    {
        abort_unless($request->user()->can('system.view') || $request->user()->can('payments.view'), 403);
        $after = (int) $request->query('after', 0);
        $new = $request->has('after')
            ? PanelNotice::query()->where('id', '>', $after)->orderBy('id')->limit(5)->get(['id', 'level', 'title', 'body'])
            : collect();

        return response()->json([
            'unread' => $notices->unreadCount($request->user()),
            'last' => (int) PanelNotice::query()->max('id'),
            'problems' => $notices->openProblems()->count(),
            'new' => $new->map(fn ($n) => ['id' => $n->id, 'level' => $n->level, 'title' => $n->title, 'body' => $n->body])->values(),
        ])->header('Cache-Control', 'no-store');
    }
}
