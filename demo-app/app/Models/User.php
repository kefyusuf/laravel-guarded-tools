<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'current_team_id', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Team && $this->current_team_id !== null
            && (string) $this->current_team_id === (string) $tenant->getKey();
    }

    public function hasOrderDeskAbility(string $ability): bool
    {
        $abilities = [
            'owner' => ['orders.read', 'invoices.read', 'customers.read'],
            'sales' => ['orders.read', 'customers.read'],
            'viewer' => ['orders.read'],
        ];

        return in_array($ability, $abilities[$this->role] ?? [], true);
    }

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
            'current_team_id' => 'integer',
        ];
    }
}
