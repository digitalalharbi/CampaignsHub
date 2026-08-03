<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Services\RequestStatusMachine;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-JOURNEY-001 — «عرض» and «تسليم» exist, and adding them broke nothing.
 *
 * The second half is the point. Inserting a step into a live workflow is the change most likely to
 * strand work in progress: every request already sitting on `qualified` was put there by somebody who
 * expected `approved` to be reachable, and a journey that quietly stopped allowing that would look
 * like the product had lost their request. So the direct paths are asserted to survive alongside the
 * new ones — not because a small request never needs a quote, but because most of them do not.
 */
final class RequestJourneyStagesTest extends TestCase
{
    use RefreshDatabase;

    private RequestStatusMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->machine = app(RequestStatusMachine::class);
    }

    public function test_the_new_stages_exist_with_arabic_names(): void
    {
        $this->assertSame('عرض سعر مُرسل', RequestStatus::where('key', 'quoted')->value('name_ar'));
        $this->assertSame('تم التسليم', RequestStatus::where('key', 'delivered')->value('name_ar'));
        $this->assertSame('معلّق', RequestStatus::where('key', 'on_hold')->value('name_ar'));
    }

    public function test_the_journey_the_brief_describes_is_walkable_end_to_end(): void
    {
        $journey = ['new', 'under_review', 'qualified', 'quoted', 'approved', 'in_progress', 'delivered', 'completed'];

        for ($i = 0; $i < count($journey) - 1; $i++) {
            $this->assertTrue(
                $this->machine->canTransition($journey[$i], $journey[$i + 1]),
                "the journey breaks between {$journey[$i]} and {$journey[$i + 1]}",
            );
        }
    }

    /** The shortcuts that existed before must still exist — see the note at the top of this file. */
    public function test_the_direct_paths_still_work_for_requests_that_need_no_quote(): void
    {
        $this->assertTrue($this->machine->canTransition('qualified', 'approved'));
        $this->assertTrue($this->machine->canTransition('in_progress', 'completed'));
    }

    /** A hold returns to where the work was, never to the top of the inbox. */
    public function test_a_held_request_resumes_where_it_paused(): void
    {
        foreach (['under_review', 'qualified', 'quoted', 'approved', 'in_progress'] as $resume) {
            $this->assertTrue($this->machine->canTransition('on_hold', $resume), "cannot resume to {$resume}");
        }

        $this->assertFalse(
            $this->machine->canTransition('on_hold', 'new'),
            'resuming to «new» would discard every decision already made about the request',
        );
    }

    /** Both pauses stop the SLA clock — that is what makes them pauses rather than states. */
    public function test_a_hold_stops_the_sla_clock(): void
    {
        $this->assertTrue((bool) RequestStatus::where('key', 'on_hold')->value('pauses_sla'));
        $this->assertTrue((bool) RequestStatus::where('key', 'waiting_client')->value('pauses_sla'));
    }

    /** The illogical jump the machine has always refused stays refused. */
    public function test_a_request_still_cannot_jump_the_journey(): void
    {
        $this->assertFalse($this->machine->canTransition('new', 'completed'));
        $this->assertFalse($this->machine->canTransition('new', 'delivered'));
        $this->assertFalse($this->machine->canTransition('quoted', 'completed'));
    }
}
