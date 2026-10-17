<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;

/**
 * Claude (Anthropic) par le SDK officiel. Effort réduit : extraction et rédaction courtes, coût maîtrisé.
 */
class AnthropicAiClient implements AiClient
{
    private ?Client $client = null;

    public function __construct(private readonly ?string $key, private readonly string $model) {}

    public function configured(): bool
    {
        return filled($this->key);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function json(string $system, array $content, array $schema, int $maxTokens = 8000): array
    {
        $this->client ??= new Client(apiKey: $this->key);

        try {
            $message = $this->client->messages->create(
                model: $this->model,
                maxTokens: $maxTokens,
                system: $system,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                requestOptions: ['timeout' => 90],
            );
        } catch (APIException $e) {
            report($e);

            throw new AiUnavailable('L\'assistant est momentanément indisponible. Réessayez dans un instant.');
        }

        $input = (int) $message->usage->inputTokens;
        $output = (int) $message->usage->outputTokens;
        if ($message->stopReason === 'refusal') {
            throw new AiUnavailable('L\'assistant n\'a pas pu traiter cette demande.', $input, $output);
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return ['data' => $data, 'input_tokens' => $input, 'output_tokens' => $output];
                }
            }
        }

        throw new AiUnavailable('L\'assistant a donné une réponse illisible. Réessayez.', $input, $output);
    }
}
