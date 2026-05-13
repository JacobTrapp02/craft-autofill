<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\fields\PlainText;

class PlainTextFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'plainText';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof PlainText;
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
            $errors[] = 'Prompt is required for Plain Text fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        return [
            'type' => 'string',
            'rules' => [
                'Return a plain text string only.',
                'Do not return markdown, HTML, or explanations.',
            ],
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

        return '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        return is_string($value);
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }

    public function getFillRuntimeSpec(FieldInterface $field): array
    {
        return [
            'inputKind' => 'text',
            'applyVia' => 'native',
            'acceptanceCheck' => 'valueRoundTrip',
        ];
    }

    public function getReviewUiSpec(FieldInterface $field): array
    {
        return [
            'inputControl' => 'textarea',
        ];
    }
}
