<?php

namespace App\Bots;

use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Ibs\IbsNotConfiguredException;
use App\Ibs\IbsUnavailableException;
use App\Models\Bot;
use App\Models\BotLinkCode;
use App\Models\BotUser;
use App\Models\LedgerEntry;
use App\Models\Package;
use App\Models\ProvisioningJob;
use App\Models\Reseller;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use App\Services\AuditLogger;
use App\Services\Finance\InsufficientBalanceException;
use App\Services\Finance\ResellerPricing;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use App\Services\Ibs\AccountSync;
use App\Services\Pricing\PricingEngine;
use App\Services\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Platform-independent conversation logic. Every menu is a reply keyboard, so a button press is just a
 * text message on Telegram, Bale and Rubika alike. Choices offered in a list are remembered in the
 * user's state (label => id), so nothing the user types is trusted as an id.
 */
class Engine
{
    public const MY_ACCOUNTS = '👤 اکانت‌های من';

    public const LINK = '🔗 افزودن اکانت';

    public const PRICES = '💰 تعرفه‌ها';

    public const SUPPORT = '🆘 پشتیبانی';

    public const PARTNER_LOGIN = '🤝 ورود همکاران و مدیران';

    public const WALLET = '👛 کیف پول';

    public const SELL = '🛒 فروش اکانت';

    public const RENEW = '♻️ تمدید / افزایش حجم';

    public const SALES = '🧾 فروش‌های اخیر';

    public const STATUS = '📊 وضعیت سیستم';

    public const LOGOUT = '🚪 خروج از حساب پنل';

    public const HOME = '🏠 منوی اصلی';

    public const CANCEL = '❌ انصراف';

    public const CONFIRM = '✅ تأیید و پرداخت از کیف پول';

    public const AUTO = '⚙️ خودکار';

    private const MAX_FAILED = 5;

    private Platform $api;

    private Bot $bot;

    public function __construct(
        private Platforms $platforms,
        private AccountSync $sync,
        private ResellerSales $sales,
        private ResellerPricing $resellerPricing,
        private PricingEngine $pricing,
        private WalletService $wallets,
        private Settings $settings,
        private AuditLogger $audit,
        private IbsConnector $ibs,
    ) {}

    public function handle(Bot $bot, Incoming $in): void
    {
        $this->bot = $bot;
        $this->api = $this->platforms->for($bot);
        $u = BotUser::query()->firstOrCreate(['bot_id' => $bot->id, 'chat_id' => $in->chatId]);
        $u->forceFill(['first_name' => $in->firstName ? mb_substr($in->firstName, 0, 120) : $u->first_name,
            'handle' => $in->handle ? mb_substr($in->handle, 0, 120) : $u->handle, 'last_seen_at' => now()])->save();
        if ($u->is_blocked) {
            return;
        }
        if (RateLimiter::tooManyAttempts('bot:'.$u->id, 30)) {
            return;
        }
        RateLimiter::hit('bot:'.$u->id, 60);

        $text = trim($in->text);
        if (str_starts_with($text, '/start')) {
            $param = trim(substr($text, 6));
            $u->setState(null);
            if ($param !== '') {
                $this->tryLinkCode($u, $param);

                return;
            }
            $this->menu($u, $this->welcome());

            return;
        }
        if (in_array($text, [self::HOME, self::CANCEL, '/menu', '/cancel'], true)) {
            $u->setState(null);
            $this->menu($u, 'منوی اصلی:');

            return;
        }
        if ($this->isMenuButton($u, $text)) {
            $u->setState(null);
            $this->menuAction($u, $text);

            return;
        }
        if ($u->state) {
            $this->continueState($u, $text, $in);

            return;
        }
        $this->menu($u, 'لطفاً یکی از گزینه‌های منو را انتخاب کنید.');
    }

    // ------------------------------------------------------------------ menu

