<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Account;
use App\Models\Ingredient;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\ShopSequence;
use App\Support\Money;
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records a stock purchase: a voucher number (BLG-PUR-000001), stock in for
 * each line (converted to the ingredient's unit), a new average cost, and,
 * when paid from an account, the money out of that account's ledger.
 */
class Purchases
{
    public function __construct(
        private TenantContext $tenancy,
        private Stock $stock,
        private AccountLedger $ledger,
        private BusinessDays $days,
    ) {}

    /**
     * @param  array{supplier_name?: ?string, purchased_on?: ?string, account_id?: int|string|null, note?: ?string}  $details
     * @param  list<array{ingredient_id: int|string, quantity: mixed, unit: string, line_total: mixed}>  $lines
     */
    public function record(array $details, array $lines): Purchase
    {
        $shop = $this->tenancy->shop();
        $lines = array_values(array_filter($lines, fn ($l) => filled($l['ingredient_id'] ?? null)));

        if ($lines === []) {
            throw new PosException('Add at least one item to the purchase.');
        }

        $date = filled($details['purchased_on'] ?? null) ? Carbon::parse($details['purchased_on'])->toDateString() : $shop->today()->toDateString();

        if ($date > $shop->today()->toDateString()) {
            throw new PosException('The purchase date cannot be in the future.');
        }

        return DB::transaction(function () use ($shop, $details, $lines, $date) {
            // Shared lock on the open day first, the same order as sales (see BusinessDays::current).
            $day = $this->days->current(lock: true);
            $ingredients = $this->stock->lock(array_column($lines, 'ingredient_id'));

            $prepared = [];
            foreach ($lines as $i => $line) {
                $ingredient = $ingredients->get((int) $line['ingredient_id']) ?? throw new PosException('Line '.($i + 1).': choose an ingredient.');
                $quantity = Quantity::of($line['quantity'] ?? 0);
                $unit = (string) ($line['unit'] ?? $ingredient->unit);
                $total = Money::of($line['line_total'] ?? 0);

                if (! Quantity::isPositive($quantity)) {
                    throw new PosException('Line '.($i + 1).': enter a quantity.');
                }
                if (! in_array($unit, Quantity::compatible($ingredient->unit), true)) {
                    throw new PosException($ingredient->name.' is counted in '.$ingredient->unit.'; buy it in '.implode(' or ', Quantity::compatible($ingredient->unit)).'.');
                }
                if (Money::isNegative($total)) {
                    throw new PosException('Line '.($i + 1).': the amount cannot be negative.');
                }

                $prepared[] = [
                    'ingredient' => $ingredient,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'base_quantity' => Quantity::convert($quantity, $unit, $ingredient->unit),
                    'unit_cost' => Quantity::round(bcdiv($total, $quantity, 8)),
                    'line_total' => $total,
                ];
            }

            $account = filled($details['account_id'] ?? null)
                ? (Account::query()->active()->find((int) $details['account_id']) ?? throw new PosException('Choose an active account to pay from.'))
                : null;

            $sequence = ShopSequence::next($shop->id, 'purchase');

            $purchase = Purchase::create([
                'voucher_number' => ShopSequence::format($shop->order_prefix, $sequence, 'PUR'),
                'sequence' => $sequence,
                'supplier_name' => $this->clean($details['supplier_name'] ?? null, 120),
                'purchased_on' => $date,
                'account_id' => $account?->id,
                'business_day_id' => $day?->id,
                'total' => Money::sum(array_column($prepared, 'line_total')),
                'note' => $this->clean($details['note'] ?? null, 500),
                'created_by' => Auth::guard('shop')->id(),
            ]);

            foreach ($prepared as $line) {
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'ingredient_id' => $line['ingredient']->id,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'base_quantity' => $line['base_quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $line['line_total'],
                ]);

                if (Quantity::isPositive($line['base_quantity'])) {
                    $this->stock->receive($line['ingredient'], $line['base_quantity'], $line['line_total'], $purchase, $day, $purchase->voucher_number);
                }
            }

            if ($account && Money::isPositive($purchase->total)) {
                $this->ledger->post($account, 'purchase', Money::sub(0, $purchase->total), $purchase, 'Purchase '.$purchase->voucher_number, businessDayId: $day?->id, mustCover: true);
            }

            return $purchase->load('items.ingredient', 'account');
        }, 3);
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
