<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\ToolUseBlock;
use Throwable;

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

    public function agent(string $system, string $question, array $tools, callable $run, int $maxTurns = 6): array
    {
        $this->client ??= new Client(apiKey: $this->key);
        $messages = [['role' => 'user', 'content' => $question]];
        $input = $output = 0;

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            try {
                $message = $this->client->messages->create(
                    model: $this->model,
                    maxTokens: 8000,
                    system: $system,
                    tools: $tools,
                    messages: $messages,
                    outputConfig: ['effort' => 'low'],
                    requestOptions: ['timeout' => 90],
                );
            } catch (APIException $e) {
                report($e);

                throw new AiUnavailable('L\'assistant est momentanément indisponible. Réessayez dans un instant.', $input, $output);
            }
            $input += (int) $message->usage->inputTokens;
            $output += (int) $message->usage->outputTokens;

            if ($message->stopReason === 'refusal') {
                throw new AiUnavailable('L\'assistant n\'a pas pu traiter cette demande.', $input, $output);
            }
            if ($message->stopReason !== 'tool_use') {
                $text = collect($message->content)->filter(fn ($b) => $b->type === 'text')->map(fn ($b) => $b->text)->implode("\n");
                if (trim($text) === '') {
                    break;
                }

                return ['text' => trim($text), 'input_tokens' => $input, 'output_tokens' => $output];
            }

            $results = [];
            foreach ($message->content as $block) {
                if ($block instanceof ToolUseBlock) {
                    try {
                        $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => json_encode($run($block->name, (array) $block->input), JSON_UNESCAPED_UNICODE)];
                    } catch (Throwable $e) {
                        $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => 'Erreur : '.$e->getMessage(), 'isError' => true];
                    }
                }
            }
            $messages[] = ['role' => 'assistant', 'content' => $this->replay($message->content)];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new AiUnavailable('L\'assistant n\'a pas trouvé de réponse. Reformulez la question.', $input, $output);
    }

    /**
     * Réponse du modèle renvoyée au tour suivant, bloc par bloc (réflexion, appels d'outils, texte).
     * Le SDK envoie un paramètre d'outil vide comme une liste ([]) : chaque outil a donc au moins un paramètre
     * obligatoire.
     *
     * @param  iterable<object>  $blocks
     * @return list<array<string, mixed>>
     */
    private function replay(iterable $blocks): array
    {
        $replayed = [];
        foreach ($blocks as $block) {
            $replayed[] = match ($block->type) {
                'thinking' => ['type' => 'thinking', 'thinking' => $block->thinking, 'signature' => $block->signature],
                'redacted_thinking' => ['type' => 'redacted_thinking', 'data' => $block->data],
                'tool_use' => ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input],
                default => ['type' => 'text', 'text' => (string) ($block->text ?? '')],
            };
        }

        return $replayed;
    }
}
