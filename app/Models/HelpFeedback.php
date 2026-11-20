<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Avis d'un marchand sur une fiche d'aide : utile ou non (un avis par personne et par fiche).
 */
class HelpFeedback extends Model
{
    protected $table = 'help_feedback';

    protected $fillable = ['help_article_id', 'user_id', 'helpful'];

    protected function casts(): array
    {
        return ['helpful' => 'boolean'];
    }
}
