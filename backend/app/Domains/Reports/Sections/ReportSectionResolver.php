<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

use App\Domains\Reports\Support\ReportComposition;

/**
 * Decides, once, which sections a report shows and why the rest do not — REPORT-SECTION-MODEL-001.
 *
 * Every surface calls this with the same settings and gets the same answer: the live page, the shared
 * link, the print route behind the PDF and the file export. A surface that made its own decision is
 * how a report comes to show a budget block on the page and not in the PDF.
 *
 * ## The reasons, and their precedence
 *
 * A hidden section carries exactly one reason, the first that applies:
 *
 *  1. `disabled_by_operator` — somebody chose it off (or chose a form that does not contain it). The
 *     strongest statement: evidence is not consulted for a section nobody wants.
 *  2. `unsupported_by_provider_or_objective` — the platforms or the objective cannot produce it
 *     honestly, whatever the figures are.
 *  3. `data_unavailable` — it would be supported, and the figures for this window are not there.
 *
 * Availability is judged only when a payload is present. The builder previewing a live report has
 * no figures until a client opens it, and a section is not «unavailable» because nobody looked.
 */
final class ReportSectionResolver
{
    public const DISABLED_BY_OPERATOR = 'disabled_by_operator';

    public const UNSUPPORTED = 'unsupported_by_provider_or_objective';

    public const DATA_UNAVAILABLE = 'data_unavailable';

    /**
     * The form's own composition, in section vocabulary. An executive summary does not contain the
     * funnel (`ReportComposition::DETAILED_ONLY`), and choosing the summary is the operator's choice.
     */
    private const FORM_WITHHOLDS = ['funnel_store' => 'funnel'];

    public function __construct(private readonly ReportSectionRegistry $registry) {}

    public function resolve(SectionSettings $settings, SectionContext $context): ResolvedSections
    {
        $withheldByForm = [];
        foreach (ReportComposition::for($context->form)->withheldSections() as $legacy) {
            if (isset(self::FORM_WITHHOLDS[$legacy])) {
                $withheldByForm[self::FORM_WITHHOLDS[$legacy]] = true;
            }
        }

        $entries = [];

        foreach ($this->registry->all() as $section) {
            $reason = null;
            $because = null;

            if (! $settings->enabled($section, $context->audience) || isset($withheldByForm[$section->key])) {
                $reason = self::DISABLED_BY_OPERATOR;
            }

            if ($reason === null) {
                foreach ($this->registry->supportPredicates($section->key) as $name => $predicate) {
                    if (! $predicate($context)) {
                        [$reason, $because] = [self::UNSUPPORTED, $name];
                        break;
                    }
                }
            }

            if ($reason === null && $context->payload !== null) {
                foreach ($this->registry->availabilityPredicates($section->key) as $name => $predicate) {
                    if (! $predicate($context)) {
                        [$reason, $because] = [self::DATA_UNAVAILABLE, $name];
                        break;
                    }
                }
            }

            $entries[$section->key] = [
                'section' => $section,
                'visible' => $reason === null,
                'reason' => $reason,
                'because' => $because,
            ];
        }

        return new ResolvedSections($entries, availabilityJudged: $context->payload !== null);
    }

    /**
     * Resolve against a payload and apply the result to it in one step — what a surface calls.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyTo(array $payload, SectionSettings $settings, SectionContext $context): array
    {
        return $this->resolve($settings, $context->withPayload($payload))->apply($payload);
    }
}
