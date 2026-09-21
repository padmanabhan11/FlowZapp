<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\Recording;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Current-period counters against plan limits (S22, 05 "Rate & plan limits").
 * Counters are computed from the tables rather than a separate usage_counters
 * row so they can never drift; the period is the calendar month (UTC) and
 * chat is per day. Exceeding a limit is 429 plan_limit_exceeded with the
 * counter in details, and the interface renders that message (S22 rule).
 */
final class Usage
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** @return array{plan: string, period_start: string, period_end: string, counters: array<string, array{used: int|float, max: int|null, unlimited: bool, near: bool, over: bool}>} */
    public function summary(?Workspace $ws = null): array
    {
        $ws ??= Workspace::query()->findOrFail($this->current->require());
        $limits = PlanLimits::all($ws->plan);
        $used = [
            'seats' => WorkspaceMember::query()->count(),
            'documents' => Document::query()->count(),
            'sop_generations' => PipelineJob::query()->where('stage', 'generate')->where('status', 'succeeded')->where('created_at', '>=', now()->startOfMonth())->count(),
            'recording_minutes' => round((float) Recording::query()->where('created_at', '>=', now()->startOfMonth())->whereNotIn('state', ['pending_upload'])->sum('duration_sec') / 60, 1),
            'chat_queries_per_day' => ChatMessage::query()->where('role', 'user')->where('created_at', '>=', now()->startOfDay())->count(),
        ];
        $counters = [];
        foreach ($used as $k => $v) {
            $max = $limits[$k] ?? null;
            $counters[$k] = ['used' => $v, 'max' => $max, 'unlimited' => $max === null, 'near' => $max !== null && $max > 0 && $v >= 0.8 * $max, 'over' => $max !== null && $v >= $max];
        }

        return ['plan' => $ws->plan, 'period_start' => now()->startOfMonth()->toIso8601String(), 'period_end' => now()->endOfMonth()->toIso8601String(), 'counters' => $counters];
    }

    /** Throws 429 plan_limit_exceeded when adding $amount to $limit would exceed the plan. Chat on a plan without chat is 402. */
    public function assert(string $limit, float $amount = 1): void
    {
        $ws = Workspace::query()->findOrFail($this->current->require());
        $max = PlanLimits::for($ws->plan, $limit);
        if ($max === null) {
            return;
        }
        if ($limit === 'chat_queries_per_day' && $max === 0) {
            throw new HttpException(402, 'The assistant is included in the Team plan. Upgrade to ask questions.');
        }
        $used = $this->summary($ws)['counters'][$limit]['used'];
        if ($used + $amount > $max) {
            $label = ['documents' => 'documents', 'sop_generations' => 'SOP generations a month', 'recording_minutes' => 'recording minutes a month', 'chat_queries_per_day' => 'questions a day', 'seats' => 'seats'][$limit] ?? $limit;
            throw new PlanLimitExceeded($ws->plan, $limit, $max, $used, 'Your '.ucfirst($ws->plan)." plan includes {$max} {$label}. Upgrade for more.");
        }
    }
}
