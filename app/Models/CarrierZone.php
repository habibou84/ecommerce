<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Zone d'un transporteur, copiée de son API (GET /zones).
 */
class CarrierZone extends Model
{
    public $timestamps = false;

    protected $fillable = ['connection_id', 'remote_id', 'name', 'parent_name', 'is_shipping', 'is_active', 'synced_at'];

    protected function casts(): array
    {
        return ['is_shipping' => 'boolean', 'is_active' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function label(): string
    {
        return $this->parent_name ? "{$this->name} ({$this->parent_name})" : $this->name;
    }
}
