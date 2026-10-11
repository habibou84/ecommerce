<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Lien court de partage : {slug}.jibiat-marchand.com/p/{code}.
 */
class ShortLink extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'code', 'target_type', 'target_id', 'share_image_path'];
}
