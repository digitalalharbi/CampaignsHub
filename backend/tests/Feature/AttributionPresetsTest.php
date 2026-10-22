<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Services\AttributionCredit;
use App\Domains\Metrics\Settings\AttributionPresets;
use App\Domains\Metrics\Settings\AttributionSettings;
use App\Domains\Metrics\Settings\ProjectAttributionSettingVersion;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ATTRIB-PRESETS-001 — BASIC / STANDARD / ADVANCED / CUSTOM over the one credit engine.
 *
 * A preset is a named configuration, not a second engine: BASIC is last touch only, STANDARD adds
 * first touch and linear, ADVANCED adds position-based and time-decay with the advanced options
 * (consent honoured, refunds excluded, eligible channels), CUSTOM is the weights themselves. Advanced
 * is OFF by default. Every change is kept as a version, so a comparison can be named with the
 * configuration it was computed under; the comparison itself is untouched.
 */
final class AttributionPresetsTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'preset-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking', 'attribution_intelligence']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'default_currency' => 'SAR', 'timezone' => 'Asia/Riyadh']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@preset.test', 'password' => 'secret1234']);
        $this->owner->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $this->owner, tenant: $this->tenant, portal: Portal::App, role: 'owner'));
        app(TenantContext::class)->forget();
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => 'tenant', 'scope_key' => $this->tenant->id, 'module' => 'attribution_intelligence'],
            ['scope_id' => null, 'enabled' => true],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);
    }

    private function as(): \Illuminate\Foundation\Testing\TestCase
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    private function url(): string
    {
        return "/api/v1/projects/{$this->project->id}/attribution-settings";
    }

    public function test_the_catalogue_names_four_presets_and_basic_is_the_default_before_anything_is_chosen(): void
    {
        $data = $this->as()->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(['basic', 'standard', 'advanced', 'custom'], array_keys($data['presets']));
        $this->assertSame('basic', $data['default_preset']);
        $this->assertFalse($data['presets']['basic']['advanced']);
        $this->assertTrue($data['presets']['advanced']['advanced']);
        $this->assertNull($data['current']);
        $this->assertSame([], $data['versions']);
    }

    public function test_each_preset_runs_exactly_its_models_and_advanced_is_off_below_advanced(): void
    {
        $this->as()->putJson($this->url(), ['preset' => 'basic'])->assertOk();
        $config = app(AttributionSettings::class)->creditConfig((string) $this->tenant->id, (string) $this->project->id);
        $this->assertSame(['last_click'], $config['models']);
        $this->assertSame('basic', $config['preset']);
        $this->assertNull($config['advanced']);

        // Models sent beside a preset are ignored: a preset is a decision, not a suggestion.
        $this->as()->putJson($this->url(), ['preset' => 'standard', 'models' => ['time_decay'], 'lookback_days' => 45])->assertOk();
        $config = app(AttributionSettings::class)->creditConfig((string) $this->tenant->id, (string) $this->project->id);
        $this->assertSame(['last_click', 'first_click', 'linear'], $config['models']);
        $this->assertSame(45, $config['lookback_days']);
        $this->assertNull($config['advanced']);

        $this->as()->putJson($this->url(), ['preset' => 'advanced', 'advanced' => ['eligible_channels' => ['snapchat', 'tiktok'], 'exclude_refunds' => false]])->assertOk();
        $config = app(AttributionSettings::class)->creditConfig((string) $this->tenant->id, (string) $this->project->id);
        $this->assertSame(['last_click', 'first_click', 'linear', 'position_based', 'time_decay'], $config['models']);
        $this->assertSame(['honour_consent' => true, 'exclude_refunds' => false, 'eligible_channels' => ['snapchat', 'tiktok']], $config['advanced']);
    }

    public function test_custom_takes_the_weights_themselves_and_refuses_without_them(): void
    {
        $this->as()->putJson($this->url(), ['preset' => 'custom'])->assertStatus(422);

        $this->as()->putJson($this->url(), ['preset' => 'custom', 'models' => ['linear'], 'weights' => ['first' => 1, 'middle' => 1, 'last' => 2]])->assertOk();
        $config = app(AttributionSettings::class)->creditConfig((string) $this->tenant->id, (string) $this->project->id);
        $this->assertSame(['linear', 'custom_weighted'], $config['models']);
        $this->assertEqualsWithDelta(0.5, $config['weights']['last'], 0.001);
        $this->assertSame('custom', $config['preset']);
    }

    /** The pre-preset contract still works and is recorded as CUSTOM, so an older caller changes nothing. */
    public function test_a_request_without_a_preset_is_the_old_contract_stored_as_custom(): void
    {
        $this->as()->putJson($this->url(), ['models' => ['linear', 'last_click'], 'lookback_days' => 30])->assertOk()
            ->assertJsonPath('data.current.preset', 'custom')
            ->assertJsonPath('data.current.models', ['linear', 'last_click']);
    }

    public function test_every_change_is_a_version_newest_first_and_an_unknown_preset_is_refused(): void
    {
        $this->as()->putJson($this->url(), ['preset' => 'basic'])->assertOk();
        $this->as()->putJson($this->url(), ['preset' => 'standard'])->assertOk();
        $data = $this->as()->putJson($this->url(), ['preset' => 'advanced'])->assertOk()->json('data');

        $this->assertSame([3, 2, 1], array_column($data['versions'], 'number'));
        $this->assertSame(['advanced', 'standard', 'basic'], array_column($data['versions'], 'preset'));
        $this->assertSame(AttributionCredit::VERSION, $data['versions'][0]['version']);
        $this->assertSame(3, ProjectAttributionSettingVersion::withoutGlobalScopes()->where('project_id', $this->project->id)->count());

        $this->as()->putJson($this->url(), ['preset' => 'premium'])->assertStatus(422);
        $this->assertSame(AttributionPresets::DEFAULT, 'basic');
    }
}
