<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Support\NextScheduledSync;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * INTEGRATION-SYNC-VISIBILITY-001 — «when will this update itself», and when not to answer.
 *
 * The card said when data LAST arrived and nothing about when more would, so the only way to tell a
 * broken integration from one merely between runs was to press «Sync now» — a real provider call
 * made by somebody who only wanted reassurance.
 *
 * The half of this worth testing is the refusal. A connection that is revoked, erroring or has no
 * bound account is still on the scheduler in the sense that the command will run; it is not going to
 * sync anything, and «next sync in 12 minutes» over one of those is the most confident kind of
 * wrong. Null is the honest answer, and the card has its own sentence for those states.
 */
final class NextScheduledSyncTest extends TestCase
{
    /** The cadence is the scheduler's own: every thirty minutes, so the next half-hour boundary. */
    public function test_it_names_the_next_half_hour_boundary(): void
    {
        $at = NextScheduledSync::at(true, Carbon::parse('2026-09-25 14:07:33'));

        $this->assertNotNull($at);
        $this->assertSame('2026-09-25 14:30:00', $at->format('Y-m-d H:i:s'));
    }

    public function test_after_the_half_hour_it_names_the_hour(): void
    {
        $at = NextScheduledSync::at(true, Carbon::parse('2026-09-25 14:31:00'));

        $this->assertSame('2026-09-25 15:00:00', $at?->format('Y-m-d H:i:s'));
    }

    /** Exactly on a boundary is BEFORE the next one, never «now». */
    public function test_on_the_boundary_it_names_the_following_run(): void
    {
        $at = NextScheduledSync::at(true, Carbon::parse('2026-09-25 14:00:00'));

        $this->assertSame('2026-09-25 14:30:00', $at?->format('Y-m-d H:i:s'));
    }

    /** It rolls the day rather than producing an impossible time. */
    public function test_it_rolls_past_midnight(): void
    {
        $at = NextScheduledSync::at(true, Carbon::parse('2026-09-25 23:45:00'));

        $this->assertSame('2026-09-26 00:00:00', $at?->format('Y-m-d H:i:s'));
    }

    /**
     * **The refusal.** An ineligible connection gets no promise.
     */
    public function test_an_ineligible_connection_is_promised_nothing(): void
    {
        $this->assertNull(NextScheduledSync::at(false, Carbon::parse('2026-09-25 14:07:00')));
    }
}
