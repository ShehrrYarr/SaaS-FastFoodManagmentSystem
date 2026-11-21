<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A menu item. Prices live on its variants (at least one, e.g. "Regular").
 * A combo is a product whose components are other products' variants.
 * Deleting archives it (soft delete) so past orders keep their history.
 */
class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPES = [
        'simple' => 'Item',
        'combo' => 'Combo deal',
    ];

    protected $attributes = [
        'type' => 'simple',
        'is_available' => true,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'category_id', 'name', 'sku', 'type', 'description',
        'image_path', 'is_available', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Auto SKU: BLG-P-0001, BLG-P-0002, ... per shop.
        static::creating(function (Product $product) {
            if (filled($product->sku)) {
                return;
            }

            $shop = app(TenantContext::class)->shop() ?? Shop::query()->find($product->tenant_id);
            $number = ShopSequence::next((int) $product->tenant_id, 'sku');

            $product->sku = ShopSequence::format($shop?->order_prefix ?: 'SKU', $number, 'P', 4);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id');
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function comboItems(): HasMany
    {
        return $this->hasMany(ComboItem::class, 'combo_product_id')->orderBy('sort_order')->orderBy('id');
    }

    /** Menu order: by category position, then the product's own position. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('category_id is null')
            ->orderBy(Category::query()->select('sort_order')->whereColumn('categories.id', 'products.category_id'))
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function isCombo(): bool
    {
        return $this->type === 'combo';
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** "Rs 650" or "Rs 650 – 850" (uses loaded variants). */
    public function priceLabel(Shop $shop): string
    {
        $prices = $this->variants->pluck('price')->map(fn ($p) => (float) $p);

        if ($prices->isEmpty()) {
            return '—';
        }

        $min = $prices->min();
        $max = $prices->max();

        return $min === $max ? $shop->money($min) : $shop->money($min).' – '.number_format($max, floor($max) == $max ? 0 : 2);
    }

    public function initials(): string
    {
        // First letter of the first two words, ignoring brackets and numbers: "Coke (345 ml)" -> "C".
        $name = preg_replace('/\(.*?\)/u', '', $this->name);
        preg_match_all('/(?<![\p{L}\p{N}])\p{L}/u', $name, $m);

        return mb_strtoupper(implode('', array_slice($m[0], 0, 2))) ?: '?';
    }
}
