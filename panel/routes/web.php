<?php

use App\Http\Controllers\Admin\AccountsController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DiscountsController;
use App\Http\Controllers\Admin\IbsGroupsController;
use App\Http\Controllers\Admin\IbsSettingsController;
use App\Http\Controllers\Admin\PackagesController;
use App\Http\Controllers\Admin\ProvisioningJobsController;
use App\Http\Controllers\Admin\ResellersController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Middleware\EnsureReseller;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1');
});

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
        Route::delete('/accounts/{account}', [AccountsController::class, 'destroy'])->name('accounts.destroy');
        Route::post('/accounts/{account}/reveal', [AccountsController::class, 'reveal'])->name('accounts.reveal')->middleware('throttle:20,1');
        Route::post('/resellers/{reseller}/adjust', [ResellersController::class, 'adjust'])->name('resellers.adjust');
        Route::post('/resellers/{reseller}/ledger/{entry}/reverse', [ResellersController::class, 'reverse'])->name('resellers.reverse');
        Route::post('/resellers/{reseller}/sales/{sale}/refund', [ResellersController::class, 'refundSale'])->name('resellers.refund-sale');
        Route::post('/accounts/jobs/{job}/resolve', [ProvisioningJobsController::class, 'resolve'])->name('accounts.jobs.resolve');
    });

    // IBSng connection, groups
    Route::get('/settings/ibsng', [IbsSettingsController::class, 'edit'])->name('ibs.settings');
    Route::post('/settings/ibsng/test', [IbsSettingsController::class, 'test'])->name('ibs.settings.test')->middleware('throttle:10,1');
    Route::post('/ibs/groups/sync', [IbsSettingsController::class, 'syncCatalog'])->name('ibs.groups.sync')->middleware('throttle:10,1');
    Route::get('/ibs/groups', [IbsGroupsController::class, 'index'])->name('ibs.groups');

    // Accounts
    Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [AccountsController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [AccountsController::class, 'store'])->name('accounts.store')->middleware('throttle:30,1');
    Route::post('/accounts/import', [AccountsController::class, 'importOne'])->name('accounts.import')->middleware('throttle:30,1');
    Route::post('/accounts/import-all', [AccountsController::class, 'importAll'])->name('accounts.import-all');
    Route::post('/accounts/import-continue', [AccountsController::class, 'importContinue'])->name('accounts.import-continue')->middleware('throttle:10,1');
    Route::get('/accounts/jobs', [ProvisioningJobsController::class, 'index'])->name('accounts.jobs');
    Route::post('/accounts/jobs/{job}/retry', [ProvisioningJobsController::class, 'retry'])->name('accounts.jobs.retry')->middleware('throttle:20,1');
    Route::get('/accounts/{account}', [AccountsController::class, 'show'])->name('accounts.show')->whereNumber('account');
    Route::post('/accounts/{account}/refresh', [AccountsController::class, 'refresh'])->name('accounts.refresh')->middleware('throttle:30,1');
    Route::post('/accounts/{account}/lock', [AccountsController::class, 'lock'])->name('accounts.lock');
    Route::post('/accounts/{account}/password', [AccountsController::class, 'password'])->name('accounts.password');
    Route::post('/accounts/{account}/renew', [AccountsController::class, 'renew'])->name('accounts.renew');
    Route::post('/accounts/{account}/volume', [AccountsController::class, 'volume'])->name('accounts.volume');
    Route::post('/accounts/{account}/apply-package', [AccountsController::class, 'applyPackage'])->name('accounts.apply-package');
    Route::post('/accounts/from-package', [AccountsController::class, 'storeFromPackage'])->name('accounts.store-package')->middleware('throttle:30,1');

    // Resellers (admin side)
    Route::get('/resellers', [ResellersController::class, 'index'])->name('resellers.index');
    Route::get('/resellers/create', [ResellersController::class, 'create'])->name('resellers.create');
    Route::post('/resellers', [ResellersController::class, 'store'])->name('resellers.store');
    Route::get('/resellers/{reseller}', [ResellersController::class, 'show'])->name('resellers.show')->whereNumber('reseller');
    Route::put('/resellers/{reseller}', [ResellersController::class, 'update'])->name('resellers.update');
    Route::post('/resellers/{reseller}/category-discount', [ResellersController::class, 'setCategoryDiscount'])->name('resellers.category-discount');
    Route::post('/resellers/{reseller}/fixed-price', [ResellersController::class, 'setFixedPrice'])->name('resellers.fixed-price');

    // Reseller portal
    Route::prefix('r')->name('portal.')->middleware(EnsureReseller::class)->group(function () {
        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/prices', [PortalController::class, 'prices'])->name('prices');
        Route::get('/sell', [PortalController::class, 'sellForm'])->name('sell');
        Route::post('/sell', [PortalController::class, 'sell'])->name('sell.store')->middleware('throttle:30,1');
        Route::get('/sales', [PortalController::class, 'sales'])->name('sales');
        Route::get('/sales/{sale}', [PortalController::class, 'sale'])->name('sales.show')->whereNumber('sale');
        Route::get('/accounts', [PortalController::class, 'accounts'])->name('accounts');
        Route::get('/accounts/{account}', [PortalController::class, 'account'])->name('accounts.show')->whereNumber('account');
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
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/system', [SystemController::class, 'status'])->name('system.status');
    Route::post('/system/upgrade', [SystemController::class, 'upgrade'])->name('system.upgrade')->middleware('throttle:10,1');
});
