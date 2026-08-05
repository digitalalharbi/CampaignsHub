<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Manage secure client links for a report (create/list/revoke + access logs). Requires reports.share. */
final class ReportShareController extends Controller
{
    public function __construct(private readonly ShareService $shares) {}

    public function index(Request $request, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findReport($report);
        $shares = ReportShare::where('report_id', $model->id)->withCount('logs')->latest()->get()
            ->map(fn (ReportShare $s) => $this->shape($s));

        return ApiResponse::success($shares, 'Shares retrieved.');
    }

    public function store(Request $request, AuditLogger $audit, string $project, string $report): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $model = $this->findReport($report);
        abort_unless($model->status === 'completed', 409, 'Generate the report before sharing.');
        // An internal report is never shareable externally — a client version must be created first.
        abort_if(
            ($model->audience ?? 'client') === 'internal',
            422, 'This report must be converted to a client version before sharing.',
        );

        $opts = $request->validate([
            'password' => ['nullable', 'string', 'min:4', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'allow_download' => ['boolean'],
            'hide_spend' => ['boolean'],
            'hide_revenue' => ['boolean'],
            'hide_campaign_names' => ['boolean'],
            'watermark' => ['boolean'],
            // LIVEREP-001 — asking for a live link.
            'live' => ['boolean'],
            'campaign_ids' => ['array'],
            'campaign_ids.*' => ['uuid'],
            'providers' => ['array'],
            'providers.*' => ['string', 'max:40'],
            'earliest' => ['nullable', 'date'],
            'latest' => ['nullable', 'date'],
        ]);

        if (! empty($opts['live'])) {
            $opts['scope'] = $this->scopeFor($model, $opts);
        }

        [$share, $raw] = $this->shares->create($model, $opts, $request->user()->id);
        $audit->log(action: 'report.shared_link_created', entityType: ReportShare::class, entityId: (string) $share->id);

        return ApiResponse::success(
            $this->shape($share) + ['url' => ShareService::pathFor($raw), 'token' => $raw],
            'Secure link created. Copy it now — it is shown only once.',
            status: 201,
        );
    }

    public function revoke(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $this->findReport($report);
        $model = ReportShare::findOrFail($share);
        $model->update(['revoked_at' => Carbon::now()]);
        $audit->log(action: 'report.shared_link_revoked', entityType: ReportShare::class, entityId: (string) $model->id);

        return ApiResponse::success($this->shape($model), 'Link revoked.');
    }

    /**
     * Push a link's expiry out — the alternative to revoking one link and issuing another.
     *
     * Worth its own endpoint because the workaround is worse than it looks: re-issuing means the client
     * must be sent a new URL, the old one 404s in whatever email or chat it was pasted into, and the
     * access history of the relationship is split across two rows. Renewing keeps one link, one history,
     * one URL the client has already bookmarked.
     *
     * A revoked link is NOT renewable. Revocation is a decision someone made about a recipient, and
     * quietly reversing it by extending a date would turn a deliberate cut-off into a date change.
     */
    public function renew(Request $request, AuditLogger $audit, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $this->findReport($report);
        $model = ReportShare::findOrFail($share);
        abort_if($model->revoked_at !== null, 409, 'A revoked link cannot be renewed — create a new one.');

        $data = $request->validate(['expires_at' => ['required', 'date', 'after:now']]);
        $model->update(['expires_at' => $data['expires_at']]);
        $audit->log(action: 'report.shared_link_renewed', entityType: ReportShare::class, entityId: (string) $model->id);

        return ApiResponse::success($this->shape($model), 'Link renewed.');
    }

    public function logs(Request $request, string $project, string $report, string $share): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.share'), 403);
        $this->findReport($report);
        $model = ReportShare::findOrFail($share);
        $logs = $model->logs()->latest()->limit(100)->get(['action', 'ip', 'user_agent', 'detail', 'created_at']);

        return ApiResponse::success($logs, 'Access logs.');
    }

    /**
     * The ceiling a live link may never exceed (LIVEREP-001).
     *
     * Built HERE, from the report and the operator's request — never from the client's later query
     * string. The operator chooses; the recipient may only narrow.
     *
     * Two decisions worth stating:
     *
     * - **An empty campaign set falls back to the report's own campaigns, not to «all».** If the
     *   operator sent no list, the defensible reading is «the campaigns this report is about», and the
     *   report knows them. Reading it as «everything in the project» would hand over campaigns nobody
     *   chose to share.
     * - **`latest` defaults to open-ended-until-today, not to the report's period end.** A live link
     *   whose window stopped where the document stopped would answer «how is it going?» with last
     *   month's answer, which is the exact failure this feature exists to remove. It still cannot reach
     *   BEFORE the report's period, because that data was never part of what was shared.
     *
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    private function scopeFor(Report $model, array $opts): array
    {
        $campaigns = array_values(array_filter((array) ($opts['campaign_ids'] ?? [])));
        if ($campaigns === []) {
            $campaigns = $this->campaignsOf($model);
        }

        $providers = array_values(array_filter((array) ($opts['providers'] ?? [])));
        if ($providers === []) {
            $providers = $this->providersOf($model);
        }

        return [
            'project_id' => (string) $model->project_id,
            'campaign_ids' => $campaigns,
            'providers' => $providers,
            'earliest' => (string) ($opts['earliest'] ?? $model->period_start?->toDateString() ?? Carbon::now()->subDays(90)->toDateString()),
            'latest' => (string) ($opts['latest'] ?? Carbon::now()->addYear()->toDateString()),
        ];
    }

    /** The campaigns this report covers: its own if it is campaign-scoped, else those in its data. */
    private function campaignsOf(Report $model): array
    {
        if ($model->campaign_id !== null) {
            return [(string) $model->campaign_id];
        }

        $rows = (array) (($model->data ?? [])['campaigns'] ?? []);

        return array_values(array_unique(array_filter(array_map(
            static fn ($row) => is_array($row) ? ($row['campaign_id'] ?? null) : null,
            $rows,
        ))));
    }

    /** The platforms this report already reports on — the honest default ceiling. */
    private function providersOf(Report $model): array
    {
        $rows = (array) (($model->data ?? [])['platforms'] ?? []);

        return array_values(array_unique(array_filter(array_map(
            static fn ($row) => is_array($row) ? ($row['provider'] ?? null) : null,
            $rows,
        ))));
    }

    private function findReport(string $id): Report
    {
        $r = Report::query()->find($id);
        abort_if($r === null, 404, 'Report not found.');

        return $r;
    }

    private function shape(ReportShare $s): array
    {
        return [
            'id' => $s->id,
            'active' => $s->isActive(),
            'mode' => $s->isLive() ? 'live' : 'snapshot',
            'scope' => $s->scope,
            'allow_download' => $s->allow_download,
            'hide_spend' => $s->hide_spend,
            'hide_revenue' => $s->hide_revenue,
            'hide_campaign_names' => $s->hide_campaign_names,
            'watermark' => $s->watermark,
            'password_protected' => $s->password_hash !== null,
            'view_count' => $s->view_count,
            'last_viewed_at' => $s->last_viewed_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
            'revoked_at' => $s->revoked_at?->toIso8601String(),
            'logs_count' => $s->logs_count ?? null,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
