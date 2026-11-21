<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where money lives: the shop's Cash Drawer (created with the shop, cannot be
 * removed) plus any bank accounts or mobile wallets the shop adds.
 * current_balance only changes through App\Services\AccountLedger.
 */
class Account extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'cash' => 'Cash',
        'bank' => 'Bank account',
        'wallet' => 'Mobile wallet',
    ];

    protected $attributes = [
        'type' => 'cash',
        'is_default_cash' => false,
        'opening_balance' => 0,
        'current_balance' => 0,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'name', 'type', 'account_title', 'account_number',
        'is_default_cash', 'opening_balance', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default_cash' => 'boolean',
            'is_active' => 'boolean',
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_default_cash')->orderBy('sort_order')->orderBy('name');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function icon(): string
    {
        return match ($this->type) {
            'bank' => 'ri-bank-line',
            'wallet' => 'ri-smartphone-line',
            default => 'ri-safe-2-line',
        };
    }

    public function tone(): string
    {
        return match ($this->type) {
            'bank' => 'primary',
            'wallet' => 'info',
            default => 'success',
        };
    }
}
