<?php

declare(strict_types=1);

namespace jtdev\craftautofill\models\ai;

use craft\base\Model;

class AiGenerationResult extends Model
{
    public bool $success = false;
    // Normalized field-value map from parsed JSON response, ready for adapter validation.
    public array $suggestions = [];
    public ?float $confidence = null;
    public ?string $error = null;

    public function rules(): array
    {
        return [
            ['success', 'boolean'],
            ['suggestions', 'safe'],
            ['confidence', 'number'],
            ['error', 'string'],
        ];
    }
}
