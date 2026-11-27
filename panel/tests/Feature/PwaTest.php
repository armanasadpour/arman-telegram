<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PwaTest extends TestCase
{
    public function test_manifest_icons_and_settings(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('display', 'standalone')->assertJsonPath('short_name', 'پنل');
        $this->get('/pwa/icon-192.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/pwa/icon-77.png')->assertNotFound();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/')->assertSee('manifest.webmanifest')->assertSee('manifest.webmanifest');
        $this->actingAs($admin)->get('/settings/app')->assertOk()->assertSee('برنامه نصبی');
        $img = imagecreatetruecolor(300, 200);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        $file = UploadedFile::fake()->createWithContent('logo.png', $png);
        $this->actingAs($admin)->from('/settings/app')->put('/settings/app', ['name' => 'پنل آرمان', 'short_name' => 'آرمان', 'theme' => '#aa2244',
            'background' => '#ffffff', 'start' => 'accounts', 'icon' => $file])->assertSessionHas('success');
        $this->get('/manifest.webmanifest')->assertJsonPath('name', 'پنل آرمان')->assertJsonPath('theme_color', '#aa2244');
        $size = getimagesizefromstring($this->get('/pwa/icon-512.png')->getContent());
        $this->assertSame([512, 512], [$size[0], $size[1]]);
        $this->actingAs($this->userWith(['settings.view']))->put('/settings/app', ['name' => 'x', 'short_name' => 'x', 'theme' => '#000000', 'background' => '#000000', 'start' => 'dashboard'])->assertForbidden();
        @array_map('unlink', glob(storage_path('app/pwa/icon-*.png')));
    }
}
