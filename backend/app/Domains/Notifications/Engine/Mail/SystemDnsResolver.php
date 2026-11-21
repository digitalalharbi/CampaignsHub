<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Mail;

/** PHP's own resolver. Never writes anything; a failed lookup is an empty answer. */
final class SystemDnsResolver implements DnsResolver
{
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_values(array_map(
            static fn (array $r): string => isset($r['entries']) ? implode('', (array) $r['entries']) : (string) ($r['txt'] ?? ''),
            $records,
        ));
    }

    public function mx(string $host): array
    {
        return array_values(array_map(static fn (array $r): string => strtolower((string) ($r['target'] ?? '')), @dns_get_record($host, DNS_MX) ?: []));
    }

    public function cname(string $host): array
    {
        return array_values(array_map(static fn (array $r): string => strtolower((string) ($r['target'] ?? '')), @dns_get_record($host, DNS_CNAME) ?: []));
    }
}
