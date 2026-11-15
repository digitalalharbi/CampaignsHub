<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

/**
 * AGENTS-SANDBOX-001 — a model that never leaves the machine, for the E2E gate and local walk-throughs.
 *
 * Chosen with `AGENTS_MODEL_DRIVER=sandbox` (the same idea as `SUBSCRIPTION_PROVIDER=sandbox`), and REFUSED in production by
 * `ModelClientFactory`, so a misplaced variable can never answer a real customer with a script. It follows the real loop: it reads
 * the campaigns with a tool, and its words quote ONLY what that tool returned (never an invented figure). Asked to pause or change a
 * budget, and given propose_change, it proposes ONE pause of the first campaign the tool listed — which, like every proposal,
 * waits for a person. It streams word by word so the screen's live reply can be seen arriving.
 */
final class SandboxModelClient implements StreamingModelClient
{
    public function __construct(private readonly int $wordDelayMs = 120) {}

    public function respond(string $model, string $system, array $history, array $tools, int $maxTokens = 16000): ModelTurn
    {
        return $this->stream($model, $system, '', $history, $tools, [], static fn (string $d) => null, static fn (): bool => false);
    }

    public function stream(string $model, string $system, string $context, array $history, array $tools, array $options, callable $onText, callable $shouldStop): ModelTurn
    {
        $names = array_column($tools, 'name');
        $asked = $this->lastPersonText($history);
        $campaigns = $this->lastToolData($history, 'list_campaigns');
        $proposed = $this->lastToolData($history, 'propose_change');

        if ($campaigns === null && in_array('list_campaigns', $names, true)) {
            return $this->tool('list_campaigns', ['days' => 7], $model);
        }

        $wantsChange = preg_match('/(أوقف|اوقف|إيقاف|ايقاف|pause|ميزانية|budget)/iu', $asked) === 1;
        $rules = $this->lastToolData($history, 'get_rules');
        if ($wantsChange && $rules === null && in_array('get_rules', $names, true) && in_array('propose_change', $names, true)) {
            return $this->tool('get_rules', [], $model);   // which accounts this project holds: a proposal may only name one of them
        }

        // The first listed campaign on an account this project holds (what a proposal needs), else simply the first one.
        $bound = array_map(static fn ($a): string => (string) ($a['id'] ?? ''), (array) ($rules['accounts'] ?? []));
        $listed = array_values((array) ($campaigns['campaigns'] ?? []));
        // A platform the person named («أوقف حملة ميتا») narrows the choice to it.
        $named = $this->namedPlatform($asked);
        $fits = static fn ($t): bool => in_array((string) ($t['external_account_id'] ?? ''), $bound, true) && ($named === null || ($t['provider'] ?? null) === $named);
        $onBound = static fn ($c): bool => is_array($c) && collect((array) ($c['on_platform'] ?? []))->contains($fits);
        $first = (array) (collect($listed)->first($onBound) ?? ($listed[0] ?? []));
        $target = (array) (collect((array) ($first['on_platform'] ?? []))->first($fits) ?? []);
        if ($wantsChange && $proposed === null && in_array('propose_change', $names, true) && isset($target['target_external_id'])) {
            return $this->tool('propose_change', [
                'provider' => (string) ($target['provider'] ?? ''), 'external_account_id' => (string) ($target['external_account_id'] ?? ''),
                'target_type' => 'campaign', 'target_external_id' => (string) $target['target_external_id'], 'action' => 'pause',
                'reason' => 'اقتراح تجريبي من بيئة الاختبار.',
            ], $model);
        }

        $window = (array) ($campaigns['window'] ?? []);
        $text = $first === []
            ? 'لا توجد بيانات للحملات في هذه الفترة.'
            : sprintf('الحملة: %s — الإنفاق %s %s من %s إلى %s.',
                (string) ($first['campaign_name'] ?? '—'), $this->fig($first['spend'] ?? null), (string) ($campaigns['currency'] ?? ''),
                (string) ($window['from'] ?? '—'), (string) ($window['to'] ?? '—'));
        if ($proposed !== null) {
            $text .= ' اقترحت إيقافها، وهو بانتظار موافقتك.';
        }

        $written = '';
        foreach (preg_split('/(?<= )/u', $text) ?: [] as $word) {
            $written .= $word;
            $onText($word);
            if ($shouldStop()) {
                return new ModelTurn([['type' => 'text', 'text' => $written]], $written, [], 'stopped', ['input_tokens' => 0, 'output_tokens' => 0], $model);
            }
            if ($this->wordDelayMs > 0) {
                usleep($this->wordDelayMs * 1000);
            }
        }

        return new ModelTurn([['type' => 'text', 'text' => $text]], $text, [], 'end', ['input_tokens' => 40, 'output_tokens' => 20], $model);
    }

    /** @param  array<string, mixed>  $input */
    private function tool(string $name, array $input, string $model): ModelTurn
    {
        $id = 'toolu_sandbox_'.bin2hex(random_bytes(6));

        return new ModelTurn([['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], '', [['id' => $id, 'name' => $name, 'input' => $input]], 'tool_use', ['input_tokens' => 40, 'output_tokens' => 10], $model);
    }

    /** @param  list<array{role: string, blocks: list<array<string, mixed>>}>  $history */
    private function lastPersonText(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            foreach (array_reverse($history[$i]['blocks']) as $b) {
                if ($history[$i]['role'] === 'user' && ($b['type'] ?? '') === 'text') {
                    return (string) ($b['text'] ?? '');
                }
            }
        }

        return '';
    }

    /**
     * The data of the latest result of one tool in the CURRENT turn (after the person's last words), unwrapped from its fence.
     *
     * @param  list<array{role: string, blocks: list<array<string, mixed>>}>  $history
     * @return array<string, mixed>|null
     */
    private function lastToolData(array $history, string $tool): ?array
    {
        $calls = [];
        $results = [];
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $blocks = $history[$i]['blocks'];
            if ($history[$i]['role'] === 'user' && in_array('text', array_column($blocks, 'type'), true)) {
                break;   // the person's words: the current turn starts after them
            }
            foreach ($blocks as $b) {
                match ($b['type'] ?? '') {
                    'tool_use' => ($b['name'] ?? '') === $tool ? $calls[] = (string) $b['id'] : null,
                    'tool_result' => $results[(string) ($b['tool_use_id'] ?? '')] = (string) ($b['content'] ?? ''),
                    default => null,
                };
            }
        }
        foreach ($calls as $id) {
            if (isset($results[$id]) && preg_match('/<untrusted_data tool="[^"]+">\n(.*)\n<\/untrusted_data>/s', $results[$id], $m) === 1) {
                $data = json_decode($m[1], true);

                return is_array($data) ? $data : null;
            }
        }

        return null;
    }

    private function namedPlatform(string $text): ?string
    {
        foreach (['meta' => '/(ميتا|فيسبوك|meta|facebook)/iu', 'google' => '/(جوجل|قوقل|google)/iu', 'snapchat' => '/(سناب|snap)/iu', 'tiktok' => '/(تيك ?توك|tiktok)/iu'] as $provider => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $provider;
            }
        }

        return null;
    }

    private function fig(mixed $v): string
    {
        return is_numeric($v) ? number_format((float) $v, 2, '.', ',') : 'لا توجد بيانات';
    }
}
