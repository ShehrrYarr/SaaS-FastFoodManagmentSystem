# SFMS: Fast-Food Management & POS (Phase 1 spec)

Status: **approved** · 2026-11-21 · M0 and M1 done
Scope: multi-tenant SaaS for fast-food shops. Online ordering is a later phase.

---

## 1. Stack

| Layer | Choice | Why |
|---|---|---|
| Framework | **Laravel 13**, PHP 8.3 (WAMP `php8.3.14`) | Laravel 12 gets security fixes only until ~Feb 2027 |
| UI logic | **Livewire 4 single-file components** (no Volt) + Alpine (bundled with Livewire) | Livewire 4 has Volt-style single-file components built in; Volt is being phased out |
| Navigation | `wire:navigate` (+ `.hover` prefetch) everywhere, `@persist` for sidebar/header | SPA feel, no full reloads |
| Theme | Herozi (Bootstrap 5, SCSS, Laravel Mix build kept as-is) | Template fidelity, light/dark |
| Database | MySQL 8+ (local: WAMP MySQL 9.1) | |
| Live updates | Laravel Reverb (websockets) on VPS; `wire:poll` fallback | Open tabs/tables update on every device |
| Hosting | VPS (queues, Reverb, scheduler) | |
| Printing | 80mm browser print layouts now; QZ Tray silent printing later | |

---

## 2. Decisions from Q&A

| Topic | Decision |
|---|---|
| Tenancy | Custom, single database. `shops` table is the tenant; every shop-owned table has `tenant_id` → `shops.id`; `BelongsToTenant` trait (global scope + auto-fill) |
| Branches | Each outlet is a separate shop (own prefix, login, menu, stock) |
| SaaS billing | `subscription_ends_at` per shop: warning banner 7 days before, auto-suspend after expiry, Super Admin extends manually |
| Order types | Dine-in (open tab, pay at end), Takeaway (pay at counter), Delivery (phone order + rider, pay on delivery) |
| Waiters | **Tags only**, no login. Cashier/manager picks the waiter. Riders use the same list (`type = rider`) |
| Kitchen | **Printed KOT**: each "send" prints only the new items; voids print a VOID KOT |
| Menu | Sizes/variants, add-on/modifier groups, combo deals, per-item notes |
| Tax | **None for now** (no tax fields on bills) |
| PRA / FBR | **Not needed** |
| Discounts & charges | Order discount (% or Rs), item discount, service charge (per-shop, dine-in), delivery charge |
| Voids | Any staff member can void; **reason required**; user and time logged; stock returned |
| Split bill | Split **payment** across accounts only (no item/equal splitting) |
| Order numbers | `BLG-000123` continuous per shop + **daily token** (1, 2, 3…, reset each business day) |
| Money accounts | Every shop gets a default **Cash Drawer** account and can add **bank/wallet accounts** (Meezan, JazzCash…). Payments go to an account. Running balances, transfers, expenses, purchases paid from an account |
| Day close | Open the day (confirm opening cash), close it by counting cash: expected vs counted, over/short posted, report locked |
| Inventory | Per-shop toggle `inventory_tracking_enabled` (default **off**). Deduct **when items are sent to kitchen**; void returns stock |
| Low stock | Per-shop setting: allow negative (warn) **or** block the item |
| Roles | **Custom roles with permission checkboxes** per shop (Owner role locked with all permissions) |
| Extras in phase 1 | Customer directory (phone lookup auto-fills delivery details) |

### Not in phase 1
Online ordering · tax · PRA/FBR fiscal integration · kitchen display screen · silent/Bluetooth printing · item/equal bill split · refunds after payment · wastage & stock-adjustment UI (the ledger supports it) · PIN login · Super Admin "login as shop" · plans/limits · multi-branch brands.

---

## 3. Tenancy, auth & routing

- **Two guards on one `users` table**: `admin` (rows with `is_super_admin = 1`) and `shop` (rows with a `tenant_id`). A Super Admin session and a shop session can coexist in one browser.
- **Routes**
  - `/super-admin/login`, `/super-admin/...` → `auth:admin` + `EnsureSuperAdmin`
  - `/{shop}/login`, `/{shop}/...` → `ResolveShopByPrefix` → `auth:shop` → `EnsureUserBelongsToShop` → `EnsureShopIsActive`
