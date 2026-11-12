<?php

declare(strict_types=1);

namespace App\Domains\Platform\Alerts;

use App\Domains\Notifications\Support\AlertVisual;
use App\Domains\Platform\Models\PlatformAlert;
use Illuminate\Support\Carbon;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — the one place an alert's words and drawing are made, for the email and the inbox alike.
 *
 * The rules it follows are the Owner's (OWNER_NOTIFICATIONS_RULES_2026-11-12 §6–9, §11):
 * - evidence only: every sentence reads a fact the detector recorded; a gateway's status is quoted, never translated
 *   into a cause; where nothing was recorded the text says so instead of guessing;
 * - an explicit time, in Riyadh, with the day and the date;
 * - a drawing in every card: the customer's way in as stages, or the number of customers affected;
 * - plain Saudi wording, «←» between steps, Latin digits;
 * - no card data, no secret, no link a customer could use.
 *
 * @phpstan-type Card array{severity: string, title: string, context: string, line: string, more: list<string>, steps: string, viz: array<string,mixed>, links: list<array{label: string, path: string}>}
 */
final class PlatformAlertPresenter
{
    private const DAYS_AR = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    private const MONTHS_AR = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    /** @return Card */
    public function present(PlatformAlert $alert, bool $ar): array
    {
        $f = $alert->facts;

        $card = match ($alert->kind) {
            PlatformAlert::ABANDONED => $this->abandoned($f, $ar),
            PlatformAlert::UNVERIFIED => $this->unverified($f, $ar),
            PlatformAlert::MISMATCH => $this->mismatch($f, $ar),
            PlatformAlert::UNSETTLED => $this->unsettled($f, $ar),
            default => $this->blocking($f, $ar),
        };

        return $card + [
            'severity' => (string) $alert->severity,
            'links' => $this->links($f, $ar),
        ];
    }

    /** The subject line and the bell title are the card title. */
    public function title(PlatformAlert $alert, bool $ar): string
    {
        return $this->present($alert, $ar)['title'];
    }

    // ── Cards ─────────────────────────────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $f */
    private function abandoned(array $f, bool $ar): array
    {
        $who = $this->who($f, $ar);

        return [
            'title' => $ar ? 'عميل وصل للدفع وما كمّل' : 'A customer reached payment and did not finish',
            'context' => $who,
            'line' => $ar
                ? "طلب الدفع {$this->when($f['opened_at'] ?? null, true)} وما وصل تأكيد من ميسر خلال {$f['window_minutes']} دقيقة."
                : "Asked to pay {$this->when($f['opened_at'] ?? null, false)}; no confirmation from Moyasar within {$f['window_minutes']} minutes.",
            'more' => array_values(array_filter([
                $this->planLine($f, $ar),
                $ar ? 'آخر خطوة وصلها: صفحة الدفع' : 'Last step reached: the payment page',
                $ar ? 'ما وصلنا من ميسر أي إشعار عن هالمحاولة.' : 'No event from Moyasar about this attempt.',
            ])),
            'steps' => $ar ? 'تواصل معه ← أعد إرسال رابط الدفع' : 'Contact them → send the payment link again',
            'viz' => ['type' => 'steps', 'steps' => array_map(fn (array $s): array => [
                'label' => $this->stage((string) $s['key'], $ar),
                'state' => (string) $s['state'],
            ], (array) ($f['funnel'] ?? []))],
        ];
    }

