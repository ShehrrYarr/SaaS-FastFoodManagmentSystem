<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\BusinessDay;
use App\Models\ComboItem;
use App\Models\Ingredient;
use App\Models\Kot;
use App\Models\ModifierOption;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\Quantity;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only way an ingredient's stock changes. Every change locks the
 * ingredient rows in id order (so two tills never deadlock or lose an update)
 * and writes a signed stock movement with the balance after it.
 */
class Stock
{
    public function __construct(private TenantContext $tenancy) {}

    public function enabled(): bool
    {
        return (bool) $this->tenancy->shop()?->inventory_tracking_enabled;
    }

    // ---------------------------------------------------------------- recipes -> ingredient use

    /**
     * What one unit of each line uses: the variant's recipe, plus the recipes of
     * the chosen add-ons, plus (for a deal) its items' recipes × their quantity.
     *
     * @param  array<int|string, array{variant_id: int, product_id: ?int, option_ids: list<int>}>  $lines
     * @return array<int|string, array<int, string>> line key => [ingredient_id => quantity in its unit]
     */
    public function perUnit(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $components = ComboItem::query()
            ->whereIn('combo_product_id', array_filter(array_column($lines, 'product_id')))
            ->get(['combo_product_id', 'product_variant_id', 'quantity'])
            ->groupBy('combo_product_id');

        $variantIds = array_merge(array_column($lines, 'variant_id'), $components->flatten()->pluck('product_variant_id')->all());
        $optionIds = array_merge(...array_values(array_map(fn ($l) => $l['option_ids'] ?? [], $lines)) ?: [[]]);

        $recipes = RecipeItem::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('recipeable_type', ProductVariant::class)->whereIn('recipeable_id', array_unique($variantIds) ?: [0]))
                ->orWhere(fn ($q) => $q->where('recipeable_type', ModifierOption::class)->whereIn('recipeable_id', array_unique($optionIds) ?: [0])))
            ->get(['recipeable_type', 'recipeable_id', 'ingredient_id', 'base_quantity'])
            ->groupBy(fn (RecipeItem $r) => $r->recipeable_type.'#'.$r->recipeable_id);

        $use = function (array &$totals, string $type, int $id, int|string $times) use ($recipes): void {
            foreach ($recipes->get($type.'#'.$id, []) as $row) {
                $totals[$row->ingredient_id] = Quantity::add($totals[$row->ingredient_id] ?? 0, Quantity::mul($row->base_quantity, $times));
            }
        };

        $result = [];

        foreach ($lines as $key => $line) {
            $totals = [];
            $use($totals, ProductVariant::class, (int) $line['variant_id'], 1);

            foreach ($components->get($line['product_id'] ?? 0, []) as $component) {
                $use($totals, ProductVariant::class, (int) $component->product_variant_id, $component->quantity);
            }

            foreach ($line['option_ids'] ?? [] as $optionId) {
                $use($totals, ModifierOption::class, (int) $optionId, 1);
            }

            $totals = array_filter($totals, fn ($q) => Quantity::isPositive($q));

            if ($totals !== []) {
                ksort($totals);
                $result[$key] = $totals;
            }
        }

        return $result;
    }

    // ---------------------------------------------------------------- orders

    /**
     * Takes stock for lines just sent to the kitchen and stores what one unit of
     * each line used. Must run inside the send transaction.
     *
     * @param  array<int, array{item: OrderItem, option_ids: list<int>}>  $sent
     * @return list<string> low / negative stock warnings
     */
    public function takeForKitchen(Kot $kot, array $sent, BusinessDay $day, string $reference): array
    {
        $lines = [];
        foreach ($sent as $row) {
            $lines[$row['item']->id] = [
                'variant_id' => $row['item']->product_variant_id,
                'product_id' => $row['item']->product_id,
                'option_ids' => $row['option_ids'],
            ];
        }

        $perUnit = $this->perUnit($lines);
        $totals = [];
        $needed = [];

        foreach ($sent as $row) {
            $item = $row['item'];
            $use = $perUnit[$item->id] ?? null;

            if (! $use) {
                continue;
            }

            $item->forceFill(['stock_snapshot' => $use])->save();

            foreach ($use as $ingredientId => $quantity) {
                $totals[$ingredientId] = Quantity::sub($totals[$ingredientId] ?? 0, Quantity::mul($quantity, $item->quantity));
                $needed[$ingredientId][] = $item->displayName();
            }
        }

        return $this->apply($totals, 'sale', $kot, $day, $reference, blockNegative: ! $this->tenancy->shop()->allow_negative_stock, neededFor: $needed);
    }

    /** Gives back what $quantity of a sent line used (from its snapshot, so recipe edits since don't matter). */
    public function returnFromVoid(OrderItem $item, int $quantity, Kot $kot, BusinessDay $day, string $reference): void
    {
        if (! $item->stock_snapshot || $quantity < 1) {
            return;
        }

        $totals = [];
        foreach ($item->stock_snapshot as $ingredientId => $perUnit) {
            $totals[(int) $ingredientId] = Quantity::mul($perUnit, $quantity);
        }

        $this->apply($totals, 'void_return', $kot, $day, $reference);
    }

    // ---------------------------------------------------------------- manual changes

    /** Stock already on hand when an ingredient is added, at a known cost per unit. */
    public function opening(Ingredient $ingredient, string|int|float $quantity, string|int|float $costPerUnit = 0): void
    {
        if (! Quantity::isPositive($quantity)) {
            return;
        }

        DB::transaction(function () use ($ingredient, $quantity, $costPerUnit) {
            $locked = $this->lock([$ingredient->id])->first();
            $locked->avg_cost = Quantity::of($costPerUnit);
            $this->move($locked, 'opening', Quantity::of($quantity), null, null, 'Opening stock', $locked->avg_cost);
            $ingredient->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Sets the stock to what was counted; the difference is the adjustment. */
    public function count(Ingredient $ingredient, string|int|float $counted, string $note): StockMovement
    {
        return DB::transaction(function () use ($ingredient, $counted, $note) {
            $locked = $this->lock([$ingredient->id])->first();
            $difference = Quantity::sub($counted, $locked->current_stock);

            if (Quantity::isZero($difference)) {
                throw new PosException('The count matches the stock already. Nothing to adjust.');
            }

            $movement = $this->move($locked, 'adjustment', $difference, null, null, $note, $locked->avg_cost);
            $ingredient->setRawAttributes($locked->getAttributes(), true);

            return $movement;
        }, 3);
    }

    /** Spoiled, dropped or expired stock. */
    public function waste(Ingredient $ingredient, string|int|float $quantity, string $note): StockMovement
    {
        if (! Quantity::isPositive($quantity)) {
            throw new PosException('Enter how much was wasted.');
        }

        return DB::transaction(function () use ($ingredient, $quantity, $note) {
            $locked = $this->lock([$ingredient->id])->first();
            $movement = $this->move($locked, 'wastage', Quantity::sub(0, $quantity), null, null, $note, $locked->avg_cost);
            $ingredient->setRawAttributes($locked->getAttributes(), true);

            return $movement;
        }, 3);
    }

    /**
     * Adds purchased stock and moves the weighted average cost:
     * new avg = (stock on hand × avg + amount paid) / (stock on hand + quantity bought).
     */
    public function receive(Ingredient $locked, string $quantity, string|int|float $lineTotal, Model $source, ?BusinessDay $day, string $reference): void
    {
        $onHand = Quantity::isPositive($locked->current_stock) ? Quantity::of($locked->current_stock) : '0.0000';
        $value = bcadd(bcmul($onHand, Quantity::of($locked->avg_cost), 8), Money::of($lineTotal), 8);
        $newQuantity = bcadd($onHand, $quantity, 8);

        $locked->avg_cost = Quantity::isPositive($newQuantity) ? Quantity::round(bcdiv($value, $newQuantity, 8)) : '0.0000';

        $this->move($locked, 'purchase', $quantity, $source, $day, $reference, Quantity::round(bcdiv(Money::of($lineTotal), $quantity, 8)));
    }

    // ---------------------------------------------------------------- internals

    /**
     * Locks ingredient rows in id order. Everything that touches stock locks this
     * way, so concurrent tills queue instead of deadlocking.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Ingredient>
     */
    public function lock(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return Ingredient::query()->whereIn('id', $ids ?: [0])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * @param  array<int, string>  $deltas  ingredient_id => signed quantity
     * @param  array<int, list<string>>  $neededFor  ingredient_id => item names (for the error message)
     * @return list<string> warnings
     */
    private function apply(array $deltas, string $type, Model $source, ?BusinessDay $day, ?string $note, bool $blockNegative = false, array $neededFor = []): array
    {
        $deltas = array_filter($deltas, fn ($q) => ! Quantity::isZero($q));

        if ($deltas === []) {
            return [];
        }

        $ingredients = $this->lock(array_keys($deltas));
        $warnings = [];

        foreach ($ingredients as $id => $ingredient) {
            $delta = $deltas[$id];
            $after = Quantity::add($ingredient->current_stock, $delta);

            if ($blockNegative && Quantity::isNegative($delta) && Quantity::isNegative($after)) {
                throw new PosException(sprintf(
                    'Not enough %s for %s: %s left, %s needed.',
                    $ingredient->name,
                    implode(', ', array_unique($neededFor[$id] ?? ['this order'])),
                    Quantity::format(Quantity::max0($ingredient->current_stock), $ingredient->unit),
                    Quantity::format(Quantity::sub(0, $delta), $ingredient->unit),
                ));
            }

            $this->move($ingredient, $type, $delta, $source, $day, $note, $ingredient->avg_cost);

            if (Quantity::isNegative($delta)) {
                $warning = match ($ingredient->stockStatus()) {
                    'out' => $ingredient->name.(Quantity::isNegative($ingredient->current_stock) ? ' is below zero ('.$ingredient->stockLabel().')' : ' has run out'),
                    'low' => $ingredient->name.' is low ('.$ingredient->stockLabel().' left)',
                    default => null,
                };

                if ($warning) {
                    $warnings[] = $warning;
                }
            }
        }

        return $warnings;
    }

    private function move(Ingredient $locked, string $type, string $delta, ?Model $source, ?BusinessDay $day, ?string $note, string|int|float|null $unitCost): StockMovement
    {
        $locked->current_stock = Quantity::add($locked->current_stock, $delta);
        $locked->save();

        $movement = new StockMovement([
            'tenant_id' => $locked->tenant_id,
            'ingredient_id' => $locked->id,
            'type' => $type,
            'quantity' => $delta,
            'balance_after' => $locked->current_stock,
            'unit_cost' => $unitCost === null ? null : Quantity::of($unitCost),
            'business_day_id' => $day?->id,
            'user_id' => Auth::guard('shop')->id(),
            'note' => $note === null ? null : mb_substr($note, 0, 255),
        ]);

        if ($source) {
            $movement->source()->associate($source);
        }

        $movement->save();

        return $movement;
    }
}
