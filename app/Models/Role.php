<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'is_locked' => false,
    ];

    protected $fillable = ['tenant_id', 'name', 'description', 'permissions', 'is_locked'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_locked' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
