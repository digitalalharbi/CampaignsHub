<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\ReviewAccess;
use App\Domains\Tenancy\Support\ReviewAccountProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * SALLA-REVIEW-001 — create, extend or revoke the Salla app-store review workspace.
 *
 *   php artisan salla:review                 provision (or refresh) it; the password comes from SALLA_REVIEW_PASSWORD
 *   php artisan salla:review --extend=7      push the expiry out seven days from now (or from the current expiry if later)
 *   php artisan salla:review --revoke        end the access immediately
 *
 * The password is read from the environment and is never an argument, never stored in the repository, never
 * logged and never printed. Without it, provisioning refuses.
 */
final class SallaReviewCommand extends Command
{
    protected $signature = 'salla:review {--extend= : Extend the access by this many days} {--revoke : End the access now}';

    protected $description = 'Provision, extend or revoke the restricted Salla review workspace (review@campaignshub.io)';

    public function handle(ReviewAccountProvisioner $provisioner): int
    {
        $tenant = Tenant::query()->where('slug', ReviewAccountProvisioner::SLUG)->first();

        if ($this->option('revoke') || $this->option('extend') !== null) {
            if ($tenant === null) {
                $this->error('The review workspace does not exist yet.');

                return self::FAILURE;
            }
            $settings = (array) ($tenant->settings ?? []);
            $review = (array) ($settings['review'] ?? []);

            if ($this->option('revoke')) {
                $review['active'] = false;
                $this->info('Review access revoked.');
            } else {
                $days = max(1, (int) $this->option('extend'));
                $from = isset($review['expires_at']) && Carbon::parse((string) $review['expires_at'])->isFuture() ? Carbon::parse((string) $review['expires_at']) : Carbon::now();
                $review['active'] = true;
                $review['expires_at'] = $from->addDays($days)->toIso8601String();
                $this->info('Review access extended to '.$review['expires_at'].'.');
            }
            $settings['review'] = $review;
            $tenant->forceFill(['settings' => $settings])->save();

            return self::SUCCESS;
        }

        // Read through config, never env(): with the configuration cached (production) env() answers null outside config files.
        $password = (string) config('app.salla_review_password', '');
        if ($password === '') {
            $this->error('SALLA_REVIEW_PASSWORD is not set, so the review account cannot be provisioned.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->provision($password);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(($result['created'] ? 'created' : 'refreshed').": tenant {$result['tenant']->id} ({$result['tenant']->slug}), owner ".ReviewAccountProvisioner::EMAIL);
        $this->line('access expires: '.$result['expires_at'].' (live: '.(ReviewAccess::isLive($result['tenant']) ? 'yes' : 'no').')');

        return self::SUCCESS;
    }
}
