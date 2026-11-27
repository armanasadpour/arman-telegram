<?php

namespace Tests\Feature;

use App\Models\ConfigFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfigFilesTest extends TestCase
{
    private const OVPN = "client\ndev tun\nproto tcp-client\nremote ir11.example.com\nport 1194\nauth-user-pass\n<key>\nSECRET-KEY\n</key>\n";

    public function test_upload_download_and_public_link(): void
    {
        $admin = $this->superAdmin();
        $file = UploadedFile::fake()->createWithContent('ir11.ovpn', self::OVPN);
        $this->actingAs($admin)->post('/config-files', ['name' => 'Server11', 'file' => $file])->assertRedirect('/config-files')->assertSessionHas('success');

        $f = ConfigFile::query()->sole();
        $this->assertSame('Server11.ovpn', $f->fileName());
        $this->assertStringStartsWith('setenv FRIENDLY_NAME "Server11"', $f->content);
        $this->assertStringNotContainsString('SECRET-KEY', (string) DB::table('config_files')->value('content'));   // stored encrypted

        $page = $this->actingAs($admin)->get('/config-files')->assertOk()->assertSee('Server11.ovpn')->assertSee('کپی لینک');
        $page->assertSee($f->publicUrl());

        auth()->logout();
        $res = $this->get('/f/'.$f->token)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Server11.ovpn"');
        $this->assertStringContainsString('remote ir11.example.com', $res->getContent());
        $this->assertSame(1, $f->fresh()->downloads);
        $this->get('/f/'.str_repeat('a', 40))->assertNotFound();

        // rename keeps the link and updates the profile name inside the file
        $this->actingAs($admin)->put('/config-files/'.$f->id, ['name' => 'Server12'])->assertSessionHas('success');
        $f->refresh();
        $this->assertSame(1, substr_count($f->content, 'FRIENDLY_NAME'));
        $this->assertStringContainsString('"Server12"', $f->content);
        $this->get('/f/'.$f->token)->assertHeader('Content-Disposition', 'attachment; filename="Server12.ovpn"');

        // new link invalidates the old one
        $old = $f->token;
        $this->actingAs($admin)->post('/config-files/'.$f->id.'/new-link')->assertSessionHas('success');
        auth()->logout();
        $this->get('/f/'.$old)->assertNotFound();
        $this->get('/f/'.$f->fresh()->token)->assertOk();
    }

    public function test_validation_and_permissions(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->from('/config-files')->post('/config-files', ['name' => 'سرور', 'content' => self::OVPN])->assertSessionHasErrors('name');
        $this->actingAs($admin)->from('/config-files')->post('/config-files', ['name' => 'X', 'content' => 'hello world'])->assertSessionHasErrors('file');
        $this->assertSame(0, ConfigFile::query()->count());

        $viewer = $this->userWith(['accounts.view']);
        $this->actingAs($viewer)->get('/config-files')->assertOk()->assertDontSee('افزودن فایل کانفیگ');
        $this->actingAs($viewer)->post('/config-files', ['name' => 'X', 'content' => self::OVPN])->assertForbidden();
        $this->actingAs($this->userWith([]))->get('/config-files')->assertForbidden();
    }
}
