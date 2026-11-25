<?php

namespace App\Bots;

use App\Ibs\ErrorMessages;
use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Ibs\IbsNotConfiguredException;
use App\Ibs\IbsUnavailableException;
use App\Models\Bot;
use App\Models\BotLinkCode;
use App\Models\BotUser;
use App\Models\CustomerOrder;
use App\Models\IbsGroup;
use App\Models\LedgerEntry;
use App\Models\MtAccount;
use App\Models\MtDevice;
use App\Models\MtGroup;
use App\Models\Package;
use App\Models\Payment;
use App\Models\ProvisioningJob;
use App\Models\Reseller;
use App\Models\ResellerSale;
use App\Models\User;
use App\Models\VpnAccount;
use App\Payments\Payments;
use App\Services\AuditLogger;
use App\Services\Finance\CustomerOrders;
use App\Services\Finance\InsufficientBalanceException;
use App\Services\Finance\ResellerPricing;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use App\Services\Ibs\AccountSync;
use App\Services\Ibs\Provisioning;
use App\Services\Mikrotik\MtProvisioning;
use App\Services\Mikrotik\WgConfig;
use App\Services\Pricing\PackageApplier;
use App\Services\Pricing\PricingEngine;
use App\Services\Settings;
use App\Support\QrCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public const CREATE = '➕ ساخت اکانت';

    public const UNLIMITED = '♾ نامحدود';

    public const HOME = '🏠 منوی اصلی';

    public const CANCEL = '❌ انصراف';

    public const CONFIRM = '✅ تأیید و پرداخت از کیف پول';

    public const AUTO = '⚙️ خودکار';

    public const BUY = '🛍 خرید اکانت';

    public const CRENEW = '🔄 تمدید اکانت من';

    public const TOPUP = '➕ شارژ کیف پول';

    public const PAY_WALLET = '💰 پرداخت از موجودی کیف پول';

    public const PAY_ONLINE = '💳 پرداخت آنلاین';

    public const PAY_CARD = '🏦 کارت به کارت';

    public const PAY_USDT = '💵 تتر (USDT)';

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
        $rows = [[self::MY_ACCOUNTS, self::LINK]];
        $user = $u->user;
        $isReseller = $user && $user->is_active && $user->isReseller();
        if ($isReseller) {
            $rows[] = [self::SELL, self::RENEW];
            $rows[] = [self::WALLET, self::SALES];
        } else {
            $rows[] = [self::BUY, self::CRENEW];
            if ($this->wallets->walletFor('customer', $u->id)->balance > 0) {
                $rows[] = [self::WALLET];
            }
        }
        $rows[] = [self::PRICES, self::SUPPORT];
        if ($user && $user->is_active) {
            if (! $isReseller && ($user->hasPermission('accounts.create') || $user->hasPermission('mt_accounts.create'))) {
                array_unshift($rows, [self::CREATE]);
            }
            if (! $isReseller && $user->hasPermission('dashboard.view')) {
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
            self::PARTNER_LOGIN => $this->ask($u, 'login_user', [], 'نام کاربری پنل را بفرستید (یا کد اتصال از بخش «اتصال به ربات»):'),
            self::CREATE => $this->mkStart($u),
            self::WALLET => $this->wallet($u),
            self::SELL => $this->sellStart($u),
            self::RENEW => $this->ask($u, 'renew_acc', [], 'نام کاربری اکانتی را که می‌خواهید تمدید یا حجمش را افزایش دهید بفرستید:'),
            self::SALES => $this->recentSales($u),
            self::BUY => $this->buyStart($u),
            self::CRENEW => $this->crenewStart($u),
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
            'login_user' => BotLinkCode::query()->where('code_hash', BotLinkCode::hash(preg_replace('/\s+/', '', $text)))->whereNull('used_at')->exists()
                ? $this->tryLinkCode($u, $text)
                : $this->ask($u, 'login_pass', ['username' => mb_substr($text, 0, 64)], 'رمز عبور پنل را بفرستید (پیام رمز بلافاصله پاک می‌شود):'),
            'login_pass' => $this->panelLogin($u, (string) ($d['username'] ?? ''), $text, $in),
            'mk_proto' => $this->mkProto($u, $text),
            'mk_plan' => $this->mkPlan($u, $text),
            'mk_days' => $this->mkDays($u, $text),
            'mk_vol' => $this->mkVol($u, $text),
            'mk_name' => $this->mkCreate($u, $text),
            'sell_pkg' => $this->sellPicked($u, $text),
            'sell_user' => $this->sellUsername($u, $text),
            'sell_pass' => $this->sellPassword($u, $text, $in),
            'sell_confirm', 'renew_confirm' => $text === self::CONFIRM ? $this->sellConfirm($u) : $this->ask($u, $u->state, $d, 'برای ادامه «تأیید» یا «انصراف» را بزنید.', [[self::CONFIRM]]),
            'renew_acc' => $this->renewAccount($u, $text),
            'renew_pkg' => $this->renewPicked($u, $text),
            'wallet' => $text === self::TOPUP ? $this->ask($u, 'topup_amount', [], 'مبلغ شارژ را به تومان بفرستید (مثلاً 500000):') : $this->menu($u, 'منوی اصلی:'),
            'topup_amount' => $this->topupAmount($u, $text),
            'buy_pkg' => $this->buyPicked($u, $text),
            'crenew_acc' => $this->crenewAccount($u, $text),
            'crenew_pkg' => $this->buyPicked($u, $text),
            'pay_method' => $this->payMethod($u, $text),
            'usdt_net' => $this->usdtNetwork($u, $text),
            'card_note' => $this->cardNote($u, $text),
            'usdt_hash' => $this->usdtHash($u, $text),
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
        $mt = $username === '' ? null : MtAccount::query()->where('protocol', 'l2tp')->where('username', $username)->whereNotIn('status', ['pending', 'failed'])->get()
            ->first(fn (MtAccount $m) => hash_equals((string) $m->password, $password));
        if ($mt) {
            $u->forceFill(['failed_logins' => 0])->save();
            $u->mtAccounts()->syncWithoutDetaching([$mt->id => ['created_at' => now()]]);
            $this->menu($u, "✅ اکانت به ربات اضافه شد.\n\n".$this->mtCard($mt));

            return;
        }
        try {
            $info = $username === '' ? null : $this->ibs->userByUsername($username);
        } catch (IbsNotConfiguredException) {
            $this->failedLink($u);   // only MikroTik in use: the L2TP check above already failed

            return;
        } catch (IbsUnavailableException|IbsException) {
            $this->menu($u, 'ارتباط با سرور موقتاً برقرار نیست. چند دقیقه دیگر دوباره تلاش کنید.');

            return;
        }
        $real = $info['attrs']['normal_password'] ?? null;
        if ($info === null || $real === null || ! hash_equals((string) $real, $password)) {
            $this->failedLink($u);

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

    private function failedLink(BotUser $u): void
    {
        $fails = $u->failed_logins + 1;
        $u->forceFill($fails >= self::MAX_FAILED ? ['failed_logins' => 0, 'login_blocked_until' => now()->addMinutes(30)] : ['failed_logins' => $fails])->save();
        $this->menu($u, 'نام کاربری یا رمز عبور درست نیست.');
    }

    private function mtCard(MtAccount $a): string
    {
        $lines = ['👤 '.$a->username.' ('.$a->protocolLabel().')', 'وضعیت: '.$a->statusLabel()[0]];
        if ($tm = $a->timeMeter()) {
            $lines[] = '';
            $lines[] = '⏳ زمان: '.VpnAccount::textBar($tm['pct']).' '.fa($tm['pct']).'٪';
            $lines[] = $tm['started']
                ? ($tm['remaining'] > 0 ? fa_duration($tm['remaining']).' مانده — تا '.jdate($a->expires_at, 'Y/m/d') : 'منقضی شده در '.jdate($a->expires_at, 'Y/m/d'))
                : fa_duration($tm['total']).' — از اولین اتصال شروع می‌شود';
        }
        $lines[] = '';
        if ($vm = $a->volumeMeter()) {
            $lines[] = '📶 حجم: '.VpnAccount::textBar($vm['pct']).' '.fa($vm['pct']).'٪';
            $lines[] = fa_bytes($vm['remaining']).' مانده از '.fa_bytes($vm['total']);
        } else {
            $lines[] = '📶 حجم: نامحدود — مصرف '.fa_bytes($a->used_bytes);
        }
        if ($a->is_online) {
            $lines[] = '';
            $lines[] = '🟢 اکنون متصل';
        }

        return implode("\n", $lines);
    }

    private function myAccounts(BotUser $u): void
    {
        $accounts = $u->accounts()->whereNull('ibs_deleted_at')->orderBy('username')->limit(10)->get();
        $mts = $u->mtAccounts()->orderBy('username')->limit(10)->get();
        if ($accounts->isEmpty() && $mts->isEmpty()) {
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
        foreach ($mts as $m) {
            $cards[] = $this->mtCard($m);
        }
        $this->menu($u, implode("\n\n➖➖➖\n\n", $cards));
    }

    private function accountCard(VpnAccount $a): string
    {
        $lines = ['👤 '.$a->username, 'وضعیت: '.(VpnAccount::STATUS_LABELS[$a->status()][0] ?? $a->status())];
        if ($tm = $a->timeMeter()) {
            $lines[] = '';
            $lines[] = '⏳ زمان: '.VpnAccount::textBar($tm['pct']).' '.fa($tm['pct']).'٪';
            $lines[] = $tm['started']
                ? ($tm['remaining'] > 0 ? fa_duration($tm['remaining']).' مانده از '.fa_duration($tm['total']).' — تا '.jdate($a->expires_at, 'Y/m/d') : 'منقضی شده در '.jdate($a->expires_at, 'Y/m/d'))
                : fa_duration($tm['total']).' — از اولین اتصال شروع می‌شود';
        }
        if ($vm = $a->volumeMeter()) {
            $lines[] = '';
            $lines[] = '📶 حجم: '.VpnAccount::textBar($vm['pct']).' '.fa($vm['pct']).'٪';
            $lines[] = fa_bytes($vm['remaining']).' مانده از '.fa_bytes($vm['total']);
        } elseif ($a->charge() && ! $a->isVolumeLimited()) {
            $lines[] = '📶 حجم: نامحدود';
        }
        if ($a->is_online) {
            $lines[] = '';
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

    // ------------------------------------------------------------------ panel login (username + password)

    private function panelLogin(BotUser $u, string $username, string $password, Incoming $in): void
    {
        $u->setState(null);
        if ($in->messageId) {
            $this->api->deleteMessage($u->chat_id, $in->messageId);
        }
        if ($u->login_blocked_until && $u->login_blocked_until->isFuture()) {
            $this->menu($u, 'به دلیل تلاش‌های ناموفق، کمی بعد دوباره تلاش کنید.');

            return;
        }
        $user = User::query()->where('username', $username)->first();
        $ok = $user && $user->is_active && Hash::check($password, $user->password);
        if (! $ok) {
            $fails = $u->failed_logins + 1;
            $u->forceFill($fails >= self::MAX_FAILED ? ['failed_logins' => 0, 'login_blocked_until' => now()->addMinutes(30)] : ['failed_logins' => $fails])->save();
            $this->menu($u, 'نام کاربری یا رمز عبور پنل درست نیست.');

            return;
        }
        $u->forceFill(['user_id' => $user->id, 'failed_logins' => 0])->save();
        $u->setRelation('user', $user);
        $this->audit->log('bots.linked', $user, 'ورود به '.$this->bot->label().' با نام کاربری و رمز', [], null);
        $this->menu($u, '✅ خوش آمدید '.$user->name.'. این گفتگو به حساب پنل شما وصل شد.');
    }

    // ------------------------------------------------------------------ staff: create an account (OpenVPN / L2TP / WireGuard)

    private const PROTOS = ['🔐 OpenVPN' => 'openvpn', '📡 L2TP' => 'l2tp', '🛡 WireGuard' => 'wireguard'];

    private function staff(BotUser $u): ?User
    {
        $user = $u->user;

        return $user && $user->is_active && ! $user->isReseller() ? $user : null;
    }

    private function mkStart(BotUser $u): void
    {
        $user = $this->staff($u);
        if (! $user) {
            $this->menu($u, 'این بخش برای مدیران است.');

            return;
        }
        $opts = [];
        if ($user->hasPermission('accounts.create') && $this->ibs->isConfigured()) {
            $opts['🔐 OpenVPN'] = 'openvpn';
        }
        if ($user->hasPermission('mt_accounts.create')) {
            foreach (['l2tp' => '📡 L2TP', 'wireguard' => '🛡 WireGuard'] as $p => $label) {
                if (MtGroup::query()->where('protocol', $p)->where('is_active', true)->exists()) {
                    $opts[$label] = $p;
                }
            }
        }
        if (! $opts) {
            $this->menu($u, 'گروهی برای ساخت اکانت تعریف نشده یا دسترسی ندارید.');

            return;
        }
        $this->ask($u, 'mk_proto', ['opts' => $opts], 'نوع اکانت را انتخاب کنید:', array_map(fn ($l) => [$l], array_keys($opts)));
    }

    private function mkProto(BotUser $u, string $text): void
    {
        $proto = ($u->state_data['opts'] ?? [])[$text] ?? null;
        if (! $proto || ! $this->staff($u)) {
            $this->mkStart($u);

            return;
        }
        $opts = [];
        $pkgs = Package::query()->with('mtGroup')->where('is_active', true)->where('type', 'new')
            ->where('protocol', $proto === 'openvpn' ? 'openvpn' : 'mikrotik')->orderBy('sort_order')->orderBy('price_rial')->limit(15)->get();
        foreach ($pkgs as $p) {
            if ($proto !== 'openvpn' && $p->mtGroup?->protocol !== $proto) {
                continue;
            }
            $opts['📦 '.$p->name.' — '.$p->summary()] = 'p:'.$p->id;
        }
        $groups = $proto === 'openvpn'
            ? IbsGroup::query()->with('charge')->where('present', true)->orderBy('name')->limit(10)->get()->mapWithKeys(fn ($g) => ['⚙️ گروه '.$g->name.' (روز و حجم دلخواه)' => 'g:'.$g->name])
            : MtGroup::query()->where('protocol', $proto)->where('is_active', true)->orderBy('name')->limit(10)->get()->mapWithKeys(fn ($g) => ['⚙️ گروه '.$g->name.' (روز و حجم دلخواه)' => 'g:'.$g->id]);
        $opts += $groups->all();
        if (! $opts) {
            $this->menu($u, 'برای این نوع، بسته یا گروه فعالی نیست.');

            return;
        }
        $this->ask($u, 'mk_plan', ['proto' => $proto, 'opts' => $opts], 'بسته یا گروه را انتخاب کنید:', array_map(fn ($l) => [$l], array_keys($opts)));
    }

    private function mkPlan(BotUser $u, string $text): void
    {
        $d = $u->state_data ?? [];
        $pick = ($d['opts'] ?? [])[$text] ?? null;
        if (! $pick) {
            $this->ask($u, 'mk_plan', $d, 'یکی از گزینه‌ها را انتخاب کنید.', array_map(fn ($l) => [$l], array_keys($d['opts'] ?? [])));

            return;
        }
        $d = ['proto' => $d['proto'], 'pick' => $pick];
        if (str_starts_with($pick, 'p:')) {
            $this->ask($u, 'mk_name', $d, '👤 نام کاربر (مشتری) را بفرستید:');

            return;
        }
        $this->ask($u, 'mk_days', $d, 'مدت اعتبار (روز) را انتخاب کنید یا عدد بفرستید:', [['30', '60', '90'], $d['proto'] === 'openvpn' ? ['180', '365'] : ['180', '365', self::UNLIMITED]]);
    }

    private static function num(string $t): ?float
    {
        $t = strtr(trim($t), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.']);

        return is_numeric($t) && (float) $t > 0 ? (float) $t : null;
    }

    private function mkDays(BotUser $u, string $text): void
    {
        $d = $u->state_data ?? [];
        $days = $text === self::UNLIMITED && $d['proto'] !== 'openvpn' ? 0 : (int) self::num($text);
        if ($days === 0 && $text !== self::UNLIMITED || $days > 3650) {
            $this->ask($u, 'mk_days', $d, 'تعداد روز را به عدد بفرستید (مثلاً 30):');

            return;
        }
        $d['days'] = $days ?: null;
        $volumeAsk = true;
        if ($d['proto'] === 'openvpn') {
            $g = IbsGroup::query()->with('charge')->where('name', substr($d['pick'], 2))->first();
            $volumeAsk = (bool) $g?->charge?->isVolume();
        }
        if (! $volumeAsk) {
            $this->ask($u, 'mk_name', $d, '👤 نام کاربر (مشتری) را بفرستید:');

            return;
        }
        $this->ask($u, 'mk_vol', $d, 'حجم (گیگابایت) را انتخاب کنید یا عدد بفرستید:', [['10', '20', '30', '50'], $d['proto'] === 'openvpn' ? ['100'] : ['100', self::UNLIMITED]]);
    }

    private function mkVol(BotUser $u, string $text): void
    {
        $d = $u->state_data ?? [];
        $gb = $text === self::UNLIMITED && $d['proto'] !== 'openvpn' ? 0.0 : (float) self::num($text);
        if (($gb <= 0 && $text !== self::UNLIMITED) || $gb > 100000) {
            $this->ask($u, 'mk_vol', $d, 'حجم را به گیگابایت و به عدد بفرستید (مثلاً 20):');

            return;
        }
        $d['gb'] = $gb ?: null;
        $this->ask($u, 'mk_name', $d, '👤 نام کاربر (مشتری) را بفرستید:');
    }

    private function mkCreate(BotUser $u, string $text): void
    {
        $d = $u->state_data ?? [];
        $name = mb_substr(trim($text), 0, 100);
        $user = $this->staff($u);
        $u->setState(null);
        if ($name === '' || ! $user) {
            $this->menu($u, 'منوی اصلی:');

            return;
        }
        $key = (string) Str::uuid();
        try {
            if ($d['proto'] === 'openvpn') {
                abort_unless($user->hasPermission('accounts.create'), 403);
                $password = Provisioning::generatePassword();
                $job = str_starts_with($d['pick'], 'p:')
                    ? app(PackageApplier::class)->createAccount(Package::query()->findOrFail((int) substr($d['pick'], 2)), $key,
                        ['username' => null, 'password' => $password, 'comment' => $name], $user->id)
                    : app(Provisioning::class)->create($key, ['username' => null, 'password' => $password, 'group' => substr($d['pick'], 2),
                        'days' => $d['days'] ?? null, 'multi_login' => null, 'volume_mb' => isset($d['gb']) ? (int) round($d['gb'] * 1024) : null, 'comment' => $name], $user->id);
                if ($job->status !== 'completed') {
                    $this->menu($u, '⏳ ساخت اکانت در سرور کامل نشد ('.mb_substr((string) $job->last_error, 0, 120).'). در پنل بخش «ساخت‌های نیمه‌کاره» را ببینید.');

                    return;
                }
                $this->menu($u, "✅ اکانت OpenVPN ساخته شد\n👤 ".$name."\n\nنام کاربری: ".$job->username."\nرمز: ".$password);

                return;
            }
            abort_unless($user->hasPermission('mt_accounts.create'), 403);
            if (str_starts_with($d['pick'], 'p:')) {
                $p = Package::query()->findOrFail((int) substr($d['pick'], 2));
                $a = app(MtProvisioning::class)->create($p->mtGroup, $key, ['days' => $p->duration_days, 'volume_gb' => $p->volume_gb,
                    'multi_login' => $p->multi_login, 'comment' => $name, 'package_id' => $p->id], $user->id);
            } else {
                $g = MtGroup::query()->findOrFail((int) substr($d['pick'], 2));
                $a = app(MtProvisioning::class)->create($g, $key, ['days' => $d['days'] ?? null, 'volume_gb' => $d['gb'] ?? null, 'comment' => $name], $user->id);
            }
        } catch (HttpException) {
            $this->menu($u, 'دسترسی ساخت این نوع اکانت را ندارید.');

            return;
        } catch (RuntimeException $e) {
            $this->menu($u, '❗ '.(($e instanceof IbsException) ? ErrorMessages::for($e) : $e->getMessage()));

            return;
        }
        if ($a->status === 'failed') {
            $this->menu($u, '❗ روتر ساخت اکانت را نپذیرفت: '.mb_substr((string) $a->error, 0, 150));

            return;
        }
        $this->sendMtCredentials($u, $a, "✅ اکانت {$a->protocolLabel()} ساخته شد\n👤 {$name}".($a->status === 'pending' ? "\n⏳ روتر الان در دسترس نیست؛ اکانت تا چند دقیقه دیگر روی روتر ساخته می‌شود." : ''));
    }

    /** Credentials as text; for WireGuard also one QR image per device. */
    private function sendMtCredentials(BotUser $u, MtAccount $a, string $intro): void
    {
        if ($a->isWireguard()) {
            foreach ($a->allDevices() as $dev) {
                $conf = WgConfig::build($a, $dev instanceof MtDevice ? $dev : null);
                $png = QrCode::png($conf);
                $caption = '🛡 '.$a->username.($a->deviceCount() > 1 ? ' — دستگاه '.fa($dev->deviceNo()) : '').' — در برنامه WireGuard: ➕ ← Scan from QR code';
                if ($png) {
                    $this->api->sendPhoto($u->chat_id, $png, $caption);
                }
            }
        }
        $this->menu($u, $intro."\n\n".CustomerOrders::mtCredentials($a));
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
        $w = $r ? $this->wallets->walletFor('reseller', $r->id) : $this->wallets->walletFor('customer', $u->id);
        $lines = ['👛 موجودی: '.fa_money($w->balance), '', 'آخرین تراکنش‌ها:'];
        foreach (LedgerEntry::query()->where('wallet_id', $w->id)->orderByDesc('id')->limit(5)->get() as $e) {
            $lines[] = ($e->amount >= 0 ? '➕ ' : '➖ ').fa_money(abs($e->amount)).' — '.(LedgerEntry::TYPES[$e->type] ?? $e->type).' — '.jdate($e->created_at, 'm/d H:i');
        }
        if ($r && $this->payments()->enabledMethods()) {
            $this->ask($u, 'wallet', [], implode("\n", $lines), [[self::TOPUP]]);

            return;
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

    private function choosePackage(BotUser $u, Reseller $r, array $types, string $state, array $data, string $prompt, ?string $protocol = null): void
    {
        $opts = [];
        $rows = [];
        foreach (Package::query()->with('mtGroup')->where('is_active', true)->whereIn('type', $types)->when($protocol, fn ($q) => $q->where('protocol', $protocol))->orderBy('sort_order')->limit(20)->get() as $p) {
            $label = '📦 '.$p->name.($p->isMikrotik() ? ' ('.$p->protocolLabel().')' : '').' — '.fa_money($this->resellerPricing->quote($r, $p)->finalPrice);
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
        if ($p->isMikrotik() && $p->mtGroup?->isWireguard()) {
            $this->ask($u, 'sell_user', ['package_id' => $p->id, 'no_password' => true], 'نام کاربری دلخواه (حروف کوچک انگلیسی و عدد) را بفرستید یا «خودکار» را بزنید:', [[self::AUTO]]);

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
        if (! empty($d['no_password'])) {
            $this->confirm($u, 'sell_confirm', $d);

            return;
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
        $m = $r && ! $a ? MtAccount::query()->where('reseller_id', $r->id)->where('username', trim($text))->whereNotIn('status', ['pending', 'failed'])->first() : null;
        if (! $a && ! $m) {
            $this->ask($u, 'renew_acc', [], 'این اکانت در میان اکانت‌های شما پیدا نشد. نام کاربری را دوباره بفرستید:');

            return;
        }
        $m
            ? $this->choosePackage($u, $r, ['renewal', 'topup'], 'renew_pkg', ['mt_account_id' => $m->id], "اکانت {$m->username}\n\nبسته تمدید یا افزایش حجم را انتخاب کنید:", 'mikrotik')
            : $this->choosePackage($u, $r, ['renewal', 'topup'], 'renew_pkg', ['account_id' => $a->id], "اکانت {$a->username}\n\nبسته تمدید یا افزایش حجم را انتخاب کنید:", 'openvpn');
    }

    private function renewPicked(BotUser $u, string $text): void
    {
        $p = $this->picked($u, $text);
        if (! $p) {
            $this->ask($u, 'renew_pkg', $u->state_data ?? [], 'لطفاً یکی از بسته‌های فهرست را انتخاب کنید.', array_map(fn ($l) => [$l], array_keys($u->state_data['opts'] ?? [])));

            return;
        }
        $this->confirm($u, 'renew_confirm', ['package_id' => $p->id, 'account_id' => $u->state_data['account_id'] ?? null, 'mt_account_id' => $u->state_data['mt_account_id'] ?? null]);
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
        } elseif (! empty($d['mt_account_id'])) {
            $lines[] = 'اکانت: '.MtAccount::query()->whereKey($d['mt_account_id'])->value('username');
        } else {
            $lines[] = 'نام کاربری: '.($d['username'] ?? 'خودکار');
            $lines[] = ! empty($d['no_password']) ? 'نوع: WireGuard (فایل پیکربندی پس از ساخت ارسال می‌شود)' : 'رمز: '.(isset($d['password']) ? 'دلخواه شما' : 'خودکار');
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
        if (! empty($d['account_id']) || ! empty($d['mt_account_id'])) {
            $target = ! empty($d['mt_account_id']) ? MtAccount::query()->where('reseller_id', $r->id)->find($d['mt_account_id'])
                : VpnAccount::query()->where('reseller_id', $r->id)->find($d['account_id']);
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
                : ($s->mtAccount ? "✅ اکانت ساخته شد.\n\n".CustomerOrders::mtCredentials($s->mtAccount)
                    : "✅ اکانت ساخته شد.\n\nنام کاربری: ".$s->account?->username."\nرمز: ".($res['password'] ?? '—')."\n\nرمز فقط همین یک بار نمایش داده می‌شود."),
            'refunded' => '❗ انجام نشد و مبلغ به کیف پول شما برگشت.'.($s->error ? ' ('.mb_substr((string) $s->error, 0, 120).')' : ''),
            'needs_review' => '⏳ نتیجه در سرور نامشخص ماند. مبلغ نگه داشته شده و پس از بررسی مدیر، انجام یا برگشت داده می‌شود.',
            default => '⏳ در حال انجام است؛ نتیجه را همین‌جا اطلاع می‌دهیم.',
        };
        $this->menu($u, $msg);
    }

    // ------------------------------------------------------------------ customer purchases and payments

    private function payments(): Payments
    {
        return app(Payments::class);
    }

    private function orders(): CustomerOrders
    {
        return app(CustomerOrders::class);
    }

    private function buyStart(BotUser $u): void
    {
        $this->publicPackages($u, ['new'], 'buy_pkg', [], 'بسته مورد نظر را انتخاب کنید:');
    }

    private function crenewStart(BotUser $u): void
    {
        $accounts = $u->accounts()->whereNull('ibs_deleted_at')->orderBy('username')->limit(10)->get();
        $mts = $u->mtAccounts()->whereNotIn('status', ['pending', 'failed'])->orderBy('username')->limit(10)->get();
        if ($accounts->isEmpty() && $mts->isEmpty()) {
            $this->menu($u, 'ابتدا اکانت خود را با «'.self::LINK.'» به ربات اضافه کنید.');

            return;
        }
        $opts = [];
        foreach ($accounts as $a) {
            $opts['👤 '.$a->username] = $a->id;
        }
        foreach ($mts as $m) {
            $opts['👤 '.$m->username.' ('.$m->protocolLabel().')'] = 'mt:'.$m->id;
        }
        $this->ask($u, 'crenew_acc', ['opts' => $opts], 'کدام اکانت را تمدید یا حجمش را افزایش می‌دهید؟', array_map(fn ($l) => [$l], array_keys($opts)));
    }

    private function crenewAccount(BotUser $u, string $text): void
    {
        $id = ($u->state_data['opts'] ?? [])[$text] ?? null;
        if (is_string($id) && str_starts_with($id, 'mt:')) {
            $m = $u->mtAccounts()->whereKey((int) substr($id, 3))->first();
            if ($m) {
                $this->publicPackages($u, ['renewal', 'topup'], 'crenew_pkg', ['mt_account_id' => $m->id], 'اکانت '.$m->username."\n\nبسته تمدید یا افزایش حجم را انتخاب کنید:", 'mikrotik');

                return;
            }
        }
        $a = $id && ! is_string($id) ? $u->accounts()->whereKey($id)->first() : null;
        if (! $a) {
            $this->crenewStart($u);

            return;
        }
        $this->publicPackages($u, ['renewal', 'topup'], 'crenew_pkg', ['account_id' => $a->id], 'اکانت '.$a->username."\n\nبسته تمدید یا افزایش حجم را انتخاب کنید:", 'openvpn');
    }

    private function publicPackages(BotUser $u, array $types, string $state, array $data, string $prompt, ?string $protocol = null): void
    {
        $opts = [];
        foreach (Package::query()->with('mtGroup')->where('is_active', true)->whereIn('type', $types)->when($protocol, fn ($q) => $q->where('protocol', $protocol))->orderBy('sort_order')->limit(20)->get() as $p) {
            $opts['📦 '.$p->name.($p->isMikrotik() ? ' ('.$p->protocolLabel().')' : '').' — '.fa_money($this->pricing->quote($p)->finalPrice)] = $p->id;
        }
        if (! $opts) {
            $this->menu($u, 'فعلاً بسته‌ای برای این کار تعریف نشده است.');

            return;
        }
        $this->ask($u, $state, $data + ['opts' => $opts], $prompt, array_map(fn ($l) => [$l], array_keys($opts)));
    }

    private function buyPicked(BotUser $u, string $text): void
    {
        $p = $this->picked($u, $text);
        if (! $p) {
            $this->ask($u, $u->state, $u->state_data ?? [], 'لطفاً یکی از بسته‌های فهرست را انتخاب کنید.', array_map(fn ($l) => [$l], array_keys($u->state_data['opts'] ?? [])));

            return;
        }
        $target = ! empty($u->state_data['mt_account_id']) ? $u->mtAccounts()->whereKey($u->state_data['mt_account_id'])->first()
            : (! empty($u->state_data['account_id']) ? $u->accounts()->whereKey($u->state_data['account_id'])->first() : null);
        try {
            $order = $this->orders()->create($u, $p, $target);
        } catch (RuntimeException $e) {
            $u->setState(null);
            $this->menu($u, '❗ '.$e->getMessage());

            return;
        }
        $balance = $this->orders()->walletBalance($u);
        $intro = '🧾 سفارش #'.fa((string) $order->id)."\nبسته: ".$p->name.' — '.$p->summary()."\nمبلغ: ".fa_money($order->price_rial);
        $this->payChoice($u, ['order_id' => $order->id, 'price' => $order->price_rial, 'amount' => max($order->price_rial - $balance, 0)], $intro, $balance >= $order->price_rial);
    }

    private function topupAmount(BotUser $u, string $text): void
    {
        $toman = (int) preg_replace('/\D/', '', strtr($text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', ',' => '', '٬' => '']));
        if ($toman * 10 < Payments::MIN_RIAL || $toman * 10 > Payments::MAX_RIAL) {
            $this->ask($u, 'topup_amount', [], 'مبلغ نامعتبر است. مبلغ را به تومان بفرستید:');

            return;
        }
        $this->payChoice($u, ['amount' => $toman * 10], 'شارژ کیف پول: '.fa_money($toman * 10), false);
    }

    /** Offer the enabled payment methods (and the wallet, when it already covers the order). */
    private function payChoice(BotUser $u, array $data, string $intro, bool $walletCovers): void
    {
        $opts = [];
        if ($walletCovers) {
            $opts[self::PAY_WALLET] = 'wallet';
        }
        $enabled = $this->payments()->enabledMethods();
        $online = array_values(array_intersect(array_keys($enabled), Payment::ONLINE));
        foreach ($online as $m) {
            $opts[count($online) > 1 ? '💳 '.$enabled[$m] : self::PAY_ONLINE] = $m;
        }
        if (isset($enabled['card'])) {
            $opts[self::PAY_CARD] = 'card';
        }
        if (isset($enabled['usdt'])) {
            $opts[self::PAY_USDT] = 'usdt';
        }
        if (! $opts) {
            $u->setState(null);
            $this->menu($u, $intro."\n\nفعلاً روش پرداختی فعال نیست. با پشتیبانی تماس بگیرید.");

            return;
        }
        $amountLine = ! $walletCovers && isset($data['price']) && $data['amount'] < $data['price']
            ? "\nمبلغ قابل پرداخت (پس از کسر موجودی کیف پول): ".fa_money(max($data['amount'], Payments::MIN_RIAL)) : '';
        $this->ask($u, 'pay_method', $data + ['opts' => $opts], $intro.$amountLine."\n\nروش پرداخت را انتخاب کنید:", array_map(fn ($l) => [$l], array_keys($opts)));
    }

    private function payMethod(BotUser $u, string $text): void
    {
        $d = $u->state_data ?? [];
        $method = ($d['opts'] ?? [])[$text] ?? null;
        if (! $method) {
            $this->ask($u, 'pay_method', $d, 'لطفاً یکی از روش‌های پرداخت را انتخاب کنید.', array_map(fn ($l) => [$l], array_keys($d['opts'] ?? [])));

            return;
        }
        if ($method === 'wallet') {
            $u->setState(null);
            $order = CustomerOrder::query()->where('bot_user_id', $u->id)->find($d['order_id'] ?? 0);
            if (! $order) {
                $this->menu($u, 'سفارش پیدا نشد.');

                return;
            }
            $order = $this->orders()->fulfil($order);
            app(Outbox::class)->flush(20);
            $this->menu($u, $order->status === 'awaiting_payment' ? '❗ موجودی کیف پول کافی نیست.' : 'سفارش ثبت شد؛ نتیجه را همین‌جا می‌بینید.');

            return;
        }
        if ($method === 'usdt') {
            $nets = array_intersect_key(Payment::NETWORKS, $this->payments()->usdtNetworks());
            if (count($nets) > 1) {
                $o = [];
                foreach ($nets as $k => $l) {
                    $o['🔗 '.$l] = $k;
                }
                $this->ask($u, 'usdt_net', ['order_id' => $d['order_id'] ?? null, 'amount' => $d['amount'], 'price' => $d['price'] ?? null, 'opts' => $o], 'شبکه انتقال تتر را انتخاب کنید:', array_map(fn ($l) => [$l], array_keys($o)));

                return;
            }
            $d['network'] = (string) array_key_first($nets);
        }
        $this->createPayment($u, $method, $d);
    }

    private function usdtNetwork(BotUser $u, string $text): void
    {
        $d = $u->state_data ?? [];
        $net = ($d['opts'] ?? [])[$text] ?? null;
        if (! $net) {
            $this->ask($u, 'usdt_net', $d, 'لطفاً یکی از شبکه‌ها را انتخاب کنید.', array_map(fn ($l) => [$l], array_keys($d['opts'] ?? [])));

            return;
        }
        $this->createPayment($u, 'usdt', $d + ['network' => $net]);
    }

    private function createPayment(BotUser $u, string $method, array $d): void
    {
        $u->setState(null);
        $r = empty($d['order_id']) ? $this->reseller($u) : null;
        $wallet = $r ? $this->wallets->walletFor('reseller', $r->id) : $this->wallets->walletFor('customer', $u->id);
        $amount = max((int) $d['amount'], Payments::MIN_RIAL);
        try {
            $p = $this->payments()->create($method, $wallet, $amount, [
                'purpose' => empty($d['order_id']) ? 'topup' : 'order', 'bot_user_id' => $u->id,
                'created_by' => $u->user_id, 'usdt_network' => $method === 'usdt' ? ($d['network'] ?? null) : null,
            ]);
        } catch (RuntimeException $e) {
            $this->menu($u, '❗ '.$e->getMessage());

            return;
        }
        if (! empty($d['order_id'])) {
            CustomerOrder::query()->where('bot_user_id', $u->id)->whereKey($d['order_id'])->update(['payment_id' => $p->id]);
        }
        $link = route('pay.show', $p->uuid);
        if ($p->isOnline()) {
            $this->menu($u, '💳 پرداخت '.fa_money($p->amount_rial)."\n\nبرای پرداخت روی لینک زیر بزنید:\n".$link."\n\nپس از پرداخت، نتیجه همین‌جا اعلام می‌شود.");

            return;
        }
        if ($method === 'card') {
            $lines = ['🏦 مبلغ '.fa_money($p->amount_rial).' را دقیقاً به یکی از کارت‌های زیر واریز کنید:', ''];
            foreach ($this->payments()->cards() as $c) {
                $lines[] = trim(chunk_split($c['number'], 4, ' ')).' — '.($c['holder'] ?? '').' ('.($c['bank'] ?? '').')';
            }
            $lines[] = '';
            $lines[] = 'سپس «شماره پیگیری» و «۴ رقم آخر کارت خودتان» را در یک پیام بفرستید.';
            $lines[] = 'برای ارسال تصویر رسید: '.$link;
            $this->ask($u, 'card_note', ['payment_id' => $p->id], implode("\n", $lines));

            return;
        }
        $this->ask($u, 'usdt_hash', ['payment_id' => $p->id], '💵 دقیقاً '.rtrim(rtrim((string) $p->usdt_amount, '0'), '.').' USDT در شبکه '
            .(Payment::NETWORKS[$p->usdt_network] ?? '')." به این آدرس بفرستید:\n\n".($this->payments()->usdtNetworks()[$p->usdt_network] ?? '')
            ."\n\nارسال در شبکه یا توکن دیگر قابل بازگشت نیست. پس از ارسال، «هش تراکنش» (TxID) را اینجا بفرستید.");
    }

    private function cardNote(BotUser $u, string $text): void
    {
        $p = Payment::query()->where('bot_user_id', $u->id)->find($u->state_data['payment_id'] ?? 0);
        $u->setState(null);
        if (! $p) {
            $this->menu($u, 'پرداخت پیدا نشد.');

            return;
        }
        try {
            $this->payments()->reportCard($p, mb_substr($text, 0, 250));
        } catch (RuntimeException $e) {
            $this->menu($u, '❗ '.$e->getMessage());

            return;
        }
        $this->menu($u, '✅ اطلاعات واریز ثبت شد. پس از بررسی مدیر، نتیجه همین‌جا اعلام می‌شود.');
    }

    private function usdtHash(BotUser $u, string $text): void
    {
        $p = Payment::query()->where('bot_user_id', $u->id)->find($u->state_data['payment_id'] ?? 0);
        if (! $p) {
            $u->setState(null);
            $this->menu($u, 'پرداخت پیدا نشد.');

            return;
        }
        try {
            $p = $this->payments()->submitUsdtHash($p, $text);
        } catch (RuntimeException $e) {
            $this->ask($u, 'usdt_hash', ['payment_id' => $p->id], '❗ '.$e->getMessage().' دوباره هش را بفرستید:');

            return;
        }
        $u->setState(null);
        app(Outbox::class)->flush(20);
        $this->menu($u, $p->status === 'paid' ? '✅ تراکنش تأیید شد.' : '⏳ هش ثبت شد: '.($p->error ?? 'در انتظار تأیید شبکه').' — خودکار دوباره بررسی و نتیجه اعلام می‌شود.');
    }

    private function welcome(): string
    {
        return $this->settings->get('bots.welcome_text') ?: 'سلام! به ربات خوش آمدید. از منوی زیر استفاده کنید.';
    }
}
