<?php

namespace App\Services\Social;

use App\Exceptions\BusinessRuleException;
use App\Jobs\PublishSocialPost;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\User;
use App\Services\Marketing\ShareImages;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Publication d'un produit sur les réseaux connectés : visuels carré et vertical refaits avec le prix du moment,
 * un lien court par réseau (pour savoir d'où viennent les commandes), publication tout de suite ou à l'heure dite.
 */
class SocialPublisher
{
    public function __construct(
        private readonly ShareImages $images,
        private readonly MetaSocial $meta,
        private readonly TikTokSocial $tiktok,
    ) {}

    /**
     * @param  list<string>  $networks
     */
    public function create(Store $store, User $user, Product $product, string $caption, array $networks, ?Carbon $scheduledAt = null): SocialPost
    {
        $connected = SocialAccount::forStore($store->id)->where('status', 'active')->pluck('network')->all();
        $networks = array_values(array_intersect(array_keys(SocialAccount::NETWORKS), $networks));
        if ($networks === []) {
            throw new BusinessRuleException('Choisissez au moins un réseau.', 'networks');
        }
        if ($missing = array_diff($networks, $connected)) {
            throw new BusinessRuleException(SocialAccount::NETWORKS[reset($missing)].' n\'est pas connecté (Marketing › Réseaux sociaux).', 'networks');
        }
        if ($product->images()->doesntExist()) {
            throw new BusinessRuleException('Ajoutez une photo au produit : les réseaux publient une image.');
        }

        $post = SocialPost::create([
            'store_id' => $store->id, 'product_id' => $product->id, 'caption' => trim($caption), 'networks' => $networks,
            'scheduled_at' => $scheduledAt && $scheduledAt->isFuture() ? $scheduledAt : null, 'created_by' => $user->id,
        ]);
        // Un lien par réseau ; les visuels portent le lien de Facebook (le plus partagé)
        $links = collect($networks)->mapWithKeys(fn ($n) => [$n => $this->images->linkFor($store, $product, $post->id, $n)])->all();
        $visuals = $this->images->visuals($store, $product, reset($links), "stores/{$store->id}/social/post-{$post->id}");
        $post->forceFill(['square_path' => $visuals['square'], 'vertical_path' => $visuals['vertical']])->save();

        if ($post->scheduled_at === null) {
            PublishSocialPost::dispatch($post->id);
        }

        return $post->fresh();
    }

    /**
     * Publie sur chaque réseau ; une erreur sur un réseau n'empêche pas les autres.
     */
    public function publish(SocialPost $post): SocialPost
    {
        if (! in_array($post->status, ['scheduled', 'queued', 'failed', 'partial'], true)) {
            return $post;
        }
        $post->forceFill(['status' => 'publishing'])->save();
        $links = $post->links()->pluck('code', 'network');
        $results = $post->results ?? [];

        foreach ($post->networks as $network) {
            if (($results[$network]['status'] ?? null) === 'published') {
                continue;
            }
            $account = SocialAccount::forStore($post->store_id)->where('network', $network)->first();
            try {
                if ($account === null) {
                    throw new BusinessRuleException(SocialAccount::NETWORKS[$network].' n\'est plus connecté.');
                }
                $caption = $this->caption($post, $network, isset($links[$network]) ? $post->store->url('/p/'.$links[$network]) : null);
                $square = $this->images->publicUrl($post->square_path);
                $vertical = $this->images->publicUrl($post->vertical_path);
                $done = $network === 'tiktok'
                    ? $this->tiktok->publish($account, [$vertical], $caption)
                    : $this->meta->publish($account, $square, $caption);
                $results[$network] = ['status' => 'published', 'id' => $done['id'], 'url' => $done['url'], 'at' => now()->toIso8601String()];
                $account->forceFill(['status' => 'active', 'last_error' => null])->save();
            } catch (Throwable $e) {
                report($e);
                $results[$network] = ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'at' => now()->toIso8601String()];
                $account?->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();
            }
        }

        $ok = collect($results)->where('status', 'published')->count();
        $post->forceFill([
            'results' => $results,
            'status' => $ok === count($post->networks) ? 'published' : ($ok > 0 ? 'partial' : 'failed'),
            'published_at' => $ok > 0 ? ($post->published_at ?? now()) : null,
        ])->save();

        return $post;
    }

    /**
     * Publications programmées arrivées à l'heure.
     */
    public function publishDue(): int
    {
        // Publiées par la file d'attente (appels Facebook/TikTok lents) : chaque publication est d'abord « réservée »
        // pour ne pas partir deux fois si la tâche repasse avant la fin
        $due = SocialPost::withoutGlobalScopes()->where('status', 'scheduled')->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->pluck('id');
        $queued = 0;
        foreach ($due as $id) {
            if (SocialPost::withoutGlobalScopes()->whereKey($id)->where('status', 'scheduled')->update(['status' => 'queued']) === 1) {
                PublishSocialPost::dispatch($id);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Légende d'un réseau : le lien du réseau à la place de {lien} (ou à la fin). Sur Instagram et TikTok le lien
     * n'est pas cliquable : il reste lisible, et invite à écrire en message.
     */
    public function caption(SocialPost $post, string $network, ?string $link): string
    {
        $text = $post->caption;
        $link ??= '';
        $text = str_contains($text, '{lien}') ? str_replace('{lien}', $link, $text) : trim($text."\n\nCommandez ici : ".$link);
        if ($network !== 'facebook') {
            $text .= "\n\nÉcrivez-nous en message pour commander 📩";
        }

        return trim($text);
    }
}
