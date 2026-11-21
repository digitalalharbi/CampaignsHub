<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Notifications\Support\NotificationChannelPolicy;
use App\Domains\Notifications\Support\WhatsAppTemplates;
use PHPUnit\Framework\TestCase;

/**
 * NOTIFICATION-CHANNELS-001 — nothing can silently be in-app-only again.
 *
 * `automation_rule` was raised by `RuleEvaluator` for as long as automation existed and reached no inbox,
 * because the dispatcher kept two hand-written lists and nobody was made to extend them. This census reads
 * `app/` for every type a bell notification can be raised with and fails for one the policy cannot
 * classify, for a file that raises notifications and is not declared, and for a policy entry that claims a
 * bell item nothing raises. A type added without a decision about its channels is a red build, not a quiet
 * gap.
 */
final class NotificationChannelPolicyTest extends TestCase
{
    /** @return array<string, string> relative path (under app/) → source */
    private function sources(): array
    {
        $out = [];
        $base = dirname(__DIR__, 2).'/app/';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[substr($file->getPathname(), strlen($base))] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /** @return list<string> files that call `->dispatch([` on the notification dispatcher */
    private function raisers(): array
    {
        $found = [];
        foreach ($this->sources() as $path => $source) {
            if (preg_match('/(?:notifications|dispatcher|inApp)->dispatch\(\[|app\(NotificationDispatcher::class\)->dispatch\(\[/', $source) === 1
                && ! str_ends_with($path, 'Services/NotificationDispatcher.php')) {
                $found[] = $path;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Every literal a bell notification type can be, as far as the source says.
     *
     * @return array<string, string> literal → where it was read
     */
    private function dispatchedLiterals(): array
    {
        $sources = $this->sources();
        $literals = [];
        $add = function (string $lit, string $where) use (&$literals): void {
            $literals[$lit] ??= $where;
        };

        foreach ($this->raisers() as $path) {
            $source = $sources[$path];

            if (preg_match_all("/'type'\s*=>\s*'([a-z_.]+)'/", $source, $m)) {
                foreach ($m[1] as $lit) {
                    $add($lit, $path);
                }
            }
            if (preg_match_all('/\'type\'\s*=>\s*"([a-z_.]+)\{/', $source, $m)) {
                foreach ($m[1] as $lit) {
                    $add($lit.'x', $path); // an interpolated suffix: `request.journey.{$stage}`
                }
            }
            if (preg_match_all('/->tell\(\$[a-z]+,\s*\'([a-z_]+)\'/', $source, $m)) {
                foreach ($m[1] as $lit) {
                    $add($lit, $path);
                }
            }
            if (preg_match_all("/NOTIFICATION_TYPE\[[^\]]+\]\s*\?\?\s*'([a-z_]+)'/", $source, $m)) {
                foreach ($m[1] as $lit) {
                    $add($lit, $path);
                }
            }
            if (preg_match('/NOTIFICATION_TYPE\s*=\s*\[(.*?)\];/s', $source, $block)) {
                preg_match_all("/=>\s*'([a-z_]+)'/", $block[1], $m);
                foreach ($m[1] as $lit) {
                    $add($lit, $path);
                }
            }
        }

        // RequestNotifier takes the type as an argument, so its callers are where the literals are.
        foreach ($sources as $path => $source) {
            if (preg_match_all("/(?:\\$[a-zA-Z]+|->)notifier->notify\(\\$[a-z]+,\s*'([a-z_.]+)'/", $source, $m)) {
                foreach ($m[1] as $lit) {
                    $add($lit, $path);
                }
            }
            if (preg_match("/->tell\(\\\$report, 'report_(?:ready|failed)'/", $source) === 1 && str_contains($path, 'GenerateReportJob')) {
                $add('report_ready', $path);
            }
        }

        return $literals;
    }

    public function test_every_file_that_raises_a_notification_is_declared_here(): void
    {
        $raisers = $this->raisers();
        $declared = NotificationChannelPolicy::SENDERS;
        sort($declared);

        // The test-notification controller raises through the same dispatcher; everything else is a domain sender.
        $this->assertSame($declared, $raisers, "A file that raises notifications is not in NotificationChannelPolicy::SENDERS (or one listed no longer does).\nDeclare it AND classify its types in the policy.");
    }

    public function test_every_type_a_dispatcher_call_can_raise_is_classified(): void
    {
        $literals = $this->dispatchedLiterals();

        // Not vacuous: the census must actually be reading the senders it exists to police.
        foreach (['automation_rule', 'report_ready', 'message', 'budget_risk', 'request.assigned', 'subscription', 'client_needs_attention', 'test_notification'] as $known) {
            $this->assertArrayHasKey($known, $literals, "the census no longer finds «{$known}» — its regexes have gone blind");
        }

        $unclassified = [];
        foreach ($literals as $literal => $where) {
            if (NotificationChannelPolicy::resolve($literal) === null) {
                $unclassified[] = "{$literal}  ({$where})";
            }
        }

        $this->assertSame([], $unclassified, "These notification types have no channel decision:\n".implode("\n", $unclassified)."\nAdd each to NotificationChannelPolicy (and MessageCatalogue when a person may switch it).");
    }

    public function test_a_bell_item_is_claimed_only_for_a_type_something_raises(): void
    {
        $raised = [];
        foreach (array_keys($this->dispatchedLiterals()) as $literal) {
            $key = NotificationChannelPolicy::resolve($literal);
            if ($key !== null) {
                $raised[$key] = true;
            }
        }

        foreach (NotificationChannelPolicy::types() as $type => $entry) {
            if ($entry['in_app']) {
                $this->assertArrayHasKey($type, $raised, "«{$type}» claims a bell item and nothing raises it");
            } else {
                $this->assertArrayNotHasKey($type, $raised, "«{$type}» is raised in-app but the policy says it has no bell item");
            }
        }
    }

    public function test_every_catalogue_type_has_a_route_and_the_flags_agree_with_the_catalogue(): void
    {
        $this->assertSame([], array_values(array_diff(MessageCatalogue::keys(), NotificationChannelPolicy::keys())), 'a catalogue type has no channel route');

        foreach (MessageCatalogue::types() as $type => $def) {
            $entry = NotificationChannelPolicy::types()[$type];
            $this->assertSame($def['mandatory'], $entry['mandatory'], $type);
            $this->assertSame($def['mandatory'], $entry['email'] === 'mandatory', "{$type}: mandatory and email=mandatory must be one fact");
            $this->assertSame(isset(MessageCatalogue::DIGEST_SWITCH[$type]), $entry['email'] === 'digest', "{$type}: only the digests are email=digest");
            $this->assertSame($def['category'], $entry['category'], $type);
            $this->assertSame($def['default_email'], $entry['default_email'], $type);
            if ($entry['email'] === 'digest' || $entry['mandatory']) {
                $this->assertFalse($entry['in_app'], "{$type} has no bell item");
            }
        }
    }

    public function test_what_the_dispatcher_mails_itself_is_never_held_for_a_digest_nothing_prints(): void
    {
        foreach (NotificationChannelPolicy::types() as $type => $entry) {
            if ($entry['via'] === 'dispatcher') {
                $this->assertTrue($entry['in_app'], "{$type} is mailed by the dispatcher so it must come through the bell");
                $this->assertFalse($entry['digestible'], "{$type} offers a digest rhythm but no digest carries it");
                $this->assertContains($entry['email'], ['immediate'], $type);
            }
        }
    }

    public function test_whatsapp_planned_types_and_their_templates_are_the_same_list_and_secrets_never_go(): void
    {
        $planned = [];
        foreach (NotificationChannelPolicy::types() as $type => $entry) {
            if ($entry['whatsapp'] === 'planned') {
                $planned[] = $type;
            }
            if (MessageCatalogue::carriesSecret($type) || $entry['email'] === 'digest') {
                $this->assertSame('never', $entry['whatsapp'], "{$type} must never be sent on WhatsApp");
            }
        }
        sort($planned);
        $templated = WhatsAppTemplates::types();
        sort($templated);

        $this->assertSame($planned, $templated, 'every WhatsApp-planned type needs exactly one template, and no template may exist for another');

        foreach ($templated as $type) {
            foreach (['ar', 'en'] as $locale) {
                $body = (string) WhatsAppTemplates::body($type, $locale);
                $this->assertStringContainsString('{{1}}', $body);
                $this->assertStringContainsString('{{2}}', $body);
                $this->assertDoesNotMatchRegularExpression('/^\s*\{\{|\}\}\s*$/', $body, "{$type}.{$locale}: a template may not start or end with a variable");
            }
        }
    }

    public function test_request_and_journey_strings_resolve_to_their_catalogue_type(): void
    {
        $this->assertSame('request', NotificationChannelPolicy::resolve('request.sla_breached'));
        $this->assertSame('request', NotificationChannelPolicy::resolve('received'));
        $this->assertSame('journey_transition', NotificationChannelPolicy::resolve('request.journey.paid'));
        $this->assertNull(NotificationChannelPolicy::resolve('somebody_elses_type'));
        $this->assertSame('none', NotificationChannelPolicy::routeFor('somebody_elses_type'), 'an unclassified type is never mailed by default');
    }
}
