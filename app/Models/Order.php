<?php

namespace App\Models;

use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dine-in tab, takeaway or delivery order. It is created the first time
 * items are sent to the kitchen, and changed only through App\Services\Orders.
 *
 * Bill: subtotal − item discounts − order discount + service charge (dine-in)
 *       + delivery charge = grand total.
 */
class Order extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'dine_in' => 'Dine-in',
        'takeaway' => 'Takeaway',
        'delivery' => 'Delivery',
    ];

    public const STATUSES = [
        'open' => 'Open',
        'completed' => 'Paid',
        'cancelled' => 'Cancelled',
    ];

    public const DELIVERY_STATUSES = [
        'pending' => 'Preparing',
        'out' => 'Out for delivery',
        'delivered' => 'Delivered',
    ];

    protected $attributes = [
        'status' => 'open',
        'payment_status' => 'unpaid',
        'subtotal' => 0,
        'item_discount_total' => 0,
        'discount_value' => 0,
        'discount_amount' => 0,
        'service_charge_value' => 0,
        'service_charge' => 0,
        'delivery_charge' => 0,
        'grand_total' => 0,
        'paid_total' => 0,
        'change_total' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'order_number', 'sequence', 'token_number', 'business_day_id', 'closed_business_day_id',
        'type', 'status', 'payment_status', 'delivery_status',
        'dining_table_id', 'waiter_id', 'rider_id', 'customer_id',
        'customer_name', 'customer_phone', 'customer_address', 'guests',
        'subtotal', 'item_discount_total', 'discount_type', 'discount_value', 'discount_amount',
        'service_charge_type', 'service_charge_value', 'service_charge', 'delivery_charge',
        'grand_total', 'paid_total', 'change_total',
        'notes', 'opened_by', 'closed_by', 'opened_at', 'dispatched_at', 'closed_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'token_number' => 'integer',
            'guests' => 'integer',
            'subtotal' => 'decimal:2',
            'item_discount_total' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'service_charge_value' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'change_total' => 'decimal:2',
            'opened_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function kots(): HasMany
    {
        return $this->hasMany(Kot::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function voids(): HasMany
    {
        return $this->hasMany(OrderItemVoid::class)->orderBy('id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(Waiter::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Waiter::class, 'rider_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function businessDay(): BelongsTo
    {
        return $this->belongsTo(BusinessDay::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    // ---------------------------------------------------------------- scopes

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /** Matches an order number ("BLG-000123" or just "123"), token, customer phone or name. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('order_number', 'like', '%'.$term.'%')
                ->orWhere('customer_name', 'like', '%'.$term.'%');

            if (ctype_digit($term)) {
                $q->orWhere('sequence', (int) $term);
            }

            $digits = preg_replace('/\D+/', '', $term);
            if (strlen($digits) >= 4) {
                $q->orWhere('customer_phone', 'like', '%'.$digits.'%');
            }
        });
    }

    // ---------------------------------------------------------------- money

    /** Recomputes every total from the (loaded) items and the stored discount and charge settings. */
    public function recalculate(): void
    {
        $items = $this->items;

        foreach ($items as $item) {
            $item->recalculate();
        }

        $subtotal = Money::sum($items->map(fn (OrderItem $item) => $item->grossTotal()));
        $itemDiscounts = Money::sum($items->map(fn (OrderItem $item) => $item->discount_amount));
        $afterItems = Money::sub($subtotal, $itemDiscounts);

        $this->subtotal = $subtotal;
        $this->item_discount_total = $itemDiscounts;
        $this->discount_amount = Money::discount($afterItems, $this->discount_type, $this->discount_value);

        $net = Money::sub($afterItems, $this->discount_amount);

        $this->service_charge = $this->type === 'dine_in' && Money::isPositive($net) && Money::isPositive($this->service_charge_value)
            ? ($this->service_charge_type === 'percent'
                ? Money::percentOf($net, $this->service_charge_value)
                : Money::of($this->service_charge_value))
            : '0.00';

        $this->grand_total = Money::sum([$net, $this->service_charge, $this->type === 'delivery' ? $this->delivery_charge : 0]);
    }

    public function totalDiscount(): string
    {
        return Money::add($this->item_discount_total, $this->discount_amount);
    }

    // ---------------------------------------------------------------- state & labels

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isDineIn(): bool
    {
        return $this->type === 'dine_in';
    }

    public function isDelivery(): bool
    {
        return $this->type === 'delivery';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public static function typeIcon(string $type): string
    {
        return match ($type) {
            'takeaway' => 'ri-shopping-bag-3-line',
            'delivery' => 'ri-e-bike-2-line',
            default => 'ri-restaurant-2-line',
        };
    }

    /** "T-04 · Ali", "Takeaway · Sara", "Delivery · 0300 1234567" */
    public function headline(): string
    {
        return match ($this->type) {
            'dine_in' => collect([$this->table?->name, $this->waiter?->name])->filter()->implode(' · ') ?: 'Dine-in',
            'delivery' => 'Delivery'.($this->customer_name ? ' · '.$this->customer_name : ($this->customer_phone ? ' · '.$this->customer_phone : '')),
            default => 'Takeaway'.($this->customer_name ? ' · '.$this->customer_name : ''),
        };
    }

    /** @return array{label: string, class: string} */
    public function statusBadge(): array
    {
        return match ($this->status) {
            'completed' => ['label' => 'Paid', 'class' => 'bg-success-subtle text-success'],
            'cancelled' => ['label' => 'Cancelled', 'class' => 'bg-danger-subtle text-danger'],
            default => ['label' => 'Open', 'class' => 'bg-warning-subtle text-warning'],
        };
    }

    public function deliveryStatusLabel(): ?string
    {
        return $this->delivery_status ? (self::DELIVERY_STATUSES[$this->delivery_status] ?? null) : null;
    }

    public function minutesOpen(): int
    {
        return (int) $this->opened_at->diffInMinutes($this->closed_at ?? now());
    }

    /** "12 min", "1 h 05 min" */
    public function durationLabel(): string
    {
        $minutes = $this->minutesOpen();

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' min';
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum(fn (OrderItem $item) => $item->activeQuantity());
    }
}
