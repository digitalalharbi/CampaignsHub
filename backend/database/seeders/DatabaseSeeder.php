<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Global permission catalogue (all environments — structural, safe in production).
        $this->call(PermissionSeeder::class);

        // 1b) Canonical request service types + lifecycle statuses (structural, all environments).
        $this->call(RequestCatalogSeeder::class);

        // 2) Platform super-admin (idempotent).
        User::firstOrCreate(
            ['email' => 'platform@mediabuying.local'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
                'is_platform_admin' => true,
                'tenant_id' => null,
            ],
        );

        // 3) Demo tenant + demo users + Sandbox data — DEV/LOCAL/DEMO only, NEVER in production.
        if (App::environment(['local', 'testing', 'demo'])) {
            $this->call(DemoSeeder::class);
        }

        // 3b) Heavy demo data (metrics/reports/creatives) — local/demo ONLY, never in `testing`
        //     (test cases seed their own minimal fixtures; this chain would bloat every test DB).
        //     Ordered so a fresh local/demo seed yields a fully-populated demo via the real tables.
        if (App::environment(['local', 'demo'])) {
            $this->call([
                DemoAnalyticsSeeder::class,
                DemoReportsSeeder::class,
                DemoCreativesSeeder::class,
            ]);
        }
    }
}
