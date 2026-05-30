<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Money;
use craft\helpers\MoneyHelper;
use Money\Currency;
use Money\Money as MoneyLibrary;
use RuntimeException;

class MoneyFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'money';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Money;
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
            $errors[] = 'Prompt is required for Money fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        if (!$field instanceof Money) {
            return ['type' => 'number'];
        }

        $rules = [
            sprintf('Return a numeric amount only in %s major currency units.', $field->currency),
            'Do not include currency symbols, codes, commas, or explanatory text.',
        ];

        $min = $this->decimalFromMinorUnits($field->min, $field->currency);
        $max = $this->decimalFromMinorUnits($field->max, $field->currency);
        if ($min !== null) {
            $rules[] = sprintf('Do not return an amount lower than %s.', $min);
        }
        if ($max !== null) {
            $rules[] = sprintf('Do not return an amount higher than %s.', $max);
        }

        return [
            'type' => 'number',
            'format' => 'money',
            'currency' => $field->currency,
            'currencyLabel' => $field->currencyLabel(),
            'showCurrency' => (bool)$field->showCurrency,
            'decimals' => $field->subunits(),
            'min' => $min,
            'max' => $max,
            'rules' => $rules,
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if ($value instanceof MoneyLibrary) {
            return (string)(MoneyHelper::toDecimal($value) ?: '');
        }

        return $this->normalizeMoneyAmount($field, $value);
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!$field instanceof Money) {
            return false;
        }

        $normalized = $this->normalizeMoneyAmount($field, $value);
        if ($normalized === '') {
            return false;
        }

        $money = $this->toMoney($field, $normalized);
        if (!$money instanceof MoneyLibrary) {
            return false;
        }

        if ($field->min !== null && $money->lessThan(new MoneyLibrary((string)$field->min, new Currency($field->currency)))) {
            return false;
        }

        if ($field->max !== null && $money->greaterThan(new MoneyLibrary((string)$field->max, new Currency($field->currency)))) {
            return false;
        }

        return true;
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        return $this->normalizeMoneyAmount($field, $value);
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        if (!$field instanceof Money) {
            throw new RuntimeException('Money suggestion adapter received an unsupported field.');
        }

        $normalized = $this->normalizeMoneyAmount($field, $value);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for money suggestion apply.');
        }

        if ($normalized === '') {
            throw new RuntimeException('Money suggestion must be a valid numeric amount.');
        }

        if (!$this->validateSuggestion($field, $normalized)) {
            throw new RuntimeException('Money suggestion must match the field currency and amount constraints.');
        }

        $entry->setFieldValue($handle, [
            'value' => $normalized,
            'currency' => $field->currency,
        ]);

        return $normalized;
    }

    private function normalizeMoneyAmount(FieldInterface $field, mixed $value): string
    {
        if (!$field instanceof Money) {
            return '';
        }

        if ($value instanceof MoneyLibrary) {
            return (string)(MoneyHelper::toDecimal($value) ?: '');
        }

        if (is_array($value)) {
            if (isset($value['value'])) {
                $value = $value['value'];
            } else {
                return '';
            }
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $money = $this->toMoney($field, $raw);
        return $money instanceof MoneyLibrary ? (string)(MoneyHelper::toDecimal($money) ?: '') : '';
    }

    private function toMoney(Money $field, string $value): MoneyLibrary|false
    {
        return MoneyHelper::toMoney([
            'value' => $value,
            'currency' => $field->currency,
        ]);
    }

    private function decimalFromMinorUnits(int|float|null $value, string $currency): ?string
    {
        if ($value === null) {
            return null;
        }

        $money = new MoneyLibrary((string)$value, new Currency($currency));
        $decimal = MoneyHelper::toDecimal($money);
        return $decimal === false ? null : $decimal;
    }
}
