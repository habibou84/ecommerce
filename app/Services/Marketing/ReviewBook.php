<?php

namespace App\Services\Marketing;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Avis clients : le client d'une commande livrée note les produits reçus par le lien de sa commande ; l'avis
 * attend la validation du marchand avant d'apparaître sur la fiche produit.
 */
class ReviewBook
{
    /**
     * Lien à envoyer au client (même jeton que le suivi de la commande).
     */
    public function url(Order $order): string
    {
        return $order->store->url('/avis/'.$order->tracking_token);
    }

    public function canReview(Order $order): bool
    {
        return $order->status === OrderStatus::Completed && filled($order->customer_phone);
    }

    /**
     * Produits de la commande encore au catalogue, avec l'avis déjà laissé.
     *
     * @return Collection<int, array{product: Product, review: ?ProductReview}>
     */
    public function products(Order $order): Collection
    {
        $ids = $order->items->pluck('product_id')->filter()->unique();
        $products = Product::forStore($order->store_id)->whereIn('id', $ids)->with('images')->get()->keyBy('id');
        $reviews = ProductReview::forStore($order->store_id)->where('order_id', $order->id)->get()->keyBy('product_id');

        return $ids->filter(fn ($id) => $products->has($id))
            ->map(fn ($id) => ['product' => $products[$id], 'review' => $reviews->get($id)])->values();
    }

    /**
     * Avis du client (un par produit ; le renvoyer le remplace et le remet à valider).
     *
     * @param  array<int|string, array{rating?: int|string|null, comment?: ?string}>  $ratings  product_id => note et commentaire
     */
    public function submit(Order $order, array $ratings, ?string $name = null): int
    {
        if (! $this->canReview($order)) {
            throw new BusinessRuleException('Les avis s\'ouvrent une fois la commande livrée.');
        }
        $products = $this->products($order)->keyBy(fn ($p) => $p['product']->id);
        $author = Str::limit(trim((string) ($name ?: $order->customer_name ?: 'Client')), 60, '');
        // Prénom et initiale seulement sur la boutique
        $parts = preg_split('/\s+/', $author);
        $public = count($parts) > 1 ? $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $author;

        $saved = 0;
        DB::transaction(function () use ($order, $ratings, $products, $public, &$saved) {
            foreach ($ratings as $productId => $row) {
                $rating = (int) ($row['rating'] ?? 0);
                if (! $products->has((int) $productId) || $rating < 1 || $rating > 5) {
                    continue;
                }
                ProductReview::forStore($order->store_id)->updateOrCreate(
                    ['order_id' => $order->id, 'product_id' => (int) $productId],
                    ['store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'author_name' => $public, 'rating' => $rating,
                        'comment' => filled($row['comment'] ?? null) ? Str::limit(trim($row['comment']), 1000, '') : null,
                        'status' => 'pending', 'published_at' => null],
                );
                $saved++;
            }
        });
        if ($saved === 0) {
            throw new BusinessRuleException('Donnez une note (de 1 à 5 étoiles) à au moins un article.', 'ratings');
        }

        return $saved;
    }

    public function publish(ProductReview $review): void
    {
        $review->forceFill(['status' => 'published', 'published_at' => $review->published_at ?? now()])->save();
    }

    public function hide(ProductReview $review): void
    {
        $review->forceFill(['status' => 'hidden'])->save();
    }

    /**
     * Note moyenne et avis publiés d'un produit (fiche produit de la boutique).
     *
     * @return array{average: ?float, count: int, reviews: Collection<int, ProductReview>}
     */
    public function published(Product $product, int $limit = 10): array
    {
        $query = ProductReview::forStore($product->store_id)->where('product_id', $product->id)->where('status', 'published');
        $count = (clone $query)->count();

        return [
            'average' => $count ? round((float) (clone $query)->avg('rating'), 1) : null,
            'count' => $count,
            'reviews' => $query->latest('published_at')->limit($limit)->get(),
        ];
    }
}
