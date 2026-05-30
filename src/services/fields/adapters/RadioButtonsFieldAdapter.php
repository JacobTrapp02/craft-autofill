<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\RadioButtons;
use RuntimeException;

class RadioButtonsFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'radioButtons';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof RadioButtons;
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
            $errors[] = 'Prompt is required for Radio Buttons fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        if (!$field instanceof RadioButtons) {
            return ['type' => 'string'];
        }

        return [
            'type' => 'string',
            'selectionMode' => 'single',
            'rules' => [
                'Return exactly one radio button option value.',
                'Do not return the option label unless it exactly matches the value.',
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
        if (!$field instanceof RadioButtons) {
            return false;
        }

        $normalized = $this->normalizeSuggestion($field, $value);
        if (!is_string($normalized) || $normalized === '') {
            return false;
        }

        if ($field->customOptions) {
            return true;
        }

        return OptionFieldValueHelper::matchesSingleValue(
            $normalized,
            OptionFieldValueHelper::normalizedOptionsFromBaseField($field),
        );
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (!$field instanceof RadioButtons) {
            return '';
        }

        return OptionFieldValueHelper::normalizeSingleValue(
            $value,
            OptionFieldValueHelper::normalizedOptionsFromBaseField($field),
        );
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for radio buttons suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }
}
