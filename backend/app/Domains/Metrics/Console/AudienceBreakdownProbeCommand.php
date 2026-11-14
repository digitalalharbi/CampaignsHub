<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Providers\ReportsAudienceBreakdowns;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Integrations\Support\ProviderErrorText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AUDIENCE-001 / GEO-001 — ask one ad account's paid-delivery breakdown for a window and PRINT it. Writes nothing.
 *
 * Dry run only, by construction: there is no write flag. The scheduled sync stores breakdowns; this command exists so
 * an operator can read what the provider says for a past window (GEO-001: 1KCOFFEE on 2026-11-12) before anything is
 * stored or concluded. It prints the region label, the ad-set count, impressions and clicks — no names of people, no
 * tokens, no spend. It calls the provider read-only; it never changes a campaign.
 *
 *   php artisan audience:breakdown-probe <account-uuid-or-provider-id> --project=<uuid> --from=2026-11-12 --to=2026-11-12 [--dimension=region]
 */
final class AudienceBreakdownProbeCommand extends Command
{
    protected $signature = 'audience:breakdown-probe {account} {--project=} {--from=} {--to=} {--dimension=region}';

    protected $description = 'Read-only: print one ad account\'s paid-delivery breakdown for a window (stores no breakdown; a token refresh may be saved as on any provider call)';

    public function handle(AdvertisingConnectorRegistry $registry): int
    {
        $ref = (string) $this->argument('account');
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: $from);
        $project = (string) $this->option('project');
        if ($from === '' || $project === '' || preg_match('/^[0-9a-f-]{36}$/i', $project) !== 1) {
            $this->error('Name an ad account, --project (its id) and --from.');

            return self::FAILURE;
        }
        // Resolved THROUGH the project's active bindings, so the same provider id in another tenant can never be picked.
        $boundIds = DB::table('project_integration_bindings')->where('project_id', $project)->where('is_active', true)->pluck('external_account_id')->map(static fn ($v): string => (string) $v)->all();
        $account = ExternalAccount::withoutGlobalScopes()->where('account_type', 'ad_account')->whereIn('id', $boundIds)
            ->where(fn ($q) => $q->where('external_id', $ref)->orWhere('id', preg_match('/^[0-9a-f-]{36}$/i', $ref) === 1 ? $ref : '00000000-0000-0000-0000-000000000000'))
            ->first();
        if ($account === null) {
            $this->error('That account is not actively bound to that project.');

            return self::FAILURE;
        }

        $connector = $registry->get((string) $account->provider);
        if (! $connector instanceof ReportsAudienceBreakdowns || ! $connector instanceof ApiAdvertisingConnector) {
            $this->line('UNAVAILABLE: '.$account->provider.' declares no paid-delivery breakdown in this product.');

            return self::SUCCESS;
        }
        $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);
        if ($connection === null) {
            $this->line('BLOCKED: the account has no provider connection.');

            return self::SUCCESS;
        }
        $probe = $connector->withConnection($connection);

        $sets = DB::table('external_ad_sets as s')->join('external_campaigns as c', 'c.id', '=', 's.external_campaign_id')
            ->where('c.external_account_id', $account->getKey())->where('s.project_id', $project)->pluck('s.external_id', 's.id');
        $delivering = [];
        foreach (DB::table('entity_daily_metrics')->where('project_id', $project)->where('external_account_id', $account->getKey())
            ->tap(fn ($q) => BoundAccountVisibility::applyToEntityMetrics($q, 'entity_daily_metrics'))
            ->where('entity_type', 'ad_set')->where('impressions', '>=', max(1, $probe->audienceMinimumImpressions()))->whereBetween('metric_date', [$from, $to])->get(['entity_id', 'metric_date']) as $d) {
            if (isset($sets[(string) $d->entity_id])) {
                $delivering[(string) $sets[(string) $d->entity_id]][] = substr((string) $d->metric_date, 0, 10);
            }
        }

        $dimension = (string) $this->option('dimension');
        $result = $probe->audienceBreakdown((string) $account->external_id, $dimension, $from, $to, $delivering);
        $this->line("== AUDIENCE-PROBE {$account->provider} {$dimension} {$from}..{$to} (dry run, nothing stored)");
        $this->line('source: '.$probe->audienceSourceField($dimension));
        if (! $result->success) {
            $this->line('FAILED: '.ProviderErrorText::forStorage((string) $result->message));

            return self::SUCCESS;
        }
        $by = [];
        foreach ($result->records as $r) {
            $v = (string) $r['value'];
            $by[$v] ??= ['ad_sets' => [], 'impressions' => null, 'clicks' => null];
            $by[$v]['ad_sets'][(string) $r['entity_id']] = true;
            foreach (['impressions', 'clicks'] as $m) {
                if (isset($r[$m])) {
                    $by[$v][$m] = ($by[$v][$m] ?? 0.0) + (float) $r[$m];
                }
            }
        }
        $known = array_flip(array_map('strval', $sets->values()->all()));
        $unknown = count(array_filter($result->records, static fn (array $r): bool => ! isset($known[(string) $r['entity_id']])));
        $this->line('rows: '.count($result->records).' · ad sets of this project in them: '.count(array_unique(array_filter(array_map(static fn (array $r): ?string => isset($known[(string) $r['entity_id']]) ? (string) $r['entity_id'] : null, $result->records)))).' · rows of other ad sets: '.$unknown);
        foreach ($by as $v => $agg) {
            $this->line(sprintf('%s | ad sets %d | impressions %s | clicks %s', $v, count($agg['ad_sets']), $agg['impressions'] === null ? 'not reported' : (string) round($agg['impressions']), $agg['clicks'] === null ? 'not reported' : (string) round($agg['clicks'])));
        }
        $this->line('== END AUDIENCE-PROBE');

        return self::SUCCESS;
    }
}
