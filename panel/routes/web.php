<?php

use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\AccountsController;
use App\Http\Controllers\Admin\AlertsController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BotsController;
use App\Http\Controllers\Admin\CleanupController;
use App\Http\Controllers\Admin\ConfigFilesController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DiscountsController;
use App\Http\Controllers\Admin\IbsGroupsController;
use App\Http\Controllers\Admin\IbsSettingsController;
use App\Http\Controllers\Admin\MtAccountsController;
use App\Http\Controllers\Admin\MtGroupsController;
use App\Http\Controllers\Admin\NoticesController;
use App\Http\Controllers\Admin\PackagesController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\ProvisioningJobsController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\ResellersController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RoutersController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\BotLinkController;
use App\Http\Controllers\Bots\WebhookController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\Pay\PayController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\PwaController;
use App\Http\Middleware\EnsureReseller;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/login/captcha', [LoginController::class, 'captcha'])->name('captcha')->middleware('throttle:30,1');
});

// Installable app (PWA): public manifest and icons
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/pwa/icon-{size}.png', [PwaController::class, 'icon'])->whereNumber('size')->name('pwa.icon');
// Direct link to a client config file (secret token; sent to customers)
Route::get('/f/{token}', [ConfigFilesController::class, 'public'])->where('token', '[A-Za-z0-9]{32,64}')->middleware('throttle:30,1')->name('config-files.public');

