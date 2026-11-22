<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money spent on running the shop, paid from one account. Recorded and voided
 * through App\Services\Expenses so the account ledger always matches.
 */
class Expense extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'voucher_number', 'sequence', 'expense_category_id', 'account_id', 'amount',
        'spent_on', 'payee', 'note', 'business_day_id', 'created_by',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function businessDay(): BelongsTo
    {
        return $this->belongsTo(BusinessDay::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Expenses that still count (not voided). */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn (Builder $q) => $q
            ->where('voucher_number', 'like', "%{$term}%")
            ->orWhere('payee', 'like', "%{$term}%")
            ->orWhere('note', 'like', "%{$term}%"));
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
