<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModifierOption extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'price' => 0,
        'is_available' => true,
        'sort_order' => 0,
    ];

    protected $fillable = ['tenant_id', 'modifier_group_id', 'name', 'price', 'is_available', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    /** What one of these uses from inventory. */
    public function recipeItems(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(RecipeItem::class, 'recipeable')->orderBy('id');
    }
}
