<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use App\Domains\Notifications\Support\MailShell;
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

        return new Envelope(subject: $ar
            ? "CampaignsHub — دعوة للانضمام إلى {$this->workspace}"
            : "CampaignsHub — You have been invited to join {$this->workspace}");
    }

    public function content(): Content
    {
        $ar = $this->lang === 'ar';
        $role = $this->role($ar);

        $intro = $this->invitedBy !== ''
            ? ($ar
                ? "دعاك {$this->invitedBy} للانضمام إلى مساحة عمل {$this->workspace} على CampaignsHub، حيث تتابع الفرق حملاتها الإعلانية المدفوعة في مكان واحد."
                : "{$this->invitedBy} has invited you to join the {$this->workspace} workspace on CampaignsHub, where teams follow their paid advertising campaigns in one place.")
            : ($ar
                ? "لديك دعوة للانضمام إلى مساحة عمل {$this->workspace} على CampaignsHub، حيث تتابع الفرق حملاتها الإعلانية المدفوعة في مكان واحد."
                : "You have been invited to join the {$this->workspace} workspace on CampaignsHub, where teams follow their paid advertising campaigns in one place.");

        $shell = MailShell::build(
            lang: $this->lang,
            subject: $ar ? 'دعوة للانضمام' : 'An invitation to join',
            preheader: $intro,
            // The recipient has no account and therefore no preferences to manage.
            preferences: false,
            footerNote: $ar
                ? 'وصلتك هذه الرسالة لأن أحد أعضاء الفريق أدخل بريدك الإلكتروني عند إرسال الدعوة.'
                : 'You received this because a member of the team entered your email address when sending the invitation.',
        );

        $body = $shell + [
            'title' => $ar ? 'دعوة للانضمام إلى الفريق' : 'An invitation to join the team',
            'intro' => $intro,
            'workspace' => $this->workspace,
            'roleName' => $role['name'],
            'roleNote' => $role['note'],
            'actionUrl' => $this->acceptUrl,
            'actionLabel' => $ar ? 'قبول الدعوة' : 'Accept the invitation',
            'validity' => $ar
                ? "هذه الدعوة صالحة لمدة {$this->expiresInHours} ساعة، وبعدها يمكن لأي عضو في الفريق إرسال دعوة جديدة."
                : "This invitation is valid for {$this->expiresInHours} hours. After that, any member of the team can send a new one.",
        ];

        $body['t'] = $shell['t'] + ($ar
            ? [
                'workspace' => 'مساحة العمل',
                'role' => 'دورك',
                'or_paste' => 'إذا لم يعمل الزر، انسخ هذا الرابط والصقه في المتصفح:',
                'not_expecting' => 'إذا لم تكن تتوقع هذه الدعوة، يمكنك تجاهل الرسالة. لن يُفتح أي حساب على بريدك ما لم تقبل الدعوة بنفسك.',
            ]
            : [
                'workspace' => 'Workspace',
                'role' => 'Your role',
                'or_paste' => 'If the button does not work, copy this link into your browser:',
                'not_expecting' => 'If you were not expecting this invitation, you can ignore this message. No account will be opened on your address unless you accept it yourself.',
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
            'owner' => ['مالك الحساب', 'صلاحية كاملة على مساحة العمل، بما في ذلك الفريق والاشتراك والفواتير.'],
            'admin' => ['مدير', 'إدارة المشاريع والحملات والفريق، دون الوصول إلى إعدادات الاشتراك.'],
            'manager' => ['مدير حسابات', 'متابعة المشاريع المسندة إليه وإدارة حملاتها وتقاريرها.'],
            'analyst' => ['محلل', 'الاطلاع على البيانات والتقارير وإنشاؤها، دون تعديل الحملات.'],
            'viewer' => ['مطّلع', 'الاطلاع على لوحات المتابعة والتقارير فقط.'],
            'member' => ['عضو فريق', 'العمل على المشاريع المسندة إليه داخل مساحة العمل.'],
        ] : [
            'owner' => ['Account owner', 'Full control of the workspace, including the team, the subscription and billing.'],
            'admin' => ['Administrator', 'Manages projects, campaigns and the team, without access to subscription settings.'],
            'manager' => ['Account manager', 'Follows their assigned projects and manages the campaigns and reports within them.'],
            'analyst' => ['Analyst', 'Reads and builds reports and analysis, without changing campaigns.'],
            'viewer' => ['Viewer', 'Reads dashboards and reports only.'],
            'member' => ['Team member', 'Works on the projects assigned to them inside the workspace.'],
        ];

        $known = $roles[$this->roleSlug] ?? null;

        // An unknown role reads as its own slug, with NO description. See the class docblock.
        return $known === null
            ? ['name' => $this->roleSlug, 'note' => '']
            : ['name' => $known[0], 'note' => $known[1]];
    }
}
