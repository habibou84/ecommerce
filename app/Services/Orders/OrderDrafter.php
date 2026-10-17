<?php

namespace App\Services\Orders;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Assistant;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;

/**
 * Saisie rapide par l'assistant IA : conversation collée (même mal écrite, en plusieurs messages) ou captures
 * d'écran (WhatsApp, Facebook, TikTok). Le résultat a la même forme que l'analyse par règles (OrderMessageParser) :
 * le formulaire se remplit de la même façon, et rien n'est enregistré avant que le marchand vérifie.
 */
class OrderDrafter
{
    private const CATALOG_LIMIT = 300;

    private const SYSTEM = <<<'TXT'
    Tu aides des commerçants de Côte d'Ivoire à saisir les commandes reçues par WhatsApp, Facebook ou TikTok.
    On te donne une conversation avec un client (texte collé et/ou captures d'écran) et le catalogue de la boutique.
    Les clients écrivent souvent en français familier ou en nouchi, avec des fautes et des abréviations (« yop » = Yopougon, « 2 robe tail M », « c combien »…).
    Extrais la commande que le client veut passer :
    - phone / phone2 : numéros du client tels qu'écrits (le premier est le numéro principal), sinon null. Pas le numéro de la boutique ;
    - customer_name : nom du client s'il le donne, sinon null ;
    - place : commune et quartier de livraison tels que le client les écrit (ex. « Yopougon Siporex »), sinon null ;
    - address et landmark : précisions d'adresse et repère (« derrière la pharmacie… »), sinon null ;
    - note : demande particulière (heure de livraison, emballage cadeau…), sinon null ;
    - amount : montant total annoncé dans la conversation, en francs CFA, sinon null ;
    - items : chaque article commandé avec sa quantité. Code de l'article : le code V… de la variante si on sait laquelle (taille, couleur), sinon le code P… du produit, sinon null si l'article n'est pas au catalogue ; title : l'article tel que le client le décrit.
    Ne retiens que ce que le client commande vraiment (pas les articles seulement demandés en renseignement puis abandonnés).
    TXT;

    public function __construct(
        private readonly Assistant $assistant,
        private readonly PlaceMatcher $matcher,
        private readonly Places $places,
    ) {}

    /**
     * @param  list<UploadedFile>  $screenshots
     * @return array<string, mixed>
     */
    public function draft(Store $store, User $user, ?string $text, array $screenshots = []): array
    {
        $products = $this->catalog($store, (string) $text);

        $content = array_map(fn (UploadedFile $shot) => $this->assistant->image($shot), $screenshots);
        $content[] = ['type' => 'text', 'text' => implode("\n\n", array_filter([
            "Catalogue de {$store->name} :\n".$this->catalogText($products),
            filled($text) ? "Conversation :\n".Str::limit(trim($text), 8000) : 'Conversation : voir les captures d\'écran.',
        ]))];

        $draft = $this->assistant->run($store, $user, 'order_draft', self::SYSTEM, $content, self::schema());

        $phones = collect([$draft['phone'] ?? null, $draft['phone2'] ?? null])->filter()
            ->map(fn ($p) => PhoneNumber::normalize((string) $p))->filter()->unique()->values();
        $all = $this->places->forStore($store);
        $found = filled($draft['place'] ?? null) ? $this->matcher->search($draft['place'], $all, 5) : collect();
        if ($found->isEmpty() && filled($draft['address'] ?? null)) {
            $found = $this->matcher->inText($draft['address'], $all);
        }
        [$items, $unmatched] = $this->items($products, $draft['items'] ?? []);

        return [
            'phone' => $phones[0] ?? null,
            'phone2' => $phones[1] ?? null,
            'customer_name' => filled($draft['customer_name'] ?? null) ? Str::limit(trim($draft['customer_name']), 80, '') : null,
            'place' => $found->first() ? ['id' => $found->first()->id, 'label' => $found->first()->label()] : null,
            'place_candidates' => $found->take(5)->map(fn ($p) => ['id' => $p->id, 'label' => $p->label()])->values()->all(),
            'place_text' => $draft['place'] ?? null,
            'address' => filled($draft['address'] ?? null) ? Str::limit(trim($draft['address']), 255, '') : null,
            'landmark' => filled($draft['landmark'] ?? null) ? Str::limit(trim($draft['landmark']), 255, '') : null,
            'amount' => isset($draft['amount']) && is_numeric($draft['amount']) ? (int) $draft['amount'] : null,
            'note' => filled($draft['note'] ?? null) ? Str::limit(trim($draft['note']), 500, '') : null,
            'items' => $items,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Produits proposés au modèle : tout le catalogue actif s'il est petit, sinon ceux dont un mot du nom est cité.
     *
     * @return Collection<int, Product>
     */
    private function catalog(Store $store, string $text): Collection
    {
        $query = fn () => Product::forStore($store->id)->where('status', ProductStatus::Active->value)
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('position')]);

        if ((clone $query())->count() <= self::CATALOG_LIMIT || trim($text) === '') {
            return $query()->latest('updated_at')->limit(self::CATALOG_LIMIT)->get();
        }

        $words = array_filter(explode(' ', PlaceMatcher::normalize($text)), fn ($w) => mb_strlen($w) >= 3);

        return $query()->where(fn ($q) => collect($words)->take(40)->each(fn ($w) => $q->orWhere('title', 'ilike', '%'.$w.'%')))
            ->limit(self::CATALOG_LIMIT)->get();
    }

    private function catalogText(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '(catalogue vide)';
        }

        return $products->map(function (Product $p) {
            $price = fn (ProductVariant $v) => number_format($v->price, 0, ',', ' ').' F';
            if (! $p->has_variants && $p->variants->count() === 1) {
                return "V{$p->variants[0]->id} {$p->title} — {$price($p->variants[0])}";
            }

            return "P{$p->id} {$p->title} : ".$p->variants->map(fn ($v) => "V{$v->id} {$v->title} {$price($v)}")->implode(' ; ');
        })->implode("\n");
    }

