<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Number;
use RuntimeException;

class NumberFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'number';
    }

    public function isAvailableInLiteVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Number;
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
            $errors[] = 'Prompt is required for Number fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        return [
            'type' => 'number',
            'rules' => [
                'Return a numeric value only.',
                'Do not return units, text, or formatting characters.',
            ],
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if (is_numeric($value)) {
            return (string)$value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        return is_numeric($value);
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (is_numeric($trimmed)) {
                return (float)$trimmed;
            }
        }

        return null;
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for number suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }
}
