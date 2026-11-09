<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Support\AdPlatforms;

/**
 * DIGESTS-VISUAL-V2-001 — the wording of every alert rule `AlertEventText` had no case for.
 *
 * Website (GA4 ×7), lead SLA (×3), tracking health, launch advance, performance, security, the report and the
 * sample alert each said «تنبيه جديد يحتاج مراجعتك — افتح التنبيه لترى التفاصيل» although the event already carried its
 * figures. They now say what happened, in numbers, with one next step. The source is called «الموقع» — never the
 * analytics vendor — and a date or a percentage is isolated left-to-right so an Arabic sentence cannot print it backwards.
 */
final class AlertRuleCopy
{
    /** The rule types this class words. */
    public const TYPES = [
        'ga4_sales_drop', 'ga4_bounce_spike', 'ga4_cvr_drop', 'ga4_aov_shift', 'ga4_attribution_gap', 'ga4_tracking_loss', 'ga4_unmatched_spend',
        'lead_unassigned', 'lead_no_contact', 'lead_follow_up_overdue',
        'tracking_health', 'launch_advance', 'sample_alert', 'performance', 'security', 'report_failed',
    ];

    /** @return array{headline: string, text: string, numbers: string, action: string, link: string}|null null = not one of ours */
    public static function say(string $type, array $ctx, bool $ar): ?array
    {
        $p = static fn ($v): string => AlertEventText::ltr(rtrim(rtrim(number_format(abs((float) $v), 1), '0'), '.').'%');
        $n = static fn ($v, int $d = 0): string => number_format((float) $v, $d);
        $ba = static fn ($was, $now, int $d = 0): string => ($ar ? 'قبل ' : 'Before ').$n($was, $d).' ← '.($ar ? 'الآن ' : 'Now ').$n($now, $d);
        $line = static fn (string $h, string $t, string $num, string $a, string $l = 'analytics'): array => ['headline' => $h, 'text' => $t, 'numbers' => $num, 'action' => $a, 'link' => $l];
        $count = (int) ($ctx['count'] ?? 0);
        $change = is_numeric($ctx['change_pct'] ?? null) ? (float) $ctx['change_pct'] : null;
        $moved = isset($ctx['previous'], $ctx['current']) && is_numeric($ctx['previous']) && is_numeric($ctx['current']) ? $ba($ctx['previous'], $ctx['current'], isset($ctx['key']) && in_array($ctx['key'], ['bounce_rate', 'conversion_rate'], true) ? 1 : 0) : '';

        switch ($type) {
            case 'ga4_sales_drop':
                return $line($ar ? 'مبيعات الموقع انخفضت '.$p($change ?? 0) : 'Website sales fell '.$p($change ?? 0), $ar ? 'مقارنة بالفترة السابقة.' : 'Against the previous period.', $moved,
                    $ar ? 'تأكد أن التتبع يعمل، ثم راجع الحملات الأعلى إنفاقًا.' : 'Check tracking works, then the biggest-spending campaigns.');
            case 'ga4_cvr_drop':
                return $line($ar ? 'تحويل الموقع انخفض '.$p($change ?? 0) : 'Website conversion fell '.$p($change ?? 0), $ar ? 'زوار أكثر يغادرون دون أن يشتروا.' : 'More visitors leave without buying.', $moved,
                    $ar ? 'افتح صفحة الشراء وتحقق من الأعطال والسرعة.' : 'Open the checkout and check for faults and speed.');
            case 'ga4_bounce_spike':
                return $line($ar ? 'ارتداد الزوار ارتفع '.$p($change ?? 0) : 'Visitor bounce rose '.$p($change ?? 0), $ar ? 'زوار يغادرون من الصفحة الأولى.' : 'Visitors leave from the first page.', $moved,
                    $ar ? 'راجع الصفحة المقصودة والإعلان الذي يقود إليها.' : 'Review the landing page and the ad that leads to it.');
            case 'ga4_aov_shift':
                $up = ($change ?? 0) > 0;

                return $line($ar ? 'متوسط السلة '.($up ? 'ارتفع ' : 'انخفض ').$p($change ?? 0) : 'Basket size '.($up ? 'rose ' : 'fell ').$p($change ?? 0), $ar ? 'تغيّر ملحوظ في قيمة الطلب الواحد.' : 'A notable move in the value of one order.', $moved,
                    $ar ? 'راجع العروض والأسعار التي تغيّرت.' : 'Review the offers and prices that changed.');
            case 'ga4_attribution_gap':
                $who = isset($ctx['platform']) && $ctx['platform'] !== '' ? AdPlatforms::name((string) $ctx['platform'], $ar ? 'ar' : 'en') : ($ar ? 'منصة' : 'A platform');

                return $line($ar ? "{$who} تدّعي أكثر مما سجّله الموقع" : "{$who} claims more than the website recorded", $ar ? 'تحويلات المنصة تفوق ما نسبه الموقع لها.' : 'The platform’s conversions exceed what the website credited to it.',
                    ($ar ? 'الفرق ' : 'Gap ').$p(((float) ($ctx['share'] ?? 0)) * 100), $ar ? 'تحقق من وسوم الروابط ومن موافقة الزوار.' : 'Check link tags and visitor consent.');
            case 'ga4_tracking_loss':
                $drop = (float) ($ctx['drop'] ?? 0) * 100;

                return $line($ar ? 'زيارات الموقع هبطت '.$p($drop).' والمنصات ما زالت تنفق' : 'Website visits fell '.$p($drop).' while platforms keep spending', $ar ? 'غالبًا التتبع معطّل، لا الطلب.' : 'Most likely tracking is broken, not demand.',
                    isset($ctx['previous'], $ctx['sessions']) ? $ba($ctx['previous'], $ctx['sessions']) : '', $ar ? 'افتح صفحة التتبع وأصلح الوسم قبل إنفاق المزيد.' : 'Open tracking and fix the tag before spending more.', 'tracking');
            case 'ga4_unmatched_spend':
                return $line($ar ? $p(((float) ($ctx['share'] ?? 0)) * 100).' من الإنفاق بلا زيارة مقيسة' : $p(((float) ($ctx['share'] ?? 0)) * 100).' of spend has no measured visit', $ar ? 'نقرات لم تصل إلى الموقع: موافقة أو مانع إعلانات أو رابط بلا وسم.' : 'Clicks that never reached the site: consent, blockers or untagged links.',
                    isset($ctx['campaigns']) ? ($ar ? 'حملات: ' : 'Campaigns: ').$n($ctx['campaigns']) : '', $ar ? 'أضف وسوم الروابط وراجع إعداد الموافقة.' : 'Add link tags and review the consent setup.', 'tracking');
            case 'lead_unassigned':
                $mins = (int) ($ctx['minutes'] ?? 60);

                return $line($ar ? $n($count).' عملاء محتملون بلا مالك' : $n($count).' leads with no owner', $ar ? 'لم يُسند لأحد منذ أكثر من '.self::span($mins, true).'.' : 'Nobody was assigned for over '.self::span($mins, false).'.',
                    '', $ar ? 'أسند كل عميل لمسؤول الآن.' : 'Assign each lead to an owner now.', 'leads');
            case 'lead_no_contact':
                $mins = (int) ($ctx['minutes'] ?? 240);

                return $line($ar ? $n($count).' عملاء بلا أي محاولة تواصل' : $n($count).' leads nobody has tried', $ar ? 'لهم مالك لكن لم يتصل بهم أحد خلال '.self::span($mins, true).'.' : 'They have an owner, yet no contact attempt in '.self::span($mins, false).'.',
                    '', $ar ? 'ابدأ بالاتصال بالأقدم.' : 'Start calling with the oldest.', 'leads_workspace');
            case 'lead_follow_up_overdue':
                return $line($ar ? $n($count).' متابعات تجاوزت موعدها' : $n($count).' follow-ups past their date', $ar ? 'وعدٌ بالتواصل مرّ وقته.' : 'A promised contact is late.',
                    '', $ar ? 'أنجز المتأخر اليوم.' : 'Clear the late ones today.', 'leads_workspace');
            case 'tracking_health':
                $t = (string) ($ctx['title_ar'] ?? '');

                return $line($ar && $t !== '' ? $t : ($ar ? 'مشكلة في تتبع النماذج' : 'A form-tracking problem'), $ar ? (string) ($ctx['message_ar'] ?? '') : '', '',
                    $ar ? 'افتح صفحة صحة التتبع وأصلح الفحص الفاشل.' : 'Open tracking health and fix the failing check.', 'tracking');
            case 'launch_advance':
                $t = (string) ($ctx['title_ar'] ?? '');

                return $line($ar && $t !== '' ? $t : ($ar ? 'حساب يستحق مرحلة إطلاق أفضل' : 'An account earned a better launch stage'), $ar ? 'لم يتغيّر شيء تلقائيًا.' : 'Nothing was changed automatically.',
                    isset($ctx['from'], $ctx['to']) ? AlertEventText::ltr((string) $ctx['from'].' → '.(string) $ctx['to']) : '', $ar ? 'افتح صفحة الإطلاق وقرّر.' : 'Open the launch page and decide.', 'launch');
            case 'sample_alert':
                return $line($ar ? 'تنبيه تجريبي — لا شيء مطلوب' : 'Sample alert — nothing to do', $ar ? 'طلبته بنفسك لتتأكد أن التنبيهات تصلك.' : 'You asked for it to check that alerts reach you.', '',
                    $ar ? 'لا يلزمك أي إجراء.' : 'No action is needed.', 'alerts');
            case 'performance':
                $t = (string) ($ctx['title_ar'] ?? '');

                return $line($ar ? ($t !== '' ? $t : 'تغيّر في الأداء يحتاج نظرة') : 'A performance change needs a look', $ar ? (string) ($ctx['message_ar'] ?? '') : '', '', $ar ? 'افتح الأداء وقارن بالفترة السابقة.' : 'Open performance and compare with the previous period.');
            case 'security':
                return $line($ar ? 'تنبيه أمني على حسابك' : 'A security alert on your account', $ar ? 'حدث شيء على حسابك يستحق التأكد.' : 'Something happened on your account worth checking.', '',
                    $ar ? 'إن لم تكن أنت، غيّر كلمة المرور فورًا.' : 'If it was not you, change your password now.', 'alerts');
            case 'report_failed':
                return $line($ar ? 'تعذّر توليد التقرير' : 'A report could not be generated', $ar ? 'لن يصل التقرير في موعده.' : 'The report will not arrive on time.', '', $ar ? 'أعد توليده أو افتحه لترى السبب.' : 'Regenerate it, or open it to see why.', 'reports');
        }

        return null;
    }

    private static function span(int $minutes, bool $ar): string
    {
        if ($minutes % 60 === 0) {
            $h = intdiv($minutes, 60);

            return $ar ? ($h === 1 ? 'ساعة' : ($h === 2 ? 'ساعتين' : $h.' ساعات')) : ($h === 1 ? 'an hour' : $h.' hours');
        }

        return $ar ? $minutes.' دقيقة' : $minutes.' minutes';
    }
}
