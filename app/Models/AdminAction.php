<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal des actions des administrateurs de la plateforme (console).
 */
class AdminAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'store_id', 'action', 'data', 'ip'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
