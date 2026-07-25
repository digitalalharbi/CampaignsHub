<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demo users (@demo-agency.local) and Sandbox data must never be created outside dev/test/demo.
 * Structural seeding (permissions, platform admin) still runs everywhere.
 */
final class DemoSeederGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seeding_creates_no_demo_accounts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        foreach (['owner@demo-agency.local', 'analyst@demo-agency.local', 'viewer@demo-agency.local'] as $email) {
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
        // Structural seeding still happened.
        $this->assertDatabaseHas('users', ['email' => 'platform@mediabuying.local']);
        $this->assertTrue(Permission::where('key', 'campaigns.view')->exists());
    }

    public function test_local_seeding_creates_demo_accounts(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'owner@demo-agency.local']);
        $this->assertDatabaseHas('users', ['email' => 'viewer@demo-agency.local']);
    }
}
