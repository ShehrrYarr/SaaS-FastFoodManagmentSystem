<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of add-ons or choices, e.g. "Extras" (any, paid) or "Spice level"
 * (pick exactly one). min_select > 0 makes it required at the POS.
 */
class ModifierGroup extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'min_select' => 0,
        'sort_order' => 0,
    ];

    protected $fillable = ['tenant_id', 'name', 'min_select', 'max_select', 'sort_order'];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_group');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    /** "Optional, pick any" / "Required, pick 1" / "Optional, up to 3" */
    public function ruleLabel(): string
    {
        $min = $this->min_select;
        $max = $this->max_select;

        $pick = match (true) {
            $max === null => $min > 0 ? "pick at least {$min}" : 'pick any',
            $min === $max => "pick {$max}",
            $min > 0 => "pick {$min}–{$max}",
            default => "up to {$max}",
        };

        return ($min > 0 ? 'Required' : 'Optional').', '.$pick;
    }
}
