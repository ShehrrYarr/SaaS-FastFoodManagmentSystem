<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar menus for both areas. An item without a built route is shown
 * as "Soon" (disabled) so the shape of the product is visible while it
 * is being built milestone by milestone.
 *
 * Item keys: label, icon, route, exact (bool), permission, soon (milestone label)
 */
final class Navigation
{
    /** @return list<array{title: string, items: list<array<string, mixed>>}> */
    public static function admin(): array
    {
        return self::filter([
            ['title' => 'Overview', 'items' => [
                ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'admin.dashboard', 'exact' => true],
            ]],
            ['title' => 'Tenants', 'items' => [
                ['label' => 'Shops', 'icon' => 'ri-store-2-line', 'route' => 'admin.shops.index'],
            ]],
            ['title' => 'Platform', 'items' => [
                ['label' => 'Super Admins', 'icon' => 'ri-shield-user-line', 'route' => 'admin.admins.index'],
            ]],
        ], null);
    }

    /** @return list<array{title: string, items: list<array<string, mixed>>}> */
    public static function shop(Shop $shop, ?User $user): array
    {
        $groups = [
            ['title' => 'Main', 'items' => [
                ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'shop.dashboard'],
            ]],
            ['title' => 'Sales', 'items' => [
                ['label' => 'POS', 'icon' => 'ri-shopping-basket-2-line', 'route' => 'shop.pos', 'permission' => 'pos.use', 'soon' => 'M3'],
                ['label' => 'Open Tabs', 'icon' => 'ri-restaurant-2-line', 'route' => 'shop.tabs', 'permission' => 'pos.use', 'soon' => 'M3'],
                ['label' => 'Orders', 'icon' => 'ri-file-list-3-line', 'route' => 'shop.orders.index', 'permission' => 'orders.view', 'soon' => 'M3'],
                ['label' => 'Day Close', 'icon' => 'ri-safe-2-line', 'route' => 'shop.day', 'permission' => 'day.open_close', 'soon' => 'M3'],
                ['label' => 'Customers', 'icon' => 'ri-contacts-book-3-line', 'route' => 'shop.customers.index', 'permission' => 'customers.manage', 'soon' => 'M2'],
            ]],
            ['title' => 'Menu', 'items' => [
                ['label' => 'Categories', 'icon' => 'ri-folder-3-line', 'route' => 'shop.categories.index', 'permission' => 'menu.manage', 'soon' => 'M2'],
                ['label' => 'Products', 'icon' => 'ri-restaurant-line', 'route' => 'shop.products.index', 'permission' => 'menu.manage', 'soon' => 'M2'],
                ['label' => 'Add-ons', 'icon' => 'ri-add-circle-line', 'route' => 'shop.modifiers.index', 'permission' => 'menu.manage', 'soon' => 'M2'],
            ]],
        ];

        if ($shop->inventory_tracking_enabled) {
            $groups[] = ['title' => 'Inventory', 'items' => [
                ['label' => 'Ingredients', 'icon' => 'ri-archive-stack-line', 'route' => 'shop.inventory.index', 'permission' => 'inventory.manage', 'soon' => 'M4'],
                ['label' => 'Purchases', 'icon' => 'ri-truck-line', 'route' => 'shop.purchases.index', 'permission' => 'purchases.manage', 'soon' => 'M4'],
                ['label' => 'Stock Ledger', 'icon' => 'ri-git-commit-line', 'route' => 'shop.stock.ledger', 'permission' => 'inventory.manage', 'soon' => 'M4'],
            ]];
        }

        $groups[] = ['title' => 'Money', 'items' => [
            ['label' => 'Accounts', 'icon' => 'ri-bank-line', 'route' => 'shop.accounts.index', 'permission' => 'accounts.manage', 'soon' => 'M2'],
            ['label' => 'Expenses', 'icon' => 'ri-wallet-3-line', 'route' => 'shop.expenses.index', 'permission' => 'expenses.manage', 'soon' => 'M5'],
            ['label' => 'Reports', 'icon' => 'ri-bar-chart-box-line', 'route' => 'shop.reports', 'permission' => 'reports.view', 'soon' => 'M5'],
        ]];

        $groups[] = ['title' => 'Setup', 'items' => [
            ['label' => 'Tables', 'icon' => 'ri-layout-grid-line', 'route' => 'shop.tables.index', 'permission' => 'floor.manage', 'soon' => 'M2'],
            ['label' => 'Waiters & Riders', 'icon' => 'ri-team-line', 'route' => 'shop.waiters.index', 'permission' => 'floor.manage', 'soon' => 'M2'],
            ['label' => 'Staff Users', 'icon' => 'ri-user-settings-line', 'route' => 'shop.staff.index', 'permission' => 'staff.manage', 'soon' => 'M2'],
            ['label' => 'Roles', 'icon' => 'ri-shield-keyhole-line', 'route' => 'shop.roles.index', 'permission' => 'roles.manage', 'soon' => 'M2'],
            ['label' => 'Settings', 'icon' => 'ri-settings-4-line', 'route' => 'shop.settings', 'permission' => 'settings.manage', 'soon' => 'M2'],
        ]];

        return self::filter($groups, $user);
    }

    /**
     * Drops items the user may not use, resolves hrefs and marks
     * not-yet-built routes as "soon".
     */
    private static function filter(array $groups, ?User $user): array
    {
        $result = [];

        foreach ($groups as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if (isset($item['permission']) && ! $user?->hasPermission($item['permission'])) {
                    continue;
                }

                $built = Route::has($item['route']);
                $item['href'] = $built ? route($item['route']) : null;
                $item['soon'] = $built ? null : ($item['soon'] ?? 'Soon');
                $items[] = $item;
            }

            if ($items !== []) {
                $result[] = ['title' => $group['title'], 'items' => $items];
            }
        }

        return $result;
    }
}
