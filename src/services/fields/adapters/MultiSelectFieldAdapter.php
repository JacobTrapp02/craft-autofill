<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\MultiSelect;
use RuntimeException;

class MultiSelectFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'multiSelect';
    }

    public function isAvailableInLiteVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof MultiSelect;
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
            $errors[] = 'Prompt is required for Multi-select fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        if (!$field instanceof MultiSelect) {
            return ['type' => 'array'];
        }

        return [
            'type' => 'array',
            'selectionMode' => 'multiple',
            'items' => ['type' => 'string'],
            'rules' => [
                'Return a JSON array of multi-select option values.',
                'Return one or more option values when appropriate.',
                'Do not return the option labels unless they exactly match the values.',
            ],
            'options' => OptionFieldValueHelper::normalizedOptionsFromBaseField($field),
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        return OptionFieldValueHelper::stringifyContextValue($value);
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!$field instanceof MultiSelect) {
            return false;
        }

        $normalized = $this->normalizeSuggestion($field, $value);
        return is_array($normalized)
            && $normalized !== []
            && OptionFieldValueHelper::matchesMultiValue(
                $normalized,
                OptionFieldValueHelper::normalizedOptionsFromBaseField($field),
            );
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (!$field instanceof MultiSelect) {
            return [];
        }

        return OptionFieldValueHelper::normalizeMultiValue(
            $value,
            OptionFieldValueHelper::normalizedOptionsFromBaseField($field),
        );
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for multi-select suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }
}
