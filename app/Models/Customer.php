<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'orders_count' => 0,
        'total_spent' => 0,
    ];

    protected $fillable = ['tenant_id', 'name', 'phone', 'address', 'notes'];

    protected function casts(): array
    {
        return [
            'orders_count' => 'integer',
            'total_spent' => 'decimal:2',
            'last_order_at' => 'datetime',
        ];
    }

    /** "0300 1234567", "+92 300-1234567" and "03001234567" are the same number. */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }
}
