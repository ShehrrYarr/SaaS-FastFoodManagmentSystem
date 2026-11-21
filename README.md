# SFMS: Fast-Food Management & POS

Multi-tenant SaaS for fast-food shops, built with Laravel 13, Livewire 4 (single-file components), MySQL and the Herozi Bootstrap 5 admin template.

The full phase 1 plan and decisions are in [docs/SPEC.md](docs/SPEC.md).

| Milestone | Status |
|---|---|
| M0 Foundation (Laravel 13, Livewire 4, Herozi layouts, `wire:navigate`) | Done |
| M1 Tenancy + Super Admin (shops, guards, middleware, roles, shop login) | Done |
| M2 Shop setup (menu, tables, waiters, staff, roles, accounts, customers, settings) | Done |
| M3 POS, tabs, KOT, checkout, day close | Done |
| M4 Inventory (ingredients, recipes, purchases) | Next |
| M5 Money & reports | Planned |
| M6 Live updates (Reverb), mobile pass, docs | Planned |

---

## Requirements

- PHP **8.3+** with `pdo_mysql`, `mbstring`, `bcmath`, `intl`, `fileinfo`
- MySQL 8+ (or MariaDB 10.6+) using **InnoDB** tables
- Composer 2, Node 18+ and npm

On this machine (WAMP) PHP 8.3 lives at `C:\wamp64\bin\php\php8.3.14`. Put it first on your `PATH`, or call it directly.

## Setup

```powershell
composer install
```

```powershell
npm install
```

```powershell
copy .env.example .env
```

```powershell
php artisan key:generate
```

Create the databases (WAMP: root with no password):

