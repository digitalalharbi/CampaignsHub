<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Notifications;

/**
 * What each lifecycle message actually says, in Arabic and English (NOTIF-SUB-001).
 *
 * Data rather than Blade views, for one reason: these are rendered at DISPATCH and stored on the row,
 * so what a customer received cannot silently change because a template was edited afterwards. A view
 * resolved at send time gives you no way to answer "what did we actually tell them?".
 *
 * Arabic is first in every pair because the product is Arabic-first, and every message names the
 * amount, the date and the action rather than gesturing at them — "your subscription needs attention"
 * is not a message anybody can act on.
 */
final class SubscriptionNotificationTemplates
{
    /**
     * @param  array<string, mixed>  $c  context: plan, amount, currency, date, days, reason, portal_url
     * @return array{subject: string, body: string}
     */
    public static function render(string $event, string $locale, array $c): array
    {
        $ar = $locale !== 'en';

        $plan = (string) ($c['plan'] ?? '');
        $amount = trim(((string) ($c['amount'] ?? '')).' '.((string) ($c['currency'] ?? '')));
        $date = (string) ($c['date'] ?? '');
        $days = (int) ($c['days'] ?? 0);
        $reason = (string) ($c['reason'] ?? '');
        $url = (string) ($c['url'] ?? '');
        // The plan being MOVED TO, and the date it starts — only a plan change carries these.
        $planName = (string) ($c['plan_name'] ?? '');
        $effectiveAt = (string) ($c['effective_at'] ?? '');

        return match ($event) {
            'trial_started' => $ar ? [
                'subject' => "بدأت تجربتك لمدة {$days} أيام — باقة {$plan}",
                'body' => "تم تأكيد رسوم التجربة ({$amount}) وتفعيل مساحة عملك.\n"
                    ."تنتهي التجربة في {$date}، وعندها تتحول تلقائيًا إلى الاشتراك الذي اخترته.\n"
                    ."يمكنك الإلغاء قبل ذلك التاريخ من إعدادات الاشتراك دون أي رسوم إضافية.\n{$url}",
            ] : [
                'subject' => "Your {$days}-day trial has started — {$plan}",
                'body' => "The trial fee ({$amount}) was confirmed and your workspace is active.\n"
                    ."The trial ends on {$date}, when it converts to the subscription you chose.\n"
                    ."You can cancel before then from subscription settings at no further charge.\n{$url}",
            ],

            // Sent BEFORE the charge, because the point of a warning is that it arrives in time to act on.
            'trial_ending' => $ar ? [
                'subject' => "تنتهي تجربتك خلال {$days} أيام",
                'body' => "تنتهي تجربتك في {$date}، وسيتم عندها تحصيل {$amount} لباقة {$plan}.\n"
                    ."إن لم ترغب في المتابعة، ألغِ الاشتراك قبل ذلك التاريخ ولن يُخصم أي مبلغ.\n{$url}",
            ] : [
                'subject' => "Your trial ends in {$days} days",
                'body' => "Your trial ends on {$date}, when {$amount} will be charged for {$plan}.\n"
                    ."If you would rather not continue, cancel before then and nothing will be charged.\n{$url}",
            ],

            'trial_converted' => $ar ? [
                'subject' => 'انتهت التجربة — بانتظار تأكيد الدفع',
                'body' => "انتهت فترة التجربة وتم إنشاء طلب دفع بقيمة {$amount} لباقة {$plan}.\n"
                    ."لن يُجدَّد اشتراكك إلا بعد تأكيد المزوّد للعملية.\n{$url}",
            ] : [
                'subject' => 'Your trial has ended — awaiting payment confirmation',
                'body' => "The trial has ended and a charge of {$amount} was opened for {$plan}.\n"
                    ."Your subscription renews once the provider confirms the payment.\n{$url}",
            ],

            'payment_confirmed' => $ar ? [
                'subject' => "تم تأكيد الدفع — {$amount}",
                'body' => "استلمنا تأكيد المزوّد لمبلغ {$amount} لباقة {$plan}.\n"
                    ."فترة الاشتراك الحالية تنتهي في {$date}.\n{$url}",
            ] : [
                'subject' => "Payment confirmed — {$amount}",
                'body' => "The provider confirmed {$amount} for {$plan}.\n"
                    ."Your current period runs until {$date}.\n{$url}",
            ],

            'renewal_failed' => $ar ? [
                'subject' => 'تعذّر تجديد اشتراكك',
                'body' => "رفض المزوّد عملية التجديد بقيمة {$amount}.\n"
                    ."حسابك ما زال يعمل حتى {$date} — حدّث وسيلة الدفع قبل ذلك التاريخ لتفادي التعليق.\n{$url}",
            ] : [
                'subject' => 'We could not renew your subscription',
                'body' => "The provider refused the renewal of {$amount}.\n"
                    ."Your account keeps working until {$date} — update your payment method before then to avoid suspension.\n{$url}",
            ],

            // Past due is an OPERATING state, and the message says so rather than alarming somebody
            // whose account is still working.
            'past_due' => $ar ? [
                'subject' => 'اشتراكك متأخر السداد',
                'body' => "لم يُستلم تأكيد الدفع للفترة الحالية. حسابك يعمل بشكل طبيعي حتى {$date}.\n"
                    ."بعد ذلك التاريخ سيُعلَّق الحساب مع الاحتفاظ الكامل ببياناتك.\n{$url}",
            ] : [
                'subject' => 'Your subscription is past due',
                'body' => "No payment confirmation was received for the current period. Your account works normally until {$date}.\n"
                    ."After that it will be suspended, with all of your data kept.\n{$url}",
            ],

            'suspended' => $ar ? [
                'subject' => 'تم تعليق حسابك',
                'body' => "انتهت مهلة السماح دون تأكيد دفع، وتم تعليق الوصول إلى مساحة العمل.\n"
                    ."بياناتك محفوظة بالكامل ولم يُحذف منها شيء. أكمل الدفع لإعادة التفعيل فورًا.\n{$url}",
            ] : [
                'subject' => 'Your account has been suspended',
                'body' => "The grace period ended without a confirmed payment, and access to the workspace has stopped.\n"
                    ."All of your data is kept and nothing has been deleted. Complete the payment to reactivate.\n{$url}",
            ],

            'reactivated' => $ar ? [
                'subject' => 'تمت إعادة تفعيل حسابك',
                'body' => "عاد حسابك للعمل، والفترة الحالية تنتهي في {$date}.\n{$url}",
            ] : [
                'subject' => 'Your account is active again',
                'body' => "Your account is working again, and the current period runs until {$date}.\n{$url}",
            ],

            /*
             * A plan change that has taken effect (PAY-002).
             *
             * Says what they are on NOW, because that is the question a customer has after paying a
             * part-period difference they may not have been expecting to see on a statement.
             */
            'plan_changed' => $ar ? [
                'subject' => "تم تغيير باقتك إلى {$planName}",
                'body' => "باقتك الحالية الآن {$planName}."
                    .((float) ($c['amount'] ?? 0) > 0 ? " تم تحصيل فرق المدة المتبقية ({$amount})." : ' لم يُطلب أي مبلغ إضافي.')
                    ."\nتنتهي الفترة الحالية في {$date}.\n{$url}",
            ] : [
                'subject' => "Your plan is now {$planName}",
                'body' => "You are on {$planName}."
                    .((float) ($c['amount'] ?? 0) > 0 ? " The difference for the remaining days was charged ({$amount})." : ' Nothing extra was charged.')
                    ."\nThe current period runs until {$date}.\n{$url}",
            ],

            /*
             * A downgrade, agreed and waiting.
             *
             * The date is the point of this message: the customer keeps everything they paid for
             * until then, and saying so is what stops «I downgraded and lost my projects today».
             */
            'plan_change_scheduled' => $ar ? [
                'subject' => "سيتم تحويلك إلى باقة {$planName} في {$effectiveAt}",
                'body' => "سجّلنا طلب تغيير باقتك إلى {$planName}.\n"
                    ."يبدأ التغيير في {$effectiveAt}، وحتى ذلك التاريخ تحتفظ بباقتك الحالية بالكامل لأنك دفعت ثمنها.\n"
                    ."لم يُخصم أي مبلغ ولم يُسترد أي مبلغ.\n{$url}",
            ] : [
                'subject' => "You move to {$planName} on {$effectiveAt}",
                'body' => "Your change to {$planName} is recorded.\n"
                    ."It starts on {$effectiveAt}. Until then you keep your current plan in full, because you have paid for it.\n"
                    ."Nothing has been charged and nothing has been refunded.\n{$url}",
            ],

            'registration_approved' => $ar ? [
                'subject' => 'تم اعتماد طلب تسجيلك',
                'body' => "راجعنا طلبك واعتمدناه.\n"
                    .($amount !== '' ? "الخطوة التالية هي إتمام الدفع ({$amount}).\n" : "يمكنك الآن استخدام مساحة عملك.\n")
                    .$url,
            ] : [
                'subject' => 'Your registration has been approved',
                'body' => "We reviewed your application and approved it.\n"
                    .($amount !== '' ? "The next step is to complete the payment ({$amount}).\n" : "Your workspace is ready.\n")
                    .$url,
            ],

            // A refusal always carries its reason: "rejected" with no explanation is a support ticket
            // nobody can answer either.
            'registration_rejected' => $ar ? [
                'subject' => 'لم نتمكن من اعتماد طلب تسجيلك',
                'body' => "بعد المراجعة، لم نتمكن من اعتماد الطلب.\nالسبب: {$reason}\n"
                    .'إن كان لديك ما يوضّح الأمر، يمكنك التقديم مرة أخرى.',
            ] : [
                'subject' => 'We could not approve your registration',
                'body' => "After review, we were not able to approve the application.\nReason: {$reason}\n"
                    .'If you have something that clarifies it, you are welcome to apply again.',
            ],

            'registration_information_requested' => $ar ? [
                'subject' => 'نحتاج معلومات إضافية لإكمال طلبك',
                'body' => "طلبك قيد المراجعة، ونحتاج منك التالي:\n{$reason}\n{$url}",
            ] : [
                'subject' => 'We need a little more to finish your application',
                'body' => "Your application is under review and we need the following from you:\n{$reason}\n{$url}",
            ],

            /*
             * An unknown event is NOT rendered as a friendly generic message.
             *
             * A message that says nothing is worse than no message: the customer is alerted and cannot
             * act. The notifier treats this as a programming error rather than sending it.
             */
            default => ['subject' => '', 'body' => ''],
        };
    }

    /** Every event this class can render — used to validate a caller rather than trust one. */
    public static function events(): array
    {
        return [
            'trial_started', 'trial_ending', 'trial_converted', 'payment_confirmed',
            'renewal_failed', 'past_due', 'suspended', 'reactivated',
            'plan_changed', 'plan_change_scheduled',
            'registration_approved', 'registration_rejected', 'registration_information_requested',
        ];
    }
}