    private function menuRows(BotUser $u): array
    {
        $rows = [[self::MY_ACCOUNTS, self::LINK], [self::PRICES, self::SUPPORT]];
        $user = $u->user;
        if ($user && $user->is_active) {
            if ($user->isReseller()) {
                $rows[] = [self::SELL, self::RENEW];
                $rows[] = [self::WALLET, self::SALES];
            } elseif ($user->hasPermission('dashboard.view')) {
                $rows[] = [self::STATUS];
            }
            $rows[] = [self::LOGOUT];
        } else {
            $rows[] = [self::PARTNER_LOGIN];
        }

        return $rows;
    }

    private function isMenuButton(BotUser $u, string $text): bool
    {
        return in_array($text, array_merge(...$this->menuRows($u)), true);
    }

    private function menu(BotUser $u, string $text): void
    {
        $this->api->send($u->chat_id, $text, $this->menuRows($u));
    }

    private function say(BotUser $u, string $text, ?array $keyboard = null): void
    {
        $this->api->send($u->chat_id, $text, $keyboard);
    }

    private function menuAction(BotUser $u, string $b): void
    {
        match ($b) {
            self::MY_ACCOUNTS => $this->myAccounts($u),
            self::LINK => $this->ask($u, 'link_user', [], 'نام کاربری اکانت خود را بفرستید:'),
            self::PRICES => $this->prices($u),
            self::SUPPORT => $this->menu($u, $this->settings->get('bots.support_text') ?: 'برای پشتیبانی با فروشنده یا مدیر تماس بگیرید.'),
            self::PARTNER_LOGIN => $this->ask($u, 'link_code', [], 'کد اتصال را از پنل (بخش «اتصال به ربات») دریافت کنید و اینجا بفرستید:'),
            self::WALLET => $this->wallet($u),
            self::SELL => $this->sellStart($u),
            self::RENEW => $this->ask($u, 'renew_acc', [], 'نام کاربری اکانتی را که می‌خواهید تمدید یا حجمش را افزایش دهید بفرستید:'),
            self::SALES => $this->recentSales($u),
            self::STATUS => $this->systemStatus($u),
            self::LOGOUT => $this->logout($u),
            default => $this->menu($u, 'منوی اصلی:'),
        };
    }

    private function ask(BotUser $u, string $state, array $data, string $text, array $extraRows = []): void
    {
        $u->setState($state, $data);
        $this->say($u, $text, array_merge($extraRows, [[self::CANCEL]]));
    }

    private function continueState(BotUser $u, string $text, Incoming $in): void
    {
        $d = $u->state_data ?? [];
        match ($u->state) {
            'link_user' => $this->ask($u, 'link_pass', ['username' => mb_substr($text, 0, 64)], 'رمز عبور اکانت را بفرستید:'),
            'link_pass' => $this->linkAccount($u, (string) ($d['username'] ?? ''), $text, $in),
            'link_code' => $this->tryLinkCode($u, $text),
            'sell_pkg' => $this->sellPicked($u, $text),
            'sell_user' => $this->sellUsername($u, $text),
            'sell_pass' => $this->sellPassword($u, $text, $in),
            'sell_confirm', 'renew_confirm' => $text === self::CONFIRM ? $this->sellConfirm($u) : $this->ask($u, $u->state, $d, 'برای ادامه «تأیید» یا «انصراف» را بزنید.', [[self::CONFIRM]]),
            'renew_acc' => $this->renewAccount($u, $text),
            'renew_pkg' => $this->renewPicked($u, $text),
            default => $this->menu($u, 'منوی اصلی:'),
        };
    }

    // ------------------------------------------------------------------ customers

