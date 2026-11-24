<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ibs\ErrorMessages;
use App\Ibs\IbsException;
use App\Ibs\IbsUnavailableException;
use App\Models\ProvisioningJob;
use App\Services\Ibs\Provisioning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ProvisioningJobsController extends Controller
{
    public function index(): View
    {
        $this->authorize('accounts.view');

        return view('accounts.jobs', [
            'open' => ProvisioningJob::query()->with('creator')->whereIn('status', ProvisioningJob::OPEN)->latest()->get(),
            'recent' => ProvisioningJob::query()->with('creator', 'account')->whereNotIn('status', ProvisioningJob::OPEN)->latest()->limit(30)->get(),
        ]);
    }

    public function retry(ProvisioningJob $job, Provisioning $p): RedirectResponse
    {
        $this->authorize('accounts.create');
        try {
            $job = $p->retry($job);
        } catch (RuntimeException $e) {
            return back()->with('error', $e instanceof IbsException || $e instanceof IbsUnavailableException ? ErrorMessages::for($e) : $e->getMessage());
        }

        return $job->status === 'completed'
            ? redirect()->route('accounts.show', $job->vpn_account_id)->with('success', 'ساخت اکانت «'.$job->username.'» کامل شد.')
            : back()->with('error', 'هنوز کامل نشد: '.$job->last_error);
    }

    public function resolve(Request $request, ProvisioningJob $job, Provisioning $p): RedirectResponse
    {
        $this->authorize('accounts.sensitive');
        $note = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];
        if (! in_array($job->status, ProvisioningJob::OPEN, true)) {
            return back()->with('error', 'این مورد باز نیست.');
        }
        $p->resolveManually($job, $note);

        return back()->with('success', 'مورد بسته شد.');
    }
}
