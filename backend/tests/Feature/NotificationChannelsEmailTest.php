<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Providers\LaravelMailProvider;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NOTIFICATION-CHANNELS-001 — Owner, 2026-11-06: «أحتاج كل شيء يكون إشعارات على الإيميل + النظام».
 *
 * An automation rule that matched used to reach the bell and nothing else: its type was in neither
 * `MAILED_HERE` nor `MAILED_ELSEWHERE`, so the dispatcher wrote `in_app_only` and moved on. These tests
 * pin the other half — the same notification now reaches an inbox through the one mailer and its ledger,
 * under every control a person already has — and pin that the controls still win.
 */
final class NotificationChannelsEmailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'operator-login',
            'providers.channels.email' => LaravelMailProvider::class,
        ]);
        Mail::fake();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.Str::random(5), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.Str::random(5), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->user = $this->member();
    }

    /** @param list<string> $denied */
    private function member(array $denied = []): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Staff', 'slug' => 'staff-'.Str::random(5)]);
        $role->givePermissionTo('projects.view', 'campaigns.view', 'analytics.view');
        $user = User::create(['name' => 'M', 'email' => 'm-'.Str::random(6).'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now(), 'locale' => 'ar']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);
        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $user->id,
            'role' => ProjectRole::MEDIA_BUYER, 'status' => 'active', 'denied' => $denied,
        ]);

        return $user;
    }

    /** @param array<string,mixed> $over */
    private function automation(array $over = []): array
    {
        return $over + [
            'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->user->id, 'project_id' => (string) $this->project->id,
            'type' => 'automation_rule', 'severity' => 'warning', 'title' => 'Rule «Pause losers» matched: Eid sale',
            'message' => 'cost_per_result = 41.2', 'title_ar' => 'طابقت القاعدة «إيقاف الخاسرة»: حملة العيد',
            'source' => 'automation', 'entity_type' => 'automation_rule', 'entity_id' => 'rule-1',
            'action_url' => '/automation', 'dedup_extra' => 'camp-1',
        ];
    }

    private function emailStatus(string $notificationId): ?string
    {
        return DB::table('notification_deliveries')->where('notification_id', $notificationId)->where('channel', 'email')->value('status');
    }

    private function preference(array $columns): void
    {
        DB::table('notification_preferences')->insert($columns + [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]), 'categories' => json_encode([]),
            'frequency' => 'realtime', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_automation_match_reaches_the_inbox_and_leaves_a_ledger_row(): void
    {
        $n = app(NotificationDispatcher::class)->dispatch($this->automation());

        $this->assertNotNull($n);
        $this->assertNotContains($this->emailStatus((string) $n->id), ['in_app_only', 'handled_elsewhere', null], 'the match stayed in the bell');
        $row = DB::table('mail_deliveries')->where('kind', 'automation_rule')->first();
        $this->assertNotNull($row, 'no mail_deliveries row — nothing went through the one mailer');
        $this->assertSame($this->user->email, $row->recipient);
        $this->assertSame((int) $this->user->id, (int) $row->user_id);
    }

    public function test_the_person_switching_the_type_off_wins(): void
    {
        $this->preference(['types' => json_encode(['automation_rule' => ['email' => false, 'in_app' => true]])]);

        $n = app(NotificationDispatcher::class)->dispatch($this->automation());

        $this->assertSame('suppressed_by_preference', $this->emailStatus((string) $n->id));
        $this->assertSame(0, DB::table('mail_deliveries')->where('kind', 'automation_rule')->count());
    }

    /** A row written before the type had a switch: «الأداء» email off was already a statement about it. */
    public function test_an_older_category_choice_is_honoured_rather_than_overruled(): void
    {
        $this->preference(['categories' => json_encode(['performance' => ['in_app' => true, 'email' => false]])]);

        $n = app(NotificationDispatcher::class)->dispatch($this->automation());

        $this->assertSame('suppressed_by_preference', $this->emailStatus((string) $n->id));
        $this->assertSame(0, DB::table('mail_deliveries')->where('kind', 'automation_rule')->count());
    }

    public function test_quiet_hours_hold_the_mail_but_not_the_bell(): void
    {
        Carbon::setTestNow('2026-11-06 23:30:00');
        $this->preference(['timezone' => 'UTC', 'quiet_hours' => json_encode(['enabled' => true, 'start' => '22:00', 'end' => '08:00'])]);

        $n = app(NotificationDispatcher::class)->dispatch($this->automation());
        Carbon::setTestNow();

        $this->assertNotNull($n);
        $this->assertSame('suppressed_by_quiet_hours', $this->emailStatus((string) $n->id));
        $this->assertSame(0, DB::table('mail_deliveries')->where('kind', 'automation_rule')->count());
    }

    public function test_a_repeat_inside_the_window_is_one_bell_item_and_one_mail(): void
    {
        $dispatcher = app(NotificationDispatcher::class);
        $this->assertNotNull($dispatcher->dispatch($this->automation()));
        $this->assertNull($dispatcher->dispatch($this->automation()));

        $this->assertSame(1, DB::table('mail_deliveries')->where('kind', 'automation_rule')->count());
    }

    /** One rule matching two hundred campaigns must not be two hundred emails. */
    public function test_a_burst_is_capped_for_the_inbox_and_still_fully_in_the_bell(): void
    {
        $dispatcher = app(NotificationDispatcher::class);
        $last = null;
        for ($i = 1; $i <= 8; $i++) {
            $last = $dispatcher->dispatch($this->automation(['dedup_extra' => 'camp-'.$i]));
        }

        $this->assertSame(5, DB::table('mail_deliveries')->where('kind', 'automation_rule')->count());
        $this->assertSame('suppressed_by_ceiling', $this->emailStatus((string) $last->id));
        $this->assertSame(8, DB::table('app_notifications')->where('type', 'automation_rule')->count());
    }

    /** A message that names a campaign and its cost does not go to a member whose membership withholds money. */
    public function test_a_member_whose_membership_withholds_money_gets_no_spend_bearing_mail(): void
    {
        $buyer = $this->member([ProjectCapability::MONEY_VIEW]);

        $n = app(NotificationDispatcher::class)->dispatch($this->automation(['user_id' => $buyer->id]));

        $this->assertSame('suppressed_client_audience', $this->emailStatus((string) $n->id));
        $this->assertSame(0, DB::table('mail_deliveries')->where('kind', 'automation_rule')->count());
    }

    public function test_the_mail_is_in_the_readers_language_through_the_operational_template(): void
    {
        app(NotificationDispatcher::class)->dispatch($this->automation());

        $row = DB::table('mail_deliveries')->where('kind', 'automation_rule')->first();
        $this->assertSame('ar', $row->locale);
        $this->assertSame('mail.operational', $row->template);
    }

    public function test_a_finished_report_is_mailed_to_its_author_but_an_alert_post_is_left_to_the_alert_mailer(): void
    {
        $dispatcher = app(NotificationDispatcher::class);

        $report = $dispatcher->dispatch([
            'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->user->id, 'type' => 'report_ready', 'title' => 'Report ready: R',
            'source' => 'reports', 'entity_type' => 'report', 'entity_id' => 'r1', 'action_url' => '/reports',
        ]);
        $alert = $dispatcher->dispatch([
            'tenant_id' => (string) $this->tenant->id, 'user_id' => $this->user->id, 'type' => 'report_failed', 'title' => 'Report failed',
            'source' => 'alerts', 'entity_type' => 'alert_event', 'entity_id' => 'e1', 'action_url' => '/alerts',
        ]);

        $this->assertContains($this->emailStatus((string) $report->id), ['queued', 'sent', 'sandbox'], 'the finished report was never mailed');
        $this->assertSame('handled_elsewhere', $this->emailStatus((string) $alert->id), 'the alert mailer owns the alert, and the ledger must not claim a second mail');
    }
}
