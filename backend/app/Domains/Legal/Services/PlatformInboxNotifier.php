<?php

declare(strict_types=1);

namespace App\Domains\Legal\Services;

use App\Domains\Legal\Models\ContactMessage;
use App\Domains\Legal\Models\DataRequest;
use App\Domains\Legal\Models\SupportTicket;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * PLATFORM-INBOX-ATTENTION-001 — a contact message, a support ticket or a data request tells the platform's
 * administrators that it arrived.
 *
 * These three belong to no workspace, so the workspace notification engine (keyed by tenant throughout) has
 * nobody to address. The message goes through the mail ledger, exactly as `PlatformRequestNotifier` does for a
 * service ordered from the site: one row per administrator, its real state whatever the transport does, and a
 * dedup key so one arrival is one message.
 *
 * Recipients come from `users.is_platform_admin` and nowhere else; a subscriber's team is never addressed, even
 * when a signed-in member of a workspace opened the ticket.
 *
 * The notice carries no part of what the visitor wrote — no message, no details, no phone. It says which queue
 * and the reference, and the administrator reads the rest behind the console's sign-in.
 *
 * The three forms are public. A burst of submissions must not become a burst of mail to the owner, so contact and
 * support notices each have an hourly allowance (`requests.platform_inbox.notify_per_hour`); past it the row is still
 * stored and still counted unread on the console, it just does not mail again within that hour. Data requests are
 * always notified.
 */
final class PlatformInboxNotifier
{
    public const KIND = 'client_request';

    public function __construct(private readonly TransactionalMailer $mailer) {}

    public function received(ContactMessage|SupportTicket|DataRequest $record): int
    {
        if (config('requests.platform_inbox.notify', true) !== true) {
            return 0;
        }

        [$queue, $id] = match (true) {
            $record instanceof ContactMessage => ['contact', (string) $record->getKey()],
            $record instanceof SupportTicket => ['support', (string) $record->getKey()],
            default => ['data', (string) $record->getKey()],
        };

        /*
         * One allowance per queue, so a burst of contact messages cannot silence a support ticket or a data request.
         * Data requests get their own, larger allowance: they carry a statutory deadline, but their intake is public
         * and per real address now (EDGE-CLIENT-IP-001), so uncapped they would let one visitor mail the owner
         * hundreds of times an hour. Past an allowance the row is still stored and counted unread on the console.
         * `hit()` returns the new count, which makes the check and the increment one step under concurrency.
         */
        $perHour = $queue === 'data'
            ? max(1, (int) config('requests.platform_inbox.notify_data_per_hour', 60))
            : max(1, (int) config('requests.platform_inbox.notify_per_hour', 30));
        if (RateLimiter::hit('platform-inbox-notify:'.$queue, 3600) > $perHour) {
            return 0;
        }

        $told = 0;

        try {
            foreach ($this->administrators() as $admin) {
                $locale = $admin->locale === 'en' ? 'en' : 'ar';
                $words = self::words($queue, $record, $locale === 'ar');

                $this->mailer->queue(
                    recipient: (string) $admin->email,
                    mail: new OperationalMail(
                        kind: self::KIND,
                        severity: $queue === 'data' ? 'warning' : 'info',
                        title: $words['title'],
                        detail: $words['detail'],
                        context: $words['context'],
                        lang: $locale,
                        recipientName: (string) $admin->name,
                        path: '/admin/inbox?tab='.$queue.'&open='.$id,
                        action: $locale === 'ar' ? 'افتح الوارد' : 'Open the inbox',
                        why: $locale === 'ar' ? 'وصلتك لأنك من إدارة المنصة.' : 'Sent because you administer the platform.',
                    ),
                    kind: self::KIND,
                    template: 'mail.operational',
                    locale: $locale,
                    tenantId: null,
                    userId: (int) $admin->getKey(),
                    dedupKey: 'platform-inbox:'.$queue.':'.$id.':'.$admin->getKey(),
                );
                $told++;
            }
        } catch (Throwable $e) {
            // The row is stored and counted whatever happens to the message about it.
            report($e);
        }

        return $told;
    }

    /** @return array{title: string, detail: string, context: string} */
    private static function words(string $queue, ContactMessage|SupportTicket|DataRequest $record, bool $ar): array
    {
        $reference = $record instanceof ContactMessage ? '' : (string) $record->reference;

        return match ($queue) {
            'contact' => [
                'title' => $ar ? 'رسالة تواصل جديدة' : 'New contact message',
                'detail' => $ar ? 'وصلت رسالة من نموذج «تواصل معنا» في الموقع. افتحها ورد على صاحبها.' : 'A message came in from the site\'s contact form. Open it and reply.',
                'context' => $ar ? 'تواصل معنا' : 'Contact',
            ],
            'support' => [
                'title' => $ar ? 'تذكرة دعم جديدة' : 'New support ticket',
                'detail' => $ar ? "وصلت التذكرة {$reference}. افتحها وحدّد ما يلزم." : "Ticket {$reference} came in. Open it and decide what it needs.",
                'context' => $reference,
            ],
            default => [
                'title' => $ar ? 'طلب بيانات جديد' : 'New data request',
                'detail' => $ar ? "وصل طلب البيانات {$reference}، وله مهلة نظامية." : "Data request {$reference} came in, and it has a statutory deadline.",
                'context' => $reference,
            ],
        };
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
