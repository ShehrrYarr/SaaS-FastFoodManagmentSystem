<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Ingredient;
use App\Models\ModifierOption;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saves the recipe of a product variant or add-on option: which ingredients
 * one unit uses, entered in any unit of the ingredient's family.
 */
class Recipes
{
    /**
     * Replaces the recipe.
     *
     * @param  list<array{ingredient_id: int|string, quantity: mixed, unit: string}>  $rows
     */
    public function save(ProductVariant|ModifierOption $recipeable, array $rows): Collection
    {
        $rows = array_values(array_filter($rows, fn ($r) => filled($r['ingredient_id'] ?? null)));
        $ids = array_map(fn ($r) => (int) $r['ingredient_id'], $rows);

        if (count($ids) !== count(array_unique($ids))) {
            throw new PosException('Each ingredient can appear only once in a recipe.');
        }

        $ingredients = Ingredient::query()->whereIn('id', $ids ?: [0])->get()->keyBy('id');

        $prepared = [];
        foreach ($rows as $i => $row) {
            $ingredient = $ingredients->get((int) $row['ingredient_id']) ?? throw new PosException('Line '.($i + 1).': choose an ingredient.');
            $unit = (string) ($row['unit'] ?: $ingredient->unit);

            if (! is_numeric($row['quantity'] ?? null) || ! Quantity::isPositive($row['quantity'])) {
                throw new PosException($ingredient->name.': enter how much one serving uses.');
            }
            if (! in_array($unit, Quantity::compatible($ingredient->unit), true)) {
                throw new PosException($ingredient->name.' is counted in '.$ingredient->unit.'; use '.implode(' or ', Quantity::compatible($ingredient->unit)).'.');
            }

            $base = Quantity::convert($row['quantity'], $unit, $ingredient->unit);

            if (! Quantity::isPositive($base)) {
                throw new PosException($ingredient->name.': '.Quantity::format($row['quantity'], $unit).' is too small to track in '.$ingredient->unit.'.');
            }

            $prepared[] = [
                'ingredient_id' => $ingredient->id,
                'quantity' => Quantity::of($row['quantity']),
                'unit' => $unit,
                'base_quantity' => $base,
            ];
        }

        return DB::transaction(function () use ($recipeable, $prepared) {
            $recipeable->recipeItems()->delete();

            foreach ($prepared as $row) {
                $recipeable->recipeItems()->create($row);
            }

            return $recipeable->recipeItems()->with('ingredient')->get();
        });
    }

    /** Ingredient cost of one unit at current average costs. */
    public static function cost(Collection $recipeItems): string
    {
        return Money::sum($recipeItems->map(fn (RecipeItem $item) => bcmul(Quantity::of($item->base_quantity), Quantity::of($item->ingredient?->avg_cost ?? 0), 4)));
    }

    public static function morphKey(Model $recipeable): string
    {
        return $recipeable->getMorphClass().'#'.$recipeable->getKey();
    }
}
