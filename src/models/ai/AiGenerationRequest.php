<?php

declare(strict_types=1);

namespace jtdev\craftautofill\models\ai;

use craft\base\Model;

class AiGenerationRequest extends Model
{
    public string $modelConfigUid = '';
    // Provider-ready conversation payload built by field adapters + prompt builder.
    public array $messages = [];
    // Read-only source/dependency values used to construct messages.
    public array $context = [];

    public function rules(): array
    {
        return [
            ['modelConfigUid', 'required'],
            ['modelConfigUid', 'string'],
            [['messages', 'context'], 'safe'],
        ];
    }
}
