<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderDisplayName;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * WEBSITE-NAMING-001 — the Owner, 2026-11-07: «استبدل مصطلح GA4 بـ الموقع أو الهبوط أو ويب سايت».
 *
 * The provider kind stays `ga4` internally (keys, class names, env vars, comments); what a person READS says
 * «الموقع» / «Website». This scans the backend's code, views and config for the uppercase word GA4 outside
 * comments and fails on any other use, so a new string cannot bring it back; and proves the one label module
 * never prints a raw provider key for a provider the catalogue knows.
 */
final class NoGa4WordInUserVisibleStringsTest extends TestCase
{
    public function test_the_word_ga4_is_not_in_any_string_a_person_can_read(): void
    {
        $offences = [];
        foreach (['app', 'resources', 'config', 'routes'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = (string) $file;
                if (! preg_match('/\.(php|blade\.php)$/', $path)) {
                    continue;
                }
                $code = (string) file_get_contents($path);
                $code = (string) preg_replace('~/\*.*?\*/~s', '', $code);
                $code = (string) preg_replace('~\{\{--.*?--\}\}~s', '', $code);
                foreach (explode("\n", $code) as $n => $line) {
                    $trim = ltrim($line);
                    if (str_starts_with($trim, '//') || str_starts_with($trim, '#')) {
                        continue;
                    }
                    if (preg_match('/(?<!::)(?<!const )GA4(?![_A-Za-z0-9])/', $line) === 1) {
                        $offences[] = substr($path, strlen(base_path()) + 1).':'.($n + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offences, 'GA4 must read «الموقع» / «Website» (or «Google Analytics» where an account is connected)');
    }

    public function test_the_label_module_names_the_website_and_never_prints_a_raw_provider_key(): void
    {
        $this->assertSame('الموقع', ProviderDisplayName::of('ga4', 'ar'));
        $this->assertSame('Website', ProviderDisplayName::of('ga4', 'en'));
        $this->assertSame('Website', ProviderDisplayName::short('GA4', 'en'));

        foreach (ProviderCatalogue::all() as $definition) {
            foreach (['ar', 'en'] as $locale) {
                $name = ProviderDisplayName::of($definition->key, $locale);
                $this->assertNotSame('', $name);
                $this->assertNotSame($definition->key, $name, "{$definition->key} printed raw in {$locale}");
                $this->assertStringNotContainsString('GA4', $definition->label);
                $this->assertStringNotContainsString('GA4', $definition->labelAr);
            }
        }
    }

    /**
     * REPORT-WEBSITE-GAP-READING-001 (Owner 2026-11-07) — «بشكل صارم لا تُظهر المصدر للبيانات». A report surface (the live link, the saved
     * report and its files, the digest, the alerts, the measurement readings they draw) names no measurement tool. The connection
     * screens (integrations, settings, forms' conversion destinations) are where an account is linked and may say «Google Analytics».
     */
    public function test_no_report_surface_names_the_measurement_tool(): void
    {
        $offences = [];
        foreach (['app/Domains/Reports', 'app/Domains/Notifications', 'app/Domains/Alerts', 'app/Domains/Integrations/Measurement', 'resources/views/mail'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = (string) $file;
                if (! str_ends_with($path, '.php')) {
                    continue;
                }
                $code = (string) preg_replace(['~/\*.*?\*/~s', '~\{\{--.*?--\}\}~s'], '', (string) file_get_contents($path));
                foreach (explode("\n", $code) as $n => $line) {
                    $trim = ltrim($line);
                    if (str_starts_with($trim, '//') || str_starts_with($trim, '#')) {
                        continue;
                    }
                    if (preg_match('/Google Analytics|جوجل أناليتكس|analytics\.google\.com|Google[’\']?s?\s+(reports?|updates?|restates?|data|tag|search console)|(تقارير|بيانات|تحديثات)\s+(Google|جوجل|غوغل)/iu', $line) === 1) {
                        $offences[] = substr($path, strlen(base_path()) + 1).':'.($n + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offences, 'a report surface names the measurement tool: say «الموقع» / «Website»');
    }
}
