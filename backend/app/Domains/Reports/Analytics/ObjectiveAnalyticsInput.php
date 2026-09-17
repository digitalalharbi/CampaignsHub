<?php

declare(strict_types=1);

namespace App\Domains\Reports\Analytics;

use Illuminate\Support\Carbon;

/**
 * The scope the objective section is built for — the same bounds the rest of the report's figures honour.
 *
 * `$content` is the roster the ads section already read (name, provider, format, objective,
 * metrics), passed in rather than queried again, so the content leaders and the ads grid describe
 * the same creatives.
 */
final class ObjectiveAnalyticsInput
{
    /**
     * @param  list<string>|null  $projectIds
     * @param  list<string>|null  $campaignIds
     * @param  list<string>|null  $providers
     * @param  list<string>|null  $accountIds
     * @param  list<array<string,mixed>>  $content
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?array $projectIds = null,
        public readonly ?array $campaignIds = null,
        public readonly ?array $providers = null,
        public readonly ?array $accountIds = null,
        public readonly array $content = [],
    ) {}
}