- **`ResolveShopByPrefix`**: finds the active shop by lowercase prefix, binds it as the current tenant (singleton `CurrentShop`), sets `URL::defaults(['shop' => prefix])` so every `route()` call gets the prefix automatically, and sets the shop timezone/currency.
- **`EnsureUserBelongsToShop`**: `auth('shop')->user()->tenant_id === currentShop->id`, else 403. A Shop A session on `/shopb/...` gets 403 and is sent back to Shop A.
- **Suspension / expiry**: a suspended or expired shop shows a "shop suspended" page at login, and active sessions are logged out on the next request.
- **Livewire**: component update requests re-resolve the tenant from the component's persisted shop id, and `BelongsToTenant` scopes every query. Model binding goes through the scope, so `/blg/orders/{id}` can never load another shop's order.
- **Prefix rules**: 2–20 chars, `a-z 0-9 -`, stored lowercase, unique, reserved words blocked (`super-admin`, `login`, `livewire`, `api`, `assets`, `storage`, `build`, `up`, …). **Locked after creation** (changing it breaks URLs and bookmarks).
- **`order_prefix`**: separate uppercase code (default = prefix without hyphens, e.g. `BLG`), used for order, purchase and SKU numbers; editable (affects new numbers only).
- **Numbering**: `shop_sequences (tenant_id, key, scope, next_value)` incremented under `lockForUpdate()`: orders `BLG-000123`, purchases `BLG-PUR-000045`, SKUs `BLG-P-0012`, daily token scoped to the business day.
- Herozi's demo catch-all route (`/{page}`) would swallow `/{shop}`. It moves to `/_herozi/{page}`, local environment only.

### Permissions (checkboxes on custom roles)
`pos.use`, `orders.view`, `orders.void`, `orders.discount`, `checkout.take_payment`, `day.open_close`, `reports.view`, `menu.manage`, `inventory.manage`, `purchases.manage`, `accounts.manage`, `expenses.manage`, `customers.manage`, `floor.manage` (tables, waiters, riders), `staff.manage`, `roles.manage`, `settings.manage`.
Seeded per new shop: **Owner** (all, locked), **Manager**, **Cashier**.

---

## 4. Data model

All money is `decimal(12,2)`; all stock and recipe quantities are `decimal(14,4)`. Every shop table has `tenant_id` (FK, indexed) plus composite uniques where noted.

**Platform**
- `users`: tenant_id (nullable), role_id, name, email, username, password, is_super_admin, is_active, last_login_at · unique(tenant_id, email), unique(tenant_id, username)
- `shops`: name, prefix (unique), order_prefix, phone, address, logo_path, currency (`PKR`), timezone (`Asia/Karachi`), is_active, suspended_reason, subscription_ends_at, inventory_tracking_enabled (**false**), allow_negative_stock (true), service_charge_type/value, default_delivery_charge, receipt_header, receipt_footer
- `shop_sequences`: tenant_id, key, scope, next_value · unique(tenant_id, key, scope)
- `roles`: tenant_id, name, permissions (json), is_locked · unique(tenant_id, name)

