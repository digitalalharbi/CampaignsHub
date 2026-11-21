<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

/** One logged lifecycle transition (a row of `project_status_transitions` / `client_status_transitions`). */
final class TransitionRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $subjectId,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $actorId,
        public readonly string $at,
    ) {}

    /** @return array{id: string, subject_id: string, from: string, to: string, actor_id: int|null, at: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'subject_id' => $this->subjectId, 'from' => $this->from, 'to' => $this->to, 'actor_id' => $this->actorId, 'at' => $this->at];
    }
}
