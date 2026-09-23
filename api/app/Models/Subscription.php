<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The billed plan of a workspace (K2; doc 04 "subscriptions"). Read through
 * App\Billing\Plans, which creates the row on first use.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $tier
 * @property int|null $seats
 * @property string $status
 * @property string|null $provider
 * @property string|null $provider_customer_id
 * @property string|null $provider_sub_id
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property string|null $scheduled_tier
 * @property int|null $scheduled_seats
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Subscription extends TenantModel
{
    public const TIERS = ['free', 'pro', 'team'];

    public const STATUSES = ['active', 'trialing', 'past_due', 'canceled'];

    protected $fillable = ['tier', 'seats', 'status', 'provider', 'provider_customer_id', 'provider_sub_id', 'current_period_end', 'cancel_at_period_end', 'scheduled_tier', 'scheduled_seats', 'scheduled_at'];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'scheduled_seats' => 'integer', 'cancel_at_period_end' => 'boolean', 'current_period_end' => 'datetime', 'scheduled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
