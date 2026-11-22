<?php

namespace App\Models;

use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line on an order, with its name and prices copied from the menu when it
 * was sent to the kitchen. Voiding lowers the active quantity; the line stays
 * for the record.
 */
class OrderItem extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'modifiers_total' => 0,
        'voided_quantity' => 0,
        'discount_value' => 0,
        'discount_amount' => 0,
        'line_total' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'order_id', 'kot_id', 'product_id', 'product_variant_id',
        'name', 'variant_name', 'unit_price', 'modifiers_total', 'quantity', 'voided_quantity',
        'discount_type', 'discount_value', 'discount_amount', 'line_total', 'note', 'combo_snapshot', 'stock_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'modifiers_total' => 'decimal:2',
            'quantity' => 'integer',
            'voided_quantity' => 'integer',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'combo_snapshot' => 'array',
            'stock_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function kot(): BelongsTo
    {
        return $this->belongsTo(Kot::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class)->orderBy('id');
    }

    public function voids(): HasMany
    {
        return $this->hasMany(OrderItemVoid::class)->orderBy('id');
    }

    public function activeQuantity(): int
    {
        return max(0, $this->quantity - $this->voided_quantity);
    }

    /** Price of one unit including add-ons. */
    public function eachPrice(): string
    {
        return Money::add($this->unit_price, $this->modifiers_total);
    }

    /** Before the line discount. */
    public function grossTotal(): string
    {
        return Money::mul($this->eachPrice(), $this->activeQuantity());
    }

    /** Recomputes the discount and line total from the active quantity. */
    public function recalculate(): void
    {
        $gross = $this->grossTotal();

        $this->discount_amount = Money::discount($gross, $this->discount_type, $this->discount_value);
        $this->line_total = Money::sub($gross, $this->discount_amount);
    }

    /** "Zinger Burger (Large)" */
    public function displayName(): string
    {
        return $this->variant_name ? $this->name.' ('.$this->variant_name.')' : $this->name;
    }

    public function isFullyVoided(): bool
    {
        return $this->activeQuantity() === 0;
    }
}
