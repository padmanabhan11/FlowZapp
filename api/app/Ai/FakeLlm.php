<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Scripted responses for tests (LLM_DRIVER=fake). Each prompt kind is
 * recognised by a marker the prompt classes put in the system text.
 */
final class FakeLlm implements LlmDriver
{
    /**
     * @var array<string, string> marker => JSON
     */
    public static array $responses = [];

    public function complete(string $system, string $user, int $maxTokens = 4096, float $temperature = 0.0): array
    {
        $text = '{}';
        foreach (self::$responses as $marker => $json) {
            if (str_contains($system, $marker)) {
                $text = $json;
                break;
            }
        }

        return ['text' => $text, 'input_tokens' => 100, 'output_tokens' => 50, 'cost_usd' => 0.0, 'model' => 'fake'];
    }
}
