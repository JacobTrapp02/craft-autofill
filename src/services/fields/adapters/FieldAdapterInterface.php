<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;

interface FieldAdapterInterface
{
    public function getKey(): string;

    public function isAvailableInFreeVersion(): bool;

    public function supports(FieldInterface $field): bool;

    /**
     * Returns the expected prompt configuration schema used by settings UI.
     *
     * @return array<string, mixed>
     */
    public function getPromptConfigSchema(FieldInterface $field): array;

    /**
     * Normalizes raw prompt config values saved in field settings.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function normalizePromptConfig(array $config, FieldInterface $field): array;

    /**
     * Validates prompt config values and returns human-readable errors.
     *
     * @param array<string, mixed> $config
     * @return string[]
     */
    public function validatePromptConfig(array $config, FieldInterface $field): array;

    /**
     * Returns field-type-specific prompt instructions for message building.
     *
     * @param array<string, mixed> $promptConfig
     * @return array<string, mixed>
     */
    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array;

    /**
     * Returns a compact context string for this field value.
     */
    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string;

    public function validateSuggestion(FieldInterface $field, mixed $value): bool;

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed;

    /**
     * Returns field-type-specific runtime behavior metadata for frontend filling.
     *
     * @return array<string, mixed>
     */
    public function getFillRuntimeSpec(FieldInterface $field): array;

    /**
     * Returns field-type-specific review modal rendering metadata.
     *
     * @return array<string, mixed>
     */
    public function getReviewUiSpec(FieldInterface $field): array;
}
