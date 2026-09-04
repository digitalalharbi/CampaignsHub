<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
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
    public function __construct(
        private readonly DigestScope $scope,
        private readonly NotificationChoices $choices,
        private readonly ProjectAbilities $abilities,
    ) {}

    /**
     * The category whose messages are about OUR plumbing — CLIENT-DIAGNOSTIC-SEPARATION-001.
     *
     * «Snapchat's last sync failed», «the token expires on Tuesday». Every one of them asks for an
     * action that only somebody who can manage the connection can take.
     */
    private const OPERATOR_CATEGORY = 'integrations';

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
            if (! $this->mayBeToldAboutOurPlumbing($user, $projectId, $category)) {
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
        if (! $this->mayBeToldAboutOurPlumbing($user, $projectId, $category)) {
            return ['eligible' => false, 'reason' => 'cannot_act_on_integrations'];
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
     * An integrations alert goes to somebody who can DO something about it.
     *
     * «Snapchat's last sync failed — some figures may be incomplete» is a statement about our
     * plumbing, and the only action it implies is reconnecting the source. A recipient without
     * `integrations.manage` on the project cannot take it: they are told our machinery is broken, in
     * our words, and left to forward it to somebody else — which is the operator diagnostic reaching a
     * client's inbox that CLIENT-DIAGNOSTIC-SEPARATION-001 exists to stop.
     *
     * The test is the CAPABILITY rather than the role name, deliberately. `client_viewer` and the
     * internal `content` role both resolve to the same preset, so «is this person a client» cannot be
     * answered from a role name — while «can this person reconnect the source» is exactly the question
     * the message raises, and the RBAC engine already answers it.
     *
     * Every other category is unaffected: a budget alert asks for a budget decision, and a client's
     * management is entitled to that one.
     */
    private function mayBeToldAboutOurPlumbing(User $user, string $projectId, string $category): bool
    {
        if ($category !== self::OPERATOR_CATEGORY) {
            return true;
        }

        return $this->abilities->allows($user, $projectId, ProjectCapability::INTEGRATIONS_MANAGE);
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
     * Delegated to `NotificationChoices` since MAIL-011, which is the single place the resolution
     * order lives. This method used to read the row itself and answer from the six-category map
     * alone — which meant a person who had switched a single message type off could still be
     * arranged into it by a manager, because the arrangement path never looked at per-type choices.
     *
     * A category counts as wanted when any type inside it is. That is what the manager asked for:
     * «tell them about the budget», not «tell them about all seven budget messages or none».
     */
    private function wantsCategory(User $user, string $tenantId, string $category): bool
    {
        return $this->choices->wantsCategory((int) $user->getKey(), $tenantId, $category);
    }
}
