<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = ['team_id', 'customer_id', 'number', 'status', 'amount_cents', 'placed_at'];

    protected function casts(): array { return ['placed_at' => 'datetime', 'amount_cents' => 'integer']; }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
}
