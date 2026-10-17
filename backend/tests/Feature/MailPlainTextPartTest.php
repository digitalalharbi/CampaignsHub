<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** EMAIL-PLAINTEXT-001 — an HTML message leaves with its text alternative; an authored text part is left alone. */
final class MailPlainTextPartTest extends TestCase
{
    public function test_an_html_message_gains_a_text_part_and_an_authored_one_is_kept(): void
    {
        config()->set('mail.default', 'array');

        Mail::html('<p>Hello <a href="https://campaignshub.io/x">open</a></p>', fn ($m) => $m->to('a@example.test')->subject('s'));
        Mail::send([], [], function ($m) {
            $m->to('b@example.test')->subject('s')->html('<p>Html</p>')->text('Authored text');
        });

        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('open (https://campaignshub.io/x)', (string) $sent[0]->getOriginalMessage()->getTextBody());
        $this->assertSame('Authored text', (string) $sent[1]->getOriginalMessage()->getTextBody());
    }
}
