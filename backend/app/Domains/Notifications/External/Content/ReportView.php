<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

use App\Domains\Notifications\External\Design\DesignTokens as T;
use App\Domains\Notifications\External\Unsubscribe\UnsubscribeLinks;
use App\Domains\Notifications\Support\DigestStatus as S;
use Illuminate\Support\Carbon;

/**
 * Turns one context + the project's figures into the email's view model (spec §11 order, §8 identity, §12 subject and
 * footprint, §13 disclaimer). Pure: no query, no I/O, so the same input always yields the same message — which is what
 * lets the pre-send «mismatch with screen» check recompute and compare.
 *
 * VISUAL FIRST (Q2): every block is a figure, a chip or a bar. The words left are labels of one or two words, one short
 * privacy line, and the small print. Every state is the calm vocabulary of the existing digests' components (`DigestStatus`:
 * ok / watch / info / none — `bad` is NEVER used on an external message) so the layout is the one the owner approved.
 */
final class ReportView
{
    public const SUBJECT_MAX = 90;

    /** The block order per role (the same components, a different emphasis). Events other than the periodic digest narrow it. */
    private const ORDER = [
        'finance' => ['head', 'privacy', 'status', 'spend_total', 'spend_card', 'trend', 'platforms'],
        'project_manager' => ['head', 'privacy', 'status', 'strip', 'kpis', 'spend_card', 'top', 'chips'],
        'admin' => ['head', 'privacy', 'status', 'tiles', 'best_worst', 'trend', 'platforms', 'spend_card', 'top', 'chips', 'ideas'],
        'client' => ['head', 'privacy', 'status', 'platforms', 'spend_card', 'chips', 'ideas'],
        'default' => ['head', 'privacy', 'status', 'kpis', 'trend', 'platforms', 'spend_card', 'top', 'chips', 'ideas'],
    ];

    private const EVENT_ORDER = [
        'digest.month_start' => ['head', 'privacy', 'status', 'spend_card'],
        'continuity.planning' => ['head', 'privacy', 'status', 'spend_card', 'trend'],
        'budget.renewed' => ['head', 'privacy', 'status', 'spend_card'],
        'performance.improved' => ['head', 'privacy', 'status', 'chips', 'kpis', 'trend'],
        'target.reached' => ['head', 'privacy', 'status', 'chips', 'kpis'],
        'record.reached' => ['head', 'privacy', 'status', 'chips', 'kpis'],
        'campaign.launched' => ['head', 'privacy', 'status', 'strip', 'top'],
    ];

    /**
     * @param  array<string, mixed>  $data  DigestDataSource::project()
     * @return array<string, mixed>
     */
    public static function build(ExternalContext $c, array $data, bool $footprintRemoved, bool $dataUpdating = false): array
    {
        $ar = $c->locale === 'ar';
        $money = static fn (?float $v): string => self::money($v, $c->currency, $c->locale);
        $moneyShort = static fn (?float $v): string => self::moneyShort($v, $c->currency, $c->locale);
        $num = static fn (?float $v, int $d = 0): string => $v === null ? '—' : number_format($v, $d, '.', ',');
        $pct = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 0, '.', ',').'%';
        $change = static fn (?float $now, ?float $before): ?float => $now === null || $before === null || $before == 0.0 ? null : ($now - $before) / abs($before);
        $metricLabel = static fn (?string $key): string => $key !== null && __('notifications_external.metric.'.$key, [], $c->locale) !== 'notifications_external.metric.'.$key
            ? (string) __('notifications_external.metric.'.$key, [], $c->locale) : $c->t('metric.result');
        /** «▲ 20%» / «▼ 5%» with the state it reads in: good → ok, adverse → watch (amber, never red), neutral → none. @return array{text: string, tone: string}|null */
        $delta = static function (?float $v, ?string $good): ?array {
            if ($v === null) {
                return null;
            }
            $glyph = $v > 0.005 ? '▲' : ($v < -0.005 ? '▼' : '–');
            $tone = $good === null || abs($v) < 0.005 ? 'none' : ((($v > 0) === ($good === 'up')) ? 'ok' : 'watch');

            return ['text' => $glyph.' '.number_format(abs($v) * 100, 0).'%', 'tone' => $tone];
        };

