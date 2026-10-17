<?php

namespace Tests\Support;

use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;

/**
 * Assistant IA des tests : réponses préparées, appels gardés pour vérifier ce qui a été envoyé.
 */
class FakeAiClient implements AiClient
{
    /** @var list<array{system: string, content: array, schema: array}> */
    public array $calls = [];

    /** @var list<array<string, mixed>|AiUnavailable> */
    private array $responses = [];

    public function __construct(private readonly bool $configured = true) {}

    public function respond(array|AiUnavailable $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function json(string $system, array $content, array $schema, int $maxTokens = 8000): array
    {
        $this->calls[] = compact('system', 'content', 'schema');
        $response = array_shift($this->responses) ?? throw new \LogicException('Aucune réponse préparée.');
        if ($response instanceof AiUnavailable) {
            throw $response;
        }

        return ['data' => $response, 'input_tokens' => 1200, 'output_tokens' => 300];
    }

    /**
     * Texte envoyé au modèle (tous les blocs texte du dernier appel).
     */
    public function lastText(): string
    {
        return collect(end($this->calls)['content'])->where('type', 'text')->pluck('text')->implode("\n");
    }
}
