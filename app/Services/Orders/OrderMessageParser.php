<?php

namespace App\Services\Orders;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;

/**
 * Saisie rapide : le marchand colle le message du client (WhatsApp, Facebook…) et la commande se pré-remplit.
 * Analyse par règles, reprise de Delivery App (HeuristicOrderParser) et étendue au catalogue : téléphones,
 * nom, quartier, adresse, repère, montant, et produits cités avec leur quantité et leur variante.
 * Rien n'est enregistré : le marchand vérifie et complète.
 */
class OrderMessageParser
{
    private const LABELS = [
        'customer_name' => 'nom|client|cliente|destinataire|pour|name',
        'phone' => 'tel|tél|telephone|téléphone|numero|numéro|contact|num|whatsapp',
        'place' => 'commune|zone|quartier|lieu|ville|destination|localisation',
        'address' => 'adresse|addresse|adr',
        'landmark' => 'repere|repère|reperes|repères',
        'amount' => 'montant|prix|total|a payer|à payer|somme',
        'note' => 'note|info|infos|remarque',
    ];

    // Numéro ivoirien à 10 chiffres, éventuellement précédé de +225 / 00225
    private const PHONE = '/(?<!\d)(?:(?:\+|00)225[\s.\-]?)?0[1-9](?:[\s.\-]?\d{2}){4}(?!\d)/u';

    private const STOPWORDS = ['de', 'du', 'des', 'la', 'le', 'les', 'en', 'et', 'a', 'au', 'aux', 'pour', 'avec', 'un', 'une'];

