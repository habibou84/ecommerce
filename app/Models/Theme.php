<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Theme extends Model
{
    protected $fillable = ['code', 'name', 'version', 'settings_schema', 'preview_path', 'is_premium', 'is_active'];

    protected function casts(): array
    {
        return ['settings_schema' => 'array', 'is_premium' => 'boolean', 'is_active' => 'boolean'];
    }
}
