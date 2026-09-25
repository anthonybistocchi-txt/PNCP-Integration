<?php

namespace App\Models\Auth;

use App\Models\UserKeywords\UserKeyword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'company_name',
        'cpf',
        'cnpj',
        'password',
        'api_key',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_key',
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

    public function keywords(): HasMany
    {
        return $this->hasMany(UserKeyword::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