    private const NUMBER_WORDS = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10];

    public function __construct(
        private readonly PlaceMatcher $matcher,
        private readonly Places $places,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parse(Store $store, string $text): array
    {
        $labeled = $this->labeledLines($text);
        $result = [];

        $phones = $this->phones($labeled['phone'] ?? '')->merge($this->phones($text))->unique()->values();
        $result['phone'] = $phones[0] ?? null;
        $result['phone2'] = $phones[1] ?? null;

        $withoutPhones = preg_replace(self::PHONE, ' ', $text);
        $all = $this->places->forStore($store);
        $found = isset($labeled['place']) ? $this->matcher->search($labeled['place'], $all, 5) : collect();
        if ($found->isEmpty()) {
            $found = $this->matcher->inText($withoutPhones, $all);
        }
        $result['place'] = $found->first() ? ['id' => $found->first()->id, 'label' => $found->first()->label()] : null;
        $result['place_candidates'] = $found->take(5)->map(fn ($p) => ['id' => $p->id, 'label' => $p->label()])->values()->all();

        $result['customer_name'] = isset($labeled['customer_name']) ? Str::limit(trim($labeled['customer_name']), 80, '') : $this->name($text);
        $result['address'] = $labeled['address'] ?? ($found->first() ? $this->addressAfter($text, $found->first()->name) : null);
        $result['landmark'] = $labeled['landmark'] ?? $this->landmark($text);
        $result['amount'] = isset($labeled['amount']) ? $this->amount($labeled['amount'], true) : $this->amount($withoutPhones);
        $result['note'] = $labeled['note'] ?? null;
        $result['items'] = $this->items($store, $withoutPhones);

        return $result;
    }

    /**
     * Produits du catalogue cités : tous les mots significatifs du nom présents (pluriel accepté).
     * Un nom à moitié cité n'est retenu que s'il désigne un seul produit.
     * Quantité lue juste avant le nom (« 2 robes », « deux sacs ») ou après (« x2 »). Variante retenue si toutes
     * ses valeurs d'options apparaissent dans le message (« taille M », « rouge »).
     *
     * @return list<array<string, mixed>>
     */
    public function items(Store $store, string $text): array
    {
        $normalized = ' '.PlaceMatcher::normalize($text).' ';
        $words = array_flip(explode(' ', trim($normalized)));

        $products = Product::forStore($store->id)->where('status', ProductStatus::Active->value)
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->with(['option1', 'option2', 'option3'])])
            ->limit(1000)->get();

        $found = fn (string $t) => isset($words[$t]) || isset($words[$t.'s']) || isset($words[$t.'x']);
        $candidates = $products->map(function (Product $product) use ($found) {
            $tokens = array_values(array_filter(explode(' ', PlaceMatcher::normalize($product->title)), fn ($t) => mb_strlen($t) >= 2 && ! in_array($t, self::STOPWORDS, true)));

            return ['product' => $product, 'tokens' => $tokens, 'matched' => count(array_filter($tokens, $found))];
        })->filter(fn ($c) => $c['tokens'] !== []);

        // Tous les mots du nom cités ; à défaut, le seul produit dont le premier mot et au moins un autre sont cités
        // (« robe wax » pour « Robe wax évasée »), s'il n'y en a pas deux aussi proches
        $matches = $candidates->filter(fn ($c) => $c['matched'] === count($c['tokens']));
        if ($matches->isEmpty()) {
            $partial = $candidates->filter(fn ($c) => $c['matched'] >= 2 && $found($c['tokens'][0]));
            $best = $partial->max('matched');
            $partial = $partial->where('matched', $best);
            $matches = $partial->count() === 1 ? $partial : collect();
        }

        $items = [];
        foreach ($matches as ['product' => $product, 'tokens' => $tokens]) {

            $variant = $this->variant($product->variants, $words);
            $items[] = [
                'product_id' => $product->id,
                'title' => $product->title,
                'variant_id' => $variant?->id,
                'variant_title' => $variant && ! $variant->is_default ? $variant->title : null,
                'needs_variant' => $variant === null,
                'quantity' => $this->quantityNear($normalized, $tokens[0]),
                'unit_price' => $variant?->price ?? $product->price_min,
            ];
        }

        // Le nom le plus long d'abord (« Robe wax longue » avant « Robe wax »)
        usort($items, fn ($a, $b) => mb_strlen($b['title']) <=> mb_strlen($a['title']));

        return array_slice($items, 0, 10);
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function variant(Collection $variants, array $words): ?ProductVariant
    {
        if ($variants->count() === 1) {
            return $variants->first();
        }

        $matching = $variants->filter(function (ProductVariant $v) use ($words) {
            foreach ([$v->option1, $v->option2, $v->option3] as $value) {
                if ($value === null) {
                    continue;
                }
                $parts = explode(' ', PlaceMatcher::normalize($value->value));
                if (! collect($parts)->every(fn ($p) => isset($words[$p]))) {
                    return false;
                }
            }

            return true;
        });

        return $matching->count() === 1 ? $matching->first() : null;
    }

    private function quantityNear(string $normalized, string $firstToken): int
    {
        $token = preg_quote($firstToken, '/');
        if (preg_match('/(?:^| )(\d{1,3}|'.implode('|', array_keys(self::NUMBER_WORDS)).') (?:\w+ ){0,1}'.$token.'s?(?: |$)/', $normalized, $m)) {
            return self::NUMBER_WORDS[$m[1]] ?? max(1, (int) $m[1]);
        }
        if (preg_match('/ '.$token.'s?(?: \w+){0,4} x ?(\d{1,3})(?: |$)/', $normalized, $m)) {
            return max(1, (int) $m[1]);
        }

        return 1;
    }

    /**
     * @return Collection<int, string>
     */
    public function phones(string $text): Collection
    {
        preg_match_all(self::PHONE, $text, $matches);

        return collect($matches[0])->map(fn ($p) => PhoneNumber::normalize($p))->filter()->values();
    }

    /**
     * Montant : « 15000 F », « 15 000 », « 15k » ; sinon le plus grand nombre plausible.
     */
    public function amount(string $text, bool $labeled = false): ?int
    {
        preg_match_all('/(?<![\d.])(\d{1,3}(?:[ .\x{202F}\x{00A0}]\d{3})+|\d+)\s*(k\b|mille\b|f\b|fr\b|frs\b|fcfa\b|cfa\b|francs?\b)?/iu', $text, $matches, PREG_SET_ORDER);

        $candidates = collect($matches)->map(function ($m) {
            $value = (int) preg_replace('/\D/', '', $m[1]);
            $suffix = Str::lower($m[2] ?? '');
            if (in_array($suffix, ['k', 'mille'], true)) {
                $value *= 1000;
            }

            return ['value' => $value, 'currency' => $suffix !== ''];
        });

        if ($labeled) {
            return $candidates->first()['value'] ?? null;
        }

        $withCurrency = $candidates->firstWhere('currency', true);
        if ($withCurrency) {
            return $withCurrency['value'];
        }

        $plausible = $candidates->filter(fn ($c) => $c['value'] >= 500 && $c['value'] <= 10_000_000);

        return $plausible->isEmpty() ? null : $plausible->max('value');
    }

    /**
     * @return array<string, string>
     */
    private function labeledLines(string $text): array
    {
        $found = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            foreach (self::LABELS as $field => $labels) {
                if (preg_match('/^\s*[-•*]?\s*(?:'.$labels.')\s*[:=\-]\s*(.+)$/iu', $line, $m)) {
                    $found[$field] ??= trim($m[1]);
                    break;
                }
            }
        }

        return $found;
    }

    private function addressAfter(string $text, string $placeName): ?string
    {
        if (! preg_match('/\b'.preg_quote($placeName, '/').'\b[\s,:-]+([^,.;!?\n]{2,60})/iu', $text, $m)) {
            return null;
        }

        $address = preg_split('/\b(?:face|en face|derri[eè]re|à côté|a cote|pr[eè]s|non loin|tel|tél|num[ée]ro|\d{2}\s?\d{2})\b/iu', $m[1])[0];
        $address = preg_replace('/\b(svp|stp|s\'il vous pla[iî]t|merci|please|pls)\b/iu', '', $address);
        $address = trim(preg_replace('/\s+/u', ' ', $address), " \t-");

        return mb_strlen($address) >= 2 ? $address : null;
    }

    private function name(string $text): ?string
    {
        if (preg_match('/\b(?i:je suis|je m\'appelle|moi c\'est|c\'est|c’est|ici|pour|client(?:e)?|destinataire|chez|livrer à|livrer a)\s*:?\s+((?:m(?:me|lle|r)?\.?\s+)?\p{Lu}[\p{L}\'\-]+(?:\s+\p{Lu}[\p{L}\'\-]+)?)/u', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function landmark(string $text): ?string
    {
        if (preg_match('/\b((?:face|en face|derri[eè]re|à côté|a cote|pr[eè]s|non loin)\s+(?:de\s+|du\s+|des\s+|la\s+|le\s+|l\')?[^\n,.;]{3,80})/iu', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
