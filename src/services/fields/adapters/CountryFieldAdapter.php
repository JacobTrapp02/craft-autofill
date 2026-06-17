<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use CommerceGuys\Addressing\Country\Country as CountryModel;
use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Country;
use RuntimeException;

class CountryFieldAdapter implements FieldAdapterInterface
{
    /**
     * @var array<string, string>|null
     */
    private ?array $countryCodeByLowerName = null;

    public function getKey(): string
    {
        return 'country';
    }

    public function isAvailableInLiteVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Country;
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
            $errors[] = 'Prompt is required for Country fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        return [
            'type' => 'string',
            'format' => 'country-code',
            'rules' => [
                'Return exactly one country value.',
                'Return a capitalized 2-letter ISO country code (for example: US, CA, GB).',
                'Do not return the full country name unless the prompt explicitly asks for it.',
            ],
            'options' => $this->countryOptions(),
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if ($value instanceof CountryModel) {
            return strtoupper(trim((string)$value->getCountryCode()));
        }

        if (is_string($value)) {
            return strtoupper(trim($value));
        }

        if (is_scalar($value)) {
            return strtoupper(trim((string)$value));
        }

        return '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        return $this->normalizeCountryCode($value) !== '';
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        return $this->normalizeCountryCode($value);
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $normalized = $this->normalizeCountryCode($value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for country suggestion apply.');
        }

        $entry->setFieldValue($handle, $normalized);
        return $normalized;
    }

    private function normalizeCountryCode(mixed $value): string
    {
        if ($value instanceof CountryModel) {
            $code = strtoupper(trim((string)$value->getCountryCode()));
            return $this->isValidCountryCode($code) ? $code : '';
        }

        $candidate = trim((string)$value);
        if ($candidate === '') {
            return '';
        }

        if (strlen($candidate) === 2 && ctype_alpha($candidate)) {
            $code = strtoupper($candidate);
            return $this->isValidCountryCode($code) ? $code : '';
        }

        $lookup = $this->countryCodeByLowerName();
        $code = $lookup[strtolower($candidate)] ?? '';

        return $this->isValidCountryCode($code) ? $code : '';
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
            // Fall back to strict ISO code-only behavior.
        }

        $this->countryCodeByLowerName = $map;
        return $this->countryCodeByLowerName;
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    private function countryOptions(): array
    {
        $options = [];

        try {
            $list = Craft::$app->getAddresses()->getCountryList(Craft::$app->language);
            foreach ($list as $code => $name) {
                $countryCode = strtoupper(trim((string)$code));
                $countryName = trim((string)$name);
                if ($countryCode === '' || $countryName === '') {
                    continue;
                }

                $options[] = [
                    'value' => $countryCode,
                    'label' => $countryName,
                ];
            }
        } catch (\Throwable) {
            // Fall back to prompt-only behavior if the list can't be loaded.
        }

        return $options;
    }

    private function isValidCountryCode(string $code): bool
    {
        if ($code === '') {
            return false;
        }

        try {
            Craft::$app->getAddresses()->getCountryRepository()->get($code, Craft::$app->language);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
