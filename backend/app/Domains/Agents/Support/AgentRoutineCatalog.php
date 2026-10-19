<?php

declare(strict_types=1);

namespace App\Domains\Agents\Support;

/**
 * AGENTS-ROUTINES-001 — the routines of the plan, each = which agent, when, and the fixed instruction it
 * receives. The instruction is ours, never a client's text, and every routine works inside the rules
 * every conversation has: figures only from tools, changes only as proposals a person approves.
 *
 * The hourly sync the plan lists needs no AI and already runs (`integrations:sync`).
 */
final class AgentRoutineCatalog
{
    /** @return array<string, array{agent: string, ar: string, en: string, when_ar: string, when_en: string, instruction: string}> */
    public static function all(): array
    {
        return [
            'daily_analysis' => [
                'agent' => 'analyst', 'ar' => 'التحليل اليومي', 'en' => 'Daily analysis',
                'when_ar' => 'يوميًا 08:45 بتوقيت الرياض', 'when_en' => 'Daily at 08:45 Riyadh',
                'instruction' => 'Routine: daily analysis. Read the last 7 days against the previous 7 and compare every campaign with the client\'s targets. Report, decision first: what improved, what worsened, which campaigns need a decision today and why. State every period. Do not propose changes; the Campaign Agent does that.',
            ],
            'budget_guard' => [
                'agent' => 'campaign', 'ar' => 'حارس الميزانية', 'en' => 'Budget guard',
                'when_ar' => 'كل ساعتين', 'when_en' => 'Every two hours',
                'instruction' => 'Routine: budget guard. Check the last 1 and 7 days of spend against the client\'s targets and limits. If a campaign is clearly spending without results beyond the client\'s minimum spend to judge, propose ONE pause or budget change with the evidence. If nothing needs action, answer in one line that nothing needs action.',
            ],
            'creative_review' => [
                'agent' => 'creative', 'ar' => 'مراجعة الإعلانات', 'en' => 'Creative review',
                'when_ar' => 'الأحد والأربعاء 09:00', 'when_en' => 'Sunday and Wednesday 09:00',
                'instruction' => 'Routine: creative review. Using the campaign figures of the last 7 days, name which campaigns show fatigue (rising cost per result with steady spend) and what kind of new creative to brief. Do not invent ad names or figures.',
            ],
            'weekly_report' => [
                'agent' => 'reports', 'ar' => 'ملخص العميل الأسبوعي', 'en' => 'Weekly client summary',
                'when_ar' => 'الخميس 14:00', 'when_en' => 'Thursday 14:00',
                'instruction' => 'Routine: weekly client summary. Write a short summary of the last 7 days for the client: results against their targets, what changed, and the plan for next week. No campaign spend figures beyond what the client\'s own targets need; plain language.',
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }
}
