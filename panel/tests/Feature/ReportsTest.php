<?php

namespace Tests\Feature;

use App\Models\ResellerSale;
use App\Services\Finance\ResellerSales;
use App\Services\Reports\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Finance\ResellerTestCase;

class ReportsTest extends ResellerTestCase
{
    private function sale($r, int $salePrice = 1_200_000, ?string $name = null): ResellerSale
    {
        return app(ResellerSales::class)->sell($r, $this->newPkg, (string) Str::uuid(), $salePrice, ['customer_name' => $name], null, $r->user_id)['sale'];
    }

    public function test_days_are_grouped_in_iran_time_and_totals_add_up(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 22:00:00', 'UTC'));   // 01:30 on 21 Nov in Tehran
        $r = $this->reseller(creditRial: 5_000_000);
        $this->sale($r);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));
        $this->sale($r, 1_500_000);
        $rep = app(Reports::class);
        $day = CarbonImmutable::parse('2026-11-21', 'Asia/Tehran');
        $d = $rep->daily($day->subDay(), $day);
        $this->assertSame(['2026-11-20' => 0, '2026-11-21' => 2_000_000], $d['revenue']);
        $this->assertSame(2, $d['count']['2026-11-21']);
        $t = $rep->totals($day, $day);
        $this->assertSame(2, $t['sales_count']);
        $this->assertSame(2_000_000, $t['sales_rial']);
        $this->assertSame(700_000, $t['reseller_profit']);
        $perf = $rep->resellers($day, $day)->first();
        $this->assertSame(2, $perf['sales']);
        $this->assertSame(3_000_000, $perf['balance']);
    }

    public function test_reports_page_dashboard_and_permissions(): void
    {
        $r = $this->reseller(creditRial: 5_000_000);
        $this->sale($r);
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/reports')->assertOk()->assertSee('درآمد فروش')->assertSee('<svg', false)->assertSee($r->name);
        $this->actingAs($admin)->get('/reports?from=۱۴۰۵/۰۱/۰۱&to=۱۴۰۵/۱۲/۲۹')->assertOk();
        $this->actingAs($admin)->get('/reports?from=garbage')->assertOk();
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('فروش امروز')->assertSee('۱۰۰٬۰۰۰ تومان');
        $this->actingAs($this->userWith(['dashboard.view']))->get('/')->assertOk()->assertDontSee('فروش امروز');
        $this->actingAs($this->userWith(['dashboard.view']))->get('/reports')->assertForbidden();
        $this->actingAs($this->userWith(['reports.view']))->get('/reports/export/sales')->assertForbidden();
        $this->actingAs($r->user)->get('/reports')->assertForbidden();
    }

    public function test_csv_export_is_excel_friendly_and_safe(): void
    {
        $r = $this->reseller(creditRial: 5_000_000);
        $this->sale($r, 1_200_000, '=HYPERLINK("http://evil")');
        $admin = $this->superAdmin();
        $res = $this->actingAs($admin)->get('/reports/export/sales');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('قیمت خرید همکار (تومان)', $csv);
        $this->assertStringContainsString(',100000,120000,20000,', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'formula neutralised');
        $this->assertMatchesRegularExpression('#14\d\d/\d\d/\d\d \d\d:\d\d#', $csv, 'Jalali dates');
        $this->assertDatabaseHas('audit_logs', ['action' => 'reports.export']);
        foreach (['orders', 'payments', 'ledger', 'accounts', 'resellers'] as $t) {
            $this->actingAs($admin)->get('/reports/export/'.$t)->assertOk();
        }
        $this->actingAs($admin)->get('/reports/export/users')->assertNotFound();
    }

    public function test_reseller_exports_only_own_data(): void
    {
        $a = $this->reseller('ra', creditRial: 5_000_000);
        $b = $this->reseller('rb', creditRial: 5_000_000);
        $this->sale($a, 1_000_000, 'مشتری الف');
        $this->sale($b, 1_000_000, 'مشتری ب');
        $this->actingAs($a->user)->get('/r')->assertOk()->assertSee('سود ۳۰ روز اخیر');
        $csv = $this->actingAs($a->user)->get('/r/export/sales')->streamedContent();
        $this->assertStringContainsString('مشتری الف', $csv);
        $this->assertStringNotContainsString('مشتری ب', $csv);
        $ledger = $this->actingAs($a->user)->get('/r/export/ledger')->streamedContent();
        $this->assertSame(3, substr_count($ledger, "\n"), 'header + seed credit + purchase of A only');
        $this->actingAs($a->user)->get('/r/export/payments')->assertNotFound();
    }
}
