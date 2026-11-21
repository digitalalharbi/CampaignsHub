<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Services\MetaConnectionDiagnosis;
use Illuminate\Console\Command;

/**
 * META-DIAGNOSIS-001 — the same read as the operator's page, for a workflow log.
 *
 * No Graph call and no write. Prints ids and Meta's codes and never a tenant's, an account's or a person's name,
 * because a workflow log is read by more people than the platform console.
 */
final class MetaDiagnoseCommand extends Command
{
    protected $signature = 'integrations:meta-diagnose';

    protected $description = 'Each Meta connection and ad account, from what the sync recorded: state, code, scopes, token, last sync, expired media. Calls nothing.';

    public function handle(MetaConnectionDiagnosis $diagnosis): int
    {
        $connections = $diagnosis->connections();

        if ($connections === []) {
            $this->line('No Meta connection exists on this install.');

            return self::SUCCESS;
        }

        foreach ($connections as $c) {
            $this->line(sprintf(
                'connection %s app=%s status=%s state=%s token=%s last_sync=%s accounts total=%d selected=%d synced=%d expired_media=%d',
                substr($c['id'], 0, 8), $c['app'], $c['status'], $c['state'], $c['token']['state'], $c['last_sync_at'] ?? 'never',
                $c['accounts_total'], $c['accounts_selected'], $c['accounts_synced'], $c['expired_media'],
            ));
            $this->line('  scopes missing: '.($c['scopes_missing'] === null ? 'unknown (no grant recorded)' : ($c['scopes_missing'] === [] ? 'none' : implode(',', $c['scopes_missing']))));
            $this->line('  last refusal: '.$this->refusal($c['refusal']));
            $this->line('  refusals in 24h: '.($c['refusals_24h'] === [] ? 'none' : (string) json_encode($c['refusals_24h'])));

            foreach ($c['accounts'] as $a) {
                $this->line(sprintf(
                    '  %s selected=%s health=%s creatives=%d expired=%d structure=%s (%s) metrics=%s (%s) media=%s renewal=%s',
                    $a['external_id'], $a['selected'] ? 'yes' : 'no', $a['health'], $a['creatives'], $a['expired_media'],
                    $a['structure']['status'] ?? 'none', $this->refusal($a['structure']['refusal']),
                    ($a['metrics']['status'] ?? 'none').$this->reading($a['metrics']), $this->refusal($a['metrics']['refusal']),
                    json_encode(array_diff_key((array) $a['structure']['media'], ['error' => true])),
                    json_encode($a['structure']['media_renewal']),
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * META-NO-DATA-READING-001 — `no_data[no_delivery, last delivery 2026-10-28]`: which «nothing» it was.
     *
     * @param  array<string,mixed>  $metrics
     */
    private function reading(array $metrics): string
    {
        if (($metrics['reading'] ?? null) === null) {
            return '';
        }

        return sprintf('[%s, last delivery %s]', $metrics['reading'], $metrics['last_delivery_date'] ?? 'none stored');
    }

    /** @param array<string,mixed>|null $refusal */
    private function refusal(?array $refusal): string
    {
        return $refusal === null
            ? 'none'
            : trim(sprintf('%s code %s subcode %s trace %s', $refusal['kind'], $refusal['code'] ?? '-', $refusal['subcode'] ?? '-', $refusal['trace'] ?? '-'));
    }
}
