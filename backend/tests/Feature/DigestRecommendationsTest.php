<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\CampaignAnnotation;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Services\DigestRecommendations;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EMAIL-SETTINGS-DEPTH-001 — the recommendations toggle, and what a digest may quote.
 *
 * Two things are being protected here, and they fail in opposite directions:
 *
 *   1. **Nothing goes out that was not asked for.** The digest may not begin carrying a colleague's
 *      approved judgement to somebody who never opted in, and an unapproved one to anybody at all.
 *   2. **What was asked for actually arrives.** `CampaignAnnotation` is tenant-scoped by a global
 *      scope that FAILS CLOSED — no resolved tenant means `1 = 0`. The digest sweep is a console
 *      command that passes the tenant as an argument and never sets that context, so a query trusting
 *      the scope returns nothing on every real send while every request-scoped test passes. That
 *      combination is why the no-context test below exists.
 */
final class DigestRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'R', 'slug' => 'rec-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'project_id' => $this->project->id, 'name' => 'C', 'objective' => 'sales', 'status' => 'active',
        ]);
        $this->user = User::create(['name' => 'R', 'email' => 'r-'.uniqid().'@rec.test', 'password' => 'secret123']);
    }

    private function annotation(array $over = []): CampaignAnnotation
    {
        return CampaignAnnotation::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $this->campaign->id,
            'kind' => 'recommendation',
            'status' => 'approved',
            'title' => 'Raise the budget on the top ad set',
            'body' => 'It is the only one under target cost.',
        ], $over));
    }

    private function window(): array
    {
        return [Carbon::now()->subDay(), Carbon::now()->addDay()];
    }

    /**
     * The service answers from its arguments, not from state somebody set three frames up.
     *
     * `DigestDispatcher` does set the tenant context, so this is not repairing a live defect. What it
     * pins is that this section cannot BECOME silently empty if that ever stops happening — the
     * failure would be indistinguishable from «this project has no recommendations», which is the
     * kind of break that survives a green suite.
     */
    public function test_it_reads_without_an_ambient_tenant_context(): void
    {
        $this->annotation();

        app(TenantContext::class)->setTenantId(null);

        [$from, $to] = $this->window();
        $rows = app(DigestRecommendations::class)->forProject($this->tenant->id, $this->project->id, $from, $to);

        $this->assertCount(1, $rows, 'The section is only populated when somebody else set the context first.');
    }

    /** Only `approved`. A draft is a judgement its author had not finished making. */
    public function test_it_carries_only_approved_recommendations(): void
    {
        $this->annotation(['status' => 'approved', 'title' => 'Approved']);
        $this->annotation(['status' => 'draft', 'title' => 'Draft']);
        $this->annotation(['status' => 'reviewed', 'title' => 'Reviewed']);
        $this->annotation(['status' => 'rejected', 'title' => 'Rejected']);
        $this->annotation(['status' => 'hidden', 'title' => 'Hidden']);

        [$from, $to] = $this->window();
        $titles = array_column(app(DigestRecommendations::class)->forProject($this->tenant->id, $this->project->id, $from, $to), 'title');

        $this->assertSame(['Approved'], $titles);
    }

    /** A note is not a recommendation, and the digest quotes only the latter. */
    public function test_a_note_is_not_quoted_as_a_recommendation(): void
    {
        $this->annotation(['kind' => 'note', 'title' => 'Just a note']);

        [$from, $to] = $this->window();

        $this->assertSame([], app(DigestRecommendations::class)->forProject($this->tenant->id, $this->project->id, $from, $to));
    }

    /** Another tenant's approved recommendation is not this tenant's to quote. */
    public function test_it_never_crosses_a_tenant(): void
    {
        $other = Tenant::create(['name' => 'O', 'slug' => 'rec-o-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $otherWorkspace = ClientWorkspace::create(['name' => 'OW', 'slug' => 'ow-'.uniqid(), 'mode' => 'managed']);
        $otherProject = Project::create(['client_workspace_id' => $otherWorkspace->id, 'name' => 'OP', 'status' => 'active']);
        $otherCampaign = UnifiedCampaign::create([
            'tenant_id' => $other->id, 'client_workspace_id' => $otherWorkspace->id,
            'project_id' => $otherProject->id, 'name' => 'OC', 'objective' => 'sales', 'status' => 'active',
        ]);
        CampaignAnnotation::create([
            'tenant_id' => $other->id, 'project_id' => $otherProject->id, 'campaign_id' => $otherCampaign->id,
            'kind' => 'recommendation', 'status' => 'approved', 'title' => 'Theirs',
        ]);

        app(TenantContext::class)->setTenantId(null);

        [$from, $to] = $this->window();

        $this->assertSame([], app(DigestRecommendations::class)->forProject($this->tenant->id, $this->project->id, $from, $to));
        $this->assertSame(['Theirs'], array_column(
            app(DigestRecommendations::class)->forProject($other->id, $otherProject->id, $from, $to), 'title',
        ));
    }

    /** Bounded by the digest's own window, so a backlog does not reappear in every email forever. */
    public function test_it_is_bounded_by_the_window_the_digest_covers(): void
    {
        $old = $this->annotation(['title' => 'Last month']);
        $old->forceFill(['created_at' => Carbon::now()->subDays(40)])->saveQuietly();
        $this->annotation(['title' => 'This week']);

        [$from, $to] = $this->window();
        $titles = array_column(app(DigestRecommendations::class)->forProject($this->tenant->id, $this->project->id, $from, $to), 'title');

        $this->assertSame(['This week'], $titles);
    }

    /** An opt-in that defaults to on is not an opt-in. */
    public function test_the_toggle_is_off_until_somebody_stores_a_true(): void
    {
        $svc = app(DigestRecommendations::class);

        $this->assertFalse($svc->enabledFor($this->user, $this->tenant->id), 'No row at all read as consent.');

        $this->prefs(['daily' => true]);
        $this->assertFalse($svc->enabledFor($this->user, $this->tenant->id), 'A map without the key read as consent.');

        $this->prefs(['daily' => true, 'recommendations' => false]);
        $this->assertFalse($svc->enabledFor($this->user, $this->tenant->id));

        $this->prefs(['daily' => true, 'recommendations' => true]);
        $this->assertTrue($svc->enabledFor($this->user, $this->tenant->id));
    }

    private function prefs(array $digests): void
    {
        DB::table('notification_preferences')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'client_workspace_id' => null],
            [
                'id' => (string) Str::uuid(),
                'channels' => json_encode(['in_app' => true, 'email' => true]),
                'categories' => json_encode([]),
                'digests' => json_encode($digests),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}
