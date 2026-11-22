<?php

namespace App\Models;

use App\Support\Money;
use App\Support\Quantity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the kitchen uses up (chicken fillet, buns, oil). current_stock
 * changes only through App\Services\Stock, which writes a stock movement.
 */
class Ingredient extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'current_stock' => 0,
        'avg_cost' => 0,
        'is_active' => true,
    ];

    protected $fillable = ['tenant_id', 'name', 'unit', 'low_stock_threshold', 'is_active'];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:4',
            'low_stock_threshold' => 'decimal:4',
            'avg_cost' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLow(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('current_stock', '<=', 0)
            ->orWhere(fn (Builder $q) => $q->whereNotNull('low_stock_threshold')->whereColumn('current_stock', '<=', 'low_stock_threshold')));
    }

    /** ok | low | out */
    public function stockStatus(): string
    {
        if (! Quantity::isPositive($this->current_stock)) {
            return 'out';
        }

        if ($this->low_stock_threshold !== null && Quantity::compare($this->current_stock, $this->low_stock_threshold) <= 0) {
            return 'low';
        }

        return 'ok';
    }

    /** @return array{label: string, class: string} */
    public function stockBadge(): array
    {
        return match ($this->stockStatus()) {
            'out' => ['label' => Quantity::isNegative($this->current_stock) ? 'Negative' : 'Out', 'class' => 'bg-danger-subtle text-danger'],
            'low' => ['label' => 'Low', 'class' => 'bg-warning-subtle text-warning'],
            default => ['label' => 'In stock', 'class' => 'bg-success-subtle text-success'],
        };
    }

    public function stockLabel(): string
    {
        return Quantity::format($this->current_stock, $this->unit);
    }

    /** Value of the stock on hand at average cost. */
    public function stockValue(): string
    {
        return Quantity::isPositive($this->current_stock)
            ? Money::of(bcmul(Quantity::of($this->current_stock), Quantity::of($this->avg_cost), 4))
            : '0.00';
    }

    public function unitLabel(): string
    {
        return Quantity::UNITS[$this->unit][2] ?? $this->unit;
    }
}
