<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Notifications;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Subscriptions\Jobs\SendSubscriptionNotification;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionNotification;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Raising a lifecycle message (NOTIF-SUB-001).
 *
 * The whole of this class is: work out who to tell, render what to say, write it down, and hand it to
 * the queue. It never sends anything itself — `SendSubscriptionNotification` does that, and it is the
 * only thing that knows whether a transport exists.
 *
 * Two decisions worth stating:
 *
 * 1. **The message is rendered NOW, not at send time.** What a customer received must be answerable
 *    after the fact, and a template resolved when the queue runs cannot be.
 * 2. **Dedup is on the OCCASION.** `renewal_failed:{subscription}:{period}`, not `renewal_failed` —
 *    the sweep is safe to run twice by design, so without this a customer gets "your card was refused"
 *    every day for a week; with `event` alone they would never hear about next month's failure.
 *
 * Tenants also get an IN-APP notification through the existing dispatcher, so the bell and the inbox
 * say the same thing. Applicants do not: they have no tenant, and there is no bell to ring.
 */
final class SubscriptionNotifier
{
    public function __construct(private readonly NotificationDispatcher $inApp) {}

    /**
     * Tell the owner of a workspace something about their subscription.
     *
     * @param  array<string, mixed>  $context
     */
    public function notifyTenant(
        Tenant $tenant,
        string $event,
        array $context = [],
        ?string $occasion = null,
    ): ?SubscriptionNotification {
        $user = $this->ownerOf($tenant);

        if ($user === null) {
            // A workspace with no owner cannot be told anything. That is a defect elsewhere, and
            // silently inventing a recipient would hide it.
            return null;
        }

        $notification = $this->record(
            event: $event,
            email: (string) $user->email,
            locale: (string) ($user->locale ?? 'ar'),
            context: $context,
            occasion: $occasion ?? (string) $tenant->getKey(),
            tenantId: (string) $tenant->getKey(),
            userId: $user->id,
        );

        if ($notification !== null) {
            $this->alsoInApp($tenant, $user, $event, $notification);
        }

        return $notification;
    }

    /**
     * Tell an APPLICANT something about their application.
     *
     * They have no tenant, no user row and no bell — email is the only channel that exists for them,
     * which is exactly why this ledger is addressed by email rather than by membership.
     *
     * @param  array<string, mixed>  $context
     */
    public function notifyApplicant(
        RegistrationRequest $request,
        string $event,
        array $context = [],
        ?string $occasion = null,
    ): ?SubscriptionNotification {
        return $this->record(
            event: $event,
            email: (string) $request->email,
            locale: 'ar',
            context: $context,
            occasion: $occasion ?? (string) $request->getKey(),
            registrationRequestId: (string) $request->getKey(),
        );
    }

    /** Context every subscription message shares, so no caller has to remember the shape. */
    public function contextFor(Subscription $subscription, array $extra = []): array
    {
        return array_merge([
            'plan' => $subscription->plan?->name ?? $subscription->plan?->code ?? '',
            'amount' => (string) ($subscription->unit_amount ?? ''),
            'currency' => (string) ($subscription->currency ?? config('subscriptions.currency')),
            'date' => $subscription->current_period_end?->toDateString()
                ?? $subscription->trial_ends_at?->toDateString() ?? '',
            'url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/app/subscriptions',
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(
        string $event,
        string $email,
        string $locale,
        array $context,
        string $occasion,
        ?string $tenantId = null,
        ?string $registrationRequestId = null,
        ?int $userId = null,
    ): ?SubscriptionNotification {
        if (! in_array($event, SubscriptionNotificationTemplates::events(), true)) {
            // A message that says nothing is worse than no message: the customer is alerted and
            // cannot act. An unknown event is a programming error, not something to send.
            throw new InvalidArgumentException("There is no template for the notification event [{$event}].");
        }

        $rendered = SubscriptionNotificationTemplates::render($event, $locale, $context);
        $dedupKey = "{$event}:{$occasion}";

        /*
         * `firstOrCreate` on a unique index, so a race between two sweeps loses at the database rather
         * than in application logic — and the second caller gets the existing row rather than an error.
         */
        $notification = SubscriptionNotification::query()->firstOrNew(['dedup_key' => $dedupKey]);

        if ($notification->exists) {
            return null;
        }

        return DB::transaction(function () use ($notification, $event, $email, $locale, $rendered, $context, $dedupKey, $tenantId, $registrationRequestId, $userId): SubscriptionNotification {
            $notification->forceFill([
                'tenant_id' => $tenantId,
                'registration_request_id' => $registrationRequestId,
                'user_id' => $userId,
                'to_email' => $email,
                'locale' => $locale,
                'event' => $event,
                'channel' => 'email',
                'subject' => $rendered['subject'],
                'body' => $rendered['body'],
                'status' => 'queued',
                'context' => $context,
                'dedup_key' => $dedupKey,
            ])->save();

            // Handed to the queue INSIDE the transaction's commit, so a rolled-back record never
            // results in a message somebody actually receives.
            SendSubscriptionNotification::dispatch((string) $notification->getKey())->afterCommit();

            return $notification->refresh();
        });
    }

    /**
     * The same message in the bell.
     *
     * Through the EXISTING dispatcher rather than a second in-app path, so a customer's own quiet
     * hours and per-category preferences apply to these exactly as they do to everything else.
     */
    private function alsoInApp(Tenant $tenant, User $user, string $event, SubscriptionNotification $notification): void
    {
        $this->inApp->dispatch([
            'tenant_id' => (string) $tenant->getKey(),
            'user_id' => $user->id,
            'type' => 'subscription',
            'severity' => in_array($event, ['renewal_failed', 'past_due', 'suspended'], true) ? 'warning' : 'info',
            'title' => $notification->subject,
            'message' => $notification->body,
            'source' => 'subscriptions',
            'entity_type' => SubscriptionNotification::class,
            'entity_id' => (string) $notification->getKey(),
            'action_url' => '/app/subscriptions',
            'dedup_extra' => $notification->dedup_key,
        ]);
    }

    /**
     * Who speaks for this workspace.
     *
     * The owner membership, and the earliest one when there are several — a workspace that changed
     * hands should not send its billing mail to whoever happened to be created last.
     */
    private function ownerOf(Tenant $tenant): ?User
    {
        $userId = Membership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->getKey())
            ->where('role', 'owner')
            ->orderBy('created_at')
            ->value('user_id');

        return $userId === null ? null : User::find($userId);
    }
}
