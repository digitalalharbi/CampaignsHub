<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Support\ReportBreakdowns;
use Illuminate\Support\Facades\Log;

/**
 * REPORT-DRILLDOWN-001 — the platform and content drill-downs, whoever is asking.
 *
 * Two doors lead here: the client's public link, gated by its token, and the operator's session,
 * gated by `reports.view` on the project. Both answer from the SAME share and through this class, so
 * the operator inspecting a live link sees exactly what its recipient sees — the share's ceiling, its
 * hide flags, its sections and its breakdowns — and a rule added here cannot be forgotten by one door.
 *
 * Every answer passes three gates in order:
 *
 *   1. the breakdown is offered on this link ({@see ReportBreakdowns}), which already requires its
 *      parent section to be visible;
 *   2. the share's hide flags ({@see ShareService::sanitizeLive()});
 *   3. the campaign-entity guard ({@see ClientReportContentValidator::campaignEntities()}). The builders
 *      strip campaign keys already; this is the backstop that makes a regression a refusal rather
 *      than a disclosure. It fails CLOSED and logs, because a drill-down that 404s is a bug report and
 *      one that names a campaign is a client reading it.
 *
 * Null means «404»: out of scope, switched off and non-existent are one answer on purpose.
 */
final class LiveDrilldown
{
    public function __construct(
        private readonly LiveReportService $live,
        private readonly ShareService $shares,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function platform(ReportShare $share, string $provider, array $query): ?array
    {
        if (! $share->isLive() || ! ReportBreakdowns::allows($share, ReportBreakdowns::PLATFORM)) {
            return null;
        }

        $payload = $this->live->platform($share, $provider, $query);
        if ($payload === null) {
            return null;
        }

        $payload = $this->shares->sanitizeLive($payload, $share);
        $payload['objectives'] = array_map(
            fn (array $block): array => ['metrics' => $this->shares->sanitizeLive(['totals' => $block['metrics']], $share)['totals']] + $block,
            $payload['objectives'],
        );

        return $this->guarded($payload, 'platform');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function content(ReportShare $share, string $key, array $query): ?array
    {
        if (! $share->isLive() || ! ReportBreakdowns::allows($share, ReportBreakdowns::CONTENT)) {
            return null;
        }

        $content = $this->live->content($share, $key, $query);
        if ($content === null) {
            return null;
        }

        // The same hide flags as the page: the row rides the roster's rules, the points the timeseries'.
        $sanitised = $this->shares->sanitizeLive(['ads_roster' => [$content['content']], 'timeseries' => $content['trend']], $share);
        $content['content'] = $sanitised['ads_roster'][0];
        $content['trend'] = $sanitised['timeseries'];

        return $this->guarded($content, 'content');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function guarded(array $payload, string $which): ?array
    {
        $violations = ClientReportContentValidator::campaignEntities($payload);
        if ($violations !== []) {
            Log::error('report drill-down refused: campaign entity in a client payload', [
                'drilldown' => $which,
                'paths' => array_column($violations, 'path'),
            ]);

            return null;
        }

        return $payload;
    }
}
