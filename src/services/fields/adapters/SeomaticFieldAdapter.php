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

    public function isAvailableInLiteVersion(): bool
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

        $base = $this->toArray($entry->getFieldValue($handle));
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

        $base['metaGlobalVars'] = array_intersect_key(
            $base['metaGlobalVars'],
            array_flip(self::SAFE_META_GLOBAL_KEYS)
        );

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
        return self::SAFE_META_GLOBAL_KEYS;
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
            return $this->normalizeArrayValue($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $this->normalizeArrayValue($decoded) : [];
        }

        if ($value instanceof \JsonSerializable) {
            try {
                $serialized = $value->jsonSerialize();
            } catch (\Throwable) {
                return [];
            }

            return is_array($serialized) ? $this->normalizeArrayValue($serialized) : [];
        }

        if (is_object($value)) {
            $props = get_object_vars($value);
            if (is_array($props) && $props !== []) {
                return $this->normalizeArrayValue($props);
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
                    return $this->normalizeArrayValue($candidate);
                }
            }
        }

        return [];
    }

    /**
     * @param array<string|int, mixed> $value
     * @return array<string, mixed>
     */
    private function normalizeArrayValue(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string)$key] = match (true) {
                is_array($item) => $this->normalizeArrayValue($item),
                is_object($item) => $this->toArray($item),
                default => $item,
            };
        }

        return $normalized;
    }
}
