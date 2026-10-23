<?php

declare(strict_types=1);

namespace App\Domains\Forms\Support;

use InvalidArgumentException;

/**
 * FORMS-HUB-001 — what a form definition may say, and the one place that decides it.
 *
 * A definition arrives from a builder, is stored as a version and is later rendered to strangers and
 * judged against what they send. Three things follow from that, and this class is where they are enforced
 * so neither the builder nor the public endpoint has to remember them:
 *
 *  - It is NORMALISED on the way in: unknown keys are cut, ids are checked, lengths are capped. A stored
 *    definition is therefore always one the renderer and the validator both understand.
 *  - It cannot ask for a person it cannot reach: a form needs a field mapped to the lead's `name` and at
 *    least one that reaches them (`email` or `phone`). A lead nobody can contact is an acquisition the
 *    client paid for and cannot work.
 *  - It carries no executable text: there is no pattern/regex validation (a stranger's input against an
 *    author's regex is a denial-of-service waiting for a pattern), and labels are plain strings.
 *
 * Conditional logic is `show_if` on a field, pointing at an EARLIER field. Forward references are refused
 * because «show this when a later answer is X» has no defined order of evaluation.
 */
final class FormDefinition
{
    public const FIELD_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'select', 'radio', 'checkbox', 'date'];

    public const MAPS = ['name', 'email', 'phone'];

    public const OPS = ['equals', 'not_equals', 'in', 'filled'];

    public const MAX_STEPS = 10;

    public const MAX_FIELDS = 40;

    private const OPTION_TYPES = ['select', 'radio', 'checkbox'];

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException carrying every problem found, one per line
     */
    public static function normalise(array $raw): array
    {
        $errors = [];
        $steps = [];
        $seen = [];
        $maps = [];
        $fieldCount = 0;

        $rawSteps = is_array($raw['steps'] ?? null) ? array_values($raw['steps']) : [];
        if ($rawSteps === []) {
            $errors[] = 'A form needs at least one step.';
        }
        if (count($rawSteps) > self::MAX_STEPS) {
            $errors[] = 'A form has at most '.self::MAX_STEPS.' steps.';
            $rawSteps = array_slice($rawSteps, 0, self::MAX_STEPS);
        }

        foreach ($rawSteps as $si => $rawStep) {
            $rawStep = is_array($rawStep) ? $rawStep : [];
            $fields = [];
            foreach (array_values(is_array($rawStep['fields'] ?? null) ? $rawStep['fields'] : []) as $rawField) {
                $fieldCount++;
                $field = self::field(is_array($rawField) ? $rawField : [], $seen, $maps, $errors);
                if ($field !== null) {
                    $seen[$field['id']] = $field['type'];
                    $fields[] = $field;
                }
            }
            if ($fields === []) {
                $errors[] = 'Step '.($si + 1).' has no fields.';
            }
            $steps[] = [
                'id' => self::id($rawStep['id'] ?? null, 's'.($si + 1)),
                'title' => self::text($rawStep['title'] ?? null, 120),
                'fields' => $fields,
            ];
        }

        if ($fieldCount > self::MAX_FIELDS) {
            $errors[] = 'A form has at most '.self::MAX_FIELDS.' fields.';
        }
        if (! isset($maps['name'])) {
            $errors[] = 'A form needs a field mapped to the lead\'s name.';
        }
        if (! isset($maps['email']) && ! isset($maps['phone'])) {
            $errors[] = 'A form needs a field mapped to email or phone, or the lead cannot be reached.';
        }

        $consent = is_array($raw['consent'] ?? null) ? $raw['consent'] : [];
        $required = (bool) ($consent['required'] ?? true);
        $consentText = self::text($consent['text'] ?? null, 1200);
        if ($required && self::isBlank($consentText)) {
            $errors[] = 'A form that asks for consent needs the consent text the person will read.';
        }

        $thank = is_array($raw['thank_you'] ?? null) ? $raw['thank_you'] : [];
        $redirect = isset($thank['redirect_url']) && is_string($thank['redirect_url']) && $thank['redirect_url'] !== '' ? $thank['redirect_url'] : null;
        if ($redirect !== null && (filter_var($redirect, FILTER_VALIDATE_URL) === false || ! str_starts_with($redirect, 'https://'))) {
            $errors[] = 'The thank-you redirect must be a full https:// address.';
            $redirect = null;
        }

        $style = is_array($raw['style'] ?? null) ? $raw['style'] : [];
        $accent = isset($style['accent']) && is_string($style['accent']) && preg_match('/^#[0-9a-fA-F]{6}$/', $style['accent']) === 1 ? strtolower($style['accent']) : null;
        $theme = in_array($style['theme'] ?? null, ['light', 'dark', 'auto'], true) ? $style['theme'] : 'auto';

        if ($errors !== []) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }

        return [
            'steps' => $steps,
            'consent' => [
                'required' => $required,
                'text' => $consentText,
                // The wording the person agreed to is identified, not just stored: change the text, change the version.
                'version' => substr(hash('sha256', json_encode($consentText, JSON_UNESCAPED_UNICODE) ?: ''), 0, 12),
            ],
            'thank_you' => ['message' => self::text($thank['message'] ?? null, 600), 'redirect_url' => $redirect],
            'style' => ['accent' => $accent, 'theme' => $theme],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  array<string,string>  $seen  earlier field ids → type
     * @param  array<string,string>  $maps  lead column → field id
     * @param  list<string>  $errors
     * @return ?array<string,mixed>
     */
    private static function field(array $raw, array $seen, array &$maps, array &$errors): ?array
    {
        $id = is_string($raw['id'] ?? null) ? $raw['id'] : '';
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $id) !== 1) {
            $errors[] = 'A field id is lowercase letters, digits and underscores, starting with a letter.';

            return null;
        }
        if (isset($seen[$id])) {
            $errors[] = "The field id «{$id}» is used twice.";

            return null;
        }
        $type = (string) ($raw['type'] ?? '');
        if (! in_array($type, self::FIELD_TYPES, true)) {
            $errors[] = "The field «{$id}» has an unknown type.";

            return null;
        }

        $field = [
            'id' => $id,
            'type' => $type,
            'label' => self::text($raw['label'] ?? null, 200),
            'required' => (bool) ($raw['required'] ?? false),
        ];
        if (self::isBlank($field['label'])) {
            $errors[] = "The field «{$id}» needs a label.";
        }

        if (in_array($type, self::OPTION_TYPES, true)) {
            $options = [];
            foreach (array_values(is_array($raw['options'] ?? null) ? $raw['options'] : []) as $o) {
                $o = is_array($o) ? $o : [];
                $value = is_scalar($o['value'] ?? null) ? mb_substr((string) $o['value'], 0, 80) : '';
                if ($value === '' || in_array($value, array_column($options, 'value'), true)) {
                    continue;
                }
                $options[] = ['value' => $value, 'label' => self::text($o['label'] ?? $value, 160)];
            }
            if (count($options) < 1 || count($options) > 50) {
                $errors[] = "The field «{$id}» needs between 1 and 50 options.";
            }
            $field['options'] = $options;
        }

        $v = is_array($raw['validation'] ?? null) ? $raw['validation'] : [];
        $validation = [];
        foreach (['min', 'max'] as $k) {
            if (isset($v[$k]) && is_numeric($v[$k]) && $type === 'number') {
                $validation[$k] = $v[$k] + 0;
            }
        }
        foreach (['min_length', 'max_length'] as $k) {
            if (isset($v[$k]) && is_int($v[$k]) && $v[$k] >= 0 && $v[$k] <= 3000 && in_array($type, ['text', 'textarea'], true)) {
                $validation[$k] = $v[$k];
            }
        }
        if ($validation !== []) {
            $field['validation'] = $validation;
        }

        $map = $raw['maps_to'] ?? null;
        if ($map !== null && $map !== '') {
            $expects = ['name' => 'text', 'email' => 'email', 'phone' => 'phone'];
            if (! in_array($map, self::MAPS, true)) {
                $errors[] = "The field «{$id}» maps to an unknown lead column.";
            } elseif (isset($maps[$map])) {
                $errors[] = "Two fields map to the lead's {$map}.";
            } elseif ($expects[$map] !== $type) {
                $errors[] = "The field «{$id}» maps to {$map} so it must be of type {$expects[$map]}.";
            } else {
                $maps[$map] = $id;
                $field['maps_to'] = $map;
                // The column the lead cannot be reached or named without is not optional.
                $field['required'] = true;
            }
        }

        if (is_array($raw['show_if'] ?? null)) {
            $c = $raw['show_if'];
            $ref = is_string($c['field'] ?? null) ? $c['field'] : '';
            $op = (string) ($c['op'] ?? 'equals');
            if (! isset($seen[$ref])) {
                $errors[] = "The field «{$id}» shows only when «{$ref}» says so, and «{$ref}» does not come earlier.";
            } elseif (! in_array($op, self::OPS, true)) {
                $errors[] = "The field «{$id}» uses an unknown condition.";
            } else {
                $value = $c['value'] ?? null;
                $field['show_if'] = [
                    'field' => $ref,
                    'op' => $op,
                    'value' => $op === 'filled' ? null : (is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : (is_scalar($value) ? (string) $value : null)),
                ];
            }
        }

        return $field;
    }

    private static function id(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $value) === 1 ? $value : $fallback;
    }

    /**
     * A string, or the {ar, en} pair — each cut to $max, nothing else kept.
     *
     * @return string|array<string,string>
     */
    public static function text(mixed $value, int $max): string|array
    {
        if (is_array($value)) {
            $out = [];
            foreach (['ar', 'en'] as $l) {
                if (isset($value[$l]) && is_string($value[$l]) && trim($value[$l]) !== '') {
                    $out[$l] = mb_substr(trim($value[$l]), 0, $max);
                }
            }

            return $out === [] ? '' : $out;
        }

        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    }

    /** @param string|array<string,string> $text */
    public static function isBlank(string|array $text): bool
    {
        return is_array($text) ? $text === [] : trim($text) === '';
    }
}
