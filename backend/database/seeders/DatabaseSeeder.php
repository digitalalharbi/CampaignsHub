<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Lead;
use App\Domains\Integrations\Actions\EstablishSandboxConnection;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Models\Task;
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

        // Demo client workspaces (3 modes) + projects + a task + notification + sandbox AI key.
        if (ClientWorkspace::count() === 0) {
            $modes = ['managed' => 'Acme (Managed) — Demo', 'collaborative' => 'Nova (Collaborative) — Demo', 'self_service' => 'Zahra (Self-Service) — Demo'];
            $firstProject = null;
            foreach ($modes as $mode => $name) {
                $ws = ClientWorkspace::create([
                    'name' => $name,
                    'slug' => 'demo-'.$mode,
                    'mode' => $mode,
                    'branding' => ['brand_name' => $name],
                ]);
                $project = Project::create([
                    'client_workspace_id' => $ws->id,
                    'name' => 'Q3 Launch — Demo',
                    'account_manager_id' => $ownerUser->id,
                    'status' => 'active',
                    'setup_completion' => 70,
                ]);
                $firstProject ??= $project;
            }

            // Bind a Sandbox ad account to the FIRST demo project only, so switching projects in the
            // UI visibly changes bound accounts (second/third projects start empty).
            if ($firstProject !== null) {
                $result = app(EstablishSandboxConnection::class)->execute('client_shared', 'Demo Sandbox connection');
                $adAccount = $result['accounts']->firstWhere('account_type', 'ad_account');
                ProjectIntegrationBinding::create([
                    'project_id' => $firstProject->id,
                    'external_account_id' => $adAccount->id,
                    'provider' => 'sandbox',
                    'purpose' => 'advertising',
                    'is_primary' => true,
                    'campaign_management_enabled' => true,
                ]);
            }

            // Project-scoped task on the FIRST demo project only (so switching projects changes
            // tasks too, not just bound accounts).
            Task::create([
                'title' => 'Prepare tracking — Demo',
                'status' => 'in_progress',
                'priority' => 'high',
                'project_id' => $firstProject?->id,
                'assignee_id' => $ownerUser->id,
                'created_by' => $ownerUser->id,
            ]);

            AppNotification::create([
                'user_id' => $ownerUser->id,
                'type' => 'integration.disconnected',
                'severity' => 'warning',
                'title' => 'Sandbox integration needs attention — Demo',
                'message' => 'Simulated alert for the demo tour.',
            ]);

            // Sandbox AI key (clearly marked, encrypted at rest).
            $aiKey = new AIProviderCredential([
                'provider' => 'openai',
                'credential_scope' => 'tenant',
                'status' => 'active',
                'created_by' => $ownerUser->id,
                'allowed_models' => ['gpt-4o-mini'],
            ]);
            $aiKey->setSecret('sk-DEMO-SANDBOX-0000');
            $aiKey->save();
        }

        $context->forget();
    }
}
