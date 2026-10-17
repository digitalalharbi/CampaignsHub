<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Notifications\Support\PlainText;
use PHPUnit\Framework\TestCase;

/** EMAIL-PLAINTEXT-001 — the text part is the HTML's own words, links kept, nothing hidden leaking in. */
final class PlainTextTest extends TestCase
{
    public function test_it_keeps_words_and_link_addresses_and_drops_markup_and_hidden_preheader(): void
    {
        $html = '<html><head><style>.a{color:red}</style><title>t</title></head><body>'
            .'<div style="display:none">preheader secret</div>'
            .'<img src="x.png" alt="CampaignsHub">'
            .'<h1>Reset your password</h1><p>Hello &amp; welcome<br>Use the button.</p>'
            .'<a href="https://campaignshub.io/reset?t=1">Choose a new password</a>'
            .'<table><tr><td>Spend</td><td>12,400 USD</td></tr></table></body></html>';

        $text = PlainText::fromHtml($html);

        $this->assertStringContainsString('Reset your password', $text);
        $this->assertStringContainsString('Hello & welcome', $text);
        $this->assertStringContainsString('Choose a new password (https://campaignshub.io/reset?t=1)', $text);
        $this->assertStringContainsString('Spend 12,400 USD', $text);
        $this->assertStringNotContainsString('preheader secret', $text);
        $this->assertStringNotContainsString('color:red', $text);
        $this->assertStringNotContainsString('<', $text);
    }
}
