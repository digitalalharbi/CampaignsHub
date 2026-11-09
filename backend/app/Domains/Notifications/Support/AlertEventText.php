<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Support\AdPlatforms;

/**
 * EMAIL-DIGESTS-OPERATIONAL-001 — an alert event, said the way an operator needs it.
 *
 * The alert engine stores an English headline and a sentence that starts with the CAMPAIGN'S NAME and
 * sometimes quotes a provider's raw error. Mailed as-is that is what the Owner called marginal and
 * unclear: a campaign name for a headline, English inside an Arabic mail, «pause on the platform».
 *
 * This reads the event's structured context (never its stored sentence) and writes: WHAT happened as
 * the headline, the numbers (before → now, and the threshold) as their own line, which project,
 * platform and objective it is about, ONE next step, and a button to the exact page INSIDE the system.
 * The campaign's name is a secondary line only, truncated, as stored.
 *
 * Nothing here recomputes a figure; every number is one the rule wrote into the event.
 */
final class AlertEventText
{
    public const NAME_LIMIT = 60;

    /**
     * @param  array<string,mixed>  $item  an alert outbox item (see `AlertEvaluator::post()`)
     * @return array<string,mixed> the keys `AlertBundleMail` renders
     */
    public static function compose(array $item, bool $ar): array
    {
        $ctx = (array) ($item['context'] ?? []);
        $type = (string) ($item['rule_type'] ?? '');
        $projectId = isset($item['project_id']) && $item['project_id'] !== '' ? (string) $item['project_id'] : null;
        $project = (string) ($item['project_name'] ?? '');
        $campaign = ($item['entity_type'] ?? '') === UnifiedCampaign::class && ($item['entity_id'] ?? '') !== ''
            ? UnifiedCampaign::withoutGlobalScopes()->find((string) $item['entity_id'])
            : null;

        $platform = self::platformOf($ctx, $campaign, $ar);
        $objective = $campaign !== null ? self::objectiveOf((string) $campaign->objective, $ar) : '';
        $where = array_values(array_filter([$project, $platform, $objective], static fn (string $p): bool => $p !== ''));
        $recovered = str_starts_with((string) ($item['triggered_at'] ?? ''), 'recovered:');

        $say = $recovered
            ? self::recovered($ar, $platform)
            : self::say($type, $ctx, $ar, $platform);

        $url = $campaign !== null && $projectId !== null
            ? MailDeepLinks::campaign($projectId, (string) $campaign->id)
            : ($say['link'] === 'integrations'
                ? MailDeepLinks::integrations($projectId)
                : ($projectId !== null && $say['link'] === 'analytics' ? MailDeepLinks::analytics($projectId, 'performance') : MailDeepLinks::alerts((string) ($item['event_id'] ?? ''))));

        // OPERATIONAL-INTELLIGENCE-P1-001 — the priority closes the context line; thin evidence replaces the recommendation.
        $priority = (string) ($item['priority'] ?? '');
        $thin = ! $recovered && ($item['insufficient'] ?? false) === true;
        if ($thin) {
            $say['action'] = $ar ? 'بيانات غير كافية — لا نوصي بإجراء قبل اكتمال الدليل.' : 'Insufficient data — no action is recommended until the evidence is complete.';
        }
        if ($priority !== '' && ! $recovered) {
            $where[] = $priority;
        }

        return [
            'severity' => (string) $item['severity'],
            'title' => $say['headline'],
            'detail' => $say['text'],
            'context' => implode(' · ', $where),
            'numbers' => $say['numbers'],
            'figures' => $recovered ? null : AlertFigures::from($ctx, $ar),
            'action' => $say['action'],
            'url' => $url,
            'button' => $say['link'] === 'integrations'
                ? ($ar ? 'افتح صفحة الربط' : 'Open the connections page')
                : ($campaign !== null ? ($ar ? 'افتح الحملة داخل النظام' : 'Open the campaign') : ($ar ? 'افتح التنبيه داخل النظام' : 'Open the alert')),
            // The name as stored, last and quiet — never the headline.
            'secondary' => $campaign !== null ? ($ar ? 'الحملة: ' : 'Campaign: ').self::truncate((string) $campaign->name) : '',
        ];
    }

