<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Shop extends Model
{
    /** Route pattern for {shop}. Upper case is accepted and redirected to the lower-case address. */
    public const PREFIX_PATTERN = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,18}[A-Za-z0-9])?';

    public const PREFIX_RULE = '/^[a-z0-9](?:[a-z0-9-]{0,18}[a-z0-9])?$/';

    public const ORDER_PREFIX_RULE = '/^[A-Z0-9]{2,10}$/';

    /** Shown as "expiring" this many days before the subscription ends. */
    public const EXPIRY_WARNING_DAYS = 7;

    public const DEFAULT_TIMEZONE = 'Asia/Karachi';

    /** First URL segments the app itself uses. */
    public const RESERVED_PREFIXES = [
        'super-admin', 'admin', 'login', 'logout', 'register', 'password', 'api', 'up', 'health',
        'livewire', 'assets', 'build', 'storage', 'vendor', 'css', 'js', 'images', 'fonts',
        'favicon', 'robots', 'sitemap', '_herozi', 'www', 'app', 'static', 'public', 'docs',
        'help', 'support', 'status', 'billing', 'pos', 'dashboard', 'settings', 'shop', 'shops',
        'broadcasting', 'reverb', 'apps', 'find', 'demo',
    ];

    /** Mirrors the column defaults so freshly created models behave the same as loaded ones. */
    protected $attributes = [
        'currency' => 'PKR',
        'currency_symbol' => 'Rs',
        'timezone' => self::DEFAULT_TIMEZONE,
        'is_active' => true,
        'inventory_tracking_enabled' => false,
        'allow_negative_stock' => true,
        'service_charge_type' => 'percent',
        'service_charge_value' => 0,
        'default_delivery_charge' => 0,
    ];

    protected $fillable = [
        'name', 'prefix', 'order_prefix', 'phone', 'email', 'address', 'logo_path',
        'currency', 'currency_symbol', 'timezone',
        'is_active', 'suspended_reason', 'suspended_at', 'subscription_ends_at',
        'inventory_tracking_enabled', 'allow_negative_stock',
        'service_charge_type', 'service_charge_value', 'default_delivery_charge',
        'receipt_header', 'receipt_footer', 'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
            'subscription_ends_at' => 'date',
            'inventory_tracking_enabled' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'service_charge_value' => 'decimal:2',
            'default_delivery_charge' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Shop $shop) {
            $shop->prefix = Str::lower(trim($shop->prefix));
            $shop->order_prefix = Str::upper(trim($shop->order_prefix));
        });
    }

    // ---------------------------------------------------------------- relations

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'tenant_id')->withoutGlobalScope(TenantScope::class);
    }

    /** The first user holding the locked Owner role. */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class, 'tenant_id')
            ->whereHas('role', fn (Builder $q) => $q->withoutGlobalScope(TenantScope::class)->where('is_locked', true))
            ->oldest('id');
    }

    // ---------------------------------------------------------------- prefixes

    public static function isReservedPrefix(string $prefix): bool
    {
        $prefix = Str::lower($prefix);

        return in_array($prefix, self::RESERVED_PREFIXES, true) || str_starts_with($prefix, 'livewire');
    }

    /** "Burger Lab Gulberg" -> "blg" */
    public static function suggestPrefix(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY);
        $initials = implode('', array_map(fn ($w) => $w[0], $words));

        $prefix = strlen($initials) >= 2 ? $initials : Str::slug($name);

        return trim(substr($prefix, 0, 20), '-');
    }

    /** "burger-lab" -> "BURGERLAB" (max 10 characters) */
    public static function suggestOrderPrefix(string $prefix): string
    {
        return substr(Str::upper(preg_replace('/[^a-z0-9]/i', '', $prefix)), 0, 10);
    }

    public function loginUrl(): string
    {
        return route('shop.login', ['shop' => $this->prefix]);
    }

    // ---------------------------------------------------------------- subscription & status

    public function today(): Carbon
    {
        return Carbon::now($this->timezone ?: self::DEFAULT_TIMEZONE)->startOfDay();
    }

    public function daysUntilExpiry(): ?int
    {
        if (! $this->subscription_ends_at) {
            return null;
        }

        $end = Carbon::parse($this->subscription_ends_at->toDateString(), $this->timezone ?: self::DEFAULT_TIMEZONE);

        return (int) $this->today()->diffInDays($end, false);
    }

    public function isSubscriptionExpired(): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days < 0;
    }

    public function isAccessible(): bool
    {
        return $this->is_active && ! $this->isSubscriptionExpired();
    }

    public function blockedReason(): ?string
    {
        if (! $this->is_active) {
            return 'This shop has been suspended'.($this->suspended_reason ? ': '.$this->suspended_reason : '.')
                .' Please contact support.';
        }

        if ($this->isSubscriptionExpired()) {
            return 'This shop\'s subscription ended on '.$this->subscription_ends_at->format('d M Y').'. Please contact support to renew.';
        }

        return null;
    }

    /** active | expiring | expired | suspended */
    public function status(): string
    {
        if (! $this->is_active) {
            return 'suspended';
        }

        if ($this->isSubscriptionExpired()) {
            return 'expired';
        }

        $days = $this->daysUntilExpiry();

        return $days !== null && $days <= self::EXPIRY_WARNING_DAYS ? 'expiring' : 'active';
    }

    /** @return array{label: string, class: string} */
    public function statusBadge(): array
    {
        return match ($this->status()) {
            'suspended' => ['label' => 'Suspended', 'class' => 'bg-danger-subtle text-danger'],
            'expired' => ['label' => 'Expired', 'class' => 'bg-secondary-subtle text-secondary'],
            'expiring' => ['label' => 'Expiring', 'class' => 'bg-warning-subtle text-warning'],
            default => ['label' => 'Active', 'class' => 'bg-success-subtle text-success'],
        };
    }

    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        $today = Carbon::now(self::DEFAULT_TIMEZONE)->toDateString();
        $warnUntil = Carbon::now(self::DEFAULT_TIMEZONE)->addDays(self::EXPIRY_WARNING_DAYS)->toDateString();

        return match ($status) {
            'suspended' => $query->where('is_active', false),
            'expired' => $query->where('is_active', true)->whereDate('subscription_ends_at', '<', $today),
            'expiring' => $query->where('is_active', true)->whereBetween('subscription_ends_at', [$today, $warnUntil]),
            'active' => $query->where('is_active', true)->where(fn (Builder $q) => $q
                ->whereNull('subscription_ends_at')
                ->orWhereDate('subscription_ends_at', '>=', $today)),
            default => $query,
        };
    }

    // ---------------------------------------------------------------- formatting

    public function money(float|int|string|null $amount): string
    {
        $amount = (float) $amount;
        $decimals = floor($amount) == $amount ? 0 : 2;

        return trim($this->currency_symbol.' '.number_format($amount, $decimals));
    }

    public function initials(): string
    {
        return Str::upper(substr($this->order_prefix ?: $this->prefix, 0, 3));
    }
}
