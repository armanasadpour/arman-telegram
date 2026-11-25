<?php

namespace Tests\Feature;

use App\Services\Updater;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class UpdaterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/upd-'.uniqid();
        File::ensureDirectoryExists($this->root.'/app');
        file_put_contents($this->root.'/.env', 'APP_KEY=keep-me');
        file_put_contents($this->root.'/app/Old.php', 'old');
        $u = new Updater;
        $u->root = $this->root;
        $this->app->instance(Updater::class, $u);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** @param array<string,string> $files */
    private function zip(array $files): UploadedFile
    {
        $path = sys_get_temp_dir().'/upd-'.uniqid().'.zip';
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE);
        foreach ($files as $n => $c) {
            $z->addFromString($n, $c);
        }
        $z->close();

        return new UploadedFile($path, 'vpn-panel-update.zip', 'application/zip', null, true);
    }

    private function manifest(string $from, array $deleted = []): string
    {
        return json_encode(['from' => $from, 'to' => '9.9.9', 'deleted' => $deleted]);
    }

    public function test_small_update_installs_files_removes_deleted_and_keeps_env_and_storage(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/system')->assertSee('بارگذاری به‌روزرسانی');
        $this->actingAs($admin)->get('/system/update')->assertOk()->assertSee(config('panel.version'));
        $zip = $this->zip(['update-manifest.json' => $this->manifest(config('panel.version'), ['app/Old.php']), 'config/panel.php' => "<?php return ['version' => '9.9.9'];",
            'app/New.php' => 'new', '.env' => 'APP_KEY=evil', 'storage/app/installed.lock' => 'x']);
        $this->actingAs($admin)->post('/system/update', ['package' => $zip])->assertRedirect(route('system.status'))->assertSessionHas('auto_upgrade');
        $this->assertSame('new', file_get_contents($this->root.'/app/New.php'));
        $this->assertFileDoesNotExist($this->root.'/app/Old.php');
        $this->assertSame('APP_KEY=keep-me', file_get_contents($this->root.'/.env'));
        $this->assertFileDoesNotExist($this->root.'/storage/app/installed.lock');
        $this->assertFileDoesNotExist($this->root.'/update-manifest.json');
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.update_installed']);
    }

    public function test_update_for_another_version_or_unsafe_paths_changes_nothing(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/system/update', ['package' => $this->zip(['update-manifest.json' => $this->manifest('0.0.1'), 'app/X.php' => 'x'])])
            ->assertSessionHas('error', fn ($m) => str_contains($m, '0.0.1'));
        $this->actingAs($admin)->post('/system/update', ['package' => $this->zip(['update-manifest.json' => $this->manifest(config('panel.version')), 'app/Y.php' => 'y', '../evil.php' => 'x'])])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'مسیر نامعتبر'));
        $this->actingAs($admin)->post('/system/update', ['package' => $this->zip(['hello.txt' => 'x'])])->assertSessionHas('error');
        $this->assertFileDoesNotExist($this->root.'/app/X.php');
        $this->assertFileDoesNotExist($this->root.'/app/Y.php');
        $this->assertFileDoesNotExist(dirname($this->root).'/evil.php');
    }

    public function test_full_package_and_only_super_admin(): void
    {
        $full = $this->zip(['artisan' => '#!php', 'vendor/autoload.php' => '<?php', 'config/panel.php' => "<?php return ['version' => '9.9.9'];", 'app/Full.php' => 'f']);
        $this->actingAs($this->userWith(['system.view']))->post('/system/update', ['package' => $full])->assertForbidden();
        $this->actingAs($this->userWith(['system.view']))->get('/system/update')->assertForbidden();
        $this->actingAs($this->superAdmin())->post('/system/update', ['package' => $full])->assertSessionHas('success', fn ($m) => str_contains($m, '9.9.9'));
        $this->assertFileExists($this->root.'/app/Full.php');
    }
}
