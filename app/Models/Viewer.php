<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Viewer extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\ViewerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'google_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Staff-only helper so shared middleware does not fatal on Viewer tokens.
     */
    public function hasAnyRole(array $roles): bool
    {
        return false;
    }

    public function hasRole(string $role): bool
    {
        return false;
    }

    public function hasPermission(string $permission): bool
    {
        return false;
    }
}
