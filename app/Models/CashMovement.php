<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement d'argent hors ventes : dépense (achat de stock, transport…), retrait (dépôt en banque, prélèvement)
 * ou apport (ajout de monnaie). Seuls ceux en espèces changent le tiroir-caisse.
 */
class CashMovement extends Model
{
    use BelongsToStore;

    public const TYPES = ['expense', 'withdrawal', 'deposit'];

    public const CATEGORIES = [
        'stock' => 'Achat de marchandises',
        'transport' => 'Transport',
        'packaging' => 'Emballages',
        'salary' => 'Salaires',
        'rent' => 'Loyer',
        'utilities' => 'Électricité, eau, internet',
        'marketing' => 'Publicité',
        'other' => 'Autre',
    ];

    /** Canal de vente visé par une dépense de publicité (valeurs des canaux de commande) */
    public const CHANNELS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'whatsapp' => 'WhatsApp',
        'online_store' => 'Site (Google…)',
    ];

    protected $fillable = ['store_id', 'cash_session_id', 'recurring_expense_id', 'type', 'category', 'channel', 'amount', 'method', 'note', 'occurred_at', 'created_by'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'amount' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
