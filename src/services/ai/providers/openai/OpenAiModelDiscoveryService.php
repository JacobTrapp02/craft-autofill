<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai\providers\openai;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Json;
use GuzzleHttp\Exception\GuzzleException;
use jtdev\craftautofill\models\Settings;

class OpenAiModelDiscoveryService extends Component
{
    private const MODELS_ENDPOINT = 'https://api.openai.com/v1/models';
    private const CACHE_TTL_SECONDS = 600;

    /**
     * @return array{options:array<int, array{label:string, value:string}>, error:?string, sourceEnv:?string}
     */
    public function discoverForSettings(Settings $settings): array
    {
        $apiKeyRef = $this->resolveApiKeyReference($settings->modelConfigs);
        if ($apiKeyRef === null) {
            return [
                'options' => [],
                'error' => null,
                'sourceEnv' => null,
            ];
        }

        $apiKey = trim(App::parseEnv($apiKeyRef));
        if ($apiKey === '') {
            return [
                'options' => [],
                'error' => sprintf('Model discovery skipped: %s resolved to an empty value.', $apiKeyRef),
                'sourceEnv' => $apiKeyRef,
            ];
        }

        $cacheKey = 'autofill:openai-models:' . hash('sha256', $apiKey);
        $cachedIds = Craft::$app->getCache()->get($cacheKey);
        if (is_array($cachedIds)) {
            return [
                'options' => $this->toOptions($cachedIds),
                'error' => null,
                'sourceEnv' => $apiKeyRef,
            ];
        }

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get(self::MODELS_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 8,
                'connect_timeout' => 5,
            ]);
        } catch (GuzzleException $exception) {
            return [
                'options' => [],
                'error' => 'Model discovery request failed: ' . $exception->getMessage(),
                'sourceEnv' => $apiKeyRef,
            ];
        }

        $decoded = Json::decodeIfJson((string)$response->getBody());
        if (!is_array($decoded)) {
            return [
                'options' => [],
                'error' => 'Model discovery response was not valid JSON.',
                'sourceEnv' => $apiKeyRef,
            ];
        }

        $rows = $decoded['data'] ?? null;
        if (!is_array($rows)) {
            return [
                'options' => [],
                'error' => 'Model discovery response was missing a data list.',
                'sourceEnv' => $apiKeyRef,
            ];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $ids[$id] = $id;
        }

        $modelIds = array_values($ids);
        natcasesort($modelIds);
        $modelIds = array_values($modelIds);

        Craft::$app->getCache()->set($cacheKey, $modelIds, self::CACHE_TTL_SECONDS);

        return [
            'options' => $this->toOptions($modelIds),
            'error' => null,
            'sourceEnv' => $apiKeyRef,
        ];
    }

    /**
     * @param array<int, mixed> $modelConfigs
     */
    private function resolveApiKeyReference(array $modelConfigs): ?string
    {
        foreach ($modelConfigs as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!($row['enabled'] ?? false)) {
                continue;
            }

            $provider = strtolower(trim((string)($row['provider'] ?? 'openai')));
            if ($provider !== 'openai') {
                continue;
            }

            $apiKeyRef = trim((string)($row['apiKeyEnv'] ?? ''));
            if ($apiKeyRef !== '') {
                return $apiKeyRef;
            }
        }

        foreach ($modelConfigs as $row) {
            if (!is_array($row)) {
                continue;
            }

            $provider = strtolower(trim((string)($row['provider'] ?? 'openai')));
            if ($provider !== 'openai') {
                continue;
            }

            $apiKeyRef = trim((string)($row['apiKeyEnv'] ?? ''));
            if ($apiKeyRef !== '') {
                return $apiKeyRef;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $modelIds
     * @return array<int, array{label:string, value:string}>
     */
    private function toOptions(array $modelIds): array
    {
        $options = [
            ['label' => 'Select a model', 'value' => ''],
        ];

        foreach ($modelIds as $modelId) {
            $options[] = [
                'label' => $modelId,
                'value' => $modelId,
            ];
        }

        return $options;
    }
}
