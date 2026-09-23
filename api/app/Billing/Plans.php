<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\Audit;
use App\Models\Document;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plan changes and seat handling (K2), independent of the payment provider.
 *
 * Rules (05 "Rate & plan limits", pricing decision 18 Sep 2026):
 *  - Free 3 seats / 50 documents, Pro unlimited seats, Team 10 seats
 *    (more than 10 is an open decision, 03 §12, so it is refused, not decided);
 *  - an upgrade, or more seats, applies at once — the provider prorates;
 *  - a downgrade, or fewer seats, is scheduled for the end of the paid
 *    period and applied then (billing:apply-scheduled, or the provider's
 *    subscription.updated webhook, whichever comes first);
 *  - a change is refused while it would put the workspace over the new
 *    limits (members over seats, documents over the cap), with the counter
 *    in the message, so a downgrade never strands content (S22 rule: a
 *    clear message, not a silent failure);
 *  - workspaces.plan is the effective plan and is written here only.
 */
final class Plans
{
    private const RANK = ['free' => 0, 'pro' => 1, 'team' => 2];

    public function __construct(private readonly CurrentWorkspace $current, private readonly BillingProvider $provider) {}

    /** The workspace's subscription row, created from its current plan on first use. */
    public function current(?Workspace $ws = null): Subscription
    {
        $ws ??= Workspace::query()->findOrFail($this->current->require());

        return $this->current->runAs($ws->id, fn () => Subscription::query()->firstOrCreate([], ['tier' => $ws->plan, 'seats' => self::defaultSeats($ws->plan)]));
    }

    public static function defaultSeats(string $tier): ?int
    {
        return PlanLimits::for($tier, 'seats');
    }

