<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The API answers in the customer's language (I18N-001).
 *
 * Before this the backend had no `lang/` directory at all: an Arabic sign-in form labelled «البريد
 * الإلكتروني» refused with "These credentials do not match our records.", and every validation
 * error, every expired session and every rate limit came back in Laravel's English. The interface
 * was translated and the answers were not — which matters most at exactly the moment something has
 * gone wrong and the person needs to read what it says.
 *
 * What these tests hold in place is not "Arabic exists somewhere". It is that Arabic is the DEFAULT,
 * that English is still reachable on request, and that no message has been left behind in one
 * language while its neighbour was translated.
 */
final class ApiLanguageTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private function user(): User
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u@lang.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);

        return $user->refresh();
    }

    // ── The default ───────────────────────────────────────────────────────────────────────────

    /**
     * A caller that expresses no preference gets Arabic.
     *
     * This is the case that was wrong: an Arabic-first product whose framework default was English,
     * so anything not routed through a translated string spoke the framework's language rather than
     * the product's.
     */
    public function test_a_request_that_says_nothing_is_answered_in_arabic(): void
    {
        $this->user();

        $message = (string) $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/login', ['email' => 'u@lang.test', 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->json('errors.email.0');

        $this->assertSame('بيانات الدخول غير صحيحة.', $message);
    }

    /** English is still there for anyone who asks for it — the product is bilingual, not Arabic-only. */
    public function test_english_is_answered_when_it_is_asked_for(): void
    {
        $this->user();

        $message = (string) $this->withHeaders($this->spa + ['Accept-Language' => 'en'])
            ->postJson('/api/v1/auth/login', ['email' => 'u@lang.test', 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->json('errors.email.0');

        $this->assertSame('These credentials do not match our records.', $message);
    }

    // ── Header parsing ────────────────────────────────────────────────────────────────────────

    /**
     * `Accept-Language` is a RANKED list, and the ranking is honoured.
     *
     * Taking the first tag would answer `en-GB,ar;q=0.9` in English, which is right, and
     * `en;q=0.3,ar;q=0.9` in English too, which is wrong — that caller said plainly that they prefer
     * Arabic. A browser sends whatever the operating system is set to, so these lists are real.
     */
    #[DataProvider('languageHeaders')]
    public function test_the_language_header_is_read_as_a_ranked_list(string $header, string $expected): void
    {
        $request = Request::create('/api/v1/anything');
        $request->headers->set('Accept-Language', $header);

        $this->assertSame($expected, SetLocale::resolve($request), "header: {$header}");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function languageHeaders(): array
    {
        return [
            'a bare tag' => ['ar', 'ar'],
            'a region subtag is dropped' => ['ar-SA', 'ar'],
            'an underscore region' => ['en_US', 'en'],
            'the usual browser list' => ['ar-SA,ar;q=0.9,en;q=0.8', 'ar'],
            'first tag wins at equal weight' => ['en-GB,ar;q=0.9', 'en'],
            'weight beats position' => ['en;q=0.3,ar;q=0.9', 'ar'],
            'a language we do not have falls back' => ['fr-FR,de;q=0.8', 'ar'],
            'an unsupported first choice yields to a supported second' => ['fr,en;q=0.5', 'en'],
            'nothing at all' => ['', 'ar'],
            'whitespace and casing' => [' AR-sa , en;q=0.2 ', 'ar'],
            // A duplicate at a lower weight is the same language and must not displace its own
            // earlier, higher-weighted entry.
            'a repeated language keeps its best weight' => ['ar,en;q=0.9,ar;q=0.1', 'ar'],
        ];
    }

    // ── The whole envelope, not just the strings somebody remembered ───────────────────────────

    /**
     * Validation messages come from the framework, and they are translated too.
     *
     * This is the highest-leverage part of the unit: every validated field in the application draws
     * its message from `lang/{locale}/validation.php`, so the alternative was translating a few
     * forms by hand and leaving the rest in English.
     */
    public function test_validation_messages_and_field_names_are_arabic(): void
    {
        $errors = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->json();

        $this->assertSame('البيانات المُدخلة غير صحيحة.', $errors['message']);
        $this->assertSame('يجب أن يكون البريد الإلكتروني بريدًا إلكترونيًا صحيحًا.', $errors['errors']['email'][0]);

        // The FIELD NAME is translated as well. Without that the message reads «حقل password مطلوب»
        // — half translated, which is more jarring than not translating it at all.
        $this->assertSame('حقل كلمة المرور مطلوب.', $errors['errors']['password'][0]);
    }

    /** The failures a customer meets without reaching a controller at all. */
    public function test_the_envelope_errors_are_translated(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'الجلسة غير صالحة. الرجاء تسجيل الدخول.');

        $this->getJson('/api/v1/no-such-endpoint')
            ->assertNotFound()
            ->assertJsonPath('message', 'العنصر المطلوب غير موجود.');

        $this->withHeaders(['Accept-Language' => 'en'])
            ->getJson('/api/v1/no-such-endpoint')
            ->assertNotFound()
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    /**
     * A 404 is raised BEFORE the API middleware group runs, and is still translated.
     *
     * The locale is resolved a second time inside the exception renderer for exactly this: a path no
     * middleware group owns never reaches `SetLocale`, and answering those in the framework's
     * language would leave the most common error in the application untranslated.
     */
    public function test_a_failure_raised_before_the_middleware_is_still_translated(): void
    {
        $this->getJson('/nothing/here/at/all')
            ->assertNotFound()
            ->assertJsonPath('message', 'العنصر المطلوب غير موجود.');
    }

    /** The success side too — a caller that names no message gets the product's own. */
    public function test_the_default_success_message_follows_the_request(): void
    {
        $this->user();

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/login', ['email' => 'u@lang.test', 'password' => 'secret1234'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تسجيل الدخول بنجاح.');
    }

    // ── Nothing left behind ───────────────────────────────────────────────────────────────────

    /**
     * Every key in an English file has an Arabic counterpart, and the reverse.
     *
     * A missing key does not fail loudly — Laravel renders it as the key itself, so `auth.signed_in`
     * would appear verbatim on the screen. This is the check that catches a message added to one
     * language and forgotten in the other, which is how a translation set decays.
     */
    public function test_no_message_exists_in_one_language_and_not_the_other(): void
    {
        foreach (['auth', 'api'] as $file) {
            $ar = require lang_path("ar/{$file}.php");
            $en = require lang_path("en/{$file}.php");

            $this->assertSame(
                array_keys($en),
                array_keys($ar),
                "lang/{$file}.php has drifted between Arabic and English",
            );

            foreach ($ar as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "ar.{$file}.{$key} is empty");
                $this->assertNotSame(
                    $en[$key],
                    $value,
                    "ar.{$file}.{$key} is identical to the English — it was copied, not translated",
                );
            }
        }
    }

    /**
     * Numbers stay in Latin digits (product rule), including inside Arabic messages.
     *
     * A size limit or a plan cap rendered in Eastern-Arabic numerals cannot be compared against what
     * the customer typed into the field, and cannot be pasted into a support conversation.
     */
    public function test_arabic_messages_do_not_use_eastern_arabic_numerals(): void
    {
        $offenders = [];

        foreach (glob(lang_path('ar/*.php')) as $file) {
            $messages = require $file;

            array_walk_recursive(
                $messages,
                function (string $value, string $key) use (&$offenders, $file): void {
                    if (preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $value) === 1) {
                        $offenders[] = basename($file).':'.$key;
                    }
                },
            );
        }

        $this->assertSame([], $offenders, 'Arabic messages must use Latin digits');
    }
}
