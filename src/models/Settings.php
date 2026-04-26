<?php

namespace jtdev\craftautofill\models;

use craft\base\Model;
use craft\helpers\StringHelper;
use jtdev\craftautofill\models\ai\OpenAiConfig;

/**
 * Autofill settings
 */
class Settings extends Model
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $modelConfigs = [];

    public function init(): void
    {
        parent::init();
        $this->modelConfigs = $this->normalizeModelConfigs($this->modelConfigs);
    }

    public function rules(): array
    {
        return [
            ['modelConfigs', 'validateModelConfigs'],
        ];
    }

    public function getDefaultModelConfigUid(): ?string
    {
        foreach ($this->modelConfigs as $config) {
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            $uid = (string)($config['uid'] ?? '');
            if ($uid !== '') {
                return $uid;
            }
        }

        return null;
    }

    public function validateModelConfigs(): void
    {
        $this->modelConfigs = $this->normalizeModelConfigs($this->modelConfigs);

        foreach ($this->modelConfigs as $index => $config) {
            $provider = (string)($config['provider'] ?? 'openai');
            $rowLabel = sprintf('Model config row %d', $index + 1);

            if ($provider !== 'openai') {
                $this->addError('modelConfigs', sprintf('%s has unsupported provider "%s".', $rowLabel, $provider));
                continue;
            }

            $openAiConfig = new OpenAiConfig($config);
            if ($openAiConfig->validate()) {
                continue;
            }

            foreach ($openAiConfig->getFirstErrors() as $attribute => $message) {
                $this->addError('modelConfigs', sprintf('%s (%s): %s', $rowLabel, $attribute, $message));
            }
        }
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeModelConfigs(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $uid = trim((string)($row['uid'] ?? ''));
            $label = trim((string)($row['label'] ?? ''));
            $provider = strtolower(trim((string)($row['provider'] ?? 'openai')));
            $apiKeyEnv = trim((string)($row['apiKeyEnv'] ?? ''));
            $modelId = trim((string)($row['modelId'] ?? ''));
            $reasoningEffort = trim((string)($row['reasoningEffort'] ?? ''));
            $enabled = $this->toBool($row['enabled'] ?? true);

            $hasMeaningfulInput = $uid !== '' || $label !== '' || $apiKeyEnv !== '' || $modelId !== '' || $reasoningEffort !== '';
            if (!$hasMeaningfulInput) {
                continue;
            }

            $normalized[] = [
                'uid' => $uid !== '' ? $uid : StringHelper::UUID(),
                'label' => $label,
                'provider' => $provider !== '' ? $provider : 'openai',
                'apiKeyEnv' => $apiKeyEnv,
                'modelId' => $modelId,
                'reasoningEffort' => $reasoningEffort !== '' ? $reasoningEffort : null,
                'enabled' => $enabled,
            ];
        }

        return $normalized;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
