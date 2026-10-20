<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\ReportShare;

/**
 * REPORT-TABLES-OWNER-COLUMNS-001 — which COLUMNS of a report table the Owner chose to publish.
 *
 * The Owner controls the table, not the product's default. A link stores the columns it shows per
 * table; an absent table means «every column», which is what every link built before this existed
 * rendered, so no existing link changes. The identity column (the row's name) is never a choice —
 * a row without its name is not a row.
 *
 * Only keys this class lists are accepted: a column the table does not have cannot be «chosen», and
 * a value arriving from a request is cut to the known set rather than stored as sent.
 *
 * Hiding a column is a display decision. Money a link hides altogether stays governed by
 * `HiddenMoney` and `hide_spend`, which this never relaxes: choosing the spend column on a link that
 * hides spend still publishes no spend.
 */
final class ReportColumns
{
    /** @var array<string, list<string>> table => its choosable columns, in display order */
    public const TABLES = [
        'platforms' => ['spend', 'results', 'cost', 'impressions', 'clicks', 'ctr'],
        // The Tables view's other three tables (REPORT-TABLES-OWNER-COLUMNS-001, second batch). The name
        // column — the account, the content, the day — is never a choice.
        'accounts' => ['platform', 'results', 'impressions', 'clicks', 'spend', 'cost'],
        'content' => ['platform', 'results', 'impressions', 'clicks', 'spend', 'cost'],
        'days' => ['impressions', 'clicks', 'results', 'spend'],
    ];

    /** @param array<string, list<string>> $chosen only the tables the Owner restricted */
    private function __construct(private readonly array $chosen) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $chosen = [];

        foreach (self::TABLES as $table => $known) {
            if (! is_array($raw[$table] ?? null)) {
                continue;
            }

            $picked = array_values(array_unique(array_filter(
                $raw[$table],
                static fn ($key): bool => is_string($key) && in_array($key, $known, true),
            )));

            // Keep the table's own order, whatever order the request listed them in.
            $chosen[$table] = array_values(array_intersect($known, $picked));
        }

        return new self($chosen);
    }

    public static function forShare(ReportShare $share): self
    {
        return self::fromArray((array) (($share->settings ?? [])['columns'] ?? []));
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return $this->chosen;
    }
}
