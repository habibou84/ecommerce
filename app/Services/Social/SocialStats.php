<?php

namespace App\Services\Social;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ShortLink;
use App\Models\SocialPost;
use App\Models\Store;
use Illuminate\Support\Carbon;

/**
 * Ce que rapporte chaque réseau : clics sur les liens publiés, commandes et chiffre d'affaires venus de Facebook,
 * Instagram ou TikTok (publications, publicités, commandes saisies avec la source du réseau), et le détail par
 * publication.
 */
class SocialStats
{
    public const NETWORKS = ['facebook', 'instagram', 'tiktok'];

    /**
     * @return array{since: string, networks: list<array<string, mixed>>, posts: list<array<string, mixed>>}
     */
    public function report(Store $store, int $days = 30): array
    {
        $since = Carbon::now($store->timezone)->subDays($days - 1)->startOfDay();
        $orders = Order::forStore($store->id)->where('created_at', '>=', $since)
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->where(fn ($q) => $q->whereIn('source', self::NETWORKS)->orWhereNotNull('utm'))
            ->get(['id', 'source', 'utm', 'total', 'status']);

        $byNetwork = array_fill_keys(self::NETWORKS, ['orders' => 0, 'revenue' => 0, 'clicks' => 0, 'posts' => 0]);
        $byPost = [];
        foreach ($orders as $order) {
            $network = self::networkOf($order->source instanceof \BackedEnum ? $order->source->value : $order->source, $order->utm);
            if ($network === null) {
                continue;
            }
            $byNetwork[$network]['orders']++;
            $byNetwork[$network]['revenue'] += $order->total;
            if (preg_match('/^post-(\d+)$/', (string) ($order->utm['utm_campaign'] ?? ''), $m)) {
                $key = $m[1].':'.$network;
                $byPost[$key] = ['orders' => ($byPost[$key]['orders'] ?? 0) + 1, 'revenue' => ($byPost[$key]['revenue'] ?? 0) + $order->total];
            }
        }

        $posts = SocialPost::forStore($store->id)->with('product:id,title')->where('created_at', '>=', $since)->latest('id')->limit(50)->get();
        $clicks = ShortLink::forStore($store->id)->whereIn('social_post_id', $posts->pluck('id'))->get(['social_post_id', 'network', 'clicks']);
        foreach ($clicks as $link) {
            $byNetwork[$link->network]['clicks'] += $link->clicks;
        }
        foreach ($posts as $post) {
            foreach ($post->networks as $n) {
                $byNetwork[$n]['posts']++;
            }
        }

        return [
            'since' => $since->toDateString(),
            'networks' => collect(self::NETWORKS)->map(fn ($n) => ['network' => $n] + $byNetwork[$n])->values()->all(),
            'posts' => $posts->map(fn (SocialPost $p) => [
                'id' => $p->id,
                'product' => $p->product?->title,
                'status' => $p->status,
                'published_at' => $p->published_at?->toIso8601String(),
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'networks' => collect($p->networks)->map(fn ($n) => [
                    'network' => $n,
                    'status' => $p->results[$n]['status'] ?? ($p->status === 'scheduled' ? 'scheduled' : 'pending'),
                    'url' => $p->results[$n]['url'] ?? null,
                    'error' => $p->results[$n]['error'] ?? null,
                    'clicks' => (int) $clicks->where('social_post_id', $p->id)->where('network', $n)->sum('clicks'),
                    'orders' => $byPost[$p->id.':'.$n]['orders'] ?? 0,
                    'revenue' => $byPost[$p->id.':'.$n]['revenue'] ?? 0,
                ])->values(),
            ])->values()->all(),
        ];
    }

    /**
     * Réseau d'origine d'une commande : source saisie, sinon l'origine de la visite (liens publiés, publicités).
     *
     * @param  array<string, string>|null  $utm
     */
    public static function networkOf(?string $source, ?array $utm): ?string
    {
        if (in_array($source, self::NETWORKS, true)) {
            return $source;
        }
        $utmSource = strtolower((string) ($utm['utm_source'] ?? ''));

        return match (true) {
            in_array($utmSource, ['facebook', 'fb', 'meta', 'messenger'], true) => 'facebook',
            in_array($utmSource, ['instagram', 'ig'], true) => 'instagram',
            in_array($utmSource, ['tiktok', 'tt'], true) => 'tiktok',
            isset($utm['ttclid']) => 'tiktok',
            isset($utm['fbclid']) => 'facebook',
            default => null,
        };
    }
}
