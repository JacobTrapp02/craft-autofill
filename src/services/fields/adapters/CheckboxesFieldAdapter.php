<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Checkboxes;
use RuntimeException;

class CheckboxesFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'checkboxes';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Checkboxes;
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
            $errors[] = 'Prompt is required for Checkboxes fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        if (!$field instanceof Checkboxes) {
            return ['type' => 'array'];
        }

        return [
            'type' => 'array',
            'selectionMode' => 'multiple',
            'items' => ['type' => 'string'],
            'rules' => [
                'Return a JSON array of checkbox option values.',
                'Return one or more checkbox option values when appropriate.',
                'Do not return the option labels unless they exactly match the values.',
            ],
            'options' => OptionFieldValueHelper::normalizedOptionsFromBaseField($field),
            'allowCustomValue' => (bool)$field->customOptions,
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        return OptionFieldValueHelper::stringifyContextValue($value);
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!$field instanceof Checkboxes) {
            return false;
        }

        $normalized = $this->normalizeSuggestion($field, $value);
        if (!is_array($normalized) || $normalized === []) {
            return false;
        }

        if ($field->customOptions) {
            return true;
        }

        return OptionFieldValueHelper::matchesMultiValue(
            $normalized,
            OptionFieldValueHelper::normalizedOptionsFromBaseField($field),
        );
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (!$field instanceof Checkboxes) {
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
            throw new RuntimeException('Could not resolve field handle for checkboxes suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }
}
