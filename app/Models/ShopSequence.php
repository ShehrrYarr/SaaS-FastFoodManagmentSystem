<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free counters per shop: order numbers, purchase vouchers, SKUs,
 * and the daily token (scope = business date).
 */
class ShopSequence extends Model
{
    protected $fillable = ['tenant_id', 'key', 'scope', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }

    /**
     * Reserve the next number. Safe under concurrency: the row is locked
     * until the surrounding transaction commits, so two tills never get
     * the same number.
     */
    public static function next(int $tenantId, string $key, string $scope = '', int $startAt = 1): int
    {
        return DB::transaction(function () use ($tenantId, $key, $scope, $startAt) {
            $find = fn () => static::query()
                ->where(['tenant_id' => $tenantId, 'key' => $key, 'scope' => $scope])
                ->lockForUpdate()
                ->first();

            // Lock the existing counter straight away. (INSERT IGNORE on an existing row takes
            // a shared lock first, and two tills upgrading shared locks at once deadlock.)
            $row = $find();

            if (! $row) {
                $now = now();

                static::query()->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'key' => $key,
                    'scope' => $scope,
                    'next_value' => $startAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $row = $find() ?? throw new \RuntimeException("Could not reserve sequence [{$key}].");
            }

            $value = $row->next_value;

            $row->forceFill(['next_value' => $value + 1])->save();

            return $value;
        });
    }

    /** "BLG" + 123 -> "BLG-000123" */
    public static function format(string $prefix, int $number, string $segment = '', int $pad = 6): string
    {
        return implode('-', array_filter([$prefix, $segment])).'-'.str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    }
}
