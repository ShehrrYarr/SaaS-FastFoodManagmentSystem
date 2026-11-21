<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DiningTable extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected $fillable = ['tenant_id', 'name', 'area', 'capacity', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByRaw('LENGTH(name)')->orderBy('name');
    }
}
