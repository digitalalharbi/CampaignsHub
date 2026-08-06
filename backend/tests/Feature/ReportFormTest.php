<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * REPORT-LINKS-13 — a summary and a full report of the same project, each shareable.
 *
 * Three questions had been sharing two columns. `type` says what a report is ABOUT (`campaign`,
 * `weekly`, `platform_comparison`); `mode` says whether a link recomputes or serves a snapshot; and
 * «summary or full detail» — a third, independent question — had been smuggled into both, with
 * `type = 'executive'` sitting in a list beside `campaign`, and `audience = 'executive'` doing
 * double duty as a slide filter.
 *
 * The consequence was not theoretical. A shared link ALWAYS ran the plain client filter, so a report
 * an operator had deliberately built as an executive summary arrived at the client in full detail.
 * The one setting that says «five pages, not thirty» was honoured in the PDF export and dropped on
 * the link — which is the copy most clients actually open.
 */
final class ReportFormTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Forms Co', 'slug' => 'forms-co', 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@forms.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $this->operator, tenant: $this->tenant, portal: Portal::App, role: 'owner',
        ));

        app(ProjectContext::class)->setProjectId((string) $this->project->id);
    }

    /**
     * The snapshot both forms are built from.
     *
     * `slides` carries the per-platform and funnel pages an executive summary drops, so the same
     * data can prove that one form trims and the other does not.
     */
    private function report(string $form): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => $form === 'executive_summary' ? 'ملخص تنفيذي — يوليو' : 'تقرير تفصيلي — يوليو',
            'type' => 'monthly',
            'form' => $form,
            'status' => 'completed',
            'currency' => 'SAR',
            'generated_at' => now(),
            'data' => [
                'checksum' => 'internal-only',
                'recommendations' => [
                    ['status' => 'approved', 'text' => 'زد ميزانية حملة المبيعات'],
                    ['status' => 'draft', 'text' => 'ملاحظة داخلية لم تُعتمد'],
                ],
                'slides' => [
                    ['type' => 'cover'],
                    ['type' => 'executive_summary'],
                    ['type' => 'platform_comparison'],
                    ['type' => 'budget'],
                    ['type' => 'next_steps'],
                    // Detail-only pages.
                    ['type' => 'platform_detail'],
                    ['type' => 'funnel'],
                    ['type' => 'creatives'],
                ],
            ],
        ]);
    }

    /** @return array{0: Report, 1: string} the report and the raw token of its link */
    private function shared(string $form, array $opts = []): array
    {
        $report = $this->report($form);
        [, $raw] = app(ShareService::class)->create($report, $opts, $this->operator->id);

        return [$report, $raw];
    }

    private function open(string $token): TestResponse
    {
        // No session at all — this is a client with a link and nothing else.
        return $this->getJson("/api/v1/reports/shared/{$token}");
    }

    /** Acceptance case 1 — one project, two reports, and both open with no sign-in. */
    public function test_a_project_can_have_a_summary_and_a_detailed_report_and_both_open_without_a_session(): void
    {
        [, $summaryToken] = $this->shared('executive_summary');
        [, $detailToken] = $this->shared('detailed');

        $summary = $this->open($summaryToken)->assertOk();
        $detail = $this->open($detailToken)->assertOk();

        $this->assertSame('executive_summary', $summary->json('data.form'));
        $this->assertSame('detailed', $detail->json('data.form'));
        $this->assertSame(2, Report::where('project_id', $this->project->id)->count());
    }

    /**
     * The defect this unit exists for: the link now honours the form.
     *
     * The summary drops per-platform detail, the funnel and the creatives pages; the detailed report
     * keeps every one of them.
     */
    public function test_the_summary_link_is_trimmed_and_the_detailed_link_is_not(): void
    {
        [, $summaryToken] = $this->shared('executive_summary');
        [, $detailToken] = $this->shared('detailed');

        $summarySlides = array_column((array) $this->open($summaryToken)->json('data.data.slides'), 'type');
        $detailSlides = array_column((array) $this->open($detailToken)->json('data.data.slides'), 'type');

        $this->assertSame(['cover', 'executive_summary', 'platform_comparison', 'budget', 'next_steps'], $summarySlides);
        $this->assertContains('funnel', $detailSlides);
        $this->assertContains('platform_detail', $detailSlides);
        $this->assertCount(8, $detailSlides);
    }

    /** Both forms are still client-facing: unapproved recommendations and internals never travel. */
    public function test_neither_form_leaks_internal_fields_or_unapproved_recommendations(): void
    {
        foreach (['executive_summary', 'detailed'] as $form) {
            [, $token] = $this->shared($form);
            $data = $this->open($token)->assertOk()->json('data.data');

            $this->assertArrayNotHasKey('checksum', $data, "{$form} leaked an internal field");
            $this->assertCount(1, $data['recommendations'], "{$form} sent an unapproved recommendation");
            $this->assertSame('زد ميزانية حملة المبيعات', $data['recommendations'][0]['text']);
        }
    }

    /**
     * The form is independent of live-versus-snapshot — all four combinations are real.
     *
     * A link is live only when it was given a scope to stay inside (LIVEREP-001), and that is a fact
     * about delivery. It says nothing about whether the report is five pages or thirty.
     */
    public function test_either_form_can_be_live_or_a_snapshot(): void
    {
        $scope = ['project_ids' => [(string) $this->project->id]];

        foreach (['executive_summary', 'detailed'] as $form) {
            [, $snapshotToken] = $this->shared($form);
            [, $liveToken] = $this->shared($form, ['scope' => $scope]);

            $this->assertSame('snapshot', $this->open($snapshotToken)->json('data.mode'), "{$form} snapshot");
            $this->assertSame($form, $this->open($snapshotToken)->json('data.form'));

            $this->assertSame('live', $this->open($liveToken)->json('data.mode'), "{$form} live");
            $this->assertSame($form, $this->open($liveToken)->json('data.form'));
        }
    }

    /** An unknown form is refused rather than stored — the column has exactly two meanings. */
    public function test_an_unknown_form_is_refused(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports", [
                'name' => 'Nope', 'type' => 'monthly', 'form' => 'somewhere_in_between',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('form');
    }

    /** A report that says nothing about its form is a full report — never silently trimmed. */
    public function test_a_report_that_names_no_form_is_a_detailed_one(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports", ['name' => 'Default', 'type' => 'monthly'])
            ->assertCreated();

        $this->assertSame('detailed', Report::where('name', 'Default')->value('form'));
    }

    /**
     * The form asked for is the form stored, and the form the list shows.
     *
     * This test exists because the first cut of the endpoint VALIDATED `form` and then dropped it:
     * `store()` builds its attributes explicitly and never copied it across, so every report came
     * out `detailed` whatever had been chosen. Every other test in this file passed anyway — they
     * build their fixtures with `Report::create()` and never go through the controller. Found by
     * creating one through the real endpoint in the browser and reading the row back.
     */
    public function test_the_form_survives_the_endpoint_and_is_reported_back(): void
    {
        $created = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports", [
                'name' => 'Summary through the door', 'type' => 'monthly', 'form' => 'executive_summary',
            ])
            ->assertCreated();

        $this->assertSame('executive_summary', $created->json('data.form'));
        $this->assertSame('executive_summary', Report::where('name', 'Summary through the door')->value('form'));

        $listed = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports")
            ->assertOk()
            ->json('data.reports');

        $row = collect($listed)->firstWhere('name', 'Summary through the door');
        $this->assertSame('executive_summary', $row['form']);
    }

    /** A revoked link is refused whichever form it carried. */
    public function test_a_revoked_link_is_refused_for_both_forms(): void
    {
        foreach (['executive_summary', 'detailed'] as $form) {
            $report = $this->report($form);
            [$share, $raw] = app(ShareService::class)->create($report, [], $this->operator->id);
            $share->update(['revoked_at' => now()]);

            $this->open($raw)->assertStatus(404);
        }
    }
}
