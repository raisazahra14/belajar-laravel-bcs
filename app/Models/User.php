<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Peta kemampuan token Sanctum (Token Abilities) standar per role untuk /api/v1.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_API_ABILITIES = [
        'admin' => [
            'barang:read',
            'barang:write',
            'stok:check',
            'stok:adjust',
            'stok:transfer',
            'stok:reverse',
            'po:read',
            'po:create',
            'po:update',
            'po:submit',
            'po:approve',
            'po:receive',
        ],
        'manager' => [
            'barang:read',
            'stok:check',
            'stok:adjust',
            'stok:transfer',
            'stok:reverse',
            'po:read',
            'po:create',
            'po:update',
            'po:submit',
            'po:approve',
        ],
        'staff' => [
            'barang:read',
            'stok:check',
            'stok:adjust',
            'stok:transfer',
            'po:read',
            'po:create',
            'po:update',
            'po:submit',
            'po:receive',
        ],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function documentVerifications(): HasMany
    {
        return $this->hasMany(DocumentVerification::class);
    }

    public function createdPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'created_by');
    }

    public function approvedPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'approved_by');
    }

    /**
     * Ambil daftar Sanctum token abilities standar berdasarkan nama role.
     *
     * @return list<string>
     */
    public static function apiAbilitiesForRole(?string $role): array
    {
        return self::ROLE_API_ABILITIES[$role ?? ''] ?? ['barang:read', 'stok:check'];
    }

    /**
     * Ambil daftar Sanctum token abilities default untuk user ini sesuai role-nya.
     *
     * @return list<string>
     */
    public function defaultApiAbilities(): array
    {
        return self::apiAbilitiesForRole($this->role);
    }

    /**
     * Saring daftar ability yang diminta klien terhadap ability maksimum role user ini.
     *
     * @param  list<string>|null  $requestedAbilities
     * @return list<string>
     */
    public function resolveApiAbilities(?array $requestedAbilities = null): array
    {
        $allowed = $this->defaultApiAbilities();

        if ($requestedAbilities === null || $requestedAbilities === []) {
            return $allowed;
        }

        $normalized = array_unique(array_map('strval', $requestedAbilities));

        return array_values(array_intersect($allowed, $normalized));
    }
}
