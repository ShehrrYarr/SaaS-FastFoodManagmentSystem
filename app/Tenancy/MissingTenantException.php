<?php

namespace App\Tenancy;

use RuntimeException;

class MissingTenantException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("Cannot create [{$model}] without a current shop. Wrap the call in TenantContext::run().");
    }

    public static function tenantChange(string $model): self
    {
        return new self("A [{$model}] cannot be moved to another shop.");
    }
}
