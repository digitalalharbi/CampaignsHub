<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

use App\Domains\Notifications\External\Unsubscribe\UnsubscribeLinks;
use Illuminate\Support\Carbon;

/**
 * Turns one context + the project's figures into the email's view model (spec §11 order, §8 identity, §12 subject and
 * footprint, §13 disclaimer). Pure: no query, no I/O, so the same input always yields the same message — which is what
 * lets the pre-send «mismatch with screen» check recompute and compare.
 */
final class ReportView
{
    public const SUBJECT_MAX = 90;

    /**
     * @param  array<string, mixed>  $data  DigestDataSource::project()
     * @return array<string, mixed>
     */
    public static function build(ExternalContext $c, array $data, bool $footprintRemoved, bool $dataUpdating = false): array
    {
        $money = static fn (?float $v): string => self::money($v, $c->currency, $c->locale);
        $num = static fn (?float $v, int $d = 0): string => $v === null ? '—' : number_format($v, $d, '.', ',');
        $pct = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 0, '.', ',').'%';
        $change = static fn (?float $now, ?float $before): ?float => $now === null || $before === null || $before == 0.0 ? null : ($now - $before) / abs($before);

        $objectives = array_values(array_filter((array) ($data['objectives'] ?? []), static fn (array $o): bool => ($o['spend'] ?? null) !== null || ($o['result'] ?? null) !== null));
        usort($objectives, static fn (array $a, array $b): int => ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0));
        $metricLabel = static fn (?string $key): string => $key !== null && __('notifications_external.metric.'.$key, [], $c->locale) !== 'notifications_external.metric.'.$key
            ? (string) __('notifications_external.metric.'.$key, [], $c->locale) : $c->t('metric.result');

        // ── KPIs per objective: never a cost per result across objectives ──
        $kpis = [];
        foreach ($objectives as $o) {
            $cards = [];
            if ($o['result'] !== null) {
                $cards[] = ['label' => $metricLabel($o['result_key']), 'value' => $num($o['result']), 'change' => $change($o['result'], $o['previous_result']), 'good_when' => 'up'];
            }
            if ($o['cost_per_result'] !== null && $o['objective'] !== 'awareness') {
                $cards[] = ['label' => $c->t('metric.cost_per_result'), 'value' => $money($o['cost_per_result']), 'change' => $change($o['cost_per_result'], $o['previous_cost_per_result']), 'good_when' => 'down'];
            }
            if (($o['roas'] ?? null) !== null) {
                $cards[] = ['label' => $c->t('metric.roas'), 'value' => $num($o['roas'], 2).'x', 'change' => null, 'good_when' => 'up'];
            }
            $cards[] = ['label' => $c->t('metric.spend'), 'value' => $money($o['spend']), 'change' => null, 'good_when' => null];
            $kpis[] = ['objective' => $c->t('objective.'.$o['objective']), 'key' => $o['objective'], 'cards' => $cards];
        }

        // ── Opening card: the top achievement, its big number, the budget ring, one data sentence ──
        $lead = $objectives[0] ?? null;
        $budget = (array) ($data['budget'] ?? ['has_budget' => false]);
        $showBudget = $c->showBudget && ($budget['has_budget'] ?? false) === true;
        $leadLabel = $lead !== null && $lead['result'] !== null ? $metricLabel($lead['result_key']) : $c->t('metric.spend');
        $leadValue = $lead !== null && $lead['result'] !== null ? $num($lead['result']) : $money($data['spend'] ?? null);
        $leadChange = $lead !== null && $lead['result'] !== null ? $change($lead['result'], $lead['previous_result']) : $change($data['spend'] ?? null, $data['previous_spend'] ?? null);
        $sentence = $leadChange !== null && $leadChange > 0.005
            ? $c->t('opening.sentence_up', ['label' => $leadLabel, 'pct' => $pct($leadChange)])
            : $c->t('opening.sentence_flat', ['label' => $leadLabel, 'value' => $leadValue]);
        $highlight = $leadValue.' '.$leadLabel;

        // ── Platforms (donut) and trend ──
        $platforms = array_values(array_filter((array) ($data['platforms'] ?? []), static fn (array $p): bool => ($p['spend'] ?? 0) > 0));
        $platformTotal = array_sum(array_map(static fn (array $p): float => (float) $p['spend'], $platforms));
        $platformRows = array_map(static fn (array $p): array => [
            'provider' => (string) $p['provider'], 'label' => self::providerLabel((string) $p['provider']), 'spend' => $money($p['spend']), 'raw' => (float) $p['spend'],
            'share' => $platformTotal > 0 ? $pct($p['spend'] / $platformTotal) : '—', 'previous' => $money($p['previous_spend'] ?? null),
            'change' => $change($p['spend'], $p['previous_spend'] ?? null),
        ], $platforms);
        $series = array_values((array) ($data['series'] ?? []));
        $seriesTotal = array_sum(array_map(static fn (array $p): float => (float) ($p['spend'] ?? 0), $series));
        $max = $series === [] ? null : max(array_map(static fn (array $p): float => (float) ($p['spend'] ?? 0), $series));

        // ── Status chip (calm vocabulary only) ──
        $continuity = (int) ($c->settings['external']['continuity_days'] ?? 5);
        $improve = (float) ($c->settings['external']['improvement_pct'] ?? 10) / 100;
        $status = 'on_track';
        foreach ($objectives as $o) {
            $d = $change($o['cost_per_result'], $o['previous_cost_per_result']);
            if ($d !== null && $d >= $improve && $o['objective'] !== 'awareness') {
                $status = 'opportunity';
            }
        }
        if ($showBudget && isset($budget['cover_days']) && (int) $budget['cover_days'] <= $continuity) {
            $status = 'needs_follow_up';
        }

        // ── Top campaigns (org roles) or top platforms (client role: no campaign names on a client message) ──
        $top = $c->isClient()
            ? ['title' => $c->t('section.top_platforms'), 'rows' => array_slice(array_map(static fn (array $p): array => ['name' => $p['label'], 'detail' => $p['spend'].' · '.$p['share']], $platformRows), 0, 3)]
            : ['title' => $c->t('section.top_campaigns'), 'rows' => array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'detail' => $c->t('objective.'.($r['objective'] === 'engagement' || $r['objective'] === 'video' ? 'awareness' : ($r['objective'] ?: 'unknown'))).' · '.$money($r['spend']).($r['result'] !== null ? ' · '.$num($r['result']).' '.$metricLabel($r['result_key']) : '')], (array) ($data['campaigns'] ?? []))];

        $achievements = [];
        foreach ($objectives as $o) {
            $d = $change($o['result'], $o['previous_result']);
            if ($d !== null && $d > 0.005) {
                $achievements[] = $c->t('events.performance_improved', ['label' => $metricLabel($o['result_key']), 'pct' => $pct($d)]);
            }
        }
        foreach ((array) ($data['achievements'] ?? []) as $a) {
            if (isset($a['text']) && is_string($a['text'])) {
                $achievements[] = $a['text'];
            }
        }

        // Approved suggestions only for the client role; the source returns approved ones only for everybody today.
        $suggestions = array_values(array_map(static fn (array $s): string => (string) $s['text'], array_filter((array) ($data['suggestions'] ?? []), static fn (array $s): bool => ! $c->isClient() || ($s['approved'] ?? false) === true)));

        $want = static fn (string $type): bool => in_array($type, $c->digestTypes, true);
        $isDigest = str_starts_with($c->eventKey, 'digest.');

        $fxLines = array_map(static fn (array $f): string => $f['withheld'] || $f['rate'] === null ? $f['from'].' → '.$f['to'].' —'
            : (string) __('notifications_external.fx_rate', ['from' => $f['from'], 'rate' => rtrim(rtrim(number_format((float) $f['rate'], 6, '.', ''), '0'), '.'), 'to' => $f['to'], 'date' => (string) $f['rate_date']], $c->locale), (array) ($data['fx'] ?? []));

        $unsubscribe = UnsubscribeLinks::url(array_filter([
            't' => $c->tenantId,
            'r' => $c->copyKind === null ? $c->recipientId : (isset($c->payload['_copy_recipient_id']) ? (string) $c->payload['_copy_recipient_id'] : null),
            'c' => $c->copyKind !== null && ! isset($c->payload['_copy_recipient_id']) ? (string) $c->delivery->recipient->address : null,
            's' => $c->subscriptionId, 'p' => $c->projectId, 'e' => $c->eventKey,
        ]), $c->locale);
        $disclaimer = $c->settings['external']['disclaimer'][$c->locale] ?? null;
        $platform = PlatformTexts::localised('disclaimer.default', $c->locale);
        $disclaimer = is_string($disclaimer) && trim($disclaimer) !== '' ? $disclaimer
            : ($platform !== null ? str_replace('{workspace}', $c->workspaceName, $platform) : $c->t('disclaimer_default', ['workspace' => $c->workspaceName]));
        $invitation = Footprint::settings()['invitation_'.$c->locale] ?? null;
        $signature = $c->settings['sender']['signature'] ?? null;

        $model = [
            'locale' => $c->locale,
            'dir' => $c->locale === 'ar' ? 'rtl' : 'ltr',
            'align' => $c->locale === 'ar' ? 'right' : 'left',
            'theme' => $c->theme,
            'workspace' => $c->workspaceName,
            'initials' => mb_strtoupper(mb_substr(trim($c->workspaceName) !== '' ? trim($c->workspaceName) : 'C', 0, 1)),
            'project' => $c->projectName,
            'badge' => $c->t('badge', ['term' => $c->term(), 'project' => $c->projectName]),
            'managed_by' => $c->t('managed_by', ['workspace' => $c->workspaceName]),
            'greeting' => $c->salutation !== null ? $c->t('greeting_named', ['name' => $c->salutation]) : $c->t('greeting'),
            'privacy' => $c->t('privacy', ['role' => $c->roleLabel, 'term' => $c->term(), 'project' => $c->projectName, 'workspace' => $c->workspaceName]),
            'period' => $c->t('period_line', ['from' => $c->from, 'to' => $c->to]),
            'copy_band' => $c->copyKind === null ? null : ['title' => $c->t('copy_band'), 'note' => $c->copyKind === 'bcc' ? $c->t('copy_band_bcc_note') : null],
            'updating' => $dataUpdating ? $c->t('updating') : null,
            'tone' => $c->t('tone.'.$c->tone()),
            'event_line' => $isDigest && $c->eventKey === 'digest.periodic' ? null : $c->t('events.'.str_replace('.', '_', $c->eventKey), [
                'project' => $c->projectName, 'date' => (string) ($c->payload['date'] ?? $budget['run_out_date'] ?? ''), 'label' => $leadLabel, 'pct' => $pct($leadChange),
            ]),
            'opening' => $dataUpdating ? null : [
                'label' => $lead !== null && $lead['result'] !== null ? $c->t('opening.result', ['label' => $leadLabel]) : $c->t('opening.spend'),
                'value' => $leadValue,
                'change' => $leadChange,
                'sentence' => $sentence,
                'ring' => $showBudget && $budget['used_ratio'] !== null ? ['ratio' => (float) $budget['used_ratio'], 'label' => $pct((float) $budget['used_ratio']).' '.$c->t('opening.budget_ring')] : null,
            ],
            'kpis' => $dataUpdating || (! $want('performance') && $isDigest) ? [] : $kpis,
            'trend' => $dataUpdating || (! $want('spend') && $isDigest) || count($series) < 2 ? null : [
                'title' => $c->t('section.trend'),
                'alt' => $c->t('chart_alt.trend', ['from' => $c->from, 'to' => $c->to, 'total' => $money($seriesTotal), 'max' => $money($max)]),
                'rows' => array_map(static fn (array $p): array => ['date' => (string) $p['date'], 'spend' => $money($p['spend'] ?? null)], $series),
            ],
            'platforms' => $dataUpdating || (! $want('spend') && $isDigest) || $platformRows === [] ? null : [
                'title' => $c->t('section.platforms'),
                'total' => $money($platformTotal),
                'alt' => $c->t('chart_alt.donut', ['items' => implode('، ', array_map(static fn (array $p): string => $p['label'].' '.$p['spend'].' ('.$p['share'].')', $platformRows)), 'total' => $money($platformTotal)]),
                'rows' => $platformRows,
            ],
            'project_card' => $dataUpdating ? null : [
                'title' => $c->t('section.project', ['term' => $c->term()]),
                'status' => $status,
                'status_label' => $c->t('status.'.$status),
                'budget' => ! $showBudget || ($isDigest && ! $want('budgets') && $c->eventKey === 'digest.periodic') ? null : [
                    'approved' => $money($budget['approved'] ?? null),
                    'spent' => $money($budget['spent'] ?? null),
                    'remaining' => $money($budget['remaining'] ?? null),
                    'pace' => $money($budget['pace_per_day'] ?? null),
                    'ratio' => $budget['used_ratio'] ?? null,
                    'equivalent' => ($budget['original_currency'] ?? $c->currency) !== $c->currency ? $c->t('budget.equivalent', ['amount' => self::money($budget['approved_original'] ?? null, (string) $budget['original_currency'], $c->locale)]) : null,
                    'lasts_until' => ! empty($budget['run_out_date']) ? $c->t('budget.lasts_until', ['date' => (string) $budget['run_out_date']]) : null,
                    'advisory' => $c->t('budget.advisory'),
                ],
                'no_budget' => $c->showBudget && ($budget['has_budget'] ?? false) !== true ? $c->t('budget.none') : null,
            ],
            'top' => $dataUpdating || (! $want('top_campaigns') && $isDigest) || $top['rows'] === [] ? null : $top,
            'achievements' => $dataUpdating || (! $want('achievements') && $isDigest) ? [] : array_slice($achievements, 0, 4),
            'suggestions' => $dataUpdating || (! $want('growth_suggestions') && $isDigest) ? [] : array_slice($suggestions, 0, 3),
            'suggestions_title' => $c->t('section.suggestions'),
            'achievements_title' => $c->t('section.achievements'),
            'kpis_title' => $c->t('section.kpis'),
            'signature' => is_string($signature) && trim($signature) !== '' ? trim($signature) : $c->t('signature_default', ['workspace' => $c->workspaceName]),
            'why' => $c->t('why', ['term' => $c->term(), 'project' => $c->projectName, 'workspace' => $c->workspaceName, 'date' => (string) ($c->subscribedSince ?? $c->createdAt->toDateString())]),
            'manage_url' => $unsubscribe,
            'manage_label' => $c->t('manage'),
            'contact' => is_string($c->settings['external']['contact_link'] ?? null) && $c->settings['external']['contact_link'] !== '' ? ['url' => (string) $c->settings['external']['contact_link'], 'label' => $c->t('contact')] : null,
            'footprint' => $footprintRemoved ? null : [
                'text' => $c->t('footprint'),
                'link' => Footprint::clickUrl($c->tenantId, $c->eventKey, $c->delivery->reference),
                'link_label' => $c->t('footprint_link'),
                'invitation' => $invitation,
            ],
            'disclaimer' => $disclaimer,
            'created' => $c->t('created_at', ['time' => $c->createdAt->copy()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i').' (GMT+3)']),
            'sources' => $c->t('sources', ['sources' => implode('، ', array_unique(array_map(static fn (array $p): string => $p['label'], $platformRows))) ?: '—']),
            'reference' => $c->t('reference', ['ref' => $c->delivery->reference]),
            'fx' => $fxLines === [] ? null : $c->t('fx', ['rates' => implode(' · ', $fxLines)]),
            'labels' => [
                'previous' => $c->t('metric.previous'), 'platform' => $c->t('metric.platform'), 'share' => $c->t('metric.share'), 'spend' => $c->t('metric.spend'), 'date' => $c->t('metric.date'),
                'approved' => $c->t('budget.approved'), 'spent' => $c->t('budget.spent'), 'remaining' => $c->t('budget.remaining'), 'pace' => $c->t('budget.pace'),
            ],
        ];

        $model['subject'] = self::subject($c, $highlight, $footprintRemoved);
        $model['highlight'] = $highlight;

        return $model;
    }

    /** `{project} — {workspace} | {custom} · CampaignsHub`, at most 90 characters; only the custom part is ever cut (spec §8, §12). */
    public static function subject(ExternalContext $c, string $highlight, bool $footprintRemoved): string
    {
        $custom = $c->subjectTemplate ?? ($c->settings['external']['subject_templates'][$c->eventKey] ?? null);
        $custom = is_string($custom) && trim($custom) !== '' ? trim($custom) : $c->t('subject.'.str_replace('.', '_', $c->eventKey), ['period' => self::periodLabel($c), 'highlight' => $highlight]);
        $custom = strtr($custom, [
            '{project}' => $c->projectName, '{workspace}' => $c->workspaceName, '{period}' => self::periodLabel($c), '{highlight}' => $highlight,
            '{recipient_role}' => $c->roleLabel, '{date}' => $c->createdAt->toDateString(),
        ]);
        $custom = trim((string) preg_replace('/\s+/u', ' ', $custom));

        $prefix = $c->projectName.' — '.$c->workspaceName.' | ';
        $suffix = $footprintRemoved ? '' : ' · CampaignsHub';
        $room = self::SUBJECT_MAX - mb_strlen($prefix) - mb_strlen($suffix);
        if (mb_strlen($custom) > $room) {
            $custom = $room > 1 ? rtrim(mb_substr($custom, 0, $room - 1)).'…' : '';
        }

        return $prefix.$custom.$suffix;
    }

    /** Latin digits, two decimals, the currency's mark beside every figure: «ر.س» only in Arabic, the ISO code otherwise. */
    public static function money(?float $amount, string $currency, string $locale): string
    {
        if ($amount === null) {
            return '—';
        }
        $code = strtoupper($currency);
        $mark = match (true) {
            $code === 'SAR' && $locale === 'ar' => 'ر.س',
            $code === 'USD' => '$',
            $code === 'EUR' => '€',
            default => $code,
        };

        return number_format($amount, 2, '.', ',').' '.$mark;
    }

    public static function periodLabel(ExternalContext $c): string
    {
        return $c->from === $c->to ? $c->from : $c->from.' – '.$c->to;
    }

    public static function providerLabel(string $provider): string
    {
        return match (strtolower($provider)) {
            'meta', 'facebook' => 'Meta', 'google', 'google_ads' => 'Google', 'tiktok' => 'TikTok', 'snapchat' => 'Snapchat',
            'x', 'twitter' => 'X', 'linkedin' => 'LinkedIn', 'openai_ads' => 'ChatGPT', default => ucfirst($provider),
        };
    }

    /** Date of a Carbon in Y-m-d — kept here so the view never formats dates itself. */
    public static function day(Carbon $at): string
    {
        return $at->toDateString();
    }
}
