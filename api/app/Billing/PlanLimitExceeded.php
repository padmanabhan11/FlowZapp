<?php

declare(strict_types=1);

namespace App\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** 429 with code plan_limit_exceeded and the counter in details (05 "Rate & plan limits"). Rendered by bootstrap/app.php. */
final class PlanLimitExceeded extends HttpException
{
    public function __construct(public readonly string $plan, public readonly string $limit, public readonly int $max, public readonly int|float $used, string $message)
    {
        parent::__construct(429, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return ['limit' => $this->limit, 'max' => $this->max, 'used' => $this->used, 'plan' => $this->plan];
    }
}
