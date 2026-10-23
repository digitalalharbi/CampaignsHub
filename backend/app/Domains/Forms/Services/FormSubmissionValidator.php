<?php

declare(strict_types=1);

namespace App\Domains\Forms\Services;

use App\Support\PhoneNumber;
use Illuminate\Validation\ValidationException;

/**
 * FORMS-HUB-001 — judges what a stranger sent against the version they were shown.
 *
 * The browser's idea of the form is a convenience; THIS is the form. Three consequences:
 *
 *  - A field the conditional logic hides is not asked for and not kept. An answer to a hidden field is
 *    discarded rather than rejected: the person may have changed an earlier answer after filling it, and
 *    refusing the whole submission for a field they can no longer see would lose the lead.
 *  - A key that is not a field of the version is refused. The payload cannot add columns to a lead.
 *  - Every value is checked against its type's own rule, whatever the browser claimed it had checked.
 *
 * @phpstan-type Cleaned array{answers: array<string,mixed>, labelled: array<string,mixed>, name: string, email: ?string, phone: ?string}
 */
final class FormSubmissionValidator
{
    /**
     * @param  array<string,mixed>  $definition  a normalised definition
     * @param  array<string,mixed>  $answers
     * @return Cleaned
     *
     * @throws ValidationException
     */
    public function validate(array $definition, array $answers): array
    {
        $errors = [];
        $fields = [];
        foreach ($definition['steps'] as $step) {
            foreach ($step['fields'] as $field) {
                $fields[$field['id']] = $field;
            }
        }

        foreach (array_keys($answers) as $key) {
            if (! is_string($key) || ! isset($fields[$key])) {
                $errors['answers.'.(is_string($key) ? $key : '?')] = 'This question is not part of the form.';
            }
        }

        $clean = [];
        foreach ($fields as $id => $field) {
            if (! $this->visible($field, $clean)) {
                continue;
            }
            $value = $this->check($field, $answers[$id] ?? null, $error);
            if ($error !== null) {
                $errors['answers.'.$id] = $error;

                continue;
            }
            if ($value !== null) {
                $clean[$id] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $mapped = ['name' => null, 'email' => null, 'phone' => null];
        foreach ($fields as $id => $field) {
            if (isset($field['maps_to']) && isset($clean[$id]) && is_string($clean[$id])) {
                $mapped[$field['maps_to']] = $clean[$id];
            }
        }

        $labelled = [];
        foreach ($clean as $id => $value) {
            if (isset($fields[$id]['maps_to'])) {
                continue;
            }
            $label = $this->label($fields[$id]);
            $labelled[isset($labelled[$label]) ? "{$label} ({$id})" : $label] = $value;
        }

        return ['answers' => $clean, 'labelled' => $labelled, 'name' => (string) $mapped['name'], 'email' => $mapped['email'], 'phone' => $mapped['phone']];
    }

    /**
     * @param  array<string,mixed>  $field
     * @param  array<string,mixed>  $clean  the answers of the fields before this one that were themselves visible
     */
    private function visible(array $field, array $clean): bool
    {
        $c = $field['show_if'] ?? null;
        if ($c === null) {
            return true;
        }
        $have = $clean[$c['field']] ?? null;

        return match ($c['op']) {
            'filled' => $have !== null && $have !== [] && $have !== '',
            'equals' => $this->same($have, $c['value']),
            'not_equals' => ! $this->same($have, $c['value']),
            'in' => is_array($c['value']) && (is_array($have) ? array_intersect($have, $c['value']) !== [] : in_array((string) $have, $c['value'], true)),
            default => true,
        };
    }

    private function same(mixed $have, mixed $want): bool
    {
        if (is_array($have)) {
            return in_array((string) $want, $have, true);
        }

        return $have !== null && (string) $have === (string) $want;
    }

    /**
     * @param  array<string,mixed>  $field
     */
    private function check(array $field, mixed $value, ?string &$error): mixed
    {
        $error = null;
        $blank = $value === null || $value === '' || $value === [];
        if ($blank) {
            if ($field['required']) {
                $error = 'This question is required.';
            }

            return null;
        }

        $v = $field['validation'] ?? [];

        switch ($field['type']) {
            case 'text':
            case 'textarea':
                if (! is_string($value)) {
                    $error = 'Enter text.';

                    return null;
                }
                $value = trim($value);
                $len = mb_strlen($value);
                $max = min((int) ($v['max_length'] ?? ($field['type'] === 'text' ? 500 : 3000)), $field['type'] === 'text' ? 500 : 3000);
                if ($len > $max) {
                    $error = "At most {$max} characters.";
                } elseif ($len < (int) ($v['min_length'] ?? 0)) {
                    $error = 'At least '.(int) $v['min_length'].' characters.';
                } elseif ($len === 0 && $field['required']) {
                    $error = 'This question is required.';
                }

                return $error === null && $len > 0 ? $value : null;

            case 'email':
                $value = is_string($value) ? mb_strtolower(trim($value)) : '';
                if ($value === '' || mb_strlen($value) > 190 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $error = 'Enter a valid email address.';

                    return null;
                }

                return $value;

            case 'phone':
                $value = is_string($value) ? trim($value) : '';
                if ($value === '' || mb_strlen($value) > 32 || PhoneNumber::normalise($value) === null) {
                    $error = 'Enter a valid phone number.';

                    return null;
                }

                return $value;

            case 'number':
                if (! is_numeric($value)) {
                    $error = 'Enter a number.';

                    return null;
                }
                $n = $value + 0;
                if (isset($v['min']) && $n < $v['min']) {
                    $error = "At least {$v['min']}.";
                } elseif (isset($v['max']) && $n > $v['max']) {
                    $error = "At most {$v['max']}.";
                }

                return $error === null ? $n : null;

            case 'select':
            case 'radio':
                $allowed = array_column($field['options'], 'value');
                if (! is_scalar($value) || ! in_array((string) $value, $allowed, true)) {
                    $error = 'Choose one of the options.';

                    return null;
                }

                return (string) $value;

            case 'checkbox':
                $allowed = array_column($field['options'], 'value');
                $values = is_array($value) ? array_values(array_unique(array_map('strval', array_filter($value, 'is_scalar')))) : [];
                if ($values === [] || array_diff($values, $allowed) !== []) {
                    $error = 'Choose from the options.';

                    return null;
                }

                return $values;

            case 'date':
                $d = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if ($d === false || $d->format('Y-m-d') !== $value) {
                    $error = 'Enter a date as YYYY-MM-DD.';

                    return null;
                }

                return $value;
        }

        $error = 'Unsupported question.';

        return null;
    }

    /** @param array<string,mixed> $field */
    private function label(array $field): string
    {
        $label = $field['label'];

        return is_array($label) ? (string) ($label['ar'] ?? $label['en'] ?? $field['id']) : ($label !== '' ? $label : $field['id']);
    }
}
