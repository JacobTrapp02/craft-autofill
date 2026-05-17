<?php

declare(strict_types=1);

namespace jtdev\craftautofill\models;

use craft\base\Model;

class AiRequestLog extends Model
{
    public ?int $fieldId = null;
    public ?int $entryId = null;
    public ?int $siteId = null;
    public ?int $userId = null;
    public string $provider = '';
    public ?string $modelConfigUid = null;
    public ?string $modelId = null;
    public ?string $reasoningEffort = null;
    public string $requestPrompt = '';
    public ?string $requestPayloadJson = null;
    public ?string $responseRawText = null;
    public ?string $responsePayloadJson = null;
    public bool $success = false;
    public ?string $errorMessage = null;
    public ?int $latencyMs = null;
    public ?int $inputTokens = null;
    public ?int $outputTokens = null;
    public ?int $totalTokens = null;
    public ?string $providerResponseId = null;

    public function rules(): array
    {
        return [
            [['fieldId', 'entryId', 'siteId', 'userId', 'latencyMs', 'inputTokens', 'outputTokens', 'totalTokens'], 'integer'],
            [['provider', 'requestPrompt'], 'string'],
            [['success'], 'boolean'],
            [['modelConfigUid', 'modelId', 'reasoningEffort', 'requestPayloadJson', 'responseRawText', 'responsePayloadJson', 'errorMessage', 'providerResponseId'], 'safe'],
        ];
    }
}
