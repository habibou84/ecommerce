<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Une action de l'assistant IA (quota mensuel de l'offre, coût en jetons).
 */
class AiUsage extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'user_id', 'feature', 'model', 'input_tokens', 'output_tokens', 'succeeded'];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }
}
