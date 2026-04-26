<?php

declare(strict_types=1);

namespace jtdev\craftautofill\models\ai;

use craft\base\Model;
use craft\helpers\App;

class OpenAiConfig extends Model
{
    public string $uid = '';
    public string $label = '';
    public string $provider = 'openai';
    public string $apiKeyEnv = '';
    public string $modelId = '';
    public ?string $reasoningEffort = null;
    public bool $enabled = true;

    public function rules(): array
    {
        return [
            [['provider', 'apiKeyEnv', 'modelId'], 'required'],
            [['uid', 'label', 'provider', 'apiKeyEnv', 'modelId', 'reasoningEffort'], 'string'],
            ['enabled', 'boolean'],
            [
                'reasoningEffort',
                'in',
                'range' => ['low', 'medium', 'high'],
                'skipOnEmpty' => true,
            ],
        ];
    }

    public function getParsedApiKey(): string
    {
        return trim(App::parseEnv($this->apiKeyEnv));
    }
}
