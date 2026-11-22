<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $attributes = [
        'is_default' => false,
        'is_available' => true,
        'sort_order' => 0,
    ];

    protected $fillable = ['tenant_id', 'product_id', 'name', 'price', 'is_default', 'is_available', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_default' => 'boolean',
            'is_available' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** "Zinger Burger (Large)", or just the product name for a single "Regular" variant. */
    public function fullName(): string
    {
        $product = $this->product;

        return $product->variants()->count() > 1 ? $product->name.' ('.$this->name.')' : $product->name;
    }

    /** What one of these uses from inventory. */
    public function recipeItems(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(RecipeItem::class, 'recipeable')->orderBy('id');
    }
}
