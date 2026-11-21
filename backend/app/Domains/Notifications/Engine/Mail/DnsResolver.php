<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Mail;

/** TXT / MX lookups for the mail DNS check. Bound to `SystemDnsResolver`; tests bind a fake. Read-only. */
interface DnsResolver
{
    /** @return list<string> every TXT string at `$host` (multi-part records joined) */
    public function txt(string $host): array;

    /** @return list<string> MX targets of `$host` */
    public function mx(string $host): array;

    /** @return list<string> CNAME targets of `$host` */
    public function cname(string $host): array;
}
