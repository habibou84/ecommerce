<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Assistant;
use App\Services\Stock\StockLevels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Question sur les chiffres de la boutique, en langage courant (« combien de robes cette semaine ? »).
 * L'assistant n'a que des outils en lecture sur la boutique courante : le rapport des ventes d'une période
 * (celui de l'écran Statistiques) et l'état du stock. Il ne devine aucun chiffre.
 */
class StatsQuestion
{
    private const LIST_LIMIT = 25;

    public function __construct(
        private readonly Assistant $assistant,
        private readonly StoreStats $stats,
        private readonly StockLevels $levels,
    ) {}

    public function answer(Store $store, User $user, string $question): string
    {
        $today = CarbonImmutable::now($store->timezone);
        $system = <<<TXT
        Tu réponds aux questions du commerçant sur les chiffres de sa boutique « {$store->name} ».
        Aujourd'hui : {$today->locale('fr')->isoFormat('dddd D MMMM YYYY')} ({$today->toDateString()}). Les semaines vont du lundi au dimanche.
        Utilise les outils pour obtenir les vrais chiffres ; n'invente et n'estime jamais un chiffre. Si les données ne permettent pas de répondre, dis-le simplement et indique ce que tu peux dire à la place.
        Réponds en français simple, en 1 à 4 phrases, sans tableau ni markdown ; montants en francs CFA avec des espaces (15 000 F) ; pourcentages arrondis.
        « Ventes » et « chiffre d'affaires » : commandes livrées, sauf si la question vise les commandes reçues.
        Si la question ne porte pas sur la boutique, dis gentiment que tu ne réponds qu'aux questions sur ses ventes, ses commandes, ses clients, ses livraisons et son stock.
        TXT;

        $tools = [
            [
                'name' => 'rapport_ventes',
                'description' => 'Statistiques de la boutique sur une période (dates incluses, fuseau de la boutique) : commandes reçues, confirmées, livrées, annulées, retournées, chiffre d\'affaires livré, panier moyen, taux de confirmation et de livraison, bénéfice estimé ; détail par canal de vente, par produit (quantités et montants), par zone de livraison, par livreur, par entreprise de livraison et par promotion. Montants en francs CFA.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'du' => ['type' => 'string', 'description' => 'Premier jour, AAAA-MM-JJ'],
                        'au' => ['type' => 'string', 'description' => 'Dernier jour, AAAA-MM-JJ'],
                    ],
                    'required' => ['du', 'au'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'etat_stock',
                'description' => 'État du stock aujourd\'hui : nombre d\'articles en stock bas et épuisés, valeur du stock, et la liste des articles concernés avec la quantité disponible.',
                // Au moins un paramètre obligatoire : le SDK PHP enverrait un objet vide comme une liste ([])
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['limite' => ['type' => 'integer', 'description' => 'Nombre d\'articles à lister (1 à 25)']],
                    'required' => ['limite'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        return $this->assistant->ask($store, $user, 'stats_question', $system, trim($question), $tools, fn (string $name, array $input) => match ($name) {
            'rapport_ventes' => $this->report($store, (string) ($input['du'] ?? ''), (string) ($input['au'] ?? '')),
            'etat_stock' => $this->stock($store, (int) ($input['limite'] ?? self::LIST_LIMIT)),
            default => throw new BusinessRuleException("Outil inconnu : {$name}"),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Store $store, string $from, string $to): array
    {
        try {
            $start = CarbonImmutable::parse($from, $store->timezone)->startOfDay();
            $end = CarbonImmutable::parse($to, $store->timezone)->endOfDay();
        } catch (Throwable) {
            throw new BusinessRuleException('Dates invalides : utilisez AAAA-MM-JJ.');
        }
        if ($end->lt($start) || $start->diffInDays($end) > 366) {
            throw new BusinessRuleException('Période invalide (un an au plus).');
        }

        $report = $this->stats->report($store, $start->utc(), $end->utc());

        // Listes raccourcies : l'essentiel suffit pour répondre
        return collect($report)->map(fn ($part) => is_array($part) && array_is_list($part) ? array_slice($part, 0, self::LIST_LIMIT) : $part)->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function stock(Store $store, int $limit): array
    {
        $issues = DB::query()->fromSub($this->levels->variantAvailability($store), 'a')
            ->join('product_variants as v', 'v.id', '=', 'a.variant_id')
            ->join('products as p', 'p.id', '=', 'a.product_id')
            ->whereColumn('a.available', '<=', 'a.threshold')
            ->orderBy('a.available')
            ->limit(max(1, min(self::LIST_LIMIT, $limit)))
            ->get(['p.title', 'v.title as variant', 'v.is_default', 'a.available'])
            ->map(fn ($r) => ['article' => $r->is_default ? $r->title : "{$r->title} — {$r->variant}", 'disponible' => (int) $r->available, 'etat' => (int) $r->available <= 0 ? 'épuisé' : 'stock bas'])
            ->all();

        return $this->levels->summary($store) + ['articles' => $issues];
    }
}
