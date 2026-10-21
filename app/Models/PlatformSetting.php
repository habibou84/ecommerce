<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglage de la plateforme (console du super administrateur) : une clé, une valeur texte.
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /**
     * Valeurs des clés qui commencent par ce préfixe, sans le préfixe.
     *
     * @return array<string, string|null>
     */
    public static function group(string $prefix): array
    {
        return static::where('key', 'like', $prefix.'.%')->pluck('value', 'key')
            ->mapWithKeys(fn ($value, $key) => [substr($key, strlen($prefix) + 1) => $value])->all();
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