    /**
     * Articles rapprochés du catalogue proposé (un code inventé ou d'une autre boutique est ignoré).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function items(Collection $products, array $rows): array
    {
        $variants = $products->flatMap(fn (Product $p) => $p->variants->each(fn ($v) => $v->setRelation('product', $p)))->keyBy('id');
        $byId = $products->keyBy('id');
        $items = [];
        $unmatched = [];

        foreach (array_slice($rows, 0, 20) as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $quantity = max(1, min(1000, (int) ($row['quantity'] ?? 1)));
            $variant = str_starts_with($code, 'V') ? $variants->get((int) substr($code, 1)) : null;
            $product = $variant?->product ?? (str_starts_with($code, 'P') ? $byId->get((int) substr($code, 1)) : null);
            if ($product === null) {
                if (filled($row['title'] ?? null)) {
                    $unmatched[] = Str::limit(trim($row['title']), 80);
                }

                continue;
            }
            // Un seul choix possible : c'est la variante
            $variant ??= $product->variants->count() === 1 ? $product->variants->first() : null;

            $items[] = [
                'product_id' => $product->id,
                'title' => $product->title,
                'variant_id' => $variant?->id,
                'variant_title' => $variant && ! $variant->is_default ? $variant->title : null,
                'needs_variant' => $variant === null,
                'quantity' => $quantity,
                'unit_price' => $variant?->price ?? $product->price_min,
            ];
        }

        return [$items, $unmatched];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $text = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'phone' => $text, 'phone2' => $text, 'customer_name' => $text, 'place' => $text, 'address' => $text,
                'landmark' => $text, 'note' => $text, 'amount' => ['type' => ['integer', 'null']],
                'items' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['code' => $text, 'title' => ['type' => 'string'], 'quantity' => ['type' => 'integer']],
                    'required' => ['code', 'title', 'quantity'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['phone', 'phone2', 'customer_name', 'place', 'address', 'landmark', 'note', 'amount', 'items'],
            'additionalProperties' => false,
        ];
    }
}
