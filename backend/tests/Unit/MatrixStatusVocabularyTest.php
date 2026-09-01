<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The matrix's status column admits SEVEN words, and this is what stops it admitting an eighth.
 *
 * Five private statuses had accumulated — `LIVE_VERIFIED`, `READY_FOR_CREDENTIALS`,
 * `BLOCKED_PRE_EXISTING_DEFECT`, `BLOCKED_PRODUCT_DECISION`, `NOT_A_DEFECT` — each invented in the
 * moment by somebody who found the canonical set too small for what they had just learned. Every one
 * of them was a reasonable thing to want to say. None of them was a status: a word only one row uses
 * cannot be filtered, counted or trusted, and a ledger whose vocabulary grows per author stops being
 * a ledger and becomes prose with a table around it.
 *
 * The fix is not to police the impulse but to redirect it: whatever the invented status was trying to
 * say belongs in the NOTES, where there is room to say it properly.
 *
 * **This test used to have the hole it exists to close.** Its reader matched `^\*\*([A-Z_]+)\*\*$`,
 * so it only ever saw cells that were ALREADY canonical in shape. Seventeen rows reading
 * `**VERIFIED (Awaiting Credentials)**`, `**VERIFIED LOCAL**`, `**CLOSED — see §51**` and
 * `**OPEN — next defect**` matched nothing, were skipped in silence, and the suite stayed green
 * across every one of them. A drift check that can only see the values it approves of reports
 * "no drift" for the entire class of drift that actually happens — a canonical word with a qualifier
 * stapled on, which reads as canonical at a glance and is exactly what an author reaches for.
 *
 * The reader now recognises a status cell by its SHAPE — wholly bold, short, and claiming one of the
 * status words — and then insists it be exactly one of the seven. `test_the_reader_sees_a_status_it_
 * does_not_approve_of` is what keeps that honest.
 *
 * A plain PHPUnit test, not a Laravel one: it reads a file and needs no application.
 */
final class MatrixStatusVocabularyTest extends TestCase
{
    /** The only statuses the matrix admits. Changing this list is a deliberate act, not a fix. */
    private const CANONICAL = [
        'NOT_STARTED',
        'IN_PROGRESS',
        'PARTIAL',
        'IMPLEMENTED_NOT_VERIFIED',
        'VERIFIED',
        'BLOCKED_EXTERNAL_CREDENTIALS',
        'BLOCKED_OPERATIONAL_EVIDENCE',
    ];

    /**
     * The requirement ids registered from the owner's three packages of 2026-08-30/31.
     *
     * @var list<string>
     */
    private const REGISTERED = [
        'TABLE-PRESENTATION-CONTRACT-001',
        'ADSET-METRICS-TRUTH-001',
        'CONTENT-TERMINOLOGY-001',
        'CONTENT-PREVIEW-SHAPES-001',
        'CONTENT-DETAIL-MODAL-001',
        'INSUFFICIENT-DATA-EXPLAINED-001',
        'STORE-TABLE-PRESENTATION-001',
        'ANALYTICS-DIFFERENTIATION-001',
        'BUDGET-ALERT-EMAIL-001',
        'MONEY-SCOPE-TRUTH-001',
        'REPORT-PRODUCT-MODEL-001',
        'REPORT-DETAIL-PARITY-001',
        'REPORT-CREATION-UX-001',
        'REPORT-INTERACTION-PARITY-001',
        'BRANDING-RENDER-EVIDENCE-001',
        'PRODUCTION-TRUTH-AUDIT-001',
        'LEAD-OPERATIONS-001',
        'TEAM-PROJECT-RBAC-001',
        'EXECUTIVE-DAILY-DIGEST-001',
        'LEAD-SOURCE-ATTRIBUTION-001',
        'CAMPAIGN-OUTCOME-DIMENSION-001',
        'LEAD-SLA-NOTIFICATION-001',
        'EXECUTIVE-OPS-DASHBOARD-001',
        'WHATSAPP-CONVERSATION-SOURCE-001',
        'GOVERNANCE-ANTILOSS-001',
    ];

