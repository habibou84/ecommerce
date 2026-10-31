<?php

namespace App\Services\Ai;

use App\Exceptions\AiQuotaExceededException;
use App\Exceptions\BusinessRuleException;
use App\Models\AiUsage;
use App\Models\Store;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Point d'entrée de l'assistant IA : vérifie qu'il est activé et que l'offre a encore des actions ce mois-ci,
 * appelle le modèle, note la consommation. Rien n'est enregistré à la place du marchand : il relit et valide.
 */
class Assistant
{
    public function __construct(
        private readonly AiClient $client,
        private readonly PlanLimits $limits,
    ) {}

    public function enabled(): bool
    {
        return $this->client->configured();
    }

    /**
     * Actions utilisées ce mois-ci, quota de l'offre (null : illimité), actions achetées en réserve et recharge
     * proposée.
     *
     * @return array{enabled: bool, used: int, limit: ?int, credits: int, pack: array{actions: int, price: int}}
     */
    public function status(Store $store): array
    {
        return [
            'enabled' => $this->enabled(), 'used' => $this->limits->used($store, 'ai_actions_per_month'), 'limit' => $this->limit($store),
            'credits' => (int) $store->fresh()->ai_credits, 'pack' => config('platform.ai_pack'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function run(Store $store, ?User $user, string $feature, string $system, array $content, array $schema, int $maxTokens = 8000): array
    {
        $this->ensureAvailable($store);

        try {
            $result = $this->client->json($system, $content, $schema, $maxTokens);
        } catch (AiUnavailable $e) {
            $this->record($store, $user, $feature, $e->inputTokens, $e->outputTokens, false);

            throw new BusinessRuleException($e->getMessage());
        }

        $this->record($store, $user, $feature, $result['input_tokens'], $result['output_tokens'], true);

        return $result['data'];
    }

    /**
     * Question avec des outils en lecture (mêmes vérifications et même comptage que run).
     *
     * @param  list<array<string, mixed>>  $tools
     */
    public function ask(Store $store, ?User $user, string $feature, string $system, string $question, array $tools, callable $run): string
    {
        $this->ensureAvailable($store);

        try {
            $result = $this->client->agent($system, $question, $tools, $run);
        } catch (AiUnavailable $e) {
            $this->record($store, $user, $feature, $e->inputTokens, $e->outputTokens, false);

            throw new BusinessRuleException($e->getMessage());
        }
        $this->record($store, $user, $feature, $result['input_tokens'], $result['output_tokens'], true);

        return $result['text'];
    }

    /**
     * Photo réduite (1200 px, JPEG) en bloc image : moins de jetons, envoi rapide même en 3G côté serveur.
     *
     * @return array<string, mixed>
     */
    public function image(UploadedFile $file): array
    {
        return $this->imageFrom($file->getRealPath());
    }

    /**
     * Même chose pour une image reçue en octets (photo d'un message WhatsApp).
     *
     * @param  string  $source  chemin du fichier ou contenu de l'image
     * @return array<string, mixed>
     */
    public function imageFrom(string $source): array
    {
        try {
            $image = (new ImageManager(new Driver))->read($source);
        } catch (Throwable) {
            throw new BusinessRuleException('Ce fichier n\'est pas une image lisible (JPEG, PNG, WebP).', 'image');
        }
        $image->scaleDown(width: 1200, height: 1200);

        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode((string) $image->toJpeg(80))]];
    }

    private function ensureAvailable(Store $store): void
    {
        if (! $this->enabled()) {
            throw new BusinessRuleException('L\'assistant IA n\'est pas encore activé sur la plateforme.');
        }
        $limit = $this->limit($store);
        // Quota de l'offre épuisé : les actions achetées (recharges) prennent le relais
        if ($this->overQuota($store, $limit) && (int) $store->fresh()->ai_credits <= 0) {
            $plan = $this->limits->plan($store)?->name;
            ['actions' => $actions, 'price' => $price] = config('platform.ai_pack');
            $recharge = "Rechargez {$actions} actions pour ".number_format($price, 0, ',', ' ').' F pour continuer.';

            throw new AiQuotaExceededException($limit === 0
                ? "L'assistant IA de l'offre {$plan} marche avec des recharges. {$recharge}"
                : "Vos {$limit} actions de l'assistant IA du mois (offre {$plan}) sont utilisées. {$recharge}", $this->status($store));
        }
    }

    private function overQuota(Store $store, ?int $limit): bool
    {
        return $limit !== null && $this->limits->used($store, 'ai_actions_per_month') >= $limit;
    }

    /**
     * Quota de l'offre ; une offre qui ne le précise pas n'en a pas (le coût de l'IA n'est jamais illimité par oubli).
     */
    private function limit(Store $store): ?int
    {
        $limits = $this->limits->plan($store)?->limits ?? [];
        if (! array_key_exists('ai_actions_per_month', $limits)) {
            return 0;
        }

        return $limits['ai_actions_per_month'] === null ? null : (int) $limits['ai_actions_per_month'];
    }

    private function record(Store $store, ?User $user, string $feature, int $input, int $output, bool $succeeded): void
    {
        // Action réussie au-delà du quota de l'offre : prise sur les actions achetées
        if ($succeeded && $this->overQuota($store, $this->limit($store))) {
            Store::whereKey($store->id)->where('ai_credits', '>', 0)->decrement('ai_credits');
        }
        AiUsage::create([
            'store_id' => $store->id, 'user_id' => $user?->id, 'feature' => $feature, 'model' => $this->client->model(),
            'input_tokens' => $input, 'output_tokens' => $output, 'succeeded' => $succeeded,
        ]);
    }
}
