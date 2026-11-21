<?php

namespace App\Tenancy;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current shop and stamps tenant_id on create.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            if (! empty($model->tenant_id)) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();

            if (! $tenantId) {
                throw MissingTenantException::forModel($model::class);
            }

            $model->tenant_id = $tenantId;
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id') && $model->getOriginal('tenant_id') !== null) {
                throw MissingTenantException::tenantChange($model::class);
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'tenant_id');
    }

    /**
     * Query across all shops. Only for Super Admin screens and system jobs.
     */
    public function scopeAcrossShops(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