    public function test_every_row_states_a_canonical_status(): void
    {
        $offenders = [];

        foreach (self::statusCells(file($this->matrixPath())) as [$id, $status, $trailing]) {
            if (! in_array($status, self::CANONICAL, true)) {
                $offenders[] = "{$id} → {$status}";

                continue;
            }

            /*
             * And a canonical word does not make the cell canonical: `**VERIFIED** — *Awaiting
             * Credentials*` qualifies the status from OUTSIDE the bold, which filters as VERIFIED
             * and reads as something else. Evidence may trail a status; a second opinion about it
             * may not.
             */
            if (preg_match('/\b(AWAITING|PENDING|LOCAL|READY|BLOCKED|PARTIAL|CLOSED|NOT VERIFIED)\b/i', $trailing) === 1) {
                $offenders[] = "{$id} → {$status} {$trailing}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The matrix admits only '.implode(' · ', self::CANONICAL).".\n"
            ."Whatever these were trying to say belongs in the notes:\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * The reader is checking something.
     *
     * A parser that silently matched nothing would make the test above pass forever — which is the
     * same failure mode as the vocabulary drift it exists to catch, one level up.
     */
    public function test_the_reader_actually_finds_the_statuses(): void
    {
        $this->assertGreaterThan(400, count(self::statusCells(file($this->matrixPath()))));
    }

    /**
     * And it sees a status it does NOT approve of — the whole point, and the part that was missing.
     *
     * Fed against the real file this proves nothing, because the real file is clean by the time this
     * runs. So it is fed the four shapes that actually got through, in the two table widths the
     * document uses (nine columns, and the narrower five-column tables where the status sits in a
     * different place). Every one must come back named.
     */
    public function test_the_reader_sees_a_status_it_does_not_approve_of(): void
    {
        $drifted = [
            '| Req ID | Area | Requirement | Backend | Frontend | Tests | Status | Commit | Notes |',
            '|---|---|---|---|---|---|---|---|---|',
            '| A-001 | AREA | Requirement | ✓ | ✓ | tests | **VERIFIED (Awaiting Credentials)** | abc1234 | note |',
            '| A-002 | AREA | Requirement | ✓ | ✓ | tests | **VERIFIED LOCAL** | abc1234 | note |',
            '| A-003 | AREA | Requirement | — | — | — | **CLOSED — see §51** | abc1234 | note |',
            '| A-004 | AREA | Requirement | — | — | — | **OPEN — next defect** | — | note |',
            '',
            '| Req ID | Requirement | Status | Evidence | Notes |',
            '|---|---|---|---|---|',
            '| A-005 | A five-column table, where the status sits somewhere else | **READY_FOR_CREDENTIALS** | ev | note |',
        ];

        $found = self::statusCells($drifted);

        $this->assertSame(
            ['A-001', 'A-002', 'A-003', 'A-004', 'A-005'],
            array_column($found, 0),
            'a drifted status was skipped instead of reported — the reader is blind in exactly the way it was before',
        );

        foreach ($found as [$id, $status]) {
            $this->assertNotContains($status, self::CANONICAL, "{$id} was read as canonical when it is not");
        }
    }

    /** A status qualified from outside its bold is drift too, and reads as canonical to a filter. */
    public function test_a_qualifier_outside_the_bold_is_reported(): void
    {
        $rows = [
            '| Req ID | Requirement | Status | Notes |',
            '|---|---|---|---|',
            '| C-001 | Qualified from outside | **VERIFIED** — *Awaiting Credentials* | note |',
            '| C-002 | Evidence, not a qualifier | **VERIFIED** (13 tests) | note |',
        ];

        $found = array_column(self::statusCells($rows), 2, 0);

        $this->assertSame('— *Awaiting Credentials*', $found['C-001']);
        $this->assertSame('(13 tests)', $found['C-002']);
    }

    /** And it does not mistake a bold phrase inside a NOTE for a status. */
    public function test_a_bold_phrase_in_a_note_is_not_read_as_a_status(): void
    {
        $rows = [
            '| Req ID | Area | Requirement | Backend | Frontend | Tests | Status | Commit | Notes |',
            '|---|---|---|---|---|---|---|---|---|',
            '| B-001 | AREA | Requirement | ✓ | ✓ | tests | **VERIFIED** | abc1234 | '
                .'**Recorded as a question, not asserted as a defect.** The row above was verified; this one repeats '
                .'the word verified at length so that a reader-by-keyword would trip over it. |',
        ];

        $this->assertSame([['B-001', 'VERIFIED', '']], self::statusCells($rows));
    }

    /**
     * Status cells, located by the table's own header rather than by what the cell says.
     *
     * The previous reader matched `^\*\*([A-Z_]+)\*\*$` and therefore only ever saw cells that were
     * already canonical in shape; seventeen qualifier-stapled statuses were skipped in silence. The
     * obvious repair — look for cells CONTAINING a status word — is only half a repair, and its own
     * hole is visible in the fixture above: `READY_FOR_CREDENTIALS` shares no word with any of the
     * seven, so a keyword reader walks straight past it. An invented status is precisely the case
     * where you cannot predict the vocabulary.
     *
     * So the column is found from the header — every table in this document declares `Status` — and
     * whatever stands in that column is the status, whatever it happens to say.
     *
     * @param  list<string>  $lines
     * @return list<array{0: string, 1: string, 2: string}> id, status, and whatever the cell says after it
     */
    private static function statusCells(array $lines): array
    {
        $out = [];
        $width = null;
        $column = null;

        foreach ($lines as $line) {
            if (! str_starts_with($line, '| ')) {
                /*
                 * Anything that is not a table row ends the table, and with it the column it
                 * declared — a BLANK LINE included: §SIGNUP-006 puts a three-column `Portal | Account
                 * | Notes` table directly under a three-column status table, and a reader that
                 * carried the column across the gap read «Notes» as a status.
                 *
                 * The `|---|---|` separator is the one exception; it is part of the table, and
                 * forgetting that is how this reader first came back empty for every row.
                 */
                if (! str_starts_with(trim($line), '|')) {
                    $width = $column = null;
                }

                continue;
            }

            /*
             * Unescaped pipes only. A cell may legitimately contain `\|` — the filter contract row
             * writes «الفترة \| المشروع \| المنصة» — and a reader that splits on it counts sixteen
             * cells in a nine-column row, fails the width check, and skips the row in silence. Which
             * is how a committed merge conflict, two duplicated rows and thirteen malformed rows
             * lived in this file with the suite green.
             */
            $cells = preg_split('/(?<!\\\\)\|/', $line);
            $trimmed = array_map(trim(...), $cells);

            if (in_array('Status', $trimmed, true)) {
                $width = count($cells);
                $column = array_search('Status', $trimmed, true);

                continue;
            }

            if ($column === null || count($cells) !== $width) {
                continue;
            }

            $id = $trimmed[1];

            if (preg_match('/^[A-Z][A-Za-z0-9._-]*$/', $id) !== 1) {
                continue;
            }

            $value = $trimmed[$column];

            if ($value === '' || $value === '—') {
                continue;
            }

            /*
             * Many rows write the status and then, in the same cell, the evidence for it:
             * `**VERIFIED** (13 tests)`. That is untidy, not drift — the status word itself is
             * canonical and a reader filtering on it finds the row. So the status is the leading
             * bold token, and what follows is returned separately for the caller to judge.
             */
            if (preg_match('/^\*\*(.+?)\*\*(.*)$/s', $value, $m) === 1) {
                $out[] = [$id, trim($m[1]), trim($m[2])];

                continue;
            }

            $out[] = [$id, $value, ''];
        }

        return $out;
    }

    /**
     * GOVERNANCE-ANTILOSS-001 — a row whose width does not match its own header.
     *
     * This is the hole every other check in this file was falling through. The reader locates the
     * status by COLUMN, so it can only read a row that has the header's number of columns; a row
     * with one column too many was not reported as malformed, it was skipped — and a skipped row is
     * a requirement nobody is checking. Thirteen rows in the main table had accumulated an extra
     * column each, because an author updating a Remaining gap appended a new cell instead of
     * replacing the old one, and Markdown renders that as a fourteenth column nobody notices in a
     * table this wide.
     */
    public function test_every_row_is_as_wide_as_its_own_header(): void
    {
        $offenders = [];

        foreach (self::tables(file($this->matrixPath())) as $table) {
            foreach ($table['rows'] as [$line, $cells]) {
                if (count($cells) !== $table['width']) {
                    $id = trim($cells[1] ?? '?');
                    $offenders[] = "{$id} → ".count($cells).' columns, header has '.$table['width'];
                }
            }
        }

        $this->assertSame([], $offenders, "A row that does not match its header is skipped by every reader here.\n  ".implode("\n  ", $offenders));
    }

    /**
     * One id, one row, inside one table.
     *
     * Not across the DOCUMENT: the dated sections below the main table are progress records, and a
     * requirement legitimately appears again in the section that closed it. Twice in the SAME table
     * is the failure — two rows with the same id and different statuses, which is what a merge
     * conflict resolved by keeping both sides produces, and which leaves the reader no way to know
     * which one is true.
     */
    public function test_no_table_carries_the_same_requirement_twice(): void
    {
        $offenders = [];

        foreach (self::tables(file($this->matrixPath())) as $table) {
            $seen = [];

            foreach ($table['rows'] as [$line, $cells]) {
                $id = trim($cells[1] ?? '');

                if (preg_match('/^[A-Z][A-Za-z0-9._-]*$/', $id) !== 1) {
                    continue;
                }

                if (isset($seen[$id])) {
                    $offenders[] = $id;
                }

                $seen[$id] = true;
            }
        }

        $this->assertSame([], $offenders, 'the same requirement is stated twice in one table: '.implode(', ', $offenders));
    }

    /**
     * A row that is not finished says what is left.
     *
     * An empty Remaining gap on an unfinished row is how a requirement quietly stops being work:
     * it keeps its status, nobody can act on it, and the next reader assumes somebody else knows.
     * VERIFIED and the two blocked statuses are exempt — there is nothing remaining, or what remains
     * is stated as the blocker.
     */
    public function test_an_unfinished_row_says_what_is_remaining(): void
    {
        $done = ['VERIFIED', 'BLOCKED_EXTERNAL_CREDENTIALS', 'BLOCKED_OPERATIONAL_EVIDENCE'];
        $offenders = [];

        foreach (self::tables(file($this->matrixPath())) as $table) {
            if ($table['status'] === null || $table['gap'] === null) {
                continue;
            }

            foreach ($table['rows'] as [$line, $cells]) {
                $id = trim($cells[1] ?? '');
                $status = trim(str_replace('*', '', $cells[$table['status']] ?? ''));

                if (preg_match('/^[A-Z][A-Za-z0-9._-]*$/', $id) !== 1 || in_array($status, $done, true)) {
                    continue;
                }

                if (in_array(trim($cells[$table['gap']] ?? ''), ['', '—', '-'], true)) {
                    $offenders[] = "{$id} ({$status})";
                }
            }
        }

        $this->assertSame([], $offenders, "these rows are unfinished and say nothing about what is left:\n  ".implode("\n  ", $offenders));
    }

    /** A merge resolved by committing both sides, which happened, and cost two rows. */
    public function test_the_ledger_carries_no_unresolved_conflict(): void
    {
        foreach ([$this->matrixPath(), $this->docPath('RESUME_STATE.md'), $this->docPath('ACTIVE_EXECUTION_STATE.md')] as $path) {
            foreach (file($path) as $n => $line) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^(<{7}|={7}$|>{7})/',
                    rtrim($line),
                    basename($path).' line '.($n + 1).' is an unresolved merge conflict',
                );
            }
        }
    }

    /**
     * The requirement families the owner registered in §56 are still here.
     *
     * Named one by one rather than counted, because a count survives a rename and a substitution.
     * These arrived as three packages in a single session and existed nowhere but a chat window
     * until they were written down; a row removed by a bad rebase would be undetectable otherwise.
     * Removing one is allowed — by superseding it in writing, which means the id still appears.
     */
    public function test_the_registered_requirement_families_are_still_recorded(): void
    {
        $matrix = file_get_contents($this->matrixPath());

        foreach (self::REGISTERED as $id) {
            $this->assertStringContainsString(
                "| {$id} |",
                $matrix,
                "{$id} was registered by the owner and no longer has a row. If it was superseded, the supersession must name it.",
            );
        }
    }

    /**
     * The resume document names the packages, so a session that starts cold knows they bind.
     *
     * RESUME_STATE is not a second matrix and is not asserted row by row. What it must not do is
     * lose the fact that these families exist — a fresh session reads it first, and what it does not
     * mention is work nobody will pick up.
     */
    public function test_the_resume_state_names_the_binding_packages(): void
    {
        $resume = file_get_contents($this->docPath('RESUME_STATE.md'));

        foreach (['LEAD-OPERATIONS-001', 'TEAM-PROJECT-RBAC-001', 'EXECUTIVE-DAILY-DIGEST-001', 'LEAD-SOURCE-ATTRIBUTION-001', 'PRODUCTION-TRUTH-AUDIT-001', 'REPORT-PRODUCT-MODEL-001'] as $id) {
            $this->assertStringContainsString($id, $resume, "RESUME_STATE must name {$id} or a cold session will not know it is binding");
        }
    }

    /**
     * And the execution state cannot report an empty queue while the ledger still has work.
     *
     * The failure this prevents is a control plane that reads «all clear» because its own list is
     * short — the queue is a view of the matrix, not an alternative to it.
     */
    public function test_the_execution_state_cannot_claim_an_empty_queue(): void
    {
        $executable = 0;

        foreach (self::statusCells(file($this->matrixPath())) as [$id, $status]) {
            if (in_array($status, ['NOT_STARTED', 'IN_PROGRESS', 'PARTIAL', 'IMPLEMENTED_NOT_VERIFIED'], true)) {
                $executable++;
            }
        }

        if ($executable === 0) {
            $this->markTestSkipped('nothing executable remains; the claim would be true');
        }

        $state = file_get_contents($this->docPath('ACTIVE_EXECUTION_STATE.md'));

        $this->assertDoesNotMatchRegularExpression(
            '/\b(queue (is )?(complete|empty|drained)|nothing (remains|left) to (do|ship)|all requirements (are )?(complete|shipped))\b/i',
            $state,
            "ACTIVE_EXECUTION_STATE claims the work is done while {$executable} matrix rows are still executable",
        );
    }

    /**
     * GOVERNANCE-ANTILOSS-001 — a row's evidence names a test that exists.
     *
     * The status column says whether a requirement is met. The EVIDENCE column says how anybody
     * could check, and it is the half that rots silently: a test gets renamed in a refactor, the row
     * keeps the old name, and the row still reads VERIFIED. Nothing fails, because nothing was ever
     * checking that the named file was real — the name is prose to every reader and to every tool.
     *
     * The sweep that added this found `LIFECYCLE-001` citing `AccountInventoryPanel.test.tsx`, gone
     * since the panel was rewritten, on a row marked VERIFIED for a four-state model the product had
     * deliberately reduced to two. One stale name, and behind it a requirement that had stopped being
     * true.
     *
     * Only names that are unambiguously tests are checked — a PHP test class, a Playwright spec, a
     * vitest file. A backticked column name or method is not a file and is not asked to be one.
     */
    public function test_every_named_test_in_the_evidence_column_exists(): void
    {
        $present = self::knownTestFiles();
        $offenders = [];

        foreach (self::evidenceCells(file($this->matrixPath())) as [$id, $cell]) {
            preg_match_all('/`([^`]+)`/', $cell, $found);

            foreach ($found[1] ?? [] as $name) {
                $name = trim($name);

                if (preg_match('/^[A-Za-z0-9_.\/-]+$/', $name) !== 1) {
                    continue;
                }

                $isTest = str_ends_with($name, 'Test')
                    || str_ends_with($name, '.spec.ts')
                    || str_ends_with($name, '.test.ts')
                    || str_ends_with($name, '.test.tsx');

                if (! $isTest) {
                    continue;
                }

                $base = basename($name);

                if (isset($present[$base]) || isset($present[$base.'.php'])) {
                    continue;
                }

                $offenders[] = "{$id} → {$name}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these rows cite evidence that does not exist. Rename the reference, or say what replaced it:\n  "
            .implode("\n  ", array_unique($offenders)),
        );
    }

    /**
     * The reader finds evidence to check.
     *
     * A sweep that silently matched nothing would pass forever, which is the same failure mode as
     * the drift it exists to catch — the lesson this file already learned once about statuses.
     */
    public function test_the_evidence_reader_actually_finds_named_tests(): void
    {
        $named = 0;

        foreach (self::evidenceCells(file($this->matrixPath())) as [, $cell]) {
            $named += preg_match_all('/`[A-Za-z0-9_.\/-]*(Test|\.spec\.ts|\.test\.tsx?)`/', $cell);
        }

        $this->assertGreaterThan(200, $named, 'the evidence reader is not reading the evidence column');
    }

    /**
     * Every test file in the repository, by basename.
     *
     * @return array<string, true>
     */
    private static function knownTestFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $names = [];

        foreach (['backend/tests', 'frontend/src', 'frontend/e2e'] as $dir) {
            $path = $root.'/'.$dir;

            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $names[$file->getFilename()] = true;
            }
        }

        return $names;
    }

