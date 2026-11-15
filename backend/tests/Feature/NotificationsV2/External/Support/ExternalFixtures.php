<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External\Support;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Entitlements\PlanEntitlementGate;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Engine\Sending\SenderPipeline;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\ConfiguredDigestEmailProvider;

/** Shared fixtures of the external-pipeline tests: a workspace, a project, fake figures, raise + drain, the sent mail. */
trait ExternalFixtures
{
    protected Tenant $tenant;

    protected User $owner;

    protected Project $project;

    protected FakeDigestData $data;

    protected function setUpExternal(): void
    {
        $this->seedPermissions();
        Carbon::setTestNow(Carbon::parse('2026-11-15 07:00:00', 'UTC')); // 10:00 Riyadh, a Sunday work day
        config()->set('providers.channels.email', ConfiguredDigestEmailProvider::class);
        config()->set('mail.from.address', 'info@campaignshub.io');
        $this->tenant = $this->tenant('Alpha Agency', 'agency');
        $this->owner = $this->member($this->tenant);
        $this->project = $this->project($this->tenant, 'Launch');
        DB::table('workspace_notification_settings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'org_domains' => json_encode(['alpha.test']), 'created_at' => now(), 'updated_at' => now()]);
        $this->data = new FakeDigestData;
        $this->app->instance(DigestDataSource::class, $this->data);
    }

    /** @param list<string> $projects */
    protected function subscribe(string $email, string $role, array $projects, array $sub = [], array $recipient = []): array
    {
        $r = (string) Str::uuid();
        DB::table('notification_external_recipients')->insert(array_merge(['id' => $r, 'tenant_id' => $this->tenant->id, 'email' => $email, 'role_key' => $role, 'status' => 'active', 'consent' => 'double_opt_in', 'language' => 'ar', 'created_at' => now()->subDays(20), 'updated_at' => now()], $recipient));
        $s = (string) Str::uuid();
        DB::table('notification_subscriptions')->insert(array_merge(['id' => $s, 'tenant_id' => $this->tenant->id, 'recipient_id' => $r, 'status' => 'active', 'event_keys' => json_encode(['target.reached', 'continuity.planning']),
            'digest_types' => json_encode(['performance', 'spend', 'budgets', 'top_campaigns', 'growth_suggestions', 'achievements']), 'period_key' => 'this_month_to_yesterday', 'frequency' => 'daily',
            'review_mode' => false, 'reviews_remaining' => 0, 'created_at' => '2026-10-01 09:00:00', 'updated_at' => now()], $sub));
        foreach ($projects as $p) {
            DB::table('notification_subscription_projects')->insert(['subscription_id' => $s, 'project_id' => $p, 'tenant_id' => $this->tenant->id]);
        }

        return [$r, $s];
    }

    protected function raise(string $event, string $project, ?string $subscription = null, array $payload = []): void
    {
        app(TenantContext::class)->forget();
        app(NotificationEngine::class)->raise(new DeliveryIntent(
            eventKey: $event, tenantId: $this->tenant->id, projectId: $project, objectType: 'test', objectId: (string) Str::uuid(),
            payload: $payload + ($event === 'digest.periodic' ? ['period' => ['key' => 'this_month_to_yesterday', 'from' => '2026-11-01', 'to' => '2026-11-14']] : []),
            subscriptionId: $subscription,
        ));
    }

    /** @return array<string, int> */
    protected function drain(): array
    {
        return app(SenderPipeline::class)->run();
    }

    /** @return list<array{email: Email, rcpt: list<string>}> */
    protected function sent(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(static fn (SentMessage $m): array => [
            'email' => $m->getOriginalMessage(),
            'rcpt' => array_map(static fn ($a): string => $a->getAddress(), $m->getEnvelope()->getRecipients()),
        ])->values()->all();
    }

    protected function disable(string $key): void
    {
        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => $key, 'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'created_at' => now(), 'updated_at' => now()]);
        $gate = app(EntitlementGate::class);
        assert($gate instanceof PlanEntitlementGate);
        $gate->flush();
    }
}
