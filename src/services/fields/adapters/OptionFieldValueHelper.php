<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\fields\BaseOptionsField;
use craft\fields\data\ColorData;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\data\SingleOptionFieldData;
use craft\helpers\Json;

class OptionFieldValueHelper
{
    /**
     * @return array<int, array{value:string, label:string}>
     */
    public static function normalizedOptionsFromBaseField(BaseOptionsField $field): array
    {
        if (!is_array($field->options ?? null)) {
            return [];
        }

        $options = [];
        foreach ($field->options as $option) {
            if (!is_array($option) || array_key_exists('optgroup', $option)) {
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

    public static function normalizeSingleValue(mixed $value, array $options): string
    {
        $values = self::normalizeMultiValue($value, $options);
        return $values[0] ?? '';
    }

    /**
     * @param array<int, array{value:string, label:string}> $options
     * @return string[]
     */
    public static function normalizeMultiValue(mixed $value, array $options): array
    {
        $normalized = [];

        foreach (self::toCandidateList($value) as $candidate) {
            $resolved = self::resolveOptionValue($candidate, $options);
            if ($resolved !== '' && !in_array($resolved, $normalized, true)) {
                $normalized[] = $resolved;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, array{value:string, label:string}> $options
     */
    public static function matchesSingleValue(string $value, array $options): bool
    {
        if ($value === '') {
            return false;
        }

        foreach ($options as $option) {
            if ($value === (string)($option['value'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{value:string, label:string}> $options
     * @param string[] $values
     */
    public static function matchesMultiValue(array $values, array $options): bool
    {
        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (!self::matchesSingleValue($value, $options)) {
                return false;
            }
        }

        return true;
    }

    public static function stringifyContextValue(mixed $value): string
    {
        if ($value instanceof SingleOptionFieldData) {
            return trim((string)($value->value ?? ''));
        }

        if ($value instanceof MultiOptionsFieldData) {
            $parts = [];
            foreach ($value as $item) {
                if (!$item instanceof OptionData) {
                    continue;
                }

                $parts[] = trim((string)($item->value ?? ''));
            }

            return implode(', ', array_values(array_filter($parts, static fn(string $part) => $part !== '')));
        }

        if ($value instanceof ColorData) {
            return trim((string)$value->getHex());
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $part = self::stringifyContextValue($item);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }

            return implode(', ', $parts);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return trim((string)$value);
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }

    /**
     * @param array<int, array{value:string, label:string}> $options
     */
    private static function resolveOptionValue(string $candidate, array $options): string
    {
        $raw = trim($candidate);
        if ($raw === '') {
            return '';
        }

        foreach ($options as $option) {
            $optionValue = trim((string)($option['value'] ?? ''));
            $optionLabel = trim((string)($option['label'] ?? ''));

            if ($raw === $optionValue || strcasecmp($raw, $optionLabel) === 0) {
                return $optionValue;
            }
        }

        return $raw;
    }

    /**
     * @return string[]
     */
    private static function toCandidateList(mixed $value): array
    {
        if ($value instanceof MultiOptionsFieldData) {
            $items = [];
            foreach ($value as $option) {
                if ($option instanceof OptionData) {
                    $items[] = trim((string)($option->value ?? ''));
                }
            }

            return array_values(array_filter($items, static fn(string $item) => $item !== ''));
        }

        if ($value instanceof SingleOptionFieldData) {
            $single = trim((string)($value->value ?? ''));
            return $single !== '' ? [$single] : [];
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                $items = [...$items, ...self::toCandidateList($item)];
            }

            return $items;
        }

        if (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            return [];
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return [];
        }

        if (str_starts_with($raw, '[')) {
            try {
                $decoded = Json::decodeIfJson($raw);
                if (is_array($decoded)) {
                    return self::toCandidateList($decoded);
                }
            } catch (\Throwable) {
                // Fall back to delimiter-based parsing below.
            }
        }

        $parts = preg_split('/\s*(?:,|\n|\r\n|;)\s*/', $raw) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $part) => $part !== ''));

        return $parts !== [] ? $parts : [$raw];
    }
}
