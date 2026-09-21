<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Laravel's database session row (global table, no tenant — a session belongs
 * to a person). Read-only view for S24 "Active sessions"; revocation deletes.
 */
class UserSession extends Model
{
    protected $table = 'sessions';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];
}
