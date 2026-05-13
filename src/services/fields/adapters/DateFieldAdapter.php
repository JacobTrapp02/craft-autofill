<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Date;
use RuntimeException;

class DateFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'date';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Date;
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
            $errors[] = 'Prompt is required for Date fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        $includesTime = $this->includesTime($field);

        return [
            'type' => 'string',
            'format' => $includesTime ? 'date-time' : 'date',
            'rules' => [
                $includesTime
                    ? 'Return a valid ISO 8601 date-time string, including time and timezone offset (e.g. 2026-05-10T14:30:00-05:00).'
                    : 'Return a valid ISO 8601 date string in YYYY-MM-DD format.',
                'Do not return explanatory text.',
            ],
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        $includesTime = $this->includesTime($field);

        if ($value instanceof \DateTimeInterface) {
            return $includesTime
                ? $value->format(\DateTimeInterface::ATOM)
                : $value->format('Y-m-d');
        }

        if (is_string($value)) {
            return trim($value);
        }

        return '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return strtotime(trim($value)) !== false;
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        $includesTime = $this->includesTime($field);

        if ($value instanceof \DateTimeInterface) {
            return $includesTime
                ? $value->format(\DateTimeInterface::ATOM)
                : $value->format('Y-m-d');
        }

        if (!is_string($value)) {
            return '';
        }

        $trimmed = trim($value);
        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return '';
        }

        return $includesTime
            ? date(\DateTimeInterface::ATOM, $timestamp)
            : date('Y-m-d', $timestamp);
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for date suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    private function includesTime(FieldInterface $field): bool
    {
        if (!$field instanceof Date) {
            return false;
        }

        // Craft Date fields expose a `showTime` setting when time should be collected.
        return (bool)($field->showTime ?? false);
    }
}
