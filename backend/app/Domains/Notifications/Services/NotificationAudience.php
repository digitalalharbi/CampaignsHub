<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who is told, and why they are allowed to be — MAIL-010.
 *
 * ## The one rule
 *
 * **A recipient list is a request. A membership is the authorisation.** Everything below follows from
 * that, and the class is arranged so the two can never be confused: `NotificationRecipients` supplies
 * candidates, `DigestScope` supplies the ceiling, and only the intersection is ever returned.
 *
 * ## Why the check is at SEND time and not at write time
 *
 * Checking when a manager adds somebody is the obvious design and it is not enough. Access changes:
 * a member is moved off a client, a scope is replaced, a membership is suspended. If eligibility were
 * settled when the row was written, the row would keep mailing that client's spend to somebody the
 * product had already stopped showing it to — and an email is the one surface where a permission
 * change cannot reach back. So the ceiling is resolved on every send.
 *
 * The write-time check still exists (see `NotificationRecipientController`), because refusing at the
 * moment somebody makes a mistake is better than silently ignoring them later. It is a courtesy;
 * this is the control.
 *
 * ## Fail-closed, stated as behaviour rather than as a principle
 *
 * - A user with no active membership in the tenant receives nothing.
 * - A user whose ceiling no longer contains the project is dropped, and the row is left alone so the
 *   manager's intent survives a temporary revocation.
 * - A category a person has switched off in their own preferences is not sent to them, whatever the
 *   recipient list says — a manager may decide who is INFORMED, never how somebody's inbox works.
 * - An empty answer means «nobody», never «everybody».
 */
final class NotificationAudience
{
    public function __construct(private readonly DigestScope $scope) {}

    /**
     * The people who should be told about `$projectId`, in `$category`.
     *
     * @return list<User> ordered by id, so a caller iterating them is deterministic
     */
    public function forProject(string $tenantId, string $projectId, string $category): array
    {
        $candidates = $this->candidateIds($tenantId, $projectId, $category);

        if ($candidates === []) {
            return [];
        }

        $out = [];

        foreach (User::query()->whereIn('id', $candidates)->orderBy('id')->get() as $user) {
            if ($user->email === null || trim((string) $user->email) === '') {
                continue;
            }
            // The ceiling, live. This is the line that makes the list a request rather than a grant.
            if (! in_array($projectId, $this->scope->projectIdsFor($user, $tenantId), true)) {
                continue;
            }
            if (! $this->wantsCategory($user, $tenantId, $category)) {
                continue;
            }

            $out[] = $user;
        }

        return $out;
    }

    /**
     * Whether this person would receive `$category` about `$projectId` today, and if not, why not.
     *
     * Written for the management screen rather than for the sender. «Sara is on the list and is not
     * being told» has to be answerable, or a manager arranges something, watches nothing happen, and
     * concludes the feature is broken.
     *
     * @return array{eligible: bool, reason: ?string}
     */
    public function explain(User $user, string $tenantId, string $projectId, string $category): array
    {
        if ($user->email === null || trim((string) $user->email) === '') {
            return ['eligible' => false, 'reason' => 'no_email'];
        }
        if (! in_array($projectId, $this->scope->projectIdsFor($user, $tenantId), true)) {
            return ['eligible' => false, 'reason' => 'outside_their_access'];
        }
        if (! $this->wantsCategory($user, $tenantId, $category)) {
            return ['eligible' => false, 'reason' => 'switched_off_by_recipient'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    /**
     * The categories this person is willing to receive by email — MAIL-010.
     *
     * Public because the alert sweep needs it. `AlertDispatcher` resolves its own recipients from
     * arrangements and never reads the preferences table, so without this a manager could arrange
     * somebody into a category they had explicitly switched off — and the product would be one where
     * turning something off in your settings does not turn it off.
     *
     * @param  list<string>  $categories  the vocabulary to answer over
     * @return list<string>
     */
    public function allowedCategories(User $user, string $tenantId, array $categories): array
    {
        return array_values(array_filter(
            $categories,
            fn (string $category): bool => $this->wantsCategory($user, $tenantId, $category),
        ));
    }

    /**
     * Rows naming this person for this project, before any of them is checked.
     *
     * A NULL `project_id` or `category` means «all of them» — see the migration for why NULLs are
     * used rather than a sentinel string.
     *
     * @return list<int>
     */
    private function candidateIds(string $tenantId, string $projectId, string $category): array
    {
        return DB::table('notification_recipients')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', $category))
            ->distinct()
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The recipient's own answer for this category.
     *
     * Absent preferences mean the defaults `NotificationPreferenceController` documents, which are
     * on for everything except performance — a person who has never opened their settings should
     * still be reachable, or a manager arranging a recipient list would find it worked for nobody.
     * What is NOT inferred is a category they explicitly switched off.
     */
    private function wantsCategory(User $user, string $tenantId, string $category): bool
    {
        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->whereNull('client_workspace_id')
            ->first();

        if ($row === null) {
            return $category !== 'performance';
        }

        // The channel switch outranks the category map: email off is off, whatever else is set.
        $channels = $row->channels === null ? [] : (array) json_decode((string) $row->channels, true);
        if (($channels['email'] ?? true) !== true) {
            return false;
        }

        $categories = $row->categories === null ? [] : (array) json_decode((string) $row->categories, true);

        if (! array_key_exists($category, $categories)) {
            return $category !== 'performance';
        }

        return (bool) (($categories[$category]['email'] ?? false));
    }
}
