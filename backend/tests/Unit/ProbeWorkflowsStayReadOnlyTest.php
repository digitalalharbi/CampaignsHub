<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Catalogue\ProviderDisplayName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * INTEGRATION-META-001 — a probe that can write is not a probe.
 *
 * `integrations:meta-probe --sync` runs a real discovery and writes metric rows. The workflow that
 * exposes the probe to production deliberately does not pass that flag, and this is the guard on
 * that intent: the distance between «ask Meta what this token can do» and «start a real sync on
 * production from a button» is five characters, and a comment saying so protects nothing.
 *
 * The same rule holds for the diagnosis workflow beside it, whose own header states it can only ask
 * questions.
 */
final class ProbeWorkflowsStayReadOnlyTest extends TestCase
{
    private const READ_ONLY_WORKFLOWS = [
        'meta-access-probe.yml',
        'production-diagnostics.yml',
    ];

    public function test_a_read_only_workflow_never_runs_a_writing_command(): void
    {
        foreach (self::READ_ONLY_WORKFLOWS as $file) {
            $path = dirname(__DIR__, 3).'/.github/workflows/'.$file;
            $this->assertFileExists($path, "$file is missing — it is the only way to ask production this question.");

            foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
                // Comments explain WHY the flag is refused; only executable lines are held to it.
                if (preg_match('/^\s*#/', $line) === 1) {
                    continue;
                }

                // ACCOUNT-SCOPE-ISOLATION-001 — deleting and queueing live in production-scope-cleanup.yml.
                $this->assertStringNotContainsString(
                    '--apply',
                    $line,
                    "$file:".($number + 1).' passes --apply, which deletes or queues. Writes belong to production-scope-cleanup.yml.',
                );
                $this->assertDoesNotMatchRegularExpression(
                    '/integrations:(scope-cleanup|resync-window)/',
                    $line,
                    "$file:".($number + 1).' calls a writing command. Writes belong to production-scope-cleanup.yml.',
                );

                $this->assertStringNotContainsString(
                    '--sync',
                    $line,
                    "$file:".($number + 1).' passes --sync, which writes. A workflow described as read-only must stay read-only.',
                );
            }
        }
    }

    /**
     * The one writing workflow writes only when a person ticks `apply`.
     *
     * Every `--apply` on an executable line is the product of `inputs.apply`, and the workflow runs
     * exactly its three writing commands — so a dispatch left at its defaults is a dry run for
     * scope-cleanup, resync-window and quarantine-sandbox alike.
     */
    public function test_the_cleanup_workflow_applies_only_when_the_apply_box_is_ticked(): void
    {
        $path = dirname(__DIR__, 3).'/.github/workflows/production-scope-cleanup.yml';
        $this->assertFileExists($path);

        $commands = [];

        foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            if (str_contains($line, '--apply')) {
                $this->assertSame(
                    'APPLY: ${{ inputs.apply && \'--apply\' || \'\' }}',
                    trim($line),
                    'production-scope-cleanup.yml:'.($number + 1).' passes --apply without the apply box.',
                );
            }

            if (preg_match('/artisan (integrations:[a-z-]+)/', $line, $m) === 1) {
                $commands[] = $m[1];
            }
        }

        sort($commands);
        $this->assertSame(
            ['integrations:quarantine-sandbox', 'integrations:resync-window', 'integrations:scope-cleanup'],
            $commands,
            'the cleanup workflow no longer runs exactly its three writing commands',
        );
    }

    /**
     * The diagnosis form offers provider keys, and they have to be the keys the DATA uses.
     *
     * It offered `google_ads`. No row carries that, so a run with it answers «No external account
     * matches that filter» — a sentence indistinguishable from «this provider has no accounts», and
     * exactly the wrong conclusion to hand someone asking why a provider has gone quiet. It was read
     * as a real Google estate of zero before the second run with `google` agreed by accident.
     */
    public function test_the_diagnosis_form_offers_provider_keys_that_exist(): void
    {
        $path = dirname(__DIR__, 3).'/.github/workflows/production-diagnostics.yml';
        $yaml = (string) file_get_contents($path);

        $matched = preg_match("/Provider key to diagnose \(([^)]*)\)/", $yaml, $found);
        $this->assertSame(1, $matched, 'The provider input lost its list of keys.');

        $known = array_keys(ProviderDisplayName::NAMES);

        foreach (array_map('trim', explode(',', $found[1])) as $offered) {
            $this->assertContains(
                $offered,
                $known,
                "The diagnosis form offers provider key `{$offered}`, which no account can carry.",
            );
        }
    }

    /**
     * A manual workflow may declare at most 25 inputs, because GitHub refuses to dispatch one with more.
     *
     * The cap is GitHub's («you may only define up to 25 `inputs` for a `workflow_dispatch` event»)
     * and it is enforced at DISPATCH, not at merge: CI stays green, the file parses, and every push
     * quietly records a «workflow file issue» run that reads like noise. #465 took the diagnosis
     * workflow from 24 inputs to 28 and the first anybody knew was a read-only dispatch on main being
     * refused — the one workflow that can ask Production a question, unable to be asked anything.
     *
     * Every workflow is walked, not the two read-only ones, because the cap is not about reading.
     */
    public function test_no_workflow_declares_more_dispatch_inputs_than_github_will_accept(): void
    {
        $limit = 25;
        $files = glob(dirname(__DIR__, 3).'/.github/workflows/*.yml') ?: [];
        $this->assertNotEmpty($files, 'no workflows found — the guard would pass over nothing');

        $over = [];
        $seen = 0;

        foreach ($files as $path) {
            $parsed = Yaml::parse((string) file_get_contents($path));
            // YAML reads a bare `on:` as boolean true; both spellings are the same trigger table.
            $triggers = $parsed['on'] ?? $parsed[true] ?? $parsed[1] ?? null;
            $inputs = is_array($triggers) ? ($triggers['workflow_dispatch']['inputs'] ?? null) : null;

            if (! is_array($inputs)) {
                continue;
            }

            $seen++;
            if (count($inputs) > $limit) {
                $over[] = basename($path).': '.count($inputs).' inputs';
            }
        }

        $this->assertGreaterThan(0, $seen, 'no workflow declares dispatch inputs — the guard measured nothing');
        $this->assertSame(
            [],
            $over,
            "GitHub refuses to dispatch a workflow with more than {$limit} inputs, and refuses it only at "
            ."dispatch time, so a green CI proves nothing about it:\n".implode("\n", $over),
        );
    }

    /**
     * In a GitHub Actions expression `cond && '' || x` ALWAYS yields x, because '' is falsy — so
     * `scope_audit == 'all' && '' || scope_audit` passed the literal «all» as a project uuid and the
     * Production inventory crashed on its first run. An empty string may never be the middle operand.
     */
    public function test_no_workflow_expression_uses_an_empty_string_as_the_true_branch(): void
    {
        foreach (glob(dirname(__DIR__, 3).'/.github/workflows/*.yml') ?: [] as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
                if (preg_match('/^\s*#/', $line) === 1) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression(
                    "/&&\s*''\s*\|\|/",
                    $line,
                    basename($path).':'.($number + 1)." uses `&& '' ||`, which always takes the right-hand value."
                );
            }
        }
    }
}
