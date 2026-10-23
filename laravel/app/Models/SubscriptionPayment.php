<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['phone', 'callback_token_hash'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'metadata' => 'array', 'amount_minor' => 'integer', 'phone' => 'encrypted'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
