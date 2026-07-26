<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues and validates secure client report links. The raw token is returned ONCE at creation and
 * never stored (only its sha256 hash is), and the sanitizer strips hidden figures before a client
 * ever sees the payload. Every access is logged.
 */
final class ShareService
{
    public function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** @return array{0: ReportShare, 1: string} the share + the raw token (show once) */
    public function create(Report $report, array $opts, ?int $userId): array
    {
        $raw = Str::random(48);
        $share = ReportShare::create([
            'tenant_id' => $report->tenant_id,
            'report_id' => $report->id,
            'token_hash' => $this->hashToken($raw),
            'password_hash' => ! empty($opts['password']) ? Hash::make($opts['password']) : null,
            'allow_download' => $opts['allow_download'] ?? true,
            'hide_spend' => $opts['hide_spend'] ?? false,
            'hide_revenue' => $opts['hide_revenue'] ?? false,
            'hide_campaign_names' => $opts['hide_campaign_names'] ?? false,
            'watermark' => $opts['watermark'] ?? false,
            'expires_at' => $opts['expires_at'] ?? null,
            'created_by' => $userId,
            'is_demo' => (bool) ($report->is_demo ?? false),
        ]);

        return [$share, $raw];
    }

    public function resolveActive(string $rawToken): ?ReportShare
    {
        $share = ReportShare::withoutGlobalScopes()->where('token_hash', $this->hashToken($rawToken))->first();

        return $share && $share->isActive() ? $share : null;
    }

    public function log(ReportShare $share, string $action, Request $request, ?string $detail = null): void
    {
        $share->logs()->create([
            'action' => $action,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    /** Removes figures the share hides so the client payload never contains them. */
    public function sanitize(array $data, ReportShare $share): array
    {
        $stripMoney = function (array &$row) use ($share): void {
            if ($share->hide_spend) {
                foreach (['spend', 'cpa', 'cpc', 'cpm'] as $k) {
                    if (array_key_exists($k, $row)) {
                        $row[$k] = null;
                    }
                }
            }
            if ($share->hide_revenue) {
                foreach (['revenue', 'roas'] as $k) {
                    if (array_key_exists($k, $row)) {
                        $row[$k] = null;
                    }
                }
            }
        };

        if (isset($data['kpis']) && is_array($data['kpis'])) {
            $stripMoney($data['kpis']);
        }
        foreach (['platforms', 'campaigns', 'top_creatives', 'timeseries', 'budget'] as $section) {
            if (! empty($data[$section]) && is_array($data[$section])) {
                foreach ($data[$section] as &$row) {
                    if (is_array($row)) {
                        $stripMoney($row);
                        if ($share->hide_campaign_names && array_key_exists('campaign_name', $row)) {
                            $row['campaign_name'] = 'حملة';
                        }
                    }
                }
                unset($row);
            }
        }
        if ($share->hide_spend || $share->hide_revenue) {
            unset($data['summary']); // summary embeds spend/revenue figures
        }

        return $data;
    }
}
