<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One line in an ingredient's stock ledger. Quantity is signed: + in, − out. */
class StockMovement extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'opening' => 'Opening stock',
        'purchase' => 'Purchase',
        'sale' => 'Sold',
        'void_return' => 'Void returned',
        'adjustment' => 'Count adjustment',
        'wastage' => 'Wastage',
    ];

    protected $fillable = [
        'tenant_id', 'ingredient_id', 'type', 'quantity', 'balance_after', 'unit_cost',
        'source_type', 'source_id', 'business_day_id', 'user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function tone(): string
    {
        return match ($this->type) {
            'purchase', 'opening', 'void_return' => 'success',
            'wastage' => 'danger',
            'adjustment' => 'warning',
            default => 'primary',
        };
    }
}
