<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\Shop\PrintController;
use App\Http\Controllers\Shop\ReportController;
use App\Models\Shop;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public: landing page, shop finder, one-tap demo
|--------------------------------------------------------------------------
*/
Route::livewire('/', 'pages::landing')->name('home');
Route::livewire('find', 'pages::find-shop')->name('find');

// Signs into the demo shop (config sfms.demo_shop) as Owner, Manager or Cashier; 404 when no demo is set.
Route::get('demo/{role}', [DemoController::class, 'enter'])
    ->whereIn('role', array_keys(DemoController::ROLES))
    ->middleware('throttle:30,1')
    ->name('demo');

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
            Route::middleware('can:pos.use')->group(function () {
                Route::livewire('pos', 'pages::shop.pos')->name('pos');
                Route::livewire('pos/{order}', 'pages::shop.pos')->whereNumber('order')->name('pos.order');
                Route::livewire('tabs', 'pages::shop.tabs')->name('tabs');
            });

            Route::middleware('can:orders.view')->group(function () {
                Route::livewire('orders', 'pages::shop.orders.index')->name('orders.index');
                Route::livewire('orders/{order}', 'pages::shop.orders.show')->whereNumber('order')->name('orders.show');
            });

            Route::middleware('can:day.open_close')->group(function () {
                Route::livewire('day', 'pages::shop.day')->name('day');
                Route::livewire('day/{businessDay}', 'pages::shop.day-report')->whereNumber('businessDay')->name('day.report');
            });

            // 80mm print layouts (permission checked in the controller).
            Route::prefix('print')->name('print.')->controller(PrintController::class)->group(function () {
                Route::get('kot/{kot}', 'kot')->whereNumber('kot')->name('kot');
                Route::get('receipt/{order}', 'receipt')->whereNumber('order')->name('receipt');
                Route::get('day/{businessDay}', 'day')->whereNumber('businessDay')->name('day');
            });

            // Inventory (only when the shop tracks stock).
            Route::middleware('shop.inventory')->group(function () {
                Route::middleware('can:inventory.manage')->group(function () {
                    Route::livewire('ingredients', 'pages::shop.inventory.ingredients')->name('inventory.index');
                    Route::livewire('recipes', 'pages::shop.inventory.recipes')->name('recipes.index');
                    Route::livewire('stock-ledger', 'pages::shop.inventory.ledger')->name('stock.ledger');
                });

                Route::middleware('can:purchases.manage')->group(function () {
                    Route::livewire('purchases', 'pages::shop.inventory.purchases')->name('purchases.index');
                    Route::livewire('purchases/create', 'pages::shop.inventory.purchase-form')->name('purchases.create');
                });
            });

            Route::livewire('customers', 'pages::shop.customers')->middleware('can:customers.manage')->name('customers.index');

            Route::middleware('can:menu.manage')->group(function () {
                Route::livewire('categories', 'pages::shop.menu.categories')->name('categories.index');
                Route::livewire('products', 'pages::shop.menu.products')->name('products.index');
                Route::livewire('products/create', 'pages::shop.menu.product-form')->name('products.create');
                Route::livewire('products/{product}/edit', 'pages::shop.menu.product-form')->whereNumber('product')->name('products.edit');
                Route::livewire('add-ons', 'pages::shop.menu.modifiers')->name('modifiers.index');
            });

            Route::middleware('can:accounts.manage')->group(function () {
                Route::livewire('accounts', 'pages::shop.accounts')->name('accounts.index');
                Route::livewire('accounts/{account}', 'pages::shop.account-ledger')->whereNumber('account')->name('accounts.show');
            });

            Route::livewire('expenses', 'pages::shop.expenses')->middleware('can:expenses.manage')->name('expenses.index');

            Route::middleware('can:reports.view')->group(function () {
                Route::livewire('reports', 'pages::shop.reports')->name('reports');
                Route::get('reports/orders.csv', [ReportController::class, 'export'])->name('reports.export');
            });

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
