<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Reports\Support\ReportScheduleKey;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

final class ReportSchedule extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'report_id', 'name', 'type', 'frequency', 'day', 'time',
        'timezone', 'audience', 'language', 'formats', 'recipients', 'cron',
        'config', 'active', 'last_run_at', 'next_run_at', 'created_by', 'is_demo',
    ];

    /** CONS-SCHEDULE-ONE — the reason `reports:dedupe-schedules` writes on the copies it switches off. */
    public const DUPLICATE_REASON = 'duplicate_schedule';

    protected $casts = [
        'config' => 'array',
        'formats' => 'array',
        'recipients' => 'array',
        'active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'disabled_at' => 'datetime',
        'is_demo' => 'boolean',
    ];

    /**
     * CONS-SCHEDULE-ONE — a NEW row carries its key from birth, so the partial unique index refuses a second active copy.
     *
     * Only on create: an existing row is keyed by `reports:dedupe-schedules` (after its extra copies are off) or by an edit
     * in the controller, never by an unrelated save such as the dispatcher moving `next_run_at`. Keying a legacy duplicate
     * on that save would collide with its twin and break the run.
     */
    protected static function booted(): void
    {
        self::creating(static function (self $schedule): void {
            if ($schedule->schedule_key === null) {
                $schedule->schedule_key = ReportScheduleKey::for($schedule);
            }
        });
    }

    /** A copy switched off because another schedule already builds the same report: «نسخة غير مجدولة». */
    public function isDisabledDuplicate(): bool
    {
        return $this->disabled_reason === self::DUPLICATE_REASON;
    }
}
