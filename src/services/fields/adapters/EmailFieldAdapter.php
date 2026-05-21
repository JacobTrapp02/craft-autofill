<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Email;
use RuntimeException;

class EmailFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'email';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Email;
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
            $errors[] = 'Prompt is required for Email fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        return [
            'type' => 'string',
            'format' => 'email',
            'rules' => [
                'Return a single valid email address only.',
                'Do not return markdown, HTML links, or mailto: syntax.',
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
        $candidate = $this->extractEmailCandidate($value);
        return $candidate !== null;
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        return $this->extractEmailCandidate($value) ?? '';
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for email suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    private function extractEmailCandidate(mixed $value): ?string
    {
        if (!is_string($value) && !is_scalar($value)) {
            return null;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        // Common AI formatting miss: [email@example.com](mailto:email@example.com)
        if (preg_match('/mailto:([^)\s>]+)/i', $raw, $match) === 1) {
            $mailto = trim((string)($match[1] ?? ''));
            if ($mailto !== '' && filter_var($mailto, FILTER_VALIDATE_EMAIL) !== false) {
                return $mailto;
            }
        }

        // Fallback: extract the first email-looking token from surrounding text.
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $match) === 1) {
            $email = trim((string)($match[0] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }

        return null;
    }
}
