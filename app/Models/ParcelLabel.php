<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étiquette pré-imprimée : code unique (8 caractères sans 0/O ni 1/I/L), QR vers la page publique du colis
 * ({boutique}/e/{code}), reliée à une commande en la scannant.
 */
class ParcelLabel extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'batch_id', 'code', 'order_id', 'assigned_at', 'assigned_by'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ParcelLabelBatch::class, 'batch_id');
    }

    /** Code tel qu'imprimé : JM-7K4Q-29XB */
    public function display(): string
    {
        return 'JM-'.substr($this->code, 0, 4).'-'.substr($this->code, 4);
    }

    public function url(): string
    {
        return $this->store->url('/e/'.$this->code);
    }
}
