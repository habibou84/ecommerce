<?php

namespace App\Services\Marketing;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Assistant;
use App\Services\Ai\CatalogContext;
use Illuminate\Support\Collection;

/**
 * Trois textes de pub Facebook et Instagram écrits par l'assistant IA (une action) pour un produit, une sélection
 * (carrousel) ou le catalogue, avec les vrais prix. Le marchand choisit et corrige avant de lancer le boost.
 */
class BoostTextWriter
{
    private const SYSTEM = <<<'TXT'
    Tu écris des textes de publicité Facebook et Instagram pour une boutique de Côte d'Ivoire qui vend en ligne et livre.
    Donne trois propositions différentes : une courte et percutante, une qui donne envie (bénéfice, usage, occasion), une orientée urgence ou promotion (stock limité seulement si l'information le dit).
    Règles :
    - Utilise uniquement les informations fournies : noms, prix, promotions, tailles. N'invente ni prix, ni taille, ni délai, ni promotion.
    - 2 à 4 lignes, quelques emojis, un appel clair à commander. N'écris pas de lien ni de hashtag : le bouton « Acheter » de la pub mène à la boutique.
    - Pour plusieurs produits ou le catalogue, parle de la sélection sans tout énumérer.
    - Français simple et chaleureux, tutoiement.
    TXT;

    public function __construct(private readonly Assistant $assistant, private readonly CatalogContext $catalog) {}

    /**
     * @param  Collection<int, Product>  $products
     * @return list<string>
     */
    public function propose(Store $store, User $user, string $kind, Collection $products, ?string $collection = null): array
    {
        $what = match ($kind) {
            'product' => 'un seul produit',
            'products' => 'une sélection de '.$products->count().' produits (carrousel)',
            default => 'le catalogue de la boutique'.($collection ? ' (collection « '.$collection.' »)' : ''),
        };
        $content = [['type' => 'text', 'text' => implode("\n\n", array_filter([
            "Boutique : {$store->name}",
            "La pub présente {$what}.",
            "Produits :\n".$this->catalog->describe($store, $products->each->loadMissing('variants')),
            $kind === 'product' && $products->first()?->description ? 'Description : '.mb_substr(strip_tags((string) $products->first()->description), 0, 1500) : null,
        ]))]];

        $result = $this->assistant->run($store, $user, 'boost_text', self::SYSTEM, $content, [
            'type' => 'object',
            'properties' => ['texts' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'required' => ['texts'],
        ], 1500);

        return array_values(array_filter(array_map(fn ($t) => trim((string) $t), array_slice($result['texts'] ?? [], 0, 3))));
    }
}
