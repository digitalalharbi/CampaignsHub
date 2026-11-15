<?php

declare(strict_types=1);

namespace App\Domains\Agents\Support;

/**
 * AGENTS-ASSISTANT-001 — which specialist answers a message sent to «المساعد».
 *
 * Deterministic and in code, on purpose:
 * - it reads ONLY the person's own message (never a tool result, a campaign name or an ad's copy), so text planted in platform
 *   data cannot move a conversation to a specialist with more tools;
 * - it costs no model call, so routing never spends the workspace's tokens;
 * - it is testable: the same words always reach the same specialist.
 *
 * A message that names no job stays with the specialist that answered the previous turn (a follow-up), or goes to the read-only
 * analyst — never to a role that can propose a change.
 */
final class AssistantRouter
{
    /**
     * Words per specialist, Arabic and English, checked in this order (the first job that matches wins, so «أنشئ حملة» is a build
     * and not a campaign question).
     *
     * @var array<string, list<string>>
     */
    private const WORDS = [
        'builder' => ['أنشئ', 'انشئ', 'إنشاء', 'انشاء', 'جهّز حملة', 'جهز حملة', 'حملة جديدة', 'خطة إطلاق', 'خطة اطلاق', 'أطلق', 'اطلق', 'قاعدة تلقائية', 'قاعدة جديدة', 'create', 'launch', 'new campaign', 'build', 'draft a rule', 'new rule'],
        'leads' => ['عملاء محتملين', 'العملاء المحتملين', 'عميل محتمل', 'ليدز', 'واتساب', 'lead quality', 'leads', 'whatsapp'],
        'reports' => ['تقرير', 'تقارير', 'ملخص', 'لخّص', 'لخص', 'للعميل', 'report', 'summary', 'summarise', 'summarize'],
        'creative' => ['إبداع', 'ابداع', 'إعلان', 'اعلان', 'الإعلانات', 'الاعلانات', 'نص إعلاني', 'نصوص', 'فكرة', 'أفكار', 'افكار', 'هوك', 'فيديو', 'تصميم', 'مُجهد', 'مجهد', 'إجهاد', 'اجهاد', 'creative', 'ad copy', 'copy', 'hook', 'video', 'fatigue', 'ads'],
        'campaign' => ['أوقف', 'اوقف', 'إيقاف', 'ايقاف', 'شغّل', 'شغل', 'استئناف', 'ميزانية', 'الميزانية', 'زِد', 'زد ', 'خفّض', 'خفض', 'pause', 'resume', 'budget', 'increase', 'decrease', 'stop the campaign'],
        'analyst' => ['حلل', 'حلّل', 'تحليل', 'أداء', 'اداء', 'مقارنة', 'قارن', 'الهدف', 'الأهداف', 'تكلفة', 'كلفة', 'roas', 'cpa', 'ctr', 'analyse', 'analyze', 'performance', 'compare', 'target', 'cost'],
    ];

    /** @param  string|null  $previous  the specialist that answered the previous turn of this conversation */
    public static function route(string $message, ?string $previous = null): string
    {
        $text = mb_strtolower(trim($message));

        foreach (self::WORDS as $specialist => $words) {
            foreach ($words as $word) {
                if (str_contains($text, mb_strtolower($word))) {
                    return $specialist;
                }
            }
        }

        // A follow-up («وأمس؟») stays with whoever answered before; a first message with no job goes to the read-only analyst.
        return $previous !== null && in_array($previous, AgentRoles::SPECIALISTS, true) ? $previous : 'analyst';
    }
}
