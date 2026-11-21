<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who took back how many of a sent line, and why. */
class OrderItemVoid extends Model
{
    use BelongsToTenant;

    protected $attributes = ['amount' => 0];

    protected $fillable = ['tenant_id', 'order_id', 'order_item_id', 'kot_id', 'business_day_id', 'quantity', 'amount', 'reason', 'user_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
