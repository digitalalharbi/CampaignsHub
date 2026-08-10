<?php

declare(strict_types=1);

namespace App\Domains\Legal\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * LEGAL-002 — a message from the public contact form.
 *
 * Not tenant-scoped, and that is deliberate: the sender is usually not a customer yet. Attaching a
 * stranger's enquiry to a workspace would either misfile it or require inventing a tenancy for them.
 * It is read only from the platform console.
 */
final class ContactMessage extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'name', 'email', 'phone', 'company', 'topic', 'source', 'subject', 'message',
        'status', 'operator_note', 'handled_by', 'handled_at', 'ip', 'user_agent', 'locale',
    ];

    protected $casts = ['handled_at' => 'datetime'];

    /** `spam` is a STATE, not a deletion — deleting the message loses the fact that it was judged. */
    public const STATUSES = ['new', 'read', 'answered', 'closed', 'spam'];

    /**
     * LOGIN-HELP-001 — the five things somebody actually writes in about.
     *
     * A closed list because the point is to be able to GROUP them: «choosing a plan» and «connecting
     * accounts» go to different people, and a free-text subject cannot be routed. `other` is there so
     * the list never forces a wrong answer — an enquiry that fits none of the four is not lost, it is
     * marked as not fitting.
     */
    public const TOPICS = ['own_campaigns', 'multi_client_campaigns', 'plan_choice', 'connect_accounts', 'other'];

    /** Where the panel was opened from. A triage hint for the operator, not a claim about the sender. */
    public const SOURCES = ['login', 'contact_page', 'pricing', 'other'];

    /**
     * The subject a topic stands for, so the operator queue reads as sentences rather than as slugs.
     *
     * Arabic, because the console is Arabic and every other subject in that table was typed in it.
     */
    public static function subjectForTopic(string $topic): string
    {
        return match ($topic) {
            'own_campaigns' => 'إدارة حملاتي',
            'multi_client_campaigns' => 'إدارة حملات عدة عملاء',
            'plan_choice' => 'مساعدة في اختيار الباقة',
            'connect_accounts' => 'ربط الحسابات والمنصات',
            default => 'استفسار آخر',
        };
    }
}
