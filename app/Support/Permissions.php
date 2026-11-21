<?php

namespace App\Support;

/**
 * Every permission a shop role can be given.
 * Roles store a list of these keys; the locked Owner role has all of them.
 */
final class Permissions
{
    public const GROUPS = [
        'Sales' => [
            'pos.use' => 'Use the POS and open tabs',
            'orders.view' => 'View orders and receipts',
            'orders.void' => 'Void items sent to the kitchen',
            'orders.discount' => 'Give discounts',
            'checkout.take_payment' => 'Take payments and close bills',
            'day.open_close' => 'Open and close the business day',
            'customers.manage' => 'Manage customers',
        ],
        'Menu & stock' => [
            'menu.manage' => 'Manage categories, products and deals',
            'inventory.manage' => 'Manage ingredients and recipes',
            'purchases.manage' => 'Record stock purchases',
        ],
        'Money & reports' => [
            'accounts.manage' => 'Manage accounts and transfers',
            'expenses.manage' => 'Record expenses',
            'reports.view' => 'View reports and dashboard figures',
        ],
        'Administration' => [
            'floor.manage' => 'Manage tables, waiters and riders',
            'staff.manage' => 'Manage staff users',
            'roles.manage' => 'Manage roles and permissions',
            'settings.manage' => 'Change shop settings',
        ],
    ];

    public const DEFAULT_ROLES = [
        'Owner' => [
            'description' => 'Full access to everything in the shop.',
            'locked' => true,
            'permissions' => '*',
        ],
        'Manager' => [
            'description' => 'Runs the shop day to day; cannot change staff, roles or settings.',
            'locked' => false,
            'permissions' => [
                'pos.use', 'orders.view', 'orders.void', 'orders.discount', 'checkout.take_payment',
                'day.open_close', 'customers.manage', 'menu.manage', 'inventory.manage',
                'purchases.manage', 'accounts.manage', 'expenses.manage', 'reports.view', 'floor.manage',
            ],
        ],
        'Cashier' => [
            'description' => 'Takes orders, runs tabs, checks out and closes the day.',
            'locked' => false,
            'permissions' => [
                'pos.use', 'orders.view', 'orders.void', 'checkout.take_payment', 'day.open_close',
                'customers.manage',
            ],
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::GROUPS)));
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /** @return list<string> */
    public static function forDefaultRole(string $role): array
    {
        $permissions = self::DEFAULT_ROLES[$role]['permissions'];

        return $permissions === '*' ? self::all() : $permissions;
    }
}
