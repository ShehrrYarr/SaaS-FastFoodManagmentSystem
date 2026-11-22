# SFMS: Fast-Food Management & POS

Multi-tenant SaaS for fast-food shops, built with Laravel 13, Livewire 4 (single-file components), MySQL and the Herozi Bootstrap 5 admin template.

The full phase 1 plan and decisions are in [docs/SPEC.md](docs/SPEC.md).

| Milestone | Status |
|---|---|
| M0 Foundation (Laravel 13, Livewire 4, Herozi layouts, `wire:navigate`) | Done |
| M1 Tenancy + Super Admin (shops, guards, middleware, roles, shop login) | Done |
| M2 Shop setup (menu, tables, waiters, staff, roles, accounts, customers, settings) | Done |
| M3 POS, tabs, KOT, checkout, day close | Done |
| M4 Inventory (ingredients, recipes, purchases) | Done |
| M5 Money & reports (expenses, transfers, ledgers, reports, dashboard) | Done |
| M6 Live updates (Reverb), mobile pass, sample history, setup docs | Done |

Phase 1 is complete. Going live on a server: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

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

Generate the keys for live updates (Reverb):

```powershell
php artisan sfms:live-keys
```

Create the databases (WAMP: root with no password):

```powershell
C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe -u root -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS sfms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS sfms_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```powershell
php artisan migrate:fresh --seed
```

This gives the Super Admin, three sample shops and two weeks of sales history (it takes about a minute and a half; see *Sample data* below).

Link public storage (product photos and shop logos are stored there):

```powershell
php artisan storage:link
```

Build the front-end assets (required after cloning: `public/assets` is generated and not committed):

```powershell
npm run production
```

Run the app and, in a second terminal, the live-updates server:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
php artisan reverb:start --host=127.0.0.1 --port=8080
```

The app works without Reverb running; screens then refresh on a timer instead of instantly (the Open Tabs board shows *Live* or *Every minute*).

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

Burger Lab and Pizza Point also come with a sample menu (sizes, add-ons and combo deals), tables, waiters, riders, bank/wallet accounts with opening balances, and a few customers. Burger Lab also gets 16 ingredients with opening stock, recipes for every menu size and stock-using add-on, and one purchase (`php artisan db:seed --class=SampleInventorySeeder` adds them to an existing database).

**Two weeks of trading history** (`SampleSalesSeeder`) for Burger Lab and Pizza Point, so the dashboard, reports, ledgers and day reports have something to show. Every past day is opened with a cash count, sells about 15 to 30 orders through the real services (dine-in, takeaway and delivery, kitchen tickets, stock, split across cash, bank and wallet), records expenses, restock purchases, banking transfers and an owner drawing, and is closed with a count (a few days a little short or over). Each step runs with the clock set to that moment, so every ledger reads in time order and every Z report balances. Today is left open with a few orders paid and some tabs running. The history is the same every time (fixed random seed), and a shop that already has orders is skipped.

