<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Campaigns\Models\CampaignAnnotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EMAIL-SETTINGS-DEPTH-001 — the recommendations a digest is allowed to carry.
 *
 * ## This generates nothing
 *
 * Every row here was written by a person and approved by a person. The digest quotes them; it does not
 * derive advice from figures and present it as somebody's recommendation. That distinction is the same
 * one `/app/recommendations` already makes, and it matters more in email: a reader cannot ask an inbox
 * where a sentence came from.
 *
 * ## Approved only, and why the same rule as a client report
 *
 * `CampaignAnnotation` runs draft → reviewed → approved → hidden/rejected, and only `approved` may
 * reach a client report. A digest is not a client report, but it is just as unretractable — mailing a
 * colleague's draft, or something a reviewer explicitly rejected, publishes a judgement its author had
 * not finished making. So the stricter of the two rules applies.
 *
 * `hidden` and `rejected` are not merely «not approved»: they are decisions to stop showing something,
 * and an email that carried them anyway would be the one surface that overrode a person's retraction.
 */
final class DigestRecommendations
{
    /**
     * Did this recipient ask for recommendations in their digest?
     *
     * Stored in `digests.recommendations`, beside the daily/weekly/alert opt-ins that already live
     * there, so no column was added and a map written before this setting existed is simply a map
     * without the key.
     *
     * Absent, malformed, or no row at all is FALSE. The only value that turns this on is an explicit
     * stored `true`, because every other reading of that row is an absence of consent rather than
     * consent — and the thing being consented to is somebody else's approved judgement arriving in
     * this person's inbox.
     *
     * It lives here rather than in `DailyDigest` because that class states it contains no SQL, and the
     * claim is load-bearing: it is what guarantees every figure in a digest came from the same
     * aggregator the dashboard reads.
     */
    public function enabledFor(User $user, string $tenantId): bool
    {
        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->whereNull('client_workspace_id')
            ->first();

        if ($row?->digests === null) {
            return false;
        }

        $digests = json_decode((string) $row->digests, true);

        return is_array($digests) && ($digests['recommendations'] ?? false) === true;
    }

    /** Enough to act on before breakfast. A digest that lists thirty is a backlog, not a message. */
    private const LIMIT = 5;

    /**
     * Approved recommendations for one project, written within the window the digest covers.
     *
     * Bounded by the digest's own window rather than «all open recommendations», so a rolling backlog
     * does not reappear in every single email until somebody closes it — which teaches the reader to
     * skip the section, and a section that is always skipped is worse than one that is absent.
     *
     * @return list<array{id: string, title: string, body: ?string, priority: ?string, campaign_id: ?string, due_date: ?string}>
     */
    public function forProject(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        /*
         * Scoped EXPLICITLY rather than by ambient context — defence in depth, not a bug fix.
         *
         * `DigestDispatcher` does set the tenant context around the build, and says why: `TenantScope`
         * fails closed, so a scheduler with no tenant resolved would produce an empty digest rather
         * than a wrong one. That is the safe direction, and it is already handled.
         *
         * This binds the tenant as a value anyway, because the id is right here in the caller's hand
         * and a section that quotes one team's approved judgement has no reason to depend on state set
         * three frames up. It is a narrowing in both directions: the global scopes are stood down, and
         * both keys they would have applied are applied here from arguments instead.
         */
        return CampaignAnnotation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('kind', 'recommendation')
            ->where('status', 'approved')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            /*
             * Newest first, ties broken by id.
             *
             * Without the tiebreak two recommendations written in the same second could swap places
             * between the digest and the screen it links to, and a reader comparing the two would be
             * looking for a change that never happened.
             */
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (CampaignAnnotation $a): array => [
                'id' => (string) $a->getKey(),
                'title' => (string) $a->title,
                'body' => $a->body === null ? null : (string) $a->body,
                'priority' => $a->priority === null ? null : (string) $a->priority,
                'campaign_id' => $a->campaign_id === null ? null : (string) $a->campaign_id,
                'due_date' => $a->due_date?->toDateString(),
            ])
            ->all();
    }
}
