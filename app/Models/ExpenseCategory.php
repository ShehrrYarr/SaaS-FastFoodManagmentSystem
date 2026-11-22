<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What an expense was for: rent, salaries, electricity... Each shop gets the defaults. */
class ExpenseCategory extends Model
{
    use BelongsToTenant;

    public const DEFAULTS = [
        'Rent',
        'Salaries & wages',
        'Electricity',
        'Gas',
        'Water',
        'Repairs & maintenance',
        'Cleaning & supplies',
        'Packaging',
        'Transport & fuel',
        'Marketing',
        'Staff meals',
        'Other',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $fillable = ['tenant_id', 'name', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public static function createDefaults(): void
    {
        foreach (self::DEFAULTS as $i => $name) {
            static::create(['name' => $name, 'sort_order' => $i]);
        }
    }
}
