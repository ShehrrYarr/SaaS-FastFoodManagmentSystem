<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Waiter;
use App\Services\AccountLedger;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Sample menus, tables, staff tags, accounts and customers for the demo
 * shops. Skips any shop that already has categories.
 */
class SampleShopDataSeeder extends Seeder
{
    public function __construct(private TenantContext $tenancy, private AccountLedger $ledger) {}

    public function run(): void
    {
        foreach ($this->data() as $prefix => $data) {
            $shop = Shop::query()->where('prefix', $prefix)->first();

            if (! $shop) {
                continue;
            }

            $this->tenancy->run($shop, function () use ($data) {
                if (Category::query()->exists()) {
                    return;
                }

                $this->seedAccounts($data['accounts'], $data['cash_opening']);
                $this->seedTables($data['tables']);

                foreach ($data['waiters'] as $name) {
                    Waiter::create(['name' => $name, 'type' => 'waiter']);
                }
                foreach ($data['riders'] as [$name, $phone]) {
                    Waiter::create(['name' => $name, 'phone' => $phone, 'type' => 'rider']);
                }

                $groups = $this->seedModifierGroups($data['modifiers']);
                $this->seedMenu($data['menu'], $groups);

                foreach ($data['customers'] as [$name, $phone, $address]) {
                    Customer::create(['name' => $name, 'phone' => $phone, 'address' => $address]);
                }
            });
        }
    }

    private function seedAccounts(array $accounts, int $cashOpening): void
    {
        $cash = Account::query()->where('is_default_cash', true)->firstOrFail();
        $cash->update(['opening_balance' => $cashOpening]);
        $this->ledger->post($cash, 'opening', $cashOpening, note: 'Opening float');

        foreach ($accounts as $i => [$name, $type, $title, $number, $opening]) {
            $account = Account::create([
                'name' => $name, 'type' => $type, 'account_title' => $title, 'account_number' => $number,
                'opening_balance' => $opening, 'sort_order' => $i + 1,
            ]);

            if ($opening) {
                $this->ledger->post($account, 'opening', $opening, note: 'Opening balance');
            }
        }
    }

    private function seedTables(array $areas): void
    {
        $sort = 0;
        foreach ($areas as $area => [$prefix, $count, $seats]) {
            for ($i = 1; $i <= $count; $i++) {
                DiningTable::create([
                    'name' => $prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'area' => $area,
                    'capacity' => $seats,
                    'sort_order' => ++$sort,
                ]);
            }
        }
    }

    /** @return array<string, ModifierGroup> */
    private function seedModifierGroups(array $definitions): array
    {
        $groups = [];
        $sort = 0;

        foreach ($definitions as $name => [$min, $max, $options]) {
            $group = ModifierGroup::create(['name' => $name, 'min_select' => $min, 'max_select' => $max, 'sort_order' => ++$sort]);

            $i = 0;
            foreach ($options as $option => $price) {
                $group->options()->create(['name' => $option, 'price' => $price, 'sort_order' => ++$i]);
            }

            $groups[$name] = $group;
        }

        return $groups;
    }

    private function seedMenu(array $menu, array $groups): void
    {
        /** @var array<string, Product> $products */
        $products = [];
        $categorySort = 0;

        foreach ($menu as $categoryName => $items) {
            $category = Category::create(['name' => $categoryName, 'sort_order' => ++$categorySort]);
            $productSort = 0;

            foreach ($items as $name => $item) {
                $product = Product::create([
                    'category_id' => $category->id,
                    'name' => $name,
                    'type' => isset($item['combo']) ? 'combo' : 'simple',
                    'description' => $item['description'] ?? null,
                    'sort_order' => ++$productSort,
                ]);

                $i = 0;
                foreach ($item['prices'] as $variant => $price) {
                    $product->variants()->create(['name' => $variant, 'price' => $price, 'is_default' => $i === 0, 'sort_order' => ++$i]);
                }

                $product->modifierGroups()->sync(
                    collect($item['addons'] ?? [])->values()->mapWithKeys(fn ($g, $i) => [$groups[$g]->id => ['sort_order' => $i + 1]])->all()
                );

                foreach ($item['combo'] ?? [] as $index => [$componentName, $variantName, $quantity]) {
                    $variant = $products[$componentName]->variants()->where('name', $variantName)->firstOrFail();
                    $product->comboItems()->create(['product_variant_id' => $variant->id, 'quantity' => $quantity, 'sort_order' => $index + 1]);
                }

                $products[$name] = $product;
            }
        }
    }