    /** @param  array<string,mixed>  $f */
    private function unverified(array $f, bool $ar): array
    {
        $gateway = $f['gateway_status'] ?? null;
        $ours = (string) ($f['our_status'] ?? '');
        $audit = is_array($f['audit'] ?? null) ? $f['audit'] : null;

        $line = $gateway !== null
            ? ($ar
                ? "ميسر أرسلت الحالة «{$gateway}» {$this->when($f['gateway_event_at'] ?? null, true)}، والدفعة عندنا «{$ours}»."
                : "Moyasar sent status «{$gateway}» {$this->when($f['gateway_event_at'] ?? null, false)}; the payment here is «{$ours}».")
            : ($ar
                ? "ما وصلنا من ميسر إشعار بحالة الدفعة، والدفعة عندنا «{$ours}» من {$this->when($f['opened_at'] ?? null, true)}."
                : "No status event from Moyasar; the payment here has been «{$ours}» since {$this->when($f['opened_at'] ?? null, false)}.");

        return [
            // «Paid» only on Moyasar's word (or the payment path's refusal of it); a charge merely started says so instead.
            'title' => ($f['paid_at_gateway'] ?? true)
                ? ($ar ? 'دفع وما تأكد الدفع من ميسر' : 'Paid, but Moyasar did not confirm it')
                : ($ar ? 'بدأ الدفع في ميسر وما اكتمل' : 'A charge started at Moyasar and did not complete'),
            'context' => $this->who($f, $ar),
            'line' => $line,
            'more' => array_values(array_filter([
                $this->planLine($f, $ar),
                $audit === null ? null : $this->auditLine($audit, $ar),
            ])),
            'steps' => $ar ? 'افتح الدفعة في لوحة ميسر ← طابق المبلغ والمرجع ← تواصل معه' : 'Open the payment in Moyasar → match amount and reference → contact them',
            'viz' => ['type' => 'steps', 'steps' => [
                ['label' => $this->stage('checkout', $ar), 'state' => 'ok'],
                ['label' => ($ar ? 'ميسر: ' : 'Moyasar: ').($gateway ?? ($ar ? 'بدون رد' : 'no reply')), 'state' => 'watch'],
                ['label' => $ar ? 'التأكيد عندنا' : 'Confirmed here', 'state' => 'bad'],
                ['label' => $this->stage('active', $ar), 'state' => 'none'],
            ]],
        ];
    }

    /** @param  array<string,mixed>  $f */
    private function mismatch(array $f, bool $ar): array
    {
        $variant = (string) ($f['variant'] ?? '');
        $paid = $this->when($f['paid_at'] ?? null, $ar);
        $grace = (int) ($f['grace_minutes'] ?? 0);
        $invoice = (string) ($f['invoice_number'] ?? '');

        [$title, $line, $broken] = match ($variant) {
            'not_provisioned' => [
                $ar ? 'دفعة ناجحة والمساحة ما انفتحت' : 'Paid, but no workspace was opened',
                $ar ? "ميسر أكدت الدفع {$paid} وما انفتحت مساحة للعميل بعد {$grace} دقيقة." : "Moyasar confirmed payment {$paid}; no workspace after {$grace} minutes.",
                'workspace',
            ],
            'no_active_subscription' => [
                $ar ? 'دفعة ناجحة والاشتراك ما تفعّل' : 'Paid, but the subscription is not active',
                $ar ? "ميسر أكدت الدفع {$paid} والمساحة بدون اشتراك فعّال بعد {$grace} دقيقة." : "Moyasar confirmed payment {$paid}; no active subscription after {$grace} minutes.",
                'active',
            ],
            'invoice_unpaid' => [
                $ar ? 'دفعة ناجحة والفاتورة ما تعلّمت مدفوعة' : 'Paid, but the invoice is not marked paid',
                $ar ? "الدفعة تأكدت {$paid} والفاتورة {$invoice} حالتها «{$f['invoice_status']}»." : "Payment confirmed {$paid}; invoice {$invoice} is «{$f['invoice_status']}».",
                'invoice',
            ],
            'refund_due' => [
                $ar ? 'دفعة ناجحة وما تفعّلت، والمبلغ مستحق للعميل' : 'Paid, not activated, and owed back',
                $ar ? 'ميسر أكدت الدفع والمنصة رفضت التفعيل وسجّلت أن المبلغ يرجع للعميل.' : 'Moyasar confirmed payment; the platform refused activation and recorded the money as owed back.',
                'active',
            ],
            default => [
                $ar ? 'فاتورة مدفوعة بدون دفعة مؤكدة' : 'An invoice is paid with no confirmed payment',
                $ar
                    ? "الفاتورة {$invoice} تعلّمت مدفوعة {$this->when($f['invoice_paid_at'] ?? null, true)} والدفعة عندنا «".($f['payment_status'] ?? 'غير موجودة').'».'
                    : "Invoice {$invoice} was marked paid {$this->when($f['invoice_paid_at'] ?? null, false)}; the payment here is «".($f['payment_status'] ?? 'missing').'».',
                'payment',
            ],
        };

        $recorded = $f['recorded_error'] ?? null;

        return [
            'title' => $title,
            'context' => $this->who($f, $ar),
            'line' => $line,
            'more' => array_values(array_filter([
                $this->planLine($f, $ar),
                $recorded === null ? null : ($ar ? 'السبب المسجّل: ' : 'Recorded reason: ').$recorded,
                ($f['registration_state'] ?? null) === null ? null : ($ar ? 'حالة التسجيل الحين: «' : 'Application state now: «').$f['registration_state'].'»',
                $ar ? 'ما فيه أي إجراء مالي تلقائي؛ القرار لك.' : 'No money moves automatically; the decision is yours.',
            ])),
            'steps' => $variant === 'refund_due'
                ? ($ar ? 'راجع السبب ← أرجع المبلغ من لوحة ميسر ← تواصل معه' : 'Check the reason → refund from the Moyasar dashboard → contact them')
                : ($ar ? 'راجع الدفعة والفاتورة ← صحّح الاشتراك من إدارة المساحة ← تواصل معه' : 'Check payment and invoice → fix the subscription from the workspace → contact them'),
            'viz' => ['type' => 'steps', 'steps' => $broken === 'payment'
                ? [
                    ['label' => $ar ? 'الفاتورة: مدفوعة' : 'Invoice: paid', 'state' => 'watch'],
                    ['label' => $ar ? 'دفعة مؤكدة' : 'Confirmed payment', 'state' => 'bad'],
                ]
                : [
                    ['label' => $ar ? 'ميسر: مدفوع' : 'Moyasar: paid', 'state' => 'ok'],
                    ['label' => $ar ? 'الفاتورة' : 'Invoice', 'state' => $broken === 'invoice' ? 'bad' : 'ok'],
                    ['label' => $broken === 'workspace' ? ($ar ? 'المساحة' : 'Workspace') : $this->stage('active', $ar), 'state' => $broken === 'invoice' ? 'none' : 'bad'],
                ]],
        ];
    }

