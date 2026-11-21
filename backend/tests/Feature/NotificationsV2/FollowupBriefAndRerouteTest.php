<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Content\CatalogMessageComposer;
use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\PreSendCheck;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\Feature\NotificationsV2\External\Support\FakeDigestData;
use Tests\Support\ConfiguredDigestEmailProvider;
use Tests\TestCase;

/**
 * NOTIF-V2-FOLLOWUP-001 — the morning brief and «أعد التوجيه بضغطة» (Owner spec §6, §16 «Follow-up»), in an agency, a direct
 * business and a client-portal workspace.
 */
final class FollowupBriefAndRerouteTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config()->set('providers.channels.email', ConfiguredDigestEmailProvider::class);
        config()->set('mail.from.address', 'info@campaignshub.io');
        $this->seedPermissions();
        $this->tenant = $this->tenant('Alpha Agency', 'agency');
        $this->at('2026-11-15 05:00:00'); // Sunday 08:00 Riyadh
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function brief(): int
    {
        app()->forgetScopedInstances();

        return (int) Artisan::call('notifications:v2-morning-brief');
    }

    private function briefs(?User $user = null): array
    {
        return DB::table('followup_briefs')->when($user !== null, fn ($q) => $q->where('user_id', $user->id))->get()->all();
    }

    private function confine(User $user, string $projectId): void
    {
        $m = Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('user_id', $user->id)->firstOrFail();
        MembershipScope::create(['membership_id' => $m->id, 'scope_type' => MembershipScope::TYPE_PROJECT, 'scope_id' => $projectId]);
    }

    public function test_each_person_gets_a_brief_about_their_own_projects_only(): void
    {
        $a = $this->project($this->tenant, 'Alpha One');
        $b = $this->project($this->tenant, 'Beta Two');
        $boss = $this->member($this->tenant, 'tenant-owner');
        $mine = $this->member($this->tenant, 'tenant-owner');
        $this->confine($mine, $a->id);

        $this->brief();

        $bossBrief = json_decode((string) $this->briefs($boss)[0]->sections, true);
        $mineBrief = json_decode((string) $this->briefs($mine)[0]->sections, true);
        $this->assertEqualsCanonicalizing(['Alpha One', 'Beta Two'], array_column($bossBrief['no_budget'], 'project_name'));
        $this->assertSame(['Alpha One'], array_column($mineBrief['no_budget'], 'project_name'), 'nothing of Beta Two reaches a person who is not on it');
        $this->assertStringNotContainsString('Beta Two', (string) $this->briefs($mine)[0]->summary.(string) $this->briefs($mine)[0]->sections);
    }

    public function test_the_brief_comes_at_the_time_the_person_chose_in_the_workspace_timezone_once_a_day(): void
    {
        $this->project($this->tenant, 'A');
        $early = $this->member($this->tenant, 'tenant-owner');
        $late = $this->member($this->tenant, 'tenant-owner');
        DB::table('followup_user_settings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $late->id, 'brief_enabled' => true, 'brief_time' => '09:30', 'created_at' => now(), 'updated_at' => now()]);

        $this->at('2026-11-15 04:55:00'); // 07:55 Riyadh
        $this->brief();
        $this->assertSame([], $this->briefs());

        $this->at('2026-11-15 05:05:00'); // 08:05
        $this->brief();
        $this->assertCount(1, $this->briefs($early));
        $this->assertSame([], $this->briefs($late));

        $this->brief(); // the same day again: still one
        $this->assertCount(1, $this->briefs($early));

        $this->at('2026-11-15 06:35:00'); // 09:35
        $this->brief();
        $this->assertCount(1, $this->briefs($late));

        $this->at('2026-11-16 05:05:00'); // the next day
        $this->brief();
        $this->assertCount(2, $this->briefs($early));
    }

    public function test_no_brief_on_a_weekend_or_when_the_person_switched_it_off_or_for_a_client_portal_member(): void
    {
        $this->project($this->tenant, 'A');
        $off = $this->member($this->tenant, 'tenant-owner');
        $portal = $this->member($this->tenant, 'client-viewer', [], 'portal');
        DB::table('followup_user_settings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $off->id, 'brief_enabled' => false, 'brief_time' => '08:00', 'created_at' => now(), 'updated_at' => now()]);

        $this->at('2026-11-13 05:05:00'); // Friday
        $this->brief();
        $this->assertSame([], $this->briefs());

        $this->at('2026-11-15 05:05:00');
        $this->brief();
        $this->assertSame([], $this->briefs($off));
        $this->assertSame([], $this->briefs($portal));
    }

    public function test_the_brief_reaches_the_bell_and_email_only_if_chosen_and_the_key_gates_the_job(): void
    {
        $this->project($this->tenant, 'A');
        $inApp = $this->member($this->tenant, 'tenant-owner');
        $both = $this->member($this->tenant, 'tenant-owner');
        DB::table('followup_user_settings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $both->id, 'brief_enabled' => true, 'brief_time' => '08:00', 'brief_channels' => json_encode(['in_app', 'email']), 'created_at' => now(), 'updated_at' => now()]);

        $this->brief();
        $channels = fn (User $u) => DB::table('notification_outbox')->where('recipient_user_id', $u->id)->where('event_key', 'followup.morning_brief')->pluck('channel')->all();
        $this->assertSame(['in_app'], $channels($inApp));
        $this->assertEqualsCanonicalizing(['in_app', 'email'], $channels($both));

        $this->at('2026-11-15 05:20:00');
        Artisan::call('notifications:v2-send');
        $row = AppNotification::withoutGlobalScopes()->where('user_id', $inApp->id)->where('kind', 'followup.morning_brief')->firstOrFail();
        $this->assertSame($this->briefs($inApp)[0]->summary, $row->message);
        $this->assertSame('/followup?brief=2026-11-15', $row->action_url);

        // The plan key off: the scheduled job refuses; nothing new.
        $this->at('2026-11-16 05:05:00');
        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => 'followup.morning_brief', 'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'paid_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('followup_briefs')->count();
        $this->brief();
        $this->assertSame($before, DB::table('followup_briefs')->count());
        $this->actingAs($inApp, 'sanctum')->getJson('/api/v1/followup/brief')->assertForbidden()->assertJsonPath('meta.upgrade', true);
    }

    public function test_the_brief_api_returns_the_snapshot_and_the_words_of_the_workspace_type(): void
    {
        $business = $this->tenant('Beta Brand', 'brand');
        $this->project($business, 'Spring Campaign');
        $owner = $this->member($business, 'tenant-owner');
        app(TenantContext::class)->forget();
        $this->brief();

        $json = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/brief')->assertOk()->json();
        $this->assertSame('2026-11-15', $json['data']['date']);
        $this->assertSame('Spring Campaign', $json['data']['sections']['no_budget'][0]['project_name']);
        $this->assertSame('حملة', $json['meta']['terms']['project'], 'a direct business says «campaign», an agency says «project»');

        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/followup/settings', ['brief_time' => '07:15', 'brief_channels' => ['in_app', 'email']])->assertOk()->assertJsonPath('data.brief_time', '07:15');
        $this->actingAs($owner, 'sanctum')->putJson('/api/v1/followup/settings', ['brief_time' => '7am'])->assertStatus(422);
    }

    // ── re-route ──

    /** @return array{0: string, 1: AppNotification, 2: User} recipient id, a report notification, the owner */
    private function reroutable(string $status = 'active', string $kind = 'report_ready'): array
    {
        $project = $this->project($this->tenant, 'Launch');
        $owner = $this->member($this->tenant, 'tenant-owner');
        $recipient = (string) Str::uuid();
        DB::table('notification_external_recipients')->insert(['id' => $recipient, 'tenant_id' => $this->tenant->id, 'email' => 'finance@client.test', 'role_key' => 'finance', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $sub = (string) Str::uuid();
        DB::table('notification_subscriptions')->insert(['id' => $sub, 'tenant_id' => $this->tenant->id, 'recipient_id' => $recipient, 'status' => 'active', 'event_keys' => json_encode(['target.reached']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_subscription_projects')->insert(['subscription_id' => $sub, 'project_id' => $project->id, 'tenant_id' => $this->tenant->id]);
        $n = AppNotification::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'user_id' => $owner->id, 'type' => 'report_ready', 'kind' => $kind, 'title' => 'Report ready', 'message' => 'm',
            'status' => 'unread', 'severity' => 'info', 'source' => 'reports', 'entity_id' => 'r1', 'dedup_key' => 'r1']);

        return [$recipient, $n, $owner];
    }

    private function externalReadyComposer(): void
    {
        app()->bind(MessageComposer::class, fn () => new class(app(CatalogMessageComposer::class)) implements MessageComposer
        {
            public function __construct(private readonly CatalogMessageComposer $base) {}

            public function compose(Delivery $delivery): ComposedMessage
            {
                $m = $this->base->compose($delivery);

                return new ComposedMessage($m->subject, $m->title, $m->body, $m->html, $m->text, null, true);
            }
        });
    }

    public function test_a_report_is_rerouted_to_a_confirmed_subscribed_recipient_through_the_same_pipeline_and_log(): void
    {
        [$recipient, $n, $owner] = $this->reroutable();
        // RELEASE-INTEGRATION — builder Q's composer and mailer are bound now: the re-routed report is Q's designed external
        // message (identity, footer, disclaimer, unsubscribe), over figures from a fake data source, sent on the array transport.
        $this->app->instance(DigestDataSource::class, new FakeDigestData);

        $list = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/reroute/recipients?notification_id='.$n->id)->assertOk()->json('data');
        $this->assertTrue($list['allowed']);
        $this->assertSame([$recipient], array_column($list['recipients'], 'id'));

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/followup/reroute', ['notification_id' => $n->id, 'recipient_id' => $recipient])->assertStatus(202)->assertJsonPath('data.queued', 1);
        app()->forgetScopedInstances();
        Artisan::call('notifications:v2-send');

        // A new recipient's first messages wait for a person (Q's review): held, logged, nothing sent yet.
        $held = DB::table('notification_delivery_log')->where('event_key', 'report.rerouted')->first();
        $this->assertSame('held_for_review', $held->status);
        $this->assertSame('Launch', $held->project_name);
        $this->assertSame('Alpha Agency', $held->workspace_name);
        $review = DB::table('notification_external_reviews')->where('reference', $held->reference)->value('id');
        $this->assertNotNull($review);

        // «أرسل» on the review releases it through the same pipeline: Q's designed message, logged sent.
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/settings/workspace/notifications/external/reviews/'.$review.'/send')->assertOk();
        app()->forgetScopedInstances();
        Artisan::call('notifications:v2-send');

        $sent = app('mailer')->getSymfonyTransport()->messages()
            ->filter(static fn (SentMessage $m): bool => $m->getOriginalMessage() instanceof Email && $m->getOriginalMessage()->getHeaders()->has('X-CH-Reference'))
            ->map(static fn (SentMessage $m): Email => $m->getOriginalMessage())->values()->all();
        $this->assertCount(1, $sent);
        $this->assertSame('finance@client.test', $sent[0]->getTo()[0]->getAddress());
        $this->assertStringContainsString('Launch', (string) $sent[0]->getSubject());
        $this->assertTrue($sent[0]->getHeaders()->has('List-Unsubscribe'));
        // Owner 2026-11-15: the external message IS the approved template in its external mode (summary only) — it carries no event sentence;
        // the subject names the project and the workspace, and no missing-string key may reach the recipient (checked below).
        $this->assertStringContainsString('Alpha Agency', (string) $sent[0]->getSubject());
        preg_match_all('/notifications_external\.[a-z_.]+/', (string) $sent[0]->getHtmlBody(), $m);
        $this->assertSame([], $m[0], 'no missing-string key reaches the recipient');
        $this->assertSame('sent', DB::table('notification_delivery_log')->where('event_key', 'report.rerouted')->where('status', 'sent')->value('status'));

        // Same item, same recipient, same day: once.
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/followup/reroute', ['notification_id' => $n->id, 'recipient_id' => $recipient])->assertStatus(202)->assertJsonPath('data.queued', 0);
    }

    public function test_review_mode_and_pre_send_checks_apply_to_a_rerouted_report(): void
    {
        [$recipient, $n, $owner] = $this->reroutable();
        $this->externalReadyComposer();
        // A review/check of builder Q's kind, registered on the same tag: the rerouted report is held, not sent.
        $held = new class implements PreSendCheck
        {
            public function check(Delivery $delivery): ?array
            {
                return $delivery->eventKey === 'report.rerouted' ? [DeliveryStatus::HeldForReview, 'first_messages'] : null;
            }
        };
        app()->instance('nv2.fake.review', $held);
        app()->tag(['nv2.fake.review'], 'notifications.presend');

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/followup/reroute', ['notification_id' => $n->id, 'recipient_id' => $recipient])->assertStatus(202);
        app()->forgetScopedInstances();
        Artisan::call('notifications:v2-send');

        Mail::assertNothingSent();
        $this->assertSame('held_for_review', DB::table('notification_delivery_log')->where('event_key', 'report.rerouted')->value('status'));
    }

    public function test_the_privacy_check_holds_a_rerouted_report_whose_project_is_outside_the_subscription(): void
    {
        [$recipient, $n, $owner] = $this->reroutable();
        $this->externalReadyComposer();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/followup/reroute', ['notification_id' => $n->id, 'recipient_id' => $recipient])->assertStatus(202);
        // The subscription is edited away from the project after queueing.
        DB::table('notification_subscription_projects')->delete();
        app()->forgetScopedInstances();
        Artisan::call('notifications:v2-send');

        Mail::assertNothingSent();
        $this->assertSame('held_for_privacy', DB::table('notification_delivery_log')->where('event_key', 'report.rerouted')->value('status'));
    }

    public function test_a_sensitive_operational_notice_or_an_unconfirmed_or_unsubscribed_recipient_cannot_be_rerouted(): void
    {
        [$recipient, $n, $owner] = $this->reroutable('active', 'sync_failed');
        $post = fn (string $id, string $rid) => $this->actingAs($owner, 'sanctum')->postJson('/api/v1/followup/reroute', ['notification_id' => $id, 'recipient_id' => $rid]);

        $post($n->id, $recipient)->assertStatus(422);
        $this->assertSame(0, DB::table('notification_outbox')->where('event_key', 'report.rerouted')->count());
        $this->assertFalse($this->actingAs($owner, 'sanctum')->getJson('/api/v1/followup/reroute/recipients?notification_id='.$n->id)->json('data.allowed'));

        $report = AppNotification::create(['tenant_id' => $this->tenant->id, 'project_id' => $n->project_id, 'user_id' => $owner->id, 'type' => 'report_ready', 'kind' => 'report_ready', 'title' => 'R', 'message' => 'm',
            'status' => 'unread', 'severity' => 'info', 'source' => 'reports', 'entity_id' => 'r2', 'dedup_key' => 'r2']);
        DB::table('notification_external_recipients')->where('id', $recipient)->update(['status' => 'pending']);
        $post($report->id, $recipient)->assertStatus(422);

        DB::table('notification_external_recipients')->where('id', $recipient)->update(['status' => 'active']);
        DB::table('notification_subscription_projects')->delete();
        $post($report->id, $recipient)->assertStatus(422);
        $this->assertSame(0, DB::table('notification_outbox')->where('event_key', 'report.rerouted')->count());
    }

    public function test_the_external_feature_off_refuses_a_reroute_on_the_server(): void
    {
        [$recipient, $n, $owner] = $this->reroutable();
        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => 'notifications.external', 'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'paid_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/followup/reroute', ['notification_id' => $n->id, 'recipient_id' => $recipient])->assertStatus(403);
        $this->assertSame(0, DB::table('notification_outbox')->where('event_key', 'report.rerouted')->count());
    }

    public function test_one_workspace_cannot_reach_another_workspaces_recipients_or_tasks(): void
    {
        [$recipient, $n, $owner] = $this->reroutable();
        $other = $this->tenant('Gamma', 'agency');
        $stranger = $this->member($other, 'tenant-owner');
        $this->project($other, 'G');

        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/followup/reroute', ['notification_id' => $n->id, 'recipient_id' => $recipient])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/followup/reroute/recipients?notification_id='.$n->id)->assertNotFound();
        $this->assertSame([], $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/followup/tasks')->json('data'));
    }
}
