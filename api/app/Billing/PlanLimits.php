<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Plan limits from 05-API-Specification "Rate & plan limits" and the pricing
 * decision of 18 Sep 2026 (Free: 5 generations, 30 recording minutes / month).
 * null means unlimited.
 */
final class PlanLimits
{
    /** @var array<string, array<string, int|null>> */
    private const LIMITS = [
        'free' => ['seats' => 3,    'documents' => 50,   'sop_generations' => 5,   'recording_minutes' => 30,   'chat_queries_per_day' => 0,   'api_per_min' => 60],
        'pro'  => ['seats' => null, 'documents' => null, 'sop_generations' => 100, 'recording_minutes' => 600,  'chat_queries_per_day' => 0,   'api_per_min' => 120],
        'team' => ['seats' => 10,   'documents' => null, 'sop_generations' => 500, 'recording_minutes' => 3000, 'chat_queries_per_day' => 500, 'api_per_min' => 300],
    ];

    public static function for(string $plan, string $limit): ?int
    {
        return self::LIMITS[$plan][$limit] ?? throw new \InvalidArgumentException("Unknown plan/limit $plan/$limit");
    }

    /** @return array<string, int|null> */
    public static function all(string $plan): array
    {
        return self::LIMITS[$plan] ?? throw new \InvalidArgumentException("Unknown plan $plan");
    }
}
