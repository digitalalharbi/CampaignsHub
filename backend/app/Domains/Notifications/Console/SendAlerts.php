<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Services\AlertDispatcher;
use App\Domains\Notifications\Services\NotificationAudience;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The alert sweep — MAIL-006.
 *
 * Runs several times a day rather than hourly: an alert is «this needs a decision», and the
 * decisions this product surfaces — a budget running ahead of plan, a cost per result climbing, a
 * source that stopped syncing — do not change between nine and ten in the morning. Four sweeps a day
 * is soon enough to act on and rare enough not to be noise.
 *
 * Opt-in, like the digests: a preference row with `alerts` off is never swept. An opt-in that
 * defaults to on is not an opt-in, and the first scheduled run after a deploy is the worst possible
 * moment to discover that.
 */
final class SendAlerts extends Command
{
    /**
     * The same vocabulary the preference screen and the recipient screen use.
     *
     * It was a literal here until MAIL-011, alongside two other copies. `MessageCatalogue` is the one
     * list now — the categories a manager may actually arrange somebody into, which is narrower than
     * every category a person can switch: nobody arranges a colleague into «الحساب».
     */
    private const CATEGORIES = MessageCatalogue::ARRANGEABLE;

    protected $signature = 'notifications:send-alerts
        {--user= : Sweep one user id only — for verifying a change without mailing an account}
        {--date= : The day to examine, YYYY-MM-DD. Defaults to today}';

    protected $description = 'Send immediate alerts for findings that should not wait for tomorrow’s digest.';

    public function handle(AlertDispatcher $alerts): int
    {
        $day = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $rows = DB::table('notification_preferences')
            ->whereNull('client_workspace_id')
            ->when($this->option('user') !== null, fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            if (! $this->wantsAlerts($row)) {
                continue;
            }

            $user = User::query()->find($row->user_id);
            if ($user === null || $user->email === null) {
                continue;
            }

            $counts = $alerts->sweep($user, (string) $row->tenant_id, $day, (string) ($row->locale ?? 'ar'));
            foreach ($counts as $state => $n) {
                $totals[$state] = ($totals[$state] ?? 0) + $n;
            }
        }

        foreach ($this->arranged($rows) as $arrangement) {
            $counts = $alerts->sweep(
                $arrangement['user'],
                $arrangement['tenant_id'],
                $day,
                $arrangement['locale'],
                $arrangement['project_ids'],
                $arrangement['categories'],
            );
            foreach ($counts as $state => $n) {
                $totals[$state] = ($totals[$state] ?? 0) + $n;
            }
        }

        $this->info('alerts '.json_encode($totals));

        return self::SUCCESS;
    }

    /**
     * People a MANAGER asked to have told, who did not ask for themselves — MAIL-010.
     *
     * ## Why they are swept separately rather than merged into the loop above
     *
     * The two are different questions. A preference row is somebody saying «send me alerts», and it
     * is unrestricted: everything they can reach. An arrangement is somebody else saying «tell them
     * about this client», and it is bounded by whatever the arrangement named.
     *
     * Anyone who appears in BOTH is skipped here: they already received the unrestricted sweep, and
     * sweeping them again would produce nothing new (the ledger's unique index refuses the second
     * claim) at the cost of a second pass over the same digest.
     *
     * ## The arrangement narrows and cannot widen
     *
     * A NULL `project_id` or `category` means «everything» — which, after `AlertDispatcher`
     * intersects with the person's own ceiling, means everything THEY can reach. The narrowing lists
     * are built here; the guarantee is enforced there.
     *
     * @param  Collection<int,object>  $preferenceRows
     * @return list<array{user: User, tenant_id: string, locale: string, project_ids: ?list<string>, categories: ?list<string>}>
     */
    private function arranged($preferenceRows): array
    {
        $alreadySwept = $preferenceRows
            ->filter(fn ($row): bool => $this->wantsAlerts($row))
            ->map(fn ($row): string => $row->tenant_id.':'.$row->user_id)
            ->all();

        $rows = DB::table('notification_recipients')
            ->when($this->option('user') !== null, fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->get();

        /** @var array<string, array{tenant_id: string, user_id: int, project_ids: ?list<string>, categories: ?list<string>}> $byPerson */
        $byPerson = [];

        foreach ($rows as $row) {
            $key = $row->tenant_id.':'.$row->user_id;
            if (in_array($key, $alreadySwept, true)) {
                continue;
            }

            $existing = $byPerson[$key] ?? [
                'tenant_id' => (string) $row->tenant_id,
                'user_id' => (int) $row->user_id,
                'project_ids' => [],
                'categories' => [],
            ];

            // NULL wins and stays won: one blanket row means the person's own ceiling is the limit,
            // and a narrower row alongside it must not claw that back.
            $existing['project_ids'] = $row->project_id === null || $existing['project_ids'] === null
                ? null
                : array_values(array_unique([...$existing['project_ids'], (string) $row->project_id]));

            $existing['categories'] = $row->category === null || $existing['categories'] === null
                ? null
                : array_values(array_unique([...$existing['categories'], (string) $row->category]));

            $byPerson[$key] = $existing;
        }

        $audience = app(NotificationAudience::class);
        $out = [];

        foreach ($byPerson as $entry) {
            $user = User::query()->find($entry['user_id']);
            if ($user === null || $user->email === null) {
                continue;
            }

            /*
             * The recipient's own switches, applied to the manager's arrangement.
             *
             * Without this the arrangement would OVERRIDE somebody's preferences: `AlertDispatcher`
             * resolves recipients from arrangements and never reads the preferences table, so a
             * category the person switched off would still reach them. A manager decides who is
             * informed; they do not decide how somebody's inbox works.
             *
             * A blanket arrangement (`categories === null`) becomes the explicit list of what this
             * person allows — narrowing a NULL is the correct direction, and it is the only place
             * where NULL stops meaning «everything».
             */
            $allowed = $audience->allowedCategories($user, $entry['tenant_id'], $entry['categories'] ?? self::CATEGORIES);

            if ($allowed === []) {
                continue;
            }

            $locale = DB::table('notification_preferences')
                ->where('tenant_id', $entry['tenant_id'])->where('user_id', $entry['user_id'])
                ->whereNull('client_workspace_id')->value('locale');

            $out[] = [
                'user' => $user,
                'tenant_id' => $entry['tenant_id'],
                'locale' => (string) ($locale ?? 'ar'),
                'project_ids' => $entry['project_ids'],
                'categories' => $allowed,
            ];
        }

        return $out;
    }

    /**
     * Absent means NO.
     *
     * The column holds the same `digests`-style map, and a row written before alerts existed has no
     * `alerts` key at all — which must read as «never asked», not as «why not».
     */
    private function wantsAlerts(object $row): bool
    {
        $chosen = $row->digests === null ? [] : (array) json_decode((string) $row->digests, true);

        return ($chosen['alerts'] ?? false) === true;
    }
}
