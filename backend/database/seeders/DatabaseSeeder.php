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
    }
}