    /** @param  array<string,mixed>  $f */
    private function unsettled(array $f, bool $ar): array
    {
        $days = (int) ($f['expected_days'] ?? 0);

        return [
            'title' => $ar ? 'دفعة مؤكدة ما ظهرت في التسوية' : 'A confirmed payment is not in a settlement',
            'context' => $this->who($f, $ar),
            'line' => $ar
                ? "تأكدت {$this->when($f['paid_at'] ?? null, true)} وما ظهرت في تسويات ميسر بعد {$days} أيام."
                : "Confirmed {$this->when($f['paid_at'] ?? null, false)}; not in a Moyasar settlement after {$days} days.",
            'more' => array_values(array_filter([$this->planLine($f, $ar)])),
            'steps' => $ar ? 'راجع التسويات في لوحة ميسر ← تواصل مع ميسر' : 'Check settlements in Moyasar → contact Moyasar',
            'viz' => ['type' => 'steps', 'steps' => [
                ['label' => $ar ? 'الدفع' : 'Payment', 'state' => 'ok'],
                ['label' => $ar ? 'التسوية' : 'Settlement', 'state' => 'bad'],
            ]],
        ];
    }

    /** @param  array<string,mixed>  $f */
    private function blocking(array $f, bool $ar): array
    {
        $type = (string) ($f['type'] ?? '');
        /** @var list<array<string,mixed>> $items */
        $items = array_values((array) ($f['items'] ?? []));
        $n = count($items);
        $last = $items[$n - 1] ?? [];
        $day = $this->day((string) ($f['day'] ?? ''), $ar);

        $title = match ($type) {
            'otp_not_delivered' => $ar ? 'رمز التحقق ما وصل لعملاء' : 'Verification codes did not reach customers',
            'otp_expired' => $ar ? 'رمز التحقق انتهى وما كمّلوا التسجيل' : 'Verification codes expired before sign-up finished',
            'payment_error' => $ar ? 'الدفع تعثّر لعملاء' : 'Payments failed for customers',
            default => $ar ? 'دعوات ما وصلت' : 'Invitations were not delivered',
        };

        $steps = match ($type) {
            'otp_not_delivered', 'otp_expired' => $ar ? 'تواصل معهم ← أعد إرسال الرمز' : 'Contact them → send the code again',
            'payment_error' => $ar ? 'تواصل معهم ← أعد إرسال رابط الدفع' : 'Contact them → send the payment link again',
            default => $ar ? 'تواصل مع المساحة ← أعد إرسال الدعوة' : 'Contact the workspace → send the invitation again',
        };

        return [
            'title' => $title,
            'context' => $day,
            'line' => $ar
                ? $this->customersAr($n)." يوم {$day}، آخرهم ".$this->itemWho($last, true).' الساعة '.$this->clock($last['at'] ?? null).' بتوقيت الرياض.'
                : "{$n} ".($n === 1 ? 'customer' : 'customers')." on {$day}; latest ".$this->itemWho($last, false).' at '.$this->clock($last['at'] ?? null).' Riyadh time.',
            'more' => array_map(fn (array $i): string => $this->itemLine($i, $ar), array_slice(array_reverse($items), 0, 4)),
            'steps' => $steps,
            // The drawing prints the number beside the noun, so the noun takes the form that number needs.
            'viz' => AlertVisual::count($n, $ar ? trim(mb_substr($this->customersAr($n), mb_strlen((string) $n))) : ($n === 1 ? 'customer' : 'customers'), 'watch'),
        ];
    }

