<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Support\MailShell;
use App\Domains\Notifications\Support\MailSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An invitation to join a workspace — MAIL-009.
 *
 * The only message this product sends to somebody who has no account, which makes it the only one a
 * reader cannot verify by recognising the product. So it names the person who invited them and the
 * workspace they are being invited to, both in the subject line, because an invitation from nobody in
 * particular is indistinguishable from spam.
 *
 * ## The role is spelled out, and its description is omitted when unknown
 *
 * `manager` is a database value. `مدير` with a sentence about what a manager can see is the answer to
 * the question the reader is actually asking, which is «what am I being given?». For a role the map
 * below does not carry, the name falls back to the slug and the DESCRIPTION is dropped entirely —
 * inventing one would be a statement about somebody's access that nothing checks, and access is the
 * one thing in this message a reader will take literally.
 */
final class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $workspace,
        public readonly string $roleSlug,
        public readonly string $acceptUrl,
        public readonly string $lang = 'ar',
        /** Who sent it. Empty when the product cannot say — never «فريق العمل» as a stand-in. */
        public readonly string $invitedBy = '',
        public readonly int $expiresInHours = 72,
    ) {}

    public function envelope(): Envelope
    {
        $ar = $this->lang === 'ar';

        return new Envelope(subject: MailSubject::of($ar
            ? "دعوة للانضمام إلى {$this->workspace}"
            : "You have been invited to join {$this->workspace}"));
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $role = $this->role($ar);

        $intro = $this->invitedBy !== ''
            ? ($ar
                ? "دعاك {$this->invitedBy} للانضمام إلى مساحة عمل {$this->workspace}."
                : "{$this->invitedBy} invited you to join the {$this->workspace} workspace.")
            : ($ar
                ? "لديك دعوة للانضمام إلى مساحة عمل {$this->workspace}."
                : "You are invited to join the {$this->workspace} workspace.");

        $shell = MailShell::build(
            lang: $this->lang,
            subject: $ar ? 'دعوة للانضمام' : 'An invitation to join',
            preheader: $intro,
            // The recipient has no account and therefore no preferences to manage.
            preferences: false,
            footerNote: $ar
                ? 'وصلتك لأن أحد أعضاء الفريق أدخل بريدك.'
                : 'Sent because a team member entered your email.',
        );

        $body = $shell + [
            'title' => $ar ? 'دعوة للانضمام' : 'An invitation to join',
            'intro' => $intro,
            'workspace' => $this->workspace,
            'roleName' => $role['name'],
            'roleNote' => $role['note'],
            'actionUrl' => $this->acceptUrl,
            'actionLabel' => $ar ? 'قبول الدعوة' : 'Accept the invitation',
            'validity' => $ar
                ? "الدعوة صالحة لمدة {$this->expiresInHours} ساعة."
                : "Valid for {$this->expiresInHours} hours.",
        ];

        $body['t'] = $shell['t'] + ($ar
            ? [
                'workspace' => 'مساحة العمل',
                'role' => 'دورك',
                'or_paste' => 'إذا لم يعمل الزر، افتح هذا الرابط:',
                'not_expecting' => 'إذا لم تتوقع هذه الدعوة، فتجاهلها. لن يُفتح حساب دون قبولك.',
            ]
            : [
                'workspace' => 'Workspace',
                'role' => 'Your role',
                'or_paste' => 'If the button fails, open this link:',
                'not_expecting' => 'Not expecting this? Ignore it. No account opens unless you accept.',
            ]);

        return new Content(view: 'mail.layout', with: $body + [
            'slot' => view('mail.invitation', $body)->render(),
        ]);
    }

    /**
     * The role in words, and what it means — when the product can honestly say.
     *
     * @return array{name:string, note:string}
     */
    private function role(bool $ar): array
    {
        $roles = $ar ? [
            'tenant-owner' => ['مالك مساحة العمل', 'صلاحية كاملة، تشمل الفريق والاشتراك والفواتير.'],
            'owner' => ['مالك الحساب', 'صلاحية كاملة، تشمل الفريق والاشتراك والفواتير.'],
            'admin' => ['مدير', 'إدارة المشاريع والحملات والفريق، دون إعدادات الاشتراك.'],
            'manager' => ['مدير حسابات', 'إدارة حملات المشاريع المسندة إليه وتقاريرها.'],
            'analyst' => ['محلل', 'الاطلاع على البيانات والتقارير وإنشاؤها، دون تعديل الحملات.'],
            'viewer' => ['مطّلع', 'الاطلاع على لوحات المتابعة والتقارير فقط.'],
            'member' => ['عضو فريق', 'العمل على المشاريع المسندة إليه.'],
            'client_viewer' => ['مطّلع', 'الاطلاع على أداء حملاتك وتقاريرك فقط.'],
            'client_approver' => ['معتمِد', 'الاطلاع على الأداء والتقارير، واعتماد العروض والرد على الطلبات والرسائل.'],
            'client_admin' => ['مسؤول العميل', 'كل ما سبق، مع إدارة من يصل إلى بوابتك.'],
        ] : [
            'tenant-owner' => ['Workspace owner', 'Full control, including team, subscription and billing.'],
            'owner' => ['Account owner', 'Full control, including team, subscription and billing.'],
            'admin' => ['Administrator', 'Manages projects, campaigns and the team, not the subscription.'],
            'manager' => ['Account manager', 'Manages campaigns and reports on assigned projects.'],
            'analyst' => ['Analyst', 'Reads and builds reports and analysis, without changing campaigns.'],
            'viewer' => ['Viewer', 'Reads dashboards and reports only.'],
            'member' => ['Team member', 'Works on assigned projects.'],
            'client_viewer' => ['Viewer', 'Reads the performance of your campaigns and your reports only.'],
            'client_approver' => ['Approver', 'Reads performance and reports, approves quotes, and replies to requests and messages.'],
            'client_admin' => ['Client administrator', 'Everything above, and manages who can reach your portal.'],
        ];

        $known = $roles[$this->roleSlug] ?? null;

        // An unknown role never prints its raw slug and is never blank: Arabic gets the generic «عضو»,
        // English the slug in words. No description — see the class docblock.
        if ($known === null) {
            $words = trim((string) preg_replace('/[\s_\-]+/', ' ', $this->roleSlug));

            return ['name' => $ar ? 'عضو' : ($words === '' ? 'Member' : ucfirst(strtolower($words))), 'note' => ''];
        }

        return ['name' => $known[0], 'note' => $known[1]];
    }
}