    /** A date or number kept left-to-right inside an Arabic sentence, so «2026-08-04» is not read backwards. */
    public static function ltr(string $text): string
    {
        return "\u{2066}".$text."\u{2069}";
    }

    public static function truncate(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_strlen($name) > self::NAME_LIMIT ? rtrim(mb_substr($name, 0, self::NAME_LIMIT - 1)).'…' : $name;
    }

    /**
     * OPERATIONAL-INTELLIGENCE-P1-001 — the same plain wording, for a caller that is not the mail.
     *
     * @return array{headline: string, text: string, numbers: string, action: string, link: string}
     */
    public static function plain(string $type, array $ctx, bool $ar, string $platform = ''): array
    {
        return self::say($type, $ctx, $ar, $platform);
    }

    /** @return array{headline: string, text: string, numbers: string, action: string, link: string} */
    private static function say(string $type, array $ctx, bool $ar, string $platform): array
    {
        $who = $platform !== '' ? $platform : ($ar ? 'المنصة' : 'the platform');
        $num = static fn ($v, int $d = 2): string => number_format((float) $v, $d);

        switch ($type) {
            case 'budget_risk':
                if (isset($ctx['consumed'], $ctx['limit'])) {
                    $cur = (string) ($ctx['currency'] ?? '');

                    return [
                        'headline' => $ar ? 'اقترب الإنفاق من الحد الذي وضعتَه' : 'Spend is close to the limit you set',
                        'text' => $ar ? 'تجاوز الاستهلاك عتبة التنبيه في حد الإنفاق الداخلي.' : 'Consumption passed an alert threshold of the internal spend limit.',
                        'numbers' => trim(($ar ? 'المستهلك ' : 'Consumed ').$num($ctx['consumed']).' '.$cur.' / '.$num($ctx['limit']).' '.$cur
                            .(isset($ctx['threshold']) ? ($ar ? ' (العتبة ' : ' (threshold ').(int) $ctx['threshold'].'%)' : '')),
                        'action' => $ar ? 'القرار لك: عدّل الحد أو راجع الإنفاق. النظام لا يوقف الإعلانات تلقائيًا.' : 'Your call: adjust the limit or review the spend. The system does not pause ads on its own.',
                        'link' => 'analytics',
                    ];
                }
                if (($ctx['basis_class'] ?? '') === 'unmeasurable') {
                    return [
                        'headline' => $ar ? 'ميزانية لا يمكن مراقبتها' : 'A budget that cannot be watched',
                        'text' => $ar ? 'عملة الميزانية تختلف عن عملة الإنفاق ولا يوجد سعر صرف، فلا نقارن بينهما.' : 'The budget is in a different currency from the spend and there is no exchange rate, so they are not compared.',
                        'numbers' => '',
                        'action' => $ar ? 'أضف سعر الصرف أو وحّد العملة.' : 'Add the exchange rate or use one currency.',
                        'link' => 'analytics',
                    ];
                }

                return [
                    'headline' => $ar ? "ميزانية {$who} قاربت النفاد" : "The {$who} budget is nearly used up",
                    'text' => $ar ? 'استهلكت الحملة نسبة كبيرة من ميزانيتها.' : 'The campaign has used a large share of its budget.',
                    'numbers' => isset($ctx['spend'], $ctx['budget']) ? ($ar ? 'المصروف ' : 'Spent ').$num($ctx['spend']).' / '.$num($ctx['budget'])
                        .(isset($ctx['ratio']) ? ' ('.$num((float) $ctx['ratio'] * 100, 0).'%)' : '') : '',
                    'action' => $ar ? 'قرار اليوم: أبقِ الميزانية أو عدّلها أو أوقف ما لا يؤدي.' : 'Decide today: keep the budget, adjust it, or pause what is not delivering.',
                    'link' => 'campaign',
                ];
            case 'cpa_increase':
            case 'cpl_increase':
                $key = $type === 'cpl_increase' ? 'cpl' : 'cpa';
                $label = PlainFindings::metricLabel($key, $ar);

                return [
                    'headline' => $ar ? "{$label} ارتفعت على {$who}" : ucfirst($label)." rose on {$who}",
                    'text' => $ar ? 'الحملة تدفع أكثر مقابل كل نتيجة مقارنة بالفترة السابقة.' : 'The campaign pays more for each result than in the previous period.',
                    'numbers' => self::beforeNow($ctx, $key, $ar),
                    'action' => $ar ? 'راجع المحتوى والجمهور، وابدأ بالأضعف أداءً.' : 'Review content and audience, starting with the weakest.',
                    'link' => 'campaign',
                ];
            case 'roas_drop':
                return [
                    'headline' => $ar ? "العائد على الإنفاق تراجع على {$who}" : "Return on ad spend fell on {$who}",
                    'text' => $ar ? 'كل ريال إعلان يعيد قيمة أقل من الفترة السابقة.' : 'Each unit of ad spend returns less than in the previous period.',
                    'numbers' => self::beforeNow($ctx, 'roas', $ar),
                    'action' => $ar ? 'راجع الإعلانات الأضعف وقرّر إيقافها أو تجديدها.' : 'Review the weakest ads and decide to pause or refresh them.',
                    'link' => 'campaign',
                ];
            case 'no_results':
                return [
                    'headline' => $ar ? "إنفاق على {$who} بلا نتائج" : "Spend on {$who} with no results",
                    'text' => $ar ? 'صُرف مبلغ دون أي نتيجة في الأيام الأخيرة.' : 'Money was spent with no result in the last days.',
                    'numbers' => isset($ctx['spend']) ? ($ar ? 'الإنفاق ' : 'Spent ').$num($ctx['spend']).' · '.($ar ? 'النتائج 0 خلال ' : '0 results in ').(int) ($ctx['days'] ?? 0).($ar ? ' أيام' : ' days') : '',
                    'action' => $ar ? 'تحقق من تتبع التحويلات ثم من المحتوى، وأوقف الحملة إن لزم.' : 'Check conversion tracking, then the content, and pause if needed.',
                    'link' => 'campaign',
                ];
            case 'sync_failure':
            case 'sync_outage':
                return [
                    'headline' => $platform !== '' ? ($ar ? "توقفت مزامنة بيانات {$platform}" : "{$platform} data sync has stopped") : ($ar ? 'توقفت مزامنة البيانات' : 'Data sync has stopped'),
                    'text' => $ar ? 'آخر محاولة فشلت، فأرقام التقارير قد تكون ناقصة.' : 'The last attempt failed, so report figures may be incomplete.',
                    'numbers' => isset($ctx['last_sync_at']) && $ctx['last_sync_at'] ? ($ar ? 'آخر أرقام بتاريخ ' : 'Last figures dated ').self::ltr(substr((string) $ctx['last_sync_at'], 0, 10)) : '',
                    'action' => $ar ? 'أعد ربط الحساب حتى تكتمل الأرقام.' : 'Reconnect the account so the figures are complete.',
                    'link' => 'integrations',
                ];
            case 'measurement_outage':
                return [
                    'headline' => $ar ? 'توقفت مزامنة بيانات الموقع' : 'Website data sync has stopped',
                    'text' => $ar ? 'لن تظهر زيارات الموقع ومبيعاته حديثة في التقارير.' : 'Website visits and sales will not be up to date in reports.',
                    'numbers' => '',
                    'action' => $ar ? 'افتح صفحة الربط وأعد ربط الموقع.' : 'Open the connections page and reconnect the website.',
                    'link' => 'integrations',
                ];
            case 'token_expiry':
                return [
                    'headline' => isset($ctx['expires_at']) ? ($ar ? "ينتهي ربط {$who} قريبًا" : "The {$who} connection expires soon") : ($ar ? "ربط {$who} مرفوض ومتوقف" : "The {$who} connection was refused and has stopped"),
                    'text' => $ar ? 'بعد الانتهاء تتوقف البيانات عن الوصول.' : 'After that, data stops arriving.',
                    'numbers' => isset($ctx['expires_at']) ? ($ar ? 'ينتهي في ' : 'Expires on ').self::ltr(substr((string) $ctx['expires_at'], 0, 10)) : '',
                    'action' => $ar ? 'جدّد الربط قبل انقطاع البيانات.' : 'Renew the connection before the data stops.',
                    'link' => 'integrations',
                ];
            case 'metric_anomaly':
                return [
                    'headline' => $ar ? "يوم غير معتاد على {$who}" : "An unusual day on {$who}",
                    'text' => $ar ? 'تغيّر مؤشر بشكل حاد عن خطه المعتاد.' : 'A metric moved sharply away from its usual level.',
                    'numbers' => self::anomaly($ctx, $ar),
                    'action' => $ar ? 'افتح الحملة وتحقق من الميزانية والمحتوى في ذلك اليوم.' : 'Open the campaign and check the budget and content for that day.',
                    'link' => 'campaign',
                ];
        }

        // A rule type this composer does not know: a generic plain headline, never the stored English.
        return [
            'headline' => $ar ? 'تنبيه جديد يحتاج مراجعتك' : 'A new alert needs your review',
            'text' => $ar ? 'افتح التنبيه لترى التفاصيل.' : 'Open the alert for the details.',
            'numbers' => '',
            'action' => $ar ? 'افتح التنبيه واتخذ قرارًا.' : 'Open the alert and decide.',
            'link' => 'alerts',
        ];
    }

