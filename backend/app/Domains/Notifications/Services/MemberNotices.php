<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EMAIL-TRANSACTIONAL-SET-001 — the two messages a member is owed about their own access: a welcome when they join, and a notice when
 * somebody changes their role.
 *
 * Both ride the shared shell (`OperationalMail`) and the one transactional mailer, so they are recorded in `mail_deliveries` with their
 * real outcome — `sent`, `sandbox`, `awaiting_credentials` or `failed` — and never claim delivery on an install with no mailer.
 *
 * Two rules that matter more than the wording:
 *
 *   - A message is a CONSEQUENCE of a change, never a condition of it. Nothing here can undo a grant or a role change: a failure is
 *     logged and swallowed, and the caller has already committed.
 *   - A role notice states the access that changed and no more — the role's NAME, before and after — and tells the person what to do if
 *     it was not expected. It does not describe permissions (`InvitationMail` explains why: access is the one thing a reader takes
 *     literally, and a description nothing checks is a statement about somebody's access).
 */
final class MemberNotices
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    /** After an invitation is accepted. One per person and workspace, however many times it is retried. */
    public function welcome(User $user, Tenant $tenant, string $roleName): string
    {
        $ar = $this->lang($user) === 'ar';

        return $this->send(
            $user, $tenant, 'member_welcome', "welcome:{$tenant->id}:{$user->id}",
            new OperationalMail(
                kind: 'member_welcome', severity: 'positive',
                title: $ar ? "أهلًا بك في {$tenant->name}" : "Welcome to {$tenant->name}",
                detail: $ar
                    ? "انضممت إلى مساحة العمل بدور «{$roleName}». يمكنك الآن متابعة الحملات والتقارير من لوحة التحكم."
                    : "You have joined the workspace as «{$roleName}». You can now follow campaigns and reports from the dashboard.",
                context: (string) $tenant->name,
                lang: $this->lang($user), recipientName: (string) $user->name,
                path: '/app/dashboard', action: $ar ? 'فتح لوحة التحكم' : 'Open the dashboard',
            ),
        );
    }

    /** After a person's role in a workspace is changed by somebody else. Not sent for an unchanged role. */
    public function roleChanged(User $user, Tenant $tenant, string $from, string $to, ?User $by = null): string
    {
        if ($from === $to) {
            return 'unchanged';
        }
        $ar = $this->lang($user) === 'ar';
        $who = $by === null ? '' : ($ar ? " بواسطة {$by->name}" : " by {$by->name}");

        return $this->send(
            $user, $tenant, 'role_changed', "role:{$tenant->id}:{$user->id}:".md5($from.'>'.$to).':'.now()->format('YmdHi'),
            new OperationalMail(
                kind: 'role_changed', severity: 'warning',
                title: $ar ? 'تغيّر دورك في مساحة العمل' : 'Your role in the workspace changed',
                detail: $ar
                    ? "تغيّر دورك في {$tenant->name}{$who}: من «{$from}» إلى «{$to}». إن لم تكن تتوقع هذا التغيير فتواصل مع مسؤول الفريق."
                    : "Your role in {$tenant->name} was changed{$who}: from «{$from}» to «{$to}». If you did not expect this, contact a workspace administrator.",
                context: (string) $tenant->name,
                lang: $this->lang($user), recipientName: (string) $user->name,
                path: '/app/dashboard', action: $ar ? 'فتح كامبينز هب' : 'Open CampaignsHub',
            ),
        );
    }

    /**
     * DELIVERY-REMINDERS-001 — a delivery needs its reviewer. One message per pack, per reason, per day (the dedup key), in the
     * reviewer's language; it is a nudge to open the pack, never a report and never sent to a client.
     *
     * @param  string  $reason  review_due | report_failed | send_due
     */
    public function deliveryReminder(User $reviewer, Tenant $tenant, string $reason, string $clientName, string $period, string $packId): string
    {
        $ar = $this->lang($reviewer) === 'ar';
        [$titleAr, $titleEn, $detailAr, $detailEn, $severity] = match ($reason) {
            'report_failed' => ['فشل تجهيز تقرير التسليم', 'A delivery report failed to build', "تعذّر توليد تقرير {$clientName} للفترة {$period}. افتح الحزمة وأعد المحاولة.", "The report for {$clientName} ({$period}) could not be built. Open the pack and try again.", 'critical'],
            'send_due' => ['تقرير معتمد بانتظار الإرسال', 'An approved report is waiting to be sent', "تقرير {$clientName} للفترة {$period} معتمد ولم يُسجَّل إرساله بعد.", "The report for {$clientName} ({$period}) is approved but not yet marked as sent.", 'warning'],
            default => ['تقرير بانتظار مراجعتك', 'A report is waiting for your review', "مسودة تقرير {$clientName} للفترة {$period} جاهزة للمراجعة والاعتماد.", "The draft report for {$clientName} ({$period}) is ready for your review and approval.", 'warning'],
        };

        return $this->send(
            $reviewer, $tenant, 'delivery_'.$reason, "delivery:{$packId}:{$reason}:".now()->format('Ymd'),
            new OperationalMail(
                kind: 'delivery_'.$reason, severity: $severity,
                title: $ar ? $titleAr : $titleEn, detail: $ar ? $detailAr : $detailEn, context: $clientName,
                lang: $this->lang($reviewer), recipientName: (string) $reviewer->name,
                path: '/agency/clients', action: $ar ? 'فتح التسليم الدوري' : 'Open the periodic delivery',
            ),
        );
    }

    private function send(User $user, Tenant $tenant, string $kind, string $dedup, OperationalMail $mail): string
    {
        try {
            return $this->mailer->send(
                recipient: (string) $user->email,
                mail: $mail,
                kind: $kind,
                template: 'mail.operational',
                locale: $this->lang($user),
                tenantId: (string) $tenant->id,
                userId: (int) $user->id,
                dedupKey: $dedup,
            );
        } catch (Throwable $e) {
            // The grant or the role change has already happened; a message about it must not be able to undo it.
            Log::warning('Member notice could not be sent.', ['kind' => $kind, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return 'failed';
        }
    }

    private function lang(User $user): string
    {
        return in_array($user->locale, ['ar', 'en'], true) ? (string) $user->locale : 'ar';
    }
}
