<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Alerts\Services\AlertEvaluator;
use ReflectionClass;
use Tests\TestCase;

/**
 * ALERT-SILENT-RULES-001 — every type a person can create is accounted for.
 *
 * `AlertController::TYPES` decides what may be saved; `AlertEvaluator`'s `match` decides what is ever
 * looked at. They drifted: `cpa_increase` and `cpl_increase` were accepted by the controller, offered
 * by the picker, labelled in the alerts page and named in the evaluator's own docblock — and fell
 * through `default => []`. The rule saved, listed as active, and could not fire. Nothing failed; the
 * operator simply was not warned, which is the failure this product keeps finding in other shapes.
 *
 * This test is the thing that stops it recurring. Every creatable type must be declared as one of:
 * evaluated on a schedule, raised by an event, or KNOWN to be raised by nothing. The third bucket is
 * deliberately uncomfortable to sit in — it is a named defect, not a resting place.
 */
final class AlertEvaluatorCoverageTest extends TestCase
{
    /** @return list<string> */
    private function creatableTypes(): array
    {
        $c = new ReflectionClass(AlertController::class);

        return $c->getConstant('TYPES');
    }

    public function test_every_creatable_type_is_accounted_for(): void
    {
        $declared = array_merge(AlertEvaluator::PERIODIC, AlertEvaluator::EVENT_DRIVEN, AlertEvaluator::UNRAISED);

        sort($declared);
        $creatable = $this->creatableTypes();
        sort($creatable);

        $this->assertSame(
            $creatable,
            $declared,
            'A type a person can create is missing from PERIODIC/EVENT_DRIVEN/UNRAISED, or is declared '
            .'but no longer creatable. Either way the picker and the evaluator disagree.'
        );
    }

    public function test_the_two_cost_per_result_rules_are_evaluated_not_silent(): void
    {
        // The specific regression: these were creatable and unevaluated.
        $this->assertContains('cpa_increase', AlertEvaluator::PERIODIC);
        $this->assertContains('cpl_increase', AlertEvaluator::PERIODIC);
    }

    public function test_no_type_is_declared_in_two_buckets_at_once(): void
    {
        $all = array_merge(AlertEvaluator::PERIODIC, AlertEvaluator::EVENT_DRIVEN, AlertEvaluator::UNRAISED);

        $this->assertSame(
            count($all),
            count(array_unique($all)),
            'A type claims to be both evaluated and not evaluated.'
        );
    }

    public function test_every_periodic_type_has_a_branch_in_the_evaluator(): void
    {
        /*
         * Read the source rather than trusting the constant: PERIODIC is a promise, and `match` is
         * what actually happens. A type could be added to the list and never wired, which is exactly
         * the original defect wearing a newer label.
         */
        $source = file_get_contents((new ReflectionClass(AlertEvaluator::class))->getFileName());
        $body = substr($source, strpos($source, 'match ($rule->type)'));
        $body = substr($body, 0, strpos($body, 'default =>'));

        foreach (AlertEvaluator::PERIODIC as $type) {
            $this->assertStringContainsString(
                "'{$type}' =>",
                $body,
                "'{$type}' is declared PERIODIC but has no branch before `default` — it cannot fire."
            );
        }
    }
}
