<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * WhatsApp de la boutique relié par QR code (« Appareils connectés », comme WhatsApp Web) : les messages des clients
 * arrivent dans la boîte Messages et l'équipe y répond, sans la limite de 24 h de l'API officielle. Le téléphone
 * garde la conversation : ce qui est écrit d'un côté apparaît de l'autre.
 */
class WhatsappLink extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'status', 'phone', 'name', 'connected_at', 'last_error'];

    protected function casts(): array
    {
        return ['connected_at' => 'datetime'];
    }

    /** Nom de la session dans le service whatsapp-bridge */
    public function session(): string
    {
        return 'store-'.$this->store_id;
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public static function connectedFor(int $storeId): ?self
    {
        return self::forStore($storeId)->where('status', 'connected')->first();
    }
}
