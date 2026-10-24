<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Services\DeliverySettings;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DELIVERY-SETTINGS-001 — the periodic delivery is configured once per client and the sweep follows it.
 *
 * Before: the sweep read `settings.delivery.weekly`, which nothing in the product could set, always on Monday, always a weekly
 * snapshot. Now an authorised user chooses on/off, weekly or monthly, the day the week begins, and snapshot or live; the sweep runs
 * daily and prepares each client only on its own day, once per period.
 */
final class DeliverySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $viewer;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $owner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $view = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $view->givePermissionTo('clients.view', 'clients.view_all', 'reports.view');
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($owner);
        $this->viewer = User::create(['name' => 'RO', 'email' => 'ro@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->viewer, $this->tenant, Portal::Agency);
        $this->viewer->assignRole($view);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Store', 'status' => 'active']);
    }

    private function save(array $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->putJson('/api/v1/delivery/settings', ['client_workspace_id' => $this->workspace->id] + $body);
    }

    public function test_the_defaults_are_off_weekly_monday_snapshot(): void
    {
        $r = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/delivery/settings?client='.$this->workspace->id)->assertOk();
        $r->assertJsonPath('data.enabled', false)->assertJsonPath('data.cadence', 'weekly')->assertJsonPath('data.week_start', 'monday')->assertJsonPath('data.mode', 'snapshot');
        $this->assertNotEmpty($r->json('data.next_period.from'));
    }

    public function test_an_authorised_user_saves_the_choices_and_only_the_keys_sent_change(): void
    {
        $this->save(['enabled' => true, 'cadence' => 'monthly', 'mode' => 'live', 'components' => ['spend' => true]])->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.cadence', 'monthly')->assertJsonPath('data.mode', 'live')->assertJsonPath('data.components.spend', true);

        $this->save(['week_start' => 'sunday'])->assertOk()->assertJsonPath('data.week_start', 'sunday')->assertJsonPath('data.cadence', 'monthly')->assertJsonPath('data.enabled', true);
        $this->assertTrue((bool) data_get($this->workspace->refresh()->settings, 'delivery.weekly'), 'the flag the sweep reads is the one written');
    }

    public function test_invalid_values_are_refused_and_a_reader_cannot_change_anything(): void
    {
        $this->save(['cadence' => 'hourly'])->assertStatus(422);
        $this->save(['week_start' => 'friday'])->assertStatus(422);
        $this->save(['mode' => 'draft'])->assertStatus(422);
        $this->save(['enabled' => true], $this->viewer)->assertForbidden();
        $this->assertFalse((bool) data_get($this->workspace->refresh()->settings, 'delivery.weekly'));
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/delivery/settings?client='.$this->workspace->id)->assertOk();
    }

    public function test_another_tenants_client_cannot_be_read_or_changed(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $foreign = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $put = $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/delivery/settings', ['client_workspace_id' => $foreign->id, 'enabled' => true]);
        $get = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/delivery/settings?client='.$foreign->id);
        $this->assertContains($put->status(), [403, 404]);
        $this->assertContains($get->status(), [403, 404]);
        $this->assertFalse((bool) data_get(ClientWorkspace::withoutGlobalScopes()->find($foreign->id)->settings, 'delivery.weekly'));
    }

    public function test_the_period_follows_the_cadence_and_the_week_start(): void
    {
        $monday = DeliverySettings::sanitise([]);
        $this->assertSame(['2026-10-12', '2026-10-18'], array_map(fn ($d) => $d->toDateString(), DeliverySettings::period($monday, Carbon::parse('2026-10-19'))));

        $sunday = DeliverySettings::sanitise(['week_start' => 'sunday']);
        $this->assertSame(['2026-10-18', '2026-10-24'], array_map(fn ($d) => $d->toDateString(), DeliverySettings::period($sunday, Carbon::parse('2026-10-25'))));

        $monthly = DeliverySettings::sanitise(['cadence' => 'monthly']);
        $this->assertSame(['2026-10-01', '2026-10-31'], array_map(fn ($d) => $d->toDateString(), DeliverySettings::period($monthly, Carbon::parse('2026-11-01'))));
        $this->assertSame(['2026-02-01', '2026-02-28'], array_map(fn ($d) => $d->toDateString(), DeliverySettings::period($monthly, Carbon::parse('2026-03-10'))));
    }

    public function test_the_daily_sweep_prepares_a_client_only_on_its_own_day_and_once_per_period(): void
    {
        $this->save(['enabled' => true, 'week_start' => 'sunday'])->assertOk();

        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-10-26'])->expectsOutputToContain('0 client(s) opted in')->assertSuccessful(); // Monday: not its day
        $this->assertSame(0, DeliveryPack::withoutGlobalScopes()->count());

        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-10-25'])->expectsOutputToContain('1 pack(s) ensured')->assertSuccessful(); // Sunday
        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-10-25'])->assertSuccessful();
        $pack = DeliveryPack::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, DeliveryPack::withoutGlobalScopes()->count());
        $this->assertSame('2026-10-18', $pack->period_start->toDateString());
        $this->assertSame('2026-10-24', $pack->period_end->toDateString());
    }

    public function test_a_monthly_live_client_gets_a_monthly_live_report_on_the_first(): void
    {
        $this->save(['enabled' => true, 'cadence' => 'monthly', 'mode' => 'live'])->assertOk();

        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-11-02'])->expectsOutputToContain('0 client(s) opted in')->assertSuccessful();
        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-11-01'])->assertSuccessful();

        $pack = DeliveryPack::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('2026-10-01', $pack->period_start->toDateString());
        $this->assertSame('2026-10-31', $pack->period_end->toDateString());
        $report = Report::withoutGlobalScopes()->findOrFail($pack->report_id);
        $this->assertSame('live', $report->mode);
        $this->assertSame('monthly', $report->type);
    }

    public function test_a_client_that_has_not_opted_in_is_never_prepared_by_the_sweep(): void
    {
        $this->artisan('delivery:prepare-weekly', ['--date' => '2026-10-19'])->assertSuccessful();
        $this->assertSame(0, DeliveryPack::withoutGlobalScopes()->count());
    }
}
