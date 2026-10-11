<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'handle', 'items'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
