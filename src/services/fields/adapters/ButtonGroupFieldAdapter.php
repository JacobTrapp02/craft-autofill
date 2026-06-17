<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\ButtonGroup;
use RuntimeException;

class ButtonGroupFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'buttonGroup';
    }

    public function isAvailableInLiteVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof ButtonGroup;
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
            $errors[] = 'Prompt is required for Button Group fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        return [
            'type' => 'string',
            'rules' => [
                'Return exactly one button option value.',
                'Do not return the option label unless it matches the value.',
            ],
            'options' => $this->normalizedOptions($field),
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return trim((string)$value);
        }

        return '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        if (!is_string($normalized) || $normalized === '') {
            return false;
        }

        foreach ($this->normalizedOptions($field) as $option) {
            if ($normalized === (string)($option['value'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        foreach ($this->normalizedOptions($field) as $option) {
            $optionValue = trim((string)($option['value'] ?? ''));
            $optionLabel = trim((string)($option['label'] ?? ''));

            if ($raw === $optionValue || strcasecmp($raw, $optionLabel) === 0) {
                return $optionValue;
            }
        }

        return $raw;
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for button group suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    private function normalizedOptions(FieldInterface $field): array
    {
        if (!$field instanceof ButtonGroup || !is_array($field->options ?? null)) {
            return [];
        }

        $options = [];
        foreach ($field->options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $value = trim((string)($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $label = trim((string)($option['label'] ?? $value));
            $options[] = [
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        }

        return $options;
    }
}
