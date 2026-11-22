<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Ingredient;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Purchases;
use App\Services\Recipes;
use App\Services\Stock;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Ingredients, opening stock, recipes and one purchase for Burger Lab
 * (the sample shop with inventory tracking on). Skips a shop that already
 * has ingredients, so it can run on an existing database.
 */
class SampleInventorySeeder extends Seeder
{
    /** name => [unit, opening stock, cost per unit, warn at] */
    private const INGREDIENTS = [
        'Chicken fillet' => ['kg', 25, 950, 5],
        'Beef mince' => ['kg', 15, 1600, 4],
        'Burger bun' => ['pcs', 120, 25, 30],
        'Cheese slice' => ['pcs', 200, 18, 40],
        'Burger sauce' => ['kg', 5, 600, 1],
        'Lettuce' => ['kg', 3, 300, 0.5],
        'Frozen fries' => ['kg', 20, 450, 5],
        'Chicken nuggets' => ['pcs', 300, 22, 60],
        'Onion rings (frozen)' => ['kg', 5, 700, 1],
        'Jalapeños' => ['kg', 2, 900, 0.3],
        'Eggs' => ['pcs', 60, 30, 12],
        'Coke can 345 ml' => ['pcs', 96, 80, 24],
        'Sprite can 345 ml' => ['pcs', 72, 80, 24],
        'Mineral water 500 ml' => ['pcs', 48, 40, 12],
        'Mint margarita mix' => ['L', 4, 500, 1],
        'Cooking oil' => ['L', 30, 520, 8],
    ];

    /** product => variant => [[ingredient, quantity, unit], ...] */
    private const RECIPES = [
        'Zinger Burger' => [
            'Regular' => [['Chicken fillet', 150, 'g'], ['Burger bun', 1, 'pcs'], ['Burger sauce', 30, 'g'], ['Lettuce', 20, 'g'], ['Cooking oil', 50, 'ml']],
            'Large' => [['Chicken fillet', 220, 'g'], ['Burger bun', 1, 'pcs'], ['Burger sauce', 40, 'g'], ['Lettuce', 25, 'g'], ['Cooking oil', 70, 'ml']],
        ],
        'Beef Smash Burger' => [
            'Regular' => [['Beef mince', 200, 'g'], ['Burger bun', 1, 'pcs'], ['Cheese slice', 1, 'pcs'], ['Burger sauce', 30, 'g']],
        ],
        'Chicken Tikka Burger' => [
            'Regular' => [['Chicken fillet', 150, 'g'], ['Burger bun', 1, 'pcs'], ['Burger sauce', 30, 'g'], ['Lettuce', 20, 'g']],
        ],
        'Double Trouble' => [
            'Regular' => [['Beef mince', 400, 'g'], ['Burger bun', 1, 'pcs'], ['Cheese slice', 2, 'pcs'], ['Burger sauce', 40, 'g']],
        ],
        'Plain Fries' => [
            'Regular' => [['Frozen fries', 150, 'g'], ['Cooking oil', 40, 'ml']],
            'Large' => [['Frozen fries', 220, 'g'], ['Cooking oil', 60, 'ml']],
        ],
        'Loaded Fries' => [
            'Regular' => [['Frozen fries', 200, 'g'], ['Cheese slice', 2, 'pcs'], ['Jalapeños', 20, 'g'], ['Cooking oil', 50, 'ml']],
            'Large' => [['Frozen fries', 300, 'g'], ['Cheese slice', 3, 'pcs'], ['Jalapeños', 30, 'g'], ['Cooking oil', 70, 'ml']],
        ],
        'Chicken Nuggets (6 pcs)' => [
            'Regular' => [['Chicken nuggets', 6, 'pcs'], ['Cooking oil', 50, 'ml']],
        ],
        'Onion Rings' => [
            'Regular' => [['Onion rings (frozen)', 150, 'g'], ['Cooking oil', 50, 'ml']],
        ],
        'Coke (345 ml)' => ['Regular' => [['Coke can 345 ml', 1, 'pcs']]],
        'Sprite (345 ml)' => ['Regular' => [['Sprite can 345 ml', 1, 'pcs']]],
        'Mineral Water' => ['Regular' => [['Mineral water 500 ml', 1, 'pcs']]],
        'Mint Margarita' => ['Regular' => [['Mint margarita mix', 250, 'ml']]],
    ];

    /** add-on option => [[ingredient, quantity, unit], ...] */
    private const ADD_ONS = [
        'Extra Cheese' => [['Cheese slice', 1, 'pcs']],
        'Jalapeños' => [['Jalapeños', 20, 'g']],
        'Extra Patty' => [['Beef mince', 150, 'g']],
        'Fried Egg' => [['Eggs', 1, 'pcs']],
    ];

    public function __construct(
        private TenantContext $tenancy,
        private Stock $stock,
        private Recipes $recipes,
        private Purchases $purchases,
    ) {}

    public function run(): void
    {
        $shop = Shop::query()->where('prefix', 'blg')->first();

        if (! $shop) {
            return;
        }

        $this->tenancy->run($shop, function () {
            if (Ingredient::query()->exists()) {
                return;
            }

            $ingredients = [];
            foreach (self::INGREDIENTS as $name => [$unit, $opening, $cost, $warnAt]) {
                $ingredient = Ingredient::create(['name' => $name, 'unit' => $unit, 'low_stock_threshold' => $warnAt]);
                $this->stock->opening($ingredient, $opening, $cost);
                $ingredients[$name] = $ingredient;
            }

            $rows = fn (array $items) => array_map(fn ($i) => [
                'ingredient_id' => $ingredients[$i[0]]->id, 'quantity' => $i[1], 'unit' => $i[2],
            ], $items);

            foreach (self::RECIPES as $productName => $variants) {
                $product = Product::query()->where('name', $productName)->with('variants')->first();

                foreach ($variants as $variantName => $items) {
                    $variant = $product?->variants->firstWhere('name', $variantName);

                    if ($variant) {
                        $this->recipes->save($variant, $rows($items));
                    }
                }
            }

            foreach (self::ADD_ONS as $optionName => $items) {
                foreach (ModifierOption::query()->where('name', $optionName)->get() as $option) {
                    $this->recipes->save($option, $rows($items));
                }
            }

            // One purchase on the bank: chicken by the kg and buns by the dozen.
            $this->purchases->record([
                'supplier_name' => 'Al-Madina Poultry & Bakery',
                'account_id' => Account::query()->where('name', 'Meezan Bank')->value('id'),
                'note' => 'Weekly restock',
            ], [
                ['ingredient_id' => $ingredients['Chicken fillet']->id, 'quantity' => 10, 'unit' => 'kg', 'line_total' => 9800],
                ['ingredient_id' => $ingredients['Burger bun']->id, 'quantity' => 5, 'unit' => 'dozen', 'line_total' => 1500],
            ]);
        });
    }
}
