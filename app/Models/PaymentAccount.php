<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Compte du marchand chez un prestataire de paiement (Wave, CinetPay, PayDunya). Identifiants chiffrés ;
 * l'argent va directement du client au marchand.
 */
class PaymentAccount extends Model
{
    use BelongsToStore;

    public const PROVIDERS = ['wave' => 'Wave', 'cinetpay' => 'CinetPay', 'paydunya' => 'PayDunya'];

    protected $fillable = ['store_id', 'provider', 'label', 'credentials', 'mode', 'webhook_uuid', 'fee_percent', 'fee_fixed', 'status', 'last_error'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'fee_percent' => 'float', 'fee_fixed' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->webhook_uuid ??= (string) Str::uuid());
    }

    public function routes(): HasMany
    {
        return $this->hasMany(PaymentRoute::class);
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function name(): string
    {
        return $this->label ?: self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    /**
     * Frais estimés d'un paiement d'après les taux saisis par le marchand.
     */
    public function estimatedFee(int $amount): int
    {
        return (int) round($amount * ($this->fee_percent ?? 0) / 100) + $this->fee_fixed;
    }
}
