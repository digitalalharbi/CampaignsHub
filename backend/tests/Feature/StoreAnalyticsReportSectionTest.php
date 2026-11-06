<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * STORE-ANALYTICS-DEPTH-001 §3.1 — «تحليلات المتجر والزيارات» as one category to show or hide, live and snapshot.
 *
 * Off for every report until an operator switches it on, and what it carries is built for a client's eyes.
 */
final class StoreAnalyticsReportSectionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private Project $project;

    private Project $sibling;

    private ExternalAccount $store;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $a = ClientWorkspace::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'mode' => 'managed']);
        $b = ClientWorkspace::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $a->id, 'name' => 'P', 'status' => 'active']);
        $this->sibling = Project::create(['client_workspace_id' => $b->id, 'name' => 'Q', 'status' => 'active']);
        $this->store = $this->storeFor($this->project, 'shop-1');
        app(ProjectContext::class)->setProjectId($this->project->id);
        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'data' => [],
        ]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        $customer = CommerceCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id, 'provider' => 'salla',
            'external_id' => 'cu1', 'name' => 'Fatimah Secret', 'email' => 'fatimah@secret.test', 'phone' => '+966500000001',
        ]);
        $order = $this->order('REF-SECRET', 300, [
            'commerce_customer_id' => $customer->id, 'utm_campaign' => 'SecretCampaignName', 'utm_source' => 'privatesource', 'utm_content' => 'SecretContent',
            'attribution_method' => 'none', 'referrer_url' => 'https://secret-referrer.test/x', 'coupon_code' => 'SECRETCODE',
        ]);
        CommerceOrderItem::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'commerce_order_id' => $order->id, 'external_id' => 'i1', 'name' => 'Tee', 'quantity' => 2, 'total' => 300]);
        $this->order('un', 100, ['attribution_method' => 'none']);
    }

    public function test_a_link_whose_report_never_chose_it_carries_nothing_and_reads_nothing(): void
    {
        $data = $this->live();
        $this->assertArrayNotHasKey('store_analytics', $data);
        $this->assertNotContains('store_analytics', $data['report_sections'] ?? []);
    }

    public function test_an_operator_switching_it_on_shows_the_category_on_the_same_link(): void
    {
        $raw = $this->link();
        $this->assertArrayNotHasKey('store_analytics', $this->read($raw));

        $this->choose(true);
        $data = $this->read($raw);
        $this->assertContains('store_analytics', $data['report_sections']);
        $s = $data['store_analytics'];
        $this->assertTrue($s['not_summed']);
        $this->assertSame('تحليلات المتجر والزيارات', $s['title_ar']);
        $this->assertSame('ما أكّده المتجر', $s['store']['label_ar']);
        $this->assertEquals(400.0, $s['store']['sales']['net']['current']);
        $this->assertSame(2, $s['store']['orders']['live']);
        $this->assertSame('Tee', $s['store']['top_products'][0]['name']);
        $this->assertSame(['utm_source', 'unattributed'], array_column($s['store']['evidence'], 'method'));
        $this->assertNull($s['site'], 'no GA4 property: absent, not zero');

        $this->choose(false);
        $this->assertArrayNotHasKey('store_analytics', $this->read($raw), 'hidden means absent from the payload');
    }

    public function test_nothing_personal_no_campaign_name_no_utm_text_and_no_spend_reach_the_link(): void
    {
        $this->choose(true);
        $body = (string) json_encode($this->live()['store_analytics'], JSON_UNESCAPED_UNICODE);
        foreach (['Fatimah', 'fatimah', '+966500000001', 'SecretCampaignName', 'privatesource', 'SecretContent', 'secret-referrer', 'SECRETCODE', 'REF-SECRET', 'spend'] as $never) {
            $this->assertStringNotContainsString($never, $body);
        }
    }

    public function test_demo_rows_are_not_a_report_and_the_exclusion_is_stated(): void
    {
        $this->order('demo', 9000, ['is_demo' => true]);
        $this->choose(true);
        $store = $this->live()['store_analytics']['store'];
        $this->assertEquals(400.0, $store['sales']['net']['current']);
        $this->assertTrue($store['demo_excluded']);
    }

    public function test_only_demo_rows_make_no_section(): void
    {
        CommerceOrder::withoutGlobalScopes()->where('project_id', $this->project->id)->update(['is_demo' => true]);
        $this->choose(true);
        $data = $this->live();
        $this->assertArrayNotHasKey('store_analytics', $data);
    }

    public function test_hide_revenue_removes_every_amount(): void
    {
        $this->choose(true);
        [, $raw] = app(ShareService::class)->create($this->report->fresh(), ['hide_revenue' => true, 'scope' => $this->scope()], null);
        $store = $this->read($raw)['store_analytics']['store'];
        $this->assertNull($store['sales']['net']['current']);
        $this->assertNull($store['averages']['aov']);
        $this->assertNull($store['top_products'][0]['revenue']);
        $this->assertNull($store['evidence'][0]['net_revenue']);
        $this->assertNull($store['trend'][0]['net']);
        $this->assertNull($store['currency']);
    }

    public function test_another_projects_store_never_enters(): void
    {
        $theirs = $this->storeFor($this->sibling, 'shop-2');
        $this->order('theirs', 7777, ['attribution_method' => 'none'], $theirs, $this->sibling);
        $this->choose(true);
        $this->assertEquals(400.0, $this->live()['store_analytics']['store']['sales']['net']['current']);
    }

    public function test_a_creator_coupon_is_not_named_while_the_service_is_withdrawn(): void
    {
        $this->order('c', 50, ['attribution_method' => 'influencer_coupon']);
        $this->choose(true);
        $methods = array_column($this->live()['store_analytics']['store']['evidence'], 'method');
        $this->assertNotContains('influencer_coupon', $methods);
        $this->assertContains('coupon', $methods);
    }

    public function test_a_snapshot_freezes_it_only_when_chosen(): void
    {
        $this->assertNull($this->snapshot()['store_analytics'] ?? null);
        $this->choose(true);
        $data = $this->snapshot();
        $this->assertSame(2, $data['store_analytics']['store']['orders']['live']);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $data = app(ReportGenerator::class)->generate($this->report->fresh());
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $data;
    }

    private function choose(bool $on): void
    {
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['section_settings' => json_encode(['sections' => ['store_analytics' => $on]])]);
    }

    /** @return array<string, mixed> */
    private function scope(): array
    {
        return ['project_id' => (string) $this->project->id, 'campaign_ids' => [], 'account_ids' => [], 'providers' => [], 'dynamic_campaigns' => true, 'earliest' => '2026-10-01', 'latest' => '2026-10-31'];
    }

    private function link(): string
    {
        [, $raw] = app(ShareService::class)->create($this->report->fresh(), ['scope' => $this->scope()], null);

        return $raw;
    }

    /** @return array<string, mixed> */
    private function read(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function live(): array
    {
        return $this->read($this->link());
    }

    private function storeFor(Project $project, string $external): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'salla', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Salla '.$external);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => $external, 'name' => 'Shop '.$external, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'salla', 'purpose' => 'ecommerce', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param array<string, mixed> $over */
    private function order(string $reference, float $total, array $over = [], ?ExternalAccount $store = null, ?Project $project = null): CommerceOrder
    {
        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => ($project ?? $this->project)->id, 'external_account_id' => ($store ?? $this->store)->id,
            'provider' => 'salla', 'external_id' => 'ext-'.$reference, 'reference' => $reference, 'status' => 'completed', 'placed_at' => '2026-10-05 10:00:00',
            'currency' => 'SAR', 'total' => $total, ...$over,
        ]);
    }
}
