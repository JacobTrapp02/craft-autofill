<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Json;
use craft\fields\data\JsonData;
use craft\helpers\Json as JsonHelper;
use RuntimeException;

class JsonFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'json';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Json;
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
            $errors[] = 'Prompt is required for JSON fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        return [
            'type' => 'object',
            'format' => 'json',
            'rules' => [
                'Return a valid JSON object or JSON array only for the value.',
                'Do not wrap the JSON in markdown fences or explanatory text.',
            ],
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        return $this->normalizeJsonString($value);
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        return $this->normalizeJsonString($value) !== '';
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        return $this->normalizeJsonString($value);
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeJsonString($value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for JSON suggestion apply.');
        }

        if ($normalized === '') {
            throw new RuntimeException('JSON suggestion must be a valid JSON object or array.');
        }

        $decoded = JsonHelper::decode($normalized);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON suggestion must decode to an object or array.');
        }

        $entry->setFieldValue($handle, $decoded);
        return $normalized;
    }

    private function normalizeJsonString(mixed $value): string
    {
        if ($value instanceof JsonData) {
            return $this->encodeJsonValue($value->jsonSerialize());
        }

        if (is_array($value)) {
            return $this->encodeJsonValue($value);
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                return $this->normalizeJsonString($value->jsonSerialize());
            }

            return $this->encodeJsonValue((array)$value);
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $decoded = JsonHelper::decodeIfJson($raw);
        if (!is_array($decoded)) {
            return '';
        }

        return $this->encodeJsonValue($decoded);
    }

    /**
     * @param array<mixed> $value
     */
    private function encodeJsonValue(array $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '';
    }
}