```powershell
C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe -u root -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS sfms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS sfms_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```powershell
php artisan migrate:fresh --seed
```

Link public storage (product photos and shop logos are stored there):

```powershell
php artisan storage:link
```

Build the front-end assets (required after cloning: `public/assets` is generated and not committed):

```powershell
npm run production
```

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

### Assets

`npm run production` compiles the Herozi and SFMS SCSS and copies images, scripts and libraries from `resources/assets` into `public/assets` (about 2–3 minutes). Run it again after changing anything in `resources/assets`.

`npm run watch` rebuilds on change. Laravel Mix 6 needs webpack **5.99.x**: newer webpack removed a file Mix depends on, so `webpack` is pinned in `package.json`.

### Tests

```powershell
php artisan test
```

Tests run against the `sfms_testing` MySQL database (see `phpunit.xml`).

---

## Sample data (local development only)

`php artisan migrate:fresh --seed` creates the accounts below. **Every seeded account uses the password `password`.** Change or remove them before going live.

| Area | Sign-in page | Login | Notes |
|---|---|---|---|
| Super Admin | http://127.0.0.1:8000/super-admin/login | `admin@sfms.test` | Manages every shop |
| Burger Lab Gulberg | http://127.0.0.1:8000/blg/login | `owner@blg.test` or `hamza` | Owner. Inventory tracking **on** |
| | | `manager@blg.test` / `ayesha` | Manager role |
| | | `cashier@blg.test` / `bilal` | Cashier role |
| Pizza Point Johar Town | http://127.0.0.1:8000/ppj/login | `owner@ppj.test` or `sana` | Inventory **off**. Subscription ends in 5 days (shows the warning banner) |
| | | `cashier@ppj.test` / `usman` | Cashier role |
| Shawarma Stop DHA | http://127.0.0.1:8000/ssd/login | `owner@ssd.test` or `ali` | **Suspended**: shows how blocked shops behave |

Burger Lab and Pizza Point also come with a sample menu (sizes, add-ons and combo deals), tables, waiters, riders, bank/wallet accounts with opening balances, and a few customers.

The home page (http://127.0.0.1:8000) asks for a shop code and forwards to that shop's sign-in page.

Herozi's original demo pages are available locally at http://127.0.0.1:8000/_herozi/index for reference (local environment only).

---

## How tenancy works

- **Shops are tenants.** Every shop-owned table has a `tenant_id` pointing at `shops.id`.
- **URLs carry the shop prefix:** `/{prefix}/login`, `/{prefix}/dashboard`, and so on. Prefixes are lowercase, unique, locked after creation, and can't be reserved words (`super-admin`, `pos`, `livewire…`, …). An upper-case prefix in a URL redirects to the lower-case one.
- **`ResolveShopByPrefix`** finds the shop and stores it in `App\Tenancy\TenantContext`. It also sets URL defaults, so `route('shop.dashboard')` needs no prefix.
- **`EnsureUserBelongsToShop`** returns 403 when a user signed in to Shop A opens Shop B's URL.
- **`EnsureShopIsActive`** signs staff out when their shop is suspended or its subscription has ended.
- **`EnsureSuperAdmin`** guards `/super-admin/...`.
- These middleware are also registered as **Livewire persistent middleware**, so they run again on every Livewire update request.
- **`BelongsToTenant`** (in `app/Tenancy`) adds a global scope and fills in `tenant_id` on create. With no shop resolved, scoped queries return **nothing** (fail closed). Use `Model::query()->acrossShops()` only on Super Admin screens.
- **Two session guards:** `admin` for Super Admins and `shop` for shop staff. Both use the `users` table, so a Super Admin and a shop user can be signed in at the same time in one browser.
- **Roles are per shop.** Each shop gets Owner (locked, all permissions), Manager and Cashier, and checks use `$user->can('pos.use')`. The permission list is in `app/Support/Permissions.php`.
- **Numbering:** `ShopSequence::next($tenantId, 'order')` returns gap-free numbers under a row lock, and `ShopSequence::format('BLG', 123)` gives `BLG-000123`.

## Taking orders (M3)

1. **Open the day** (`/{shop}/day`): count the cash drawer. Any difference from the Cash Drawer's ledger balance is posted as cash over/short. The POS refuses orders while no day is open.
2. **POS** (`/{shop}/pos`): choose Dine-in, Takeaway or Delivery, then tap items. Items with sizes or add-ons open a sheet. New lines stay in the POS (and your session, so they survive moving between pages) until you press **Send**, which saves them on the order and prints a kitchen ticket (KOT). The order gets its number (`BLG-000123`) and daily token on its first send.
   - Dine-in needs a table or a waiter. Delivery needs a phone and address; a known phone fills in the customer.
   - **Send & pay** sends the new lines and opens payment in one tap (takeaway).
   - Voids need the `orders.void` permission and a reason, and print a VOID ticket. Discounts need `orders.discount`.
3. **Open Tabs** (`/{shop}/tabs`): every table (free or busy), unpaid takeaway orders and the delivery queue (send out with a rider, then mark paid). Refreshes every 15 seconds until live updates arrive in M6.
4. **Payment**: one or more payment lines (split across the Cash Drawer, banks and wallets). They must add up to the bill exactly; extra cash goes in "Cash received" and the change is shown. Each payment posts to its account's ledger.
5. **Close the day**: count the drawer again. Expected cash is the Cash Drawer's ledger balance; the difference is posted and the day's report (sales by account and type, items, voids, discounts, cash) is stored and printed as a Z report. Tabs still open carry over and count on the day they're paid.

**Orders** (`/{shop}/orders`) lists every order by date with its detail page: items, voids, payments and kitchen tickets, with reprint buttons.

### Printing

Kitchen tickets, receipts and day reports are 80mm pages under `/{shop}/print/...`. The POS prints them through a hidden iframe, so the browser's print dialog appears. The printer button in the POS order panel turns kitchen tickets or receipts off on that device (saved in the browser), for phones and tills without a printer. For silent printing today, start Chrome with `--kiosk-printing` and set the thermal printer as the default; QZ Tray comes later.

## Front-end notes

- Pages are Livewire 4 single-file components in `resources/views/pages/...`, routed with `Route::livewire()`.
- Every internal link uses `wire:navigate`. The header and sidebar are wrapped in `@persist`, so they stay mounted between pages.
- `resources/assets/js/sfms.js` re-applies Herozi's layout attributes after each `wire:navigate` swap (theme, sidebar mode), closes the mobile drawer and open modals, and shows toasts. Livewire components trigger a toast with `$this->dispatch('toast', type: 'success', message: '...')`.
- SFMS styles live in `resources/assets/scss/sfms.scss` and build to `public/assets/css/sfms.min.css`.

## Gotchas

- **MySQL storage engine.** WAMP's MySQL defaults to MyISAM, which has no transactions, row locks or foreign keys. `config/database.php` forces `InnoDB` (override with `DB_ENGINE`); keep it that way, because stock and order numbering depend on row locks.
- **Livewire state between requests.** Livewire keeps its persistent-middleware state in memory and skips re-running the checks when the previous request had the same route. `AppServiceProvider` flushes that state after every request, so the shop and permission checks can never be skipped in long-running workers (Octane) or tests. Keep that line.
- **PHP version.** Laravel 13 needs PHP 8.3, and `php -v` on this machine may still report 8.2.
- **Bootstrap backdrops inside Livewire components.** Bootstrap 5.3 puts an offcanvas backdrop next to the panel, so a Livewire re-render would delete it. `sfms.js` tells Livewire's morph to skip `.offcanvas-backdrop` / `.modal-backdrop`; keep that hook.
- **Locking reads in order code.** Inside a transaction MySQL serves plain reads from a snapshot, which can miss lines another till just added. `App\Services\Orders` reloads order lines and checks table occupancy with `lockForUpdate()`; keep that pattern for stock in M4.
