<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Content\CatalogMessageComposer;
use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\Sender;
use App\Domains\Notifications\Engine\Contracts\UsageCounter;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Engine\Sending\EngineMail;
use App\Domains\Notifications\Engine\Sending\SenderPipeline;
use App\Domains\Notifications\Services\SoundPreferences;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\Support\ConfiguredDigestEmailProvider;
use Tests\TestCase;

/** NOTIF-V2-ENGINE-001 — the sender pipeline and the immutable log (spec §4): no real send, Mail is faked throughout. */
final class SenderPipelineTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC')); // Sunday 12:00 Riyadh
        Mail::fake();
        config()->set('providers.channels.email', ConfiguredDigestEmailProvider::class);
        config()->set('mail.from.address', 'info@campaignshub.io');
        $this->seedPermissions();
        $this->tenant = $this->tenant('Alpha', 'agency');
        $this->owner = $this->member($this->tenant, 'tenant-owner');
        $this->project = $this->project($this->tenant, 'Launch');
        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function raise(string $event, array $extra = []): void
    {
        app()->forgetScopedInstances();
        app(NotificationEngine::class)->raise(new DeliveryIntent(
            eventKey: $event, tenantId: $extra['tenant'] ?? $this->tenant->id, projectId: $extra['project'] ?? $this->project->id,
            objectType: 'campaign', objectId: $extra['object'] ?? 'c-1', subscriptionId: $extra['subscription'] ?? null,
        ));
    }

    private function drain(): array
    {
        app()->forgetScopedInstances();

        return app(SenderPipeline::class)->run();
    }

    /** @return list<string> */
    private function statuses(?string $channel = null): array
    {
        return DB::table('notification_delivery_log')->when($channel, fn ($q) => $q->where('channel', $channel))->orderBy('seq')->pluck('status')->all();
    }

    private function useSender(Sender $sender): void
    {
        $class = get_class($sender);
        app()->instance($class, $sender);
        app()->tag([$class], 'notifications.senders');
    }

    private function externalSubscription(string $status = 'active'): string
    {
        $recipient = (string) Str::uuid();
        DB::table('notification_external_recipients')->insert(['id' => $recipient, 'tenant_id' => $this->tenant->id, 'email' => 'finance@client.test', 'role_key' => 'finance', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $sub = (string) Str::uuid();
        DB::table('notification_subscriptions')->insert(['id' => $sub, 'tenant_id' => $this->tenant->id, 'recipient_id' => $recipient, 'status' => 'active', 'event_keys' => json_encode(['target.reached']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_subscription_projects')->insert(['subscription_id' => $sub, 'project_id' => $this->project->id, 'tenant_id' => $this->tenant->id]);

        return $recipient;
    }

    public function test_an_internal_event_lands_in_the_bell_and_the_email_is_sent_only_on_acceptance(): void
    {
        $this->raise('connection.expired'); // critical: no grouping wait

        $r = $this->drain();

        $this->assertSame(2, $r['sent']); // in-app + email (push: no device, skipped)
        Mail::assertSent(EngineMail::class, fn (EngineMail $m) => $m->hasTo($this->owner->email) && $m->hasFrom('info@campaignshub.io'));
        $bell = DB::table('app_notifications')->where('user_id', $this->owner->id)->first();
        $this->assertSame('connection.expired', $bell->kind);
        $this->assertSame('critical', $bell->severity);
        $this->assertStringContainsString('Launch', (string) $bell->message_ar);
        $email = DB::table('notification_delivery_log')->where('channel', 'email')->first();
        $this->assertSame('sent', $email->status);
        $this->assertNotNull($email->provider_accepted_at);
        $this->assertSame('Launch', $email->project_name);
        $this->assertSame('Alpha', $email->workspace_name);
        $this->assertSame(0, DB::table('notification_outbox')->where('state', '!=', 'done')->count());
        $this->assertSame(['skipped'], $this->statuses('push'));
    }

    public function test_a_transient_failure_is_tried_three_times_with_growing_back_off_then_logged_once_as_failed(): void
    {
        $this->useSender(new class implements Sender
        {
            public int $calls = 0;

            public function channel(): Channel
            {
                return Channel::Email;
            }

            public function send(Delivery $delivery): SendResult
            {
                $this->calls++;

                return SendResult::failed('connection_failed: timed out');
            }
        });
        $this->raise('connection.expired');

        $this->assertSame(1, $this->drain()['retried']);
        $row = DB::table('notification_outbox')->where('channel', 'email')->first();
        $this->assertSame('2026-11-15 09:05:00', Carbon::parse($row->not_before)->utc()->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-11-15 09:05:00', 'UTC'));
        $this->assertSame(1, $this->drain()['retried']);
        $this->assertSame('2026-11-15 09:35:00', Carbon::parse(DB::table('notification_outbox')->where('channel', 'email')->value('not_before'))->utc()->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-11-15 09:35:00', 'UTC'));
        $this->assertSame(1, $this->drain()['failed']);
        $this->assertSame(['failed'], $this->statuses('email'));
        $this->assertSame(3, (int) DB::table('notification_delivery_log')->where('channel', 'email')->value('attempt'));
        $this->assertSame(0, $this->drain()['failed']); // nothing left to try
    }

    public function test_a_permanent_refusal_is_not_retried(): void
    {
        $this->useSender(new class implements Sender
        {
            public function channel(): Channel
            {
                return Channel::Email;
            }

            public function send(Delivery $delivery): SendResult
            {
                return SendResult::failed('sender_rejected', permanent: true);
            }
        });
        $this->raise('connection.expired');

        $this->assertSame(1, $this->drain()['failed']);
        $this->assertSame(['failed'], $this->statuses('email'));
    }

    public function test_a_second_run_never_sends_the_same_delivery_twice_and_a_stale_claim_is_never_resent(): void
    {
        $this->raise('connection.expired');
        $this->drain();
        $this->drain();
        Mail::assertSentCount(1);

        $this->raise('connection.expired', ['object' => 'c-2']);
        DB::table('notification_outbox')->where('state', 'pending')->where('channel', 'email')->update(['state' => 'claimed', 'attempts' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:30:00', 'UTC'));
        $r = $this->drain();
        $this->assertSame(1, $r['abandoned']);
        Mail::assertSentCount(1);
        $this->assertSame('abandoned_after_claim', DB::table('notification_delivery_log')->where('status', 'failed')->value('reason'));
    }

    public function test_a_key_switched_off_after_queueing_is_refused_at_send_time_and_monthly_limits_hold(): void
    {
        $this->raise('connection.expired');
        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => 'notifications.internal', 'enabled' => false, 'grace_days' => 14, 'keep_existing' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(3, $this->drain()['blocked']);
        Mail::assertNothingSent();
        $this->assertSame(['blocked_by_plan', 'blocked_by_plan', 'blocked_by_plan'], $this->statuses());
    }

    public function test_external_mail_needs_an_external_ready_template_counts_usage_and_names_the_workspace(): void
    {
        $this->externalSubscription();
        $this->raise('target.reached');
        $this->assertSame(1, $this->drain()['skipped']);
        $this->assertSame('external_template_not_installed', DB::table('notification_delivery_log')->value('reason'));
        Mail::assertNothingSent();

        app()->bind(MessageComposer::class, fn () => new class(app(CatalogMessageComposer::class)) implements MessageComposer
        {
            public function __construct(private readonly CatalogMessageComposer $base) {}

            public function compose(Delivery $delivery): ComposedMessage
            {
                $m = $this->base->compose($delivery);

                return new ComposedMessage($m->subject, $m->title, $m->body, $m->html, $m->text, null, true);
            }
        });
        $this->raise('target.reached', ['object' => 'c-9']);
        $this->assertSame(1, $this->drain()['sent']);
        Mail::assertSent(EngineMail::class, fn (EngineMail $m) => $m->hasTo('finance@client.test')
            && $m->hasFrom('info@campaignshub.io', 'Alpha عبر CampaignsHub'));
        $this->assertSame(1, app(UsageCounter::class)->current($this->tenant->id, UsageCounter::EXTERNAL_MESSAGES));

        DB::table('tenant_limit_overrides')->insert(['tenant_id' => $this->tenant->id, 'metric' => 'notif.monthly_messages', 'limit_value' => 1, 'reason' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        $this->raise('target.reached', ['object' => 'c-10']);
        $this->assertSame(1, $this->drain()['blocked']);
        $this->assertSame('limit:monthly_messages', DB::table('notification_delivery_log')->orderByDesc('seq')->value('reason'));
    }

    public function test_an_external_recipient_paused_after_queueing_gets_nothing(): void
    {
        $recipient = $this->externalSubscription();
        $this->raise('target.reached');
        DB::table('notification_external_recipients')->where('id', $recipient)->update(['status' => 'paused']);

        $this->assertSame(1, $this->drain()['skipped']);
        $this->assertSame('recipient_not_active', DB::table('notification_delivery_log')->value('reason'));
    }

    public function test_one_workspace_never_takes_the_whole_run(): void
    {
        config()->set('notifications_v2.sender.per_tenant_per_run', 2);
        foreach (['c-1', 'c-2', 'c-3'] as $c) {
            $this->raise('connection.expired', ['object' => $c]); // 3 channels each = 9 rows
        }
        $other = $this->tenant('Beta', 'agency');
        $this->member($other, 'tenant-owner');
        $p = $this->project($other, 'B');
        app(TenantContext::class)->forget();
        $this->raise('connection.expired', ['tenant' => $other->id, 'project' => $p->id]);

        $this->drain();

        $this->assertSame(2, DB::table('notification_outbox')->where('tenant_id', $this->tenant->id)->where('state', 'done')->count());
        $this->assertSame(2, DB::table('notification_outbox')->where('tenant_id', $other->id)->where('state', 'done')->count());
    }

    public function test_the_log_endpoint_shows_only_this_workspace_exports_csv_and_offers_no_way_to_change_it(): void
    {
        $this->raise('connection.expired');
        $this->drain();
        $reference = (string) DB::table('notification_delivery_log')->where('channel', 'email')->value('reference');
        DB::table('notification_delivery_log')->insert(['id' => (string) Str::uuid(), 'tenant_id' => (string) Str::uuid(), 'delivery_id' => (string) Str::uuid(), 'reference' => 'N-OTHER', 'event_key' => 'x', 'scope' => 'internal', 'channel' => 'email', 'recipient_kind' => 'user', 'status' => 'sent']);

        $list = $this->actingAs($this->owner)->getJson('/api/v1/settings/workspace/notifications/log')->assertOk();
        $this->assertSame(3, $list->json('meta.total'));
        $this->assertNotContains('N-OTHER', array_column($list->json('data'), 'reference'));

        $csv = $this->actingAs($this->owner)->get('/api/v1/settings/workspace/notifications/log/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($reference, $csv);
        $this->assertStringNotContainsString('N-OTHER', $csv);

        $id = (string) DB::table('notification_delivery_log')->where('reference', $reference)->value('id');
        foreach (['putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->assertContains($this->actingAs($this->owner)->{$verb}('/api/v1/settings/workspace/notifications/log/'.$id)->status(), [404, 405]);
            $this->assertContains($this->actingAs($this->owner)->{$verb}('/api/v1/settings/workspace/notifications/log')->status(), [404, 405]);
        }

        $viewer = $this->member($this->tenant, 'viewer', ['projects.view']);
        $this->actingAs($viewer)->getJson('/api/v1/settings/workspace/notifications/log')->assertForbidden();
    }

    public function test_the_plan_also_governs_builder_l_sound_and_push(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->assertTrue(app(SoundPreferences::class)->forReader($this->owner)['enabled']);

        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => 'notifications.sound', 'enabled' => false, 'grace_days' => 14, 'keep_existing' => true, 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetScopedInstances();
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->assertFalse(app(SoundPreferences::class)->forReader($this->owner)['enabled']);
    }
}