// Web-cron for hosts without CLI cron: /cron/{key}. Key is set by the installer.
Route::get('/cron/{key}', CronController::class)->middleware('throttle:10,1')->name('cron');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/confirm-password', [PasswordController::class, 'showConfirm'])->name('password.confirm');
    Route::post('/confirm-password', [PasswordController::class, 'confirm'])->middleware('throttle:10,1');
    Route::get('/profile/password', [PasswordController::class, 'edit'])->name('profile.password');
    Route::put('/profile/password', [PasswordController::class, 'update'])->name('profile.password.update')->middleware('throttle:10,1');

    // Staff
    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('/staff/create', [StaffController::class, 'create'])->name('staff.create');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('/staff/{staff}/edit', [StaffController::class, 'edit'])->name('staff.edit');
    Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::middleware(RequireRecentPasswordConfirmation::class)->group(function () {
        Route::post('/staff/{staff}/toggle-active', [StaffController::class, 'toggleActive'])->name('staff.toggle');
        Route::post('/staff/{staff}/reset-password', [StaffController::class, 'resetPassword'])->name('staff.reset-password');
        Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        Route::put('/settings/ibsng', [IbsSettingsController::class, 'update'])->name('ibs.settings.update');
        Route::put('/bots/{platform}', [BotsController::class, 'update'])->name('bots.update');
        Route::post('/bots/{platform}/rotate', [BotsController::class, 'rotateSecret'])->name('bots.rotate');
        Route::get('/bots/relay/{kind}', [BotsController::class, 'relay'])->name('bots.relay');
        Route::get('/routers/create', [RoutersController::class, 'create'])->name('routers.create');
        Route::post('/routers', [RoutersController::class, 'store'])->name('routers.store');
        Route::get('/routers/{router}/edit', [RoutersController::class, 'edit'])->name('routers.edit');
        Route::put('/routers/{router}', [RoutersController::class, 'update'])->name('routers.update');
        Route::delete('/routers/{router}', [RoutersController::class, 'destroy'])->name('routers.destroy');
        Route::post('/routers/{router}/pin', [RoutersController::class, 'fetchPin'])->name('routers.pin')->middleware('throttle:10,1');
        Route::post('/routers/{router}/pin/trust', [RoutersController::class, 'trustPin'])->name('routers.pin.trust');
        Route::post('/payments/{payment}/approve', [PaymentsController::class, 'approve'])->name('payments.approve');
        Route::post('/payments/{payment}/reject', [PaymentsController::class, 'reject'])->name('payments.reject');
        Route::post('/customer-orders/{order}/refund', [PaymentsController::class, 'refundOrder'])->name('orders.refund');
        Route::get('/settings/payments', [PaymentSettingsController::class, 'edit'])->name('payments.settings');
        Route::put('/settings/payments', [PaymentSettingsController::class, 'update'])->name('payments.settings.update');
        Route::delete('/accounts/{account}', [AccountsController::class, 'destroy'])->name('accounts.destroy');
        Route::post('/accounts/cleanup', [CleanupController::class, 'start'])->name('accounts.cleanup.start');
        Route::post('/accounts/{account}/reveal', [AccountsController::class, 'reveal'])->name('accounts.reveal')->middleware('throttle:20,1');
        Route::post('/resellers/{reseller}/adjust', [ResellersController::class, 'adjust'])->name('resellers.adjust');
        Route::post('/resellers/{reseller}/ledger/{entry}/reverse', [ResellersController::class, 'reverse'])->name('resellers.reverse');
        Route::post('/resellers/{reseller}/sales/{sale}/refund', [ResellersController::class, 'refundSale'])->name('resellers.refund-sale');
        Route::get('/mt/accounts/{account}/connect', [MtAccountsController::class, 'connect'])->name('mt.accounts.connect')->whereNumber('account');
        Route::post('/mt/accounts/{account}/password', [MtAccountsController::class, 'password'])->name('mt.accounts.password');
        Route::post('/mt/accounts/{account}/keys', [MtAccountsController::class, 'keys'])->name('mt.accounts.keys');
        Route::post('/mt/accounts/{account}/private-key', [MtAccountsController::class, 'privateKey'])->name('mt.accounts.private-key');
        Route::delete('/mt/accounts/{account}', [MtAccountsController::class, 'destroy'])->name('mt.accounts.destroy');
        Route::post('/accounts/jobs/{job}/resolve', [ProvisioningJobsController::class, 'resolve'])->name('accounts.jobs.resolve');
    });

    // Payments
    Route::get('/payments', [PaymentsController::class, 'index'])->name('payments.index');
    Route::get('/payments/{payment}', [PaymentsController::class, 'show'])->name('payments.show');
    Route::get('/payments/{payment}/receipt', [PaymentsController::class, 'receipt'])->name('payments.receipt');
    Route::post('/payments/{payment}/recheck', [PaymentsController::class, 'recheck'])->name('payments.recheck')->middleware('throttle:20,1');

    // MikroTik routers
    Route::get('/routers', [RoutersController::class, 'index'])->name('routers.index');
    Route::get('/routers/{router}', [RoutersController::class, 'show'])->name('routers.show')->whereNumber('router');
    Route::post('/routers/{router}/test', [RoutersController::class, 'test'])->name('routers.test')->middleware('throttle:20,1');
    Route::post('/routers/{router}/protocol', [RoutersController::class, 'toggleProtocol'])->name('routers.protocol');

    // MikroTik groups and accounts
    Route::get('/mt/groups', [MtGroupsController::class, 'index'])->name('mt.groups.index');
    Route::get('/mt/groups/create', [MtGroupsController::class, 'create'])->name('mt.groups.create');
    Route::post('/mt/groups', [MtGroupsController::class, 'store'])->name('mt.groups.store')->middleware('throttle:20,1');
    Route::get('/mt/groups/{group}/edit', [MtGroupsController::class, 'edit'])->name('mt.groups.edit');
    Route::put('/mt/groups/{group}', [MtGroupsController::class, 'update'])->name('mt.groups.update')->middleware('throttle:20,1');
    Route::delete('/mt/groups/{group}', [MtGroupsController::class, 'destroy'])->name('mt.groups.destroy');
    Route::get('/mt/accounts', [MtAccountsController::class, 'index'])->name('mt.accounts.index');
    Route::get('/l2tp/accounts', [MtAccountsController::class, 'index'])->defaults('proto', 'l2tp')->name('l2tp.accounts');
    Route::get('/wireguard/accounts', [MtAccountsController::class, 'index'])->defaults('proto', 'wireguard')->name('wg.accounts');
    Route::get('/l2tp/groups', [MtGroupsController::class, 'index'])->defaults('proto', 'l2tp')->name('l2tp.groups');
    Route::get('/wireguard/groups', [MtGroupsController::class, 'index'])->defaults('proto', 'wireguard')->name('wg.groups');
    Route::get('/mt/groups/{group}/import', [MtAccountsController::class, 'importForm'])->name('mt.import');
    Route::post('/mt/groups/{group}/import', [MtAccountsController::class, 'import'])->name('mt.import.store')->middleware('throttle:20,1');
    Route::get('/mt/accounts/create', [MtAccountsController::class, 'create'])->name('mt.accounts.create');
    Route::post('/mt/accounts', [MtAccountsController::class, 'store'])->name('mt.accounts.store')->middleware('throttle:30,1');
    Route::post('/mt/accounts/from-package', [MtAccountsController::class, 'storeFromPackage'])->name('mt.accounts.store-package')->middleware('throttle:30,1');
    Route::get('/mt/accounts/{account}', [MtAccountsController::class, 'show'])->name('mt.accounts.show')->whereNumber('account')->withTrashed();
    Route::post('/mt/accounts/{account}/renew', [MtAccountsController::class, 'renew'])->name('mt.accounts.renew');
    Route::post('/mt/accounts/{account}/volume', [MtAccountsController::class, 'volume'])->name('mt.accounts.volume');
    Route::post('/mt/accounts/{account}/limits', [MtAccountsController::class, 'limits'])->name('mt.accounts.limits');
    Route::post('/mt/accounts/{account}/package', [MtAccountsController::class, 'applyPackage'])->name('mt.accounts.package');
    Route::post('/mt/accounts/{account}/reset', [MtAccountsController::class, 'resetUsage'])->name('mt.accounts.reset');
    Route::post('/mt/accounts/{account}/reset-first-use', [MtAccountsController::class, 'resetFirstUse'])->name('mt.accounts.reset-first');
    Route::post('/mt/accounts/{account}/info', [MtAccountsController::class, 'updateInfo'])->name('mt.accounts.info');
    Route::post('/mt/accounts/{account}/kick', [MtAccountsController::class, 'kick'])->name('mt.accounts.kick')->middleware('throttle:20,1');
    Route::post('/mt/accounts/{account}/toggle', [MtAccountsController::class, 'toggle'])->name('mt.accounts.toggle');
    Route::post('/mt/accounts/{account}/retry', [MtAccountsController::class, 'retry'])->name('mt.accounts.retry')->middleware('throttle:20,1');
    Route::post('/mt/accounts/{account}/sync', [MtAccountsController::class, 'sync'])->name('mt.accounts.sync')->middleware('throttle:20,1');

    // Reports
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/export/{type}', [ReportsController::class, 'export'])->name('reports.export')->middleware('throttle:20,1');

    // Alerts
    Route::get('/settings/alerts', [AlertsController::class, 'edit'])->name('alerts.settings');
    Route::put('/settings/alerts', [AlertsController::class, 'update'])->name('alerts.settings.update');
    Route::post('/settings/alerts/test', [AlertsController::class, 'testReport'])->name('alerts.test')->middleware('throttle:5,1');
    Route::get('/bots/outbox', [AlertsController::class, 'outbox'])->name('bots.outbox');

    // Bots
    Route::get('/bots', [BotsController::class, 'index'])->name('bots.index');
    Route::put('/bots-texts', [BotsController::class, 'texts'])->name('bots.texts');
    Route::post('/bots/reach', [BotsController::class, 'reach'])->name('bots.reach')->middleware('throttle:10,1');
    Route::post('/bots/{platform}/connect', [BotsController::class, 'connect'])->name('bots.connect')->middleware('throttle:10,1');
    Route::post('/bots/{platform}/test', [BotsController::class, 'sendTest'])->name('bots.test')->middleware('throttle:10,1');
    Route::get('/bot-link', [BotLinkController::class, 'show'])->name('bot-link');
    Route::post('/bot-link', [BotLinkController::class, 'create'])->name('bot-link.create')->middleware('throttle:10,1');
    Route::delete('/bot-link/{botUser}', [BotLinkController::class, 'unlink'])->name('bot-link.unlink');

    // IBSng connection, groups
    Route::get('/settings/ibsng', [IbsSettingsController::class, 'edit'])->name('ibs.settings');
    Route::post('/settings/ibsng/test', [IbsSettingsController::class, 'test'])->name('ibs.settings.test')->middleware('throttle:10,1');
    Route::post('/ibs/groups/sync', [IbsSettingsController::class, 'syncCatalog'])->name('ibs.groups.sync')->middleware('throttle:10,1');
    Route::get('/ibs/groups', [IbsGroupsController::class, 'index'])->name('ibs.groups');
    Route::get('/config-files', [ConfigFilesController::class, 'index'])->name('config-files.index');
    Route::post('/config-files', [ConfigFilesController::class, 'store'])->name('config-files.store')->middleware('throttle:20,1');
    Route::put('/config-files/{file}', [ConfigFilesController::class, 'update'])->name('config-files.update')->middleware('throttle:20,1');
    Route::post('/config-files/{file}/new-link', [ConfigFilesController::class, 'newLink'])->name('config-files.new-link')->middleware('throttle:20,1');
    Route::delete('/config-files/{file}', [ConfigFilesController::class, 'destroy'])->name('config-files.destroy');
    Route::get('/config-files/{file}/download', [ConfigFilesController::class, 'download'])->name('config-files.download');
    Route::post('/ibs/groups', [IbsGroupsController::class, 'store'])->name('ibs.groups.store')->middleware('throttle:20,1');
    Route::get('/ibs/groups/{name}/edit', [IbsGroupsController::class, 'edit'])->name('ibs.groups.edit')->where('name', '[A-Za-z0-9_-]+');
    Route::put('/ibs/groups/{name}', [IbsGroupsController::class, 'update'])->name('ibs.groups.update')->where('name', '[A-Za-z0-9_-]+')->middleware('throttle:20,1');
    Route::delete('/ibs/groups/{name}', [IbsGroupsController::class, 'destroy'])->name('ibs.groups.destroy')->where('name', '[A-Za-z0-9_-]+')->middleware('throttle:20,1');
    Route::post('/ibs/charges', [IbsGroupsController::class, 'storeCharge'])->name('ibs.charges.store')->middleware('throttle:20,1');
    Route::delete('/ibs/charges/{name}', [IbsGroupsController::class, 'destroyCharge'])->name('ibs.charges.destroy')->where('name', '[A-Za-z0-9_-]+')->middleware('throttle:20,1');

    // Accounts
    Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [AccountsController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [AccountsController::class, 'store'])->name('accounts.store')->middleware('throttle:30,1');
    Route::post('/accounts/import', [AccountsController::class, 'importOne'])->name('accounts.import')->middleware('throttle:30,1');
    Route::post('/accounts/import-all', [AccountsController::class, 'importAll'])->name('accounts.import-all');
    Route::post('/accounts/import-continue', [AccountsController::class, 'importContinue'])->name('accounts.import-continue')->middleware('throttle:10,1');
    Route::get('/accounts/jobs', [ProvisioningJobsController::class, 'index'])->name('accounts.jobs');
    Route::get('/accounts/cleanup', [CleanupController::class, 'show'])->name('accounts.cleanup');
    Route::post('/accounts/cleanup/continue', [CleanupController::class, 'continue'])->name('accounts.cleanup.continue')->middleware('throttle:10,1');
    Route::post('/accounts/cleanup/cancel', [CleanupController::class, 'cancel'])->name('accounts.cleanup.cancel');
    Route::post('/accounts/jobs/{job}/retry', [ProvisioningJobsController::class, 'retry'])->name('accounts.jobs.retry')->middleware('throttle:20,1');
    Route::get('/accounts/{account}', [AccountsController::class, 'show'])->name('accounts.show')->whereNumber('account');
    Route::post('/accounts/{account}/refresh', [AccountsController::class, 'refresh'])->name('accounts.refresh')->middleware('throttle:30,1');
    Route::post('/accounts/{account}/lock', [AccountsController::class, 'lock'])->name('accounts.lock');
    Route::post('/accounts/{account}/password', [AccountsController::class, 'password'])->name('accounts.password');
    Route::post('/accounts/{account}/info', [AccountsController::class, 'updateInfo'])->name('accounts.info');
    Route::post('/accounts/{account}/kick', [AccountsController::class, 'kick'])->name('accounts.kick')->middleware('throttle:20,1');
    Route::post('/accounts/{account}/renew', [AccountsController::class, 'renew'])->name('accounts.renew');
    Route::post('/accounts/{account}/reset-first-login', [AccountsController::class, 'resetFirstLogin'])->name('accounts.reset-first');
    Route::post('/accounts/{account}/volume', [AccountsController::class, 'volume'])->name('accounts.volume');
    Route::post('/accounts/{account}/apply-package', [AccountsController::class, 'applyPackage'])->name('accounts.apply-package');
    Route::post('/accounts/from-package', [AccountsController::class, 'storeFromPackage'])->name('accounts.store-package')->middleware('throttle:30,1');

    // Resellers (admin side)
    Route::get('/resellers', [ResellersController::class, 'index'])->name('resellers.index');
    Route::get('/resellers/create', [ResellersController::class, 'create'])->name('resellers.create');
    Route::post('/resellers', [ResellersController::class, 'store'])->name('resellers.store');
    Route::get('/resellers/{reseller}', [ResellersController::class, 'show'])->name('resellers.show')->whereNumber('reseller');
    Route::put('/resellers/{reseller}', [ResellersController::class, 'update'])->name('resellers.update');
    Route::post('/resellers/{reseller}/accounts/bulk', [ResellersController::class, 'bulkAccounts'])->name('resellers.bulk')->middleware('throttle:20,1');
    Route::post('/resellers/{reseller}/category-discount', [ResellersController::class, 'setCategoryDiscount'])->name('resellers.category-discount');
    Route::post('/resellers/{reseller}/fixed-price', [ResellersController::class, 'setFixedPrice'])->name('resellers.fixed-price');

    // Reseller portal
    Route::prefix('r')->name('portal.')->middleware(EnsureReseller::class)->group(function () {
        Route::get('/topup', [PortalController::class, 'topupForm'])->name('topup');
        Route::get('/export/{type}', [PortalController::class, 'export'])->name('export')->middleware('throttle:20,1');
        Route::post('/topup', [PortalController::class, 'topup'])->name('topup.store')->middleware('throttle:20,1');
        Route::post('/topup/card', [PortalController::class, 'topupCard'])->name('topup.card')->middleware('throttle:10,1');
        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/prices', [PortalController::class, 'prices'])->name('prices');
        Route::get('/sell', [PortalController::class, 'sellForm'])->name('sell');
        Route::post('/sell', [PortalController::class, 'sell'])->name('sell.store')->middleware('throttle:30,1');
        Route::get('/sales', [PortalController::class, 'sales'])->name('sales');
        Route::get('/sales/{sale}', [PortalController::class, 'sale'])->name('sales.show')->whereNumber('sale');
        Route::get('/accounts', [PortalController::class, 'accounts'])->name('accounts');
        Route::get('/accounts/{account}', [PortalController::class, 'account'])->name('accounts.show')->whereNumber('account');
        Route::get('/mt/{account}', [PortalController::class, 'mtAccount'])->name('mt.show')->whereNumber('account');
        Route::get('/ledger', [PortalController::class, 'ledger'])->name('ledger');
    });

    // Packages, categories, discounts
    Route::get('/packages', [PackagesController::class, 'index'])->name('packages.index');
    Route::get('/packages/create', [PackagesController::class, 'create'])->name('packages.create');
    Route::post('/packages', [PackagesController::class, 'store'])->name('packages.store');
    Route::get('/packages/{package}/edit', [PackagesController::class, 'edit'])->name('packages.edit');
    Route::put('/packages/{package}', [PackagesController::class, 'update'])->name('packages.update');
    Route::delete('/packages/{package}', [PackagesController::class, 'destroy'])->name('packages.destroy');
    Route::post('/package-categories', [PackagesController::class, 'storeCategory'])->name('packages.categories.store');
    Route::delete('/package-categories/{category}', [PackagesController::class, 'destroyCategory'])->name('packages.categories.destroy');
    Route::get('/discounts', [DiscountsController::class, 'index'])->name('discounts.index');
    Route::get('/discounts/create', [DiscountsController::class, 'create'])->name('discounts.create');
    Route::post('/discounts', [DiscountsController::class, 'store'])->name('discounts.store');
    Route::get('/discounts/{discount}/edit', [DiscountsController::class, 'edit'])->name('discounts.edit');
    Route::put('/discounts/{discount}', [DiscountsController::class, 'update'])->name('discounts.update');
    Route::delete('/discounts/{discount}', [DiscountsController::class, 'destroy'])->name('discounts.destroy');

    // Roles
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');

    // Audit, settings, system
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/audit/{log}', [AuditController::class, 'show'])->name('audit.show')->whereNumber('log');
    Route::post('/audit/verify', [AuditController::class, 'verify'])->name('audit.verify')->middleware('throttle:5,1');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::get('/settings/app', [PwaController::class, 'edit'])->name('settings.app');
    Route::put('/settings/app', [PwaController::class, 'update'])->name('settings.app.update')->middleware('throttle:20,1');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/system', [SystemController::class, 'status'])->name('system.status');
    Route::get('/notices', [NoticesController::class, 'index'])->name('notices.index');
    Route::get('/accounting/topups', [AccountingController::class, 'topups'])->name('accounting.topups');
    Route::get('/accounting/cards', [AccountingController::class, 'cards'])->name('accounting.cards');
    Route::put('/accounting/cards', [AccountingController::class, 'saveCards'])->name('accounting.cards.save')->middleware('throttle:20,1');
    Route::get('/notices/poll', [NoticesController::class, 'poll'])->name('notices.poll')->middleware('throttle:30,1');
    Route::post('/notices/test', [NoticesController::class, 'test'])->name('notices.test')->middleware('throttle:10,1');
    Route::post('/system/upgrade', [SystemController::class, 'upgrade'])->name('system.upgrade')->middleware('throttle:10,1');
    Route::get('/system/update', [SystemController::class, 'updateForm'])->name('system.update');
    Route::post('/system/update', [SystemController::class, 'updateUpload'])->name('system.update.store')->middleware('throttle:10,1');
});

// Messenger webhooks (no session auth; secured by the random path secret and platform headers)
Route::post('/hook/{platform}/{secret}', WebhookController::class)->where('platform', 'telegram|bale|rubika')->where('secret', '[A-Za-z0-9]{20,64}')->middleware('throttle:600,1')->name('bots.hook');

// Public payment pages (addressed by the payment's random UUID)
Route::get('/pay/{uuid}', [PayController::class, 'show'])->name('pay.show')->middleware('throttle:60,1');
Route::post('/pay/{uuid}/go', [PayController::class, 'go'])->name('pay.go')->middleware('throttle:20,1');
Route::post('/pay/{uuid}/card', [PayController::class, 'card'])->name('pay.card')->middleware('throttle:10,1');
Route::post('/pay/{uuid}/usdt', [PayController::class, 'usdt'])->name('pay.usdt')->middleware('throttle:10,1');
Route::match(['get', 'post'], '/pay/callback/{uuid}', [PayController::class, 'callback'])->name('pay.callback')->middleware('throttle:60,1');
