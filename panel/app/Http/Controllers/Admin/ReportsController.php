<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Reports\CsvExport;
use App\Services\Reports\Reports;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(private Reports $reports) {}

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} local days, max 366 */
    private function period(Request $request): array
    {
        [$defFrom, $defTo] = $this->reports->lastDays(30);
        $from = Jalali::parseDate($request->query('from')) ?? $defFrom;
        $to = Jalali::parseDate($request->query('to')) ?? $defTo;
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }

        return [$from, $to];
    }

    public function index(Request $request): View
    {
        $this->authorize('reports.view');
        [$from, $to] = $this->period($request);

        return view('reports.index', [
            'from' => $from, 'to' => $to,
            'daily' => $this->reports->daily($from, $to),
            'totals' => $this->reports->totals($from, $to),
            'resellers' => $this->reports->resellers($from, $to),
            'groups' => $this->reports->accountsByGroup(),
            'q' => ['from' => jdate($from, 'Y/m/d'), 'to' => jdate($to, 'Y/m/d')],
        ]);
    }

    public function export(Request $request, string $type, CsvExport $csv): StreamedResponse
    {
        $this->authorize('reports.export');
        abort_unless(in_array($type, CsvExport::TYPES, true), 404);
        [$from, $to] = $this->period($request);
        [$f, $t] = $this->reports->range($from, $to);
        app(AuditLogger::class)->log('reports.export', null, 'خروجی اکسل: '.$type.' ('.jdate($from, 'Y/m/d').' تا '.jdate($to, 'Y/m/d').')');

        return $csv->download($type, $f, $t, 'report-'.$type.'-'.str_replace('/', '', Jalali::toLatinDigits(jdate($from, 'Y/m/d'))).'-'.str_replace('/', '', Jalali::toLatinDigits(jdate($to, 'Y/m/d'))).'.csv');
    }
}