    /** @return array{headline: string, text: string, numbers: string, action: string, link: string} */
    private static function recovered(bool $ar, string $platform): array
    {
        return [
            'headline' => $ar ? 'عادت الحالة إلى طبيعتها' : 'Back to normal',
            'text' => $ar ? 'لم تعد الحالة التي نبّهناك إليها قائمة، وأُغلق التنبيه.' : 'The condition we warned you about is gone, and the alert is closed.',
            'numbers' => '',
            'action' => $ar ? 'لا يلزمك أي إجراء.' : 'No action needed.',
            'link' => 'alerts',
        ];
    }

    private static function beforeNow(array $ctx, string $key, bool $ar): string
    {
        $now = $ctx[$key.'_current'] ?? null;
        $was = $ctx[$key.'_previous'] ?? null;
        if (! is_numeric($now) || ! is_numeric($was)) {
            return '';
        }
        $fmt = static fn ($v): string => $key === 'roas' ? number_format((float) $v, 2).'×' : number_format((float) $v, 2);
        $days = (int) ($ctx['window_days'] ?? 0);
        $thr = isset($ctx['threshold_pct']) ? (float) $ctx['threshold_pct'] : null;

        return ($ar ? 'قبل ' : 'Before ').$fmt($was).' ← '.($ar ? 'الآن ' : 'Now ').$fmt($now)
            .($days > 0 ? ($ar ? " (آخر {$days} أيام)" : " (last {$days} days)") : '')
            .($thr !== null ? ($ar ? ' · حد التنبيه '.rtrim(rtrim(number_format($thr, 1), '0'), '.').'%' : ' · alert at '.rtrim(rtrim(number_format($thr, 1), '0'), '.').'%') : '');
    }

