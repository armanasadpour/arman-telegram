<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PanelNotice;
use App\Services\Notices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Notification center: server / router / IBSng down and back up. */
class NoticesController extends Controller
{
    public function index(Request $request, Notices $notices): View
    {
        $this->authorize('system.view');
        $user = $request->user();
        $seen = $user->notices_seen_at;
        $user->forceFill(['notices_seen_at' => now()])->save();

        return view('notices.index', [
            'open' => $notices->openProblems(),
            'rows' => PanelNotice::query()->orderByDesc('id')->paginate(40),
            'seen' => $seen,
        ]);
    }

    /** Polled by the panel every minute: unread count + notices newer than ?after (for toasts / browser notifications). */
    public function poll(Request $request, Notices $notices): JsonResponse
    {
        $this->authorize('system.view');
        $after = (int) $request->query('after', 0);
        $new = $after > 0
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
