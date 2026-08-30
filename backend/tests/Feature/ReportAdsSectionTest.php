<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-AD-PREVIEW-001 — the report ends with the work, not with a table of names.
 *
 * `top_creatives` ranks CAMPAIGNS, and `creative_level` has said `campaign` since the snapshot was
 * written — honestly, because ad-level rows did not exist. They do now: `creative_daily_metrics` per
 * creative, and `external_creatives` carrying the media AD-MEDIA-RECOVERY-001 started reading.
 *
 * A client's report is the copy they keep, and the ads are the part they recognise. Every entry
 * carries the objective's own indicators beside the preview, so it reads as evidence rather than as
 * a gallery.
 */
final class ReportAdsSectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    private const DATE = '2026-07-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'ads-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->id);

        $this->account = $this->externalAccount();
    }

    /** The ads that ran, with the media that ran with them. */
    public function test_the_report_carries_the_ads_and_the_picture_that_ran(): void
    {
        $campaign = $this->campaign('Eid sales', CampaignObjective::Sales, spend: 5_000, orders: 100, revenue: 40_000);
        $this->creative($campaign, 'Eid film', spend: 3_000, conversions: 70, revenue: 30_000, thumbnail: 'https://cdn/eid.jpg');
        $this->creative($campaign, 'Eid still', spend: 2_000, conversions: 30, revenue: 10_000, thumbnail: 'https://cdn/still.jpg');

        $data = $this->generate();

        $this->assertSame('ad', $data['ads_level'], 'ad-level rows exist, so the section is about ads');
        $this->assertNull($data['ads_absent_reason']);
        $this->assertCount(2, $data['ads']);

        $names = array_column($data['ads'], 'name');
        $this->assertContains('Eid film', $names);
        $this->assertContains('Eid still', $names);

        $ad = array_column($data['ads'], null, 'name')['Eid film'];
        $this->assertSame('available', $ad['preview']['state']);
        $this->assertSame('https://cdn/eid.jpg', $ad['preview']['thumbnail_url']);
        // The metadata a reader needs to place it, beside the picture.
        $this->assertSame('meta', $ad['provider']);
        $this->assertSame('Eid sales', $ad['campaign_name']);
        $this->assertEqualsWithDelta(3_000.0, $ad['spend'], 0.01);
    }

    /**
     * A project with no creatives gets NO section, and a reason.
     *
     * An empty gallery under a heading reads as «your ads performed so badly there is nothing to
     * show» — a claim about the client's advertising made by a gap in ours.
     */
    public function test_a_project_with_no_creatives_gets_no_ads_section(): void
    {
        $this->campaign('Eid sales', CampaignObjective::Sales, spend: 5_000, orders: 100, revenue: 40_000);

        $data = $this->generate();

        $this->assertSame([], $data['ads']);
        $this->assertSame('no_creatives_in_window', $data['ads_absent_reason']);
        // And it does not claim to be an ad-level report while showing nothing.
        $this->assertSame('campaign', $data['ads_level']);
    }

    /**
     * An ad whose media the platform withheld still appears, with its reason.
     *
     * Dropping it would quietly change which ads a client is shown — the report would list the ones
     * whose pictures we happen to hold, ranked as though that were about performance.
     */
    public function test_an_ad_with_no_media_still_appears_and_says_why(): void
    {
        $campaign = $this->campaign('Eid sales', CampaignObjective::Sales, spend: 5_000, orders: 100, revenue: 40_000);
        $this->creative($campaign, 'No picture', spend: 4_000, conversions: 90, revenue: 35_000, thumbnail: null);

        $data = $this->generate();

        $this->assertCount(1, $data['ads']);
        $this->assertSame('unavailable', $data['ads'][0]['preview']['state']);
        $this->assertNotSame('', (string) $data['ads'][0]['preview']['note_en']);
    }

    // ── REPORT-ANALYTICAL-DEPTH-001 — the argument the document makes ────────────────────────────

    /**
     * The sections, in the order they argue, declared once by the snapshot.
     *
     * The snapshot carried every section and no statement about their ORDER or their presence, so
     * each renderer decided both for itself — the interactive report, the PDF and the shared link
     * each held their own list, and a section added for one arrived in the others weeks later or not
     * at all.
     *
     * The order is not decoration. Summary → performance → platform → objective → entity → ads →
     * findings is an argument, and a reader who meets the findings before the figures is being asked
     * to trust a conclusion whose evidence has not been shown.
     */
    public function test_the_report_declares_its_sections_in_the_order_they_argue(): void
    {
        $campaign = $this->campaign('Eid sales', CampaignObjective::Sales, spend: 5_000, orders: 100, revenue: 40_000);
        $this->creative($campaign, 'Eid film', spend: 3_000, conversions: 70, revenue: 30_000, thumbnail: 'https://cdn/eid.jpg');

        $outline = $this->generate()['outline'];

        $this->assertSame(
            ['executive_summary', 'performance', 'platforms', 'objectives', 'campaigns', 'ads', 'findings', 'recommendations'],
            array_column($outline, 'key'),
        );

        $sections = array_column($outline, null, 'key');
        $this->assertTrue($sections['performance']['present']);
        $this->assertTrue($sections['ads']['present']);
        $this->assertNull($sections['ads']['absent_reason'], 'a present section has nothing to explain');
    }

    /**
     * An absent section says why, and does not become an empty heading.
     *
     * «Ads» over an empty box reads as «your ads performed so badly there is nothing to show».
     * «Findings» over one reads as «we looked and found nothing wrong» — a claim, not an absence.
     */
    public function test_a_section_with_nothing_to_show_is_absent_with_a_reason(): void
    {
        $this->campaign('Eid sales', CampaignObjective::Sales, spend: 5_000, orders: 100, revenue: 40_000);

        $sections = array_column($this->generate()['outline'], null, 'key');

        $this->assertFalse($sections['ads']['present']);
        $this->assertSame('no_creatives_in_window', $sections['ads']['absent_reason'], 'the reason is the one the section itself gave');
    }

    /** The outline is DERIVED from the data, so a section cannot claim to be present while empty. */
    public function test_the_outline_cannot_disagree_with_the_data_it_describes(): void
    {
        $campaign = $this->campaign('Eid sales', CampaignObjective::Sales, spend: 5_000, orders: 100, revenue: 40_000);
        $this->creative($campaign, 'Eid film', spend: 3_000, conversions: 70, revenue: 30_000, thumbnail: 'https://cdn/eid.jpg');

        $data = $this->generate();

        foreach ($data['outline'] as $section) {
            $key = $section['key'] === 'objectives' ? null : $section['key'];

            if ($key === null || ! array_key_exists($key, $data) || ! is_array($data[$key])) {
                continue;
            }

            $this->assertSame(
                $data[$key] !== [],
                $section['present'],
                "the outline says {$key} is ".($section['present'] ? 'present' : 'absent').' and the data says otherwise',
            );
        }
    }

    /** @return array<string,mixed> */
    private function generate(): array
    {
        $report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'R',
            'type' => 'performance',
            'status' => 'draft',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'currency' => 'SAR',
        ]);

        return app(ReportGenerator::class)->generate($report);
    }

    private function campaign(string $name, CampaignObjective $objective, float $spend, float $orders, float $revenue): UnifiedCampaign
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => $objective->value,
            'total_budget' => 50_000, 'budget_currency' => 'SAR',
        ]);
        $campaign->forceFill(['objective_source' => 'platform'])->save();

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'ext-'.uniqid(), 'name' => $name, 'status' => 'active',
        ]);

        foreach (['spend' => $spend, 'conversions' => $orders, 'revenue' => $revenue] as $key => $value) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $this->account->getKey(), 'external_campaign_id' => $external->id,
                'unified_campaign_id' => $campaign->id, 'provider' => 'meta',
                'metric_key' => $key, 'metric_date' => self::DATE, 'value' => $value,
                'original_amount' => $value, 'original_currency' => 'SAR', 'project_currency' => 'SAR', 'exchange_rate' => 1,
            ]);
        }

        return $campaign;
    }

    private function creative(
        UnifiedCampaign $campaign,
        string $name,
        float $spend,
        float $conversions,
        float $revenue,
        ?string $thumbnail,
    ): void {
        $external = ExternalCampaign::withoutGlobalScopes()->where('unified_campaign_id', $campaign->id)->firstOrFail();

        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaign->id,
            'external_campaign_id' => $external->id,
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.uniqid(),
            'name' => $name,
            'format' => 'image',
            'status' => 'active',
            'thumbnail_url' => $thumbnail,
            'source_type' => 'api',
            'is_demo' => false,
        ]);

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaign->id,
            'creative_id' => $creative->getKey(),
            'metric_date' => self::DATE,
            'spend' => $spend,
            'impressions' => 100_000,
            'clicks' => 1_200,
            'conversions' => $conversions,
            'revenue' => $revenue,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function externalAccount(): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => 'meta', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act-meta',
            'name' => 'Meta',
            'status' => 'active',
            'currency' => 'SAR',
        ])->save();

        return $account;
    }
}
