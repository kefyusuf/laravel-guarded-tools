<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['team_id', 'name', 'city'];

    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
}
