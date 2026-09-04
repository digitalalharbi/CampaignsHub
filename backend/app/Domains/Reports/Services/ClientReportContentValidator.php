<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * Guards a CLIENT-facing report body against leaking internal/technical content: UUIDs, checksums,
 * request/queue/sync internals, draft (unapproved) recommendations, and internal campaign markers.
 * Used as a test assertion and a runtime guard before a client link/PDF is served.
 *
 * ## The structural half — CLIENT-REPORT-ENTITY-BOUNDARY-001
 *
 * The patterns below can only catch text that LOOKS internal. A campaign called «White Friday» looks
 * like nothing at all, and it is still the agency's own container. So identity is checked by SHAPE:
 * a client body may not carry a campaign or ad-set key, whatever the value in it reads like.
 *
 * {@see ClientReportView} removes those keys before this runs, which makes a violation here a report
 * on the REMOVER — a path that reached a client without passing through it, or a key nobody thought
 * of. That is exactly what a guard downstream of a fix is for.
 */
final class ClientReportContentValidator
{
    /** Forbidden patterns in client-facing text. */
    private const FORBIDDEN = [
        'uuid' => '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
        'checksum' => '/\b[0-9a-f]{32,64}\b/i',
        'internal_marker_burner' => '/\bburner\b/i',
        'internal_marker_test' => '/\btest campaign\b/i',
        'internal_version_tag' => '/\b(?:adset|ad set)[-\s]?copy\b|campaign[-\s]?final[-\s]?v\d+/i',
        'request_id' => '/\brequest[_\s-]?id\b/i',
        'checksum_word' => '/\bchecksum\b/i',
        'sync_run' => '/\bsync[_\s-]?run\b|\bsync diagnostics\b/i',
        'queue' => '/\bqueue\b|\bjob[_\s-]?id\b|\bretry\b/i',
        'token' => '/\bbearer\b|\baccess[_\s-]?token\b|\boauth\b/i',
        'payload' => '/\bpayload\b|\braw[_\s-]?field\b/i',
        'stack_trace' => '/\bstack ?trace\b|\bexception\b/i',
        'sql' => '/\bselect\s+.+\s+from\b|\binsert into\b/i',
        'temp_marker' => '/\btmp\b|final[-\s]?v[23]\b/i',
        'confidence' => '/\bconfidence[_\s-]?score\b|\bai[_\s-]?score\b/i',
    ];

    /**
     * @param  array<string,mixed>  $clientData  a snapshot already passed through ClientReportView::filter
     * @return list<array{code:string, match:string, path:string}> violations (empty = clean)
     */
    public function scan(array $clientData): array
    {
        $violations = [];

        // A campaign-management entity, by shape rather than by what its name happens to read like.
        foreach (self::entityViolations($clientData) as $violation) {
            $violations[] = $violation;
        }

        // Any unapproved recommendation is itself a violation for a client report.
        foreach ($clientData['recommendations'] ?? [] as $r) {
            if (($r['status'] ?? 'draft') !== 'approved') {
                $violations[] = ['code' => 'unapproved_recommendation', 'match' => (string) ($r['title'] ?? ''), 'path' => 'recommendations'];
            }
        }

        // Scan the client-facing text fields (not the whole snapshot — metadata/methodology legitimately
        // carry a checksum in some surfaces; the client BODY must be clean).
        $text = $this->collectText($clientData);
        foreach (self::FORBIDDEN as $code => $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $violations[] = ['code' => $code, 'match' => (string) $m[0], 'path' => 'body'];
            }
        }

        return $violations;
    }

    /**
     * The keys that carry campaign-management identity, wherever they sit.
     *
     * Walked rather than checked at the top level: `objective_performance.paths[].campaigns[]` and
     * `direct.excluded_campaigns[]` are three levels down, and both carried names and primary keys
     * to a client until this requirement. A guard that only looked at `data.campaigns` would have
     * called that payload clean.
     *
     * @param  array<string,mixed>  $clientData
     * @return list<array{code:string, match:string, path:string}>
     */
    private static function entityViolations(array $clientData): array
    {
        /** @var list<array{code:string, match:string, path:string}> $found */
        $found = [];

        $walk = function (mixed $node, string $path) use (&$walk, &$found): void {
            if (! is_array($node)) {
                return;
            }

            foreach (['campaign_name', 'campaign_id', 'ad_set_name', 'ad_set_id', 'external_entity_id'] as $key) {
                if (array_key_exists($key, $node) && $node[$key] !== null && $node[$key] !== []) {
                    $found[] = [
                        'code' => 'campaign_management_entity',
                        'match' => $key,
                        'path' => $path === '' ? $key : $path.'.'.$key,
                    ];
                }
            }

            foreach ($node as $k => $v) {
                $walk($v, $path === '' ? (string) $k : $path.'.'.$k);
            }
        };

        $walk($clientData, '');

        return $found;
    }

    public function passes(array $clientData): bool
    {
        return $this->scan($clientData) === [];
    }

    /** Concatenate the client-visible text (names, findings, recommendations, summary). */
    private function collectText(array $data): string
    {
        $parts = [];
        foreach (($data['campaigns'] ?? []) as $c) {
            $parts[] = (string) ($c['campaign_name'] ?? '');
        }
        foreach (($data['top_creatives'] ?? []) as $c) {
            $parts[] = (string) ($c['campaign_name'] ?? '');
            $parts[] = (string) ($c['reason'] ?? '');
        }
        foreach (['findings', 'recommendations'] as $key) {
            foreach (($data[$key] ?? []) as $n) {
                $parts[] = (string) ($n['title'] ?? '');
                $parts[] = (string) ($n['detail'] ?? '');
            }
        }
        foreach (($data['summary'] ?? []) as $line) {
            $parts[] = (string) $line;
        }
        foreach (($data['next_steps'] ?? []) as $step) {
            $parts[] = (string) ($step['action'] ?? '');
            $parts[] = (string) ($step['reason'] ?? '');
        }
        $parts[] = (string) ($data['best']['campaign'] ?? '');

        return implode("\n", $parts);
    }
}
