<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Client de la boutique, identifié par son numéro de téléphone.
 */
class Customer extends Model
{
    use BelongsToStore, SoftDeletes;

    protected $fillable = ['store_id', 'name', 'phone', 'phone2', 'email', 'source', 'accepts_marketing', 'note'];

    protected function casts(): array
    {
        return [
            'accepts_marketing' => 'boolean',
            'marketing_opt_out_at' => 'datetime',
            'last_campaign_at' => 'datetime',
            'verified_at' => 'datetime',
            'blacklisted_at' => 'datetime',
            'last_order_at' => 'datetime',
            'total_spent' => 'integer',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->latest('updated_at');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest('id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function isBlacklisted(): bool
    {
        return $this->blacklisted_at !== null;
    }

    /**
     * Part des commandes abouties parmi celles qui ont un résultat (livrées ou refusées).
     */
    public function deliveryRate(): ?int
    {
        $decided = $this->delivered_count + $this->refused_count;

        return $decided === 0 ? null : (int) round($this->delivered_count * 100 / $decided);
    }
}
