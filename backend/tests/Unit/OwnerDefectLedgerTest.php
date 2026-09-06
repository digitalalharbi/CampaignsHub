<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The owner's observations cannot quietly leave the record.
 *
 * ## Why a ledger needs a guard at all
 *
 * `docs/OWNER_OBSERVED_DEFECTS.md` exists because an observation kept turning into an implementation
 * detail and then into a status report, while the owner was still looking at the thing they
 * reported. A document that records that risk is worth nothing if the document itself can rot: a row
 * pointing at a requirement ID that no longer exists is a broken link, and a row that closes without
 * Production evidence is the original failure wearing a tick.
 *
 * So three rules, held here rather than hoped for.
 *
 *   1. Every Matrix ID a row cites EXISTS in the Matrix. An ID that has been renamed or removed
 *      silently unhooks the observation from the requirement that owes it.
 *   2. A row may only claim `Prod verified = yes`. Merged and deployed are different columns for a
 *      reason, and «the tests pass» is none of the three.
 *   3. Rows are never removed. The count only goes up, and the file states that in words.
 */
final class OwnerDefectLedgerTest extends TestCase
{
    private const LEDGER = __DIR__.'/../../../docs/OWNER_OBSERVED_DEFECTS.md';

    private const MATRIX = __DIR__.'/../../../docs/REQUIREMENTS_TRACEABILITY_MATRIX.md';

    public function test_the_ledger_exists_and_carries_the_owners_observations(): void
    {
        $this->assertFileExists(self::LEDGER);

        /* The register was created carrying 66 observations. It may grow; it may not shrink. */
        $this->assertGreaterThanOrEqual(66, count($this->rows()), 'An owner observation has left the ledger.');
    }

    /**
     * Every cited requirement is a requirement this product actually has.
     *
     * A dangling ID is worse than no ID: it reads as «this is tracked» and points nowhere.
     */
    public function test_every_cited_requirement_exists_in_the_matrix(): void
    {
        $known = $this->matrixIds();
        $dangling = [];

        foreach ($this->rows() as $row) {
            foreach (explode(';', $row['ids']) as $id) {
                $id = trim($id);

                if ($id === '' || $id === '—' || $id === 'UNMAPPED_OWNER_DEFECT') {
                    continue;
                }

                if (! in_array($id, $known, true)) {
                    $dangling[] = "{$row['no']} → {$id}";
                }
            }
        }

        $this->assertSame([], $dangling, 'A ledger row cites a requirement the Matrix does not hold.');
    }

    /**
     * An observation closes on the running product, and on nothing else.
     *
     * `Prod verified` is the only column that closes a row, and it may not be claimed while the row
     * still names a remaining acceptance gap — that combination is precisely the report the owner
     * kept receiving while the defect was still on their screen.
     */
    public function test_a_row_cannot_be_verified_while_it_still_names_a_gap(): void
    {
        $contradictions = [];

        foreach ($this->rows() as $row) {
            $gap = trim($row['gap']);

            if (str_starts_with(strtolower(trim($row['verified'])), 'yes') && $gap !== '' && $gap !== '—') {
                $contradictions[] = "{$row['no']}: verified, yet «{$gap}»";
            }
        }

        $this->assertSame([], $contradictions);
    }

    /** The rules the file states about itself are the rules it is read by. */
    public function test_the_ledger_states_that_an_observation_is_never_deleted(): void
    {
        $text = (string) file_get_contents(self::LEDGER);

        $this->assertStringContainsString('never deleted', $text);
        $this->assertStringContainsString('Merged is not deployed', $text);
    }

    /** @return list<string> */
    private function matrixIds(): array
    {
        $ids = [];

        foreach (file(self::MATRIX) ?: [] as $line) {
            $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

            if (count($cells) === 11) {
                $id = trim($cells[1]);

                if ($id !== '' && $id !== 'ID') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** @return list<array{no:string,ids:string,verified:string,gap:string}> */
    private function rows(): array
    {
        $rows = [];

        foreach (file(self::LEDGER) ?: [] as $line) {
            $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

            /* 14 columns plus the empty strings either side of the leading and trailing pipes. */
            if (count($cells) !== 16 || ! ctype_digit(trim($cells[1]))) {
                continue;
            }

            $rows[] = [
                'no' => trim($cells[1]),
                'ids' => trim($cells[6]),
                'verified' => trim($cells[12]),
                'gap' => trim($cells[13]),
            ];
        }

        return $rows;
    }
}
