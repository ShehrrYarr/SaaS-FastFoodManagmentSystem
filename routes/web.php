<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Models\Shop;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landing: find your shop
|--------------------------------------------------------------------------
*/
Route::livewire('/', 'pages::home')->name('home');

/*
|--------------------------------------------------------------------------
| Super Admin  (/super-admin/...)
|--------------------------------------------------------------------------
*/
Route::prefix('super-admin')->name('admin.')->group(function () {
    Route::livewire('login', 'pages::admin.login')->name('login');

    Route::middleware(['auth:admin', 'super.admin'])->group(function () {
        Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
        Route::livewire('shops', 'pages::admin.shops.index')->name('shops.index');
        Route::livewire('shops/create', 'pages::admin.shops.create')->name('shops.create');
        Route::livewire('shops/{shop}', 'pages::admin.shops.show')->whereNumber('shop')->name('shops.show');
        Route::livewire('admins', 'pages::admin.admins')->name('admins.index');
        Route::post('logout', [LogoutController::class, 'admin'])->name('logout');
    });
});

/*
|--------------------------------------------------------------------------
| Herozi template demo pages (local development reference only)
|--------------------------------------------------------------------------
*/
if (app()->isLocal()) {
    Route::get('_herozi/{page?}', [DashboardController::class, 'index'])
        ->where('page', '[A-Za-z0-9\-]+')
        ->name('herozi.demo');
}

/*
|--------------------------------------------------------------------------
| Shop workspace  (/{shop}/...)  -- must stay last
|--------------------------------------------------------------------------
*/
Route::prefix('{shop}')
    ->where(['shop' => Shop::PREFIX_PATTERN])
    ->middleware('shop.resolve')
    ->name('shop.')
    ->group(function () {
        Route::livewire('login', 'pages::shop.login')->name('login');

        Route::middleware(['auth:shop', 'shop.member', 'shop.active'])->group(function () {
            Route::get('/', fn () => redirect()->route('shop.dashboard'))->name('home');
            Route::livewire('dashboard', 'pages::shop.dashboard')->name('dashboard');

            // Each "can:" check also runs on every Livewire action (persistent middleware).
            Route::livewire('customers', 'pages::shop.customers')->middleware('can:customers.manage')->name('customers.index');

            Route::middleware('can:menu.manage')->group(function () {
                Route::livewire('categories', 'pages::shop.menu.categories')->name('categories.index');
                Route::livewire('products', 'pages::shop.menu.products')->name('products.index');
                Route::livewire('products/create', 'pages::shop.menu.product-form')->name('products.create');
                Route::livewire('products/{product}/edit', 'pages::shop.menu.product-form')->whereNumber('product')->name('products.edit');
                Route::livewire('add-ons', 'pages::shop.menu.modifiers')->name('modifiers.index');
            });

            Route::livewire('accounts', 'pages::shop.accounts')->middleware('can:accounts.manage')->name('accounts.index');

            Route::middleware('can:floor.manage')->group(function () {
                Route::livewire('tables', 'pages::shop.tables')->name('tables.index');
                Route::livewire('waiters', 'pages::shop.waiters')->name('waiters.index');
            });

            Route::livewire('staff', 'pages::shop.staff')->middleware('can:staff.manage')->name('staff.index');
            Route::livewire('roles', 'pages::shop.roles')->middleware('can:roles.manage')->name('roles.index');
            Route::livewire('settings', 'pages::shop.settings')->middleware('can:settings.manage')->name('settings');

            Route::post('logout', [LogoutController::class, 'shop'])->name('logout');
        });
    });
