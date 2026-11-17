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

    // Mots d'un message qui annonce sans doute un achat (texte normalisé : minuscules, sans accents)
    private const ORDER_WORDS = '/(?:^| )(?:je veux|je voudrais|j aimerais|je prends|commande\w*|commander|acheter|livr\w*|dispo\w*|taille|pointure|couleur|combien|prix)(?: |$)/';

    /** @var array<int, Collection<int, Product>> produits actifs, chargés une fois le temps d'un passage sur plusieurs messages */
    private array $catalog = [];

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
     * Produits du catalogue cités : tous les mots significatifs du nom présents (pluriel accepté dans les deux sens :
     * « sticker » retrouve « Stickers »). À défaut, au moins 2 mots du nom, dont le premier ou la moitié du nom (où
     * qu'ils soient) : retenu seulement s'il n'y a pas deux produits aussi proches (voir choices()). À défaut encore, le
     * premier mot du nom seul (« un Stickers ») : retenu s'il n'y a qu'un produit de ce nom.
     * Quantité lue juste avant le nom (« 2 robes », « deux sacs ») ou après (« x2 »). Variante retenue si toutes
     * ses valeurs d'options apparaissent dans le message (« taille M », « rouge »).
     *
     * @return list<array<string, mixed>>
     */
    public function items(Store $store, string $text): array
    {
        [$normalized, $words, $matches] = $this->rank($store, $text);

        $items = [];
        foreach ($matches as ['product' => $product, 'tokens' => $tokens]) {

            $variant = $this->variant($product->variants, $words);
            $items[] = [
                'product_id' => $product->id,
                'title' => $product->title,
                'variant_id' => $variant?->id,
                'variant_title' => $variant && ! $variant->is_default ? $variant->title : null,
                'needs_variant' => $variant === null,
                'quantity' => $this->quantityNear($normalized, $this->firstCited($tokens, $words)),
                'unit_price' => $variant?->price ?? $product->price_min,
            ];
        }

        // Le nom le plus long d'abord (« Robe wax longue » avant « Robe wax »)
        usort($items, fn ($a, $b) => mb_strlen($b['title']) <=> mb_strlen($a['title']));

        return array_slice($items, 0, 10);
    }

    /**
     * Plusieurs produits aussi proches du texte (« sticker muraux carré » pour « … carré multicolore » et « … carré
     * noir ») : ceux entre lesquels choisir, quand items() n'en retient aucun. Vide s'il n'y a pas d'hésitation.
     *
     * @return list<array{product_id: int, title: string}>
     */
    public function choices(Store $store, string $text): array
    {
        [, , $matches, $ties] = $this->rank($store, $text);

        return $matches->isNotEmpty() ? [] : $ties->take(10)->map(fn ($c) => ['product_id' => $c['product']->id, 'title' => $c['product']->title])->values()->all();
    }

    /**
     * Produits retenus, et produits à égalité quand aucun ne se détache.
     *
     * @return array{0: string, 1: array<string, int>, 2: Collection<int, array<string, mixed>>, 3: Collection<int, array<string, mixed>>}
     */
    private function rank(Store $store, string $text): array
    {
        $normalized = ' '.PlaceMatcher::normalize($text).' ';
        $words = array_flip(explode(' ', trim($normalized)));
        $stems = array_flip(array_map(fn ($w) => self::stem((string) $w), array_keys($words)));

        $products = $this->catalog[$store->id] ?? $this->products($store);

        $found = fn (string $t) => isset($words[$t]) || isset($words[$t.'s']) || isset($words[$t.'x']) || isset($stems[self::stem($t)]);
        $candidates = $products->map(function (Product $product) use ($found) {
            $tokens = array_values(array_filter(explode(' ', PlaceMatcher::normalize($product->title)), fn ($t) => mb_strlen($t) >= 2 && ! in_array($t, self::STOPWORDS, true)));

            return ['product' => $product, 'tokens' => $tokens, 'matched' => count(array_filter($tokens, $found))];
        })->filter(fn ($c) => $c['tokens'] !== []);

        // Tous les mots du nom cités ; à défaut, le seul produit dont au moins 2 mots et la moitié du nom sont cités
        // (« robe wax » pour « Robe wax évasée », « sticker muraux carré » pour « Lot de 6 stickers muraux carrés »)
        $matches = $candidates->filter(fn ($c) => $c['matched'] === count($c['tokens']));
        $ties = collect();
        if ($matches->isEmpty()) {
            // Début du nom cité (« sticker muraux carré » pour « Sticker muraux carré multicolore – 30 x 30 cm… »), ou
            // la moitié du nom où que ce soit (« Lot de 6 stickers muraux carrés »)
            $partial = $candidates->filter(fn ($c) => $c['matched'] >= 2 && ($found($c['tokens'][0]) || count($c['tokens']) <= $c['matched'] * 2));
            $best = $partial->max('matched');
            $ties = $partial->where('matched', $best)->values();
            $matches = $ties->count() === 1 ? $ties : collect();
            // Un seul mot cité, le premier du nom (« Je veux un Stickers » pour « Stickers muraux carrés… ») : retenu s'il
            // n'y a qu'un produit de ce nom, sinon proposé au choix ; mot d'au moins 4 lettres (pas « sac », « lot »)
            if ($partial->isEmpty()) {
                $ties = $candidates->filter(fn ($c) => mb_strlen($c['tokens'][0]) >= 4 && ! is_numeric($c['tokens'][0]) && $found($c['tokens'][0]))->values();
                $matches = $ties->count() === 1 ? $ties : collect();
            }
        }

        return [$normalized, $words, $matches, $ties];
    }

    /** Mot sans son pluriel (« stickers », « carres », « muraux » → « sticker », « carre », « mural ») */
    private static function stem(string $word): string
    {
        if (mb_strlen($word) <= 3) {
            return $word;
        }
        if (str_ends_with($word, 'aux')) {
            return mb_substr($word, 0, -3).'al';
        }

        return rtrim($word, 'sx');
    }

    /**
     * Premier mot du nom cité dans le message (pour lire la quantité juste avant) ; sinon le premier mot du nom.
     *
     * @param  list<string>  $tokens
     * @param  array<string, int>  $words
     */
    private function firstCited(array $tokens, array $words): string
    {
        foreach ($tokens as $t) {
            if (isset($words[$t]) || isset($words[$t.'s']) || isset($words[$t.'x'])) {
                return $t;
            }
            $stem = self::stem($t);
            foreach (array_keys($words) as $w) {
                if (self::stem((string) $w) === $stem) {
                    return (string) $w;
                }
            }
        }

        return $tokens[0];
    }

    /**
     * Messages d'une conversation qui contiennent peut-être une commande : un produit du catalogue cité, un numéro de
     * téléphone, ou des mots d'achat (« je veux », « livraison », « taille »…). Sert à proposer « Faire une commande »
     * sous ces messages.
     *
     * @param  array<int, string>  $texts  textes par identifiant de message
     * @return list<int> identifiants des messages retenus
     */
    public function orderHints(Store $store, array $texts): array
    {
        $hints = [];
        $this->catalog[$store->id] = $this->products($store);
        foreach ($texts as $id => $text) {
            if (mb_strlen(trim($text)) < 6) {
                continue;
            }
            if (preg_match(self::ORDER_WORDS, ' '.PlaceMatcher::normalize($text).' ')
                || $this->phones($text)->isNotEmpty()
                || $this->items($store, $text) !== []) {
                $hints[] = $id;
            }
        }
        unset($this->catalog[$store->id]);

        return $hints;
    }

    /**
     * @return Collection<int, Product>
     */
    private function products(Store $store): Collection
    {
        return Product::forStore($store->id)->where('status', ProductStatus::Active->value)
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->with(['option1', 'option2', 'option3'])])
            ->orderBy('id')->limit(1000)->get();
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
