<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Addresses;
use RuntimeException;

class AddressesFieldAdapter implements FieldAdapterInterface
{
    private const ADDRESS_KEYS = [
        'title',
        'countryCode',
        'administrativeArea',
        'locality',
        'postalCode',
        'addressLine1',
        'addressLine2',
        'addressLine3',
    ];

    /**
     * @var array<string, string>|null
     */
    private ?array $countryCodeByLowerName = null;

    public function getKey(): string
    {
        return 'addresses';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return false;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Addresses;
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
            $errors[] = 'Prompt is required for Addresses fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        $maxItems = $field instanceof Addresses ? (int)($field->maxAddresses ?? 0) : 0;

        return [
            'type' => 'object',
            'rules' => array_values(array_filter([
                'Return JSON only.',
                'Return an object with an addresses array.',
                'Use only: label, country, addressLine1, addressLine2, addressLine3, state, city, zipCode.',
                'country should be an ISO 2-letter code when possible (e.g. US, CA).',
                $maxItems > 0 ? sprintf('Return at most %d address item(s).', $maxItems) : null,
            ])),
            'properties' => [
                'addresses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'country' => ['type' => 'string'],
                            'addressLine1' => ['type' => 'string'],
                            'addressLine2' => ['type' => 'string'],
                            'addressLine3' => ['type' => 'string'],
                            'state' => ['type' => 'string'],
                            'city' => ['type' => 'string'],
                            'zipCode' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        $rows = $this->normalizeSuggestion($field, $value);
        if (!is_array($rows)) {
            return '';
        }

        $friendly = array_map(fn(array $row) => $this->toFriendlyRow($row), $rows);
        return json_encode(['addresses' => array_values($friendly)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        $decoded = $this->toArray($value);
        if (array_key_exists('addresses', $decoded) && is_array($decoded['addresses'])) {
            return true;
        }

        $normalized = $this->normalizeSuggestion($field, $value);
        return is_array($normalized) && $normalized !== [];
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        $rows = $this->extractAddressRows($value);
        if ($rows === []) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalizedRow = [];
            $row = $this->mapFriendlyKeys($row);
            if (isset($row['_id']) && trim((string)$row['_id']) !== '') {
                $normalizedRow['_id'] = trim((string)$row['_id']);
            }
            foreach (self::ADDRESS_KEYS as $key) {
                if (!array_key_exists($key, $row)) {
                    continue;
                }

                $raw = trim((string)$row[$key]);
                if ($raw === '') {
                    continue;
                }

                if ($key === 'countryCode') {
                    $raw = $this->normalizeCountryCode($raw);
                    if ($raw === '') {
                        continue;
                    }
                }

                $normalizedRow[$key] = $raw;
            }

            if ($normalizedRow !== []) {
                $normalized[] = $normalizedRow;
            }
        }

        if ($field instanceof Addresses && $field->maxAddresses && count($normalized) > (int)$field->maxAddresses) {
            $normalized = array_slice($normalized, 0, (int)$field->maxAddresses);
        }

        return $normalized;
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeSuggestion($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for addresses suggestion apply.');
        }

        // Craft Addresses expects an array keyed by synthetic IDs/new indexes.
        $payload = [];
        foreach ($normalized as $index => $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $id = trim((string)($row['_id'] ?? ''));
            if ($id !== '' && ctype_digit($id)) {
                unset($row['_id']);
                $payload[$id] = $row;
                continue;
            }

            unset($row['_id']);
            $payload["new:$index"] = $row;
        }

        $entry->setFieldValue($handle, $payload);
        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractAddressRows(mixed $value): array
    {
        $decoded = $this->toArray($value);
        if ($decoded === []) {
            return [];
        }

        if (isset($decoded['addresses']) && is_array($decoded['addresses'])) {
            return array_values(array_filter($decoded['addresses'], fn($row) => is_array($row)));
        }

        // Accept a single address object as shorthand.
        $hasAddressKey = false;
        foreach (self::ADDRESS_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $hasAddressKey = true;
                break;
            }
        }

        if ($hasAddressKey) {
            return [$decoded];
        }

        // Accept already-keyed Craft-style arrays.
        $rows = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapFriendlyKeys(array $row): array
    {
        $mapped = $row;

        if (isset($row['label']) && !isset($mapped['title'])) {
            $mapped['title'] = $row['label'];
        }

        if (isset($row['country']) && !isset($mapped['countryCode'])) {
            $mapped['countryCode'] = $row['country'];
        }

        if (isset($row['state']) && !isset($mapped['administrativeArea'])) {
            $mapped['administrativeArea'] = $row['state'];
        }

        if (isset($row['city']) && !isset($mapped['locality'])) {
            $mapped['locality'] = $row['city'];
        }

        if (isset($row['zipCode']) && !isset($mapped['postalCode'])) {
            $mapped['postalCode'] = $row['zipCode'];
        }

        if (isset($row['id']) && !isset($mapped['_id'])) {
            $mapped['_id'] = $row['id'];
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function toFriendlyRow(array $row): array
    {
        $friendly = [
            'label' => trim((string)($row['title'] ?? '')),
            'country' => trim((string)($row['countryCode'] ?? '')),
            'addressLine1' => trim((string)($row['addressLine1'] ?? '')),
            'addressLine2' => trim((string)($row['addressLine2'] ?? '')),
            'addressLine3' => trim((string)($row['addressLine3'] ?? '')),
            'state' => trim((string)($row['administrativeArea'] ?? '')),
            'city' => trim((string)($row['locality'] ?? '')),
            'zipCode' => trim((string)($row['postalCode'] ?? '')),
        ];

        $id = trim((string)($row['_id'] ?? ''));
        if ($id !== '') {
            $friendly['_id'] = $id;
        }

        return array_filter($friendly, static fn($value) => $value !== '');
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
            if (method_exists($value, 'all')) {
                try {
                    $all = $value->all();
                } catch (\Throwable) {
                    $all = null;
                }

                if (is_array($all)) {
                    $rows = [];
                    foreach ($all as $item) {
                        if (!is_object($item)) {
                            continue;
                        }

                        $row = [];
                        $id = $item->id ?? null;
                        if ($id !== null && $id !== '') {
                            $row['_id'] = (string)$id;
                        }
                        foreach (self::ADDRESS_KEYS as $key) {
                            if (!isset($item->{$key}) || $item->{$key} === null || $item->{$key} === '') {
                                continue;
                            }
                            $row[$key] = (string)$item->{$key};
                        }
                        if ($row !== []) {
                            $rows[] = $row;
                        }
                    }

                    return ['addresses' => $rows];
                }
            }

            $props = get_object_vars($value);
            return is_array($props) ? $props : [];
        }

        return [];
    }

    private function normalizeCountryCode(string $raw): string
    {
        $candidate = trim($raw);
        if ($candidate === '') {
            return '';
        }

        if (strlen($candidate) === 2 && ctype_alpha($candidate)) {
            return strtoupper($candidate);
        }

        $lookup = $this->countryCodeByLowerName();
        $byName = strtolower($candidate);
        if (isset($lookup[$byName])) {
            return $lookup[$byName];
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function countryCodeByLowerName(): array
    {
        if (is_array($this->countryCodeByLowerName)) {
            return $this->countryCodeByLowerName;
        }

        $map = [];
        try {
            $list = Craft::$app->getAddresses()->getCountryRepository()->getList(Craft::$app->language);
            foreach ($list as $country) {
                $code = strtoupper((string)($country->getAlpha2() ?? ''));
                $name = strtolower(trim((string)($country->getName() ?? '')));
                if ($code === '' || $name === '') {
                    continue;
                }
                $map[$name] = $code;
            }
        } catch (\Throwable) {
            // Keep empty map and rely on explicit ISO codes.
        }

        $this->countryCodeByLowerName = $map;
        return $this->countryCodeByLowerName;
    }
}
