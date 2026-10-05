<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = ['team_id', 'order_id', 'number', 'amount_cents', 'due_at', 'paid_at'];

    protected function casts(): array { return ['due_at' => 'datetime', 'paid_at' => 'datetime', 'amount_cents' => 'integer']; }
    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
