<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertRuleEvaluator;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Deterministic demo alert rules on the demo store project, then evaluates them so the notification
 * center has real (demo-flagged) alerts — e.g. the "spend without conversions" burner campaign trips
 * the critical rule. All rows are is_demo and removed cleanly by demo:remove.
 */
final class DemoAlertsSeeder extends Seeder
{
    public function run(): void
    {
        // Seeding runs without a resolved tenant/project context, so bypass the tenant/project scopes.
        $tenant = Tenant::where('slug', 'demo-agency')->first();
        if (! $tenant) {
            return;
        }
        $ws = ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('slug', 'demo-store-analytics')->first();
        $project = $ws ? Project::withoutGlobalScopes()->where('client_workspace_id', $ws->id)->where('name', 'متجر تجريبي — Demo')->first() : null;
        if (! $project) {
            return;
        }

        // Thresholds set against the demo store's actual performance so the notification centre shows
        // realistic, triggered alerts (CPA above a 50-SAR target; ROAS below a 10× target).
        $rules = [
            ['name' => 'تكلفة النتيجة فوق المستهدف (50 SAR)', 'rule_type' => 'cpa_increased', 'threshold' => 50, 'severity' => 'warning'],
            ['name' => 'العائد على الإنفاق دون المستهدف (10×)', 'rule_type' => 'roas_decreased', 'threshold' => 10.0, 'severity' => 'warning'],
            ['name' => 'إنفاق دون تحويلات', 'rule_type' => 'spend_no_conversions', 'threshold' => 3000, 'severity' => 'critical'],
        ];
        foreach ($rules as $r) {
            AlertRule::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'project_id' => $project->id, 'rule_type' => $r['rule_type']],
                $r + ['evaluation_window' => 30, 'cooldown' => 86400, 'is_active' => true, 'is_demo' => true],
            );
        }

        // Evaluate now so the demo notification center is populated with real, demo-flagged alerts.
        app(AlertRuleEvaluator::class)->evaluateProject((string) $tenant->id, (string) $project->id);
    }
}