        $objectives = array_values(array_filter((array) ($data['objectives'] ?? []), static fn (array $o): bool => ($o['spend'] ?? null) !== null || ($o['result'] ?? null) !== null));
        usort($objectives, static fn (array $a, array $b): int => ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0));

        // ── Figures per objective (never a cost per result across objectives), as the digests' tiles ──
        $figure = static fn (string $key, string $label, string $value, ?array $d): array => [
            'key' => $key, 'label' => $label, 'value' => $value, 'missing' => $value === '—', 'colour' => S::INK[$d['tone'] ?? 'none'], 'change' => $d['text'] ?? '',
        ];
        $groups = [];
        foreach ($objectives as $o) {
            $cards = [];
            if ($o['result'] !== null) {
                $cards[] = $figure('result', $metricLabel($o['result_key']), self::short($o['result']), $delta($change($o['result'], $o['previous_result']), 'up'));
            }
            if ($o['cost_per_result'] !== null && $o['objective'] !== 'awareness') {
                $cards[] = $figure('cpr', $c->t('metric.cost_per_result'), $moneyShort($o['cost_per_result']), $delta($change($o['cost_per_result'], $o['previous_cost_per_result']), 'down'));
            }
            if (($o['roas'] ?? null) !== null) {
                $cards[] = $figure('roas', $c->t('metric.roas'), $num($o['roas'], 2).'x', null);
            }
            $cards[] = $figure('spend', $c->t('metric.spend'), $moneyShort($o['spend']), null);
            $groups[] = ['objective' => (string) $o['objective'], 'label' => $c->t('objective.'.$o['objective']), 'campaigns' => '', 'state' => 'ok', 'kpis' => $cards, 'platforms' => [], 'funnel' => []];
        }

        // ── Lead figure: the top objective's result, else the spend ──
        $lead = $objectives[0] ?? null;
        $budget = (array) ($data['budget'] ?? ['has_budget' => false]);
        $showBudget = $c->showBudget && ($budget['has_budget'] ?? false) === true;
        // Finance leads with the money; every other role with the top objective's result (falling back to the money).
        $byResult = $lead !== null && $lead['result'] !== null && $c->roleKey !== 'finance';
        $leadLabel = $byResult ? $metricLabel($lead['result_key']) : $c->t('metric.spend');
        $leadValue = $byResult ? $num($lead['result']) : $money($data['spend'] ?? null);
        $leadShort = $byResult ? self::short($lead['result']) : $moneyShort($data['spend'] ?? null);
        $leadChange = $byResult ? $change($lead['result'], $lead['previous_result']) : $change($data['spend'] ?? null, $data['previous_spend'] ?? null);
        $leadDelta = $delta($leadChange, $byResult ? 'up' : null);
        $highlight = $leadValue.' '.$leadLabel;

        // ── Platforms (donut + legend rows) ──
        $platforms = array_values(array_filter((array) ($data['platforms'] ?? []), static fn (array $p): bool => ($p['spend'] ?? 0) > 0));
        $platformTotal = array_sum(array_map(static fn (array $p): float => (float) $p['spend'], $platforms));
        $maxPlatform = $platforms === [] ? 0.0 : max(array_map(static fn (array $p): float => (float) $p['spend'], $platforms));
        $platformRows = array_map(static function (array $p) use ($money, $moneyShort, $pct, $change, $delta, $platformTotal, $maxPlatform): array {
            $d = $delta($change($p['spend'], $p['previous_spend'] ?? null), null);

            return [
                'provider' => (string) $p['provider'], 'label' => self::providerLabel((string) $p['provider']), 'spend' => $money($p['spend']), 'short' => $moneyShort($p['spend']), 'raw' => (float) $p['spend'],
                'share' => $platformTotal > 0 ? $pct($p['spend'] / $platformTotal) : '—', 'bar' => $maxPlatform > 0 ? max(4, (int) round($p['spend'] / $maxPlatform * 100)) : 0,
                'previous' => $money($p['previous_spend'] ?? null), 'delta' => $d,
            ];
        }, $platforms);
        $series = array_values((array) ($data['series'] ?? []));
        $seriesTotal = array_sum(array_map(static fn (array $p): float => (float) ($p['spend'] ?? 0), $series));
        $max = $series === [] ? null : max(array_map(static fn (array $p): float => (float) ($p['spend'] ?? 0), $series));
        $bars = array_map(static fn (array $p): array => [
            'h' => $max !== null && $max > 0 ? (int) round((float) ($p['spend'] ?? 0) / $max * 40) : 0, 'missing' => ! is_numeric($p['spend'] ?? null),
            'label' => (string) (int) substr((string) $p['date'], 8, 2),
        ], $series);

        // ── Calm status (never red): on_track / opportunity / needs_follow_up ──
        $continuity = (int) ($c->settings['external']['continuity_days'] ?? 5);
        $improve = (float) ($c->settings['external']['improvement_pct'] ?? 10) / 100;
        $statusKey = 'on_track';
        foreach ($objectives as $o) {
            $d = $change($o['cost_per_result'], $o['previous_cost_per_result']);
            if ($d !== null && $d >= $improve && $o['objective'] !== 'awareness') {
                $statusKey = 'opportunity';
            }
        }
        if ($showBudget && isset($budget['cover_days']) && (int) $budget['cover_days'] <= $continuity) {
            $statusKey = 'needs_follow_up';
        }
        $statusTone = ['on_track' => 'ok', 'opportunity' => 'info', 'needs_follow_up' => 'watch'][$statusKey];
        if ($lead === null && $platformRows === []) {
            $statusTone = 'none';
        }

        // ── Budget card: the weekly spend summary's own block (limit · spent · remaining, bar, runway) in the calm palette ──
        $runOut = ! empty($budget['run_out_date']) ? self::dayLabel((string) $budget['run_out_date'], $c->locale) : null;
        $used = $budget['used_ratio'] ?? null;
        $remaining = $budget['remaining'] ?? null;
        $budgetCard = null;
        if ($showBudget && ! $dataUpdating) {
            $over = is_numeric($remaining) && (float) $remaining < 0;
            $near = isset($budget['cover_days']) && (int) $budget['cover_days'] <= $continuity;
            $state = $over ? 'over' : ($near ? 'near' : 'ok');
            $remainingPct = is_numeric($used) ? max(0, 100 - (int) round((float) $used * 100)) : null;
            $budgetCard = [
                'name' => $c->projectName,
                'state' => $state,
                'state_label' => $c->t('status.'.($state === 'ok' ? 'on_track' : 'needs_follow_up')),
                'spend_text' => $money($data['spend'] ?? null),
                'cap_text' => $money($budget['approved'] ?? null),
                'consumed_text' => $money($budget['spent'] ?? null),
                'remaining_text' => $money($remaining).($remainingPct !== null ? ' · '.$remainingPct.'%' : ''),
                'bar' => is_numeric($used) ? max(2, min(100, (int) round((float) $used * 100))) : 0,
                'days_text' => $runOut !== null ? $c->t('budget.lasts_until', ['date' => $runOut]) : '',
                'equivalent' => ($budget['original_currency'] ?? $c->currency) !== $c->currency ? $c->t('budget.equivalent', ['amount' => self::money($budget['approved_original'] ?? null, (string) $budget['original_currency'], $c->locale)]) : null,
                'ring' => is_numeric($used) ? ['ratio' => (float) $used, 'label' => $pct((float) $used)] : null,
            ];
        } elseif ($c->showBudget && ($budget['has_budget'] ?? false) !== true && ! $dataUpdating) {
            $budgetCard = ['name' => $c->projectName, 'state' => 'none', 'state_label' => $c->t('budget.none'), 'spend_text' => $money($data['spend'] ?? null), 'cap_text' => '', 'consumed_text' => '', 'remaining_text' => '', 'bar' => 0, 'days_text' => '', 'equivalent' => null, 'ring' => null];
        }
        $spendTotal = [
            'spend' => $money($data['spend'] ?? null),
            'remaining' => is_numeric($remaining) ? $money((float) $remaining) : '—',
            'left_label' => $c->t('budget.left'),
            'tot' => ['spend_left_out' => 0, 'cap_left_out' => 0, 'near' => 0],
        ];

        // ── Top campaigns (org roles) or top platforms (client role: no campaign names on a client message) ──
        $topMax = 0.0;
        foreach ((array) ($data['campaigns'] ?? []) as $r) {
            $topMax = max($topMax, (float) ($r['spend'] ?? 0));
        }
        $top = $c->isClient()
            ? ['title' => $c->t('section.top_platforms'), 'rows' => array_slice(array_map(static fn (array $p): array => ['rank' => null, 'provider' => $p['provider'], 'label' => $p['label'], 'value' => $p['short'], 'bar' => $p['bar'], 'delta' => $p['delta']], $platformRows), 0, 3)]
            : ['title' => $c->t('section.top_campaigns'), 'rows' => array_map(static fn (array $r, int $i): array => [
                'rank' => $i + 1, 'provider' => (string) ($r['provider'] ?? ''), 'label' => (string) $r['name'], 'value' => $moneyShort($r['spend'] ?? null),
                'bar' => $topMax > 0 ? max(4, (int) round((float) ($r['spend'] ?? 0) / $topMax * 100)) : 0, 'delta' => null,
            ], (array) ($data['campaigns'] ?? []), array_keys((array) ($data['campaigns'] ?? [])))];

        // ── Achievements as chips: «الطلبات +20%», never a sentence ──
        $chips = [];
        foreach ($objectives as $o) {
            $d = $change($o['result'], $o['previous_result']);
            if ($d !== null && $d > 0.005) {
                $chips[] = ['tone' => 'ok', 'text' => $c->t('achievement', ['label' => $metricLabel($o['result_key']), 'pct' => $pct($d)])];
            }
        }
        foreach ((array) ($data['achievements'] ?? []) as $a) {
            if (isset($a['text']) && is_string($a['text'])) {
                $chips[] = ['tone' => 'ok', 'text' => self::words($a['text'], 5)];
            }
        }

        // Approved suggestions only for the client role; each is its short title (3–5 words), never the body.
        $ideas = array_values(array_map(static fn (array $s): string => self::words(trim((string) explode(' — ', (string) $s['text'])[0]), 5), array_filter((array) ($data['suggestions'] ?? []), static fn (array $s): bool => ! $c->isClient() || ($s['approved'] ?? false) === true)));

        // ── Best / weakest platform (admin): the share leader, and the biggest mover down ──
        $bestWorst = [];
        if (count($platformRows) >= 2) {
            $bestWorst[] = ['tone' => 'ok', 'title' => $c->t('section.best'), 'name' => $platformRows[0]['label'], 'value' => $platformRows[0]['short'], 'sub' => $platformRows[0]['share']];
            $movers = array_values(array_filter($platformRows, static fn (array $r): bool => ($r['delta']['text'] ?? '') !== '' && str_starts_with((string) $r['delta']['text'], '▼')));
            if ($movers !== []) {
                $bestWorst[] = ['tone' => 'watch', 'title' => $c->t('section.follow'), 'name' => $movers[0]['label'], 'value' => $movers[0]['short'], 'sub' => (string) $movers[0]['delta']['text']];
            }
        }

        $want = static fn (string $type): bool => in_array($type, $c->digestTypes, true);
        $isDigest = str_starts_with($c->eventKey, 'digest.');
        $eventSuffix = str_replace('.', '_', $c->eventKey);
        $title = $c->eventKey === 'digest.periodic' && $c->roleKey === 'finance' ? $c->t('title.digest_periodic_finance') : $c->t('title.'.$eventSuffix, ['term' => $c->term()]);

        $roleKey = $c->isClient() ? 'client' : (isset(self::ORDER[$c->roleKey]) ? $c->roleKey : 'default');
        $order = self::EVENT_ORDER[$c->eventKey] ?? self::ORDER[$roleKey];
        // A digest type the recipient did not choose drops its block (as before).
        $dropped = [];
        if ($isDigest && $c->eventKey === 'digest.periodic') {
            foreach (['performance' => ['kpis', 'tiles'], 'spend' => ['trend', 'platforms', 'spend_total'], 'budgets' => ['spend_card'], 'top_campaigns' => ['top'], 'achievements' => ['chips'], 'growth_suggestions' => ['ideas']] as $type => $blocks) {
                if (! $want($type)) {
                    $dropped = array_merge($dropped, $blocks);
                }
            }
        }
        $has = [
            'kpis' => $groups !== [], 'tiles' => $groups !== [], 'strip' => $platformRows !== [], 'best_worst' => $bestWorst !== [],
            'trend' => count($series) >= 2, 'platforms' => $platformRows !== [], 'spend_total' => $showBudget || $platformTotal > 0, 'spend_card' => $budgetCard !== null,
            'top' => $top['rows'] !== [], 'chips' => $chips !== [], 'ideas' => $ideas !== [], 'head' => true, 'privacy' => true, 'status' => true,
        ];
        $order = $dataUpdating ? ['head', 'privacy', 'updating'] : array_values(array_filter($order, static fn (string $b): bool => ($has[$b] ?? false) && ! in_array($b, $dropped, true)));

        $tiles = [];
        if ($groups !== []) {
            $tiles[] = $figure('spend', $c->t('metric.spend'), $moneyShort($data['spend'] ?? null), $delta($change($data['spend'] ?? null, $data['previous_spend'] ?? null), null));
            foreach (array_slice($groups[0]['kpis'], 0, 2) as $k) {
                if ($k['key'] !== 'spend') {
                    $tiles[] = $k;
                }
            }
            $tiles = array_slice($tiles, 0, 3);
        }

        $unsubscribe = UnsubscribeLinks::url(array_filter([
            't' => $c->tenantId,
            'r' => $c->copyKind === null ? $c->recipientId : (isset($c->payload['_copy_recipient_id']) ? (string) $c->payload['_copy_recipient_id'] : null),
            'c' => $c->copyKind !== null && ! isset($c->payload['_copy_recipient_id']) ? (string) $c->delivery->recipient->address : null,
            's' => $c->subscriptionId, 'p' => $c->projectId, 'e' => $c->eventKey,
        ]), $c->locale);
        $disclaimer = $c->settings['external']['disclaimer'][$c->locale] ?? null;
        $disclaimer = is_string($disclaimer) && trim($disclaimer) !== '' ? $disclaimer : $c->t('disclaimer_default', ['workspace' => $c->workspaceName]);
        $invitation = Footprint::settings()['invitation_'.$c->locale] ?? null;
        $signature = $c->settings['sender']['signature'] ?? null;
        $fxLines = array_map(static fn (array $f): string => $f['withheld'] || $f['rate'] === null ? $f['from'].' → '.$f['to'].' —'
            : (string) __('notifications_external.fx_rate', ['from' => $f['from'], 'rate' => rtrim(rtrim(number_format((float) $f['rate'], 6, '.', ''), '0'), '.'), 'to' => $f['to'], 'date' => (string) $f['rate_date']], $c->locale), (array) ($data['fx'] ?? []));
        $period = self::periodChip($c->from, $c->to, $c->locale);

        $model = [
            'locale' => $c->locale,
            'dir' => $ar ? 'rtl' : 'ltr',
            'align' => $ar ? 'right' : 'left',
            'theme' => $c->theme,
            'workspace' => $c->workspaceName,
            'initials' => mb_strtoupper(mb_substr(trim($c->workspaceName) !== '' ? trim($c->workspaceName) : 'C', 0, 1)),
            'project' => $c->projectName,
            'managed_by' => $c->t('managed_by', ['workspace' => $c->workspaceName]),
            'badge' => $c->t('badge', ['term' => $c->term(), 'project' => $c->projectName]),
            'title' => $title,
            'period' => $period,
            'period_range' => $c->from.' – '.$c->to,
            'role' => $c->roleKey,
            'greeting' => $c->salutation !== null ? $c->t('greeting_named', ['name' => $c->salutation]) : $c->t('greeting'),
            'privacy' => $c->t('privacy', ['role' => $c->roleLabel, 'term' => $c->term(), 'project' => $c->projectName, 'workspace' => $c->workspaceName]),
            'lock_alt' => $c->t('chart_alt.lock'),
            'copy_band' => $c->copyKind === null ? null : $c->t('copy_band'),
            'test_banner' => str_starts_with($c->delivery->reference, 'TEST') ? $c->t('test_banner') : null,
            'updating' => $dataUpdating ? $c->t('updating') : null,
            'order' => $order,
            'status' => ['tone' => $statusTone, 'key' => $statusKey, 'label' => $c->t('status.'.$statusKey)],
            'verdict' => $dataUpdating ? null : [
                'state' => $statusTone, 'name' => $c->projectName, 'sub' => $lead !== null ? $c->t('objective.'.$lead['objective']) : $c->term(),
                'kpi' => ['value' => $leadShort, 'label' => $leadLabel, 'change' => $leadDelta['text'] ?? '', 'colour' => S::INK[$leadDelta['tone'] ?? 'none'], 'missing' => $leadShort === '—'],
                'full' => $leadValue,
            ],
            'kpi_groups' => $dataUpdating ? [] : $groups,
            'tiles' => $dataUpdating ? [] : $tiles,
            'trend' => $dataUpdating || count($series) < 2 ? null : [
                'title' => $c->t('section.trend'),
                'total' => $moneyShort($seriesTotal),
                'delta' => $delta($change($data['spend'] ?? null, $data['previous_spend'] ?? null), null),
                'alt' => $c->t('chart_alt.trend', ['from' => $c->from, 'to' => $c->to, 'total' => $money($seriesTotal), 'max' => $money($max)]),
                'bars' => $bars,
                'rows' => array_map(static fn (array $p): array => ['date' => (string) $p['date'], 'spend' => $money($p['spend'] ?? null)], $series),
                'legend' => ['current' => $c->t('metric.current'), 'previous' => $c->t('metric.previous')],
            ],
            'platforms' => $dataUpdating || $platformRows === [] ? null : [
                'title' => $c->t('section.platforms'),
                'total' => $moneyShort($platformTotal),
                'full_total' => $money($platformTotal),
                'alt' => $c->t('chart_alt.donut', ['items' => implode('، ', array_map(static fn (array $p): string => $p['label'].' '.$p['spend'].' ('.$p['share'].')', $platformRows)), 'total' => $money($platformTotal)]),
                'rows' => $platformRows,
            ],
            'strip' => array_map(static fn (array $p): array => ['provider' => $p['provider'], 'short' => $p['label'], 'state' => 'ok'], $dataUpdating ? [] : $platformRows),
            'best_worst' => $dataUpdating ? [] : $bestWorst,
            'spend_total' => $spendTotal,
            'spend_card' => $budgetCard,
            'top' => $dataUpdating || $top['rows'] === [] ? null : $top,
            'chips' => $dataUpdating ? [] : array_slice($chips, 0, 4),
            'ideas' => $dataUpdating ? [] : array_slice($ideas, 0, 3),
            'ideas_title' => $c->t('section.suggestions'),
            'chips_title' => $c->t('section.achievements'),
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
            'labels' => ['previous' => $c->t('metric.previous'), 'spend' => $c->t('metric.spend'), 'date' => $c->t('metric.share')],
            'preheader' => implode(' · ', array_filter([$period, $statusKey !== '' ? $c->t('status.'.$statusKey) : null, $leadValue.' '.$leadLabel])),
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

        return number_format($amount, 2, '.', ',').' '.self::mark($currency, $locale);
    }

    /** The compact form for tiles, rows and chart centres (the abbreviation table, docs/notifications-v2/Q2-notes.md): K from 10,000, M from 1,000,000. */
    public static function moneyShort(?float $amount, string $currency, string $locale): string
    {
        return $amount === null ? '—' : self::short($amount).' '.self::mark($currency, $locale);
    }

    public static function short(?float $v): string
    {
        if ($v === null) {
            return '—';
        }
        $a = abs($v);
        $trim = static fn (float $x): string => rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.');

        return match (true) {
            $a >= 1_000_000 => $trim($v / 1_000_000).'M',
            $a >= 10_000 => $trim($v / 1_000).'K',
            $a >= 100 => number_format($v, 0, '.', ','),
            default => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'),
        };
    }

    private static function mark(string $currency, string $locale): string
    {
        $code = strtoupper($currency);

        return match (true) {
            $code === 'SAR' && $locale === 'ar' => 'ر.س',
            $code === 'USD' => '$',
            $code === 'EUR' => '€',
            default => $code,
        };
    }

    /** «1–14 نوفمبر» / «28 أكتوبر – 3 نوفمبر» / «14 نوفمبر»: Latin digits, month names, the year only across years. */
    public static function periodChip(string $from, string $to, string $locale): string
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        $months = (array) __('notifications_external.months', [], $locale);
        $mo = static fn (Carbon $d): string => (string) ($months[$d->month - 1] ?? $d->format('M'));
        $en = $locale === 'en';
        $year = $f->year !== $t->year ? ' '.$t->year : '';
        $dayOf = static fn (Carbon $d): string => $en ? $mo($d).' '.$d->day : $d->day.' '.$mo($d);

        if ($f->isSameDay($t)) {
            return $dayOf($t).$year;
        }
        if ($f->year === $t->year && $f->month === $t->month) {
            return $en ? $mo($f).' '.$f->day.'–'.$t->day : $f->day.'–'.$t->day.' '.$mo($t);
        }

        return $dayOf($f).' – '.$dayOf($t).$year;
    }

    public static function dayLabel(string $date, string $locale): string
    {
        return self::periodChip($date, $date, $locale);
    }

    public static function periodLabel(ExternalContext $c): string
    {
        return $c->from === $c->to ? $c->from : $c->from.' – '.$c->to;
    }

    /** The first N words, no ellipsis games: a chip is a label, not an excerpt. */
    public static function words(string $text, int $n): string
    {
        $w = preg_split('/\s+/u', trim($text)) ?: [];

        return implode(' ', array_slice($w, 0, $n));
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
