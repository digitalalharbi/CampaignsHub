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
                ? ($ar ? 'افتح الربط' : 'Open the connections page')
                : ($campaign !== null ? ($ar ? 'افتح الحملة' : 'Open the campaign') : ($ar ? 'افتح التنبيه' : 'Open the alert')),
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
                        'headline' => $ar ? 'قرّبت توصل للحد اللي حطيته' : 'Close to the limit you set',
                        'text' => $ar ? 'وصل الإنفاق لحد التنبيه.' : 'Spend passed an alert level of the spend limit.',
                        'numbers' => trim(($ar ? 'الإنفاق ' : 'Spent ').$num($ctx['consumed']).' '.$cur.' / '.$num($ctx['limit']).' '.$cur
                            .(isset($ctx['threshold']) ? ($ar ? ' (الحد ' : ' (limit ').(int) $ctx['threshold'].'%)' : '')),
                        'action' => $ar ? 'هذا الحد للمتابعة والتنبيه. للإيقاف التلقائي فعّل قاعدة من الأتمتة.' : 'This limit is for follow-up and alerts. To pause automatically, turn on an automation rule.',
                        'link' => 'analytics',
                    ];
                }
                if (($ctx['basis_class'] ?? '') === 'unmeasurable') {
                    return [
                        'headline' => $ar ? 'ميزانية ما نقدر نتابعها' : 'A budget that cannot be watched',
                        'text' => $ar ? 'عملة الميزانية غير عملة الصرف وما في سعر تحويل، فما نقارن.' : 'The budget is in a different currency from the spend and there is no exchange rate, so they are not compared.',
                        'numbers' => '',
                        'action' => $ar ? 'أضف سعر التحويل أو وحّد العملة.' : 'Add the exchange rate or use one currency.',
                        'link' => 'analytics',
                    ];
                }

                return [
                    'headline' => $ar ? "ميزانية {$who} قربت تخلص" : "The {$who} budget is nearly used up",
                    'text' => $ar ? 'الحملة صرفت أغلب ميزانيتها.' : 'The campaign has used a large share of its budget.',
                    'numbers' => isset($ctx['spend'], $ctx['budget']) ? ($ar ? 'الإنفاق ' : 'Spent ').$num($ctx['spend']).' / '.$num($ctx['budget'])
                        .((float) ($ctx['budget'] ?? 0) > 0 ? ' ('.self::ltr($num((float) $ctx['spend'] / (float) $ctx['budget'] * 100, 0).'%').')' : '') : '',
                    'action' => $ar ? 'أبقِ الميزانية ← أو عدّلها ← أو أوقف الأقل أداءً.' : 'Decide today: keep the budget, adjust it, or pause what is not delivering.',
                    'link' => 'campaign',
                ];
            case 'cpa_increase':
            case 'cpl_increase':
                $key = $type === 'cpl_increase' ? 'cpl' : 'cpa';
                $label = PlainFindings::metricLabel($key, $ar);

                return [
                    'headline' => $ar ? "{$label} زادت على {$who}" : ucfirst($label)." rose on {$who}",
                    'text' => $ar ? 'كل نتيجة صارت أغلى من قبل.' : 'The campaign pays more for each result than in the previous period.',
                    'numbers' => self::beforeNow($ctx, $key, $ar),
                    'action' => $ar ? 'راجع الجمهور ← ثم المحتوى ← ابدأ بالأقل أداءً.' : 'Review content and audience, starting with the weakest.',
                    'link' => 'campaign',
                ];
            case 'roas_drop':
                return [
                    'headline' => $ar ? "العائد نزل على {$who}" : "Return on ad spend fell on {$who}",
                    'text' => $ar ? 'كل ريال إعلان يرجّع أقل من قبل.' : 'Each unit of ad spend returns less than in the previous period.',
                    'numbers' => self::beforeNow($ctx, 'roas', $ar),
                    'action' => $ar ? 'افتح الأقل أداءً ← جدّده أو أوقفه.' : 'Review the weakest ads and decide to pause or refresh them.',
                    'link' => 'campaign',
                ];
            case 'no_results':
                return [
                    'headline' => $ar ? "{$who} بدون نتائج" : "Spend on {$who} with no results",
                    'text' => $ar ? 'انصرف مبلغ وما جات أي نتيجة.' : 'Money was spent with no result in the last days.',
                    // OBJECTIVE-RESULT-001 — names the result that is zero (orders, visits …), «النتائج» only for an older event.
                    'numbers' => isset($ctx['spend']) ? ($ar ? 'الإنفاق ' : 'Spent ').$num($ctx['spend']).' · '.($ar ? ((string) ($ctx['result_label']['ar'] ?? 'النتائج')).' 0 ' : '0 '.strtolower((string) ($ctx['result_label']['en'] ?? 'results')).' ')
                        .(($w = self::window($ctx, $ar)) !== '' ? '('.$w.')' : ($ar ? 'خلال ' : 'in ').(int) ($ctx['days'] ?? 0).($ar ? ' أيام' : ' days')) : '',
                    'action' => $ar ? 'تحقق من تتبع التحويلات ثم المحتوى، وأوقف الحملة إن لزم.' : 'Check conversion tracking, then the content, and pause if needed.',
                    'link' => 'campaign',
                ];
            case 'sync_failure':
            case 'sync_outage':
                return [
                    'headline' => $platform !== '' ? ($ar ? "مزامنة {$platform} متوقفة" : "{$platform} data sync has stopped") : ($ar ? 'المزامنة متوقفة' : 'Data sync has stopped'),
                    'text' => $ar ? 'آخر محاولة فشلت، فالأرقام ممكن تكون ناقصة.' : 'The last attempt failed, so report figures may be incomplete.',
                    'numbers' => isset($ctx['last_sync_at']) && $ctx['last_sync_at'] ? ($ar ? 'آخر أرقام بتاريخ ' : 'Last figures dated ').self::ltr(substr((string) $ctx['last_sync_at'], 0, 10)) : '',
                    'action' => $ar ? 'أعد ربط الحساب.' : 'Reconnect the account so the figures are complete.',
                    'link' => 'integrations',
                ];
            case 'measurement_outage':
                return [
                    'headline' => $ar ? 'مزامنة الموقع متوقفة' : 'Website data sync has stopped',
                    'text' => $ar ? 'زيارات ومبيعات الموقع ما راح تظهر محدّثة.' : 'Website visits and sales will not be up to date in reports.',
                    'numbers' => '',
                    'action' => $ar ? 'افتح الربط ← أعد ربط الموقع.' : 'Open the connections page and reconnect the website.',
                    'link' => 'integrations',
                ];
            case 'token_expiry':
                return [
                    'headline' => isset($ctx['expires_at']) ? ($ar ? "ربط {$who} قارب ينتهي" : "The {$who} connection expires soon") : ($ar ? "ربط {$who} متوقف" : "The {$who} connection was refused and has stopped"),
                    'text' => isset($ctx['expires_at']) ? ($ar ? 'بعدها ما توصل بيانات.' : 'After that, data stops arriving.') : ($ar ? 'البيانات ما توصل لين تعيد الربط.' : 'Data is stopped until you reconnect.'),
                    'numbers' => isset($ctx['expires_at']) ? ($ar ? 'ينتهي يوم ' : 'Expires on ').self::ltr(substr((string) $ctx['expires_at'], 0, 10)) : '',
                    'action' => $ar ? 'جدّد الربط قبل ما تنقطع البيانات.' : 'Renew the connection before the data stops.',
                    'link' => 'integrations',
                ];
            case 'metric_anomaly':
                return [
                    'headline' => $ar ? "يوم مو معتاد على {$who}" : "An unusual day on {$who}",
                    'text' => $ar ? 'مؤشر تحرك بقوة عن مستواه المعتاد.' : 'A metric moved sharply away from its usual level.',
                    'numbers' => self::anomaly($ctx, $ar),
                    'action' => $ar ? 'افتح الحملة ← شوف الميزانية والمحتوى في ذلك اليوم.' : 'Open the campaign and check the budget and content for that day.',
                    'link' => 'campaign',
                ];
        }

        // DIGESTS-VISUAL-V2-001 — website, lead, tracking, launch, performance, security and the sample have their own words.
        $own = AlertRuleCopy::say($type, $ctx, $ar);
        if ($own !== null) {
            return $own;
        }
        // An event that carries its own Arabic title and message (the rule wrote them) is said with those, never with a generic line.
        if ($ar && is_string($ctx['title_ar'] ?? null) && $ctx['title_ar'] !== '') {
            return ['headline' => $ctx['title_ar'], 'text' => (string) ($ctx['message_ar'] ?? ''), 'numbers' => '', 'action' => 'افتحه وقرّر.', 'link' => 'alerts'];
        }

        // A rule type this composer does not know: a generic plain headline, never the stored English.
        return [
            'headline' => $ar ? 'فيه تنبيه جديد' : 'A new alert needs your review',
            'text' => $ar ? 'افتحه وشوف التفاصيل.' : 'Open the alert for the details.',
            'numbers' => '',
            'action' => $ar ? 'افتحه وقرّر.' : 'Open the alert and decide.',
            'link' => 'alerts',
        ];
    }

    /** @return array{headline: string, text: string, numbers: string, action: string, link: string} */
    private static function recovered(bool $ar, string $platform): array
    {
        return [
            'headline' => $ar ? 'رجعت الأمور طبيعية' : 'Back to normal',
            'text' => $ar ? 'المشكلة انتهت وأُغلق التنبيه.' : 'The condition we warned you about is gone, and the alert is closed.',
            'numbers' => '',
            'action' => $ar ? 'ما يحتاج منك شيء.' : 'No action needed.',
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
            .(($w = self::window($ctx, $ar)) !== '' ? ' ('.$w.')' : ($days > 0 ? ($ar ? " (آخر {$days} أيام)" : " (last {$days} days)") : ''))
            .($thr !== null ? ($ar ? ' · حد التنبيه '.self::ltr(rtrim(rtrim(number_format($thr, 1), '0'), '.').'%') : ' · alert at '.rtrim(rtrim(number_format($thr, 1), '0'), '.').'%') : '');
    }

    /**
     * DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — the measured period stated with its dates («أمس الأربعاء 11 نوفمبر», «5–11 نوفمبر»),
     * when the event carries them; an event written before the window was recorded keeps its «آخر N أيام».
     */
    public static function window(array $ctx, bool $ar): string
    {
        $from = $ctx['window_from'] ?? null;
        $to = $ctx['window_to'] ?? null;
        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            return '';
        }

        return DigestSchedule::periodLabel($from === $to ? 'daily' : 'range', $from, $to, $ar);
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
