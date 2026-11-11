<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lot d'étiquettes pré-imprimées (un PDF à imprimer) */
class ParcelLabelBatch extends Model
{
    use BelongsToStore;

    /** Formats : mini 40 × 30 en rouleau, mini en planche A4 (45 par feuille), grande 10 × 15 en rouleau */
    public const FORMATS = ['mini' => 'Mini 40 × 30 · rouleau', 'sheet' => 'Mini 40 × 30 · planche A4', 'large' => 'Grande 10 × 15 · rouleau'];

    public const QUANTITIES = [50, 100, 200, 500];

    protected $fillable = ['store_id', 'format', 'quantity', 'created_by'];

    public function labels(): HasMany
    {
        return $this->hasMany(ParcelLabel::class, 'batch_id');
    }
}
