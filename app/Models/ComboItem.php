<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboItem extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'quantity' => 1,
        'sort_order' => 0,
    ];

    protected $fillable = ['tenant_id', 'combo_product_id', 'product_variant_id', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'combo_product_id')->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
