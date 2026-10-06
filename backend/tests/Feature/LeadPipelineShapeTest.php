<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\CRM\Models\Lead;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VIZ-LEADS-001 — the pipeline's SHAPE, which the leads list could not be asked for.
 *
 * `/leads` returns a page of rows and two counts. «How many are sitting at proposal» is a question
 * about the whole pipeline, and a client that answered it by counting the fifteen rows it was given
 * would be reporting its page as the estate — a funnel that changes when you turn the page.
 *
 * So the server groups it, over the reader's own visibility, and three properties keep it honest:
 *
 *   1. Every stage of `LEAD_STATUSES` appears, including the empty ones. A funnel that omits its
 *      empty stages is not a funnel: «qualified → negotiation» hides the proposal step entirely and
 *      reads as though nothing is stuck there, when what it says is that nothing GOT there.
 *   2. The stages describe the list's scope with ONE deliberate exception — the status filter
 *      itself, which is excluded because a pipeline narrowed to one stage is not a pipeline. The
 *      response says so, so the two figures are never mistaken for the same scope.
 *   3. A reader who cannot see a row cannot count it. The grouping runs through `LeadVisibility`,
 *      the same gate the rows do — otherwise the shape of the pipeline is one `curl` away for
 *      somebody entitled to none of it.
 */
final class LeadPipelineShapeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'lps-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->projectId = (string) Str::uuid();

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'lps-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    public function test_the_response_carries_one_entry_per_stage(): void
    {
        $this->lead('A', 'new');
        $this->lead('B', 'new');
        $this->lead('C', 'qualified');

        $stages = $this->list()['meta']['stages'];

        $this->assertSame(2, collect($stages)->firstWhere('status', 'new')['count']);
        $this->assertSame(1, collect($stages)->firstWhere('status', 'qualified')['count']);
    }

    /** An empty stage is a FACT about the pipeline — «nothing reached proposal» — not a missing row. */
    public function test_a_stage_nobody_has_reached_is_reported_as_zero_rather_than_omitted(): void
    {
        $this->lead('A', 'new');

        $stages = collect($this->list()['meta']['stages']);

        $this->assertNotNull($stages->firstWhere('status', 'contact_attempted'));
        $this->assertSame(0, $stages->firstWhere('status', 'contact_attempted')['count']);
    }

    /**
     * The order is the pipeline's, not the database's — a funnel read out of order is not a funnel.
     *
     * `rank()` gives the three terminal stages one shared rank, because they are ends rather than
     * degrees. They still need a stable position to be drawn, so they follow the enum's own
     * declaration order after the progressive stages.
     */
    public function test_the_stages_arrive_in_pipeline_order(): void
    {
        $this->lead('A', 'won');
        $this->lead('B', 'new');

        $order = array_column($this->list()['meta']['stages'], 'status');

        $this->assertSame(
            ['new', 'assigned', 'contact_attempted', 'contacted', 'qualified', 'appointment', 'won', 'lost', 'invalid'],
            $order,
        );
    }

    public function test_a_source_filter_narrows_the_shape_the_same_way_it_narrows_the_list(): void
    {
        $this->lead('A', 'new', source: 'website');
        $this->lead('B', 'new', source: 'referral');

        $stages = collect($this->list(['source' => 'website'])['meta']['stages']);

        $this->assertSame(1, $stages->firstWhere('status', 'new')['count']);
    }

    /**
     * The status filter is the ONE thing the shape ignores, and it says so.
     *
     * Narrowing the pipeline to the stage the reader is looking at leaves a «funnel» of one bar,
     * which answers nothing. The flag is what stops the two figures being read as one scope.
     */
    public function test_a_status_filter_narrows_the_list_but_not_the_shape_and_the_response_admits_it(): void
    {
        $this->lead('A', 'new');
        $this->lead('B', 'qualified');

        $body = $this->list(['status' => 'new']);

        $this->assertCount(1, $body['data']);
        $this->assertTrue($body['meta']['stages_ignore_status_filter']);
        $this->assertSame(1, collect($body['meta']['stages'])->firstWhere('status', 'qualified')['count']);
    }

    public function test_the_flag_is_false_when_no_status_filter_is_in_play(): void
    {
        $this->lead('A', 'new');

        $this->assertFalse($this->list()['meta']['stages_ignore_status_filter']);
    }

    /** Duplicates are arrivals. The shape counts them, like `received` does, and not like `unique`. */
    public function test_the_shape_counts_arrivals_rather_than_people(): void
    {
        $first = $this->lead('نورة', 'new', email: 'noura@example.com');
        $dupe = $this->lead('نورة', 'new', email: 'noura@example.com');
        $dupe->forceFill(['canonical_lead_id' => $first->getKey(), 'duplicate_reason' => 'email'])->save();

        $body = $this->list();

        $this->assertSame(2, collect($body['meta']['stages'])->firstWhere('status', 'new')['count']);
        $this->assertSame(['received' => 2, 'unique' => 1], $body['meta']['counts']);
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function list(array $query = []): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/leads?'.http_build_query($query + ['per_page' => 100]))
            ->assertOk()
            ->json();
    }

    private function lead(string $name, string $status, string $source = 'website', ?string $email = null): Lead
    {
        $lead = new Lead;
        $lead->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->projectId,
            'name' => $name,
            'email' => $email,
            'email_normalized' => $email === null ? null : strtolower(trim($email)),
            'status' => $status,
            'source' => $source,
        ])->save();

        return $lead;
    }
}
