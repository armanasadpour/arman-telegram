<?php

namespace Tests\Feature\Mikrotik;

use App\Bots\Engine;
use App\Models\MtAccount;
use App\Models\VpnAccount;
use Tests\Feature\Bots\BotTestCase;

class BotStaffCreateTest extends BotTestCase
{
    use MikrotikFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMikrotik();
        $this->bot();
    }

    private function login(string $chat = '600'): void
    {
        $this->superAdmin(['username' => 'boss', 'name' => 'مدیر']);
        $this->tg('/start', chat: $chat);
        $this->tg(Engine::PARTNER_LOGIN, chat: $chat);
        $this->tg('boss', chat: $chat);
        $this->tg('wrong-pass', chat: $chat);
        $this->assertStringContainsString('درست نیست', $this->lastText());
        $this->tg(Engine::PARTNER_LOGIN, chat: $chat);
        $this->tg('boss', chat: $chat);
        $this->tg('Password12345', chat: $chat);
        $this->assertStringContainsString('خوش آمدید', $this->lastText());
        $this->assertNotNull($this->request('/deleteMessage'), 'password message removed');
        $this->assertContains(Engine::CREATE, $this->lastKeyboard());
    }

    public function test_admin_logs_in_with_password_and_creates_wireguard_with_qr(): void
    {
        $this->login();
        $this->tg(Engine::CREATE, chat: '600');
        $this->assertSame(['🔐 OpenVPN', '📡 L2TP', '🛡 WireGuard', Engine::CANCEL], $this->lastKeyboard());
        $this->tg('🛡 WireGuard', chat: '600');
        $this->tg(collect($this->lastKeyboard())->first(fn ($l) => str_contains($l, 'گروه WG')), chat: '600');
        $this->tg('30', chat: '600');
        $this->tg(Engine::UNLIMITED, chat: '600');
        $this->tg('رضا موبایل', chat: '600');
        $a = MtAccount::query()->firstOrFail();
        $this->assertSame(['wireguard', 'رضا موبایل', 30, null], [$a->protocol, $a->comment, $a->duration_days, $a->volume_limit_bytes]);
        $photo = $this->request('/sendPhoto');
        $this->assertNotNull($photo, 'QR sent as a photo');
        $this->assertStringContainsString('PrivateKey = '.$a->wg_private_key, $this->lastText());
    }

    public function test_admin_creates_l2tp_and_openvpn(): void
    {
        $this->syncCatalog();
        $this->login('601');
        $this->tg(Engine::CREATE, chat: '601');
        $this->tg('📡 L2TP', chat: '601');
        $this->tg(collect($this->lastKeyboard())->first(fn ($l) => str_contains($l, 'گروه L2TP')), chat: '601');
        $this->tg('۶۰', chat: '601');
        $this->tg('20', chat: '601');
        $this->tg('علی', chat: '601');
        $a = MtAccount::query()->where('protocol', 'l2tp')->firstOrFail();
        $this->assertSame([60, 20 * 1073741824], [$a->duration_days, $a->volume_limit_bytes]);
        $this->assertStringContainsString('رمز: '.$a->password, $this->lastText());

        $this->tg(Engine::CREATE, chat: '601');
        $this->tg('🔐 OpenVPN', chat: '601');
        $this->tg(collect($this->lastKeyboard())->first(fn ($l) => str_contains($l, 'یک‌ماهه')), chat: '601');
        $this->tg('حسن', chat: '601');
        $v = VpnAccount::query()->firstOrFail();
        $this->assertStringContainsString('نام کاربری: '.$v->username, $this->lastText());
        $this->assertStringContainsString('حسن', (string) $v->comment);
    }

    public function test_customers_and_resellers_do_not_get_create(): void
    {
        $this->tg('/start', chat: '602');
        $this->assertNotContains(Engine::CREATE, $this->lastKeyboard());
        $this->tg(Engine::CREATE, chat: '602');
        $this->assertSame(0, MtAccount::query()->count());
    }
}
