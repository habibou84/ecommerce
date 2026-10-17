<?php

namespace App\Services\Ai;

/**
 * Appel au modèle d'IA avec une réponse JSON imposée par un schéma (remplacé par un faux dans les tests).
 */
interface AiClient
{
    public function configured(): bool;

    public function model(): string;

    /**
     * @param  list<array<string, mixed>>  $content  blocs du message (texte, images)
     * @param  array<string, mixed>  $schema  schéma JSON de la réponse
     * @return array{data: array<string, mixed>, input_tokens: int, output_tokens: int}
     *
     * @throws AiUnavailable
     */
    public function json(string $system, array $content, array $schema, int $maxTokens = 8000): array;

    /**
     * Question avec des outils en lecture : le modèle appelle les outils autant que nécessaire (dans la limite
     * de $maxTurns), puis répond en texte.
     *
     * @param  list<array<string, mixed>>  $tools  définitions (name, description, inputSchema)
     * @param  callable(string, array<string, mixed>): mixed  $run  exécute un outil, renvoie un résultat sérialisable
     * @return array{text: string, input_tokens: int, output_tokens: int}
     *
     * @throws AiUnavailable
     */
    public function agent(string $system, string $question, array $tools, callable $run, int $maxTurns = 6): array;
}
