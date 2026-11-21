<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Requests\Models\ExternalRequest;
use App\Models\User;
use Throwable;

/**
 * PLATFORM-SERVICE-INBOX-001 — telling the platform's own administrators that a service was ordered.
 *
 * A platform request belongs to no workspace, so the workspace notification engine has nobody to address:
 * it is keyed by tenant throughout. This goes through the mail ledger instead — the same `TransactionalMailer`
 * every other message uses, so the attempt is recorded with its real state whatever the transport does
 * (`sent`, `queued`, `awaiting_credentials`, `failed`) and one request is one message per administrator.
 *
 * The recipients are read from `users.is_platform_admin` and from nowhere else. A subscriber's team is never
 * a recipient, and a request that is not platform-owned is not this class's to announce.
 */
final class PlatformRequestNotifier
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    public function received(ExternalRequest $request): int
    {
        if (! $request->isPlatformOwned() || config('requests.platform_inbox.notify', true) !== true) {
            return 0;
        }

        $told = 0;

        try {
            foreach ($this->administrators() as $admin) {
                $locale = $admin->locale === 'en' ? 'en' : 'ar';
                $ar = $locale === 'ar';
                $service = (string) ($ar ? $request->type->name_ar : $request->type->name_en);

                $this->mailer->queue(
                    recipient: (string) $admin->email,
                    mail: new OperationalMail(
                        kind: 'client_request',
                        severity: 'info',
                        title: $ar ? 'طلب خدمة جديد' : 'New service request',
                        detail: $ar
                            ? "وصل الطلب {$request->reference} من موقع كامبينز هب: {$service}. افتحه وحدّد المسؤول."
                            : "Request {$request->reference} came in from the CampaignsHub site: {$service}. Open it and pick an owner.",
                        context: (string) ($request->company_name ?: $request->reference),
                        lang: $locale,
                        recipientName: (string) $admin->name,
                        path: '/admin/requests?open='.$request->id,
                        action: $ar ? 'افتح الطلب' : 'Open the request',
                        why: $ar ? 'وصلتك لأنك من إدارة المنصة.' : 'Sent because you administer the platform.',
                    ),
                    kind: 'client_request',
                    template: 'mail.operational',
                    locale: $locale,
                    tenantId: null,
                    userId: (int) $admin->getKey(),
                    dedupKey: 'platform-request:'.$request->id.':'.$admin->getKey(),
                );
                $told++;
            }
        } catch (Throwable $e) {
            // The request is stored and listed whatever happens to the message about it.
            report($e);
        }

        return $told;
    }

    /** @return list<User> */
    private function administrators(): array
    {
        return User::query()
            ->where('is_platform_admin', true)
            ->whereNotNull('email')
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u): bool => (string) $u->email !== '')
            ->values()->all();
    }
}
