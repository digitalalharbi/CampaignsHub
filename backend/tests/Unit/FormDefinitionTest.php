<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Forms\Support\FormDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * FORMS-HUB-001 — what a form definition may say.
 */
final class FormDefinitionTest extends TestCase
{
    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function valid(array $over = []): array
    {
        return array_replace_recursive([
            'steps' => [[
                'id' => 's1', 'title' => 'You',
                'fields' => [
                    ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                    ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
                ],
            ]],
            'consent' => ['required' => true, 'text' => ['ar' => 'أوافق', 'en' => 'I agree']],
        ], $over);
    }

    private function refused(array $definition): string
    {
        try {
            FormDefinition::normalise($definition);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $this->fail('The definition was accepted.');
    }

    public function test_a_valid_definition_is_normalised_with_a_consent_version_that_follows_the_text(): void
    {
        $a = FormDefinition::normalise($this->valid());
        $b = FormDefinition::normalise($this->valid(['consent' => ['text' => ['en' => 'I agree to be called']]]));

        $this->assertSame(12, strlen($a['consent']['version']));
        $this->assertNotSame($a['consent']['version'], $b['consent']['version'], 'Change the wording, change the version.');
        $this->assertTrue($a['steps'][0]['fields'][0]['required'], 'The name the lead cannot exist without is never optional.');
    }

    public function test_a_form_needs_a_name_and_a_way_to_reach_the_person(): void
    {
        $noName = $this->valid();
        $noName['steps'][0]['fields'][0] = ['id' => 'x', 'type' => 'text', 'label' => 'X'];
        $this->assertStringContainsString('name', $this->refused($noName));

        $noContact = $this->valid();
        array_pop($noContact['steps'][0]['fields']);
        $this->assertStringContainsString('email or phone', $this->refused($noContact));
    }

    public function test_a_mapped_field_has_the_type_its_column_needs_and_each_column_is_mapped_once(): void
    {
        $wrongType = $this->valid();
        $wrongType['steps'][0]['fields'][1] = ['id' => 'mobile', 'type' => 'text', 'label' => 'Mobile', 'maps_to' => 'phone'];
        $this->assertStringContainsString('must be of type phone', $this->refused($wrongType));

        $twice = $this->valid();
        $twice['steps'][0]['fields'][] = ['id' => 'other', 'type' => 'text', 'label' => 'Other', 'maps_to' => 'name'];
        $this->assertStringContainsString('Two fields map', $this->refused($twice));
    }

    public function test_conditional_logic_may_only_look_backwards(): void
    {
        $forward = $this->valid();
        $forward['steps'][0]['fields'][0]['show_if'] = ['field' => 'mobile', 'op' => 'filled'];
        $this->assertStringContainsString('does not come earlier', $this->refused($forward));

        $ok = $this->valid();
        $ok['steps'][0]['fields'][] = ['id' => 'budget', 'type' => 'number', 'label' => 'Budget', 'show_if' => ['field' => 'mobile', 'op' => 'filled']];
        $this->assertSame('filled', FormDefinition::normalise($ok)['steps'][0]['fields'][2]['show_if']['op']);
    }

    public function test_ids_are_unique_types_are_known_and_options_are_required_where_they_matter(): void
    {
        $dup = $this->valid();
        $dup['steps'][0]['fields'][] = ['id' => 'mobile', 'type' => 'text', 'label' => 'Again'];
        $this->assertStringContainsString('used twice', $this->refused($dup));

        $type = $this->valid();
        $type['steps'][0]['fields'][] = ['id' => 'f', 'type' => 'file', 'label' => 'File'];
        $this->assertStringContainsString('unknown type', $this->refused($type));

        $noOptions = $this->valid();
        $noOptions['steps'][0]['fields'][] = ['id' => 'city', 'type' => 'select', 'label' => 'City', 'options' => []];
        $this->assertStringContainsString('options', $this->refused($noOptions));
    }

    public function test_no_pattern_validation_is_accepted_or_kept(): void
    {
        $d = $this->valid();
        $d['steps'][0]['fields'][0]['validation'] = ['pattern' => '(a+)+$', 'max_length' => 40];
        $out = FormDefinition::normalise($d);

        $this->assertSame(['max_length' => 40], $out['steps'][0]['fields'][0]['validation']);
    }

    public function test_consent_text_is_required_when_consent_is_and_the_redirect_must_be_https(): void
    {
        $this->assertStringContainsString('consent text', $this->refused($this->valid(['consent' => ['text' => ['ar' => '', 'en' => '']]])));
        $this->assertStringContainsString('https', $this->refused($this->valid(['thank_you' => ['redirect_url' => 'javascript:alert(1)']])));
        $this->assertStringContainsString('https', $this->refused($this->valid(['thank_you' => ['redirect_url' => 'http://example.test/x']])));
    }
}
