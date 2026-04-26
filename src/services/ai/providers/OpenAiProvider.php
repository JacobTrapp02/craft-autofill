<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai\providers;

use Craft;
use craft\base\Model;
use craft\helpers\Json;
use GuzzleHttp\Exception\GuzzleException;
use jtdev\craftautofill\models\ai\AiGenerationRequest;
use jtdev\craftautofill\models\ai\OpenAiConfig;
use jtdev\craftautofill\services\ai\AiProviderInterface;
use RuntimeException;

class OpenAiProvider implements AiProviderInterface
{
    private const RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

    public function getProviderKey(): string
    {
        return 'openai';
    }

    public function generate(AiGenerationRequest $request, Model $providerConfig): array
    {
        if (!$providerConfig instanceof OpenAiConfig) {
            throw new RuntimeException('OpenAI provider requires an OpenAiConfig instance.');
        }

        if (!$request->validate()) {
            throw new RuntimeException('AI generation request is invalid.');
        }

        if (!$providerConfig->validate()) {
            throw new RuntimeException('OpenAI configuration is invalid.');
        }

        $apiKey = $providerConfig->getParsedApiKey();
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key env var resolved to an empty value.');
        }

        $payload = [
            'model' => $providerConfig->modelId,
            'input' => $request->messages,
        ];

        if ($providerConfig->reasoningEffort !== null && $providerConfig->reasoningEffort !== '') {
            $payload['reasoning'] = ['effort' => $providerConfig->reasoningEffort];
        }

        $payload['text'] = [
            'format' => [
                'type' => 'json_object',
            ],
        ];

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->post(self::RESPONSES_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => Json::encode($payload),
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('OpenAI request failed: ' . $exception->getMessage(), previous: $exception);
        }

        $decoded = Json::decodeIfJson((string)$response->getBody());

        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI response body was not valid JSON.');
        }

        return $decoded;
    }
}
