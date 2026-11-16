<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Pub Facebook et Instagram créée par l'assistant Boost : un produit, plusieurs (carrousel) ou le catalogue. Les
 * commandes venues de la pub portent utm_content « b{id} ».
 */
class Boost extends Model
{
    use BelongsToStore;

    /** Statuts où la pub peut encore dépenser */
    public const LIVE = ['review', 'active', 'paused'];

    protected $fillable = [
        'store_id', 'kind', 'product_ids', 'collection_id', 'name', 'daily_budget', 'days', 'zones', 'age_min', 'age_max', 'gender',
        'message', 'status', 'ad_account_id', 'currency', 'page_id', 'campaign_id', 'adset_id', 'ad_id', 'product_set_id', 'error', 'spend',
        'clicks', 'impressions', 'orders', 'sales', 'billed_orders', 'credits_used', 'ends_at', 'synced_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'product_ids' => 'array', 'zones' => 'array', 'daily_budget' => 'integer', 'days' => 'integer', 'age_min' => 'integer',
            'age_max' => 'integer', 'spend' => 'integer', 'clicks' => 'integer', 'impressions' => 'integer', 'orders' => 'integer',
            'sales' => 'integer', 'billed_orders' => 'integer', 'credits_used' => 'integer', 'ends_at' => 'datetime', 'synced_at' => 'datetime',
        ];
    }

    /** Un boost « pas créé » ou terminé, sans dépense, commande ni crédit retiré, peut être supprimé sans rien perdre */
    public function isDeletable(): bool
    {
        return in_array($this->status, ['failed', 'ended'], true) && ! $this->spend && ! $this->orders && ! $this->credits_used;
    }

    /** Marque des liens de la pub, reprise dans la commande */
    public function tag(): string
    {
        return 'b'.$this->id;
    }
}
