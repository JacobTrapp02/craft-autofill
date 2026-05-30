<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Range;
use RuntimeException;

class RangeFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'range';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Range;
    }

    public function getPromptConfigSchema(FieldInterface $field): array
    {
        return [
            'fields' => [
                [
                    'key' => 'prompt',
                    'type' => 'multiline',
                    'label' => 'Prompt',
                    'required' => true,
                ],
            ],
        ];
    }

    public function normalizePromptConfig(array $config, FieldInterface $field): array
    {
        return [
            'prompt' => trim((string)($config['prompt'] ?? '')),
        ];
    }

    public function validatePromptConfig(array $config, FieldInterface $field): array
    {
        $normalized = $this->normalizePromptConfig($config, $field);
        $errors = [];

        if ($normalized['prompt'] === '') {
            $errors[] = 'Prompt is required for Range fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        if (!$field instanceof Range) {
            return ['type' => 'number'];
        }

        $rules = [
            'Return a numeric value only.',
            'Do not return units, suffixes, or explanatory text.',
            sprintf('Return a value between %s and %s.', $this->stringifyNumber($field->min), $this->stringifyNumber($field->max)),
        ];

        if ((float)$field->step > 0.0) {
            $rules[] = sprintf('Match the configured step increment of %s when possible.', $this->stringifyNumber($field->step));
        }

        if (is_string($field->suffix) && trim($field->suffix) !== '') {
            $rules[] = sprintf('The field suffix is "%s", but do not include it in the returned value.', trim($field->suffix));
        }

        return [
            'type' => 'number',
            'min' => $field->min,
            'max' => $field->max,
            'step' => $field->step,
            'suffix' => trim((string)($field->suffix ?? '')),
            'rules' => $rules,
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if (is_numeric($value)) {
            return $this->stringifyNumber($value);
        }

        return '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!$field instanceof Range) {
            return false;
        }

        $normalized = $this->normalizeSuggestion($field, $value);
        if (!is_numeric($normalized)) {
            return false;
        }

        $number = (float)$normalized;
        if ($number < (float)$field->min || $number > (float)$field->max) {
            return false;
        }

        $step = (float)$field->step;
        if ($step > 0) {
            $offset = ($number - (float)$field->min) / $step;
            if (abs($offset - round($offset)) > 0.000001) {
                return false;
            }
        }

        return true;
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (is_numeric($value)) {
            return $this->normalizeNumericValue((float)$value);
        }

        $raw = trim((string)$value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return $this->normalizeNumericValue((float)$raw);
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for range suggestion apply.');
        }

        if (!is_numeric($normalized)) {
            throw new RuntimeException('Range suggestion must be numeric.');
        }

        if (!$this->validateSuggestion($field, $normalized)) {
            throw new RuntimeException('Range suggestion must match the field min, max, and step constraints.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    private function normalizeNumericValue(float $value): int|float
    {
        if ((float)(int)$value === $value) {
            return (int)$value;
        }

        return $value;
    }

    private function stringifyNumber(int|float|string $value): string
    {
        $string = (string)$value;
        if (!str_contains($string, '.')) {
            return $string;
        }

        return rtrim(rtrim($string, '0'), '.') ?: '0';
    }
}