The home page (http://127.0.0.1:8000) is the public **landing page**: what SFMS does, real screenshots (`resources/assets/images/landing`), WhatsApp contact and "Ask for the pricing" buttons (number in `SFMS_WHATSAPP`), and a shop-code box that forwards to a shop's sign-in page (also at `/find`).

**Public demo.** With `SFMS_DEMO_SHOP=blg` in `.env`, the landing page shows one-tap buttons that sign visitors straight into that shop as its Owner, Manager or Cashier (`/demo/owner`, `/demo/manager`, `/demo/cashier`; no password). Each entry repairs what a previous visitor could break (the role's permissions, a disabled login), and the shop shows a demo bar with role switching. `php artisan sfms:demo-reset` wipes the database and rebuilds the sample data; on a demo server it runs nightly from cron, keeps your own Super Admins, and gives every sample login a random password so the README logins don't work there. Leave `SFMS_DEMO_SHOP` empty on installs with real shops: the demo routes then return 404 and the reset refuses to run.

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
3. **Open Tabs** (`/{shop}/tabs`): every table (free or busy), unpaid takeaway orders and the delivery queue (send out with a rider, then mark paid). Updates live as other tills work (see *Live updates*), and refreshes every minute as a fallback.
4. **Payment**: one or more payment lines (split across the Cash Drawer, banks and wallets). They must add up to the bill exactly; extra cash goes in "Cash received" and the change is shown. Each payment posts to its account's ledger.
5. **Close the day**: count the drawer again. Expected cash is the Cash Drawer's ledger balance; the difference is posted and the day's report (sales by account and type, items, voids, discounts, cash) is stored and printed as a Z report. Tabs still open carry over and count on the day they're paid.

**Orders** (`/{shop}/orders`) lists every order by date with its detail page: items, voids, payments and kitchen tickets, with reprint buttons.

### Printing

Kitchen tickets, receipts and day reports are 80mm pages under `/{shop}/print/...`. The POS prints them through a hidden iframe, so the browser's print dialog appears. The printer button in the POS order panel turns kitchen tickets or receipts off on that device (saved in the browser), for phones and tills without a printer. For silent printing today, start Chrome with `--kiosk-printing` and set the thermal printer as the default; QZ Tray comes later.

## Inventory (M4)

Only for shops with **Settings → Track ingredient inventory** on; otherwise the pages redirect to the dashboard and nothing is deducted.

- **Ingredients** (`/{shop}/ingredients`): each is counted in one unit (`kg g L ml pcs pair dozen`) with an optional low-stock level. Opening stock and its cost are entered when you add one. **Count** sets the stock to what was counted (the difference is an adjustment); **Record wastage** takes stock out with a reason.
- **Recipes** (`/{shop}/recipes`): what one serving of each menu size (and each add-on that uses stock) takes. Enter any unit of the same kind, e.g. 200 g of a kg ingredient. Deals use their items' recipes automatically. The page shows ingredient cost and food-cost % per item.
- **Purchases** (`/{shop}/purchases`): voucher `BLG-PUR-000001`, lines in any compatible unit (buy buns by the dozen, count them in pcs), paid from an account or left unpaid. Stock goes up and the average cost becomes a weighted average.
- **Stock ledger** (`/{shop}/stock-ledger`): every change, signed, with the balance after it and a link to the order or purchase.
- **At the POS**: sending to the kitchen takes the recipes (item + add-ons + deal items) in the same transaction, and each line remembers what one unit used, so voids and cancels give back exactly that even if the recipe changed later. With **Allow negative stock** off, a send that would take an ingredient below zero is refused with the shortfall; with it on, the send goes through and the cashier sees a low/negative warning.
- **Low stock** shows on the dashboard for users with `inventory.manage`.

Unit conversion: `kg = 1000 g`, `L = 1000 ml`, `dozen = 12 pcs`, `pair = 2 pcs`. Quantities are stored with 4 decimals and calculated exactly (bcmath).

## Money & reports (M5)

- **Accounts** (`/{shop}/accounts`): balances, recent activity, and **Transfer** between accounts (`BLG-TRF-000001`). Each account has a **ledger** (`/{shop}/accounts/{id}`): every entry with its running balance, filters by type and dates, money in/out totals, and a link to the order, expense, purchase, transfer or day report behind it. **Money in / Money out** records cash the owner adds or takes, bank charges and corrections (a reason is required).
- **Expenses** (`/{shop}/expenses`, `expenses.manage`): category, amount, the account it was paid from, date, paid to and a note (`BLG-EXP-000001`). A month view with totals by category and what came out of the drawer. Expenses are never deleted: **Void** puts the money back with a reversing ledger entry and keeps the record. Categories can be added, renamed or hidden.
- **No overdrafts:** an expense, purchase, transfer or money out can't take more than the account holds ("Cash Drawer only has Rs 1,650.").
- **Day close matches the ledger:** the day report lists opening count + cash sales ± every other drawer entry (expenses, purchases, transfers, money in/out) = expected cash, so the count can be checked line by line. The Z report prints the same, plus the day's expenses by category.
- **Reports** (`/{shop}/reports`, `reports.view`): Today, Yesterday, This week, Last 7 / 30 days, This month, Last month or a custom range (up to a year). Sales by day (or month), payments by account, order types, discounts/voids, best sellers, categories, busy hours, cashiers, waiters, expenses by category and a profit estimate (sales − ingredient cost − expenses). **Orders CSV** downloads the orders behind the report (opens in Excel).
- **Dashboard**: sales for the last 7 days and this month's profit estimate (`reports.view`), and account balances (`accounts.manage`).
- Charts use ApexCharts (`resources/assets/js/sfms-charts.js`), loaded only on pages that draw one, in the theme's colours for light and dark mode. Each chart has its figures in a table under it.

## Live updates (M6)

Screens update the moment another till changes something: the Open Tabs board, the POS (if another till pays or cancels the order on screen, the cashier is told and taken to its history), the dashboard, orders, the business day, accounts, expenses, ingredients and the stock ledger.

- **How it works:** model hooks (`AppServiceProvider::registerLiveUpdates`) note what changed during a request (`orders`, `day`, `money`, `stock` and the order ids) in `App\Support\LiveUpdates`. When the request ends, one `App\Events\ShopUpdated` per shop goes to the private channel `shop.{id}` through [Laravel Reverb](https://reverb.laravel.com). `resources/assets/js/sfms-live.js` (Laravel Echo + Pusher client) turns it into a Livewire event, and each screen (trait `App\Livewire\Concerns\RefreshesLive`) re-renders if it shows that area.
- **The message carries no order details**, only what changed. Screens reload their own data through the usual permission checks.
- **Who may listen:** `routes/channels.php` allows only active staff of that shop while the shop is accessible (not suspended or expired).
- **If Reverb is down** nothing breaks: the send is logged and skipped (with short timeouts), and Open Tabs falls back to refreshing every minute. After a dropped connection the browser refreshes everything once when it reconnects.
- **Settings:** `BROADCAST_CONNECTION=reverb` and the `REVERB_*` values in `.env` (`php artisan sfms:live-keys` generates the keys). `REVERB_CLIENT_*` is where browsers connect; behind a proxy that is your public host on 443/https (see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)). Without a key, shop pages load no live-update scripts at all.

## Mobile

Every shop and Super Admin screen was checked at 375 px wide (no sideways scrolling; wide tables become stacked cards, chip rows scroll on their own). On phones and touch tablets every button, chip, form field and page link is at least 44 px tall (`sfms.scss`, "mobile pass"). The POS cart becomes a bottom bar that opens a sheet.

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
- **Locking reads in order code.** Inside a transaction MySQL serves plain reads from a snapshot, which can miss lines another till just added. `App\Services\Orders` reloads order lines and checks table occupancy with `lockForUpdate()`, and `App\Services\Stock` locks ingredient rows **in id order** so concurrent tills queue instead of deadlocking. Order transactions retry up to 3 times if MySQL still picks one as a deadlock victim. `ShopSequence::next()` locks an existing counter directly (no `INSERT IGNORE` first), because two tills upgrading shared locks deadlock.
- **Day lock for money postings.** `BusinessDays::current(lock: true)` (and `requireOpen()`) take a *shared* lock on the open day row inside every transaction that moves money; closing the day takes it *exclusively* and locks the Cash Drawer row. Sales on several tills don't block each other, but a close waits for them and they wait for the close, so the close's expected cash and the day's ledger always agree. The day row is always locked first.
- **Live updates are sent after the response.** `LiveUpdates` collects changes and sends them from a `terminating` callback, so they go out after every transaction has committed and never slow a sale down. Code that changes data outside a request (a command, a job) sends its messages when the process ends.
- **Sample history runs with a fake clock.** `SampleSalesSeeder` uses `Carbon::setTestNow()` for each step; `DatabaseSeeder` dates the sample menus and accounts the day before the history starts. Don't call those seeders on a production database.
- **Concurrency test.** `tests/Feature/StockConcurrencyTest.php` runs 4 PHP processes selling at once against `sfms_testing`. It can't use transactions, so it rebuilds that database before and after (about 30 s). It is skipped if the config is cached.
- **`@php(...)` with `@php … @endphp` in one template.** Blade matches a block from the first `@php` to the next `@endphp`, so an inline `@php($x = …)` *before* a block form swallows everything in between. Use block form in templates that have both.
