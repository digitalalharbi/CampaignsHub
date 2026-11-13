<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEventMailer;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Providers\MessageProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * ALERT-RULES-MANAGE-001 — what a rule says about email is what happens.
 *
 * Its channels: a rule without «email» sends none (the list was stored and never read). Its recipients: a rule that
 * names the project's manager or members mails them, each re-checked now; a rule that names nobody keeps the default
 * — everyone in scope with a notification row. No real send: Mail is faked and the provider is a stub.
 */
final class AlertRuleRecipientsMailTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    private User $defaultReader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
        config()->set('providers.channels.email', RecipientsMailStubProvider::class);
        Mail::fake();
        $this->defaultReader = $this->owner();
        $this->prefs($this->defaultReader);
    }

    private function prefs(User $user): void
    {
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => (string) $this->agency->id, 'user_id' => $user->id,
            'channels' => json_encode(['in_app' => true, 'email' => true]), 'categories' => json_encode([]), 'frequency' => 'realtime', 'locale' => 'ar',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rule(array $over = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'tenant_id' => $this->agency->id, 'project_id' => (string) $this->projectA->id, 'type' => 'sync_failure', 'name' => 'Sync',
            'cooldown_minutes' => 720, 'channels' => ['in_app', 'email'], 'severity' => 'warning', 'active' => true,
        ], $over));
    }

    /** @return array<string,mixed> */
    private function item(AlertRule $rule, string $event = 'event-1'): array
    {
        return [
            'event_id' => $event, 'rule_id' => (string) $rule->id, 'notification_type' => 'sync_failed', 'severity' => 'warning', 'title' => 'T', 'detail' => 'D',
            'project_id' => (string) $this->projectA->id, 'project_name' => 'Project A', 'triggered_at' => Carbon::parse('2026-11-13 06:00')->toIso8601String(),
        ];
    }

    private function sweep(array $items): array
    {
        return app(AlertEventMailer::class)->sweep((string) $this->agency->id, $items, Carbon::parse('2026-11-13 06:00'));
    }

    public function test_a_rule_without_the_email_channel_sends_no_email(): void
    {
        $counts = $this->sweep([$this->item($this->rule(['channels' => ['in_app']]))]);

        Mail::assertNothingSent();
        $this->assertSame(1, $counts['rule_channel_off']);
    }

    public function test_a_rule_naming_nobody_keeps_the_default_recipients(): void
    {
        $this->sweep([$this->item($this->rule())]);

        Mail::assertSent(AlertBundleMail::class, fn ($m) => $m->hasTo($this->defaultReader->email));
        Mail::assertSent(AlertBundleMail::class, 1);
    }

    public function test_a_rule_naming_the_manager_and_a_member_mails_them_and_not_the_rest(): void
    {
        $manager = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientA->id]);
        $named = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientA->id]);
        $this->projectA->forceFill(['account_manager_id' => $manager->id])->save();

        $this->sweep([$this->item($this->rule(['recipients' => ['project_manager' => true, 'user_ids' => [(int) $named->id]]]))]);

        Mail::assertSent(AlertBundleMail::class, 2);
        Mail::assertSent(AlertBundleMail::class, fn ($m) => $m->hasTo($manager->email));
        Mail::assertSent(AlertBundleMail::class, fn ($m) => $m->hasTo($named->email));
        Mail::assertNotSent(AlertBundleMail::class, fn ($m) => $m->hasTo($this->defaultReader->email));
    }

    public function test_a_named_member_who_lost_the_project_is_not_mailed(): void
    {
        $named = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientA->id]);
        $rule = $this->rule(['recipients' => ['project_manager' => false, 'user_ids' => [(int) $named->id]]]);
        // Narrowed to client B after the rule was saved.
        $membership = DB::table('memberships')->where('user_id', $named->id)->value('id');
        DB::table('membership_scopes')->where('membership_id', $membership)->update(['scope_id' => (string) $this->clientB->id]);

        $this->sweep([$this->item($rule)]);

        Mail::assertNothingSent();
    }

    public function test_one_person_gets_one_bundle_for_default_and_named_alerts_in_the_same_sweep(): void
    {
        $named = $this->rule(['recipients' => ['project_manager' => false, 'user_ids' => [(int) $this->defaultReader->id]]]);
        $default = $this->rule(['name' => 'Other']);

        $this->sweep([$this->item($named, 'e-1'), $this->item($default, 'e-2')]);

        Mail::assertSent(AlertBundleMail::class, 1);
    }
}

final class RecipientsMailStubProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $destination, array $payload): array
    {
        return ['status' => 'sent', 'provider_message_id' => 'stub', 'error' => null];
    }
}
