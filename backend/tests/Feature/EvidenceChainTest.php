<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Actions\ImportStoreData;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\ValueObjects\Attribution;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Touchpoints\MarketingTouchpoint;
use App\Domains\Metrics\Touchpoints\OrderTouchpointLink;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tracking\Models\TrackingEvent;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * EVIDENCE-CHAIN-001 — the whole chain, through the real entry points, in one place:
 *
 *   a tracked link click → a touchpoint → a store order that carries the click's token → attribution → the
 *   reconciled attribution report.
 *
 * Passing allocation tests prove how credit is DIVIDED. They do not prove that a click ever becomes a touchpoint, that
 * an order ever finds it, or that the report ever shows it. This file starts at the public short link and ends at the
 * authenticated API, and the cases are the ways the chain breaks in practice: a machine's click, a missing token, a
 * token nobody minted, a switched-off module, a click after the order, another workspace's token, a refund.
 */
final class EvidenceChainTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private const NOW = '2026-10-21 12:00:00';

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    private ExternalAccount $store;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'chain-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking', 'attribution_intelligence']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'default_currency' => 'SAR', 'timezone' => 'Asia/Riyadh']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@chain.test', 'password' => 'secret1234']);
        $this->owner->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $this->owner, tenant: $this->tenant, portal: Portal::App, role: 'owner'));

        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'salla', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'salla-'.uniqid());
        $this->store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'salla', 'account_type' => 'store',
            'external_id' => 'shop-'.uniqid(), 'name' => 'Store', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->turnOn('advanced_tracking');
        $this->turnOn('attribution_intelligence');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function turnOn(string $module, bool $on = true, ?Tenant $tenant = null): void
    {
        $tenant ??= $this->tenant;
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'scope_type' => 'tenant', 'scope_key' => $tenant->id, 'module' => $module],
            ['scope_id' => null, 'enabled' => $on],
        );
        app(Capabilities::class)->bump((string) $tenant->id);
    }

    private function as(): \Illuminate\Foundation\Testing\TestCase
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    private function chooseModels(array $models = ['linear', 'last_click', 'first_click']): void
    {
        $this->as()->putJson("/api/v1/projects/{$this->project->id}/attribution-settings", ['models' => $models, 'lookback_days' => 30])->assertOk();
    }

    private function link(string $destination = 'https://store.example/product?ref=summer'): ShortLink
    {
        return ShortLink::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'slug' => substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 7),
            'kind' => 'link', 'destination' => $destination, 'mode' => 'redirect', 'is_active' => true,
        ]);
    }

    private const PERSON = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit Mobile/15E148 Snapchat/12.50';

    /** The URL the visitor is sent to for a click made by a person. */
    private function click(ShortLink $link, string $agent = self::PERSON): string
    {
        return (string) $this->withHeaders(['User-Agent' => $agent])->get('/l/'.$link->slug)->assertStatus(302)->headers->get('Location');
    }

    /** A store order, through the real import, as the connector would hand it over. */
    private function order(string $externalId, ?string $landingUrl, float $total = 250.0, float $refunded = 0.0, string $placedAt = '2026-10-21T13:00:00+00:00', ?ExternalAccount $store = null, ?Tenant $tenant = null): CommerceOrder
    {
        $store ??= $this->store;
        app(TenantContext::class)->setTenantId((string) ($tenant ?? $this->tenant)->id);
        app(ImportStoreData::class)->orders($store, (string) ($store === $this->store ? $this->project->id : $store->getAttribute('_project')), [[
            // The project reports in USD (the default), so USD orders need no rate and the figures below are the plain ones.
            'external_id' => $externalId, 'status' => 'completed', 'placed_at' => $placedAt, 'currency' => 'USD',
            'total' => $total, 'refunded_total' => $refunded,
            'attribution' => Attribution::read([], $landingUrl),
        ]]);
        app(TenantContext::class)->forget();

        return CommerceOrder::withoutGlobalScopes()->where('external_account_id', $store->getKey())->where('external_id', $externalId)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        return $this->as()->getJson("/api/v1/projects/{$this->project->id}/metrics/attribution?from=2026-10-14&to=2026-10-21")->assertOk()->json('data');
    }

    /** @param array<string,mixed> $report @return array<string,array<string,array{orders:float,revenue:float}>> */
    private function credit(array $report): array
    {
        $out = [];
        foreach ($report['model_comparison']['credit_models']['channels'] ?? [] as $c) {
            $out[$c['channel']] = $c['models'];
        }

        return $out;
    }

    public function test_a_click_becomes_a_touchpoint_and_the_order_that_carries_its_token_is_credited_to_it(): void
    {
        $this->chooseModels();
        $link = $this->link();

        $sentTo = $this->click($link);

        // The visitor goes to the same place, with the token added in the open and everything else kept.
        $parts = parse_url($sentTo);
        $this->assertSame('store.example', $parts['host']);
        $this->assertSame('/product', $parts['path']);
        parse_str($parts['query'], $query);
        $this->assertSame('summer', $query['ref']);
        $this->assertNotEmpty($query['ch_tid']);
        $this->assertSame($query['ch_tid'], $query['utm_content']);

        $touch = MarketingTouchpoint::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($query['ch_tid'], $touch->tracking_token);
        $this->assertSame('smart_link', $touch->channel);
        $this->assertSame((string) $link->getKey(), $touch->smart_link_id);
        $this->assertSame(TrackingEvent::withoutGlobalScopes()->value('id'), $touch->source_ref, 'the touchpoint names the event it came from');

        // The store records the landing URL on the order, and the import links it.
        $order = $this->order('o-1', $sentTo);
        $linked = OrderTouchpointLink::withoutGlobalScopes()->where('commerce_order_id', $order->getKey())->firstOrFail();
        $this->assertSame('tracking_token_match', $linked->method);
        $this->assertSame('verified', $linked->grade);

        $report = $this->report();
        $credit = $this->credit($report);
        $this->assertEqualsWithDelta(1.0, $credit['smart_link']['linear']['orders'], 0.0001);
        $this->assertEqualsWithDelta(250.0, $credit['smart_link']['last_click']['revenue'], 0.01);
        $this->assertSame('verified_marketing_touchpoints', $report['model_comparison']['credit_models']['evidence_basis']);
        $this->assertSame(1, $report['model_comparison']['credit_models']['coverage']['orders_with_verified_touchpoints']);
        $this->assertArrayNotHasKey('unattributed', $credit);
    }

    public function test_the_existing_reconciliation_is_identical_with_and_without_the_fractional_models(): void
    {
        $link = $this->link();
        $this->order('o-1', $this->click($link));

        $without = $this->report();
        $this->assertArrayNotHasKey('credit_models', $without['model_comparison'], 'nothing is chosen yet, so nothing is added');

        $this->chooseModels();
        $with = $this->report();
        unset($with['model_comparison']['credit_models']);

        $this->assertEquals($without, $with, 'choosing the models adds a block and moves no existing figure');
    }

    public function test_a_machines_click_is_sent_to_the_same_place_without_a_token_and_leaves_no_touchpoint(): void
    {
        $this->chooseModels();
        $link = $this->link();

        $person = $this->click($link);
        $bot = $this->click($link, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

        $this->assertSame('https://store.example/product?ref=summer', $bot, 'a machine is sent to the plain destination');
        $this->assertStringContainsString('ch_tid=', $person);
        $this->assertSame(1, MarketingTouchpoint::withoutGlobalScopes()->count(), 'only the person is a touchpoint');
        $this->assertSame(2, TrackingEvent::withoutGlobalScopes()->count(), 'both are events, the machine flagged');
        $this->assertSame(1, TrackingEvent::withoutGlobalScopes()->where('is_bot', true)->count());
    }

    public function test_an_order_without_the_token_a_made_up_token_or_a_foreign_one_stays_unattributed(): void
    {
        $this->chooseModels();
        $this->click($this->link());

        $this->order('o-none', 'https://store.example/product');
        $this->order('o-made-up', 'https://store.example/product?ch_tid=zzzzzzzzzzzzzzzzzzzz');
        $this->order('o-no-landing', null);

        $report = $this->report();
        $credit = $this->credit($report);

        $this->assertSame(0, OrderTouchpointLink::withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(3.0, $credit['unattributed']['linear']['orders'], 0.0001);
        $this->assertArrayNotHasKey('smart_link', $credit);
        $this->assertSame(3, $report['model_comparison']['credit_models']['coverage']['orders_without_evidence']);
        $this->assertEqualsWithDelta(0.0, $report['model_comparison']['credit_models']['coverage']['share_with_verified_touchpoints'], 0.0001);
    }

    public function test_a_click_after_the_order_cannot_have_caused_it(): void
    {
        $this->chooseModels();
        // The order was placed at 11:00 and carries the token of a click made at 12:00.
        $sentTo = $this->click($this->link());
        $this->order('o-early', $sentTo, placedAt: '2026-10-21T11:00:00+00:00');

        $this->assertSame(0, OrderTouchpointLink::withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(1.0, $this->credit($this->report())['unattributed']['linear']['orders'], 0.0001);
    }

    public function test_a_replayed_click_import_and_sync_make_one_touchpoint_and_one_link(): void
    {
        $this->chooseModels();
        $sentTo = $this->click($this->link());

        $this->order('o-1', $sentTo);
        $this->order('o-1', $sentTo); // the next nightly sweep reads the same order again

        $this->assertSame(1, CommerceOrder::withoutGlobalScopes()->where('external_id', 'o-1')->count());
        $this->assertSame(1, OrderTouchpointLink::withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(1.0, $this->credit($this->report())['smart_link']['linear']['orders'], 0.0001);
    }

    public function test_refunds_a_full_refund_is_not_shared_out_and_a_partial_one_shares_the_net(): void
    {
        $this->chooseModels();
        $link = $this->link();
        $this->order('o-full', $this->click($link), total: 200.0, refunded: 200.0);
        $this->order('o-part', $this->click($link), total: 200.0, refunded: 50.0);

        $report = $this->report();
        $coverage = $report['model_comparison']['credit_models']['coverage'];

        $this->assertSame(1, $coverage['orders_excluded_fully_refunded']);
        $this->assertSame(1, $coverage['orders_credited']);
        $this->assertEqualsWithDelta(150.0, $this->credit($report)['smart_link']['linear']['revenue'], 0.01);
    }

    public function test_an_order_whose_revenue_is_withheld_is_still_an_order_and_the_shortfall_is_stated(): void
    {
        $this->chooseModels();
        $sentTo = $this->click($this->link());
        // SAR into a USD-reporting project with no dated rate: the amount cannot be stated, so it is withheld, not guessed.
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        app(ImportStoreData::class)->orders($this->store, (string) $this->project->id, [[
            'external_id' => 'o-sar', 'status' => 'completed', 'placed_at' => '2026-10-21T13:00:00+00:00', 'currency' => 'SAR', 'total' => 250.0,
            'attribution' => Attribution::read([], $sentTo),
        ]]);
        app(TenantContext::class)->forget();

        $report = $this->report();
        $this->assertEqualsWithDelta(1.0, $this->credit($report)['smart_link']['linear']['orders'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->credit($report)['smart_link']['linear']['revenue'], 0.01);
        $this->assertSame(1, $report['model_comparison']['credit_models']['coverage']['orders_with_withheld_revenue']);
    }

    public function test_with_advanced_tracking_off_the_click_is_not_tagged_recorded_or_linked(): void
    {
        $this->turnOn('advanced_tracking', false);
        $this->chooseModels();

        $sentTo = $this->click($this->link());

        $this->assertSame('https://store.example/product?ref=summer', $sentTo, 'the destination is untouched');
        $this->assertSame(0, TrackingEvent::withoutGlobalScopes()->count());
        $this->assertSame(0, MarketingTouchpoint::withoutGlobalScopes()->count());
        $this->order('o-1', $sentTo);
        $this->assertSame(0, OrderTouchpointLink::withoutGlobalScopes()->count());
    }

    public function test_with_attribution_intelligence_off_the_report_is_exactly_what_it_was_and_the_settings_are_refused(): void
    {
        $this->chooseModels();
        $this->order('o-1', $this->click($this->link()));
        $this->assertArrayHasKey('credit_models', $this->report()['model_comparison']);

        $this->turnOn('attribution_intelligence', false);

        $this->assertArrayNotHasKey('credit_models', $this->report()['model_comparison'], 'switching the module off removes the block and nothing else');
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/attribution-settings")->assertForbidden()->assertJsonPath('errors.module', 'attribution_intelligence');
        $this->as()->putJson("/api/v1/projects/{$this->project->id}/attribution-settings", ['models' => ['linear']])->assertForbidden();
    }

    public function test_another_workspaces_order_never_links_to_this_workspaces_click(): void
    {
        $this->chooseModels();
        $sentTo = $this->click($this->link());

        $other = Tenant::create(['name' => 'B', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking']]);
        $this->turnOn('advanced_tracking', true, $other);
        app(TenantContext::class)->setTenantId((string) $other->id);
        $client = ClientWorkspace::create(['name' => 'OC', 'slug' => 'oc-'.uniqid(), 'mode' => 'managed', 'default_currency' => 'SAR', 'timezone' => 'Asia/Riyadh']);
        $theirs = Project::create(['client_workspace_id' => $client->id, 'name' => 'OP', 'status' => 'active']);
        $connection = app(TokenVault::class)->open(tenantId: $other->id, provider: 'salla', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'salla-'.uniqid());
        $theirStore = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'salla', 'account_type' => 'store',
            'external_id' => 'shop-'.uniqid(), 'name' => 'Theirs', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'active',
        ]);
        $theirStore->setAttribute('_project', (string) $theirs->id);
        app(TenantContext::class)->forget();

        // Their order carries OUR click's token.
        $this->order('o-foreign', $sentTo, store: $theirStore, tenant: $other);

        $this->assertSame(0, OrderTouchpointLink::withoutGlobalScopes()->count(), 'a token minted for one workspace proves nothing in another');
        $this->assertSame(1, MarketingTouchpoint::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    // ── what the project can SEE of it: the tracking summary the Dashboard and Analytics widgets read ───

    /** @return array<string,mixed> */
    private function summary(string $query = 'from=2026-10-14&to=2026-10-21'): array
    {
        return $this->as()->getJson("/api/v1/projects/{$this->project->id}/tracking/summary?{$query}")->assertOk()->json('data');
    }

    public function test_the_summary_counts_recorded_opens_apart_from_machines_and_never_claims_sales_or_unique_visitors(): void
    {
        $this->assertSame('no_events', $this->summary()['state'], 'before any click it says nothing was recorded, not zero visitors');

        $link = $this->link();
        $this->click($link);
        $this->click($link);
        $this->click($link, 'Googlebot/2.1');

        $s = $this->summary();

        $this->assertSame('measured', $s['state']);
        $this->assertSame(2, $s['opens']);
        $this->assertSame(1, $s['machine_opens']);
        $this->assertSame('2026-10-21', $s['tracking_since']);
        $this->assertSame(['short_link_redirect' => 2], $s['by_type']);
        $this->assertSame(['external_url' => 2], $s['by_destination']);
        $this->assertSame($link->slug, $s['top_links'][0]['slug']);
        $this->assertSame('store.example', $s['top_links'][0]['destination_host']);
        $this->assertSame(2, $s['top_links'][0]['opens']);

        $this->assertSame(8, count($s['series']), 'every day of the window is present');
        $this->assertSame(2, array_sum(array_column($s['series'], 'opens')), 'the series adds up to the total');
        $this->assertFalse($s['basis']['includes_sales']);
        $this->assertSame('devices_per_day_estimate', $s['basis']['unique_basis']);
        $this->assertArrayNotHasKey('unique_visitors', $s, 'a period total of visitors is not something the data supports');
        $this->assertArrayNotHasKey('unique_devices', $s);
    }

    public function test_the_summary_is_refused_when_tracking_is_off_and_never_includes_another_workspaces_events(): void
    {
        $this->click($this->link());

        $other = Tenant::create(['name' => 'B', 'slug' => 'oth-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking']]);
        TrackingEvent::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'project_id' => $this->project->id, 'event_type' => 'short_link_redirect', 'subject_type' => 'short_link',
            'subject_id' => (string) Str::uuid(), 'source' => 'short_link', 'occurred_at' => Carbon::now(), 'recorded_at' => Carbon::now(),
            'consent_state' => 'not_required', 'visitor_confidence' => 'none',
        ]);
        $this->assertSame(1, $this->summary()['opens'], 'another workspace\'s event is not counted, even under the same project id');

        $this->turnOn('advanced_tracking', false);
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/tracking/summary")->assertForbidden()->assertJsonPath('errors.module', 'advanced_tracking');
    }

    // ── the last link: the CLIENT REPORT ─────────────────────────────────────────────────────────────────

    /** @return array{0: Report, 1: ReportShare, 2: string} */
    private function sharedReport(array $opts = []): array
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'currency' => 'USD',
            'period_start' => '2026-10-14', 'period_end' => '2026-10-21', 'data' => ['period' => ['from' => '2026-10-14', 'to' => '2026-10-21']],
        ]);
        [$share, $raw] = app(ShareService::class)->create($report, $opts, null);
        app(TenantContext::class)->forget();

        return [$report, $share, $raw];
    }

    private function linkWidgets(Report $report, ReportShare $share, array $items)
    {
        return $this->as()->putJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares/{$share->id}/widgets", ['items' => $items]);
    }

    /** @return list<array<string,mixed>> */
    private function clientWidgets(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/widgets")->assertOk()->json('data.widgets');
    }

    public function test_a_client_link_shows_no_widgets_until_its_owner_switches_them_on_even_when_the_data_exists(): void
    {
        $this->chooseModels();
        $this->order('o-1', $this->click($this->link()));
        [, , $raw] = $this->sharedReport();

        $this->assertSame([], $this->clientWidgets($raw), 'modules on, events recorded, models chosen, and still nothing until the link says so');
    }

    public function test_the_client_gets_link_activity_cut_down_to_what_a_client_may_see(): void
    {
        $link = $this->link();
        $this->click($link);
        $this->click($link);
        $this->click($link, 'Googlebot/2.1');
        [$report, $share, $raw] = $this->sharedReport();

        $this->linkWidgets($report, $share, [['key' => 'link_opens', 'visible' => true, 'order' => 1, 'size' => 'full']])->assertOk();

        $widgets = $this->clientWidgets($raw);
        $this->assertCount(1, $widgets);
        $w = $widgets[0];
        $this->assertSame('link_opens', $w['key']);
        $this->assertSame(2, $w['data']['opens']);
        $this->assertSame('2026-10-21', $w['data']['tracking_since']);
        $this->assertFalse($w['data']['basis']['includes_sales']);

        // The agency's diagnostics are not in the client's payload at all.
        $json = (string) json_encode($w);
        foreach (['machine_opens', 'devices', 'top_links', 'by_type', $link->slug, 'store.example', 'is_bot', 'visitor'] as $internal) {
            $this->assertStringNotContainsString($internal, $json, "the client payload carries {$internal}");
        }
    }

    public function test_the_client_gets_attribution_with_orders_only_and_the_unattributed_share_kept(): void
    {
        $this->chooseModels(['linear', 'last_click']);
        $this->order('o-tracked', $this->click($this->link()), total: 250.0);
        $this->order('o-untracked', 'https://store.example/product', total: 100.0);
        [$report, $share, $raw] = $this->sharedReport();
        $this->linkWidgets($report, $share, [['key' => 'attribution_credit', 'visible' => true, 'order' => 1, 'size' => 'full']])->assertOk();

        $w = $this->clientWidgets($raw)[0];

        $this->assertSame('attribution_credit', $w['key']);
        $rows = collect($w['data']['channels'])->keyBy('channel');
        $this->assertEqualsWithDelta(1.0, $rows['smart_link']['models']['linear']['orders'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $rows['unattributed']['models']['linear']['orders'], 0.0001, 'the orders with no evidence are shown, not folded away');
        $this->assertSame(1, $w['data']['coverage']['orders_without_evidence']);
        $this->assertStringNotContainsString('"revenue"', (string) json_encode($w), 'money stays under the link\'s own hide flags and is not in this payload');
        $this->assertArrayHasKey('orders_with_withheld_revenue', $w['data']['coverage'], 'the disclosure about withheld revenue is kept');
        $this->assertStringNotContainsString('recorded_by', (string) json_encode($w));
    }

    public function test_a_widget_the_project_forbids_or_whose_module_is_off_or_whose_link_is_narrower_is_never_returned(): void
    {
        $link = $this->link();
        $this->click($link);
        [$report, $share, $raw] = $this->sharedReport();
        $this->linkWidgets($report, $share, [['key' => 'link_opens', 'visible' => true]])->assertOk();
        $this->assertCount(1, $this->clientWidgets($raw));

        // 1. The project forbids it on client links.
        $this->as()->putJson("/api/v1/projects/{$this->project->id}/widgets/report", ['items' => [['key' => 'link_opens', 'visible' => false]]])->assertOk();
        $this->assertSame([], $this->clientWidgets($raw));
        $resolved = collect($this->as()->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares/{$share->id}/widgets")->assertOk()->json('data.widgets'))->keyBy('key');
        $this->assertSame('hidden_by_project', $resolved['link_opens']['reason'], 'the owner is told why, so they are not left believing a client can see it');
        $this->as()->putJson("/api/v1/projects/{$this->project->id}/widgets/report", ['items' => [['key' => 'link_opens', 'visible' => true]]])->assertOk();
        $this->assertCount(1, $this->clientWidgets($raw));

        // 2. The module is switched off.
        $this->turnOn('advanced_tracking', false);
        $this->assertSame([], $this->clientWidgets($raw));
        $this->turnOn('advanced_tracking', true);
        $this->assertCount(1, $this->clientWidgets($raw));

        // 3. A link narrower than its project: the touchpoints and the store ledger are project-wide, so it shows none.
        [$narrowReport, $narrow, $narrowRaw] = $this->sharedReport(['scope' => ['project_id' => $this->project->id, 'account_ids' => [(string) Str::uuid()]]]);
        $this->linkWidgets($narrowReport, $narrow, [['key' => 'link_opens', 'visible' => true]])->assertOk();
        $this->assertSame([], $this->clientWidgets($narrowRaw));
    }

    public function test_each_link_has_its_own_widgets_and_a_revoked_or_password_protected_link_is_still_closed(): void
    {
        $this->click($this->link());
        [$report, $one, $rawOne] = $this->sharedReport();
        [, $two, $rawTwo] = $this->sharedReport();
        $this->linkWidgets($report, $one, [['key' => 'link_opens', 'visible' => true]])->assertOk();

        $this->assertCount(1, $this->clientWidgets($rawOne));
        $this->assertSame([], $this->clientWidgets($rawTwo), 'switching a widget on for one link does not touch another');

        [$lockedReport, $locked, $rawLocked] = $this->sharedReport(['password' => 'open-sesame']);
        $this->linkWidgets($lockedReport, $locked, [['key' => 'link_opens', 'visible' => true]])->assertOk();
        $this->getJson("/api/v1/reports/shared/{$rawLocked}/widgets")->assertStatus(401);
        $this->withHeaders(['X-Report-Password' => 'open-sesame'])->getJson("/api/v1/reports/shared/{$rawLocked}/widgets")->assertOk();

        $one->forceFill(['revoked_at' => now()])->save();
        $this->getJson("/api/v1/reports/shared/{$rawOne}/widgets")->assertNotFound();
        $this->getJson('/api/v1/reports/shared/not-a-real-token/widgets')->assertNotFound();
    }

    public function test_changing_a_links_widgets_needs_reports_manage_stays_inside_the_workspace_and_is_audited(): void
    {
        $this->click($this->link());
        [$report, $share] = $this->sharedReport();
        $this->linkWidgets($report, $share, [['key' => 'link_opens', 'visible' => true, 'order' => 3, 'size' => 'half']])->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'widgets.link_layout_changed')->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->after['items'][0]['visible']);

        // Unknown widgets are refused and nothing is stored.
        $this->linkWidgets($report, $share, [['key' => 'teleporter', 'visible' => true]])->assertStatus(422);

        // Another workspace's owner cannot read or change this link's widgets.
        $other = Tenant::create(['name' => 'B', 'slug' => 'oth-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media']]);
        app(TenantContext::class)->setTenantId((string) $other->id);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'S', 'email' => uniqid().'@s.test', 'password' => 'secret1234']);
        $stranger->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $stranger, tenant: $other, portal: Portal::App, role: 'owner'));
        app(TenantContext::class)->forget();

        $url = "/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares/{$share->id}/widgets";
        $this->actingAs($stranger, 'sanctum')->withHeaders(self::SPA)->getJson($url)->assertStatus(404);
        $this->actingAs($stranger, 'sanctum')->withHeaders(self::SPA)->putJson($url, ['items' => []])->assertStatus(404);
    }

    // ── the same widgets in the FILES a link produces ────────────────────────────────────────────────────

    /** @return array{0: Report, 1: array<string,mixed>} the report carrying the link's download document */
    private function downloadDocumentFor(Report $report, ReportShare $share): array
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $fresh = Report::withoutGlobalScopes()->findOrFail($report->getKey());
        $document = app(ShareService::class)->downloadDocument($fresh, $share);
        $fresh->data = $document;

        return [$fresh, $document];
    }

    public function test_the_csv_and_the_spreadsheet_carry_the_same_widgets_the_page_shows_and_nothing_more(): void
    {
        $this->chooseModels(['linear']);
        $link = $this->link();
        $sent = $this->click($link);
        $this->click($link, 'Googlebot/2.1');
        $this->order('o-1', $sent);
        $this->order('o-2', 'https://store.example/product');
        [$report, $share, $raw] = $this->sharedReport(['allow_download' => true]);
        $this->linkWidgets($report, $share, [
            ['key' => 'link_opens', 'visible' => true, 'order' => 1, 'size' => 'full'],
            ['key' => 'attribution_credit', 'visible' => true, 'order' => 2, 'size' => 'full'],
        ])->assertOk();

        $page = collect($this->clientWidgets($raw))->keyBy('key');
        [$withDoc, $document] = $this->downloadDocumentFor($report, $share);
        $this->assertEquals($page->pluck('data', 'key')->all(), collect($document['widgets'])->pluck('data', 'key')->all(), 'the document holds exactly what the page was given');

        $csv = app(ReportExporter::class)->render($withDoc, 'csv', $share);
        $this->assertStringContainsString('Link activity / نشاط الروابط', $csv);
        $this->assertStringContainsString('2026-10-21,1', $csv, 'the human open is a row; the machine open is not');
        $this->assertStringContainsString('"Total opens / إجمالي الفتحات",1', $csv);
        $this->assertStringContainsString('Attribution by channel / الإسناد حسب القناة', $csv);
        $this->assertStringContainsString('smart_link,1', $csv);
        $this->assertStringContainsString('unattributed,1', $csv);
        $this->assertStringContainsString('"Orders without evidence / طلبات بلا دليل",1', $csv);
        // The orders were worth 250 and 250: no amount of money reaches this file (the disclosure about withheld revenue is a count, not an amount).
        foreach (['machine', $link->slug, 'store.example', '250'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $csv, "the file carries {$internal}");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'widgets_').'.xlsx';
        file_put_contents($tmp, app(ReportExporter::class)->render($withDoc, 'xlsx', $share));
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        $this->assertNotNull($book->getSheetByName('Link activity'));
        $this->assertNotNull($book->getSheetByName('Attribution'));
        $this->assertContains('smart_link', array_column($book->getSheetByName('Attribution')->toArray(), 0));
    }

    public function test_a_link_with_no_widgets_switched_on_produces_files_with_no_widget_tables(): void
    {
        $this->chooseModels(['linear']);
        $this->order('o-1', $this->click($this->link()));
        [$report, $share] = $this->sharedReport(['allow_download' => true]);

        [$withDoc, $document] = $this->downloadDocumentFor($report, $share);
        $this->assertSame([], $document['widgets']);

        $csv = app(ReportExporter::class)->render($withDoc, 'csv', $share);
        $this->assertStringNotContainsString('Link activity', $csv);
        $this->assertStringNotContainsString('Attribution by channel', $csv);
    }

    public function test_the_settings_refuse_what_the_engine_cannot_run_and_say_why(): void
    {
        foreach ([['models' => ['teleport']], ['models' => ['linear'], 'lookback_days' => 0], ['models' => ['linear'], 'weights' => ['first' => 0, 'middle' => 0, 'last' => 0]]] as $bad) {
            $this->as()->putJson("/api/v1/projects/{$this->project->id}/attribution-settings", $bad)->assertStatus(422);
        }
        $this->assertFalse($this->as()->getJson("/api/v1/projects/{$this->project->id}/attribution-settings")->assertOk()->json('data.configured'));
    }
}
