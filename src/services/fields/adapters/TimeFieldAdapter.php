<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Time;
use craft\helpers\DateTimeHelper;
use DateTimeInterface;
use RuntimeException;

class TimeFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'time';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Time;
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
            $errors[] = 'Prompt is required for Time fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        $contract = [
            'type' => 'string',
            'format' => 'time',
            'rules' => [
                'Return exactly one time value.',
                'Return the time in 24-hour format as HH:MM or HH:MM:SS.',
                'Do not return a date, timezone, or explanatory text.',
            ],
        ];

        if ($field instanceof Time) {
            $contract['minuteIncrement'] = max(1, (int)$field->minuteIncrement);

            if (is_string($field->min) && $field->min !== '') {
                $contract['min'] = $field->min;
                $contract['rules'][] = sprintf('Do not return a time earlier than %s.', $field->min);
            }

            if (is_string($field->max) && $field->max !== '') {
                $contract['max'] = $field->max;
                $contract['rules'][] = sprintf('Do not return a time later than %s.', $field->max);
            }
        }

        return $contract;
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        return $this->normalizeTimeString($value);
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!$field instanceof Time) {
            return false;
        }

        $normalized = $this->normalizeTimeString($value);
        if ($normalized === '') {
            return false;
        }

        $seconds = DateTimeHelper::timeToSeconds($normalized);
        if ($seconds === false) {
            return false;
        }

        $minSeconds = is_string($field->min) && $field->min !== '' ? DateTimeHelper::timeToSeconds($field->min) : null;
        $maxSeconds = is_string($field->max) && $field->max !== '' ? DateTimeHelper::timeToSeconds($field->max) : null;

        if (is_int($minSeconds) && $seconds < $minSeconds) {
            return false;
        }

        if (is_int($maxSeconds) && $seconds > $maxSeconds) {
            return false;
        }

        return true;
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        return $this->normalizeTimeString($value);
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeTimeString($value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for time suggestion apply.');
        }

        if ($normalized === '') {
            throw new RuntimeException('Time suggestion must be a valid time.');
        }

        if (!$this->validateSuggestion($field, $normalized)) {
            throw new RuntimeException('Time suggestion must match the field time constraints.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    private function normalizeTimeString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if (is_array($value)) {
            $value = $value['time'] ?? $value['value'] ?? '';
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $date = DateTimeHelper::toDateTime(['time' => $raw], true);
        return $date ? $date->format('H:i:s') : '';
    }
}
