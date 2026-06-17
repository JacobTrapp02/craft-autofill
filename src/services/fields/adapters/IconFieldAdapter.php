<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Icon;
use craft\fields\data\IconData;
use RuntimeException;

class IconFieldAdapter implements FieldAdapterInterface
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $icons = null;

    public function getKey(): string
    {
        return 'icon';
    }

    public function isAvailableInLiteVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Icon;
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
            $errors[] = 'Prompt is required for Icon fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        $rules = [
            'Return exactly one icon value.',
            'Return the icon name string only.',
            'Do not return SVG markup, HTML, CSS classes, or a Font Awesome prefix.',
            'Use the Craft/Font Awesome icon name format, such as house, user, arrow-right, or circle-check.',
        ];

        if ($field instanceof Icon && !$field->includeProIcons) {
            $rules[] = 'Choose an icon available in the free icon set.';
        }

        return [
            'type' => 'string',
            'format' => 'icon-name',
            'rules' => $rules,
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if ($value instanceof IconData) {
            return trim($value->name);
        }

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
        $normalized = $this->normalizeSuggestion($field, $value);

        if (!is_string($normalized) || $normalized === '') {
            return false;
        }

        if (!$field instanceof Icon) {
            return false;
        }

        $meta = $this->iconMeta($normalized);
        if ($meta === null) {
            return false;
        }

        if ($field->includeProIcons) {
            return true;
        }

        return $this->isFreeIcon($meta);
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if ($value instanceof IconData) {
            return trim($value->name);
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $normalized = strtolower($raw);
        $normalized = preg_replace('/^(fa[srlbdkt]?-?|fa-)/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', '-', trim($normalized)) ?? $normalized;

        return trim($normalized);
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for icon suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function iconMeta(string $name): ?array
    {
        $icons = $this->icons();
        $meta = $icons[$name] ?? null;

        return is_array($meta) ? $meta : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function icons(): array
    {
        if (is_array($this->icons)) {
            return $this->icons;
        }

        try {
            $indexPath = Craft::getAlias('@app/icons/index.php');
            $icons = require $indexPath;
            $this->icons = is_array($icons) ? $icons : [];
        } catch (\Throwable) {
            $this->icons = [];
        }

        return $this->icons;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function isFreeIcon(array $meta): bool
    {
        return !array_key_exists('pro', $meta) || !$meta['pro'];
    }
}
