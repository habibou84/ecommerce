<?php

namespace App\Models;

use App\Enums\ConfirmationOutcome;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderConfirmation extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'order_id', 'channel', 'outcome', 'note', 'callback_at', 'user_id'];

    protected function casts(): array
    {
        return ['outcome' => ConfirmationOutcome::class, 'callback_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
