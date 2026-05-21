<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use RuntimeException;

class SeomaticFieldAdapter implements FieldAdapterInterface
{
    private const SUPPORTED_SOURCE_KEYS = [
        'seoTitleSource',
        'siteNamePositionSource',
        'seoDescriptionSource',
        'seoKeywordsSource',
    ];

    private const SUPPORTED_VALUE_KEYS = [
        'seoTitle',
        'siteNamePosition',
        'seoDescription',
        'seoKeywords',
    ];

    private const SAFE_META_GLOBAL_KEYS = [
        'seoTitle',
        'siteNamePosition',
        'seoDescription',
        'seoKeywords',
    ];

    public function getKey(): string
    {
        return 'seomatic';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return class_exists('nystudio107\\seomatic\\fields\\SeoSettings')
            && $field instanceof \nystudio107\seomatic\fields\SeoSettings;
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
            $errors[] = 'Prompt is required for SEOmatic fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        $allowed = $this->resolveAllowedPromptSections($field);
        $requested = $this->resolveRequestedPromptSections($promptConfig);

        $includeSeoTitle = $allowed['seoTitle'] && $requested['seoTitle'];
        $includeSiteNamePosition = $allowed['siteNamePosition'] && $requested['siteNamePosition'];
        $includeSeoDescription = $allowed['seoDescription'] && $requested['seoDescription'];
        $includeSeoKeywords = $allowed['seoKeywords'] && $requested['seoKeywords'];

        $properties = [];
        if ($includeSeoTitle) {
            $properties['seoTitle'] = ['type' => 'string'];
            $properties['seoTitleSource'] = ['type' => 'string'];
        }
        if ($includeSiteNamePosition) {
            $properties['siteNamePosition'] = ['type' => 'string', 'enum' => ['before', 'after', 'none']];
            $properties['siteNamePositionSource'] = ['type' => 'string'];
        }
        if ($includeSeoDescription) {
            $properties['seoDescription'] = ['type' => 'string'];
            $properties['seoDescriptionSource'] = ['type' => 'string'];
        }
        if ($includeSeoKeywords) {
            $properties['seoKeywords'] = ['type' => 'string'];
            $properties['seoKeywordsSource'] = ['type' => 'string'];
        }

        return [
            'type' => 'object',
            'rules' => [
                'Return a JSON object only for value.',
                'Include only keys relevant to SEO overrides.',
                'Do not return markdown or explanations.',
            ],
            'properties' => $properties,
            'sections' => [
                'seoTitle' => $includeSeoTitle,
                'siteNamePosition' => $includeSiteNamePosition,
                'seoDescription' => $includeSeoDescription,
                'seoKeywords' => $includeSeoKeywords,
            ],
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        $normalized = $this->normalizeSeoPatch($value);
        if ($normalized === []) {
            return '';
        }

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        $normalized = $this->normalizeSeoPatch($value);
        return $normalized !== [];
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        return $this->normalizeSeoPatch($value);
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $patch = $this->normalizeSeoPatch($value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for SEOmatic suggestion apply.');
        }

        $currentValue = $entry->getFieldValue($handle);
        if (is_object($currentValue)) {
            $updatedObject = $this->applyPatchToSeomaticObject($currentValue, $patch);
            $entry->setFieldValue($handle, $updatedObject);
            return $patch;
        }

        $base = $this->toArray($currentValue);
        $merged = $this->mergeSeoPatch($base, $patch);

        $entry->setFieldValue($handle, $merged);
        return $patch;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSeoPatch(mixed $raw): array
    {
        $input = $this->toArray($raw);
        if ($input === []) {
            return [];
        }

        $flat = $this->flattenCandidateInput($input);
        $normalized = [];

        foreach (self::SUPPORTED_VALUE_KEYS as $key) {
            if (!array_key_exists($key, $flat)) {
                continue;
            }

            $value = trim((string)$flat[$key]);
            if ($value === '') {
                continue;
            }

            if ($key === 'siteNamePosition') {
                $lower = strtolower($value);
                if (!in_array($lower, ['before', 'after', 'none'], true)) {
                    continue;
                }
                $normalized[$key] = $lower;
                continue;
            }

            $normalized[$key] = $value;
        }

        foreach (self::SUPPORTED_SOURCE_KEYS as $key) {
            if (!array_key_exists($key, $flat)) {
                continue;
            }

            $value = trim((string)$flat[$key]);
            if ($value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        // Source selection is automatic for supported fields: if a value is set, source is custom.
        if (($normalized['seoTitle'] ?? '') !== '') {
            $normalized['seoTitleSource'] = 'custom';
        }
        if (($normalized['siteNamePosition'] ?? '') !== '') {
            $normalized['siteNamePositionSource'] = 'custom';
        }
        if (($normalized['seoDescription'] ?? '') !== '') {
            $normalized['seoDescriptionSource'] = 'custom';
        }
        if (($normalized['seoKeywords'] ?? '') !== '') {
            $normalized['seoKeywordsSource'] = 'custom';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function mergeSeoPatch(array $base, array $patch): array
    {
        if (!isset($base['metaGlobalVars']) || !is_array($base['metaGlobalVars'])) {
            $base['metaGlobalVars'] = [];
        }

        $assignableMetaGlobalKeys = $this->resolveAssignableMetaGlobalKeys($base);

        foreach ($patch as $key => $value) {
            if ($value === '') {
                continue;
            }

            // Prevent SEOmatic model assignment errors by only setting keys that are assignable.
            if (in_array($key, $assignableMetaGlobalKeys, true)) {
                $base['metaGlobalVars'][$key] = $value;
                continue;
            }

            // Best-effort fallback for source fields: if the key already exists at root, update there.
            if (array_key_exists($key, $base)) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function applyPatchToSeomaticObject(object $value, array $patch): object
    {
        $metaGlobalVars = $this->readProperty($value, 'metaGlobalVars');

        foreach ($patch as $key => $fieldValue) {
            if (!is_string($fieldValue) || $fieldValue === '') {
                continue;
            }

            if (in_array($key, self::SAFE_META_GLOBAL_KEYS, true)) {
                if (is_object($metaGlobalVars)) {
                    $this->assignObjectPropertyIfSupported($metaGlobalVars, $key, $fieldValue);
                    continue;
                }

                if (is_array($metaGlobalVars)) {
                    $metaGlobalVars[$key] = $fieldValue;
                    continue;
                }
            }

            // Source fields are not part of MetaGlobalVars; only set them if root object supports it.
            $this->assignObjectPropertyIfSupported($value, $key, $fieldValue);
        }

        if (is_array($metaGlobalVars)) {
            $this->assignObjectPropertyIfSupported($value, 'metaGlobalVars', $metaGlobalVars);
        }

        return $value;
    }

    private function readProperty(object $value, string $property): mixed
    {
        if (property_exists($value, $property)) {
            return $value->{$property};
        }

        try {
            return $value->{$property};
        } catch (\Throwable) {
            return null;
        }
    }

    private function assignObjectPropertyIfSupported(object $target, string $key, mixed $value): void
    {
        if (method_exists($target, 'canSetProperty') && $target->canSetProperty($key)) {
            $target->{$key} = $value;
            return;
        }

        if (!property_exists($target, $key)) {
            return;
        }

        try {
            $target->{$key} = $value;
        } catch (\Throwable) {
            // Ignore unsupported assignment attempts.
        }
    }

    /**
     * @return array{seoTitle:bool,siteNamePosition:bool,seoDescription:bool,seoKeywords:bool}
     */
    private function resolveAllowedPromptSections(FieldInterface $field): array
    {
        return [
            'seoTitle' => true,
            'siteNamePosition' => true,
            'seoDescription' => true,
            'seoKeywords' => true,
        ];
    }

    /**
     * @return array{seoTitle:bool,siteNamePosition:bool,seoDescription:bool,seoKeywords:bool}
     */
    private function resolveRequestedPromptSections(array $promptConfig): array
    {
        $seomatic = is_array($promptConfig['seomatic'] ?? null) ? $promptConfig['seomatic'] : [];

        return [
            'seoTitle' => $this->asBool($seomatic['includeSeoTitle'] ?? true),
            'siteNamePosition' => $this->asBool($seomatic['includeSiteNamePosition'] ?? true),
            'seoDescription' => $this->asBool($seomatic['includeSeoDescription'] ?? true),
            'seoKeywords' => $this->asBool($seomatic['includeSeoKeywords'] ?? true),
        ];
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $base
     * @return string[]
     */
    private function resolveAssignableMetaGlobalKeys(array $base): array
    {
        $keys = self::SAFE_META_GLOBAL_KEYS;

        $metaGlobalVars = $base['metaGlobalVars'] ?? null;
        if (!is_array($metaGlobalVars)) {
            return $keys;
        }

        foreach (array_keys($metaGlobalVars) as $existingKey) {
            if (!is_string($existingKey) || $existingKey === '') {
                continue;
            }

            if (!in_array($existingKey, $keys, true)) {
                $keys[] = $existingKey;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function flattenCandidateInput(array $input): array
    {
        $flat = $input;

        if (isset($input['metaGlobalVars']) && is_array($input['metaGlobalVars'])) {
            $flat = array_merge($flat, $input['metaGlobalVars']);
        }

        if (isset($input['value']) && is_array($input['value'])) {
            $flat = array_merge($flat, $input['value']);
        }

        return $flat;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : [];
        }

        if ($value instanceof \JsonSerializable) {
            try {
                $serialized = $value->jsonSerialize();
            } catch (\Throwable) {
                return [];
            }

            return is_array($serialized) ? $serialized : [];
        }

        if (is_object($value)) {
            $props = get_object_vars($value);
            if (is_array($props) && $props !== []) {
                return $props;
            }

            foreach (['toArray', 'getAttributes'] as $method) {
                if (!method_exists($value, $method)) {
                    continue;
                }

                try {
                    $candidate = $value->{$method}();
                } catch (\Throwable) {
                    continue;
                }

                if (is_array($candidate)) {
                    return $candidate;
                }
            }
        }

        return [];
    }
}
