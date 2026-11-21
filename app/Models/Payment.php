<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part (or all) of a bill paid into one account. Split bills have several. */
class Payment extends Model
{
    use BelongsToTenant;

    protected $attributes = ['change_given' => 0];

    protected $fillable = [
        'tenant_id', 'order_id', 'account_id', 'business_day_id',
        'amount', 'tendered', 'change_given', 'reference', 'received_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tendered' => 'decimal:2',
            'change_given' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
