<?php

namespace App\Models;

use App\Support\Permissions;
use App\Tenancy\TenantScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * Users are not tenant-scoped globally: the auth guards must be able to load
 * any user by id. Shop screens always filter explicitly by tenant_id, and
 * EnsureUserBelongsToShop rejects users from another shop.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'is_super_admin' => false,
        'is_active' => true,
    ];

    protected $fillable = [
        'tenant_id', 'role_id', 'name', 'email', 'username', 'phone', 'password',
        'is_super_admin', 'is_active', 'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $user->email = $user->email ? Str::lower(trim($user->email)) : null;
            $user->username = $user->username ? Str::lower(trim($user->username)) : null;
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'tenant_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScope(TenantScope::class);
    }

    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->where('is_super_admin', true)->whereNull('tenant_id');
    }

    public function scopeOfShop(Builder $query, Shop|int $shop): Builder
    {
        return $query->where('tenant_id', $shop instanceof Shop ? $shop->getKey() : $shop);
    }

    /** Matches a login string against email or username. */
    public function scopeWhereLogin(Builder $query, string $login): Builder
    {
        $login = Str::lower(trim($login));

        return $query->where(fn (Builder $q) => $q->where('email', $login)->orWhere('username', $login));
    }

    public function isOwner(): bool
    {
        return (bool) $this->role?->is_locked && (int) $this->role->tenant_id === (int) $this->tenant_id;
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->tenant_id || ! $this->is_active) {
            return false;
        }

        $role = $this->role;

        if (! $role || (int) $role->tenant_id !== (int) $this->tenant_id) {
            return false;
        }

        return $role->is_locked || in_array($permission, $role->permissions ?? [], true);
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $initials = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)));

        return Str::upper($initials ?: '?');
    }

    public function loginName(): string
    {
        return $this->email ?: (string) $this->username;
    }

    public static function permissionExists(string $permission): bool
    {
        return Permissions::exists($permission);
    }
}