    // ── Pieces ────────────────────────────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $i */
    private function itemLine(array $i, bool $ar): string
    {
        $parts = [$this->itemWho($i, $ar), ($ar ? 'الحالة «' : 'status «').($i['status'] ?? '').'»', $this->when($i['at'] ?? null, $ar)];
        if (($i['channel'] ?? null) !== null) {
            array_splice($parts, 1, 0, [$i['channel'] === 'mobile' ? ($ar ? 'الجوال' : 'mobile') : ($ar ? 'البريد' : 'email')]);
        }
        if (($i['recorded_error'] ?? null) !== null) {
            $parts[] = (string) $i['recorded_error'];
        }

        return implode(' · ', array_filter($parts, fn ($p): bool => $p !== ''));
    }

    /** @param  array<string,mixed>  $i */
    private function itemWho(array $i, bool $ar): string
    {
        $who = trim((string) ($i['who'] ?? ''));
        if (($i['recipient'] ?? null) !== null) {
            $who = trim($who.' → '.$i['recipient']);
        }

        return $who !== '' ? $who : ($ar ? 'بدون اسم' : 'unnamed');
    }

    /** @param  array<string,mixed>  $audit */
    private function auditLine(array $audit, bool $ar): string
    {
        $said = $audit['gateway_raw_status'] ?? $audit['gateway_says'] ?? null;
        $at = $this->when($audit['at'] ?? null, $ar);

        return match ($audit['action'] ?? '') {
            'subscription.payment.unconfirmed' => $ar ? "حاولنا نسأل ميسر للتأكيد {$at} وما ردّت." : "Asked Moyasar to confirm {$at}; no answer.",
            'subscription.payment.event_contradicted' => $ar ? "سألنا ميسر مباشرة {$at} وردّت بالحالة «{$said}»." : "Asked Moyasar directly {$at}; it answered «{$said}».",
            'subscription.payment.reference_mismatch' => $ar ? "ميسر نسبت الدفعة لمرجع ثاني {$at}." : "Moyasar attributed the charge to another reference {$at}.",
            'subscription.payment.amount_mismatch' => $ar ? "المبلغ أو العملة اللي أكدتها ميسر يختلف عن المطلوب {$at}." : "Moyasar confirmed a different amount or currency {$at}.",
            default => '',
        };
    }

