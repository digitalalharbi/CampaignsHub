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
                return $line($ar ? 'مبيعات الموقع نزلت '.$p($change ?? 0) : 'Website sales fell '.$p($change ?? 0), $ar ? 'مقارنة بالفترة اللي قبلها.' : 'Against the previous period.', $moved,
                    $ar ? 'تحقق من التتبع ثم الحملات الأعلى صرفًا.' : 'Check tracking works, then the biggest-spending campaigns.');
            case 'ga4_cvr_drop':
                return $line($ar ? 'تحويل الموقع نزل '.$p($change ?? 0) : 'Website conversion fell '.$p($change ?? 0), $ar ? 'زوار أكثر يطلعون بدون ما يشترون.' : 'More visitors leave without buying.', $moved,
                    $ar ? 'افتح صفحة الشراء ← الأعطال ← السرعة.' : 'Open the checkout and check for faults and speed.');
            case 'ga4_bounce_spike':
                return $line($ar ? 'الارتداد زاد '.$p($change ?? 0) : 'Visitor bounce rose '.$p($change ?? 0), $ar ? 'زوار يطلعون من أول صفحة.' : 'Visitors leave from the first page.', $moved,
                    $ar ? 'راجع صفحة الهبوط ثم الإعلان اللي يوصل لها.' : 'Review the landing page and the ad that leads to it.');
            case 'ga4_aov_shift':
                $up = ($change ?? 0) > 0;

                return $line($ar ? 'متوسط السلة '.($up ? 'زاد ' : 'نزل ').$p($change ?? 0) : 'Basket size '.($up ? 'rose ' : 'fell ').$p($change ?? 0), $ar ? 'قيمة الطلب الواحد تغيّرت بوضوح.' : 'A notable move in the value of one order.', $moved,
                    $ar ? 'راجع العروض ثم الأسعار اللي تغيّرت.' : 'Review the offers and prices that changed.');
            case 'ga4_attribution_gap':
                $who = isset($ctx['platform']) && $ctx['platform'] !== '' ? AdPlatforms::name((string) $ctx['platform'], $ar ? 'ar' : 'en') : ($ar ? 'منصة' : 'A platform');

                return $line($ar ? "{$who} تسجّل أكثر من الموقع" : "{$who} claims more than the website recorded", $ar ? 'تحويلات المنصة أكثر مما نسبه الموقع لها.' : 'The platform’s conversions exceed what the website credited to it.',
                    ($ar ? 'الفرق ' : 'Gap ').$p(((float) ($ctx['share'] ?? 0)) * 100), $ar ? 'تحقق من وسوم الروابط ثم موافقة الزوار.' : 'Check link tags and visitor consent.');
            case 'ga4_tracking_loss':
                $drop = (float) ($ctx['drop'] ?? 0) * 100;

                return $line($ar ? 'زيارات الموقع نزلت '.$p($drop).' والمنصات مكمّلة صرف' : 'Website visits fell '.$p($drop).' while platforms keep spending', $ar ? 'المقارنة بالفترة اللي قبلها.' : 'Against the previous period.',
                    isset($ctx['previous'], $ctx['sessions']) ? $ba($ctx['previous'], $ctx['sessions']) : '', $ar ? 'التتبع ← الوسم ← أصلحه قبل ما تصرف أكثر.' : 'Open tracking and fix the tag before spending more.', 'tracking');
            case 'ga4_unmatched_spend':
                return $line($ar ? $p(((float) ($ctx['share'] ?? 0)) * 100).' من الصرف بدون زيارة مسجّلة' : $p(((float) ($ctx['share'] ?? 0)) * 100).' of spend has no measured visit', $ar ? 'نقرات ما سُجّلت لها زيارة في الموقع.' : 'Clicks with no visit recorded on the website.',
                    isset($ctx['campaigns']) ? ($ar ? 'حملات: ' : 'Campaigns: ').$n($ctx['campaigns']) : '', $ar ? 'أضف وسوم الروابط ثم راجع إعداد الموافقة.' : 'Add link tags and review the consent setup.', 'tracking');
            case 'lead_unassigned':
                $mins = (int) ($ctx['minutes'] ?? 60);

                return $line($ar ? $n($count).' مهتمين بدون مسؤول' : $n($count).' leads with no owner', $ar ? 'ما انسندوا لأحد من أكثر من '.self::span($mins, true).'.' : 'Nobody was assigned for over '.self::span($mins, false).'.',
                    '', $ar ? 'أسند كل مهتم لمسؤول الحين.' : 'Assign each lead to an owner now.', 'leads');
            case 'lead_no_contact':
                $mins = (int) ($ctx['minutes'] ?? 240);

                return $line($ar ? $n($count).' مهتمين ما تواصل معهم أحد' : $n($count).' leads nobody has tried', $ar ? 'لهم مسؤول بس ما اتصل أحد خلال '.self::span($mins, true).'.' : 'They have an owner, yet no contact attempt in '.self::span($mins, false).'.',
                    '', $ar ? 'ابدأ بالأقدم.' : 'Start calling with the oldest.', 'leads_workspace');
            case 'lead_follow_up_overdue':
                return $line($ar ? $n($count).' متابعات فات موعدها' : $n($count).' follow-ups past their date', $ar ? 'وعد بالتواصل فات وقته.' : 'A promised contact is late.',
                    '', $ar ? 'خلّص المتأخر اليوم.' : 'Clear the late ones today.', 'leads_workspace');
            case 'tracking_health':
                $t = (string) ($ctx['title_ar'] ?? '');

                return $line($ar && $t !== '' ? $t : ($ar ? 'تتبع النماذج فيه مشكلة' : 'A form-tracking problem'), $ar ? (string) ($ctx['message_ar'] ?? '') : '', '',
                    $ar ? 'افتح صحة التتبع ← أصلح الفحص الفاشل.' : 'Open tracking health and fix the failing check.', 'tracking');
            case 'launch_advance':
                $t = (string) ($ctx['title_ar'] ?? '');

                return $line($ar && $t !== '' ? $t : ($ar ? 'حساب جاهز لمرحلة إطلاق أفضل' : 'An account earned a better launch stage'), $ar ? 'ما تغيّر شيء لوحده.' : 'Nothing was changed automatically.',
                    isset($ctx['from'], $ctx['to']) ? AlertEventText::ltr((string) $ctx['from'].' → '.(string) $ctx['to']) : '', $ar ? 'افتح الإطلاق وقرّر.' : 'Open the launch page and decide.', 'launch');
            case 'sample_alert':
                return $line($ar ? 'تنبيه تجريبي — لا شيء مطلوب' : 'Sample alert — nothing to do', $ar ? 'طلبته بنفسك عشان تتأكد إن التنبيهات توصلك.' : 'You asked for it to check that alerts reach you.', '',
                    $ar ? 'ما يحتاج منك شيء.' : 'No action is needed.', 'alerts');
            case 'performance':
                $t = (string) ($ctx['title_ar'] ?? '');

                return $line($ar ? ($t !== '' ? $t : 'الأداء تغيّر') : 'A performance change needs a look', $ar ? (string) ($ctx['message_ar'] ?? '') : '', '', $ar ? 'افتح الأداء ← قارن بالفترة اللي قبلها.' : 'Open performance and compare with the previous period.');
            case 'security':
                return $line($ar ? 'تنبيه أمان على حسابك' : 'A security alert on your account', $ar ? 'صار شيء على حسابك، تأكد منه.' : 'Something happened on your account worth checking.', '',
                    $ar ? 'لو مو أنت: غيّر كلمة المرور فورًا.' : 'If it was not you, change your password now.', 'alerts');
            case 'report_failed':
                return $line($ar ? 'التقرير ما انطلع' : 'A report could not be generated', $ar ? 'التقرير ما راح يوصل بموعده.' : 'The report will not arrive on time.', '', $ar ? 'أعد توليده أو افتحه وشوف السبب.' : 'Regenerate it, or open it to see why.', 'reports');
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