    /** Seat cap that invites and the usage screen enforce: the subscription's, falling back to the tier default. */
    public function seatLimit(Workspace $ws): ?int
    {
        $sub = $this->current($ws);

        return $sub->tier === $ws->plan ? $sub->seats : self::defaultSeats($ws->plan);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(Workspace $ws): array
    {
        $sub = $this->current($ws);

        return [
            'tier' => $sub->tier, 'seats' => $sub->seats, 'status' => $sub->status,
            'current_period_end' => $sub->current_period_end?->toIso8601String(), 'cancel_at_period_end' => $sub->cancel_at_period_end,
            'scheduled' => $sub->scheduled_tier === null ? null : ['tier' => $sub->scheduled_tier, 'seats' => $sub->scheduled_seats, 'at' => $sub->scheduled_at?->toIso8601String()],
            'provider' => $this->provider->connected() ? $this->provider->name() : null,
            'has_payment_method' => $sub->provider_sub_id !== null,
            'max_team_seats' => PlanLimits::for('team', 'seats'),
        ];
    }

    /**
     * Admin-initiated change. Returns the state, or a checkout URL to
     * complete first when the workspace has never paid.
     *
     * @return array{state: array<string, mixed>, checkout_url: ?string}
     */
    public function change(Workspace $ws, string $tier, ?int $seats, User $actor, string $returnUrl): array
    {
        if (! isset(self::RANK[$tier])) {
            throw new HttpException(422, 'Unknown plan.');
        }
        $seats = $tier === 'team' ? ($seats ?? self::defaultSeats('team')) : self::defaultSeats($tier);
        $sub = $this->current($ws);
        $this->assertFits($ws, $tier, $seats);

        if ($tier === $sub->tier && $seats === $sub->seats) {
            $this->clearScheduled($sub);

            return ['state' => $this->state($ws), 'checkout_url' => null];
        }

        if ($tier !== 'free' && $sub->provider_sub_id === null) {
            // Never paid: the provider's checkout creates the subscription; its webhook then lands it here.
            return ['state' => $this->state($ws), 'checkout_url' => $this->provider->checkoutUrl($ws, $tier, $seats, $returnUrl)];
        }

        $upgrade = self::RANK[$tier] > self::RANK[$sub->tier] || ($tier === $sub->tier && ($seats === null || ($sub->seats !== null && $seats > $sub->seats)));
        if ($upgrade) {
            if ($sub->provider_sub_id !== null) {
                $this->provider->changePlan($sub, $tier, $seats);
            }
            $this->apply($ws, $sub, $tier, $seats, $actor->id, 'upgrade');
        } else {
            $at = $sub->current_period_end ?? now();
            $sub->forceFill(['scheduled_tier' => $tier, 'scheduled_seats' => $seats, 'scheduled_at' => $at])->save();
            if ($sub->provider_sub_id !== null) {
                $this->provider->changePlan($sub, $tier, $seats);   // the provider applies it at period end too
            }
            Audit::record('billing.downgrade_scheduled', 'workspace', $ws->id, ['to' => $tier, 'seats' => $seats, 'at' => $at->toIso8601String(), 'by' => $actor->id]);
            if ($at->lte(now())) {
                $this->applyScheduled($sub);
            }
        }

        return ['state' => $this->state($ws), 'checkout_url' => null];
    }

    public function cancelScheduled(Workspace $ws, User $actor): void
    {
        $sub = $this->current($ws);
        if ($sub->scheduled_tier !== null) {
            $this->clearScheduled($sub);
            if ($sub->provider_sub_id !== null) {
                $this->provider->changePlan($sub, $sub->tier, $sub->seats);
            }
            Audit::record('billing.downgrade_cancelled', 'workspace', $ws->id, ['by' => $actor->id]);
        }
    }

    /** Applies a due scheduled change (billing:apply-scheduled). */
    public function applyScheduled(Subscription $sub): bool
    {
        if ($sub->scheduled_tier === null || $sub->scheduled_at === null || $sub->scheduled_at->gt(now())) {
            return false;
        }
        $ws = Workspace::query()->findOrFail($sub->workspace_id);
        $this->apply($ws, $sub, $sub->scheduled_tier, $sub->scheduled_seats, null, 'scheduled');

        return true;
    }

    /**
     * Provider truth arriving by webhook: creates or updates the subscription
     * and the effective plan. Runs outside any request workspace, so the
     * row is found by provider ids (or the workspace id the checkout carried).
     *
     * @param  array{type: string, provider_customer_id: ?string, provider_sub_id: ?string, tier: ?string, seats: ?int, status: ?string, current_period_end: ?string, workspace_id: ?string}  $event
     */
    public function applyWebhook(array $event): ?Subscription
    {
        $q = Subscription::withoutGlobalScopes(); // allowlisted: webhooks carry no workspace; the row is looked up by provider id
        $sub = null;
        if ($event['provider_sub_id']) {
            $sub = (clone $q)->where('provider', $this->provider->name())->where('provider_sub_id', $event['provider_sub_id'])->first();
        }
        if ($sub === null && $event['workspace_id']) {
            $ws = Workspace::query()->find($event['workspace_id']);
            $sub = $ws ? $this->current($ws) : null;
        }
        if ($sub === null) {
            return null;
        }
        $ws = Workspace::query()->findOrFail($sub->workspace_id);

        return $this->current->runAs($ws->id, function () use ($sub, $ws, $event): Subscription {
            $fill = ['provider' => $this->provider->name()];
            foreach (['provider_customer_id', 'provider_sub_id'] as $k) {
                if ($event[$k]) {
                    $fill[$k] = $event[$k];
                }
            }
            if ($event['current_period_end']) {
                $fill['current_period_end'] = Carbon::parse($event['current_period_end']);
            }
            if ($event['status'] && in_array($event['status'], Subscription::STATUSES, true)) {
                $fill['status'] = $event['status'];
            }
            $sub->forceFill($fill)->save();

            if ($event['type'] === 'subscription.canceled') {
                $this->apply($ws, $sub, 'free', self::defaultSeats('free'), null, 'provider_canceled', force: true);
            } elseif ($event['type'] === 'subscription.updated' && $event['tier'] && isset(self::RANK[$event['tier']])) {
                $seats = $event['tier'] === 'team' ? ($event['seats'] ?? $sub->seats ?? self::defaultSeats('team')) : self::defaultSeats($event['tier']);
                if ($event['tier'] !== $sub->tier || $seats !== $sub->seats) {
                    $this->apply($ws, $sub, $event['tier'], $seats, null, 'provider', force: true);
                }
            } elseif ($event['type'] === 'payment.failed') {
                $sub->forceFill(['status' => 'past_due'])->save();
                Audit::record('billing.payment_failed', 'workspace', $ws->id);
                Log::channel('alerts')->warning('billing.payment_failed', ['workspace_id' => $ws->id, 'provider_sub_id' => $sub->provider_sub_id]);
            }

            return $sub->refresh();
        });
    }

    /** Refuses a plan the workspace would immediately exceed. */
    private function assertFits(Workspace $ws, string $tier, ?int $seats): void
    {
        $maxTeam = PlanLimits::for('team', 'seats');
        if ($tier === 'team' && $seats !== null && $maxTeam !== null && $seats > $maxTeam) {
            throw new HttpException(422, "Team includes up to {$maxTeam} seats. Contact FlowZapp support for more.");
        }
        if ($seats !== null && $seats < 1) {
            throw new HttpException(422, 'A plan needs at least one seat.');
        }
        $this->current->runAs($ws->id, function () use ($tier, $seats): void {
            $members = WorkspaceMember::query()->count();
            if ($seats !== null && $members > $seats) {
                throw new HttpException(422, "This workspace has {$members} members and the plan you chose includes {$seats} seats. Remove members first.");
            }
            $docCap = PlanLimits::for($tier, 'documents');
            $docs = Document::query()->count();
            if ($docCap !== null && $docs > $docCap) {
                throw new HttpException(422, "This workspace has {$docs} documents and ".ucfirst($tier)." is capped at {$docCap}. Archive or delete documents first.");
            }
        });
    }

    private function apply(Workspace $ws, Subscription $sub, string $tier, ?int $seats, ?string $actorId, string $reason, bool $force = false): void
    {
        if (! $force) {
            $this->assertFits($ws, $tier, $seats);
        }
        DB::transaction(function () use ($ws, $sub, $tier, $seats, $actorId, $reason): void {
            $from = $sub->tier;
            $sub->forceFill(['tier' => $tier, 'seats' => $seats, 'scheduled_tier' => null, 'scheduled_seats' => null, 'scheduled_at' => null])->save();
            $ws->forceFill(['plan' => $tier])->save();
            $this->current->runAs($ws->id, fn () => Audit::record('billing.plan_changed', 'workspace', $ws->id, ['from' => $from, 'to' => $tier, 'seats' => $seats, 'reason' => $reason, 'by' => $actorId]));
        });
    }

    private function clearScheduled(Subscription $sub): void
    {
        if ($sub->scheduled_tier !== null) {
            $sub->forceFill(['scheduled_tier' => null, 'scheduled_seats' => null, 'scheduled_at' => null])->save();
        }
    }
}
