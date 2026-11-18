<?php

declare(strict_types=1);

namespace App\Domains\Agents\Support;

/**
 * AGENTS-ASSISTANT-001 — the cards, the sources and the proposals of one assistant turn, built by CODE from the tool results.
 *
 * The model writes the words; the cards beside them are cut from what the tools actually returned, so a chart or a table in the
 * chat can never carry a figure the tools did not produce, and «المصدر» names exactly which data and which period were read.
 * Only aggregates and the ids the screen needs are kept — never ad copy, never contact details.
 */
final class TurnDigest
{
    /** @var list<array<string, mixed>> */
    private array $sources = [];

    /** @var array<string, array<string, mixed>> one card per tool, the latest call wins */
    private array $cards = [];

    /** @var list<string> */
    private array $proposals = [];

    /** @var list<string> */
    private array $drafts = [];

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    public function add(string $tool, array $input, array $result): void
    {
        $window = $this->window($result);
        $this->sources[] = array_filter(['tool' => $tool, 'from' => $window['from'] ?? null, 'to' => $window['to'] ?? null]);

        match ($tool) {
            'list_campaigns' => $this->campaigns($result, $window),
            'compare_targets' => $this->targets($result, $window),
            'get_history' => $this->series($result),
            'list_creatives' => $this->creatives($result, $window),
            'propose_change' => isset($result['proposal_id']) ? $this->proposals[] = (string) $result['proposal_id'] : null,
            'draft_launch_plan' => isset($result['plan_id']) ? $this->drafts[] = 'launch_plan:'.$result['plan_id'] : null,
            'draft_automation_rule' => isset($result['rule_id']) ? $this->drafts[] = 'rule:'.$result['rule_id'] : null,
            default => null,
        };
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }

    /** @return array{sources: list<array<string, mixed>>, cards: list<array<string, mixed>>, proposals: list<string>, drafts: list<string>} */
    public function meta(): array
    {
        // The same tool read twice for the same period is one source.
        $sources = array_values(array_unique(array_map('json_encode', $this->sources)));

        return [
            'sources' => array_map(static fn (string|false $s): array => (array) json_decode((string) $s, true), $sources),
            'cards' => array_values($this->cards),
            'proposals' => array_values(array_unique($this->proposals)),
            'drafts' => array_values(array_unique($this->drafts)),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{from?: string, to?: string}
     */
    private function window(array $result): array
    {
        if (isset($result['window']) && is_array($result['window'])) {
            return array_filter(['from' => $result['window']['from'] ?? null, 'to' => $result['window']['to'] ?? null]);
        }

        return array_filter(['from' => $result['from'] ?? null, 'to' => $result['to'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $window
     */
    private function campaigns(array $result, array $window): void
    {
        $rows = collect((array) ($result['campaigns'] ?? []))
            ->sortByDesc(static fn (array $c): float => (float) ($c['spend'] ?? 0))
            ->take(6)
            ->map(static fn (array $c): array => [
                'id' => $c['campaign_id'] ?? null,
                'name' => isset($c['campaign_name']) ? mb_substr(DataMinimizer::text((string) $c['campaign_name']), 0, 80) : null,
                'objective' => $c['objective'] ?? null,
                'spend' => self::num($c['spend'] ?? null),
                'results' => self::num($c['conversions'] ?? null),
                'cpa' => self::num($c['cpa'] ?? null),
                // ROAS is a sales measure: carried only for a sales objective.
                'roas' => ($c['objective'] ?? null) === 'sales' ? self::num($c['roas'] ?? null) : null,
            ])->values()->all();

        $this->cards['list_campaigns'] = ['type' => 'campaigns', 'window' => $window, 'currency' => $result['currency'] ?? null, 'rows' => $rows,
            'count' => count((array) ($result['campaigns'] ?? []))];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $window
     */
    private function targets(array $result, array $window): void
    {
        $counts = ['on_target' => 0, 'off_target' => 0, 'not_judged' => 0, 'no_figure' => 0];
        foreach ((array) ($result['verdicts'] ?? []) as $v) {
            $k = (string) ($v['verdict'] ?? '');
            if (isset($counts[$k])) {
                $counts[$k]++;
            }
        }
        $this->cards['compare_targets'] = ['type' => 'targets', 'window' => $window, 'rules' => (int) ($result['rules'] ?? 0), 'counts' => $counts];
    }

    /** @param  array<string, mixed>  $result */
    private function series(array $result): void
    {
        $points = array_map(static fn (array $d): array => [
            'date' => $d['date'] ?? null, 'spend' => self::num($d['spend'] ?? null), 'results' => self::num($d['conversions'] ?? null),
        ], array_slice((array) ($result['days'] ?? []), -90));

        $this->cards['get_history'] = ['type' => 'series', 'campaign_id' => $result['campaign_id'] ?? null,
            'window' => array_filter(['from' => $result['from'] ?? null, 'to' => $result['to'] ?? null]), 'points' => $points];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $window
     */
    private function creatives(array $result, array $window): void
    {
        $rows = array_map(static fn (array $c): array => [
            'id' => $c['id'] ?? null,
            'name' => isset($c['name']) ? mb_substr(DataMinimizer::text((string) $c['name']), 0, 80) : null,
            'spend' => self::num($c['spend'] ?? null), 'ctr' => self::num($c['ctr'] ?? null),
            'fatigue' => is_array($c['fatigue'] ?? null) ? ($c['fatigue']['status'] ?? null) : null,
        ], array_slice((array) ($result['creatives'] ?? []), 0, 6));

        $this->cards['list_creatives'] = ['type' => 'creatives', 'window' => $window, 'rows' => $rows];
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 4) : null;
    }
}
