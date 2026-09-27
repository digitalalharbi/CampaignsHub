<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MessageCatalogue;
use Tests\TestCase;

/**
 * EMAIL-SETTINGS-DEPTH-001 — every message a person can decide about is NAMED on the screen where
 * they decide.
 *
 * `MessageCatalogue` is the server's list of notification types. `messageLabels.ts` is the only
 * place those keys become something a person can read, and its own header says so. When a type is
 * added to the catalogue and not to the labels, nothing breaks and no test fails — the settings
 * table simply prints the raw key in a column where every sibling reads as a sentence. Two had
 * reached production that way: `internal_spend_limit` sat between «سرعة استهلاك الميزانية» and
 * «ارتفاع تكلفة النتيجة», and `lead_sla` among the operations rows.
 *
 * It reads as a rendering fault rather than as a missing translation, which is worse: a reader who
 * sees a database key stops trusting the screen it is on.
 *
 * The guard lives on the backend because the catalogue does, and because a test may read the
 * frontend file from here without the frontend suite needing a filesystem.
 */
final class EveryMessageTypeIsNamedTest extends TestCase
{
    public function test_every_catalogue_type_has_a_label_a_person_can_read(): void
    {
        $labels = (string) file_get_contents(
            base_path('../frontend/src/features/settings/messageLabels.ts'),
        );

        /* TYPE_LABELS' entries, as `key: { ar: …` — the shape the file states for every one. */
        preg_match_all('/^\s*([a-z0-9_]+):\s*\{\s*ar:/m', $labels, $found);
        $named = array_flip($found[1]);

        $unnamed = [];

        foreach (MessageCatalogue::keys() as $key) {
            if (! array_key_exists($key, $named)) {
                $unnamed[] = $key;
            }
        }

        $this->assertSame([], $unnamed, implode("\n", array_merge(
            ['These notification types would print as raw keys on the settings screen:', ''],
            $unnamed,
            ['', 'Add each one to TYPE_LABELS in frontend/src/features/settings/messageLabels.ts.',
                'The label says what ARRIVES, not what the code detects.'],
        )));
    }
}
