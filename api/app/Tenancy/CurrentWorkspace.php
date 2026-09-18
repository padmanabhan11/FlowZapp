<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

/**
 * The single source of the current tenant for a request or job.
 *
 * Resolved once per request by ResolveWorkspace middleware (from the session
 * plus the X-Workspace-Id header) and set explicitly by queued jobs. Never set
 * from a request payload: a workspace_id arriving in a body is ignored.
 *
 * See 03-Technical-Architecture §4 — this object plus TenantScope is the
 * replacement for the Postgres row-level security the v1.0 design relied on.
 */
final class CurrentWorkspace
{
    private ?string $id = null;

    public function set(?string $workspaceId): void
    {
        $this->id = $workspaceId;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function isSet(): bool
    {
        return $this->id !== null;
    }

    /**
     * The id, or an exception. Use this on every write path so that a job or
     * request that forgot to resolve its tenant fails loudly rather than
     * writing an unscoped row.
     */
    public function require(): string
    {
        if ($this->id === null) {
            throw new RuntimeException(
                'No current workspace. Tenant-scoped reads and writes need a resolved workspace '
                .'(ResolveWorkspace middleware, or CurrentWorkspace::set() in a job).'
            );
        }

        return $this->id;
    }

    /**
     * Run a callback as a given workspace, restoring the previous one after.
     * Jobs and console commands use this; controllers never need to.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(string $workspaceId, callable $callback): mixed
    {
        $previous = $this->id;
        $this->id = $workspaceId;
        try {
            return $callback();
        } finally {
            $this->id = $previous;
        }
    }
}
