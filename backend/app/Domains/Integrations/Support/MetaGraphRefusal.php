<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

/**
 * META-GRAPH-REFUSAL-001 — one Graph refusal, read by Meta's own code, with who acts and how.
 *
 * Reads the stored form `PlatformHttp::reason()` writes: `<sentence> · code N · subcode M · trace T`, plus the
 * `(#N)` head Graph puts on many sentences. The tables are Meta's published ones (Graph API «Handling Errors»,
 * Marketing API «Rate Limiting» and «Error Reference»); a code they do not list stays UNCLASSIFIED and says so.
 *
 * A rate limit fixed by waiting and a token fixed only by a new consent need opposite actions, and a dead token
 * or a throttle also means the next call is wasted: {@see self::stopsFurtherCalls()}.
 */
final readonly class MetaGraphRefusal
{
    public const SESSION_INVALID = 'session_invalid';

    public const PERMISSION_MISSING = 'permission_missing';

    public const ACCOUNT_UNREACHABLE = 'account_unreachable';

    public const RATE_LIMITED = 'rate_limited';

    public const TRANSIENT = 'transient';

    public const DATA_VOLUME = 'data_volume';

    public const REQUEST_REJECTED = 'request_rejected';

    public const POLICY_BLOCK = 'policy_block';

    public const UNCLASSIFIED = 'unclassified';

    /** Rate limits: application, user, per-API, app limit, ad account, and the Business Use Case family. */
    private const RATE_LIMIT_CODES = [4, 17, 32, 341, 613];

    private function __construct(
        public string $kind,
        public ?int $code,
        public ?int $subcode,
        public ?string $trace,
    ) {}

    public static function read(?string $message): ?self
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        $code = self::number('/(?<![\w])code (\d+)(?!\d)/', $message) ?? self::number('/\(#(\d+)\)/', $message);
        $subcode = self::number('/(?<![\w])subcode (\d+)(?!\d)/', $message);
        $trace = preg_match('/(?<![\w])trace (\S+)/', $message, $m) === 1 ? $m[1] : null;

        return new self(self::kindOf($message, $code, $subcode), $code, $subcode, $trace);
    }

    public function actor(): string
    {
        return match ($this->kind) {
            self::SESSION_INVALID, self::PERMISSION_MISSING, self::ACCOUNT_UNREACHABLE, self::POLICY_BLOCK => 'customer',
            self::RATE_LIMITED, self::TRANSIENT, self::DATA_VOLUME => 'nobody',
            default => 'product',
        };
    }

    /** Asking again now cannot help: the token is dead, or Meta has just said to slow down. */
    public function stopsFurtherCalls(): bool
    {
        return $this->kind === self::SESSION_INVALID || $this->kind === self::RATE_LIMITED;
    }

    public function actionAr(): string
    {
        return match ($this->kind) {
            self::SESSION_INVALID => $this->isCheckpoint()
                ? 'أوقفت Meta جلسة صاحب الربط لفحص أمني. يسجّل الدخول في facebook.com لإنهاء الفحص، ثم يعيد الربط من هذه البطاقة.'
                : 'لم تعد Meta تقبل هذا التفويض. يعيد صاحب الربط الربط من هذه البطاقة، وتبقى الحسابات المختارة كما هي.',
            self::PERMISSION_MISSING => 'لم تُمنح الصلاحية المطلوبة. يمنح مالك الحساب الإعلاني صلاحية ads_read لهذا المستخدم من إعدادات Business Manager، وتُستأنف المزامنة في الجولة التالية.',
            self::ACCOUNT_UNREACHABLE => 'لا يصل هذا التفويض إلى الحساب الإعلاني: إما حُذف الحساب أو أُزيلت صلاحية المستخدم عليه. يتحقق مالك الحساب من وصول المستخدم في Business Manager.',
            self::RATE_LIMITED => 'حدّت Meta من عدد الطلبات مؤقتًا. توقفت الطلبات المتبقية في هذه الجولة وتُستأنف في الجولة التالية، ولا يلزم أي إجراء.',
            self::TRANSIENT => 'تعذّر الرد من Meta مؤقتًا. تُعاد المحاولة في الجولة التالية، ولا يلزم أي إجراء.',
            self::DATA_VOLUME => 'رفضت Meta حجم الصفحة المطلوبة. يعيد النظام الطلب بصفحة أصغر تلقائيًا، ولا يلزم أي إجراء.',
            self::REQUEST_REJECTED => 'رفضت Meta معاملًا في طلب كامبينز هب. المشكلة في الطلب لا في الحساب، والتفاصيل الفنية أدناه للفريق.',
            self::POLICY_BLOCK => 'حظرت Meta هذا الإجراء مؤقتًا بسبب مخالفة سياسة. يراجع صاحب الحساب حالة الحساب في Meta.',
            default => 'رد غير مصنّف من Meta. التفاصيل الفنية أدناه للفريق.',
        };
    }

    public function actionEn(): string
    {
        return match ($this->kind) {
            self::SESSION_INVALID => $this->isCheckpoint()
                ? 'Meta paused this person’s session for a security check. They sign in at facebook.com to clear it, then reconnect from this card.'
                : 'Meta no longer accepts this authorisation. Its owner reconnects from this card; the selected accounts stay as they are.',
            self::PERMISSION_MISSING => 'The required permission was not granted. The ad account’s owner grants this user ads_read in Business Manager settings, and the next sweep resumes.',
            self::ACCOUNT_UNREACHABLE => 'This authorisation cannot reach the ad account: it was deleted, or the user’s access to it was removed. The account’s owner checks the user’s access in Business Manager.',
            self::RATE_LIMITED => 'Meta is limiting requests for now. The rest of this sweep stopped asking and the next sweep resumes; nothing is needed.',
            self::TRANSIENT => 'Meta could not answer for now. The next sweep tries again; nothing is needed.',
            self::DATA_VOLUME => 'Meta refused the size of the requested page. The sync asks again with a smaller page by itself; nothing is needed.',
            self::REQUEST_REJECTED => 'Meta rejected a parameter in CampaignsHub’s request. This is the request and not the account; the technical details below are for the team.',
            self::POLICY_BLOCK => 'Meta has temporarily blocked this action for a policy violation. The account’s owner reviews its status at Meta.',
            default => 'An unclassified answer from Meta. The technical details below are for the team.',
        };
    }

    /**
     * @return array{kind:string,actor:string,code:?int,subcode:?int,trace:?string,action_ar:string,action_en:string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'actor' => $this->actor(),
            'code' => $this->code,
            'subcode' => $this->subcode,
            'trace' => $this->trace,
            'action_ar' => $this->actionAr(),
            'action_en' => $this->actionEn(),
        ];
    }

    /** 459 «user checkpointed», 464 «unconfirmed user»: Meta's table says they must log in at facebook.com. */
    private function isCheckpoint(): bool
    {
        return in_array($this->subcode, [459, 464], true);
    }

    private static function kindOf(string $message, ?int $code, ?int $subcode): string
    {
        // Code 1 is Meta's catch-all, and this sentence inside it has a different cure from waiting.
        if (stripos($message, 'reduce the amount of data') !== false) {
            return self::DATA_VOLUME;
        }

        if ($code !== null) {
            return match (true) {
                $code === 190, $code === 102 => self::SESSION_INVALID,
                $code === 3, $code === 10, $code >= 200 && $code <= 299 => self::PERMISSION_MISSING,
                $code === 100 && $subcode === 33 => self::ACCOUNT_UNREACHABLE,
                // Code 100 is Meta's general parameter refusal, and it also says «(#100) Missing Permission» on a grant.
                $code === 100 && stripos($message, 'permission') !== false => self::PERMISSION_MISSING,
                in_array($code, self::RATE_LIMIT_CODES, true), $code >= 80000 && $code <= 80014 => self::RATE_LIMITED,
                $code === 1, $code === 2 => self::TRANSIENT,
                $code === 368 => self::POLICY_BLOCK,
                $code === 100 => self::REQUEST_REJECTED,
                default => self::UNCLASSIFIED,
            };
        }

        return match (true) {
            preg_match('/request limit reached|too many calls|exceeded the rate limit/i', $message) === 1 => self::RATE_LIMITED,
            preg_match('/cURL error|timed? ?out|temporarily unavailable/i', $message) === 1 => self::TRANSIENT,
            preg_match('/validating access token|session has (been invalidated|expired)/i', $message) === 1 => self::SESSION_INVALID,
            preg_match('/permission/i', $message) === 1 => self::PERMISSION_MISSING,
            default => self::UNCLASSIFIED,
        };
    }

    private static function number(string $pattern, string $message): ?int
    {
        return preg_match($pattern, $message, $m) === 1 ? (int) $m[1] : null;
    }
}
