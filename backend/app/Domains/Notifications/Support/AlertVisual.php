<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * DIGESTS-CADENCE-BUDGETS-001 — the approved digest grammar, for EVERY alert and notification.
 *
 * The Owner approved the digest templates as the standard for alerts and notifications too: a status chip and a coloured
 * edge, the problem DRAWN, one short plain line, the next steps in order with arrows, and one button to the exact screen.
 * This class turns any alert item (the alert engine's, the findings sweep's, a bell notification's) into that shape, so the
 * mail templates and the bell read one structure and none of them falls back to a paragraph.
 *
 * It draws only what the item already knows. A finding with figures gets bars from those figures ({@see AlertMailItem::viz()});
 * one without figures gets the STAGES it is about — connected → syncing ✕ → figures — never an invented number.
 */
final class AlertVisual
{
    /** Alert severity → the digest's four states (`DigestStatus`). */
    public const STATE = ['critical' => 'bad', 'error' => 'bad', 'warning' => 'watch', 'positive' => 'ok', 'success' => 'ok', 'info' => 'none'];

    /** The longest «one short line» a card carries; a longer text is cut at its first sentence, then at this length. */
    public const LINE_LIMIT = 110;

    /** @return array{state: string, glyph: string, label: string} */
    public static function chip(string $severity, bool $ar): array
    {
        $state = self::STATE[$severity] ?? 'none';

        return [
            'state' => $state,
            'glyph' => DigestStatus::GLYPH[$state],
            'label' => match ($state) {
                'bad' => $ar ? 'عاجل' : 'Urgent',
                'watch' => $ar ? 'انتبه' : 'Watch',
                'ok' => $ar ? 'خبر زين' : 'Good news',
                default => $ar ? 'للعلم' : 'For your info',
            },
        ];
    }

    /**
     * The next steps, in order: «افتح التتبع ← الوسم ← أصلحه» becomes three steps, a plain sentence stays one.
     *
     * @return list<string>
     */
    public static function steps(string $action): array
    {
        $action = trim($action);
        if ($action === '') {
            return [];
        }
        $parts = preg_split('/\s*(?:←|→)\s*|,\s+then\s+|\s+then\s+/u', $action) ?: [$action];
        // Multibyte-safe: `trim()` with «،» in its list cuts the bytes of Arabic letters that share its lead byte.
        $parts = array_values(array_filter(array_map(static fn (string $p): string => (string) preg_replace('/^[\s.،:]+|[\s.،:]+$/u', '', $p), $parts), static fn (string $p): bool => $p !== ''));

        return array_slice($parts, 0, 4);
    }

    /** ONE short line: the first sentence, cut to {@see LINE_LIMIT}. No paragraph ever reaches a card. */
    public static function line(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(.+?[.!؟?])(\s|$)/u', $text, $m) === 1) {
            $text = $m[1];
        }

        return Str::limit($text, self::LINE_LIMIT, '…');
    }

    /**
     * The problem drawn when the item carries no figure to draw: the stages it is about, the broken one marked.
     *
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    public static function stages(string $rule, array $ctx, string $severity, bool $ar): array
    {
        $bad = (self::STATE[$severity] ?? 'none') === 'bad' ? 'bad' : 'watch';
        $s = static fn (string $a, string $e, string $state): array => ['label' => $ar ? $a : $e, 'state' => $state];

        $steps = match (true) {
            in_array($rule, ['sync_failure', 'sync_outage', 'sync_failed', 'stale_data', 'data_gap'], true) => [$s('الربط', 'Connection', 'ok'), $s('المزامنة', 'Sync', $bad), $s('الأرقام', 'Figures', 'watch')],
            $rule === 'measurement_outage' => [$s('الموقع', 'Website', 'ok'), $s('المزامنة', 'Sync', $bad), $s('التقارير', 'Reports', 'watch')],
            in_array($rule, ['token_expiry', 'token_expiring'], true) => [$s('الربط', 'Connection', $bad), $s('المزامنة', 'Sync', 'watch'), $s('الأرقام', 'Figures', 'none')],
            in_array($rule, ['tracking_health', 'ga4_tracking_loss', 'ga4_unmatched_spend', 'ga4_attribution_gap'], true) => [$s('الإعلان', 'Ad', 'ok'), $s('الزيارة', 'Visit', $bad), $s('القياس', 'Measurement', 'watch')],
            $rule === 'report_failed' => [$s('الجدولة', 'Scheduled', 'ok'), $s('التوليد', 'Generated', $bad), $s('الإرسال', 'Delivered', 'none')],
            $rule === 'report_ready' => [$s('الجدولة', 'Scheduled', 'ok'), $s('التوليد', 'Generated', 'ok'), $s('جاهز', 'Ready', 'ok')],
            $rule === 'launch_advance' => [
                ['label' => (string) ($ctx['from'] ?? ($ar ? 'المرحلة الحالية' : 'Current stage')), 'state' => 'ok'],
                ['label' => (string) ($ctx['to'] ?? ($ar ? 'المرحلة الأقوى' : 'Stronger stage')), 'state' => 'watch'],
            ],
            in_array($rule, ['security', 'sla_warning'], true) => [$s('الدخول', 'Sign-in', $bad), $s('تأكد', 'Check', 'watch'), $s('آمن', 'Secure', 'none')],
            $rule === 'recovered' => [$s('التنبيه', 'Alert', 'watch'), $s('انحلّ', 'Resolved', 'ok')],
            $rule === 'sample_alert' => [$s('التنبيه', 'Alert', 'ok'), $s('البريد', 'Email', 'ok'), $s('وصلك', 'Delivered', 'ok')],
            in_array($rule, ['internal_spend_limit', 'budget_pace', 'budget_risk'], true) => [$s('الميزانية', 'Budget', 'ok'), $s('الإنفاق', 'Spend', $bad), $s('الحد', 'Limit', 'watch')],
            $rule === 'reallocation' => [$s('منصة أغلى', 'Costlier platform', 'watch'), $s('منصة أرخص', 'Cheaper platform', 'ok')],
            $rule === 'frequency_saturation' => [$s('الجمهور', 'Audience', 'ok'), $s('التكرار', 'Frequency', $bad), $s('المحتوى', 'Content', 'watch')],
            in_array($rule, ['request', 'approval', 'message'], true) => [$s('وصل', 'Received', 'ok'), $s('بانتظارك', 'Waiting for you', 'watch')],
            $rule === 'billing' => [$s('الفاتورة', 'Invoice', 'ok'), $s('الدفع', 'Payment', $severity === 'positive' ? 'ok' : 'watch')],
            default => [$s('ما حدث', 'What happened', self::STATE[$severity] ?? 'none'), $s('قرارك', 'Your decision', 'none')],
        };

        return ['type' => 'steps', 'steps' => $steps];
    }

    /** @return array<string,mixed> a count drawn as blocks: one per item, up to ten */
    public static function count(int $n, string $label, string $state): array
    {
        return ['type' => 'count', 'n' => $n, 'blocks' => max(1, min(10, $n)), 'more' => $n > 10, 'label' => $label, 'state' => $state];
    }

    /**
     * One item, in the approved grammar: chip, drawn problem, one line, ordered steps, one button.
     *
     * @param  array<string,mixed>  $item  an alert card (`AlertMailItem::build()`, a findings item, or an operational message)
     * @return array<string,mixed>
     */
    public static function present(array $item, bool $ar): array
    {
        $severity = (string) ($item['severity'] ?? 'info');
        $rule = (string) ($item['rule'] ?? '');
        $ctx = (array) ($item['ctx'] ?? []);
        $viz = $item['viz'] ?? null;
        if (empty($viz) && $rule !== '') {
            $viz = AlertMailItem::viz($rule, $ctx, $ar);
        }
        if (empty($viz) && str_starts_with($rule, 'lead_') && is_numeric($ctx['count'] ?? null)) {
            $viz = self::count((int) $ctx['count'], $ar ? 'مهتم' : 'leads', 'watch');
        }
        if (empty($viz)) {
            $viz = self::stages($rule, $ctx, $severity, $ar);
        }

        return array_merge($item, [
            'chip' => self::chip($severity, $ar),
            'viz' => $viz,
            // ALERT-VISUAL-EVERY-CARD-001 — the ring's timeline when the card has no figure to draw: opened → now / resolved.
            'timeline' => self::timeline($item, $ctx, $ar),
            'line' => self::line((string) ($item['detail'] ?? '')),
            'steps' => self::steps((string) ($item['action'] ?? '')),
        ]);
    }

    /**
     * ALERT-VISUAL-EVERY-CARD-001 — the two ends of a card's timeline, dated: when it opened (the alert's window, or its first
     * trigger) and when it ended (resolved) or «today». Null when the card carries no date at all.
     *
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $ctx
     * @return array{from: string, to: string}|null
     */
    public static function timeline(array $item, array $ctx, bool $ar): ?array
    {
        $date = static function (mixed $v): ?string {
            if (! is_string($v) || $v === '') {
                return null;
            }
            try {
                return Carbon::parse(str_replace('recovered:', '', $v))->setTimezone('Asia/Riyadh')->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };
        $opened = $date($ctx['opened_at'] ?? null) ?? $date($ctx['window_from'] ?? null) ?? $date($ctx['last_day'] ?? null);
        $resolved = $date($ctx['recovered_at'] ?? null);
        $now = $date((string) ($item['triggered_at'] ?? '')) ?? Carbon::now('Asia/Riyadh')->toDateString();
        if ($opened === null && $resolved === null) {
            return null;
        }

        return [
            'from' => ($ar ? 'بدأ ' : 'Opened ').AlertEventText::ltr($opened ?? $now),
            'to' => $resolved !== null ? ($ar ? 'انحلّ ' : 'Resolved ').AlertEventText::ltr($resolved) : ($ar ? 'اليوم ' : 'Today ').AlertEventText::ltr($now),
        ];
    }
}
