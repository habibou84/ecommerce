<?php

namespace App\Services\Ai;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\QuotaExceededException;
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
     * Actions restantes ce mois-ci (null : illimité).
     *
     * @return array{enabled: bool, used: int, limit: ?int}
     */
    public function status(Store $store): array
    {
        return ['enabled' => $this->enabled(), 'used' => $this->limits->used($store, 'ai_actions_per_month'), 'limit' => $this->limit($store)];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function run(Store $store, ?User $user, string $feature, string $system, array $content, array $schema, int $maxTokens = 8000): array
    {
        if (! $this->enabled()) {
            throw new BusinessRuleException('L\'assistant IA n\'est pas encore activé sur la plateforme.');
        }
        $limit = $this->limit($store);
        if ($limit !== null && $this->limits->used($store, 'ai_actions_per_month') >= $limit) {
            $plan = $this->limits->plan($store)?->name;

            throw new QuotaExceededException($limit === 0
                ? "L'assistant IA n'est pas compris dans l'offre {$plan}. Changez d'offre dans Réglages › Abonnement."
                : "Les {$limit} actions de l'assistant IA de l'offre {$plan} sont utilisées ce mois-ci. Passez à une offre supérieure pour continuer.");
        }

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
     * Photo réduite (1200 px, JPEG) en bloc image : moins de jetons, envoi rapide même en 3G côté serveur.
     *
     * @return array<string, mixed>
     */
    public function image(UploadedFile $file): array
    {
        try {
            $image = (new ImageManager(new Driver))->read($file->getRealPath());
        } catch (Throwable) {
            throw new BusinessRuleException('Ce fichier n\'est pas une image lisible (JPEG, PNG, WebP).', 'image');
        }
        $image->scaleDown(width: 1200, height: 1200);

        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode((string) $image->toJpeg(80))]];
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
        AiUsage::create([
            'store_id' => $store->id, 'user_id' => $user?->id, 'feature' => $feature, 'model' => $this->client->model(),
            'input_tokens' => $input, 'output_tokens' => $output, 'succeeded' => $succeeded,
        ]);
    }
}
