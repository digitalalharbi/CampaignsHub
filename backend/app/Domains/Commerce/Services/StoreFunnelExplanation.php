<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the funnel's shape, applied to the funnel itself.
 *
 * ## What the section was
 *
 * A row of stages with a number under each. The drop between two of them is the only thing anybody
 * opens it for, and it was left for the reader to compute — every time, from figures whose sources
 * differ: the click is the platform's claim, the order is the merchant's ledger.
 *
 * ## The signal is the LARGEST measured drop, and only between measured stages
 *
 * A stage nobody measured cannot be an end of a drop. Treating an unavailable stage as zero produces
 * a hundred-per-cent fall into it, which is the biggest number on the page and the one thing there
 * that is certainly false — and it appears exactly where the data is weakest, which is where a
 * reader trusts a big number most.
 *
 * ## Nothing here is a benchmark
 *
 * There is no «good» conversion rate in this file. The drop is stated with the two stages it sits
 * between, and the explanation says what the pair MEANS — that these two numbers come from different
 * systems and a gap between them is partly measurement — rather than what it is worth.
 */
final class StoreFunnelExplanation
{
    /**
     * @param  list<array<string,mixed>>  $stages  from {@see StoreFunnelService::build()}
     * @return array<string,mixed>
     */
    public function explain(array $stages): array
    {
        $measured = array_values(array_filter(
            $stages,
            static fn (array $s): bool => in_array($s['state'] ?? null, ['measured', 'partial'], true) && $s['value'] !== null,
        ));

        $unmeasured = count($stages) - count($measured);

        if (count($measured) < 2) {
            return [
                'signal' => null,
                'context' => null,
                'explanation' => null,
                'evidence' => [],
                'action' => null,
                /*
                 * «One stage is measured» and «no stage is» are different situations: the first is a
                 * store that has not synced its orders, the second is a project with no store at all.
                 */
                'silent_reason' => $measured === [] ? 'no_stage_could_be_measured' : 'only_one_stage_is_measured',
                'unmeasured_stages' => $unmeasured,
            ];
        }

        $worst = null;

        for ($i = 1; $i < count($measured); $i++) {
            $from = $measured[$i - 1];
            $to = $measured[$i];
            $before = (float) $from['value'];
            $after = (float) $to['value'];

            // A stage that GREW is not a drop. It happens — a platform counts a view the shop never
            // saw — and calling it a fall of minus-something would be arithmetic pretending to read.
            if ($before <= 0 || $after > $before) {
                continue;
            }

            $lost = $before - $after;
            $share = $lost / $before;

            if ($worst === null || $share > $worst['share']) {
                $worst = [
                    'from' => ['key' => $from['key'], 'label_ar' => $from['label_ar'], 'label_en' => $from['label_en'], 'value' => $before],
                    'to' => ['key' => $to['key'], 'label_ar' => $to['label_ar'], 'label_en' => $to['label_en'], 'value' => $after],
                    'lost' => round($lost, 2),
                    'share' => round($share, 4),
                    /*
                     * Whether the two ends came from the SAME system. A drop between a platform's
                     * click and a merchant's order is partly measurement; a drop between two of the
                     * merchant's own stages is not, and an operator reads those differently.
                     */
                    'same_source' => ($from['source']['kind'] ?? null) === ($to['source']['kind'] ?? null),
                ];
            }
        }

        if ($worst === null) {
            return [
                'signal' => null,
                'context' => null,
                'explanation' => null,
                'evidence' => [],
                'action' => null,
                'silent_reason' => 'no_stage_fell',
                'unmeasured_stages' => $unmeasured,
            ];
        }

        return [
            'signal' => $worst,
            'context' => ['stages_measured' => count($measured), 'stages_total' => count($stages)],
            'explanation' => $worst['same_source']
                ? [
                    'ar' => 'المرحلتان من المصدر نفسه، فالفارق بينهما سلوك حقيقي لا فرق في القياس.',
                    'en' => 'Both stages come from the same source, so the distance between them is behaviour rather than a difference in measurement.',
                ]
                : [
                    'ar' => 'المرحلتان من مصدرين مختلفين — إحداهما من المنصة والأخرى من المتجر — فجزء من الفارق قياس لا سلوك.',
                    'en' => 'The two stages come from different sources — one from the platform, one from the shop — so part of the distance is measurement rather than behaviour.',
                ],
            'evidence' => [$worst['from']['key'], $worst['to']['key']],
            'action' => [
                'ar' => "افحص ما بين «{$worst['from']['label_ar']}» و«{$worst['to']['label_ar']}» — هنا يفقد المسار أكبر نسبة.",
                'en' => "Look at what sits between «{$worst['from']['label_en']}» and «{$worst['to']['label_en']}» — this is where the path loses its largest share.",
            ],
            'silent_reason' => null,
            'unmeasured_stages' => $unmeasured,
        ];
    }
}
