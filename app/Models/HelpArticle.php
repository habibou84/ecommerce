<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Fiche du centre d'aide (Console › Centre d'aide) : une question, sa réponse courte, les étapes, une vidéo, des
 * images et l'écran à ouvrir. Lue dans l'application (Aide) ; c'est aussi la matière des réponses du support.
 */
class HelpArticle extends Model
{
    public const CATEGORIES = [
        'demarrer' => 'Bien démarrer',
        'produits' => 'Produits et stock',
        'commandes' => 'Commandes',
        'livraisons' => 'Livraisons',
        'whatsapp' => 'WhatsApp et messages',
        'boutique' => 'Boutique en ligne',
        'live' => 'Vendre en live',
        'argent' => 'Caisse et paiements',
        'compte' => 'Compte et équipe',
    ];

    public const MAX_IMAGES = 6;

    protected $fillable = [
        'slug', 'category', 'title', 'summary', 'steps', 'body', 'keywords', 'video_url', 'poster_url', 'video_seconds',
        'screen', 'screen_label', 'sort', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'images' => 'array',
            'video_seconds' => 'integer',
            'sort' => 'integer',
            'is_published' => 'boolean',
            'views' => 'integer',
        ];
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(HelpFeedback::class);
    }

    public static function disk(): string
    {
        return config('filesystems.catalog_disk', 'public');
    }

    /** Vidéo envoyée dans la console, sinon l'adresse indiquée (fichier du site ou lien) */
    public function videoUrl(): ?string
    {
        return $this->video_path ? Storage::disk(self::disk())->url($this->video_path) : $this->video_url;
    }

    /**
     * @return list<string>
     */
    public function imageUrls(): array
    {
        return array_map(fn (string $path) => Storage::disk(self::disk())->url($path), $this->images ?? []);
    }
}
