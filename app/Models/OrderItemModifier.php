<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends Model
{
    use BelongsToTenant;

    protected $attributes = ['price' => 0];

    protected $fillable = ['tenant_id', 'order_item_id', 'modifier_option_id', 'group_name', 'name', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
