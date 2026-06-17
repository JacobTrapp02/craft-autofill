<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Color;
use craft\validators\ColorValidator;
use RuntimeException;

class ColorFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'color';
    }

    public function isAvailableInLiteVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Color;
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
            $errors[] = 'Prompt is required for Color fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        if (!$field instanceof Color) {
            return ['type' => 'string'];
        }

        $contract = [
            'type' => 'string',
            'selectionMode' => 'single',
            'format' => 'color',
            'rules' => [
                'Return exactly one color value.',
                'Prefer returning the configured hex color value.',
            ],
        ];

        $options = $this->normalizedOptions($field);
        if ($options !== []) {
            $contract['options'] = $options;
        }

        if ($field->allowCustomColors) {
            $contract['allowCustomValue'] = true;
            $contract['rules'][] = 'If no palette option fits, you may return a valid hex color.';
        } elseif ($options !== []) {
            $contract['rules'][] = 'Return one of the configured palette values.';
        }

        return $contract;
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        return OptionFieldValueHelper::stringifyContextValue($value);
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!$field instanceof Color) {
            return false;
        }

        $normalized = $this->normalizeSuggestion($field, $value);
        if (!is_string($normalized) || $normalized === '') {
            return false;
        }

        if ($field->allowCustomColors) {
            return $this->isValidColor($normalized);
        }

        return OptionFieldValueHelper::matchesSingleValue($normalized, $this->normalizedOptions($field));
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (!$field instanceof Color) {
            return '';
        }

        $raw = OptionFieldValueHelper::normalizeSingleValue($value, $this->normalizedOptions($field));
        if ($raw === '') {
            return '';
        }

        if ($this->isValidColor($raw)) {
            return ColorValidator::normalizeColor($raw);
        }

        return $raw;
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for color suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    private function normalizedOptions(Color $field): array
    {
        $options = [];
        foreach ($field->palette as $color) {
            if (!is_array($color)) {
                continue;
            }

            $value = trim((string)($color['color'] ?? ''));
            if ($value === '') {
                continue;
            }

            $label = trim((string)($color['label'] ?? ''));
            $options[] = [
                'value' => ColorValidator::normalizeColor($value),
                'label' => $label !== '' ? $label : ColorValidator::normalizeColor($value),
            ];
        }

        return $options;
    }

    private function isValidColor(string $value): bool
    {
        $validator = new ColorValidator();
        return $validator->validate($value);
    }
}
