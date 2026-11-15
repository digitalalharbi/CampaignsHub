<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Notifications\Engine\Consent\ConfirmationRequests;
use App\Domains\Notifications\Engine\Sending\EngineMail;
use App\Domains\Notifications\Models\ExternalDigestRecipient;
use App\Domains\Notifications\Services\SpendSummaryDispatcher;
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

/** NOTIF-V2-ENGINE-001 — moving existing external recipients without deletion or email, and «أرسل طلبات التأكيد» (spec §9). */
final class LegacyMigrationAndConsentTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC'));
        Mail::fake();
        config()->set('providers.channels.email', ConfiguredDigestEmailProvider::class);
        $this->seedPermissions();
        $this->tenant = $this->tenant('Alpha', 'agency');
        $this->owner = $this->member($this->tenant, 'tenant-owner');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function legacy(string $email, array $projectIds, bool $active = true, string $cadence = 'weekly'): ExternalDigestRecipient
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $r = ExternalDigestRecipient::create(['tenant_id' => $this->tenant->id, 'email' => $email, 'name' => 'Sara', 'cadence' => $cadence, 'active' => $active]);
        $r->bindProjects($projectIds);

        return $r;
    }

    public function test_existing_recipients_move_as_pending_with_archived_projects_excluded_nothing_deleted_and_no_email(): void
    {
        $live = $this->project($this->tenant, 'Live');
        $old = $this->project($this->tenant, 'Old', 'archived');
        $a = $this->legacy('pm@client.test', [$live->id, $old->id]);
        $this->legacy('cfo@client.test', [$live->id], false, 'monthly');

        $this->artisan('notifications:v2-import-legacy', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('notification_external_recipients')->count());

        $this->artisan('notifications:v2-import-legacy')->assertSuccessful();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(2, DB::table('external_digest_recipients')->count());           // nothing deleted
        $this->assertTrue((bool) DB::table('external_digest_recipients')->where('id', $a->id)->value('active')); // nor changed
        $this->assertSame(['pending', 'pending'], DB::table('notification_external_recipients')->orderBy('email')->pluck('status')->all());
        $pm = DB::table('notification_external_recipients')->where('email', 'pm@client.test')->first();
        $sub = DB::table('notification_subscriptions')->where('recipient_id', $pm->id)->first();
        $this->assertSame('pending', $sub->status);
        $this->assertSame(['weekly', 'last_7'], [$sub->frequency, $sub->period_key]);
        $this->assertSame([$live->id], DB::table('notification_subscription_projects')->where('subscription_id', $sub->id)->pluck('project_id')->all());
        $cfo = DB::table('notification_external_recipients')->where('email', 'cfo@client.test')->value('id');
        $this->assertSame('paused', DB::table('notification_subscriptions')->where('recipient_id', $cfo)->value('status'));
        $this->assertSame(2, DB::table('notification_subscription_log')->where('action', 'migrated')->count());

        // Idempotent.
        $this->artisan('notifications:v2-import-legacy')->assertSuccessful();
        $this->assertSame(2, DB::table('notification_subscriptions')->count());

        // The old sender leaves a migrated recipient alone; nothing reaches them before confirmation.
        app(TenantContext::class)->forget();
        $this->assertSame('moved_to_shared_engine', app(SpendSummaryDispatcher::class)->sendExternal($a->fresh(), Carbon::now('Asia/Riyadh')));
        Mail::assertNothingSent();
    }

    public function test_confirmation_requests_need_the_owner_and_the_previewed_count_then_a_confirmation_activates(): void
    {
        $live = $this->project($this->tenant, 'Live');
        $this->legacy('pm@client.test', [$live->id]);
        $this->artisan('notifications:v2-import-legacy')->assertSuccessful();
        app(TenantContext::class)->forget();

        $this->actingAs($this->owner)->getJson('/api/v1/settings/workspace/notifications/confirmation-requests')->assertOk()
            ->assertJsonPath('data.recipients', 1)->assertJsonPath('data.subscriptions', 1);

        $manager = $this->member($this->tenant, 'project-manager');
        app(TenantContext::class)->forget();
        $this->actingAs($manager)->postJson('/api/v1/settings/workspace/notifications/confirmation-requests', ['expected' => 1])->assertForbidden();
        $this->actingAs($this->owner)->postJson('/api/v1/settings/workspace/notifications/confirmation-requests', ['expected' => 2])->assertStatus(409);
        Mail::assertNothingSent();

        $this->actingAs($this->owner)->postJson('/api/v1/settings/workspace/notifications/confirmation-requests', ['expected' => 1])
            ->assertOk()->assertJsonPath('data.sent', 1);
        Mail::assertSent(EngineMail::class, fn (EngineMail $m) => $m->hasTo('pm@client.test') && str_contains($m->message->title, 'Alpha تدعوك لاستلام تقارير مشروع Live'));
        $this->assertSame(1, DB::table('notification_subscription_log')->where('action', 'confirmation_requested')->where('actor_user_id', $this->owner->id)->count());

        $token = null;
        Mail::assertSent(EngineMail::class, function (EngineMail $m) use (&$token): bool {
            preg_match('~/n/confirm/([A-Za-z0-9]{48})~', $m->message->text, $hit);
            $token = $hit[1] ?? null;

            return true;
        });
        $this->assertNotNull($token);

        $this->get('/n/confirm/'.$token)->assertOk()->assertSee('<form', false);
        $this->assertSame('pending', DB::table('notification_external_recipients')->value('status')); // a GET (link scanner) confirms nothing
        $this->post('/n/confirm/'.$token)->assertOk();
        $this->assertSame('active', DB::table('notification_external_recipients')->value('status'));
        $this->assertSame('active', DB::table('notification_subscriptions')->value('status'));
        $this->assertSame('double_opt_in', DB::table('notification_external_recipients')->value('consent'));
        $this->post('/n/confirm/'.$token)->assertNotFound(); // single use
    }

    public function test_an_org_domain_address_can_be_approved_internally_but_an_outside_one_must_confirm(): void
    {
        $live = $this->project($this->tenant, 'Live');
        DB::table('workspace_notification_settings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'org_domains' => json_encode(['alpha.test']), 'created_at' => now(), 'updated_at' => now()]);
        $this->legacy('finance@alpha.test', [$live->id]);
        $this->legacy('pm@client.test', [$live->id]);
        $this->artisan('notifications:v2-import-legacy')->assertSuccessful();
        app(TenantContext::class)->forget();
        $inside = (string) DB::table('notification_external_recipients')->where('email', 'finance@alpha.test')->value('id');
        $outside = (string) DB::table('notification_external_recipients')->where('email', 'pm@client.test')->value('id');

        $this->actingAs($this->owner)->postJson("/api/v1/settings/workspace/notifications/recipients/{$outside}/approve-internally")->assertStatus(422);
        $this->actingAs($this->owner)->postJson("/api/v1/settings/workspace/notifications/recipients/{$inside}/approve-internally")->assertOk();

        $row = DB::table('notification_external_recipients')->where('id', $inside)->first();
        $this->assertSame(['active', 'approved_internally', $this->owner->id], [$row->status, $row->consent, (int) $row->approved_by]);
        $this->assertSame(1, DB::table('notification_subscription_log')->where('action', 'approved_internally')->where('actor_user_id', $this->owner->id)->count());
    }

    public function test_an_unanswered_request_raises_one_internal_notice_after_seven_days(): void
    {
        $live = $this->project($this->tenant, 'Live');
        $this->legacy('pm@client.test', [$live->id]);
        $this->artisan('notifications:v2-import-legacy')->assertSuccessful();
        app(ConfirmationRequests::class)->send($this->tenant->id, (int) $this->owner->id);

        Carbon::setTestNow(Carbon::parse('2026-11-21 09:00:00', 'UTC'));
        $this->assertSame(0, app(ConfirmationRequests::class)->sweepUnconfirmed());
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00', 'UTC'));
        $this->assertSame(1, app(ConfirmationRequests::class)->sweepUnconfirmed());
        $this->assertSame(0, app(ConfirmationRequests::class)->sweepUnconfirmed());
        $this->assertSame(1, DB::table('notification_outbox')->where('event_key', 'external.recipient_unconfirmed_7d')->where('recipient_user_id', $this->owner->id)->where('channel', 'in_app')->count());
    }

    public function test_another_workspace_cannot_preview_send_or_approve_this_workspace_recipients(): void
    {
        $live = $this->project($this->tenant, 'Live');
        $this->legacy('pm@client.test', [$live->id]);
        $this->artisan('notifications:v2-import-legacy')->assertSuccessful();
        $id = (string) DB::table('notification_external_recipients')->value('id');
        $other = $this->tenant('Other', 'agency');
        $stranger = $this->member($other, 'tenant-owner');
        app(TenantContext::class)->forget();

        $this->actingAs($stranger)->getJson('/api/v1/settings/workspace/notifications/confirmation-requests')->assertOk()->assertJsonPath('data.recipients', 0);
        $this->actingAs($stranger)->postJson("/api/v1/settings/workspace/notifications/recipients/{$id}/approve-internally")->assertStatus(422);
        $this->actingAs($stranger)->getJson('/api/v1/settings/workspace/notifications/subscription-log')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('pending', DB::table('notification_external_recipients')->where('id', $id)->value('status'));
    }
}
