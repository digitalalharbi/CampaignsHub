<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ClientReportView;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ReportTemplateEngine;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\HiddenMoney;

/**
 * REPORT-AUTO-COMPOSE-001 (Owner file 2026-11-03 §7.2) — sections and indicators picked by the campaign's objective and the data.
 *
 * ## Opt-in, and only ever a narrowing
 *
 * Nothing here runs until somebody picks it. It then writes the same SHOW/HIDE switches an operator would, so every manual control
 * works afterwards and there is no second composition model for a renderer to honour. It can only take away:
 *
 *  - a section the operator or the audience default has off stays off. Detailed tables and site measurement are decisions somebody
 *    makes about a client, and the rule that one switch never forces another applies to this one too;
 *  - a link's accounts, campaigns and platforms are not touched, and its figures are read through the link's own builder, so
 *    `BoundAccountVisibility` and the ceiling answer exactly as they do for the client;
 *  - an indicator the link hides is not offered. `ReportShare::selectsSpend()` makes choosing spend publish it, so a hidden money
 *    figure must not reach the list this writes.
 *
 * ## No data is not a hide
 *
 * A section with nothing to show is reported as such and left switched on, because the resolver already leaves it out while it is
 * empty. Storing a hide would keep it off a live link after the data arrives. Only the objective's own answer is stored.
 *
 * ## A snapshot is history
 *
 * Its stored document is not rewritten. The chosen indicators live in `config['auto_compose']` and `ReportSectionSurfaces::apply()`
 * presents them, so every surface shows one composition and reverting loses nothing.
 */
final class ReportAutoComposer
{
    public const DECISION_SHOW = 'show';

    public const DECISION_HIDE = 'hide';

    public const DECISION_UNAVAILABLE = 'unavailable';

    public const DECISION_OFF = 'off';

    public function __construct(
        private readonly ReportSectionSurfaces $surfaces,
        private readonly ReportSectionRegistry $registry,
        private readonly ReportTemplateEngine $template,
    ) {}

    /**
     * What automatic mode would do, without doing it.
     *
     * @return array<string, mixed>
     */
    public function propose(Report $report, ?ReportShare $share): array
    {
        $payload = $this->document($report, $share);
        $objective = $this->objective($report, $share, $payload);
        $resolved = $this->surfaces->resolve($report, $share, 'preview', $payload);

        $sections = [];
        foreach ($this->registry->all() as $section) {
            [$decision, $reason] = $this->decide($section->key, $resolved->reasonFor($section->key), $objective['value']);
            $sections[] = [
                'key' => $section->key,
                'title_ar' => $section->titleAr,
                'title_en' => $section->titleEn,
                'decision' => $decision,
                'reason' => $reason,
            ];
        }

        [$metrics, $leftOut] = $this->indicators($report, $share, $payload, $objective['value']);
        $mark = (array) ($this->stored($report, $share)['auto_compose'] ?? []);

        return [
            'level' => $share === null ? 'report' : 'link',
            'share_id' => $share === null ? null : (string) $share->getKey(),
            'objective' => $objective['value'],
            'objective_source' => $objective['source'],
            'availability_judged' => $payload !== null,
            'sections' => $sections,
            'metrics' => $metrics,
            'metrics_left_out' => $leftOut,
            // A live report's cards belong to each of its links; only a link's own or a stored document's list can be set here.
            'metrics_apply' => $share !== null || ! $this->isLive($report),
            'applied' => $mark !== [],
            'applied_at' => $mark['at'] ?? null,
        ];
    }

    /** @return array<string, mixed> the proposal as it stands after applying */
    public function apply(Report $report, ?ReportShare $share): array
    {
        $proposal = $this->propose($report, $share);
        $hide = array_values(array_map(
            static fn (array $row): string => $row['key'],
            array_filter($proposal['sections'], static fn (array $row): bool => $row['decision'] === self::DECISION_HIDE),
        ));
        $mark = [
            'mode' => 'auto',
            'objective' => $proposal['objective'],
            'at' => now()->toIso8601String(),
            'hidden' => $hide,
            'metrics' => $proposal['metrics_apply'] ? $proposal['metrics'] : [],
        ];

        if ($share !== null) {
            $this->applyToLink($report, $share, $hide, $proposal['metrics'], $mark);
        } else {
            $this->applyToReport($report, $hide, $mark);
        }

        return $this->propose($report->fresh() ?? $report, $share?->fresh());
    }

