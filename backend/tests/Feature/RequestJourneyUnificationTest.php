<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Journey\RequestStage;
use App\Domains\Requests\Journey\StageStatusMap;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Services\RequestJourneyService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REQ-UNIFY-001 — the stage and the status can no longer disagree.
 *
 * A request carried two independent models of the same journey and only one of them moved. The board,
 * the inbox counts and the client's progress bar all read the STATUS, so advancing the stage was
 * invisible to every reader — a request could be «paid» internally and «under review» on screen.
 */
final class RequestJourneyUnificationTest extends TestCase
{
    use RefreshDatabase;

    private ExternalRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $this->request = ExternalRequest::create([
            'tenant_id' => $tenant->id,
            'reference' => 'REQ-UNIFY-1',
            'module' => 'paid_media',
            'source' => 'public_portal',
            'type_id' => DB::table('request_types')->value('id'),
            'status_id' => DB::table('request_statuses')->where('key', 'new')->value('id'),
            'journey_stage' => RequestStage::Submitted->value,
            'priority' => 'medium',
            'contact_name' => 'Guest',
            'contact_email' => 'g@example.test',
        ]);
    }

    private function statusKey(): string
    {
        return (string) DB::table('request_statuses')
            ->where('id', $this->request->fresh()->status_id)
            ->value('key');
    }

    public function test_advancing_the_stage_moves_the_status_with_it(): void
    {
        app(RequestJourneyService::class)->transition($this->request, RequestStage::UnderReview);

        $this->assertSame('under_review', $this->statusKey());
    }

    /**
     * The compression that makes the short list short: three internal stages, one thing the reader
     * needs to know — a price has gone out and we are waiting.
     */
    public function test_the_quote_stages_all_read_as_one_status(): void
    {
        $service = app(RequestJourneyService::class);
        $service->transition($this->request, RequestStage::UnderReview);
        $service->transition($this->request, RequestStage::Qualified);
        $service->transition($this->request, RequestStage::ProposalSent);
        $this->assertSame('quoted', $this->statusKey());

        $service->transition($this->request, RequestStage::AwaitingClientApproval);
        $this->assertSame('quoted', $this->statusKey(), 'awaiting approval should still read as «quote sent»');

        $service->transition($this->request, RequestStage::PaymentPending);
        $this->assertSame('quoted', $this->statusKey());
    }

    public function test_the_whole_journey_lands_on_the_right_statuses(): void
    {
        $service = app(RequestJourneyService::class);
        $expected = [
            [RequestStage::UnderReview, 'under_review'],
            [RequestStage::Qualified, 'qualified'],
            [RequestStage::ProposalSent, 'quoted'],
            [RequestStage::AwaitingClientApproval, 'quoted'],
            [RequestStage::PaymentPending, 'quoted'],
            [RequestStage::Paid, 'approved'],
            [RequestStage::Onboarding, 'approved'],
            [RequestStage::InProgress, 'in_progress'],
            [RequestStage::ClientReview, 'delivered'],
            [RequestStage::Completed, 'completed'],
        ];

        foreach ($expected as [$stage, $status]) {
            $service->transition($this->request, $stage);
            $this->assertSame($status, $this->statusKey(), "stage {$stage->value} did not land on {$status}");
        }
    }

    public function test_a_hold_and_its_resume_are_reflected_in_the_status(): void
    {
        $service = app(RequestJourneyService::class);
        $service->transition($this->request, RequestStage::UnderReview);
        $service->transition($this->request, RequestStage::OnHold, reason: 'client unreachable');
        $this->assertSame('on_hold', $this->statusKey());

        $service->transition($this->request, RequestStage::UnderReview);
        $this->assertSame('under_review', $this->statusKey(), 'resuming left the status on hold');
    }

    /**
     * Every stage must have a decided meaning.
     *
     * This is the test that stops the two models drifting apart again: adding a stage without deciding
     * what the reader should see fails here rather than silently leaving the status behind.
     */
    public function test_no_stage_was_left_without_a_status(): void
    {
        $undecided = [];
        foreach (RequestStage::cases() as $stage) {
            if (StageStatusMap::statusFor($stage) === null) {
                $undecided[] = $stage->value;
            }
        }

        $this->assertSame([], $undecided, 'these stages have no status — the board would not move for them');
    }

    /** And every status the map names must actually exist in the catalogue. */
    public function test_every_mapped_status_exists(): void
    {
        $known = DB::table('request_statuses')->pluck('key')->all();

        foreach (array_unique(array_values(StageStatusMap::all())) as $key) {
            $this->assertContains($key, $known, "the map points at «{$key}», which is not a real status");
        }
    }
}
