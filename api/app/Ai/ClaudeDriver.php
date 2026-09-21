<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Http;

/** Anthropic Messages API. Zero data retention is configured at the account level (03 §11). */
final class ClaudeDriver implements LlmDriver
{
    /** USD per million tokens — update with the price sheet. */
    private const PRICE = ['in' => 3.00, 'out' => 15.00];

    public function __construct(private readonly string $apiKey, private readonly string $model = 'claude-sonnet-4-5') {}

    public function complete(string $system, string $user, int $maxTokens = 4096, float $temperature = 0.0): array
    {
        $res = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'])
            ->timeout(300)->retry(2, 2000)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model, 'max_tokens' => $maxTokens, 'temperature' => $temperature,
                'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]],
            ])->throw()->json();

        $text = implode('', array_map(fn ($b) => ($b['type'] ?? '') === 'text' ? $b['text'] : '', $res['content'] ?? []));
        $in = (int) ($res['usage']['input_tokens'] ?? 0);
        $out = (int) ($res['usage']['output_tokens'] ?? 0);

        return ['text' => $text, 'input_tokens' => $in, 'output_tokens' => $out,
            'cost_usd' => round($in / 1e6 * self::PRICE['in'] + $out / 1e6 * self::PRICE['out'], 5), 'model' => $this->model];
    }
}
