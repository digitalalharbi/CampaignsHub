<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Models\ConversionDelivery;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * TRACKING-HEALTH-001 — each check is a statement about something measured, with its number and the action it asks for;
 * a check that could not be made says so, and no accuracy is ever claimed.
 */
final class TrackingHealthTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    private function as()
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    private function tracking(bool $on): void
    {
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => 'tenant', 'scope_key' => $this->tenant->id, 'module' => 'advanced_tracking'],
            ['scope_id' => null, 'enabled' => $on],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);
    }

    private function slug(bool $asksMeasurement = true): string
    {
        $definition = [
            'steps' => [['id' => 's1', 'title' => 'You', 'fields' => [
                ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
            ]]],
            'consent' => ['required' => true, 'text' => ['en' => 'Contact me.'], 'measurement' => ['enabled' => $asksMeasurement, 'text' => ['en' => 'Report my hashed details.']]],
        ];
        $id = $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms", ['name' => 'Villa enquiry', 'definition' => $definition])->assertCreated()->json('data.id');
        $this->as()->postJson("/api/v1/projects/{$this->project->id}/forms/{$id}/publish")->assertOk();

        return (string) LeadForm::withoutGlobalScopes()->findOrFail($id)->slug;
    }

    private function submit(string $slug, int $n, array $attribution = [], bool $measurement = true): void
    {
        $this->postJson("/api/v1/public/forms/{$slug}/submissions", [
            'submission_id' => 'sub-'.str_pad((string) $n, 16, '0', STR_PAD_LEFT),
            'answers' => ['full_name' => "Person {$n}", 'mobile' => '05000'.str_pad((string) (1000 + $n), 5, '0', STR_PAD_LEFT)],
            'consent' => ['accepted' => true, 'measurement' => $measurement],
            'attribution' => $attribution,
        ])->assertCreated();
    }

    /** @return array<string,mixed> */
    private function health(string $query = ''): array
    {
        return $this->as()->getJson("/api/v1/projects/{$this->project->id}/tracking-health{$query}")->assertOk()->json('data');
    }

    /** @param array<string,mixed> $health */
    private function check(array $health, string $prefix): ?array
    {
        foreach ($health['checks'] as $c) {
            if (str_starts_with($c['key'], $prefix)) {
                return $c;
            }
        }

        return null;
    }

    public function test_a_form_that_is_viewed_often_and_never_sent_is_critical_with_the_action_to_take(): void
    {
        $this->tracking(true);
        $slug = $this->slug();
        foreach (range(1, 22) as $n) {
            $this->postJson("/api/v1/public/forms/{$slug}/events", ['event' => 'form_view', 'event_id' => 'ev-'.str_pad((string) $n, 10, '0', STR_PAD_LEFT), 'session_id' => 'ses-'.str_pad((string) $n, 10, '0', STR_PAD_LEFT)])->assertNoContent();
        }

        $h = $this->health();

        $c = $this->check($h, 'form_broken:');
        $this->assertSame('critical', $c['severity']);
        $this->assertSame(22, $c['numbers']['views']);
        $this->assertStringContainsString('phone', (string) $c['action']);
        $this->assertSame(1, $h['summary']['critical']);
        $this->assertSame('critical', $h['checks'][0]['severity'], 'The worst thing comes first.');
    }

    public function test_with_tracking_off_the_visit_checks_say_they_cannot_be_made_instead_of_passing(): void
    {
        $slug = $this->slug();
        $this->submit($slug, 1);

        $c = $this->check($this->health(), 'form_visits:');

        $this->assertSame('unavailable', $c['severity']);
        $this->assertStringContainsString('cannot be told from one nobody opens', (string) $c['detail']);
    }

    public function test_coverage_is_a_rate_only_over_enough_leads_and_a_mostly_untagged_form_warns(): void
    {
        $this->tracking(true);
        $slug = $this->slug();
        foreach (range(1, 3) as $n) {
            $this->submit($slug, $n);
        }
        $thin = $this->health();
        $this->assertNull($thin['coverage']['with_campaign_tag'], 'Three leads is noise, not a percentage.');
        $this->assertSame('unavailable', $this->check($thin, 'coverage_thin')['severity']);

        foreach (range(4, 8) as $n) {
            $this->submit($slug, $n, $n === 4 ? ['utm_source' => 'meta', 'fbclid' => 'AbCdEf12345'] : []);
        }
        $h = $this->health();

        $this->assertSame(8, $h['coverage']['form_leads']);
        $this->assertSame(0.125, $h['coverage']['with_campaign_tag']);
        $this->assertSame(0.125, $h['coverage']['with_click_id']);
        $this->assertEquals(1.0, $h['coverage']['measurement_consent_granted']);
        $c = $this->check($h, 'missing_campaign_tag');
        $this->assertSame('warning', $c['severity']);
        $this->assertSame(['without_tag' => 7, 'of' => 8], $c['numbers']);
    }

    public function test_failed_and_stuck_deliveries_and_a_destination_whose_form_never_asks_are_surfaced(): void
    {
        $this->tracking(true);
        Queue::fake();
        Http::fake();
        $slug = $this->slug(asksMeasurement: false);
        $dest = $this->as()->postJson("/api/v1/projects/{$this->project->id}/conversion-destinations", ['kind' => 'ga4', 'name' => 'GA4', 'config' => ['measurement_id' => 'G-ABC123XYZ9'], 'secrets' => ['api_secret' => 's']])->assertCreated()->json('data.id');
        $this->as()->patchJson("/api/v1/projects/{$this->project->id}/conversion-destinations/{$dest}", ['status' => 'live', 'confirm_live' => true])->assertOk();
        $this->submit($slug, 1);

        ConversionDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'conversion_destination_id' => $dest, 'event_name' => 'generate_lead',
            'event_id' => 'e-failed', 'status' => 'failed', 'error' => 'HTTP 403 ***',
        ]);
        $stuck = ConversionDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'conversion_destination_id' => $dest, 'event_name' => 'generate_lead',
            'event_id' => 'e-stuck', 'status' => 'queued',
        ]);
        $stuck->forceFill(['created_at' => Carbon::now()->subHours(3)])->save();

        $h = $this->health();

        $this->assertSame('critical', $this->check($h, 'delivery_failed:')['severity'], 'A live destination that failed lost real conversions.');
        $this->assertSame('warning', $this->check($h, 'delivery_stuck:')['severity']);
        $never = $this->check($h, 'never_asked:');
        $this->assertSame('warning', $never['severity']);
        $this->assertStringContainsString('measurement consent', (string) $never['action']);
        $row = $h['deliveries'][0];
        $this->assertSame([1, 1, 1], [$row['failed'], $row['queued_stuck'], $row['skipped_consent']]);
    }

    public function test_a_failed_delivery_on_a_destination_that_is_not_live_is_only_information(): void
    {
        $this->tracking(true);
        $this->slug();
        $dest = $this->as()->postJson("/api/v1/projects/{$this->project->id}/conversion-destinations", ['kind' => 'ga4', 'name' => 'GA4', 'config' => ['measurement_id' => 'G-ABC123XYZ9']])->assertCreated()->json('data.id');
        ConversionDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'conversion_destination_id' => $dest, 'event_name' => 'generate_lead', 'event_id' => 'e-failed', 'status' => 'failed',
        ]);

        $this->assertSame('info', $this->check($this->health(), 'delivery_failed:')['severity']);
    }

    public function test_what_a_platform_reports_is_set_beside_what_the_leads_centre_holds_and_a_big_gap_warns(): void
    {
        $this->slug();
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => 'C', 'status' => 'active', 'objective' => 'leads']);
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $campaign->id, 'provider' => 'meta', 'metric_key' => 'leads', 'metric_date' => Carbon::now()->toDateString(), 'value' => 40,
        ]);
        foreach (range(1, 10) as $n) {
            Lead::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => "L{$n}", 'source' => 'paid', 'status' => 'new', 'provider' => 'meta', 'provider_lead_id' => "m{$n}", 'received_at' => Carbon::now()]);
        }
        app(TenantContext::class)->forget();

        $h = $this->health();

        $this->assertSame([['provider' => 'meta', 'platform_reported' => 40, 'stored' => 10, 'difference' => 30, 'stored_to_reported' => 0.25]], $h['reconciliation']);
        $c = $this->check($h, 'reconcile:meta');
        $this->assertSame('warning', $c['severity']);
        $this->assertStringContainsString('attribution window', (string) $c['action']);
    }

    public function test_it_never_claims_an_accuracy_and_needs_the_permission_and_stays_in_the_project(): void
    {
        $h = $this->health();
        $this->assertStringContainsString('never states an accuracy', $h['confidence_note']);
        $this->assertStringNotContainsString('accuracy_percent', json_encode($h));

        $viewer = User::create(['name' => 'V', 'email' => uniqid().'@v.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $this->actingAs($viewer, 'sanctum')->withHeaders(self::SPA)->getJson("/api/v1/projects/{$this->project->id}/tracking-health")->assertForbidden();
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/tracking-health?from=2020-01-01&to=2026-10-01")->assertStatus(422);
    }
}
