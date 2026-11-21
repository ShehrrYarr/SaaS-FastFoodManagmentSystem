<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One line in an account's ledger. Amount is signed: + money in, - money out. */
class AccountTransaction extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'opening' => 'Opening balance',
        'sale' => 'Sale',
        'refund' => 'Refund',
        'transfer_in' => 'Transfer in',
        'transfer_out' => 'Transfer out',
        'expense' => 'Expense',
        'purchase' => 'Stock purchase',
        'cash_in' => 'Cash in',
        'cash_out' => 'Cash out',
        'over_short' => 'Cash over / short',
        'adjustment' => 'Adjustment',
    ];

    protected $fillable = [
        'tenant_id', 'account_id', 'type', 'amount', 'balance_after',
        'source_type', 'source_id', 'business_day_id', 'user_id', 'note', 'transacted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }
}
