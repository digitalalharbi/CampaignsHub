<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\OrderAttributionResolver;
use App\Domains\Commerce\ValueObjects\Attribution;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerTrackingAsset;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ATTR-EVIDENCE-INFLUENCER-COUPON-001 (Owner §C) — a creator's discount code is attribution evidence of its
 * own rank: below a platform's click id, above a bare utm_source; it places the order on the
 * collaboration's campaign, and the code's redemptions are the store's own confirmed orders.
 */
final class InfluencerCouponEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private ExternalAccount $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Ramadan', 'objective' => 'sales', 'status' => 'active', 'total_budget' => 1000, 'budget_currency' => 'SAR']);

        $credential = new IntegrationCredential(['provider' => 'salla', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'salla', 'connection_name' => 'salla', 'scope' => 'project_only', 'status' => 'connected']);
        $this->store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'salla',
            'account_type' => 'store', 'external_id' => 'store-1', 'name' => 'Shop', 'status' => 'active', 'discovered_at' => now(),
        ]);
    }

    private function asset(string $code, bool $active = true, string $kind = 'discount_code'): InfluencerTrackingAsset
    {
        $influencer = Influencer::create(['tenant_id' => $this->tenant->id, 'name' => 'Sara', 'handle' => 'sara-'.uniqid(), 'primary_platform' => 'snapchat', 'status' => 'active']);
        $collab = InfluencerCollaboration::create([
            'tenant_id' => $this->tenant->id, 'influencer_id' => $influencer->id, 'campaign_id' => $this->campaign->id,
            'title' => 'Ramadan push', 'status' => 'active', 'currency' => 'SAR', 'agreed_fee' => '5000.00',
        ]);

        return InfluencerTrackingAsset::create([
            'tenant_id' => $this->tenant->id, 'collaboration_id' => $collab->id, 'kind' => $kind, 'code' => $code,
            'destination_url' => $kind === 'link' ? 'https://shop.example/?ref='.$code : null, 'is_active' => $active,
            'redemptions_source' => 'awaiting_credentials',
        ]);
    }

    private function order(?string $coupon, array $over = []): CommerceOrder
    {
        return CommerceOrder::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->getKey(),
            'provider' => 'salla', 'external_id' => 'o-'.uniqid(), 'status' => 'completed', 'placed_at' => Carbon::today()->subDay(),
            'currency' => 'SAR', 'total' => 250, 'coupon_code' => $coupon,
        ], $over));
    }

    public function test_the_coupon_is_read_from_the_store_payload_into_its_own_column(): void
    {
        $columns = Attribution::read(explicit: [], landingUrl: null, referrer: null, couponCode: '  SARA20 ')->toColumns();

        $this->assertSame('SARA20', $columns['coupon_code']);
        $this->assertNull(Attribution::read()->toColumns()['coupon_code']);
    }

    public function test_a_creator_code_places_the_order_on_the_collaboration_campaign_and_recounts_redemptions(): void
    {
        $asset = $this->asset('SARA20');
        $resolver = app(OrderAttributionResolver::class);

        $order = $resolver->apply($this->order('sara20'), collect());

        $this->assertSame('influencer_coupon', $order->attribution_method);
        $this->assertSame((string) $this->campaign->id, (string) $order->unified_campaign_id);
        $this->assertNull($order->external_campaign_id, 'a code names the collaboration, never a platform campaign');
        $this->assertSame(1, $asset->refresh()->redemptions);
        $this->assertSame('platform', $asset->redemptions_source, 'the store itself confirmed the redemption');

        // A second order with the same code: recounted, not incremented twice.
        $resolver->apply($this->order('SARA20'), collect());
        $resolver->apply($this->order('SARA20', ['cancelled_at' => Carbon::now()]), collect());
        $this->assertSame(2, $asset->refresh()->redemptions, 'a cancelled order is not a redemption');
    }

    public function test_a_platform_click_id_and_a_utm_campaign_outrank_the_code_and_a_bare_source_does_not(): void
    {
        $this->asset('SARA20');
        $resolver = app(OrderAttributionResolver::class);
        $adAccount = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->store->provider_connection_id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act-1', 'name' => 'Meta', 'status' => 'active', 'discovered_at' => now(),
        ]);
        $meta = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $adAccount->getKey(), 'provider' => 'meta',
            'external_id' => '120', 'name' => 'Meta Ramadan', 'status' => 'active', 'unified_campaign_id' => $this->campaign->id,
        ]);

        $utm = $resolver->resolve($this->order('SARA20', ['utm_campaign' => '120']), collect([$meta]));
        $this->assertSame('utm_campaign_id', $utm['attribution_method']);

        $click = $resolver->resolve($this->order('SARA20', ['click_id' => 'fb.1', 'click_id_provider' => 'meta']), collect());
        $this->assertSame('click_id_platform_only', $click['attribution_method']);

        $source = $resolver->resolve($this->order('SARA20', ['utm_source' => 'meta']), collect());
        $this->assertSame('influencer_coupon', $source['attribution_method'], 'a word in utm_source does not outrank a creator\'s code');
    }

    public function test_an_unknown_inactive_or_link_code_is_no_evidence(): void
    {
        $this->asset('OLD10', active: false);
        $this->asset('LINKY', kind: 'link');
        $resolver = app(OrderAttributionResolver::class);

        $this->assertSame('none', $resolver->resolve($this->order('NOPE'), collect())['attribution_method']);
        $this->assertSame('none', $resolver->resolve($this->order('OLD10'), collect())['attribution_method']);
        $this->assertSame('none', $resolver->resolve($this->order('LINKY'), collect())['attribution_method']);
        $this->assertSame('none', $resolver->resolve($this->order(null), collect())['attribution_method']);
    }
}
