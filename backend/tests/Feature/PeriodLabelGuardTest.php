<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * PERIOD-LABEL-001 — an export, a mail, a digest or a notification writes a period ONE way: `App\Support\PeriodLabel`.
 *
 * A line that joins two dates (or a date and a period bound) with an arrow, a dash or «..» is a hand-written period and
 * fails the build. Operator console output (`/Console/`) is not a surface a customer reads and is outside the scan;
 * the allowlist below is for NON-period pairs only, each justified.
 */
final class PeriodLabelGuardTest extends TestCase
{
    /** Directories whose text reaches a customer or leaves the product as a file. */
    private const SCAN = [
        'app/Domains/Notifications', 'app/Domains/Delivery', 'app/Domains/Reports', 'app/Domains/Funding',
        'app/Domains/Billing', 'app/Domains/Alerts', 'app/Domains/Tasks', 'app/Domains/Agents', 'resources/views',
    ];

    /** A date-ish operand: a stored date, a window bound or a from/to variable. */
    private const OPERAND = '(?:toDateString\(\)|period_(?:start|end)|window_(?:start|end|from|to)|\$from\b|\$to\b|\$start\b|\$end\b|\[\'from\'\]|\[\'to\'\]|\.from\b|\.to\b|\bperiod\.(?:from|to))';

    /** @var list<string> «relative/path.php:needle» — a line holding the needle is skipped, and the reason is stated. */
    private const ALLOW = [
        // The launch-stage pair of a «launch_advance» alert: `from`/`to` are STAGE names («learning → scaling»), not dates.
        'app/Domains/Notifications/Support/AlertRuleCopy.php:ctx[\'from\']',
        // The digest's bar-chart axis ticks: day-of-month numbers of each bucket («5–11»), a label for one bar, not a period.
        'app/Domains/Notifications/Support/OperationalDigestModel.php:\'label\' => $b[\'from\']',
        // A currency PAIR («EUR → SAR —»): `from`/`to` are currency codes, not dates.
        'app/Domains/Notifications/External/Content/ReportView.php:$f[\'from\'].\' → \'.$f[\'to\']',
    ];

    public function test_no_export_or_notification_writes_a_period_by_hand(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2);

        foreach (self::SCAN as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = str_replace('\\', '/', (string) $file);
                if (! preg_match('/\.php$/', $path) || str_contains($path, '/Console/')) {
                    continue;
                }
                $rel = ltrim(substr($path, strlen($root)), '/');
                foreach ((array) file($path) as $i => $line) {
                    if (self::isHandWrittenPeriod((string) $line) && ! self::allowed($rel, (string) $line)) {
                        $offenders[] = $rel.':'.($i + 1).'  '.trim((string) $line);
                    }
                }
            }
        }

        self::assertSame([], $offenders, "A period must be written by App\\Support\\PeriodLabel, not by hand:\n".implode("\n", $offenders));
    }

    public function test_the_guard_itself_catches_a_hand_written_period(): void
    {
        foreach ([
            "\$p = \$pack->period_start->toDateString().' → '.\$pack->period_end->toDateString();",
            '{{ $invoice->period_start }} → {{ $invoice->period_end }}',
            '$period = "{$from} → {$to}";',
            "\$x = (\$data['from'] ?? 'start').' .. '.(\$data['to'] ?? \$today);",
            "return \$start.' — '.\$end;",
            "\$s = \$report->period_start . ' – ' . \$report->period_end;",
        ] as $line) {
            self::assertTrue(self::isHandWrittenPeriod($line), $line);
        }
        foreach ([
            "\$p = PeriodLabel::range(\$pack->period_start, \$pack->period_end, 'ar');",
            "'name' => \$a.' — '.\$b,",
            "\$this->line('  window: '.\$x);",
        ] as $line) {
            self::assertFalse(self::isHandWrittenPeriod($line), $line);
        }
    }

    private static function isHandWrittenPeriod(string $line): bool
    {
        $t = ltrim($line);
        if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '{{--')) {
            return false;
        }
        $o = self::OPERAND;

        return preg_match('/'.$o.'[^\n]{0,60}?(?:→|–|—|\' \.\. \'|\s\.\.\s)[^\n]{0,60}?'.$o.'/u', $line) === 1;
    }

    private static function allowed(string $rel, string $line): bool
    {
        foreach (self::ALLOW as $entry) {
            [$file, $needle] = explode(':', $entry, 2);
            if ($file === $rel && str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }
}
