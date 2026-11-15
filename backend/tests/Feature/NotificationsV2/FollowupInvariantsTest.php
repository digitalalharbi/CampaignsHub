<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\TestCase;

/**
 * NOTIF-V2-FOLLOWUP-001 / NOTIF-V2-INTERNAL-001 — the rules that must hold in EVERY workspace type, for every tenant, and that the
 * assistant never breaks (Owner spec §0, §6 «NO automatic edits», §16 «Generalisation / Isolation»).
 */
final class FollowupInvariantsTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 05:10:00', 'UTC')); // Sunday 08:10 Riyadh
        $this->seedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runJobs(): void
    {
        app()->forgetScopedInstances();
        Artisan::call('notifications:v2-internal-scan');
        app()->forgetScopedInstances();
        Artisan::call('notifications:v2-morning-brief');
    }

    /** @return array<string, string> a digest of every table the assistant must never write */
    private function untouchable(): array
    {
        $out = [];
        foreach (['project_fundings', 'funding_payments', 'spend_limits', 'external_campaigns', 'unified_campaigns', 'project_integration_bindings', 'agent_autonomy_grants'] as $table) {
            $out[$table] = Schema::hasTable($table) ? md5(json_encode(DB::table($table)->orderBy('id')->get())) : 'absent';
        }

        return $out;
    }

    public function test_the_assistant_never_edits_a_budget_a_limit_a_campaign_or_a_platform_link(): void
    {
        $tenant = $this->tenant('Alpha', 'agency');
        $project = $this->project($tenant, 'Launch');
        $owner = $this->member($tenant, 'tenant-owner');
        $before = $this->untouchable();

        $this->runJobs();
        $tasks = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/tasks')->assertOk()->json('data');
        $this->assertNotEmpty($tasks);
        $id = $tasks[0]['id'];
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/followup/tasks/{$id}/snooze", ['preset' => 'tomorrow'])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/followup/tasks/{$id}/close")->assertOk();
        $this->runJobs();

        $this->assertSame($before, $this->untouchable());
        $this->assertSame($project->id, DB::table('projects')->where('id', $project->id)->value('id'));
    }

    public function test_the_assistants_code_cannot_write_money_or_campaign_tables_by_construction(): void
    {
        $forbidden = ['project_fundings', 'funding_payments', 'spend_limits', 'external_campaigns', 'unified_campaigns', 'ProjectFunding', 'FundingPayment', 'SpendLimit', 'AddFundingPayment', 'FundingLedgerActions', 'CampaignWriteService'];
        $allowedReads = ['ProjectBudgetReader'];
        foreach (['Followup', 'Internal'] as $dir) {
            foreach ((new Finder)->files()->in(app_path('Domains/Notifications/'.$dir))->name('*.php') as $file) {
                $src = (string) file_get_contents($file->getRealPath());
                // The observers NAME a change after the fact; they import the model classes to listen to them, never to write.
                if ($file->getFilename() === 'BudgetAndProjectObservers.php') {
                    $this->assertDoesNotMatchRegularExpression('/(::create\(|->update\(|->delete\(|->save\(|->forceFill\(|insert\()/', $src, 'observers only read');

                    continue;
                }
                foreach ($forbidden as $word) {
                    $this->assertStringNotContainsString($word, str_replace($allowedReads, '', $src), $file->getFilename().' must not touch '.$word);
                }
            }
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function workspaceTypes(): array
    {
        return [
            'agency' => ['agency', 'agency', 'مشروع', 'عميل'],
            'direct business' => ['brand', 'app', 'حملة', 'علامة'],
        ];
    }

    /** @dataProvider workspaceTypes */
    public function test_follow_up_and_brief_work_in_an_agency_and_in_a_direct_business_each_in_its_own_words(string $accountType, string $portal, string $projectWord, string $clientWord): void
    {
        $tenant = $this->tenant('WS '.$accountType, $accountType);
        $this->project($tenant, 'P1');
        $owner = $this->member($tenant, 'tenant-owner', null, $portal);
        app(TenantContext::class)->forget();

        $this->runJobs();

        $tasks = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/tasks')->assertOk();
        $this->assertSame($projectWord, $tasks->json('meta.terms.project'));
        $this->assertSame($clientWord, $tasks->json('meta.terms.client'));
        $this->assertCount(1, $tasks->json('data'));
        $this->assertSame('WS '.$accountType, $tasks->json('data.0.workspace_name'));
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/brief')->assertOk()->assertJsonPath('data.date', '2026-11-15');
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/notifications')->assertOk();
    }

    public function test_a_client_portal_workspace_member_has_the_centre_without_sensitive_events_and_no_follow_up_or_brief(): void
    {
        $tenant = $this->tenant('Portal Co', 'agency');
        $project = $this->project($tenant, 'Theirs');
        $portalUser = $this->member($tenant, 'client-viewer', [], 'portal');
        app(TenantContext::class)->forget();

        $this->runJobs();
        app(NotificationEngine::class)->raise(new DeliveryIntent(eventKey: 'project.created', tenantId: $tenant->id, projectId: $project->id, objectType: 'project', objectId: $project->id));
        Artisan::call('notifications:v2-send');

        $this->actingAs($portalUser, 'sanctum')->getJson('/api/v1/followup/tasks')->assertForbidden();
        $this->actingAs($portalUser, 'sanctum')->getJson('/api/v1/followup/brief')->assertForbidden();
        $this->assertSame(0, DB::table('followup_briefs')->where('user_id', $portalUser->id)->count());
        $this->assertSame(0, DB::table('notification_outbox')->where('recipient_user_id', $portalUser->id)->whereIn('event_key', ['project.no_budget_for_period', 'followup.morning_brief'])->count());
        $this->actingAs($portalUser, 'sanctum')->getJson('/api/v1/notifications')->assertOk();
    }

    public function test_tasks_briefs_and_notifications_of_one_workspace_never_appear_in_another(): void
    {
        $a = $this->tenant('Alpha', 'agency');
        $this->project($a, 'Only A');
        $userA = $this->member($a, 'tenant-owner');
        $b = $this->tenant('Beta', 'agency');
        $this->project($b, 'Only B');
        $userB = $this->member($b, 'tenant-owner');
        app(TenantContext::class)->forget();

        $this->runJobs();
        Artisan::call('notifications:v2-send');

        $seen = fn (User $u) => json_encode([
            $this->actingAs($u, 'sanctum')->getJson('/api/v1/followup/tasks?status=all')->json('data'),
            $this->actingAs($u, 'sanctum')->getJson('/api/v1/followup/brief')->json('data'),
            $this->actingAs($u, 'sanctum')->getJson('/api/v1/notifications')->json('data'),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Only A', $seen($userA));
        $this->assertStringNotContainsString('Only B', $seen($userA));
        $this->assertStringContainsString('Only B', $seen($userB));
        $this->assertStringNotContainsString('Only A', $seen($userB));

        $taskB = (string) DB::table('followup_tasks')->where('tenant_id', $b->id)->value('id');
        $this->actingAs($userA, 'sanctum')->getJson("/api/v1/followup/tasks/{$taskB}")->assertNotFound();
    }

    public function test_switching_a_key_off_and_on_again_keeps_every_row(): void
    {
        $tenant = $this->tenant('Alpha', 'agency');
        $this->project($tenant, 'Launch');
        $owner = $this->member($tenant, 'tenant-owner');
        app(TenantContext::class)->forget();
        $this->runJobs();
        $counts = fn () => [DB::table('followup_tasks')->count(), DB::table('followup_briefs')->count(), DB::table('followup_task_events')->count()];
        $before = $counts();

        foreach (['followup.tasks', 'followup.morning_brief', 'notifications.internal'] as $key) {
            DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => $key, 'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'paid_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        app()->forgetScopedInstances();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/tasks')->assertForbidden()->assertJsonPath('meta.upgrade', true);
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/brief')->assertForbidden();
        $this->runJobs();
        $this->assertSame($before, $counts(), 'jobs refused and nothing was deleted');

        DB::table('notification_plan_features')->delete();
        app()->forgetScopedInstances();
        $this->assertCount(1, $this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/tasks')->assertOk()->json('data'));
    }

    public function test_no_emoji_in_any_follow_up_or_centre_string(): void
    {
        $pattern = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{FE0F}\x{20E3}]/u';
        foreach (['ar', 'en'] as $locale) {
            $strings = json_encode([__('notifications_v2.followup', [], $locale), __('notifications_v2.events', [], $locale)], JSON_UNESCAPED_UNICODE);
            $this->assertSame(0, preg_match($pattern, (string) $strings), $locale);
        }
        $row = AppNotification::class;
        $this->assertTrue(class_exists($row));
    }
}
