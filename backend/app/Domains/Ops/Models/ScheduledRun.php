<?php

declare(strict_types=1);

namespace App\Domains\Ops\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One execution of one scheduled command. Platform-scoped: the scheduler belongs to no tenant.
 */
final class ScheduledRun extends Model
{
    use HasUuids;

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** Not a failure. `withoutOverlapping` refusing a second copy is the guard working. */
    public const SKIPPED = 'skipped';

    protected $table = 'scheduled_runs';

    protected $fillable = [
        'command', 'started_at', 'finished_at', 'duration_ms',
        'outcome', 'exit_code', 'failure_class', 'failure_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
