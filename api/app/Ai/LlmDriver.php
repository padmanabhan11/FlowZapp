<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Language-model driver (03 §1 "Intelligence … via driver interface").
 * Claude is the default; the interface exists so prompts never know the vendor.
 */
interface LlmDriver
{
    /**
     * @return array{text: string, input_tokens: int, output_tokens: int, cost_usd: float, model: string}
     */
    public function complete(string $system, string $user, int $maxTokens = 4096, float $temperature = 0.0): array;
}
