<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money moved between two of the shop's accounts (e.g. the drawer banked at Meezan). */
class Transfer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'voucher_number', 'sequence', 'from_account_id', 'to_account_id',
        'amount', 'note', 'business_day_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