    /**
     * The evidence cell of every row, located by its own table's header.
     *
     * @param  list<string>  $lines
     * @return list<array{0: string, 1: string}>
     */
    private static function evidenceCells(array $lines): array
    {
        $out = [];
        $width = null;
        $columns = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (! str_starts_with($line, '| ')) {
                if (! str_starts_with(trim($line), '|')) {
                    $width = null;
                    $columns = [];
                }

                continue;
            }

            $cells = preg_split('/(?<!\\\\)\|/', $line);
            $trimmed = array_map(trim(...), $cells);

            if (in_array($trimmed[1] ?? '', ['ID', 'Req ID'], true)) {
                $width = count($cells);
                $columns = [];

                foreach (['Test', 'Tests', 'Evidence'] as $header) {
                    $at = array_search($header, $trimmed, true);

                    if ($at !== false) {
                        $columns[] = $at;
                    }
                }

                continue;
            }

            if ($columns === [] || count($cells) !== $width) {
                continue;
            }

            $id = $trimmed[1];

            if (preg_match('/^[A-Z][A-Za-z0-9._-]*$/', $id) !== 1) {
                continue;
            }

            foreach ($columns as $at) {
                $out[] = [$id, $trimmed[$at] ?? ''];
            }
        }

        return $out;
    }

    /**
     * Tables, each with its own header width and the columns it declares.
     *
     * @param  list<string>  $lines
     * @return list<array{width: int, status: int|null, gap: int|null, rows: list<array{0: string, 1: list<string>}>}>
     */
    private static function tables(array $lines): array
    {
        $tables = [];
        $current = null;

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (! str_starts_with($line, '| ')) {
                if (! str_starts_with(trim($line), '|') && $current !== null) {
                    $tables[] = $current;
                    $current = null;
                }

                continue;
            }

            $cells = preg_split('/(?<!\\\\)\|/', $line);
            $trimmed = array_map(trim(...), $cells);

            /*
             * A header, and therefore a new table. Recognised by declaring an id column — every
             * table here opens with `ID` or `Req ID` — so a data row cannot be mistaken for one.
             */
            if (in_array($trimmed[1] ?? '', ['ID', 'Req ID'], true)) {
                if ($current !== null) {
                    $tables[] = $current;
                }

                $gap = array_search('Remaining gap', $trimmed, true);
                $status = array_search('Status', $trimmed, true);

                $current = [
                    'width' => count($cells),
                    'status' => $status === false ? null : $status,
                    'gap' => $gap === false ? null : $gap,
                    'rows' => [],
                ];

                continue;
            }

            if ($current === null || preg_match('/^\|[\s:|-]+\|$/', $line) === 1) {
                continue;
            }

            $current['rows'][] = [$line, $cells];
        }

        if ($current !== null) {
            $tables[] = $current;
        }

        return $tables;
    }

    private function docPath(string $name): string
    {
        $path = dirname(__DIR__, 3).'/docs/'.$name;
        $this->assertFileExists($path);

        return $path;
    }

    private function matrixPath(): string
    {
        $path = dirname(__DIR__, 3).'/docs/REQUIREMENTS_TRACEABILITY_MATRIX.md';
        $this->assertFileExists($path);

        return $path;
    }
}
