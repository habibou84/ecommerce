<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Recherche du centre d'aide restée sans réponse : la liste des fiches à écrire.
 */
class HelpMiss extends Model
{
    public $timestamps = false;

    protected $fillable = ['query', 'hits', 'last_at'];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'last_at' => 'datetime'];
    }
}