    private static function anomaly(array $ctx, bool $ar): string
    {
        $p = (array) (($ctx['points'] ?? [])[0] ?? []);
        if (! isset($p['value'], $p['baseline'])) {
            return '';
        }
        $label = PlainFindings::metricLabel((string) ($p['metric'] ?? ''), $ar);

        return $label.': '.number_format((float) $p['baseline'], 2).' ← '.number_format((float) $p['value'], 2).(isset($p['date']) ? ' ('.self::ltr((string) $p['date']).')' : '');
    }

    private static function platformOf(array $ctx, ?UnifiedCampaign $campaign, bool $ar): string
    {
        $locale = $ar ? 'ar' : 'en';
        if (isset($ctx['provider']) && is_string($ctx['provider']) && $ctx['provider'] !== '') {
            return AdPlatforms::name($ctx['provider'], $locale);
        }
        $platforms = $campaign !== null ? array_values(array_filter((array) $campaign->platforms, 'is_string')) : [];

        return implode($ar ? '، ' : ', ', array_map(static fn (string $p): string => AdPlatforms::name($p, $locale), $platforms));
    }

    private static function objectiveOf(string $objective, bool $ar): string
    {
        $case = CampaignObjective::tryFrom($objective);

        return $case === null ? '' : $case->labels()[$ar ? 'ar' : 'en'];
    }
}
