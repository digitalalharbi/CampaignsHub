<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use Illuminate\Support\Facades\DB;

/**
 * CAMPAIGNS-ADOPT-001 — recovering, from evidence, which detached rows a PERSON detached.
 *
 * ## The danger this closes
 *
 * `external_campaigns.unlinked_at` is the record that lets the importer adopt a campaign discovered
 * before adoption existed without undoing somebody's decision. Existing rows have no such record, and
 * `unified_campaign_id IS NULL` is equally true of «never adopted» and «deliberately unlinked». Left
 * unstamped, the first sweep after the deploy would re-adopt every historically unlinked row — a
 * silent reversal of real decisions, on production, with nothing on screen to explain it.
 *
 * ## Why the audit trail is proof here, and not a guess
 *
 * Three facts, each checkable in this repository rather than assumed:
 *
 * 1. `CampaignLinker::unlink()` is the ONLY code path that clears `unified_campaign_id`, and the one
 *    route that calls it writes `campaign.external_unlinked` against the external campaign's id.
 * 2. That action name has existed since external-campaign management itself — `280f333`, the commit
 *    that introduced both. There is no earlier spelling to miss.
 * 3. `audit_logs` is created by `2026_07_21_100200`, three days BEFORE `create_campaigns_tables`, and
 *    nothing prunes it. The `integrations:prune-raw` retention is for provider payloads, not audits.
 *
 * So every external campaign that has ever existed was created while unlink auditing was in place,
 * and the audit history covering it is complete. The absence of an entry is therefore evidence, not
 * merely a lack of evidence — which is what makes «leave it adoptable» a proven statement about that
 * row rather than an assumption about all of them.
 *
 * Nothing here names an account, a project or a tenant. The rule is the same rule everywhere.
 */
final class StampHistoricalUnlinks
{
    /** The one action the product has ever written when a person detaches an external campaign. */
    public const AUDIT_ACTION = 'campaign.external_unlinked';

    /**
     * Stamp `unlinked_at` on every detached row the audit trail shows a person detached.
     *
     * The timestamp is the LATEST such entry: a row can be linked and unlinked more than once, and
     * the decision that stands is the most recent one.
     *
     * @return int how many rows carried a recorded decision
     */
    public function execute(): int
    {
        /*
         * `audit_logs.entity_id` is a varchar — it identifies rows across many tables, not all of
         * which key on a uuid — so the comparison is cast explicitly. Postgres has no implicit
         * `varchar = uuid`, and leaving it out is a migration that fails halfway through a deploy.
         */
        return DB::table('external_campaigns')
            ->whereNull('unified_campaign_id')
            ->whereNull('unlinked_at')
            ->whereExists(fn ($q) => $q->select(DB::raw('1'))
                ->from('audit_logs as a')
                ->whereRaw('a.entity_id = external_campaigns.id::text')
                ->where('a.action', self::AUDIT_ACTION))
            ->update([
                'unlinked_at' => DB::raw(
                    '(SELECT MAX(a.created_at) FROM audit_logs a '
                    ."WHERE a.entity_id = external_campaigns.id::text AND a.action = '".self::AUDIT_ACTION."')"
                ),
            ]);
    }
}