    private function linkAccount(BotUser $u, string $username, string $password, Incoming $in): void
    {
        $u->setState(null);
        if ($in->messageId) {
            $this->api->deleteMessage($u->chat_id, $in->messageId);   // the password should not stay in the chat
        }
        if ($u->login_blocked_until && $u->login_blocked_until->isFuture()) {
            $this->menu($u, 'به دلیل تلاش‌های ناموفق، تا '.fa_duration((int) now()->diffInSeconds($u->login_blocked_until, true)).' دیگر امکان افزودن اکانت نیست.');

            return;
        }
        try {
            $info = $username === '' ? null : $this->ibs->userByUsername($username);
        } catch (IbsUnavailableException|IbsNotConfiguredException|IbsException) {
            $this->menu($u, 'ارتباط با سرور موقتاً برقرار نیست. چند دقیقه دیگر دوباره تلاش کنید.');

            return;
        }
        $real = $info['attrs']['normal_password'] ?? null;
        if ($info === null || $real === null || ! hash_equals((string) $real, $password)) {
            $fails = $u->failed_logins + 1;
            $u->forceFill($fails >= self::MAX_FAILED ? ['failed_logins' => 0, 'login_blocked_until' => now()->addMinutes(30)] : ['failed_logins' => $fails])->save();
            $this->menu($u, 'نام کاربری یا رمز عبور درست نیست.');

            return;
        }
        $account = $this->sync->apply($info);
        if (! $account) {
            $this->menu($u, 'این اکانت قابل افزودن نیست.');

            return;
        }
        $u->forceFill(['failed_logins' => 0])->save();
        $u->accounts()->syncWithoutDetaching([$account->id => ['created_at' => now()]]);
        $this->menu($u, "✅ اکانت به ربات اضافه شد.\n\n".$this->accountCard($account));
    }

    private function myAccounts(BotUser $u): void
    {
        $accounts = $u->accounts()->whereNull('ibs_deleted_at')->orderBy('username')->limit(10)->get();
        if ($accounts->isEmpty()) {
            $this->menu($u, 'هنوز اکانتی به ربات اضافه نکرده‌اید. از «'.self::LINK.'» استفاده کنید.');

            return;
        }
        $cards = [];
        foreach ($accounts as $a) {
            try {
                $a = $this->sync->refresh($a);
            } catch (Throwable) {
                // show the last known data
            }
            $cards[] = $this->accountCard($a);
        }
        $this->menu($u, implode("\n\n➖➖➖\n\n", $cards));
    }

    private function accountCard(VpnAccount $a): string
    {
        $lines = ['👤 '.$a->username, 'وضعیت: '.(VpnAccount::STATUS_LABELS[$a->status()][0] ?? $a->status())];
        if ($a->expires_at) {
            $lines[] = 'انقضا: '.jdate($a->expires_at, 'Y/m/d').($a->expires_at->isFuture() ? ' ('.fa((int) ceil(now()->diffInDays($a->expires_at, true))).' روز مانده)' : '');
        } elseif ($a->effectiveRelExpSeconds()) {
            $lines[] = 'مدت: '.fa_duration($a->effectiveRelExpSeconds()).' از اولین اتصال';
        }
        if ($a->isVolumeLimited()) {
            $lines[] = 'حجم باقی‌مانده: '.fa_bytes($a->remainingBytes());
        }
        if ($a->is_online) {
            $lines[] = '🟢 اکنون متصل';
        }

        return implode("\n", $lines);
    }

    private function prices(BotUser $u): void
    {
        $lines = [];
        foreach (Package::query()->where('is_active', true)->orderBy('type')->orderBy('sort_order')->limit(30)->get() as $p) {
            $lines[] = '📦 '.$p->name.' — '.$p->summary().' — '.fa_money($this->pricing->quote($p)->finalPrice);
        }
        $this->menu($u, $lines ? "تعرفه‌ها:\n\n".implode("\n", $lines) : 'فعلاً بسته‌ای تعریف نشده است.');
    }

    // ------------------------------------------------------------------ panel users (resellers / staff)

