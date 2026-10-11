<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['code', 'name', 'monthly_price', 'yearly_price', 'limits', 'features', 'is_public', 'sort'];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'features' => 'array',
            'is_public' => 'boolean',
        ];
    }

    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? null;
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? false);
    }
}
