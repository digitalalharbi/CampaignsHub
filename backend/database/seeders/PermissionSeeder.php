<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Seeds the global permission catalogue. Keys follow "<group>.<action>".
 * Sensitive actions (launch/pause/budget/refund) are separate permissions so they can be gated.
 */
final class PermissionSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private array $catalogue = [
        'clients' => ['view', 'create', 'update', 'delete'],
        'leads' => ['view', 'create', 'update', 'delete', 'convert'],
        'proposals' => ['view', 'create', 'update', 'approve'],
        'campaigns' => ['view', 'create', 'update', 'launch', 'pause', 'link', 'budget.change'],
        'content' => ['view', 'create', 'update', 'approve', 'reject'],
        'integrations' => ['view', 'connect', 'disconnect'],
        'connections' => ['view', 'manage'],
        'analytics' => ['view'],
        'reports' => ['view', 'create', 'export', 'share', 'approve'],
        'alerts' => ['view', 'manage'],
        'billing' => ['view', 'manage', 'refund'],
        'automations' => ['view', 'manage'],
        'users' => ['view', 'invite', 'update', 'remove'],
        'audit' => ['view'],
        'settings' => ['manage'],
        'workspaces' => ['view', 'create', 'update', 'delete', 'members.manage'],
        'projects' => ['view', 'view.all', 'create', 'update', 'delete', 'manage_members'],
        'ai' => ['view', 'manage', 'use'],
        'notifications' => ['view'],
        'tasks' => ['view', 'create', 'update', 'delete'],
    ];

    public function run(): void
    {
        foreach ($this->catalogue as $group => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(
                    ['key' => "{$group}.{$action}"],
                    ['group' => $group, 'description' => ucfirst($group).' — '.$action],
                );
            }
        }
    }
}
