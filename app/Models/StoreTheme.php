<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreTheme extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'theme_id', 'settings', 'draft_settings', 'published_at'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'draft_settings' => 'array', 'published_at' => 'datetime'];
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}
