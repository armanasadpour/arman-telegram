<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConfigFile;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Client config files (.ovpn) kept in the panel: upload, download, secret direct link to send to customers. */
class ConfigFilesController extends Controller
{
    private const MAX_KB = 512;

    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('accounts.view');

        return view('config-files.index', ['files' => ConfigFile::query()->with('creator')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $data = $this->validated($request, true);
        $f = ConfigFile::query()->create($data + ['created_by' => $request->user()->id]);
        $this->audit->log('config_files.create', $f, 'افزودن فایل کانفیگ «'.$f->name.'»');

        return redirect()->route('config-files.index')->with('success', 'فایل «'.$f->name.'» ذخیره شد. لینک مستقیم آن آماده است.');
    }

    public function update(Request $request, ConfigFile $file): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $data = $this->validated($request, false, $file);
        $old = $file->name;
        $file->fill($data)->save();
        $this->audit->log('config_files.update', $file, 'ویرایش فایل کانفیگ «'.$old.'»'.($old !== $file->name ? ' ← «'.$file->name.'»' : '').(isset($data['content']) && $request->hasFile('file') ? ' (فایل جایگزین شد)' : ''));

        return redirect()->route('config-files.index')->with('success', 'فایل «'.$file->name.'» به‌روز شد. لینک قبلی همچنان کار می‌کند و فایل جدید را می‌دهد.');
    }

    public function newLink(ConfigFile $file): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $file->forceFill(['token' => ConfigFile::newToken()])->save();
        $this->audit->log('config_files.relink', $file, 'ساخت لینک جدید برای فایل کانفیگ «'.$file->name.'» (لینک قبلی باطل شد)');

        return back()->with('success', 'لینک جدید ساخته شد؛ لینک قبلی دیگر کار نمی‌کند.');
    }

    public function destroy(ConfigFile $file): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $this->audit->log('config_files.delete', $file, 'حذف فایل کانفیگ «'.$file->name.'»');
        $file->delete();

        return back()->with('success', 'فایل حذف شد و لینک آن دیگر کار نمی‌کند.');
    }

    /** Download from inside the panel. */
    public function download(ConfigFile $file): Response
    {
        $this->authorize('accounts.view');

        return $this->send($file);
    }

    /** Public direct link (no login): /f/<token>. */
    public function public(string $token): Response
    {
        $file = ConfigFile::query()->where('token', $token)->first();
        abort_unless($file && strlen($token) >= 32, 404);
        $file->forceFill(['downloads' => $file->downloads + 1, 'last_download_at' => now()])->save();

        return $this->send($file);
    }

    private function send(ConfigFile $file): Response
    {
        return response((string) $file->content, 200, [
            'Content-Type' => $file->extension === 'ovpn' ? 'application/x-openvpn-profile' : 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$file->fileName().'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating, ?ConfigFile $file = null): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*$/'],
            'file' => [$creating && ! $request->filled('content') ? 'required' : 'nullable', 'file', 'max:'.self::MAX_KB],
            'content' => ['nullable', 'string', 'max:'.(self::MAX_KB * 1024)],
        ], [
            'name.regex' => 'نام فقط با حروف انگلیسی، عدد، خط تیره، نقطه و فاصله (مثلاً Server11).',
            'file.required' => 'فایل کانفیگ را انتخاب کنید یا متن آن را بچسبانید.',
            'file.max' => 'حجم فایل حداکثر '.self::MAX_KB.' کیلوبایت.',
        ]);
        $name = trim((string) $request->input('name'));
        $content = null;
        if ($request->hasFile('file')) {
            $content = (string) file_get_contents($request->file('file')->getRealPath());
        } elseif ($request->filled('content')) {
            $content = (string) $request->input('content');
        }
        $out = ['name' => $name];
        if ($content === null && $file && $file->name !== $name) {
            $content = (string) $file->content;      // rename only: update the profile name inside the file
        }
        if ($content !== null) {
            $content = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $content));
            if (! preg_match('/^\s*(client|remote)\b/m', $content) || str_contains($content, "\0")) {
                throw ValidationException::withMessages(['file' => 'این فایل کانفیگ OpenVPN نیست (خط client یا remote ندارد).']);
            }
            $out['content'] = self::withProfileName($content, $name);
            $out['extension'] = 'ovpn';
            $out['protocol'] = 'openvpn';
        }

        return $out;
    }

    /** Name shown in OpenVPN Connect when the file is imported. */
    public static function withProfileName(string $content, string $name): string
    {
        $line = 'setenv FRIENDLY_NAME "'.str_replace('"', '', $name).'"';
        $content = preg_replace('/^\s*setenv\s+FRIENDLY_NAME\b.*$/m', $line, $content, -1, $n);
        if (! $n) {
            $content = $line."\n".ltrim($content);
        }

        return rtrim($content)."\n";
    }
}
