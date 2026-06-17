<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use RuntimeException;

class CkeditorFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'ckeditor';
    }

    public function isAvailableInLiteVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return class_exists('craft\\ckeditor\\Field') && $field instanceof \craft\ckeditor\Field;
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
            $errors[] = 'Prompt is required for CKEditor fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        return [
            'type' => 'string',
            'rules' => [
                'Return a string suitable for rich text content.',
                'If markup is used, keep it minimal and valid HTML fragments only.',
            ],
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        return $this->extractContextText($value);
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

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for CKEditor suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    private function extractContextText(mixed $value, int $depth = 0): string
    {
        if ($depth > 3 || $value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $part) {
                $text = $this->extractContextText($part, $depth + 1);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return trim(implode("\n\n", $parts));
        }

        if ($value instanceof \JsonSerializable) {
            try {
                return $this->extractContextText($value->jsonSerialize(), $depth + 1);
            } catch (\Throwable) {
                // Fall through to other extraction strategies.
            }
        }

        if (is_object($value)) {
            foreach (['getParsedContent', 'getRawContent', 'getText', 'getValue', 'toHtml', '__toString'] as $method) {
                if (!method_exists($value, $method)) {
                    continue;
                }

                try {
                    $candidate = $value->{$method}();
                } catch (\Throwable) {
                    continue;
                }

                $text = $this->extractContextText($candidate, $depth + 1);
                if ($text !== '') {
                    return $text;
                }
            }

            foreach (['content', 'value', 'html', 'body', 'text'] as $property) {
                if (!isset($value->{$property})) {
                    continue;
                }

                $text = $this->extractContextText($value->{$property}, $depth + 1);
                if ($text !== '') {
                    return $text;
                }
            }

            if ($value instanceof \Stringable) {
                return trim((string)$value);
            }
        }

        return '';
    }
}
