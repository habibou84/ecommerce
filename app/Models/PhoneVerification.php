<?php

namespace App\Models;

use App\Enums\VerificationPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vérification d'un numéro par code SMS (création de boutique, invitation, mot de passe oublié).
 * Le navigateur garde un jeton secret ; la base n'en garde que l'empreinte.
 */
class PhoneVerification extends Model
{
    protected $fillable = [
        'purpose', 'phone', 'store_id', 'user_id', 'token_hash', 'sms_code_hash', 'sms_count', 'sms_sent_at',
        'attempts', 'payload', 'ip', 'verified_at', 'completed_at', 'expires_at',
    ];

    protected $hidden = ['token_hash', 'sms_code_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'purpose' => VerificationPurpose::class,
            'payload' => 'encrypted:array',
            'sms_count' => 'integer',
            'attempts' => 'integer',
            'sms_sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('completed_at')->where('expires_at', '>', now());
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
