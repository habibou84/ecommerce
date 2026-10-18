<?php

namespace App\Services\Social;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Assistant;
use App\Services\Ai\CatalogContext;

/**
 * Trois textes de publication proposés par l'assistant IA pour un produit (Facebook, Instagram, TikTok), avec le
 * vrai prix. Le marchand choisit, corrige, puis publie : rien ne part sans lui.
 */
class CaptionWriter
{
    private const SYSTEM = <<<'TXT'
    Tu écris des publications de réseaux sociaux (Facebook, Instagram, TikTok) pour une boutique de Côte d'Ivoire qui vend en ligne et livre.
    Donne trois propositions différentes : une courte et percutante, une qui raconte (bénéfice, usage, occasion), une orientée promotion ou urgence (stock limité seulement si l'information le dit).
    Règles :
    - Utilise uniquement les informations fournies : nom, prix, promotion, variantes. N'invente ni prix, ni taille, ni délai, ni promotion.
    - 2 à 5 lignes, des emojis avec mesure, un appel clair à commander. Écris {lien} là où doit aller le lien de commande (il sera remplacé).
    - Ton « simple » : français courant et chaleureux. Ton « nouchi » : français ivoirien familier et léger, compréhensible par tous, jamais vulgaire.
    - hashtags : 3 à 6 mots-clés utiles en Côte d'Ivoire (ex. #Abidjan, la catégorie), sans espace, avec le #.
    TXT;

    public function __construct(private readonly Assistant $assistant, private readonly CatalogContext $catalog) {}

    /**
     * @return list<array{text: string, hashtags: list<string>}>
     */
    public function propose(Store $store, Product $product, User $user, string $tone = 'simple'): array
    {
        $content = [['type' => 'text', 'text' => implode("\n\n", array_filter([
            "Boutique : {$store->name}",
            "Produit :\n".$this->catalog->describe($store, collect([$product->loadMissing('variants')])),
            $product->description ? 'Description : '.mb_substr(strip_tags((string) $product->description), 0, 1500) : null,
            'Ton demandé : '.($tone === 'nouchi' ? 'nouchi' : 'simple'),
        ]))]];

        $result = $this->assistant->run($store, $user, 'social_caption', self::SYSTEM, $content, [
            'type' => 'object',
            'properties' => ['captions' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string'], 'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']]],
                'required' => ['text', 'hashtags'],
            ]]],
            'required' => ['captions'],
        ], 2000);

        return array_values(array_map(fn ($c) => [
            'text' => trim((string) ($c['text'] ?? '')),
            'hashtags' => array_values(array_filter(array_map(fn ($h) => '#'.ltrim(preg_replace('/\s+/', '', (string) $h), '#'), $c['hashtags'] ?? []), fn ($h) => $h !== '#')),
        ], array_slice($result['captions'] ?? [], 0, 3)));
    }
}
