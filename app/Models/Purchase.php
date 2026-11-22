<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stock purchase (BLG-PUR-000001), paid from an account or left unpaid. */
class Purchase extends Model
{
    use BelongsToTenant;

    protected $attributes = ['total' => 0];

    protected $fillable = [
        'tenant_id', 'voucher_number', 'sequence', 'supplier_name', 'purchased_on',
        'account_id', 'business_day_id', 'total', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'purchased_on' => 'date',
            'total' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPaid(): bool
    {
        return $this->account_id !== null;
    }
}
