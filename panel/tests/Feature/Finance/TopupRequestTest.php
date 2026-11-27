<?php

namespace Tests\Feature\Finance;

use App\Models\Payment;
use App\Services\Finance\WalletService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TopupRequestTest extends ResellerTestCase
{
    public function test_reseller_requests_topup_with_receipt_and_admin_approves(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $r = $this->reseller();

        // admin defines a bank card (Persian digits, spaces and IR are cleaned)
        $this->actingAs($admin)->put('/accounting/cards', ['cards' => [['number' => '۶۰۳۷ ۹۹۱۱ ۲۳۴۵ ۶۷۸۹', 'sheba' => 'IR12 0170 0000 0012 3456 7890 01', 'holder' => 'آرمان', 'bank' => 'ملی']]])
            ->assertSessionHas('success');
        $page = $this->actingAs($r->user)->get('/r/topup')->assertOk();
        $page->assertSee('data-copy-text="6037991123456789"', false)->assertSee('data-copy-text="120170000000123456789001"', false)->assertSee('theme-reseller', false);

        // request: amount with separators, receipt image required
        $this->actingAs($r->user)->post('/r/topup/card', ['amount_toman' => '500,000'])->assertSessionHasErrors('receipt');
        $this->actingAs($r->user)->post('/r/topup/card', ['amount_toman' => '۵۰۰,۰۰۰', 'receipt' => UploadedFile::fake()->image('fish.jpg')])->assertSessionHas('success');
        $p = Payment::query()->sole();
        $this->assertSame(['review', 5_000_000, 'card', 'topup'], [$p->status, $p->amount_rial, $p->method, $p->purpose]);
        Storage::disk('local')->assertExists($p->receipt_path);
        $this->assertSame(0, $this->bal($r));

        $this->actingAs($admin)->getJson('/notices/poll?after=0')->assertJsonPath('unread', 1)->assertJsonPath('new.0.level', 'info');
        $this->actingAs($this->userWith(['payments.view']))->get('/notices')->assertOk()->assertSee('درخواست شارژ حساب');
        $this->actingAs($admin)->get('/accounting/topups')->assertOk()->assertSee($r->name)->assertSee('تأیید و شارژ کیف پول');
        $this->actingAs($admin)->confirmed()->post('/payments/'.$p->id.'/approve')->assertSessionHas('success');
        $this->actingAs($admin)->confirmed()->post('/payments/'.$p->id.'/approve');   // twice: still one credit
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(5_000_000, $this->bal($r));
        $this->actingAs($r->user)->get('/r/ledger')->assertOk()->assertSee('شارژ کیف پول');

        $this->actingAs($r->user)->get('/accounting/topups')->assertForbidden();
    }

    public function test_no_card_no_request_and_money_fields_accept_separators(): void
    {
        Storage::fake('local');
        $r = $this->reseller();
        $this->actingAs($r->user)->post('/r/topup/card', ['amount_toman' => '100000', 'receipt' => UploadedFile::fake()->image('f.jpg')])->assertSessionHas('error');
        $this->assertSame(0, Payment::query()->count());

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/resellers')->assertOk()->assertSee('ویرایش')->assertSee('همکار جدید');
        $this->actingAs($admin)->confirmed()->post('/resellers/'.$r->id.'/adjust', ['direction' => 'credit', 'amount_toman' => '1,250,000', 'op_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(12_500_000, $this->bal($r));
    }

    private function bal($r): int
    {
        return (int) app(WalletService::class)->walletFor('reseller', $r->id)->fresh()->balance;
    }
}
