<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * EMAIL-SETTINGS-DEPTH-001 — an alert threshold that would silence the alert is refused.
 *
 * `threshold` was validated as `['nullable', 'array']` and nothing more, so any shape at all was
 * stored and handed to the evaluator, which reads `(int) $rule->threshold['days']` and
 * `(float) $rule->threshold['pct']`.
 *
 * The failure that matters is not a crash — it is silence. `days: 0` gives the evaluator a window
 * with no days in it, and `pct: -5` a drop threshold every window clears. Both produce a rule that
 * looks configured on the screen, reports no incidents, and is indistinguishable from an account
 * with nothing wrong. An alert that cannot fire is worse than no alert, because somebody is relying
 * on it.
 *
 * `(int) 'soon'` is 0 in PHP, which is why a string is refused rather than coerced.
 */
final class AlertThresholdValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'at-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'at-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $tenant);
        $this->owner->assignRole($role);
    }

    /** A window with no days in it is not a window. */
    public function test_a_zero_day_window_is_refused(): void
    {
        $this->create(['days' => 0])->assertStatus(422);
    }

    /** A negative percentage is a threshold every window clears — an alert that can never fire. */
    public function test_a_negative_percentage_is_refused(): void
    {
        $this->create(['pct' => -5])->assertStatus(422);
    }

    /** `(int) 'soon'` is 0, so a string that looks like a setting would silently become «no window». */
    public function test_a_non_numeric_threshold_is_refused_rather_than_coerced(): void
    {
        $this->create(['days' => 'soon'])->assertStatus(422);
    }

    /** A budget ratio above 1 means «alert me after I have overspent», which is not a risk warning. */
    public function test_a_budget_ratio_outside_its_range_is_refused(): void
    {
        $this->create(['ratio' => 1.8], 'budget_risk')->assertStatus(422);
        $this->create(['ratio' => 0], 'budget_risk')->assertStatus(422);
    }

    /** A key nobody reads is refused, so a typo does not look configured while doing nothing. */
    public function test_an_unknown_threshold_key_is_refused(): void
    {
        $this->create(['dayz' => 7])->assertStatus(422);
    }

    /** The real settings still save. */
    public function test_a_sensible_threshold_is_accepted(): void
    {
        $this->create(['days' => 7, 'pct' => 25])->assertCreated();
        $this->create(['ratio' => 0.9], 'budget_risk')->assertCreated();
        $this->create(null)->assertCreated();
    }

    private function create(?array $threshold, string $type = 'roas_drop'): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/alerts/rules', [
            'type' => $type,
            'name' => 'Rule '.uniqid(),
            'threshold' => $threshold,
        ]);
    }
}
