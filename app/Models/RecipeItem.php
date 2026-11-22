<?php

namespace App\Models;

use App\Support\Quantity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One ingredient in the recipe of a product variant or an add-on option,
 * for one unit sold. "200 g" of a kg ingredient is stored with base_quantity 0.2.
 */
class RecipeItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'recipeable_type', 'recipeable_id', 'ingredient_id', 'quantity', 'unit', 'base_quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'base_quantity' => 'decimal:4',
        ];
    }

    public function recipeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function label(): string
    {
        return Quantity::format($this->quantity, $this->unit);
    }
}
