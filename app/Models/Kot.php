<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kitchen order ticket. "new" lists the items sent; "void" lists the items taken back. */
class Kot extends Model
{
    use BelongsToTenant;

    /** Low or negative stock left after this ticket (not stored), for the POS to warn about. @var list<string> */
    public array $stockWarnings = [];

    protected $attributes = [
        'type' => 'new',
        'printed_count' => 0,
    ];

    protected $fillable = ['tenant_id', 'order_id', 'business_day_id', 'number', 'type', 'printed_count', 'created_by'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'printed_count' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function voids(): HasMany
    {
        return $this->hasMany(OrderItemVoid::class)->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoid(): bool
    {
        return $this->type === 'void';
    }

    public function label(): string
    {
        return ($this->isVoid() ? 'VOID KOT #' : 'KOT #').$this->number;
    }
}
