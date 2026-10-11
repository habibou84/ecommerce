<?php

namespace App\Rules;

use App\Models\SlugHistory;
use App\Models\Store;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Adresse d'une boutique ({slug}.jibiat-marchand.com) : 3 à 40 caractères, lettres minuscules, chiffres et
 * tirets, libre, non réservée et non utilisée comme ancienne adresse redirigée.
 */
class StoreSlug implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreStoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/', $value) || str_contains($value, '--')) {
            $fail('L\'adresse doit faire 3 à 40 caractères : lettres minuscules sans accent, chiffres et tirets (pas au début ni à la fin).');

            return;
        }

        if (in_array($value, config('platform.reserved_slugs'), true)) {
            $fail('Cette adresse est réservée.');

            return;
        }

        $taken = Store::withTrashed()->where('slug', $value)->when($this->ignoreStoreId, fn ($q) => $q->whereKeyNot($this->ignoreStoreId))->exists()
            || SlugHistory::where('old_slug', $value)->where('expires_at', '>', now())
                ->when($this->ignoreStoreId, fn ($q) => $q->where('store_id', '!=', $this->ignoreStoreId))->exists();

        if ($taken) {
            $fail('Cette adresse est déjà prise.');
        }
    }
}