**Menu**
- `categories`: name, sort_order, is_active
- `products`: category_id, name, sku (unique per shop), type (`simple` | `combo`), description, image_path, is_available, sort_order
- `product_variants`: product_id, name (`Regular`, `Large`), price, is_default, is_available. Every product has at least one variant, and order lines always point to a variant.
- `modifier_groups`: name, min_select, max_select, is_required · `modifier_options`: group_id, name, price, is_available · pivot `product_modifier_group`
- `combo_items`: combo_product_id, product_variant_id, quantity (fixed components; choices like "pick a drink" are modifier groups on the combo)
- `recipe_items`: recipeable (variant **or** modifier option), ingredient_id, quantity + unit as entered, base_quantity (converted to the ingredient's unit)

**Inventory** (screens visible only when tracking is on)
- `ingredients`: name, unit (`kg` `g` `L` `ml` `pcs` `pair` `dozen`), current_stock, low_stock_threshold, avg_cost, is_active
- `purchases`: voucher_number, supplier_name, purchased_on, account_id (null = unpaid), total, note · `purchase_items`: ingredient_id, quantity, unit, base_quantity, unit_cost, line_total
- `stock_movements` (ledger): ingredient_id, type (`purchase` `sale` `void_return` `adjustment` `wastage`), quantity (signed), balance_after, unit_cost, source (morph), user_id, note
- Unit conversion within families: mass `kg=1000 g`, volume `L=1000 ml`, count `pcs=1, pair=2, dozen=12`.

**Floor & customers**
- `dining_tables`: name (`T-01`), area, capacity, sort_order, is_active · unique(tenant_id, name)
- `waiters`: name, phone, type (`waiter` | `rider`), is_active
- `customers`: name, phone (unique per shop), address, notes, orders_count, total_spent, last_order_at

**Orders**
- `business_days`: business_date, status, opened_by/at, opening_cash_counted, closed_by/at, expected_cash, counted_cash, cash_difference, summary (json snapshot)
- `orders`: order_number, sequence, token_number, business_day_id, type, status (`open` `completed` `cancelled`), payment_status (`unpaid` `partial` `paid`), dining_table_id, waiter_id, rider_id, customer_id, customer name/phone/address snapshot, guests, subtotal, item_discount_total, discount_type/value/amount, service_charge, delivery_charge, grand_total, paid_total, change_total, notes, opened_by, closed_by, opened_at, closed_at, cancel_reason
- `order_items`: order_id, product_id, product_variant_id, name snapshot, unit_price, modifiers_total, quantity, voided_quantity, discount_type/value/amount, line_total, note, status (`pending` | `sent`), kot_id, combo_snapshot (json)
- `order_item_modifiers`: order_item_id, modifier_option_id, name, price (snapshots)
- `order_item_voids`: order_item_id, quantity, reason, user_id, kot_id
- `kots`: order_id, number, type (`new` | `void`), printed_count, created_by
- `payments`: order_id, account_id, amount, tendered, change_given, reference (bank/wallet transaction id), received_by, business_day_id

**Money**
- `accounts`: name, type (`cash` `bank` `wallet`), account_title, account_number, is_default_cash (one per shop, cannot be deleted), opening_balance, current_balance, is_active
- `account_transactions` (ledger): account_id, type (`sale` `transfer_in` `transfer_out` `expense` `purchase` `cash_in` `cash_out` `opening` `over_short`), amount (signed), balance_after, source (morph), business_day_id, user_id, note
- `transfers`: from_account_id, to_account_id, amount, note
- `expense_categories`, `expenses`: category_id, account_id, amount, note, spent_on, business_day_id

---

## 5. Key flows

**POS**
1. Choose order type. **Dine-in** needs a table, a waiter, or both. **Takeaway**: optional name. **Delivery**: typing a phone number auto-fills the customer and address; the rider is assigned on dispatch.
2. Add items from a touch grid (instant search + category chips). Products with more than one variant, or with modifiers, open a bottom sheet to choose size and add-ons.
3. **Send to kitchen** commits pending lines in one `DB::transaction`: lock the order → create a KOT → mark lines `sent` → (if inventory is on) explode variant + modifier + combo recipes into ingredient totals, lock those ingredient rows **in id order** with `lockForUpdate()` (avoids deadlocks), apply the shop's negative-stock rule, decrement with exact MySQL decimals, write `stock_movements` → print the KOT.
4. **Takeaway "Charge"** = send to kitchen + open checkout in one tap.

**Open Tabs board**: table grid (free/occupied, amount, minutes open), waiter filter, delivery list (pending / out for delivery). Tap a tab to add items, change quantities/notes, void, or check out. Updates live.

**Voids**: unsent lines can be removed freely. Sent lines need a reason (any user); this records `order_item_voids`, prints a VOID KOT and returns the stock in the same transaction.

**Checkout** (modal on desktop, full-screen sheet on mobile): subtotal − item discounts − order discount + service charge (dine-in) + delivery charge = grand total. One or more payment lines (account + amount, optional reference); cash lines show tendered and change. When fully paid: order `completed`, table freed, account ledger posted, customer stats updated, 80mm receipt with shop name, `BLG-000123`, and token.

**Business day**: the POS requires an open day. Opening confirms the counted drawer cash (a difference posts as over/short). Closing shows expected cash (drawer ledger) vs counted, posts over/short, and locks a day report (sales by account, order type, item, plus voids and discounts). Open tabs at close are allowed with a warning; they carry over and count on the day they're paid.

---

## 6. Screens

**Super Admin** `/super-admin`: Dashboard (shops total / active / suspended / expiring soon) · Shops list · Create shop wizard (shop details + prefix + owner login in one flow) · Shop detail (edit, suspend/activate, extend subscription, reset passwords, add staff users) · Super Admin users.

**Shop** `/{shop}`: Dashboard · POS · Open Tabs · Orders (list + detail + receipt reprint) · Day open/close · Categories · Products (variants, modifiers, combos, recipes) · Modifier groups · Inventory (ingredients, purchases, stock ledger, low-stock alerts) · Accounts (balances, ledger, transfers) · Expenses · Customers · Tables · Waiters & riders · Staff users · Roles · Reports · Settings.

**Mobile**: on POS screens below `lg`, the cart becomes a sticky bottom bar ("3 items · Rs 2,450 · View"), which opens a bottom offcanvas; tap targets are at least 44px; tables use stacked card rows on phones; no horizontal overflow.

---

## 7. Milestones

| # | Milestone | Done when |
|---|---|---|
| M0 | Foundation: Laravel 13 + Livewire 4, MySQL, Herozi layouts as Livewire layouts, `wire:navigate` + Herozi JS re-init on `livewire:navigated`, git | Herozi shell navigates with no reloads, light/dark works |
| M1 | Tenancy + Super Admin: shops, guards, middleware, roles/permissions, shop login, subscription expiry | Isolation tests pass (Shop A user can't reach Shop B) |
| M2 | Shop setup: settings, accounts, tables, waiters/riders, staff, roles, categories, products/variants/modifiers/combos, customers | Menu fully manageable on phone and desktop |
| M3 | POS: order types, tabs board, KOT, voids, checkout with split payments, receipts, business day open/close | Full dine-in, takeaway and delivery cycle works |
| M4 | Inventory: ingredients, units, recipes, purchases, deduction/reversal, negative-stock setting, low-stock alerts | Burger example deducts 0.2 kg / 0.1 kg / 1 pair exactly; concurrency test passes |
| M5 | Money & reports: transfers, expenses, ledgers, day-end report, sales reports, dashboards | Cash count matches ledger |
| M6 | Reverb live updates, mobile pass on every screen, seeders, setup docs | Fresh `migrate --seed` gives Super Admin + 2 sample shops |

---

## 8. Defaults I chose (say if you want any changed)

- Currency **PKR ("Rs")**, English UI, timezone Asia/Karachi (all per shop).
- Shop login with **email or username** + password.
- Shop prefix locked after creation; `order_prefix` editable.
- No refunds or reopening of paid orders in phase 1.
- Open-tabs board uses `wire:poll` until Reverb lands in M6.
- Sample shops: **Burger Lab Gulberg** (`blg`, inventory ON, with the meat/sauce/bread burger example) and a second shop with inventory OFF. Credentials go in the seeder and README.

---

## 9. Research sources

- Livewire 4: [upgrade guide](https://livewire.laravel.com/docs/4.x/upgrading), [Laravel News: everything new](https://laravel-news.com/everything-new-in-livewire-4), [Livewire 4 is here](https://laravel.com/blog/livewire-4-is-here-the-artisan-of-the-day-is-caleb-porzio), [Volt → SFC via Shift](https://laravelshift.com/upgrade-livewire-3-to-livewire-4)
- Laravel 13: [release notes](https://laravel.com/docs/13.x/releases.md), [Laravel 13 released](https://laravel-news.com/laravel-13-released)
- Tenancy: [stancl single-database](https://v4.tenancyforlaravel.com/single-database-tenancy), [stancl path identification](https://tenancyforlaravel.com/docs/v3/tenant-identification), [shared-DB foundation layer](https://dev.to/nasrulhazim/shared-database-multi-tenancy-in-laravel-the-foundation-layer-4oej)
- POS workflow: [Odoo restaurant features](https://www.odoo.com/documentation/master/applications/sales/point_of_sale/restaurant.html), [KOT in F&B service](https://www.restroworks.com/blog/kot-in-food-and-beverage-service/), [Loyverse restaurants](https://loyverse.com/restaurants-pos)
- Printing: [QZ Tray raw printing](https://qz.io/docs/raw), [ESC/POS from a web SaaS](https://yomotherboard.com/question/whats-the-best-way-to-print-esc-pos-receipts-from-a-web-saas/)
- Pakistan tax context (not in scope now): [PRA card tax 8% from July 2026](https://propakistani.pk/2026/07/03/punjab-increases-restaurant-card-payment-tax/), [PRA billing rules](https://propakistani.pk/2026/08/18/punjab-issues-new-billing-rules-for-restaurants/), [PRA POS/eIMS manual](https://e.pra.punjab.gov.pk/templates/POS_COMPONENT_and_eIMS_User_Manual.pdf)
