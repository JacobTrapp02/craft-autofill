<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\fields\Lightswitch;

class LightswitchFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'lightswitch';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Lightswitch;
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
            $errors[] = 'Prompt is required for Lightswitch fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        $normalized = $this->normalizePromptConfig($promptConfig, $field);

        return [
            'type' => 'boolean',
            'rules' => [
                'Return true or false only.',
                'Do not return yes/no strings.',
            ],
            'prompt' => $normalized['prompt'],
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return ((int)$value === 1) ? 'true' : 'false';
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
        }

        return 'false';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        return is_bool($value);
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