    private function tryLinkCode(BotUser $u, string $code): void
    {
        $u->setState(null);
        if ($u->login_blocked_until && $u->login_blocked_until->isFuture()) {
            $this->menu($u, 'به دلیل تلاش‌های ناموفق، کمی بعد دوباره تلاش کنید.');

            return;
        }
        $code = strtoupper(preg_replace('/\s+/', '', strtr($code, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])));
        $row = BotLinkCode::query()->where('code_hash', BotLinkCode::hash($code))->whereNull('used_at')->where('expires_at', '>', now())->first();
        $user = $row?->user;
        if (! $row || ! $user || ! $user->is_active) {
            $fails = $u->failed_logins + 1;
            $u->forceFill($fails >= self::MAX_FAILED ? ['failed_logins' => 0, 'login_blocked_until' => now()->addMinutes(30)] : ['failed_logins' => $fails])->save();
            $this->menu($u, 'کد اتصال معتبر نیست یا منقضی شده است.');

            return;
        }
        $row->forceFill(['used_at' => now()])->save();
        $u->forceFill(['user_id' => $user->id, 'failed_logins' => 0])->save();
        $u->setRelation('user', $user);
        $this->audit->log('bots.linked', $user, 'اتصال '.$this->bot->label().' به حساب «'.$user->username.'»', [], null);
        $this->menu($u, '✅ این گفتگو به حساب «'.$user->name.'» در پنل وصل شد.');
    }

    private function logout(BotUser $u): void
    {
        $u->forceFill(['user_id' => null])->save();
        $u->setRelation('user', null);
        $this->menu($u, 'اتصال این گفتگو به حساب پنل قطع شد.');
    }

    private function reseller(BotUser $u): ?Reseller
    {
        $r = $u->reseller();

        return $r && $u->user?->is_active ? $r : null;
    }

    private function wallet(BotUser $u): void
    {
        $r = $this->reseller($u);
        if (! $r) {
            $this->menu($u, 'این بخش فقط برای همکاران است.');

            return;
        }
        $w = $this->wallets->walletFor('reseller', $r->id);
        $lines = ['👛 موجودی: '.fa_money($w->balance), '', 'آخرین تراکنش‌ها:'];
        foreach (LedgerEntry::query()->where('wallet_id', $w->id)->orderByDesc('id')->limit(5)->get() as $e) {
            $lines[] = ($e->amount >= 0 ? '➕ ' : '➖ ').fa_money(abs($e->amount)).' — '.(LedgerEntry::TYPES[$e->type] ?? $e->type).' — '.jdate($e->created_at, 'm/d H:i');
        }
        $this->menu($u, implode("\n", $lines));
    }

    private function recentSales(BotUser $u): void
    {
        $r = $this->reseller($u);
        if (! $r) {
            $this->menu($u, 'این بخش فقط برای همکاران است.');

            return;
        }
        $this->sales->syncPending();
        $lines = [];
        foreach (ResellerSale::query()->with('package', 'account')->where('reseller_id', $r->id)->orderByDesc('id')->limit(10)->get() as $s) {
            $lines[] = '#'.fa((string) $s->id).' '.($s->package?->name ?? '').' — '.($s->account?->username ?? '—').' — '.(ResellerSale::STATUS[$s->status][0] ?? $s->status).' — '.fa_money($s->cost_rial);
        }
        $this->menu($u, $lines ? "فروش‌های اخیر:\n\n".implode("\n", $lines) : 'هنوز فروشی ندارید.');
    }

    private function systemStatus(BotUser $u): void
    {
        if (! $u->user?->hasPermission('dashboard.view') || $u->user->isReseller()) {
            $this->menu($u, 'دسترسی ندارید.');

            return;
        }
        $health = $this->ibs->health();
        $lines = [
            '📊 وضعیت سیستم',
            'اکانت‌ها: '.fa(VpnAccount::query()->whereNull('ibs_deleted_at')->count()).' — آنلاین: '.fa(VpnAccount::query()->where('is_online', true)->count()),
            'فروش‌های نیازمند رسیدگی: '.fa(ResellerSale::query()->where('status', 'needs_review')->count()),
            'ساخت‌های نیمه‌کاره: '.fa(ProvisioningJob::query()->whereIn('status', ProvisioningJob::OPEN)->count()),
            'سرور: '.($health['breaker_open'] ? '🔴 قطع' : ($health['ok_at'] ? '🟢 آخرین تماس موفق '.fa_ago(Carbon::parse($health['ok_at'])) : '—')),
        ];
        $this->menu($u, implode("\n", $lines));
    }

    // ------------------------------------------------------------------ reseller selling

    private function sellStart(BotUser $u): void
    {
        $r = $this->reseller($u);
        if (! $r) {
            $this->menu($u, 'این بخش فقط برای همکاران است.');

            return;
        }
        $this->choosePackage($u, $r, ['new'], 'sell_pkg', [], 'بسته را انتخاب کنید (قیمت خرید شما):');
    }

    private function choosePackage(BotUser $u, Reseller $r, array $types, string $state, array $data, string $prompt): void
    {
        $opts = [];
        $rows = [];
        foreach (Package::query()->where('is_active', true)->whereIn('type', $types)->orderBy('sort_order')->limit(20)->get() as $p) {
            $label = '📦 '.$p->name.' — '.fa_money($this->resellerPricing->quote($r, $p)->finalPrice);
            $opts[$label] = $p->id;
            $rows[] = [$label];
        }
        if (! $opts) {
            $this->menu($u, 'بسته فعالی برای این کار تعریف نشده است.');

            return;
        }
        $this->ask($u, $state, $data + ['opts' => $opts], $prompt, $rows);
    }

    private function picked(BotUser $u, string $text): ?Package
    {
        $id = ($u->state_data['opts'] ?? [])[$text] ?? null;

        return $id ? Package::query()->where('is_active', true)->find($id) : null;
    }

    private function sellPicked(BotUser $u, string $text): void
    {
        $p = $this->picked($u, $text);
        if (! $p) {
            $this->ask($u, 'sell_pkg', $u->state_data ?? [], 'لطفاً یکی از بسته‌های فهرست را انتخاب کنید.', array_map(fn ($l) => [$l], array_keys($u->state_data['opts'] ?? [])));

            return;
        }
        $this->ask($u, 'sell_user', ['package_id' => $p->id], 'نام کاربری دلخواه (حروف کوچک انگلیسی و عدد) را بفرستید یا «خودکار» را بزنید:', [[self::AUTO]]);
    }

    private function sellUsername(BotUser $u, string $text): void
    {
        $d = $u->state_data ?? [];
        if ($text !== self::AUTO) {
            if (! preg_match('/^[a-z0-9][a-z0-9_.-]{2,31}$/', $text)) {
                $this->ask($u, 'sell_user', $d, 'نام کاربری باید ۳ تا ۳۲ کاراکتر از حروف کوچک انگلیسی، عدد، «_»، «.» یا «-» باشد. دوباره بفرستید یا «خودکار» را بزنید:', [[self::AUTO]]);

                return;
            }
            $d['username'] = $text;
        }
        $this->ask($u, 'sell_pass', $d, 'رمز دلخواه را بفرستید یا «خودکار» را بزنید:', [[self::AUTO]]);
    }

    private function sellPassword(BotUser $u, string $text, Incoming $in): void
    {
        $d = $u->state_data ?? [];
        if ($text !== self::AUTO) {
            $d['password'] = mb_substr($text, 0, 64);
        }
        $this->confirm($u, 'sell_confirm', $d);
    }

    private function renewAccount(BotUser $u, string $text): void
    {
        $r = $this->reseller($u);
        $a = $r ? VpnAccount::query()->where('reseller_id', $r->id)->whereNull('ibs_deleted_at')->where('username', trim($text))->first() : null;
        if (! $a) {
            $this->ask($u, 'renew_acc', [], 'این اکانت در میان اکانت‌های شما پیدا نشد. نام کاربری را دوباره بفرستید:');

            return;
        }
        $this->choosePackage($u, $r, ['renewal', 'topup'], 'renew_pkg', ['account_id' => $a->id], "اکانت {$a->username}\n\nبسته تمدید یا افزایش حجم را انتخاب کنید:");
    }

    private function renewPicked(BotUser $u, string $text): void
    {
        $p = $this->picked($u, $text);
        if (! $p) {
            $this->ask($u, 'renew_pkg', $u->state_data ?? [], 'لطفاً یکی از بسته‌های فهرست را انتخاب کنید.', array_map(fn ($l) => [$l], array_keys($u->state_data['opts'] ?? [])));

            return;
        }
        $this->confirm($u, 'renew_confirm', ['package_id' => $p->id, 'account_id' => $u->state_data['account_id'] ?? null]);
    }

    private function confirm(BotUser $u, string $state, array $d): void
    {
        $r = $this->reseller($u);
        $p = Package::query()->find($d['package_id'] ?? 0);
        if (! $r || ! $p) {
            $u->setState(null);
            $this->menu($u, 'منوی اصلی:');

            return;
        }
        $d['sale_key'] = (string) Str::uuid();   // fixed now: pressing confirm twice can never sell twice
        $q = $this->resellerPricing->quote($r, $p);
        $lines = ['🧾 خلاصه', 'بسته: '.$p->name.' — '.$p->summary(), 'مبلغ کسر از کیف پول: '.fa_money($q->finalPrice)];
        if (! empty($d['account_id'])) {
            $lines[] = 'اکانت: '.VpnAccount::query()->whereKey($d['account_id'])->value('username');
        } else {
            $lines[] = 'نام کاربری: '.($d['username'] ?? 'خودکار');
            $lines[] = 'رمز: '.(isset($d['password']) ? 'دلخواه شما' : 'خودکار');
        }
        $lines[] = 'موجودی فعلی: '.fa_money($this->wallets->walletFor('reseller', $r->id)->balance);
        $this->ask($u, $state, $d, implode("\n", $lines), [[self::CONFIRM]]);
    }

    private function sellConfirm(BotUser $u): void
    {
        $d = $u->state_data ?? [];
        $u->setState(null);
        $r = $this->reseller($u);
        $p = Package::query()->find($d['package_id'] ?? 0);
        if (! $r || ! $p || empty($d['sale_key'])) {
            $this->menu($u, 'منوی اصلی:');

            return;
        }
        $target = null;
        if (! empty($d['account_id'])) {
            $target = VpnAccount::query()->where('reseller_id', $r->id)->find($d['account_id']);
            if (! $target) {
                $this->menu($u, 'اکانت پیدا نشد.');

                return;
            }
        }
        try {
            $res = $this->sales->sell($r, $p, $d['sale_key'], $this->pricing->quote($p)->finalPrice,
                ['username' => $d['username'] ?? null, 'password' => $d['password'] ?? null, 'customer_name' => null, 'customer_phone' => null, 'source' => $this->bot->platform],
                $target, $u->user_id);
        } catch (InsufficientBalanceException) {
            $this->menu($u, '❗ موجودی کیف پول کافی نیست. کیف پول خود را شارژ کنید.');

            return;
        } catch (RuntimeException $e) {
            $this->menu($u, '❗ '.(($e instanceof IbsException) ? 'سرور درخواست را نپذیرفت.' : $e->getMessage()));

            return;
        }
        $s = $res['sale'];
        $msg = match ($s->status) {
            'completed' => $target
                ? '✅ اکانت '.$target->username.' با بسته «'.$p->name.'» به‌روزرسانی شد.'
                : "✅ اکانت ساخته شد.\n\nنام کاربری: ".$s->account?->username."\nرمز: ".($res['password'] ?? '—')."\n\nرمز فقط همین یک بار نمایش داده می‌شود.",
            'refunded' => '❗ انجام نشد و مبلغ به کیف پول شما برگشت.'.($s->error ? ' ('.mb_substr((string) $s->error, 0, 120).')' : ''),
            'needs_review' => '⏳ نتیجه در سرور نامشخص ماند. مبلغ نگه داشته شده و پس از بررسی مدیر، انجام یا برگشت داده می‌شود.',
            default => '⏳ در حال انجام است؛ نتیجه را همین‌جا اطلاع می‌دهیم.',
        };
        $this->menu($u, $msg);
    }

    private function welcome(): string
    {
        return $this->settings->get('bots.welcome_text') ?: 'سلام! به ربات خوش آمدید. از منوی زیر استفاده کنید.';
    }
}
