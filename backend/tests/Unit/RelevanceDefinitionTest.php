<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Support\Relevance;
use PHPUnit\Framework\TestCase;

/**
 * ENTITY-RELEVANCE-ORDERING-001 — one definition, held in two languages.
 *
 * The RULE lives in `frontend/src/features/campaigns/campaignRelevance.ts` and is read at every rung
 * of the hierarchy by listings that already hold their rows. It could not stay only there the moment
 * a PAGED listing needed the same order: the content library is sorted and paged by the database, so
 * ordering one page in the browser reorders that page and misstates the listing — the order has to be
 * expressed in SQL, and a SQL order needs these two constants.
 *
 * Two copies of a definition is what that module's own docblock warns against. This is what keeps it
 * one copy: the TypeScript file is read as text and compared against the PHP constants, so the day
 * somebody adds `suspended` to one side, the other side fails.
 *
 * ## Why this guard is in PHP and not beside the rule
 *
 * The frontend's `tsc -b` covers its test files and the project has no Node type definitions, so a
 * test there cannot read a file off disk — and a guard rewritten to fit that limitation would be
 * asserting something weaker than «these two agree». PHP can read both files, so it holds both.
 */
final class RelevanceDefinitionTest extends TestCase
{
    private function typescript(): string
    {
        $path = __DIR__.'/../../../frontend/src/features/campaigns/campaignRelevance.ts';

        $this->assertFileExists($path, 'the rule moved — this guard is watching nothing');

        return (string) file_get_contents($path);
    }

    public function test_both_sides_name_the_same_stopped_statuses(): void
    {
        preg_match("/NOT_RUNNING_STATUSES = \[([^\]]*)\]/", $this->typescript(), $m);
        preg_match_all("/'([^']+)'/", $m[1] ?? '', $found);

        $ts = $found[1] ?? [];
        sort($ts);
        $php = Relevance::NOT_RUNNING;
        sort($php);

        $this->assertNotSame([], $ts, 'the TypeScript list could not be read');
        $this->assertSame($php, $ts, 'the two definitions of «stopped» have drifted apart');
    }

    public function test_both_sides_allow_the_same_reporting_lag(): void
    {
        preg_match('/SERVING_WITHIN_DAYS = (\d+)/', $this->typescript(), $m);

        $this->assertSame(Relevance::SERVING_WITHIN_DAYS, (int) ($m[1] ?? 0));
    }
}
