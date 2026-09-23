<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Plans;
use App\Models\Subscription;
use Illuminate\Console\Command;

/** K2: applies downgrades whose paid period has ended (hourly; the provider webhook usually gets there first). */
final class ApplyScheduledPlanChanges extends Command
{
    protected $signature = 'billing:apply-scheduled';

    protected $description = 'Apply scheduled plan downgrades whose period end has passed';

    public function handle(Plans $plans): int
    {
        $n = 0;
        $due = Subscription::withoutGlobalScopes()->whereNotNull('scheduled_tier')->where('scheduled_at', '<=', now())->get(); // allowlisted: scheduler runs across all workspaces
        foreach ($due as $sub) {
            $n += (int) $plans->applyScheduled($sub);
        }
        $this->info("{$n} scheduled plan change(s) applied");

        return self::SUCCESS;
    }
}
