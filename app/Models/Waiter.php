<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A waiter or delivery rider. Name tags only: they never sign in. */
class Waiter extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'waiter' => 'Waiter',
        'rider' => 'Rider',
    ];

    protected $attributes = [
        'type' => 'waiter',
        'is_active' => true,
    ];

    protected $fillable = ['tenant_id', 'name', 'phone', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeWaiters(Builder $query): Builder
    {
        return $query->where('type', 'waiter');
    }

    public function scopeRiders(Builder $query): Builder
    {
        return $query->where('type', 'rider');
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))) ?: '?');
    }
}
