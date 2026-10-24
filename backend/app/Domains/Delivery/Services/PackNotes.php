<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAnnotation;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * DELIVERY-NOTES-001 — the Owner's own notes in a delivery: add, edit, order, hide from the client, delete.
 *
 * A note is a `ReportAnnotation` with `source = manual`, so it travels through everything a generated one does — the report data,
 * the client view, the live link, the PDF and the WhatsApp text — and no second renderer exists. Four kinds, which decide where it
 * is drawn: an observation, a challenge and an opportunity are findings (neutral / warning / positive); a recommendation is a
 * recommendation. `audience = internal` keeps a note off every client surface.
 *
 * Written by someone who may approve (the controller requires `reports.approve`), so a note is APPROVED when it is saved: nothing
 * here is an AI draft waiting for a decision. Generated text is never approved this way.
 */
final class PackNotes
{
    /** kind → [annotation type, finding severity] */
    public const KINDS = [
        'observation' => ['finding', 'info'],
        'challenge' => ['finding', 'warning'],
        'opportunity' => ['finding', 'positive'],
        'recommendation' => ['recommendation', 'info'],
    ];

    public const AUDIENCES = ['client', 'internal'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<ReportAnnotation> */
    public function list(DeliveryPack $pack): array
    {
        return ReportAnnotation::withoutGlobalScopes()->where('report_id', $pack->report_id)->where('source', 'manual')
            ->orderByRaw('position is null')->orderBy('position')->orderBy('created_at')->get()->all();
    }

    public function add(DeliveryPack $pack, string $kind, ?string $textAr, ?string $textEn, string $audience, ?User $by): ReportAnnotation
    {
        $this->open($pack);
        $this->assertKind($kind);
        $this->assertText($textAr, $textEn);
        [$type] = self::KINDS[$kind];

        $note = new ReportAnnotation;
        $note->forceFill([
            'tenant_id' => $pack->tenant_id, 'report_id' => $pack->report_id, 'annotation_id' => substr(md5(Str::uuid()->toString()), 0, 12),
            'type' => $type, 'text_ar' => $this->clean($textAr), 'text_en' => $this->clean($textEn), 'source' => 'manual', 'priority' => 'normal',
            'status' => 'approved', 'audience' => in_array($audience, self::AUDIENCES, true) ? $audience : 'client',
            'position' => ((int) ReportAnnotation::withoutGlobalScopes()->where('report_id', $pack->report_id)->max('position')) + 1,
            'evidence' => ['kind' => $kind], 'is_ai_generated' => false, 'version' => 1, 'created_by' => $by?->id,
            'approved_by' => $by?->id, 'approved_at' => now(), 'reviewed_by' => $by?->id, 'reviewed_at' => now(),
        ])->save();
        $this->audit->log('delivery_note.added', 'report_annotation', (string) $note->id, null, ['kind' => $kind, 'audience' => $note->audience]);
        $this->regenerate($pack);

        return $note;
    }

    /** @param array<string,mixed> $fields text_ar, text_en, audience, kind — only keys present change */
    public function update(DeliveryPack $pack, ReportAnnotation $note, array $fields): ReportAnnotation
    {
        $this->open($pack);
        $this->mine($pack, $note);

        $textAr = array_key_exists('text_ar', $fields) ? $this->clean($fields['text_ar']) : $note->text_ar;
        $textEn = array_key_exists('text_en', $fields) ? $this->clean($fields['text_en']) : $note->text_en;
        $this->assertText($textAr, $textEn);
        $change = ['text_ar' => $textAr, 'text_en' => $textEn, 'version' => $note->version + 1];
        if (isset($fields['audience']) && in_array($fields['audience'], self::AUDIENCES, true)) {
            $change['audience'] = $fields['audience'];
        }
        if (isset($fields['kind'])) {
            $this->assertKind((string) $fields['kind']);
            $change['type'] = self::KINDS[$fields['kind']][0];
            $change['evidence'] = ['kind' => $fields['kind']];
        }
        $note->forceFill($change)->save();
        $this->audit->log('delivery_note.updated', 'report_annotation', (string) $note->id, null, array_diff_key($change, ['version' => 1]));
        $this->regenerate($pack);

        return $note->refresh();
    }

    public function delete(DeliveryPack $pack, ReportAnnotation $note): void
    {
        $this->open($pack);
        $this->mine($pack, $note);
        $this->audit->log('delivery_note.deleted', 'report_annotation', (string) $note->id);
        $note->delete();
        $this->regenerate($pack);
    }

    /** @param list<string> $ids the pack's manual notes in the order wanted; notes not named keep their place after them */
    public function reorder(DeliveryPack $pack, array $ids): void
    {
        $this->open($pack);
        $own = collect($this->list($pack))->keyBy(fn (ReportAnnotation $n): string => (string) $n->id);
        $position = 0;
        foreach (array_values(array_unique($ids)) as $id) {
            if ($own->has($id)) {
                $own[$id]->forceFill(['position' => ++$position])->save();
                $own->forget($id);
            }
        }
        foreach ($own as $rest) {
            $rest->forceFill(['position' => ++$position])->save();
        }
        $this->regenerate($pack);
    }

    private function open(DeliveryPack $pack): void
    {
        if ($pack->status === 'sent') {
            throw new InvalidArgumentException('This pack has already been sent.');
        }
    }

    private function mine(DeliveryPack $pack, ReportAnnotation $note): void
    {
        if ((string) $note->report_id !== (string) $pack->report_id || $note->source !== 'manual') {
            throw new InvalidArgumentException('Only the notes written for this delivery can be changed here.');
        }
    }

    private function assertKind(string $kind): void
    {
        if (! array_key_exists($kind, self::KINDS)) {
            throw new InvalidArgumentException('Unknown note kind.');
        }
    }

    private function assertText(?string $ar, ?string $en): void
    {
        if ($this->clean($ar) === null && $this->clean($en) === null) {
            throw new InvalidArgumentException('A note needs some text.');
        }
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 600);
    }

    /** The client snapshot is regenerated so every surface reflects the change, exactly as an approval does. */
    private function regenerate(DeliveryPack $pack): void
    {
        $report = Report::withoutGlobalScopes()->find($pack->report_id);
        if ($report !== null) {
            $report->update(['status' => 'processing']);
            GenerateReportJob::dispatch((string) $report->id);
        }
    }
}
