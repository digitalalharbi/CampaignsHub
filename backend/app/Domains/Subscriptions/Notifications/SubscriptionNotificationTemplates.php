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
            /*
             * A plan bought outright (PLAN-PAID-001).
             *
             * Deliberately not worded as a trial: there is no window to cancel inside and no
             * conversion coming, and saying otherwise would tell a paying customer to expect a second
             * charge they have already made.
             */
            'subscription_started' => $ar ? [
                'subject' => "تم تفعيل اشتراكك — باقة {$plan}",
                'body' => "تم تأكيد الدفع ({$amount}).\n"
                    ."يتجدد اشتراكك في {$date}، ويمكنك إلغاؤه من إعدادات الاشتراك.\n{$url}",
            ] : [
                'subject' => "Your subscription is active — {$plan}",
                'body' => "Your payment ({$amount}) was confirmed.\n"
                    ."It renews on {$date}, and you can cancel it in subscription settings.\n{$url}",
            ],

            'trial_started' => $ar ? [
                'subject' => "بدأت تجربتك لمدة {$days} أيام — باقة {$plan}",
                'body' => "تم تأكيد رسوم التجربة ({$amount}).\n"
                    ."تنتهي التجربة في {$date} وتتحول تلقائيًا إلى الاشتراك الذي اخترته.\n"
                    ."يمكنك الإلغاء قبلها من إعدادات الاشتراك دون رسوم إضافية.\n{$url}",
            ] : [
                'subject' => "Your {$days}-day trial has started — {$plan}",
                'body' => "The trial fee ({$amount}) was confirmed.\n"
                    ."The trial ends on {$date} and converts to the subscription you chose.\n"
                    ."Cancel before then in subscription settings at no further charge.\n{$url}",
            ],

            // Sent BEFORE the charge, because the point of a warning is that it arrives in time to act on.
            'trial_ending' => $ar ? [
                'subject' => "تنتهي تجربتك خلال {$days} أيام",
                'body' => "تنتهي تجربتك في {$date}، ويُحصَّل عندها {$amount} لباقة {$plan}.\n"
                    ."ألغِ قبل ذلك التاريخ ولن يُخصم أي مبلغ.\n{$url}",
            ] : [
                'subject' => "Your trial ends in {$days} days",
                'body' => "Your trial ends on {$date}, when {$amount} is charged for {$plan}.\n"
                    ."Cancel before then and nothing is charged.\n{$url}",
            ],

            'trial_converted' => $ar ? [
                'subject' => 'انتهت التجربة — بانتظار تأكيد الدفع',
                'body' => "أُنشئ طلب دفع بقيمة {$amount} لباقة {$plan}.\n"
                    ."يتجدد اشتراكك بعد تأكيد المزوّد.\n{$url}",
            ] : [
                'subject' => 'Your trial has ended — awaiting payment confirmation',
                'body' => "A charge of {$amount} was opened for {$plan}.\n"
                    ."Your subscription renews once the provider confirms it.\n{$url}",
            ],

            'payment_confirmed' => $ar ? [
                'subject' => "تم تأكيد الدفع — {$amount}",
                'body' => "أكّد المزوّد {$amount} لباقة {$plan}.\n"
                    ."تنتهي الفترة الحالية في {$date}.\n{$url}",
            ] : [
                'subject' => "Payment confirmed — {$amount}",
                'body' => "The provider confirmed {$amount} for {$plan}.\n"
                    ."Your current period runs until {$date}.\n{$url}",
            ],

            'renewal_failed' => $ar ? [
                'subject' => 'تعذّر تجديد اشتراكك',
                'body' => "رفض المزوّد عملية التجديد بقيمة {$amount}.\n"
                    ."حسابك يعمل حتى {$date}. حدّث وسيلة الدفع قبلها لتفادي التعليق.\n{$url}",
            ] : [
                'subject' => 'We could not renew your subscription',
                'body' => "The provider refused the renewal of {$amount}.\n"
                    ."Your account works until {$date}. Update your payment method before then to avoid suspension.\n{$url}",
            ],

            // Past due is an OPERATING state, and the message says so rather than alarming somebody
            // whose account is still working.
            'past_due' => $ar ? [
                'subject' => 'اشتراكك متأخر السداد',
                'body' => "لم يصل تأكيد الدفع. حسابك يعمل حتى {$date}.\n"
                    ."بعدها يُعلَّق الحساب وتبقى بياناتك محفوظة.\n{$url}",
            ] : [
                'subject' => 'Your subscription is past due',
                'body' => "No payment confirmation arrived. Your account works until {$date}.\n"
                    ."After that it is suspended, and your data is kept.\n{$url}",
            ],

            'suspended' => $ar ? [
                'subject' => 'تم تعليق حسابك',
                'body' => "انتهت مهلة السماح دون تأكيد دفع، فعُلّق الوصول إلى مساحة العمل.\n"
                    ."بياناتك محفوظة بالكامل. أكمل الدفع لإعادة التفعيل.\n{$url}",
            ] : [
                'subject' => 'Your account has been suspended',
                'body' => "The grace period ended without a confirmed payment, so access to the workspace has stopped.\n"
                    ."All of your data is kept. Complete the payment to reactivate.\n{$url}",
            ],

            'reactivated' => $ar ? [
                'subject' => 'تمت إعادة تفعيل حسابك',
                'body' => "عاد حسابك للعمل، وتنتهي الفترة الحالية في {$date}.\n{$url}",
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
                'body' => "تحتفظ بباقتك الحالية كاملة حتى {$effectiveAt}، ثم تنتقل إلى {$planName}.\n"
                    ."لم يُخصم أي مبلغ ولم يُسترد أي مبلغ.\n{$url}",
            ] : [
                'subject' => "You move to {$planName} on {$effectiveAt}",
                'body' => "You keep your current plan in full until {$effectiveAt}, then move to {$planName}.\n"
                    ."Nothing was charged or refunded.\n{$url}",
            ],

            'registration_approved' => $ar ? [
                'subject' => 'تم اعتماد طلب تسجيلك',
                'body' => "اعتمدنا طلبك.\n"
                    .($amount !== '' ? "الخطوة التالية: إتمام الدفع ({$amount}).\n" : "مساحة عملك جاهزة.\n")
                    .$url,
            ] : [
                'subject' => 'Your registration has been approved',
                'body' => "We approved your application.\n"
                    .($amount !== '' ? "Next: complete the payment ({$amount}).\n" : "Your workspace is ready.\n")
                    .$url,
            ],

            /*
             * SIGNUP-DECISION-001 — four different things that all begin with «approved», told apart, because each one
             * asks something different of the reader or asks nothing.
             */
            'registration_approved_steps_remain' => $ar ? [
                'subject' => 'تم اعتماد طلبك، وبقيت خطوات للتفعيل',
                'body' => "اعتمدنا طلبك، ولم يُفعَّل الحساب بعد. أكمل الخطوات المتبقية من صفحة حالة الطلب.\n".$url,
            ] : [
                'subject' => 'Your application is approved, with steps left',
                'body' => "We approved your application. The account is not active yet: finish the remaining steps from your status page.\n".$url,
            ],

            'registration_approved_discount' => $ar ? [
                'subject' => 'تم اعتماد طلبك بخصم، وبانتظار سداد المبلغ المتبقي',
                'body' => "اعتمدنا طلبك بخصم {$days}% على الفترة الأولى. المبلغ المتبقي {$amount}.\n"
                    ."لن يُفعَّل الحساب قبل تأكيد الدفع.\n".$url,
            ] : [
                'subject' => 'Approved with a discount: the remainder is awaiting payment',
                'body' => "We approved your application with {$days}% off the first period. The remainder is {$amount}.\n"
                    ."The account activates once that payment is confirmed.\n".$url,
            ],

            'registration_approved_waived' => $ar ? [
                'subject' => 'تم اعتماد طلبك بإعفاء من رسوم الفترة الأولى، وحسابك نشط',
                'body' => "أعفيناك من رسوم الفترة الأولى، وحسابك نشط الآن. لم يُخصم منك أي مبلغ.\n"
                    ."ينتهي الإعفاء في {$date}، ويصبح الاشتراك بعدها مستحقًا بقيمة {$amount}.\n".$url,
            ] : [
                'subject' => 'Approved with the first period waived: your account is active',
                'body' => "We waived the first period's fee and your account is active. Nothing was charged.\n"
                    ."The waiver ends on {$date}. After that the subscription is due at {$amount}.\n".$url,
            ],

            'registration_approved_trial' => $ar ? [
                'subject' => 'تم اعتماد طلبك بفترة تجربة، وحسابك نشط',
                'body' => "حسابك نشط بفترة تجربة تنتهي في {$date}. لن يُخصم منك أي مبلغ تلقائيًا.\n"
                    ."بعد انتهائها يصبح الدفع مستحقًا بقيمة {$amount} لمواصلة الخدمة.\n".$url,
            ] : [
                'subject' => 'Approved with a trial: your account is active',
                'body' => "Your account is active on a trial ending {$date}. Nothing is charged automatically.\n"
                    ."After it ends, {$amount} is due to continue.\n".$url,
            ],

            'admin_trial_ending' => $ar ? [
                'subject' => "تنتهي فترة تجربتك في {$date}",
                'body' => "تنتهي فترة تجربتك في {$date}، وبعدها يصبح الدفع مستحقًا لمواصلة الخدمة. لن يُخصم أي مبلغ تلقائيًا.\n".$url,
            ] : [
                'subject' => "Your trial ends on {$date}",
                'body' => "Your trial ends on {$date}. After that, payment is due to continue. Nothing is charged automatically.\n".$url,
            ],

            // A refusal always carries its reason: "rejected" with no explanation is a support ticket
            // nobody can answer either.
            'registration_rejected' => $ar ? [
                'subject' => 'لم نتمكن من اعتماد طلب تسجيلك',
                'body' => "السبب: {$reason}\n"
                    .'يمكنك التقديم مرة أخرى.',
            ] : [
                'subject' => 'We could not approve your registration',
                'body' => "Reason: {$reason}\n"
                    .'You can apply again.',
            ],

            'registration_information_requested' => $ar ? [
                'subject' => 'نحتاج معلومات إضافية لإكمال طلبك',
                'body' => "نحتاج منك التالي:\n{$reason}\n{$url}",
            ] : [
                'subject' => 'We need a little more to finish your application',
                'body' => "We need the following from you:\n{$reason}\n{$url}",
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
            'subscription_started', 'trial_started', 'trial_ending', 'trial_converted', 'payment_confirmed',
            'renewal_failed', 'past_due', 'suspended', 'reactivated',
            'plan_changed', 'plan_change_scheduled',
            'registration_approved', 'registration_rejected', 'registration_information_requested',
            'registration_approved_steps_remain', 'registration_approved_discount', 'registration_approved_waived',
            'registration_approved_trial', 'admin_trial_ending',
        ];
    }
}