    private function data(): array
    {
        return [
            'blg' => [
                'cash_opening' => 5000,
                'accounts' => [
                    ['Meezan Bank', 'bank', 'Burger Lab Gulberg', 'PK36MEZN0001234567890123', 25000],
                    ['JazzCash', 'wallet', 'Hamza Riaz', '0300-1234567', 0],
                ],
                'tables' => ['Hall' => ['T-', 8, 4], 'Rooftop' => ['R-', 4, 6]],
                'waiters' => ['Ali Hassan', 'Usman Ghani', 'Faisal Iqbal'],
                'riders' => [['Kamran Akmal', '0333-1112233'], ['Zubair Shah', '0345-4445566']],
                'modifiers' => [
                    'Extras' => [0, null, ['Extra Cheese' => 100, 'Jalapeños' => 60, 'Extra Patty' => 250, 'Fried Egg' => 80]],
                    'Spice level' => [1, 1, ['Mild' => 0, 'Regular' => 0, 'Spicy' => 0]],
                    'Dip' => [0, 2, ['Garlic Mayo' => 50, 'Chipotle' => 50, 'Ketchup' => 0]],
                ],
                'menu' => [
                    'Burgers' => [
                        'Zinger Burger' => ['prices' => ['Regular' => 650, 'Large' => 850], 'addons' => ['Extras', 'Spice level']],
                        'Beef Smash Burger' => ['prices' => ['Regular' => 950], 'addons' => ['Extras']],
                        'Chicken Tikka Burger' => ['prices' => ['Regular' => 600], 'addons' => ['Extras', 'Spice level']],
                        'Double Trouble' => ['prices' => ['Regular' => 1250], 'addons' => ['Extras'], 'description' => 'Two beef patties, double cheese.'],
                    ],
                    'Fries & Sides' => [
                        'Plain Fries' => ['prices' => ['Regular' => 250, 'Large' => 350], 'addons' => ['Dip']],
                        'Loaded Fries' => ['prices' => ['Regular' => 450, 'Large' => 650]],
                        'Chicken Nuggets (6 pcs)' => ['prices' => ['Regular' => 450], 'addons' => ['Dip']],
                        'Onion Rings' => ['prices' => ['Regular' => 350], 'addons' => ['Dip']],
                    ],
                    'Drinks' => [
                        'Coke (345 ml)' => ['prices' => ['Regular' => 120]],
                        'Sprite (345 ml)' => ['prices' => ['Regular' => 120]],
                        'Mineral Water' => ['prices' => ['Regular' => 80]],
                        'Mint Margarita' => ['prices' => ['Regular' => 350]],
                    ],
                    'Deals' => [
                        'Deal 1: Zinger Duo' => ['prices' => ['Regular' => 1650], 'combo' => [
                            ['Zinger Burger', 'Regular', 2], ['Plain Fries', 'Regular', 1], ['Coke (345 ml)', 'Regular', 2],
                        ], 'addons' => ['Spice level']],
                        'Deal 2: Smash Meal' => ['prices' => ['Regular' => 1550], 'combo' => [
                            ['Beef Smash Burger', 'Regular', 1], ['Loaded Fries', 'Regular', 1], ['Mint Margarita', 'Regular', 1],
                        ]],
                    ],
                ],
                'customers' => [
                    ['Ahmed Raza', '03001234567', 'House 12, Street 4, Gulberg III, Lahore'],
                    ['Fatima Noor', '03214567890', 'Flat 3B, MM Alam Road, Gulberg, Lahore'],
                    ['Bilal Chaudhry', '03337654321', 'Office 5, Hali Road, Gulberg II, Lahore'],
                ],
            ],
            'ppj' => [
                'cash_opening' => 3000,
                'accounts' => [
                    ['Easypaisa', 'wallet', 'Sana Malik', '0321-7654321', 0],
                ],
                'tables' => ['Dining' => ['T-', 6, 4]],
                'waiters' => ['Hamid Ali'],
                'riders' => [['Imran Khan', '0300-9998877']],
                'modifiers' => [
                    'Extra toppings' => [0, 3, ['Extra Cheese' => 150, 'Olives' => 100, 'Jalapeños' => 80, 'Mushrooms' => 120]],
                    'Crust' => [1, 1, ['Pan' => 0, 'Thin' => 0, 'Stuffed crust' => 250]],
                ],
                'menu' => [
                    'Pizzas' => [
                        'Chicken Tikka Pizza' => ['prices' => ['Small' => 700, 'Medium' => 1200, 'Large' => 1700], 'addons' => ['Crust', 'Extra toppings']],
                        'Chicken Fajita Pizza' => ['prices' => ['Small' => 750, 'Medium' => 1250, 'Large' => 1750], 'addons' => ['Crust', 'Extra toppings']],
                        'Pepperoni Pizza' => ['prices' => ['Small' => 800, 'Medium' => 1350, 'Large' => 1850], 'addons' => ['Crust', 'Extra toppings']],
                        'Veggie Lover Pizza' => ['prices' => ['Small' => 650, 'Medium' => 1100, 'Large' => 1550], 'addons' => ['Crust', 'Extra toppings']],
                    ],
                    'Sides' => [
                        'Garlic Bread' => ['prices' => ['Regular' => 350]],
                        'Chicken Wings (6 pcs)' => ['prices' => ['Regular' => 550]],
                        'Fries' => ['prices' => ['Regular' => 250]],
                    ],
                    'Drinks' => [
                        'Pepsi (1.5 L)' => ['prices' => ['Regular' => 250]],
                        'Pepsi (345 ml)' => ['prices' => ['Regular' => 120]],
                        'Mineral Water' => ['prices' => ['Regular' => 80]],
                    ],
                    'Deals' => [
                        'Family Deal' => ['prices' => ['Regular' => 2600], 'combo' => [
                            ['Chicken Tikka Pizza', 'Large', 1], ['Chicken Fajita Pizza', 'Medium', 1], ['Pepsi (1.5 L)', 'Regular', 1],
                        ]],
                    ],
                ],
                'customers' => [
                    ['Ayesha Siddiqui', '03451239876', 'House 88, Block G1, Johar Town, Lahore'],
                ],
            ],
        ];
    }
}
