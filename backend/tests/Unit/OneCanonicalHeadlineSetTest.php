<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * OWNER CONTENT P0 — every surface that JUDGES a creative asks one question about it.
 *
 * `CreativeMetrics::headline()` answers two different questions depending on whether it is handed the
 * creative's figures. With them it returns what THIS creative, in THIS window, can actually answer.
 * Without them it returns what the objective's family would want of any creative — a description of a
 * family, not a reading of a row.
 *
 * The client report detail once asked the second question. A reader comparing it with the content
 * card, over one creative and one period, was told the creative should be judged on metrics that were
 * blank in the report and not told about the ones that were real. That was fixed in
 * `SharedCreativeView`, and this is what stops it coming back somewhere else: a new surface that
 * forgets the figures is a silent divergence, visible only by opening two pages side by side.
 *
 * The scan is deliberately narrow — the call, on `CreativeMetrics`, with one argument — and the two
 * places that legitimately ask the family's question name themselves here with their reason.
 */
final class OneCanonicalHeadlineSetTest extends TestCase
{
    /**
     * The two callers that ask about a FAMILY rather than about a creative.
     *
     * `ContentIntelligence::comparableMetric()` is choosing a metric to compare FORMATS by — the
     * family's order is the point, and it then requires every compared format to have answered it.
     *
     * `ReconcileContentMetricsCommand` prints the family's list beside the surfaces' as context, and
     * labels it as context: it is what explains WHY a metric is missing from the card.
     *
     * @var list<string>
     */
    private const MAY_ASK_WITHOUT_FIGURES = [
        'Domains/Metrics/Services/ContentIntelligence.php',
        'Domains/Campaigns/Console/ReconcileContentMetricsCommand.php',
    ];

    public function test_no_surface_judges_a_creative_on_the_family_list_alone(): void
    {
        /* A plain unit test: no framework is booted, so the path is taken from this file. */
        $app = dirname(__DIR__, 2).'/app';
        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($app.'/', '', (string) $file->getPathname());
            $source = (string) file_get_contents((string) $file->getPathname());

            foreach (explode("\n", $source) as $number => $line) {
                /*
                 * `…metrics->headline($x)` or `app(CreativeMetrics::class)->headline($x)` — one
                 * argument, so no figures. `DigestPresenter::headline()` is a different class with a
                 * different job and is not matched: the receiver has to be the metrics service.
                 */
                if (preg_match('/(metrics|CreativeMetrics::class\))->headline\(\s*\$[A-Za-z_]+\s*\)/', $line) !== 1) {
                    continue;
                }

                if (in_array($relative, self::MAY_ASK_WITHOUT_FIGURES, true)) {
                    continue;
                }

                $offenders[] = $relative.':'.($number + 1);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A surface asks CreativeMetrics::headline() without this creative\'s figures.',
            'That returns the objective family\'s list rather than what this row can answer, so the',
            'surface promises metrics the creative never reported and drops ones it did — which is the',
            'card ↔ report divergence the owner met. Pass the figures, or add the call here with the',
            'reason it is asking about a family rather than about a creative:',
            ...$offenders,
        ]));
    }
}