    /** @return array<string, mixed> the proposal as it stands after going back */
    public function revert(Report $report, ?ReportShare $share): array
    {
        if ($share !== null) {
            $settings = (array) ($share->settings ?? []);
            $before = (array) ($settings['auto_compose']['before'] ?? []);
            $scope = (array) ($share->scope ?? []);
            if (array_key_exists('overrides', $before)) {
                $settings['section_overrides'] = (array) $before['overrides'];
                if (array_key_exists('metrics', $before)) {
                    $scope['metrics'] = (array) $before['metrics'];
                } else {
                    unset($scope['metrics']);
                }
                $share->forceFill(['scope' => $scope])->save();
            }
            if (($before['had_overrides'] ?? true) === false) {
                unset($settings['section_overrides']);
            }
            unset($settings['auto_compose']);
            $share->settings = $settings;
            $share->save();
        } else {
            $config = (array) ($report->config ?? []);
            if (is_array($config['auto_compose']['before'] ?? null)) {
                $report->section_settings = SectionSettings::fromArray($config['auto_compose']['before'], $this->registry)->toArray();
            }
            unset($config['auto_compose']);
            $report->config = $config;
            $report->save();
        }

        return $this->propose($report->fresh() ?? $report, $share?->fresh());
    }

    /**
     * The indicators a stored document presents once somebody picked automatic mode — the link's own, then the report's. Null for
     * everything else, which is what keeps an existing report byte-identical.
     *
     * @return list<string>|null
     */
    public static function chosenMetrics(Report $report, ?ReportShare $share): ?array
    {
        if ($share !== null && $share->isLive()) {
            return null;
        }
        foreach ([$share === null ? [] : (array) ($share->settings ?? []), (array) ($report->config ?? [])] as $stored) {
            $metrics = array_values(array_filter((array) ($stored['auto_compose']['metrics'] ?? []), 'is_string'));
            if ($metrics !== []) {
                return $metrics;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string|null} */
    private function decide(string $key, ?string $reason, string $objective): array
    {
        return match (true) {
            $reason === ReportSectionResolver::DISABLED_BY_OPERATOR => [self::DECISION_OFF, 'turned_off'],
            $key === 'funnel' && ReportTemplateEngine::hasNoFunnel($objective) => [self::DECISION_HIDE, 'objective'],
            $reason === ReportSectionResolver::UNSUPPORTED => [self::DECISION_UNAVAILABLE, 'unsupported'],
            $reason === ReportSectionResolver::DATA_UNAVAILABLE => [self::DECISION_UNAVAILABLE, 'no_data'],
            default => [self::DECISION_SHOW, null],
        };
    }

    /**
     * The objective's indicators, less what this surface may not show and what nobody reported.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{0: list<string>, 1: list<array{key: string, reason: string}>}
     */
    private function indicators(Report $report, ?ReportShare $share, ?array $payload, string $objective): array
    {
        $hidden = $this->moneyNotShownHere($report, $share, $payload);
        $reported = (array) ($payload['reported'] ?? []);
        $figures = (array) ($payload['totals'] ?? $payload['kpis'] ?? []);

        $picked = [];
        $leftOut = [];
        foreach ($this->template->metricSet($objective) as $metric) {
            if (in_array($metric, $hidden, true)) {
                $leftOut[] = ['key' => $metric, 'reason' => $share !== null ? 'hidden_by_link' : 'hidden_by_report'];
            } elseif ($payload !== null && (($figures[$metric] ?? null) === null || ($reported[$metric] ?? true) === false)) {
                $leftOut[] = ['key' => $metric, 'reason' => 'not_reported'];
            } else {
                $picked[] = $metric;
            }
        }

        return [$picked, $leftOut];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<string>
     */
    private function moneyNotShownHere(Report $report, ?ReportShare $share, ?array $payload): array
    {
        if ($share !== null) {
            return HiddenMoney::keys((bool) $share->hide_spend, (bool) $share->hide_revenue);
        }
        if (($report->audience ?? 'client') === 'internal') {
            return [];
        }

        // A client's stored document: a money figure it does not already present is not this mode's to introduce.
        $shown = array_values(array_filter((array) ($payload['metric_set'] ?? []), 'is_string'));

        return array_values(array_diff(HiddenMoney::keys(true, true), $shown));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{value: string, source: string}
     */
    private function objective(Report $report, ?ReportShare $share, ?array $payload): array
    {
        $declared = trim((string) ($report->campaign_objective ?? ''));
        if ($declared !== '' && $declared !== 'custom') {
            return ['value' => $declared, 'source' => 'report'];
        }

        $scope = (array) ($share !== null ? ($share->scope ?? []) : ($report->scope ?? []));
        $lens = ReportObjectiveLens::fromScopeObjectives(array_values(array_filter((array) ($scope['objectives'] ?? []), 'is_string')));
        if ($lens !== null) {
            return ['value' => $lens->value(), 'source' => 'scope'];
        }

        $stored = is_string($payload['objective'] ?? null) ? trim($payload['objective']) : '';
        if ($stored !== '' && $stored !== 'custom') {
            return ['value' => $stored, 'source' => 'data'];
        }

        // What the money in this scope was declared for; several families have no single honest headline.
        $families = (array) ($payload['objective_analytics']['families'] ?? []);
        if (count($families) === 1 && is_string($families[0]['family'] ?? null)) {
            return ['value' => $families[0]['family'], 'source' => 'data'];
        }

        return ['value' => 'custom', 'source' => 'mixed'];
    }

    /**
     * The figures this surface would show, read through its own path. Null is «no figures to judge»: a live report before any link.
     *
     * @return array<string, mixed>|null
     */
    private function document(Report $report, ?ReportShare $share): ?array
    {
        if ($share !== null && $share->isLive()) {
            $live = app(LiveReportService::class);

            return app(ShareService::class)->sanitizeLive($live->build($share, [], ReportingCurrency::DEFAULT), $share);
        }

        $data = is_array($report->data) && $report->data !== [] ? $report->data : null;
        if ($data === null) {
            return null;
        }

        $document = app(ClientReportView::class)->document(
            $data,
            $share !== null ? ($report->audience === 'executive' ? 'executive' : 'client') : (string) ($report->audience ?? 'client'),
            $share !== null ? $share->formOr($report->form) : $report->form,
        );

        return $share !== null ? app(ShareService::class)->sanitize($document, $share) : $document;
    }

    /** @param list<string> $hide */
    private function applyToReport(Report $report, array $hide, array $mark): void
    {
        $settings = $report->sectionSettings()->toArray();
        $config = (array) ($report->config ?? []);
        // Going back returns to what was there before automatic mode was first picked, however many times it is re-picked.
        $mark['before'] = $config['auto_compose']['before'] ?? $settings;
        foreach ($hide as $key) {
            $settings['sections'][$key] = false;
        }
        $report->section_settings = SectionSettings::fromArray($settings, $this->registry)->toArray();
        $config['auto_compose'] = $mark;
        $report->config = $config;
        $report->save();
    }

    /**
     * @param  list<string>  $hide
     * @param  list<string>  $metrics
     */
    private function applyToLink(Report $report, ReportShare $share, array $hide, array $metrics, array $mark): void
    {
        $settings = (array) ($share->settings ?? []);
        $scope = (array) ($share->scope ?? []);
        $mark['before'] = $settings['auto_compose']['before'] ?? [
            'overrides' => array_values((array) ($settings['section_overrides'] ?? [])),
            'had_overrides' => array_key_exists('section_overrides', $settings),
        ] + (array_key_exists('metrics', $scope) ? ['metrics' => $scope['metrics']] : []);

        $settings['section_overrides'] = array_values(array_unique([...(array) ($settings['section_overrides'] ?? []), ...$hide]));
        $settings['auto_compose'] = $mark;
        // Nothing picked means the link keeps the cards it has: an empty list reads as «every card», which would widen it.
        if ($metrics !== [] && $share->isLive()) {
            $scope['metrics'] = $metrics;
        }
        $share->settings = $settings;
        $share->scope = $scope;
        $share->save();
    }

    /** @return array<string, mixed> */
    private function stored(Report $report, ?ReportShare $share): array
    {
        return (array) ($share !== null ? ($share->settings ?? []) : ($report->config ?? []));
    }

    private function isLive(Report $report): bool
    {
        return $report->type === 'live' || (bool) (($report->config ?? [])['live'] ?? false) || ! is_array($report->data) || $report->data === [];
    }
}
