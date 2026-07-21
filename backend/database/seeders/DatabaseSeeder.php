<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\CRM\Models\Lead;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Global permission catalogue (all environments).
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

        // 3) Demo tenant with seed data — DEV/LOCAL only, never in production.
        if (App::environment(['local', 'testing'])) {
            $this->seedDemoTenant();
        }
    }

    private function seedDemoTenant(): void
    {
        $context = app(TenantContext::class);

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo-agency'],
            ['name' => 'Demo Agency', 'status' => 'active'],
        );

        $context->setTenantId((string) $tenant->id);

        Workspace::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'default'],
            ['name' => 'Default Workspace'],
        );

        $owner = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
            ['name' => 'Tenant Owner', 'is_system' => true],
        );
        // Owner gets every permission.
        $owner->givePermissionTo(...Permission::pluck('key')->all());

        $manager = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'account-manager'],
            ['name' => 'Account Manager', 'is_system' => true],
        );
        $manager->givePermissionTo(
            'clients.view', 'clients.create', 'clients.update',
            'leads.view', 'leads.create', 'leads.update', 'leads.convert',
            'campaigns.view', 'content.view', 'content.approve', 'reports.view', 'reports.export',
        );

        $ownerUser = User::firstOrCreate(
            ['email' => 'owner@demo-agency.local'],
            ['name' => 'Demo Owner', 'password' => Hash::make('password'), 'tenant_id' => $tenant->id],
        );
        $ownerUser->assignRole($owner);

        // Demo CRM leads (only if none exist yet for this tenant).
        if (Lead::count() === 0) {
            $seed = [
                ['name' => 'Acme Co', 'source' => 'website', 'status' => 'qualified', 'estimated_value' => 12000],
                ['name' => 'Nova Retail', 'source' => 'referral', 'status' => 'new', 'estimated_value' => 8400],
                ['name' => 'Zahra Store', 'source' => 'whatsapp', 'status' => 'proposal_sent', 'estimated_value' => 21000],
                ['name' => 'Falcon Media', 'source' => 'event', 'status' => 'negotiation', 'estimated_value' => 5600],
                ['name' => 'Bright Foods', 'source' => 'paid', 'status' => 'contacted', 'estimated_value' => 15250],
            ];
            foreach ($seed as $row) {
                Lead::create(array_merge($row, [
                    'owner_id' => $ownerUser->id,
                    'currency' => 'SAR',
                ]));
            }
        }

        $context->forget();
    }
}