    /** @param  array<string,mixed>  $f */
    private function planLine(array $f, bool $ar): ?string
    {
        if (! isset($f['amount'])) {
            return null;
        }
        $plan = $ar ? ($f['plan_name_ar'] ?? $f['plan_name'] ?? $f['plan_code'] ?? null) : ($f['plan_name'] ?? $f['plan_code'] ?? null);
        $interval = match ($f['interval'] ?? null) {
            'annual' => $ar ? 'سنوي' : 'annual',
            'monthly' => $ar ? 'شهري' : 'monthly',
            default => null,
        };
        $purpose = match ($f['purpose'] ?? null) {
            'trial' => $ar ? 'رسوم التجربة' : 'trial fee',
            'plan_change' => $ar ? 'تغيير الباقة' : 'plan change',
            'reactivation' => $ar ? 'إعادة تفعيل' : 'reactivation',
            default => $ar ? 'اشتراك' : 'subscription',
        };
        $amount = number_format((float) $f['amount'], 2, '.', ',').' '.($f['currency'] ?? '');

        $planText = $plan === null ? '' : ($ar ? 'الباقة: ' : 'Plan: ').$plan.($interval === null ? '' : " ({$interval})").' · ';

        return $planText.($ar ? 'المبلغ: ' : 'Amount: ').$amount.' · '.$purpose;
    }

    /** @param  array<string,mixed>  $f */
    private function who(array $f, bool $ar): string
    {
        $who = trim((string) ($f['who'] ?? ''));

        return $who !== '' ? $who : ($ar ? 'عميل بدون اسم' : 'An unnamed customer');
    }

    private function stage(string $key, bool $ar): string
    {
        return match ($key) {
            'signup' => $ar ? 'التسجيل' : 'Sign-up',
            'verify' => $ar ? 'التحقق' : 'Verified',
            'checkout' => $ar ? 'صفحة الدفع' : 'Payment page',
            'subscription' => $ar ? 'الاشتراك الحالي' : 'Current plan',
            'confirmed' => $ar ? 'التأكيد' : 'Confirmed',
            default => $ar ? 'الاشتراك' : 'Subscription',
        };
    }

    /**
     * @param  array<string,mixed>  $f
     * @return list<array{label: string, path: string}>
     */
    private function links(array $f, bool $ar): array
    {
        $links = [];
        if (($f['registration_id'] ?? null) !== null) {
            $links[] = ['label' => $ar ? 'التسجيلات' : 'Registrations', 'path' => '/admin/registrations'];
        }
        if (($f['tenant_id'] ?? null) !== null) {
            $links[] = ['label' => $ar ? 'المساحة' : 'Workspace', 'path' => '/admin/tenants'];
        }

        return $links;
    }

    /** «الأربعاء 11 نوفمبر 14:05» in Riyadh — the day and date written out, Latin digits. */
    public function when(mixed $iso, bool $ar): string
    {
        if (! is_string($iso) || $iso === '') {
            return '';
        }
        $t = Carbon::parse($iso)->setTimezone((string) config('platform_alerts.timezone', 'Asia/Riyadh'));

        return $ar
            ? self::DAYS_AR[$t->dayOfWeek].' '.$t->day.' '.self::MONTHS_AR[$t->month].' '.$t->format('H:i')
            : $t->format('D j M H:i');
    }

    /** «1 عميل · 2 عميلان · 3 عملاء · 11 عميلًا» — the noun follows the number, digits stay Latin. */
    private function customersAr(int $n): string
    {
        $h = $n % 100;

        return $n.' '.match (true) {
            $n === 1 => 'عميل',
            $n === 2 => 'عميلان',
            $h >= 3 && $h <= 10 => 'عملاء',
            default => 'عميلًا',
        };
    }

    private function clock(mixed $iso): string
    {
        return is_string($iso) && $iso !== '' ? Carbon::parse($iso)->setTimezone((string) config('platform_alerts.timezone', 'Asia/Riyadh'))->format('H:i') : '';
    }

    private function day(string $date, bool $ar): string
    {
        if ($date === '') {
            return '';
        }
        $t = Carbon::parse($date, (string) config('platform_alerts.timezone', 'Asia/Riyadh'));

        return $ar ? self::DAYS_AR[$t->dayOfWeek].' '.$t->day.' '.self::MONTHS_AR[$t->month] : $t->format('D j M');
    }
}
