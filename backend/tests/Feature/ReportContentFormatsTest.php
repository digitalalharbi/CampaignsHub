<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CREATIVE-FORMAT-INTELLIGENCE-001 in a client link — «أداء أنواع المحتوى».
 *
 * The same canonical answer the operator reads, for this link's project and window, from the SAME
 * service. A report that computed its own would be a second answer to «هل الصور أم الفيديو؟», and the
 * first time the two disagreed nobody would know which to believe.
 *
 * What differs is what a client may be shown, which is a redaction question rather than an
 * arithmetic one: the agency's ad accounts are its own arrangement, a link that hides spend hides the
 * spend MIX, and a link that hides the creatives hides a statement about those creatives.
 */
final class ReportContentFormatsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $workspace;

    private User $operator;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'rcf-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create([
            'name' => 'Op', 'email' => 'rcf-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->workspace->getKey(), 'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(), provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'acc-'.uniqid(),
            'name' => 'Agency Meta Account', 'currency' => 'SAR', 'status' => 'active',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(), 'external_account_id' => $this->account->getKey(),
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);

        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Sales', 'status' => 'active', 'objective' => 'sales', 'platforms' => ['meta'],
        ]);

        $this->creatives($campaign, 'video', 3, 300.0, 30.0);
        $this->creatives($campaign, 'image', 3, 300.0, 10.0);
    }

    /** The client's report carries the comparison, decided the same way the operator's is. */
    public function test_the_client_link_carries_the_format_comparison(): void
    {
        $formats = $this->payload()['content_formats'];

        $this->assertIsArray($formats);
        $this->assertSame('video', $formats['objectives'][0]['comparison']['best']);
        /*
         * Three creatives a side is MODERATE, not high — the evidence ladder is counts and nothing
         * else, and asserting the rung is what keeps it from drifting into a claim.
         */
        $this->assertSame('moderate', $formats['objectives'][0]['evidence']);
    }

    /**
     * The agency's ad accounts are its own arrangement, not the client's business.
     *
     * The same reason the campaign picker was removed from a client link: it published the agency's
     * internal naming to the person the report is for.
     */
    public function test_the_client_is_never_told_which_ad_accounts_carried_the_work(): void
    {
        $formats = $this->payload()['content_formats'];

        $this->assertSame([], $formats['accounts']);
        $this->assertStringNotContainsString('Agency Meta Account', (string) json_encode($formats));
    }

    /**
     * A link that hides spend hides the spend MIX.
     *
     * A mix is spend expressed as a share, and a share of a hidden figure is that figure. The
     * comparison itself survives, because it is decided on a rate or a cost per outcome — a reading
     * about the creative rather than about the money.
     */
    public function test_hiding_spend_hides_the_spend_mix_and_keeps_the_comparison(): void
    {
        $formats = $this->payload(['hide_spend' => true])['content_formats'];

        $this->assertSame([], $formats['spend_mix']['formats']);
        $this->assertNull($formats['spend_mix']['total']);
        $this->assertFalse($formats['spend_mix']['complete']);
        $this->assertSame('video', $formats['objectives'][0]['comparison']['best']);
    }

    /**
     * A link that hides the creatives hides a statement ABOUT those creatives.
     *
     * Null rather than an empty array: the page reads `payload.content_formats &&`, and `[]` is
     * truthy in Javascript — it would render the block and then read a key off an array.
     */
    public function test_hiding_the_content_section_hides_the_comparison_with_it(): void
    {
        /*
         * Driven through `settings.sections`, which is the map `applySectionFlags` reads.
         *
         * The live builder writes `section_overrides`, a different and newer list in the registry's
         * own vocabulary; the two mechanisms meet elsewhere and that seam is not this unit's to move.
         * What is asserted here is the one thing this change owns: when the content section IS
         * hidden, the format comparison goes with its siblings rather than describing ads the reader
         * was not shown.
         */
        $token = $this->link();
        $share = ReportShare::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $share->settings = ['sections' => ['creatives' => false]] + (array) ($share->settings ?? []);
        $share->save();

        $payload = $this->getJson("/api/v1/reports/shared/{$token}/live")->assertOk()->json('data');

        /*
         * The siblings of the same section, so a passing assertion cannot mean «nothing was hidden» —
         * and asserted as absent-OR-empty, because the payload drops a hidden section's keys rather
         * than emptying them. Either shape is the section being gone; what matters is that the format
         * comparison is gone the same way.
         */
        $this->assertSame([], $payload['ads'] ?? [], 'the content section was not hidden at all');
        $this->assertNull($payload['content_formats'] ?? null);
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return $this->getJson("/api/v1/reports/shared/{$this->link($over)}/live")->assertOk()->json('data');
    }

    /** @param array<string, mixed> $over */
    private function link(array $over = []): string
    {
        $created = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->getKey()}/reports/live", array_merge([
                'name' => 'Client link',
                'from' => '2026-09-01',
                'to' => '2026-09-30',
                'form' => 'detailed',
            ], $over))
            ->assertCreated()
            ->json('data');

        return (string) $created['token'];
    }

    private function creatives(UnifiedCampaign $campaign, string $format, int $count, float $spend, float $conversions): void
    {
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $campaign->getKey(),
            'provider' => 'meta', 'external_id' => 'ec-'.uniqid(), 'name' => 'C', 'status' => 'active',
        ]);

        for ($i = 0; $i < $count; $i++) {
            $creative = ExternalCreative::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                'campaign_id' => $campaign->getKey(), 'external_campaign_id' => $external->getKey(),
                'external_account_id' => $this->account->getKey(), 'provider' => 'meta',
                'external_id' => 'cr-'.uniqid().$i, 'external_creative_id' => 'xc-'.uniqid().$i,
                'format' => $format, 'name' => 'Creative '.$i,
            ]);

            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'creative_id' => $creative->getKey(),
                'campaign_id' => $campaign->getKey(),
                'metric_date' => '2026-09-15',
                'spend' => $spend,
                'conversions' => $conversions,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
