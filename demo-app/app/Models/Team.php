<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = ['name', 'slug'];

    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function users(): HasMany { return $this->hasMany(User::class, 'current_team_id'); }
}
