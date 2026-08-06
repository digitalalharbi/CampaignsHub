<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Models\ReportRecipient;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\DemoClientPortalSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/** php artisan demo:remove — delete ONLY demo analytics data (is_demo rows + the demo store workspace). */
final class DemoRemoveCommand extends Command
{
    protected $signature = 'demo:remove';

    protected $description = 'Remove demo analytics data only (is_demo metrics/runs + demo store). Never touches real data.';

    public function handle(): int
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            $this->error('demo:remove is disabled outside local/testing/demo.');

            return self::FAILURE;
        }

        $metrics = DailyMetric::withoutGlobalScopes()->where('is_demo', true)->delete();
        $runs = MetricSyncRun::withoutGlobalScopes()->where('is_demo', true)->delete();

        // Delete demo report export files, then the rows (reports themselves also cascade with the
        // demo workspace below, but this clears exports/schedules/recipients + any stray is_demo rows).
        foreach (ReportExport::withoutGlobalScopes()->where('is_demo', true)->get() as $ex) {
            if ($ex->path) {
                Storage::disk($ex->disk)->delete($ex->path);
            }
        }
        ReportExport::withoutGlobalScopes()->where('is_demo', true)->delete();
        ReportRecipient::withoutGlobalScopes()->where('is_demo', true)->delete();
        ReportSchedule::withoutGlobalScopes()->where('is_demo', true)->delete();
        $reports = Report::withoutGlobalScopes()->where('is_demo', true)->delete();

        $tenant = Tenant::where('slug', 'demo-agency')->first();
        $workspaces = 0;
        if ($tenant) {
            app(TenantContext::class)->setTenantId((string) $tenant->id);
            // Hard delete (force) so nothing lingers soft-deleted; DB cascade removes the demo
            // analytics project + its unified campaigns.
            $workspaces = ClientWorkspace::withTrashed()->where('slug', 'demo-store-analytics')->forceDelete();
            app(TenantContext::class)->forget();
        }

        $portal = $this->clientPortalDemo();

        $this->info("Removed demo: {$metrics} metrics, {$runs} sync runs, {$reports} reports, {$workspaces} workspace(s), {$portal} client-portal row(s).");

        return self::SUCCESS;
    }

    /**
     * The seeded client-portal demo — requests, quotes, invoices, conversations and their files.
     *
     * These four tables have no `is_demo` column, so the rows are matched by the reserved prefixes
     * `DemoClientPortalSeeder` writes and by nothing else. Matching on the client space instead would
     * be wrong in the one way that matters: a real quote raised against the demo client would be
     * deleted along with the demo ones. A prefix a real document cannot carry is the safer key, and
     * «عدم حذف بيانات حقيقية» is the rule being satisfied here, not merely respected.
     *
     * @return int rows removed
     */
    private function clientPortalDemo(): int
    {
        $requests = ExternalRequest::query()
            ->where('is_demo', true)
            ->where('reference', 'like', DemoClientPortalSeeder::REQUEST_PREFIX.'%')
            ->get();

        // The files first: a `request_files` row cascades with its request, but the BYTES do not.
        foreach (RequestFile::query()->whereIn('request_id', $requests->modelKeys())->get() as $file) {
            if ($file->path) {
                Storage::disk($file->disk)->delete($file->path);
            }
        }

        $threads = MessageThread::withoutGlobalScopes()
            ->whereIn('subject', DemoClientPortalSeeder::THREAD_SUBJECTS)
            ->get();

        $removed = Message::withoutGlobalScopes()->whereIn('thread_id', $threads->modelKeys())->delete()
            + MessageThread::withoutGlobalScopes()->whereIn('id', $threads->modelKeys())->delete()
            + Invoice::withoutGlobalScopes()->where('number', 'like', DemoClientPortalSeeder::INVOICE_PREFIX.'%')->delete()
            + Quote::withoutGlobalScopes()->where('number', 'like', DemoClientPortalSeeder::QUOTE_PREFIX.'%')->delete()
            + ExternalRequest::query()->whereIn('id', $requests->modelKeys())->delete();

        return (int) $removed;
    }
}
