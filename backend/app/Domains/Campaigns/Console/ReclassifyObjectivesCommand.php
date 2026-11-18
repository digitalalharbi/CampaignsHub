<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Actions\ReclassifyCampaignObjectives;
use App\Domains\Campaigns\Enums\CampaignObjective;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OBJECTIVE-RECLASSIFY-RUN-001 — run {@see ReclassifyCampaignObjectives} from an operator workflow, with a dry run.
 *
 * The action existed only inside two migrations, so it could not be run again after a mapping changed
 * (X line items, Snapchat AWARENESS_AND_ENGAGEMENT, ChatGPT `clicks`, Google conversion bidding) without a
 * new migration, and nothing could show what it WOULD do before it did it.
 *
 * `--dry-run` runs the very same action inside a transaction and rolls it back, so the numbers it prints
 * are the real run's numbers, not an estimate. The action and the resolver only write rows (campaigns
 * and audit entries), so nothing escapes the rollback.
 *
 * Output is counts only — platform, objective before → after, number of campaigns — never a name or an
 * id. Manual objectives are checked before and after: if a single one changed, the run is rolled back
 * and the command fails (the resolver never touches them; this proves it on the data).
 */
final class ReclassifyObjectivesCommand extends Command
{
    protected $signature = 'objectives:reclassify {--dry-run : Run inside a transaction and roll it back; print what would change}';

    protected $description = 'Re-derive unclassified campaign objectives from their platforms (never a manual one). Counts only.';

    public function handle(ReclassifyCampaignObjectives $action): int
    {
        $dry = (bool) $this->option('dry-run');
        $before = $this->candidates();
        $manualBefore = $this->manualFingerprint();

        DB::beginTransaction();

        try {
            $result = $action->execute();
            $after = DB::table('unified_campaigns')->whereIn('id', array_keys($before))->pluck('objective', 'id')->all();
            $manualAfter = $this->manualFingerprint();

            if ($manualAfter !== $manualBefore) {
                DB::rollBack();
                $this->error('A MANUAL objective changed — rolled back, nothing written.');

                return self::FAILURE;
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $moves = [];
        foreach ($before as $id => [$provider, $from]) {
            $to = (string) ($after[$id] ?? $from);
            $moves[$provider.' | '.($from === '' ? '(empty)' : $from).' → '.$to] = ($moves[$provider.' | '.($from === '' ? '(empty)' : $from).' → '.$to] ?? 0) + 1;
        }
        ksort($moves);

        $this->line(($dry ? 'DRY RUN (rolled back)' : 'RUN (committed)').' — examined '.$result['examined'].', reclassified '.$result['reclassified'].', still unclassified '.$result['unclassified']);
        $this->line('platform | objective before → after | campaigns');
        foreach ($moves as $key => $n) {
            $this->line($key.' | '.$n);
        }
        $this->line('manual objectives unchanged: '.$manualBefore['count']);

        return self::SUCCESS;
    }

    /**
     * The rows the action examines (not manual; `other` or not a canonical value), with their platform.
     *
     * @return array<string, array{0: string, 1: string}> id => [platform, objective before]
     */
    private function candidates(): array
    {
        $valid = CampaignObjective::values();

        return DB::table('unified_campaigns as uc')
            ->whereNull('uc.deleted_at')
            ->where('uc.objective_source', '!=', 'manual')
            ->where(fn ($q) => $q->whereNotIn('uc.objective', $valid)->orWhere('uc.objective', CampaignObjective::Other->value)->orWhereNull('uc.objective'))
            ->selectRaw("uc.id, COALESCE(uc.objective, '') AS objective, COALESCE((SELECT STRING_AGG(DISTINCT ec.provider, '+') FROM external_campaigns ec WHERE ec.unified_campaign_id = uc.id), '(none)') AS provider")
            ->get()
            ->mapWithKeys(static fn ($r): array => [(string) $r->id => [(string) $r->provider, (string) $r->objective]])
            ->all();
    }

    /** @return array{count: int, hash: string} every manual objective, so a single change is detectable */
    private function manualFingerprint(): array
    {
        $rows = DB::table('unified_campaigns')->where('objective_source', 'manual')->orderBy('id')->pluck('objective', 'id')->all();

        return ['count' => count($rows), 'hash' => md5((string) json_encode($rows))];
    }
}
