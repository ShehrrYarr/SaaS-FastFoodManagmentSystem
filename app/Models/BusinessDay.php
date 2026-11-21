<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trading session. The POS only takes orders while a day is open.
 * Opening and closing are done by App\Services\BusinessDays.
 */
class BusinessDay extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'open',
        'opening_cash_expected' => 0,
        'opening_cash_counted' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'business_date', 'status',
        'opened_by', 'opened_at', 'opening_cash_expected', 'opening_cash_counted', 'opening_note',
        'closed_by', 'closed_at', 'expected_cash', 'counted_cash', 'cash_difference', 'closing_note', 'summary',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash_expected' => 'decimal:2',
            'opening_cash_counted' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'summary' => 'array',
        ];
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** "Sat 21 Nov 2026" */
    public function label(): string
    {
        return $this->business_date->format('D d M Y');
    }
}
