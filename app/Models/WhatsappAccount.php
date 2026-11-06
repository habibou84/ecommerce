<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Numéro WhatsApp Business du marchand (API Cloud de Meta) : messages automatiques aux clients.
 */
class WhatsappAccount extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'connection', 'two_step_pin', 'template_status', 'phone_number_id', 'waba_id', 'display_phone', 'verified_name', 'name_status', 'access_token', 'app_secret', 'verify_token', 'webhook_uuid', 'templates', 'automations', 'status', 'last_error', 'meta_health', 'meta_health_at', 'meta_state', 'meta_state_since', 'payment_missing_at'];

    protected $hidden = ['access_token', 'app_secret', 'two_step_pin'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'app_secret' => 'encrypted', 'two_step_pin' => 'encrypted', 'templates' => 'array', 'automations' => 'array', 'template_status' => 'array', 'meta_health' => 'array', 'meta_health_at' => 'datetime', 'meta_state_since' => 'datetime', 'payment_missing_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->webhook_uuid ??= (string) Str::uuid();
            $a->verify_token ??= Str::random(32);
        });
    }

    public function isEmbedded(): bool
    {
        // « connection » est aussi le nom de la connexion à la base dans Eloquent : lire la colonne
        return $this->getAttribute('connection') === 'embedded';
    }

    /**
     * Mode sans carte : Meta a refusé un modèle faute de moyen de paiement. Les modèles (payants) ne sont plus
     * tentés ; un seul essai toutes les 12 h pour voir si une carte a été ajoutée entre-temps.
     */
    public function withoutCard(): bool
    {
        return $this->payment_missing_at !== null;
    }

    public function skipsTemplates(): bool
    {
        return $this->payment_missing_at !== null && $this->payment_missing_at->gt(now()->subHours(12));
    }

    public function automates(string $event): bool
    {
        return $this->status === 'active' && ($this->automations[$event] ?? false);
    }
}
