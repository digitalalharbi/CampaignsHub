<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External;

use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\External\Charts\ChartImages;
use App\Domains\Notifications\External\Charts\ResvgCharts;
use App\Domains\Notifications\External\Charts\TableOnlyCharts;
use App\Domains\Notifications\External\Checks\ExternalPreSendChecks;
use App\Domains\Notifications\External\Console\ExternalEventsCommand;
use App\Domains\Notifications\External\Console\ReviewSweepCommand;
use App\Domains\Notifications\External\Content\ExternalMessageComposer;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use App\Domains\Notifications\External\Digest\MetricsDigestDataSource;
use App\Domains\Notifications\External\Mail\ExternalMailer;
use App\Domains\Notifications\External\Mail\ExternalTransport;
use Illuminate\Support\ServiceProvider;

/**
 * NOTIF-V2-EXTERNAL-001 — plugs the external pipeline into builder M's engine through its extension points: the
 * `MessageComposer` contract (external scope), the email channel (`MailSender` hands external deliveries to
 * `ExternalMailer`), and the `notifications.presend` tag. Registered after the engine's provider.
 */
final class ExternalNotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(DigestDataSource::class, MetricsDigestDataSource::class);
        // Charts: resvg when its runtime is present, else tables (never a broken image).
        $this->app->scoped(ChartImages::class, fn () => ResvgCharts::available() ? new ResvgCharts : new TableOnlyCharts);
        $this->app->scoped(ExternalMessageComposer::class);
        $this->app->bind(MessageComposer::class, fn ($app) => $app->make(ExternalMessageComposer::class));
        $this->app->bind(ExternalTransport::class);
        $this->app->bind(ExternalMailer::class);
        $this->app->tag([ExternalPreSendChecks::class], 'notifications.presend');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReviewSweepCommand::class, ExternalEventsCommand::class]);
        }
    }
}
