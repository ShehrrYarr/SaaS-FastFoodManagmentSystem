<?php

namespace App\Models;

use App\Support\Quantity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'purchase_id', 'ingredient_id', 'quantity', 'unit', 'base_quantity', 'unit_cost', 'line_total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function quantityLabel(): string
    {
        return Quantity::format($this->quantity, $this->unit);
    }
}
